<?php

namespace App\Services\Quota;

use App\Exceptions\CriteriaInactiveException;
use App\Exceptions\DiscoveryRunInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DiscoveryQuotaService — derived daily credit ledger for discovery runs.
 *
 * All balances are derived at read time from the discovery_runs table.
 * No stored running balance — the quota_date column on each run pins
 * every reservation/consumption to a fixed calendar day.
 *
 * Null-safety rule (PHP footgun): min(20, null) === null and null <= 0 === true.
 * Every method that performs arithmetic or comparison MUST call isUnlimited()
 * first and branch away from any arithmetic path when the package is unlimited.
 */
class DiscoveryQuotaService
{
    // ── Active package ─────────────────────────────────────────────────────────

    /**
     * Return the Package for the latest assignment, or null when no assignment
     * exists (which is explicitly treated as unlimited by convention).
     */
    public function activePackage(): ?Package
    {
        return PackageAssignment::latestActive()?->package;
    }

    // ── Unlimited guard ────────────────────────────────────────────────────────

    /**
     * True when no assignment exists OR the active package has daily_credits === null.
     *
     * This MUST be called first before any arithmetic involving daily_credits.
     * "No assignment" = unlimited (fresh install can never be locked out).
     */
    public function isUnlimited(): bool
    {
        $package = $this->activePackage();

        if ($package === null) {
            return true;
        }

        return $package->daily_credits === null;
    }

    // ── Consumption accounting ─────────────────────────────────────────────────

    /**
     * Total credits spent or reserved on the given date.
     *
     * - Terminal runs (completed|failed): contribute `consumed` (actual spend).
     * - In-flight runs (pending|running): contribute max(credits_reserved, consumed)
     *   (conservative — treat the full reservation as spent until terminal).
     *
     * Query: one pass with CASE WHEN, portable across MySQL 8 and SQLite.
     * Uses the indexed `quota_date` column — never DATE(created_at).
     *
     * @param  CarbonInterface  $date
     * @param  int|null         $excludeRunId  Exclude a specific run from the sum
     *                                         (used by re-check in the job to exclude
     *                                          the run's own reservation).
     */
    public function usedOn(CarbonInterface $date, ?int $excludeRunId = null): int
    {
        $query = DiscoveryRun::query()
            ->where('quota_date', $date->toDateString())
            ->selectRaw(
                "SUM(CASE
                    WHEN status IN ('completed', 'failed')
                        THEN consumed
                    ELSE
                        CASE WHEN credits_reserved > consumed
                            THEN credits_reserved
                            ELSE consumed
                        END
                 END) as total_used"
            );

        if ($excludeRunId !== null) {
            $query->where('id', '!=', $excludeRunId);
        }

        $result = $query->value('total_used');

        return (int) ($result ?? 0);
    }

    /**
     * Remaining credits for the given date, floored at 0.
     *
     * Only call when not unlimited — throws LogicException if called for an
     * unlimited package to surface incorrect call sites early.
     *
     * @throws \LogicException
     */
    public function remainingOn(CarbonInterface $date, ?int $excludeRunId = null): int
    {
        if ($this->isUnlimited()) {
            throw new \LogicException(
                'remainingOn() must not be called when the package is unlimited. ' .
                'Guard with isUnlimited() before calling this method.'
            );
        }

        $daily = (int) $this->activePackage()->daily_credits;

        return max(0, $daily - $this->usedOn($date, $excludeRunId));
    }

    /**
     * Remaining credits for today, or null when the package is unlimited.
     *
     * Convenience accessor for UI/controller display — callers that need a
     * nullable value (badge, disabled-state) use this instead of branching.
     */
    public function remainingTodayForDisplay(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return $this->remainingOn(Carbon::today());
    }

    // ── Batch sizing ───────────────────────────────────────────────────────────

    /**
     * Effective batch size for a dispatch:
     *   - Unlimited: use daily_limit (?: 20) — no solde cap.
     *   - Limited: min(daily_limit ?: 20, remainingOn(today)).
     *
     * Returns 0 when limited and remaining is 0 (caller should check before
     * dispatching, but reserveRun() will throw QuotaExhaustedException anyway).
     *
     * NOTE: this method is intentionally NOT called inside reserveRun() because
     * the remaining value is re-computed inside the lock-protected transaction.
     * External callers (display, preview) may use it safely.
     */
    public function effectiveBatchFor(ProspectCriteria $criteria): int
    {
        $wantedBatch = $criteria->daily_limit ?: 20;

        if ($this->isUnlimited()) {
            return $wantedBatch;
        }

        return min($wantedBatch, $this->remainingOn(Carbon::today()));
    }

    // ── Reservation (single entry point) ──────────────────────────────────────

    /**
     * Reserve a discovery run for the given criteria.
     *
     * This is the ONLY entry point for creating DiscoveryRun rows — controller,
     * artisan command, and any future scheduler all call this method.
     *
     * Serialization: GET_LOCK is acquired FIRST, then the DB transaction is opened
     * inside the lock, so the lock spans the full transaction commit. This prevents
     * a second concurrent request from reading pre-commit state and over-reserving.
     *
     * Lock scope: namespaced by database name to avoid collisions on shared MySQL
     * servers. Name kept under 64 chars.
     *
     * Guards moved inside the lock+transaction:
     *   - criteria lockForUpdate re-read (serialises concurrent edits)
     *   - is_active check  → throws CriteriaInactiveException (422)
     *   - in-flight check  → throws DiscoveryRunInFlightException (409)
     *   - quota exhausted  → throws QuotaExhaustedException (422)
     *
     * On non-MySQL drivers (SQLite in tests) the named lock is skipped — single-
     * writer + single-process tests do not need it.
     *
     * @throws CriteriaInactiveException       When criteria is inactive.
     * @throws DiscoveryRunInFlightException   When a non-stale run is already in flight.
     * @throws QuotaExhaustedException         When the daily balance is 0.
     * @throws QuotaLockUnavailableException   When the MySQL lock cannot be acquired (fail-closed).
     */
    public function reserveRun(ProspectCriteria $criteria): DiscoveryRun
    {
        $isMySQL  = DB::getDriverName() === 'mysql';
        $lockName = 'discovery-quota:' . DB::getDatabaseName();

        // Truncate to 64 chars (MySQL hard limit for GET_LOCK names).
        $lockName = mb_substr($lockName, 0, 64);

        if ($isMySQL) {
            $lockResult = DB::selectOne('SELECT GET_LOCK(?, 5) as acquired', [$lockName]);
            if (! $lockResult || (int) $lockResult->acquired !== 1) {
                throw new QuotaLockUnavailableException();
            }
        }

        try {
            $run = DB::transaction(function () use ($criteria) {
                // Re-read criteria with a row-level lock so concurrent reservations
                // for the same criteria are serialized even on non-MySQL drivers.
                $criteria = ProspectCriteria::lockForUpdate()->findOrFail($criteria->id);

                if (! $criteria->is_active) {
                    throw new CriteriaInactiveException();
                }

                $latest = $criteria->discoveryRuns()->latest('id')->first();

                if ($latest && in_array($latest->status, ['pending', 'running'], true) && ! $latest->isStale()) {
                    throw new DiscoveryRunInFlightException($latest);
                }

                $today       = Carbon::today();
                $wantedBatch = $criteria->daily_limit ?: 20;

                if (! $this->isUnlimited()) {
                    $remaining = $this->remainingOn($today);

                    if ($remaining <= 0) {
                        throw new QuotaExhaustedException();
                    }

                    $batch = min($wantedBatch, $remaining);
                } else {
                    $batch = $wantedBatch;
                }

                return DiscoveryRun::create([
                    'prospect_criteria_id'  => $criteria->id,
                    'status'                => 'pending',
                    'credits_reserved'      => $batch,
                    'consumed'              => 0,
                    'quota_date'            => $today->toDateString(),
                    'package_assignment_id' => PackageAssignment::latestActive()?->id,
                ]);
            });

            return $run;
        } finally {
            // Release lock AFTER the transaction has committed (the finally block
            // runs after DB::transaction returns, so the commit has already happened).
            if ($isMySQL) {
                DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }
}
