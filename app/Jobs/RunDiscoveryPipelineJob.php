<?php

namespace App\Jobs;

use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Support\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
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
 * Queue: default (database driver in fretiq).
 * Retry policy: 2 attempts.
 *
 * Concurrency: WithoutOverlapping keyed on criteriaId. A blocked duplicate is
 * released back to the queue (default); after $tries attempts it is terminalized
 * as failed by the failed() hook — no silent zombie pending row.
 * expireAfter(360) releases the lock if the job crashes before the lock is freed.
 *
 * timeout/retry_after pair: $timeout=300, retry_after=600 (config/queue.php).
 * retry_after must exceed timeout to avoid concurrent re-release by the DB driver.
 *
 * DiscoveryPipelineService is resolved via app() which auto-wires its two
 * concrete constructor dependencies — no manual binding needed.
 */
class RunDiscoveryPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Maximum number of retry attempts before marking as failed. */
    public int $tries = 2;

    /** @var int Maximum seconds this job may run before the worker kills it. */
    public int $timeout = 300;

    public function __construct(
        private readonly int $criteriaId,
        private readonly ?int $runId = null,
    ) {}

    /**
     * Prevent concurrent executions for the same criteria.
     * A blocked duplicate is RELEASED back to the queue (default behavior); after
     * $tries attempts it is terminalized as failed by the failed() hook — no silent
     * zombie pending row is ever left behind.
     * expireAfter(360) releases the lock after 6 min even if the job crashes.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->criteriaId))->expireAfter(360)];
    }

    /**
     * Execute the discovery pipeline.
     */
    public function handle(): void
    {
        // 🔴 Guard with isset — never rely on the default applying to in-flight
        // serialized jobs that were queued before the runId parameter existed.
        $run = isset($this->runId) ? DiscoveryRun::find($this->runId) : null;

        // Pre-flight status check: a terminalized (failed) run must never execute.
        // The stale terminalizer or a failed() hook may have flipped the row to
        // 'failed' between dispatch and pick-up. Fresh DB read is authoritative.
        if ($run !== null && ! in_array($run->status, ['pending', 'running'], true)) {
            Log::info('[RunDiscoveryPipelineJob] Run is already in a terminal state — aborting.', [
                'run_id'      => $this->runId,
                'run_status'  => $run->status,
                'criteria_id' => $this->criteriaId,
            ]);
            return;
        }

        $criteria = ProspectCriteria::find($this->criteriaId);

        if (! $criteria) {
            Log::warning('[RunDiscoveryPipelineJob] ProspectCriteria not found — aborting.', [
                'criteria_id' => $this->criteriaId,
            ]);
            $run?->update([
                'status'      => 'failed',
                'error'       => 'Critère introuvable ou inactif',
                'finished_at' => now(),
            ]);
            return;
        }

        // E1: re-check is_active inside the job. Covers the queued-then-deactivated race
        // and future scheduler dispatch.
        if (! $criteria->is_active) {
            Log::info('[RunDiscoveryPipelineJob] Criteria inactive — skipping.', [
                'criteria_id'   => $this->criteriaId,
                'criteria_name' => $criteria->name,
            ]);
            $run?->update([
                'status'      => 'failed',
                'error'       => 'Critère introuvable ou inactif',
                'finished_at' => now(),
            ]);
            return;
        }

        Log::info('[RunDiscoveryPipelineJob] Starting discovery pipeline', [
            'criteria_id'   => $this->criteriaId,
            'criteria_name' => $criteria->name,
        ]);

        // ── SerpAPI search-call budget check ───────────────────────────────────
        // Compute what this attempt is still allowed to fetch from SerpAPI.
        // Candidate processing uses DiscoveryRun.consumed as its cursor; provider
        // calls use searches_reserved/searches_consumed.
        $searchBudget = null; // null = legacy/no run row; pipeline falls back to criteria daily_limit

        if ($run !== null) {
            $reservedSearches = (int) ($run->searches_reserved ?? $run->credits_reserved);
            $searchBudget = max(0, $reservedSearches - (int) ($run->searches_consumed ?? 0));
            $snapshotCount = is_array($run->candidates_snapshot) ? count($run->candidates_snapshot) : 0;
            $hasUnprocessedSnapshot = $snapshotCount > (int) $run->consumed;

            // If the full search reservation has already been consumed on a retry,
            // still process any candidates already present in the durable snapshot.
            if ($searchBudget === 0 && ! $hasUnprocessedSnapshot) {
                Log::info('[RunDiscoveryPipelineJob] SerpAPI search budget épuisé (retry) — aborting.', [
                    'criteria_id'       => $this->criteriaId,
                    'run_id'            => $this->runId,
                    'searches_reserved' => $reservedSearches,
                    'searches_consumed' => (int) ($run->searches_consumed ?? 0),
                    'consumed'          => $run->consumed,
                ]);
                $run->update([
                    'status'      => 'failed',
                    'error'       => 'Budget SerpAPI épuisé (retry)',
                    'finished_at' => now(),
                ]);
                return;
            }

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
                    $dailyCap  = (int) $quotaService->activePackage()?->daily_credits;
                    $available = max(0, $dailyCap - $usedByOthers);
                    $searchBudget = min($searchBudget, $available);
                }

                // ── Monthly company cap ────────────────────────────────────────
                // Applied even when daily is unlimited (run must abort if monthly is zero).
                // Restructured so the single abort check below covers both caps.
                if (! $quotaService->monthlyIsUnlimited()) {
                    $monthlyUsedByOthers = $quotaService->usedInPeriod($pStart, $pEnd, $run->id);
                    $monthlyCap          = (int) $quotaService->activePackage()?->monthly_credits;
                    $searchBudget        = min($searchBudget, max(0, $monthlyCap - $monthlyUsedByOthers));
                }

                // Single abort: fires whether daily, monthly, or both drove search budget to 0.
                if ($searchBudget === 0 && ! $hasUnprocessedSnapshot) {
                    Log::info('[RunDiscoveryPipelineJob] Solde épuisé (daily ou mensuel) — aborting.', [
                        'criteria_id' => $this->criteriaId,
                        'run_id'      => $this->runId,
                        'quota_date'  => $run->quota_date->toDateString(),
                    ]);
                    $run->update([
                        'status'      => 'failed',
                        'error'       => 'Budget épuisé (retry)',
                        'finished_at' => now(),
                    ]);
                    return;
                }
            }
        }

        // ── Contact quota budget check ─────────────────────────────────────────
        // $contactBudget = null means unlimited (no enrichment cap).
        // Exhausted contact budget does NOT abort the run — discovery still runs,
        // Hunter is simply skipped for all candidates.
        $contactBudget = null;

        if ($run !== null) {
            $contactBudget = max(0, (int) $run->contact_credits_reserved - (int) $run->contact_consumed);

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
                    $dailyCap    = (int) $quotaService->activePackage()?->daily_contact_credits;
                    $available   = max(0, $dailyCap - $contactUsed);
                    $contactBudget = min($contactBudget, $available);
                }

                // ── Monthly contact cap ────────────────────────────────────────
                // Clamp only — contact exhaustion NEVER aborts the run (mirrors
                // the reservation path's contact asymmetry).
                if (! $quotaService->monthlyContactIsUnlimited()) {
                    $monthlyContactUsed = $quotaService->contactUsedInPeriod($pStart, $pEnd, $run->id);
                    $monthlyCap         = (int) $quotaService->activePackage()?->monthly_contact_credits;
                    $contactBudget      = min($contactBudget, max(0, $monthlyCap - $monthlyContactUsed));
                }
            }
        }

        // Mark the run as in-progress before we call the pipeline.
        $run?->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);

        try {
            $stats = $pipeline->run($criteria, $searchBudget, $run, $contactBudget); // returns ['companies'=>int,'contacts'=>int,'skipped'=>int,'low_score'=>int,'contacts_consumed'=>int]

            // Counts (companies_count, contacts_count, skipped_count, low_score_count)
            // are now persisted incrementally by the pipeline's CAS UPDATE after each
            // upsert. The job completion writes ONLY the terminal status + finished_at.
            $run?->update([
                'status'      => 'completed',
                'finished_at' => now(),
            ]);

            Log::info('[RunDiscoveryPipelineJob] Pipeline completed', array_merge(
                ['criteria_id' => $this->criteriaId],
                $stats
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

        $run = DiscoveryRun::find($this->runId);

        if ($run && $run->status !== 'completed') {
            $run->update([
                'status'      => 'failed',
                'error'       => Str::limit($e->getMessage(), 1000),
                'finished_at' => now(),
            ]);
        }
    }
}
