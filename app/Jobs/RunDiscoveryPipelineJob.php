<?php

namespace App\Jobs;

use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunDiscoveryPipelineJob — queued cold-discovery run for a single ProspectCriteria.
 *
 * Dispatched by:
 *   - `prospect:discover` artisan command (--queue flag, future)
 *   - Scheduled runs (routes/console.php)
 *   - Manual trigger from the UI
 *
 * Queue: default (database driver in fretiq).
 * Retry policy: 2 attempts with no backoff (short-lived API calls).
 *
 * DiscoveryPipelineService is resolved via app() which auto-wires its two
 * concrete constructor dependencies — no manual binding needed.
 */
class RunDiscoveryPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Maximum number of retry attempts before marking as failed. */
    public int $tries = 2;

    public function __construct(
        private readonly int $criteriaId,
    ) {}

    /**
     * Execute the discovery pipeline.
     */
    public function handle(): void
    {
        $criteria = ProspectCriteria::find($this->criteriaId);

        if (! $criteria) {
            Log::warning('[RunDiscoveryPipelineJob] ProspectCriteria not found — aborting.', [
                'criteria_id' => $this->criteriaId,
            ]);
            return;
        }

        Log::info('[RunDiscoveryPipelineJob] Starting discovery pipeline', [
            'criteria_id'   => $this->criteriaId,
            'criteria_name' => $criteria->name,
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);

        $stats = $pipeline->run($criteria);

        Log::info('[RunDiscoveryPipelineJob] Pipeline completed', array_merge(
            ['criteria_id' => $this->criteriaId],
            $stats
        ));
    }
}
