<?php

namespace App\Jobs;

use App\Exceptions\CriteriaCompanyNoLongerEligibleException;
use App\Exceptions\DiscoveryConfigurationException;
use App\Exceptions\EnrichmentInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Services\Discovery\CriteriaContactEnrichmentService;
use App\Services\Discovery\DiscoveryClaimFinalizer;
use App\Services\Discovery\DiscoveryExecutionDeadline;
use App\Services\Discovery\DiscoveryPipelineResult;
use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RunDiscoveryPipelineJob — queued cold-discovery run for a single ProspectCriteria.
 *
 * Dispatched by:
 *   - `prospect:discover` artisan command (--queue flag, future)
 *   - Scheduled runs (routes/console.php)
 *   - Manual trigger from the UI (ProspectCriteriaController::discover)
 *
 * Queue: discovery (database driver in fretiq).
 * Retry policy: up to 20 resumable attempts, with at most 2 thrown exceptions.
 *
 * Concurrency: WithoutOverlapping keyed on criteriaId. A blocked duplicate is
 * released back to the queue (default); after $tries attempts it is terminalized
 * as failed by the failed() hook — no silent zombie pending row.
 * expireAfter(570) releases the lock if the job crashes before the lock is freed.
 *
 * timeout/retry_after pair: $timeout=540, retry_after=600 (config/queue.php).
 * retry_after must exceed timeout to avoid concurrent re-release by the DB driver.
 *
 * DiscoveryPipelineService is resolved via app() which auto-wires its two
 * concrete constructor dependencies — no manual binding needed.
 */
class RunDiscoveryPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Maximum number of resumable attempts before marking as failed. */
    public int $tries = 20;

    /** @var int Thrown exceptions allowed independently of normal releases. */
    public int $maxExceptions = 2;

    /** @var int Maximum seconds this job may run before the worker kills it. */
    public int $timeout = 540;

    public function __construct(
        private readonly int $criteriaId,
        private readonly ?int $runId = null,
    ) {
        $this->onQueue('discovery');
    }

    /**
     * Prevent concurrent executions for the same criteria.
     * A blocked duplicate is RELEASED back to the queue (default behavior); after
     * $tries attempts it is terminalized as failed by the failed() hook — no silent
     * zombie pending row is ever left behind.
     * Discovery and explicit missing-contact jobs share this criteria-level lock.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("criteria-enrichment:{$this->criteriaId}"))
            ->shared()
            ->releaseAfter(30)
            ->expireAfter(570)];
    }

    /**
     * Execute the discovery pipeline.
     */
    public function handle(): void
    {
        $attemptStartedAt = microtime(true);
        $attemptDeadline = new DiscoveryExecutionDeadline(
            $attemptStartedAt,
            $this->discoveryAttemptBudget(),
        );

        // Guard with isset — never rely on the default applying to in-flight
        // serialized jobs that were queued before the runId parameter existed.
        // An explicit id is never allowed to degrade into the legacy untracked path.
        $run = null;
        if (isset($this->runId)) {
            $run = $this->ownedRunQuery()->first();

            if ($run === null) {
                Log::warning('[RunDiscoveryPipelineJob] Run does not belong to this discovery criterion — aborting.', [
                    'run_id' => $this->runId,
                    'criteria_id' => $this->criteriaId,
                ]);

                return;
            }
        }

        // Pre-flight status check: a terminalized (failed) run must never execute.
        // The stale terminalizer or a failed() hook may have flipped the row to
        // 'failed' between dispatch and pick-up. Fresh DB read is authoritative.
        if ($run !== null && ! in_array($run->status, ['pending', 'running'], true)) {
            Log::info('[RunDiscoveryPipelineJob] Run is already in a terminal state — aborting.', [
                'run_id' => $this->runId,
                'run_status' => $run->status,
                'criteria_id' => $this->criteriaId,
            ]);

            return;
        }

        $criteria = ProspectCriteria::find($this->criteriaId);

        if (! $criteria) {
            Log::warning('[RunDiscoveryPipelineJob] ProspectCriteria not found — aborting.', [
                'criteria_id' => $this->criteriaId,
            ]);
            $this->terminalizeActiveRun('Critère introuvable ou inactif');

            return;
        }

        // E1: re-check is_active inside the job. Covers the queued-then-deactivated race
        // and future scheduler dispatch.
        if (! $criteria->is_active) {
            Log::info('[RunDiscoveryPipelineJob] Criteria inactive — skipping.', [
                'criteria_id' => $this->criteriaId,
                'criteria_name' => $criteria->name,
            ]);
            $this->terminalizeActiveRun('Critère introuvable ou inactif');

            return;
        }

        // Acquire the active run state with guarded writes. Only the pending ->
        // running transition sets started_at; retries refresh updated_at alone.
        if ($run !== null) {
            $started = $this->ownedRunQuery()
                ->where('status', 'pending')
                ->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($started === 0) {
                $this->ownedRunQuery()
                    ->where('status', 'running')
                    ->update(['updated_at' => now()]);
            }

            $run = $this->ownedRunQuery()->first();
            if ($run === null || $run->status !== 'running') {
                return;
            }
        }

        Log::info('[RunDiscoveryPipelineJob] Starting discovery pipeline', [
            'criteria_id' => $this->criteriaId,
            'criteria_name' => $criteria->name,
        ]);

        // ── SerpAPI search-call budget check ───────────────────────────────────
        // Compute what this attempt is still allowed to fetch from SerpAPI.
        // Candidate processing uses DiscoveryRun.consumed as its cursor; provider
        // calls use searches_reserved/searches_consumed.
        $searchBudget = null; // null = legacy/no run row; pipeline falls back to criteria daily_limit

        if ($run !== null) {
            $reservedSearches = (int) ($run->searches_reserved ?? $run->credits_reserved);
            $searchesConsumed = (int) ($run->searches_consumed ?? 0);
            $effectiveReservation = $reservedSearches;
            // Re-check remaining against THIS run's quota_date AND monthly period,
            // excluding our own reservation (run->id) from the used tally.
            //
            // The exclusion is critical for the monthly cap: without it, a run's own
            // in-flight reservation fills the entire monthly budget and reduces its
            // own effective batch to 0 on the first run of any month where the
            // monthly cap is the binding constraint (finding #3).
            if ($run->quota_date !== null) {
                /** @var DiscoveryQuotaService $quotaService */
                $quotaService = app(DiscoveryQuotaService::class);

                // Pin the monthly period off the run's quota_date (not today), so that
                // retries queued on a prior day map to the window that was active when
                // the run was first dispatched. Reuse for both company + contact monthly.
                [$pStart, $pEnd] = $quotaService->currentPeriod(Carbon::parse($run->quota_date));

                // ── Daily company cap ──────────────────────────────────────────
                if (! $quotaService->isUnlimited()) {
                    $usedByOthers = $quotaService->usedOn(
                        Carbon::parse($run->quota_date),
                        $run->id  // exclude this run's own reservation from the sum
                    );
                    $dailyCap = (int) $quotaService->activePackage()?->daily_credits;
                    $effectiveReservation = min(
                        $effectiveReservation,
                        max(0, $dailyCap - $usedByOthers),
                    );
                }

                // ── Monthly company cap ────────────────────────────────────────
                // Applied even when daily is unlimited (run must abort if monthly is zero).
                // Restructured so the single abort check below covers both caps.
                if (! $quotaService->monthlyIsUnlimited()) {
                    $monthlyUsedByOthers = $quotaService->usedInPeriod($pStart, $pEnd, $run->id);
                    $monthlyCap = (int) $quotaService->activePackage()?->monthly_credits;
                    $effectiveReservation = min(
                        $effectiveReservation,
                        max(0, $monthlyCap - $monthlyUsedByOthers),
                    );
                }
            }

            // A package can be reduced after reservation. Shrink the durable
            // search reservation to the new lifetime ceiling (never below spent)
            // so a zero-cap run becomes terminal/contact-only instead of being
            // released forever with unspendable SerpAPI credits.
            $effectiveReservation = max($searchesConsumed, $effectiveReservation);
            if ($effectiveReservation < $reservedSearches) {
                $this->ownedRunQuery()
                    ->where('status', 'running')
                    ->update([
                        'searches_reserved' => $effectiveReservation,
                        'credits_reserved' => min(
                            (int) $run->credits_reserved,
                            $effectiveReservation,
                        ),
                        'updated_at' => now(),
                    ]);
                $run->searches_reserved = $effectiveReservation;
                $run->credits_reserved = min(
                    (int) $run->credits_reserved,
                    $effectiveReservation,
                );
            }

            $searchBudget = max(0, $effectiveReservation - $searchesConsumed);
        }

        // ── Contact quota budget check ─────────────────────────────────────────
        // $contactBudget = null means unlimited (no enrichment cap).
        // Exhausted contact budget does NOT abort the run — discovery still runs,
        // Hunter is simply skipped for all candidates.
        $contactBudget = null;

        if ($run !== null) {
            $contactsConsumed = (int) $run->contact_consumed;
            $contactBudget = max(0, (int) $run->contact_credits_reserved - $contactsConsumed);
            $successesRemaining = max(
                0,
                (int) $run->successful_enrichments_target - (int) $run->successful_enrichments,
            );
            if ($successesRemaining === 0) {
                $contactBudget = 0;
            }

            if ($run->quota_date !== null) {
                /** @var DiscoveryQuotaService $quotaService */
                $quotaService = app(DiscoveryQuotaService::class);

                // Pin the monthly period once — reused for both daily-contact and
                // monthly-contact checks (same $pStart/$pEnd as the company block above
                // is NOT accessible here, so we re-derive it; the cost is minimal).
                [$pStart, $pEnd] = $quotaService->currentPeriod(Carbon::parse($run->quota_date));

                // ── Daily contact cap ──────────────────────────────────────────
                // GUARD FIRST — contactUsedOn is fine here (it's the raw sum, not remainingOn);
                // but we still guard contactIsUnlimited() for symmetry and null-safety.
                if (! $quotaService->contactIsUnlimited()) {
                    $contactUsed = $quotaService->contactUsedOn(Carbon::parse($run->quota_date), $run->id);
                    $dailyCap = (int) $quotaService->activePackage()?->daily_contact_credits;
                    $available = max(0, $dailyCap - $contactUsed - $contactsConsumed);
                    $contactBudget = min($contactBudget, $available);
                }

                // ── Monthly contact cap ────────────────────────────────────────
                // Clamp only — contact exhaustion NEVER aborts the run (mirrors
                // the reservation path's contact asymmetry).
                if (! $quotaService->monthlyContactIsUnlimited()) {
                    $monthlyContactUsed = $quotaService->contactUsedInPeriod($pStart, $pEnd, $run->id);
                    $monthlyCap = (int) $quotaService->activePackage()?->monthly_contact_credits;
                    $contactBudget = min(
                        $contactBudget,
                        max(0, $monthlyCap - $monthlyContactUsed - $contactsConsumed),
                    );
                }
            }
        }

        $automaticEnrichment = $run !== null && $this->automaticEnrichmentEnabled($criteria);
        $providerCircuitOpen = $automaticEnrichment && $this->providerCircuitWasOpened($run);
        $backlogRemains = false;
        $backlogNeedsContinuation = false;
        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);

        if ($automaticEnrichment) {
            $eligibility = app(CriteriaContactEnrichmentService::class);
            $enrichment = app(CompanyEnrichmentService::class);
            $quota = app(DiscoveryQuotaService::class);
            $contactBudgetAtPhaseStart = (int) $contactBudget;
            $contactConsumedAtPhaseStart = (int) $run->contact_consumed;

            if (! $providerCircuitOpen && $contactBudget > 0) {
                foreach ($eligibility->automaticEligibleIds($criteria, $run->id) as $companyId) {
                    $timeout = $attemptDeadline->timeout(20);
                    if ($timeout === null) {
                        break;
                    }

                    $run->refresh();
                    $phaseAttempts = (int) $run->contact_consumed - $contactConsumedAtPhaseStart;
                    if ($phaseAttempts >= $contactBudgetAtPhaseStart
                        || (int) $run->contact_consumed >= (int) $run->contact_credits_reserved
                        || (int) $run->successful_enrichments >= (int) $run->successful_enrichments_target) {
                        break;
                    }

                    try {
                        $claimed = $quota->claimAutomaticEnrichment((int) $companyId, $run);
                        $candidate = collect($run->candidates_snapshot ?? [])->first(
                            fn ($item): bool => is_array($item)
                                && ($item['domain'] ?? null) === $claimed->domain,
                        );
                        $fallbackCountry = $pipeline->countryFallback($criteria, is_array($candidate) ? $candidate : []);
                        $outcome = $enrichment->enrichClaimed($claimed, $run, $timeout, $fallbackCountry);

                        if ($outcome['outcome'] === 'provider_failed') {
                            $providerCircuitOpen = true;
                            break;
                        }
                    } catch (CriteriaCompanyNoLongerEligibleException|EnrichmentInFlightException) {
                        continue;
                    } catch (QuotaExhaustedException) {
                        break;
                    } catch (QuotaLockUnavailableException) {
                        Log::warning('[RunDiscoveryPipelineJob] Quota admission lock unavailable during backlog processing.', [
                            'criteria_id' => $this->criteriaId,
                            'run_id' => $run->id,
                        ]);
                        break;
                    }
                }
            }

            $run->refresh();
            $backlogRemains = $eligibility
                ->automaticBacklogExists($criteria, $run->id);
            $phaseAttempts = max(
                0,
                (int) $run->contact_consumed - $contactConsumedAtPhaseStart,
            );
            $contactBudget = min(
                max(0, (int) $run->contact_credits_reserved - (int) $run->contact_consumed),
                max(0, $contactBudgetAtPhaseStart - $phaseAttempts),
            );
            $successesRemaining = max(
                0,
                (int) $run->successful_enrichments_target - (int) $run->successful_enrichments,
            );
            if ($successesRemaining === 0) {
                $contactBudget = 0;
            }

            // A deadline or transient admission-lock failure may leave backlog
            // while this parent still owns usable Hunter capacity. Discovery may
            // finish in this attempt, but the run must remain resumable until the
            // backlog phase gets another chance to spend that reservation.
            $backlogNeedsContinuation = $backlogRemains
                && ! $providerCircuitOpen
                && $contactBudget > 0;

            if ($backlogRemains || $providerCircuitOpen) {
                $contactBudget = 0;
            }
        }

        try {
            // Backlog enrichment and discovery each receive their own bounded
            // window. This keeps a full backlog from consuming the time needed
            // to continue SerpAPI collection and candidate processing.
            $discoveryStartedAt = microtime(true);
            $discoveryDeadline = new DiscoveryExecutionDeadline(
                $discoveryStartedAt,
                $this->discoveryAttemptBudget(),
            );

            $result = $providerCircuitOpen
                ? $pipeline->run($criteria, $searchBudget, $run, $contactBudget, $discoveryStartedAt, true, $discoveryDeadline)
                : $pipeline->run($criteria, $searchBudget, $run, $contactBudget, $discoveryStartedAt, false, $discoveryDeadline);

            if (! $result instanceof DiscoveryPipelineResult) {
                throw new \UnexpectedValueException('Le pipeline de découverte doit retourner un DiscoveryPipelineResult.');
            }

            if (! $result->isComplete() || $backlogNeedsContinuation) {
                if ($this->attempts() >= $this->tries) {
                    $message = sprintf(
                        'Découverte incomplète après %d tentatives. Vérifiez la disponibilité des services externes puis relancez.',
                        $this->tries,
                    );
                    $exception = new \RuntimeException($message);

                    $this->terminalizeActiveRun($message);
                    $this->fail($exception);

                    return;
                }

                if ($run !== null) {
                    // A stale terminalizer may have won between the pipeline return
                    // and this heartbeat. Do not requeue a now-terminal run. Ownership
                    // is re-asserted with an explicit exists() check — MySQL's PDO
                    // driver reports changed rows, not matched rows, so a same-second
                    // updated_at write returning 0 affected rows must never be read
                    // as "no longer running" (see DiscoveryRun::touchHeartbeat()).
                    if (! DiscoveryRun::touchHeartbeat($this->ownedRunQuery())) {
                        Log::info('[RunDiscoveryPipelineJob] Run no longer running — not requeuing.', [
                            'run_id' => $this->runId,
                            'criteria_id' => $this->criteriaId,
                        ]);

                        return;
                    }
                }

                // release() requeues this exact serialized job. Do not dispatch a
                // second job: a normal release consumes an attempt but does not
                // count against maxExceptions.
                $this->release(5);

                return;
            }

            // Counts (companies_count, contacts_count, skipped_count, low_score_count)
            // are now persisted incrementally by the pipeline's CAS UPDATE after each
            // upsert. The job completion writes ONLY the terminal status + finished_at.
            if ($run !== null) {
                DB::transaction(function () use ($run): void {
                    $completed = $this->ownedRunQuery()
                        ->where('status', 'running')
                        ->update([
                            'status' => 'completed',
                            'error' => null,
                            'finished_at' => now(),
                            'updated_at' => now(),
                        ]);

                    if ($completed === 1) {
                        app(DiscoveryClaimFinalizer::class)->releaseForTerminalRun((int) $run->id);
                    }
                });
            }

            $completedRun = $run?->fresh();
            Log::info('[RunDiscoveryPipelineJob] Pipeline completed', array_merge(
                ['criteria_id' => $this->criteriaId],
                $result->toArray(),
                [
                    'contact_attempts_reserved' => (int) ($completedRun?->contact_credits_reserved ?? 0),
                    'contact_attempts_consumed' => (int) ($completedRun?->contact_consumed ?? 0),
                    'successful_enrichments' => (int) ($completedRun?->successful_enrichments ?? 0),
                    'successful_enrichments_target' => (int) ($completedRun?->successful_enrichments_target ?? 0),
                    'contacts_created' => (int) ($completedRun?->contacts_count ?? 0),
                ],
            ));
        } catch (\Throwable $e) {
            // 🔴 Do NOT write 'failed' here — let failed() handle the terminal state
            // so that retry attempts (which re-enter handle()) don't clobber the row
            // after a retry succeeds. Just rethrow to preserve Laravel's retry logic.
            throw $e;
        }
    }

    /**
     * Called by the queue worker after all retry attempts are exhausted.
     * This is the single place that writes the 'failed' terminal state.
     *
     * Guard: only write if not already 'completed' — a race where the last retry
     * succeeded but failed() fires shortly after must not overwrite the success.
     */
    public function failed(\Throwable $e): void
    {
        if (! isset($this->runId)) {
            return;
        }

        Log::error('[RunDiscoveryPipelineJob] Discovery exhausted its exception retries.', [
            'run_id' => $this->runId,
            'criteria_id' => $this->criteriaId,
            'exception_class' => $e::class,
        ]);

        $message = $e instanceof DiscoveryConfigurationException
            ? $e->getMessage()
            : DiscoveryRun::UNEXPECTED_FAILURE_MESSAGE;

        $this->terminalizeActiveRun($message);
    }

    private function terminalizeActiveRun(string $error): void
    {
        if (! isset($this->runId)) {
            return;
        }

        DB::transaction(function () use ($error): void {
            $failed = $this->ownedRunQuery()
                ->whereIn('status', ['pending', 'running'])
                ->update([
                    'status' => 'failed',
                    'error' => Str::limit($error, 1000),
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($failed === 1) {
                app(DiscoveryClaimFinalizer::class)->releaseForTerminalRun((int) $this->runId);
            }
        });
    }

    private function ownedRunQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return DiscoveryRun::query()
            ->whereKey($this->runId)
            ->where('prospect_criteria_id', $this->criteriaId)
            ->where('type', 'discovery');
    }

    private function automaticEnrichmentEnabled(ProspectCriteria $criteria): bool
    {
        if ($criteria->auto_enrich !== null) {
            return (bool) $criteria->auto_enrich;
        }

        try {
            return (bool) Setting::get('decouverte.auto_enrich', false);
        } catch (\Illuminate\Database\QueryException) {
            // Allows pre-migration/isolated lifecycle checks to fail closed.
            return false;
        }
    }

    private function discoveryAttemptBudget(): int
    {
        try {
            $value = Setting::get('decouverte.run_time_budget', 240);
        } catch (\Illuminate\Database\QueryException) {
            return 240;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            return 240;
        }

        return max(30, min(240, (int) $value));
    }

    /** A provider failure is durable and scoped to this exact parent run. */
    private function providerCircuitWasOpened(DiscoveryRun $run): bool
    {
        return (bool) ($run->fresh()?->hunter_circuit_open ?? $run->hunter_circuit_open);
    }
}
