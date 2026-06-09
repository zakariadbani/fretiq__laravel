<?php

namespace App\Jobs;

use App\Services\Campaign\CampaignSchedulerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GenerateCampaignRunsJob — queue-friendly wrapper around CampaignSchedulerService.
 *
 * Idempotent (queue-idempotency.md §3): CampaignRun::firstOrCreate with the
 * UNIQUE(campaign_id, occurrence_key) constraint is the durable backstop.
 * Duplicate dispatches are silent no-ops.
 *
 * Typically dispatched by `campaigns:generate-runs` artisan command, but can
 * also be dispatched programmatically (e.g. from an admin action).
 */
class GenerateCampaignRunsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 3 times if the DB is temporarily unavailable. */
    public int $tries = 3;

    /** Timeout — generation should be fast (DB reads + inserts only, no sends). */
    public int $timeout = 60;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $count = app(CampaignSchedulerService::class)->generateDueRuns();

        Log::info('[GenerateCampaignRunsJob] Recurring runs generated.', ['count' => $count]);
    }
}
