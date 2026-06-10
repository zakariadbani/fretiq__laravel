<?php

namespace App\Jobs;

use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryPipelineService;
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

        // Mark the run as in-progress before we call the pipeline.
        $run?->update([
            'status'     => 'running',
            'started_at' => now(),
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);

        try {
            $stats = $pipeline->run($criteria); // returns ['companies'=>int,'contacts'=>int,'skipped'=>int]

            $run?->update([
                'status'          => 'completed',
                'companies_count' => $stats['companies'] ?? 0,   // explicit remap: service key 'companies' → column 'companies_count'
                'contacts_count'  => $stats['contacts'] ?? 0,
                'skipped_count'   => $stats['skipped'] ?? 0,
                'finished_at'     => now(),
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
