<?php

namespace App\Services\Quota;

use App\Exceptions\CriteriaInactiveException;
use App\Exceptions\DiscoveryRunInFlightException;
use App\Exceptions\EnrichmentInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DiscoveryQuotaService — derived daily + monthly credit ledger for discovery runs.
 *
 * All balances are derived at read time from the discovery_runs table.
 * No stored running balance — the quota_date column on each run pins
 * every reservation/consumption to a fixed calendar day.
 *
 * Two parallel meters, each with a daily AND a monthly cap:
 *   daily_credits / monthly_credits / credits_reserved / consumed
 *       = company (discovery) meter — one credit per company found.
 *   daily_contact_credits / monthly_contact_credits / contact_credits_reserved / contact_consumed
 *       = contact (enrichment) meter — one credit per contact enriched.
 *
 * Null-safety rule (PHP footgun): min(20, null) === null and null <= 0 === true.
 * Every method that performs arithmetic or comparison MUST call isUnlimited()
 * (or contactIsUnlimited() / monthlyIsUnlimited() / monthlyContactIsUnlimited())
 * first and branch away from any arithmetic path when the relevant cap is null.
 * Caps are appended to a $caps[] array ONLY after their *IsUnlimited() guard passes —
 * null must NEVER reach min().
 *
 * Monthly window (currentPeriod):
 *   Both period bounds are derived from the ORIGINAL anchor via addMonthsNoOverflow($i)
 *   for consecutive $i. Never re-anchor off a clamped value — that causes a 31st anchor
 *   to drift to the 28th and leave days in no window. Deriving every boundary as
 *   anchor + i months makes consecutive windows share their boundary exactly →
 *   gap-free, overlap-free, every day in exactly one window.
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

    // ── Quota timezone (single source of truth) ────────────────────────────────

    /**
     * The configured quota timezone — 'Europe/Paris' (default) or 'UTC'.
     *
     * Every quota-day computation (today(), currentHour(), quota_date writes)
     * MUST read this instead of hardcoding a zone. The scheduler gate and the
     * quota_date persisted on reservation both route through this same source,
     * so a criterion cannot double-fire across the UTC/local-midnight band.
     */
    public function quotaTz(): string
    {
        return (string) Setting::get('decouverte.timezone', 'Europe/Paris');
    }

    /**
     * "Today" in the configured quota timezone — the calendar day used for
     * quota_date and all daily-meter reads.
     *
     * Only the calendar DATE is computed in the quota tz; the returned Carbon
     * is a tz-neutral midnight in the app's default timezone (UTC). This
     * matters because quota_date is a plain `date` column and quota_anchor_date
     * is parsed with Carbon::parse() (both with NO timezone offset) — building
     * "today" as an absolute instant at Europe/Paris 00:00 (which is UTC 22:00
     * the PRIOR day) would shift every currentPeriod() anchor-walk comparison
     * by the UTC/Paris offset and desync from quota_date string comparisons.
     * Deriving the date string first, then parsing it plainly, keeps this
     * value comparable the same way every other quota_date read/write is.
     */
    public function today(): Carbon
    {
        return Carbon::parse(Carbon::now($this->quotaTz())->toDateString());
    }

    /**
     * Current hour (0-23) in the configured quota timezone — used by the
     * auto-discovery scheduler gate (run_at_hour <= currentHour()).
     */
    public function currentHour(): int
    {
        return Carbon::now($this->quotaTz())->hour;
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

    /**
     * True when no assignment exists OR the active package has
     * daily_contact_credits === null.
     *
     * This MUST be called first before any arithmetic involving
     * daily_contact_credits — same PHP null footgun as isUnlimited().
     * "No assignment" = unlimited by convention.
     */
    public function contactIsUnlimited(): bool
    {
        $package = $this->activePackage();

        if ($package === null) {
            return true;
        }

        return $package->daily_contact_credits === null;
    }

    /**
     * True when no assignment exists OR the active package has monthly_credits === null.
     *
     * This MUST be called first before any arithmetic involving monthly_credits.
     * "No assignment" = unlimited by convention (same as isUnlimited()).
     *
     * @see monthlyContactIsUnlimited() for the parallel monthly contact meter guard.
     */
    public function monthlyIsUnlimited(): bool
    {
        $package = $this->activePackage();

        if ($package === null) {
            return true;
        }

        return $package->monthlyIsUnlimited();
    }

    /**
     * True when no assignment exists OR the active package has
     * monthly_contact_credits === null.
     *
     * This MUST be called first before any arithmetic involving monthly_contact_credits.
     * "No assignment" = unlimited by convention.
     */
    public function monthlyContactIsUnlimited(): bool
    {
        $package = $this->activePackage();

        if ($package === null) {
            return true;
        }

        return $package->monthlyContactIsUnlimited();
    }

    // ── Monthly period helper ──────────────────────────────────────────────────

    /**
     * Current monthly window [start, end) — half-open interval.
     *
     * Returns [$start, $end] as Carbon instances where:
     *   $start <= $on < $end
     *
     * Anchor: loaded from activePackage()?->quota_anchor_date.
     * If no anchor is stored, falls back to the 1st of the calendar month.
     *
     * Contiguity invariant: BOTH bounds are derived from the ORIGINAL anchor via
     * addMonthsNoOverflow($i) for consecutive $i. Never re-anchor off a clamped
     * value — that was the bug that caused a 31st anchor to drift to 28th and
     * leave days in no window. With both bounds off the original anchor, every
     * consecutive pair shares its boundary exactly → gap-free and overlap-free.
     *
     * Carbon 3 / signed-diff note ([[carbon3-signed-diff]]): floatDiffInMonths()
     * is signed; the seed MAY be negative when $on < $anchor (e.g. the job
     * re-check passes a historical quota_date after an admin moves quota_anchor_date
     * forward). The two walks converge on the unique $i where
     * anchor+i <= on < anchor+i+1, regardless of seed sign. Correctness comes
     * from the lte($on) walk, not the diff. The backward walk has no $i>0 gate
     * so it can reach negative $i to find a pre-anchor window.
     *
     * @param  CarbonInterface|null  $on  Reference "today"; defaults to $this->today() (quota tz).
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}  [$start, $end] half-open.
     */
    public function currentPeriod(?CarbonInterface $on = null): array
    {
        $on  = ($on ?? $this->today())->copy()->startOfDay();
        $raw = $this->activePackage()?->quota_anchor_date;
        $anchor = $raw ? Carbon::parse($raw)->startOfDay() : $on->copy()->startOfMonth();

        // Signed seed — may be negative when $on < $anchor (pre-anchor window).
        // Walk forward/backward so [anchor+i, anchor+i+1) contains $on. Both bounds off the ORIGINAL anchor.
        // Contiguity invariant: every consecutive boundary is shared exactly once → gap-free and overlap-free.
        $i = (int) floor($anchor->floatDiffInMonths($on));                                // signed seed — may be negative
        while ($anchor->copy()->addMonthsNoOverflow($i + 1)->lte($on)) { $i++; }         // walk forward
        while ($anchor->copy()->addMonthsNoOverflow($i)->gt($on))      { $i--; }         // walk backward (no $i>0 gate)

        return [$anchor->copy()->addMonthsNoOverflow($i), $anchor->copy()->addMonthsNoOverflow($i + 1)];
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
                        THEN (consumed - COALESCE(excluded_count, 0))
                    ELSE
                        CASE WHEN credits_reserved > (consumed - COALESCE(excluded_count, 0))
                            THEN credits_reserved
                            ELSE (consumed - COALESCE(excluded_count, 0))
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

        return $this->remainingOn($this->today());
    }

    // ── Contact meter accounting ───────────────────────────────────────────────

    /**
     * Total contact credits spent or reserved on the given date.
     *
     * Mirrors usedOn() but sums contact_consumed (terminal) /
     * max(contact_credits_reserved, contact_consumed) (in-flight).
     *
     * Query: one pass with CASE WHEN, portable across MySQL 8 and SQLite.
     * Uses the indexed `quota_date` column — never DATE(created_at).
     *
     * @param  CarbonInterface  $date
     * @param  int|null         $excludeRunId  Exclude a specific run from the sum.
     */
    public function contactUsedOn(CarbonInterface $date, ?int $excludeRunId = null): int
    {
        $query = DiscoveryRun::query()
            ->where('quota_date', $date->toDateString())
            ->selectRaw(
                "SUM(CASE
                    WHEN status IN ('completed', 'failed')
                        THEN contact_consumed
                    ELSE
                        CASE WHEN contact_credits_reserved > contact_consumed
                            THEN contact_credits_reserved
                            ELSE contact_consumed
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
     * Remaining contact credits for the given date, floored at 0.
     *
     * Only call when not contact-unlimited — throws LogicException if called for an
     * unlimited package to surface incorrect call sites early.
     *
     * @throws \LogicException
     */
    public function contactRemainingOn(CarbonInterface $date, ?int $excludeRunId = null): int
    {
        if ($this->contactIsUnlimited()) {
            throw new \LogicException(
                'contactRemainingOn() must not be called when the contact meter is unlimited. ' .
                'Guard with contactIsUnlimited() before calling this method.'
            );
        }

        $daily = (int) $this->activePackage()->daily_contact_credits;

        return max(0, $daily - $this->contactUsedOn($date, $excludeRunId));
    }

    /**
     * Remaining contact credits for today, or null when the contact meter is unlimited.
     *
     * Convenience accessor for UI/controller display — callers that need a
     * nullable value (badge, disabled-state) use this instead of branching.
     */
    public function contactRemainingTodayForDisplay(): ?int
    {
        if ($this->contactIsUnlimited()) {
            return null;
        }

        return $this->contactRemainingOn($this->today());
    }

    // ── Monthly consumption accounting ────────────────────────────────────────

    /**
     * Total company credits spent or reserved within the given period window.
     *
     * Mirrors usedOn() but aggregates over a date range using a half-open
     * interval: quota_date >= $start AND quota_date < $end (NEVER BETWEEN —
     * inclusive upper-bound would double-count the boundary day across two windows).
     *
     * Uses the same SUM(CASE …) as usedOn() so terminal-vs-in-flight accounting
     * cannot diverge between the daily and monthly meters.
     *
     * The range scan is sargable on the existing btree index on quota_date
     * (added in migration 2026_06_10_400003).
     *
     * @param  CarbonInterface  $start         Inclusive lower bound (>= start).
     * @param  CarbonInterface  $end           Exclusive upper bound (< end).
     * @param  int|null         $excludeRunId  Exclude a specific run from the sum
     *                                         (job re-check uses this to exclude the
     *                                          run's own reservation — finding #3).
     */
    public function usedInPeriod(CarbonInterface $start, CarbonInterface $end, ?int $excludeRunId = null): int
    {
        $query = DiscoveryRun::query()
            ->where('quota_date', '>=', $start->toDateString())
            ->where('quota_date', '<',  $end->toDateString())
            ->selectRaw(
                "SUM(CASE
                    WHEN status IN ('completed', 'failed')
                        THEN (consumed - COALESCE(excluded_count, 0))
                    ELSE
                        CASE WHEN credits_reserved > (consumed - COALESCE(excluded_count, 0))
                            THEN credits_reserved
                            ELSE (consumed - COALESCE(excluded_count, 0))
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
     * Total contact credits spent or reserved within the given period window.
     *
     * Mirrors contactUsedOn() and usedInPeriod() — sums contact_consumed (terminal)
     * or max(contact_credits_reserved, contact_consumed) (in-flight) over the
     * half-open date range.
     *
     * @param  CarbonInterface  $start         Inclusive lower bound (>= start).
     * @param  CarbonInterface  $end           Exclusive upper bound (< end).
     * @param  int|null         $excludeRunId  Exclude a specific run from the sum.
     */
    public function contactUsedInPeriod(CarbonInterface $start, CarbonInterface $end, ?int $excludeRunId = null): int
    {
        $query = DiscoveryRun::query()
            ->where('quota_date', '>=', $start->toDateString())
            ->where('quota_date', '<',  $end->toDateString())
            ->selectRaw(
                "SUM(CASE
                    WHEN status IN ('completed', 'failed')
                        THEN contact_consumed
                    ELSE
                        CASE WHEN contact_credits_reserved > contact_consumed
                            THEN contact_credits_reserved
                            ELSE contact_consumed
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
     * Remaining company credits for the given period, floored at 0.
     *
     * Only call when not monthly-unlimited — throws LogicException if called for
     * an unlimited package to surface incorrect call sites early (mirrors remainingOn()).
     *
     * The $excludeRunId parameter is critical for the job's mid-run re-check
     * (finding #3): without it, the run's own in-flight reservation fills the
     * entire monthly budget and starves itself to 0.
     *
     * @param  CarbonInterface  $start         Inclusive period start (from currentPeriod()).
     * @param  CarbonInterface  $end           Exclusive period end (from currentPeriod()).
     * @param  int|null         $excludeRunId  Run to exclude from the used tally.
     * @throws \LogicException
     */
    public function monthlyRemaining(CarbonInterface $start, CarbonInterface $end, ?int $excludeRunId = null): int
    {
        if ($this->monthlyIsUnlimited()) {
            throw new \LogicException(
                'monthlyRemaining() must not be called when the monthly company cap is unlimited. ' .
                'Guard with monthlyIsUnlimited() before calling this method.'
            );
        }

        $cap = (int) $this->activePackage()->monthly_credits;

        return max(0, $cap - $this->usedInPeriod($start, $end, $excludeRunId));
    }

    /**
     * Remaining contact credits for the given period, floored at 0.
     *
     * Only call when not monthly-contact-unlimited — throws LogicException if
     * called for an unlimited package (mirrors contactRemainingOn()).
     *
     * @param  CarbonInterface  $start         Inclusive period start (from currentPeriod()).
     * @param  CarbonInterface  $end           Exclusive period end (from currentPeriod()).
     * @param  int|null         $excludeRunId  Run to exclude from the used tally.
     * @throws \LogicException
     */
    public function monthlyContactRemaining(CarbonInterface $start, CarbonInterface $end, ?int $excludeRunId = null): int
    {
        if ($this->monthlyContactIsUnlimited()) {
            throw new \LogicException(
                'monthlyContactRemaining() must not be called when the monthly contact cap is unlimited. ' .
                'Guard with monthlyContactIsUnlimited() before calling this method.'
            );
        }

        $cap = (int) $this->activePackage()->monthly_contact_credits;

        return max(0, $cap - $this->contactUsedInPeriod($start, $end, $excludeRunId));
    }

    /**
     * Remaining monthly company credits for today's period, or null when unlimited.
     *
     * Convenience accessor for UI/controller display — computes currentPeriod()
     * internally so callers do not need to pin the window themselves. Mirrors
     * remainingTodayForDisplay() for the monthly meter.
     */
    public function monthlyRemainingForDisplay(): ?int
    {
        if ($this->monthlyIsUnlimited()) {
            return null;
        }

        [$start, $end] = $this->currentPeriod();

        return $this->monthlyRemaining($start, $end);
    }

    /**
     * Remaining monthly contact credits for today's period, or null when unlimited.
     *
     * Mirrors contactRemainingTodayForDisplay() for the monthly meter.
     */
    public function monthlyContactRemainingForDisplay(): ?int
    {
        if ($this->monthlyContactIsUnlimited()) {
            return null;
        }

        [$start, $end] = $this->currentPeriod();

        return $this->monthlyContactRemaining($start, $end);
    }

    // ── Batch sizing ───────────────────────────────────────────────────────────

    /**
     * Effective batch size for a dispatch preview.
     *
     * Computes min(wantedBatch, <all active company caps>) so UI/preview callers
     * get the correct (smaller) batch even when daily is unlimited but monthly is
     * limited (finding #10 — the old isUnlimited() early-return masked the monthly cap).
     *
     * Active caps: each cap is appended to $caps ONLY after its *IsUnlimited() guard
     * passes, so null never reaches min() (PHP null footgun).
     *
     * Returns $wantedBatch unmodified only when BOTH daily AND monthly company caps
     * are unlimited (or no active package exists).
     *
     * Returns 0 when all-active caps have been exhausted.
     *
     * NOTE: NOT called inside reserveRun() — the remaining value is re-computed
     * inside the lock-protected transaction. External callers (display, preview) only.
     */
    public function effectiveBatchFor(ProspectCriteria $criteria): int
    {
        $wantedBatch = $criteria->daily_limit ?: 20;
        $today       = $this->today();

        // Collect only the caps that are active (not unlimited); each guarded before append.
        $caps = [];
        if (! $this->isUnlimited())        $caps[] = $this->remainingOn($today);
        if (! $this->monthlyIsUnlimited()) {
            [$pStart, $pEnd] = $this->currentPeriod($today);
            $caps[] = $this->monthlyRemaining($pStart, $pEnd);
        }

        // No active caps → both meters unlimited; return the wanted batch as-is.
        if ($caps === []) {
            return $wantedBatch;
        }

        return min($wantedBatch, min($caps));
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

                $latest = $criteria->discoveryRuns()
                    ->where('type', 'discovery')
                    ->latest('id')
                    ->first();

                if ($latest && in_array($latest->status, ['pending', 'running'], true) && ! $latest->isStale()) {
                    throw new DiscoveryRunInFlightException($latest);
                }

                $today       = $this->today();
                $wantedBatch = $criteria->daily_limit ?: 20;

                // Pin the monthly period ONCE — both company and contact monthly reads
                // use this pinned window so a run straddling midnight cannot read two
                // different windows for its two meters (finding #4 pin-once rule).
                [$pStart, $pEnd] = $this->currentPeriod($today);

                // Company meter — collect only the caps that apply (each guarded by
                // *IsUnlimited() before append so null never reaches min()).
                $caps = [];
                if (! $this->isUnlimited())        $caps[] = $this->remainingOn($today);
                if (! $this->monthlyIsUnlimited()) $caps[] = $this->monthlyRemaining($pStart, $pEnd);

                $batch = $caps === [] ? $wantedBatch : min($wantedBatch, min($caps));

                // Throw if ANY active company cap is binding at zero — moved OUTSIDE
                // the individual cap blocks so it fires when daily is unlimited but
                // monthly is the binding zero (the old code inside !isUnlimited()
                // silently skipped the monthly check in that case).
                if ($caps !== [] && $batch <= 0) {
                    throw new QuotaExhaustedException();
                }

                // Contact meter: reserve as many as remain (or full batch if unlimited).
                // Contact exhaustion does NOT throw — the run proceeds at reduced scope.
                // Each cap is appended only after its *IsUnlimited() guard so null
                // never reaches min() (PHP null footgun).
                $contactCaps = [$batch];   // upper-bound by company batch
                if ($criteria->contact_limit !== null)    $contactCaps[] = (int) $criteria->contact_limit;  // per-criteria cap
                if (! $this->contactIsUnlimited())        $contactCaps[] = $this->contactRemainingOn($today);
                if (! $this->monthlyContactIsUnlimited()) $contactCaps[] = $this->monthlyContactRemaining($pStart, $pEnd);
                $contactReserved = min($contactCaps);

                return DiscoveryRun::create([
                    'prospect_criteria_id'     => $criteria->id,
                    'status'                   => 'pending',
                    'credits_reserved'         => $batch,
                    'consumed'                 => 0,
                    'contact_credits_reserved' => $contactReserved,
                    'contact_consumed'         => 0,
                    'quota_date'               => $today->toDateString(),
                    'package_assignment_id'    => PackageAssignment::latestActive()?->id,
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

    // ── Manual enrichment reservation ─────────────────────────────────────────

    /**
     * Reserve a manual enrichment run for a single company.
     *
     * Uses the same GET_LOCK + transaction protocol as reserveRun() for
     * serialization. Credits_reserved = 1 and consumed = 1 (debit-before-call
     * semantics — no refund on Hunter failure).
     *
     * Guards (checked inside the lock+transaction):
     *   - Same-company in-flight: running non-stale manual row for this company → EnrichmentInFlightException (409)
     *   - Quota exhausted (limited packages only)               → QuotaExhaustedException (422)
     *
     * @throws EnrichmentInFlightException    When a non-stale manual run is already running for this company.
     * @throws QuotaExhaustedException        When the daily balance is 0.
     * @throws QuotaLockUnavailableException  When the MySQL lock cannot be acquired (fail-closed).
     */
    public function reserveManualEnrichment(Company $company): DiscoveryRun
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
            $run = DB::transaction(function () use ($company) {
                // Same-company in-flight guard: look for any running manual row
                // for this company that is not stale.
                $candidates = DiscoveryRun::where('type', 'manual')
                    ->where('company_id', $company->id)
                    ->whereIn('status', ['running'])
                    ->get();

                foreach ($candidates as $candidate) {
                    if (! $candidate->isStale()) {
                        throw new EnrichmentInFlightException();
                    }
                }

                $today = $this->today();

                // Pin the monthly period once — both daily and monthly contact guards
                // use the same window so they cannot see two different periods.
                [$pStart, $pEnd] = $this->currentPeriod($today);

                // Contact quota guard: manual enrichment debits the contact meter only.
                // Both the daily AND monthly contact caps must have remaining balance.
                // Guard *IsUnlimited() first to avoid null arithmetic on each.
                // Unlike the discovery path, manual enrich DOES throw on contact exhaustion.
                if (! $this->contactIsUnlimited() && $this->contactRemainingOn($today) <= 0) {
                    throw new QuotaExhaustedException();
                }
                if (! $this->monthlyContactIsUnlimited() && $this->monthlyContactRemaining($pStart, $pEnd) <= 0) {
                    throw new QuotaExhaustedException();
                }

                return DiscoveryRun::create([
                    'prospect_criteria_id'     => $company->criteria_id ?? null,
                    'type'                     => 'manual',
                    'company_id'               => $company->id,
                    'status'                   => 'running',
                    'credits_reserved'         => 0,  // no company meter debit
                    'consumed'                 => 0,  // no company meter debit
                    'contact_credits_reserved' => 1,
                    'contact_consumed'         => 1,
                    'quota_date'               => $today->toDateString(),
                    'started_at'               => now(),
                    'companies_count'          => 0,
                    'contacts_count'           => 0,
                    'skipped_count'            => 0,
                    'low_score_count'          => 0,
                    'package_assignment_id'    => PackageAssignment::latestActive()?->id,
                ]);
            });

            return $run;
        } finally {
            if ($isMySQL) {
                DB::selectOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    // ── Reporting ──────────────────────────────────────────────────────────────

    /**
     * Daily series of discovery + contact consumption over [$start, $end).
     *
     * Zero-fills every day in the half-open range so the caller always gets a
     * dense series (no gaps for days with no runs) — CarbonPeriod is INCLUSIVE
     * by default, so we fill $start through $end->copy()->subDay() to land
     * exactly on the half-open range's calendar days.
     *
     * Accounting shape MUST match usedOn()/contactUsedOn(): discoveries subtract
     * COALESCE(excluded_count, 0) from `consumed` (mirrors usedOn() — a candidate
     * rejected by AI targeting still advances `consumed` but is refunded via
     * excluded_count) for both terminal and in-flight branches; contacts mirror
     * contactUsedOn() and are NOT excluded_count-adjusted (plain contact_consumed).
     * Otherwise the chart's "today" point disagrees with the today progress bar
     * rendered from usedOn() on the same page.
     *
     * @param  CarbonInterface  $start  Inclusive lower bound.
     * @param  CarbonInterface  $end    Exclusive upper bound.
     * @return array{dates: array<string>, discoveries: array<int>, contacts: array<int>}
     */
    public function dailySeries(CarbonInterface $start, CarbonInterface $end): array
    {
        $rows = DiscoveryRun::query()
            ->where('quota_date', '>=', $start->toDateString())
            ->where('quota_date', '<',  $end->toDateString())
            ->selectRaw(
                "quota_date,
                 COALESCE(SUM(CASE
                    WHEN status IN ('completed', 'failed') THEN (consumed - COALESCE(excluded_count, 0))
                    ELSE CASE WHEN credits_reserved > (consumed - COALESCE(excluded_count, 0))
                        THEN credits_reserved
                        ELSE (consumed - COALESCE(excluded_count, 0))
                    END
                 END), 0) as discoveries,
                 COALESCE(SUM(CASE
                    WHEN status IN ('completed', 'failed') THEN contact_consumed
                    ELSE CASE WHEN contact_credits_reserved > contact_consumed THEN contact_credits_reserved ELSE contact_consumed END
                 END), 0) as contacts"
            )
            ->groupBy('quota_date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->quota_date)->toDateString());

        $dates       = [];
        $discoveries = [];
        $contacts    = [];

        // CarbonPeriod is inclusive of both endpoints — stop at $end minus a day
        // so the half-open [$start, $end) range yields exactly the right day count.
        foreach (\Carbon\CarbonPeriod::create($start, $end->copy()->subDay()) as $day) {
            $key           = $day->toDateString();
            $row           = $rows->get($key);
            $dates[]       = $key;
            $discoveries[] = $row ? (int) $row->discoveries : 0;
            $contacts[]    = $row ? (int) $row->contacts : 0;
        }

        return [
            'dates'       => $dates,
            'discoveries' => $discoveries,
            'contacts'    => $contacts,
        ];
    }

    /**
     * Per-criteria breakdown of runs/companies/contacts/credits over [$start, $end).
     *
     * Grouping key is CASE WHEN type='manual' THEN NULL ELSE prospect_criteria_id END —
     * manual enrichment runs carry the target company's criteria_id (see
     * reserveManualEnrichment()), so grouping by prospect_criteria_id alone would
     * fold manual-enrichment spend into that criterion's row. Bucketing manual
     * runs under a NULL key keeps them in their own "Enrichissement manuel" row.
     *
     * @param  CarbonInterface  $start  Inclusive lower bound.
     * @param  CarbonInterface  $end    Exclusive upper bound.
     * @return \Illuminate\Support\Collection
     */
    public function perCriteriaBreakdown(CarbonInterface $start, CarbonInterface $end): \Illuminate\Support\Collection
    {
        return DiscoveryRun::query()
            ->leftJoin('prospect_criteria', function ($join) {
                $join->on('prospect_criteria.id', '=', DB::raw(
                    "(CASE WHEN discovery_runs.type = 'manual' THEN NULL ELSE discovery_runs.prospect_criteria_id END)"
                ));
            })
            ->where('discovery_runs.quota_date', '>=', $start->toDateString())
            ->where('discovery_runs.quota_date', '<',  $end->toDateString())
            // consumed is EFFECTIVE (net of AI-excluded candidates), matching the
            // usedOn()/usedInPeriod() meter — not raw consumed.
            ->selectRaw(
                "CASE WHEN discovery_runs.type = 'manual' THEN NULL ELSE discovery_runs.prospect_criteria_id END as criteria_key,
                 COALESCE(prospect_criteria.name, 'Enrichissement manuel') as label,
                 COUNT(*) as runs_count,
                 COALESCE(SUM(discovery_runs.companies_count), 0) as companies_count,
                 COALESCE(SUM(discovery_runs.new_companies_count), 0) as new_companies_count,
                 COALESCE(SUM(discovery_runs.contacts_count), 0) as contacts_count,
                 COALESCE(SUM(discovery_runs.consumed - COALESCE(discovery_runs.excluded_count, 0)), 0) as consumed,
                 COALESCE(SUM(discovery_runs.contact_consumed), 0) as contact_consumed"
            )
            ->groupBy('criteria_key', 'label')
            ->orderByDesc('runs_count')
            ->get();
    }
}
