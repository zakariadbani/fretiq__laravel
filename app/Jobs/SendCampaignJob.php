<?php

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaign\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SendCampaignJob — processes a single CampaignRun through the send engine.
 *
 * Idempotency guarantees (queue-idempotency.md §3):
 *   - ShouldBeUnique: only one job per run_id in the queue at any time (uniqueFor=600s).
 *   - WithoutOverlapping: prevents two workers from executing the same run concurrently.
 *   - CampaignService::sendRun() is idempotent via claim-commit-then-send.
 *   - Retry policy: 5 attempts, exponential backoff, up to 6 hours.
 */
class SendCampaignJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum number of retry attempts before marking as failed. */
    public int $tries = 5;

    /** Job timeout in seconds — long enough for large recipient lists. */
    public int $timeout = 120;

    public function __construct(
        public readonly int $runId,
    ) {}

    // ── ShouldBeUnique ─────────────────────────────────────────────────────────

    /**
     * Unique cache key — prevents duplicate jobs in the queue for the same run.
     */
    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    /**
     * Hold the unique lock for 600 seconds after the job is dispatched.
     * This covers the case where the job is waiting in the queue.
     */
    public function uniqueFor(): int
    {
        return 600;
    }

    // ── WithoutOverlapping middleware ──────────────────────────────────────────

    /**
     * Attach middleware:
     *   WithoutOverlapping — prevents two workers from concurrently processing
     *   the same run_id. Release time is 0 so a failed job immediately releases
     *   the lock for retry.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->runId))->dontRelease(),
        ];
    }

    // ── Retry policy ───────────────────────────────────────────────────────────

    /**
     * Exponential backoff in seconds: 10s, 30s, 60s, 120s between attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * Give up retrying after 6 hours from the first attempt.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(6)->toDateTime();
    }

    // ── Handle ─────────────────────────────────────────────────────────────────

    /**
     * Execute the campaign run through the send engine.
     *
     * Resolves CampaignService from the container so tests can swap the binding.
     * Returns silently if the run no longer exists (deleted concurrently).
     */
    public function handle(): void
    {
        $run = CampaignRun::find($this->runId);

        if ($run === null) {
            Log::warning('[SendCampaignJob] CampaignRun not found — discarding job.', [
                'run_id' => $this->runId,
            ]);
            return;
        }

        Log::info('[SendCampaignJob] Starting run.', ['run_id' => $this->runId]);

        app(CampaignService::class)->sendRun($run);

        Log::info('[SendCampaignJob] Run completed.', ['run_id' => $this->runId]);
    }

    /** Reconcile paced company ledgers after the queue exhausts all attempts. */
    public function failed(Throwable $exception): void
    {
        $run = CampaignRun::find($this->runId);

        if ($run === null) {
            return;
        }

        app(CampaignService::class)->finalizePacedFailure($run, $exception);
    }
}
