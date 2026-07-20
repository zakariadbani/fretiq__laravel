<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CampaignsSyncSequenceEnrollments extends Command
{
    protected $signature = 'campaigns:sync-sequence-enrollments';

    protected $description = 'Inscrit les nouveaux contacts éligibles dans les campagnes séquentielles suivies.';

    public function handle(CampaignService $campaignService, PacedSequenceEnrollmentService $pacedService): int
    {
        $campaigns = 0;
        $enrolled = 0;
        $skipped = 0;
        $blocked = 0;

        Campaign::query()
            ->where('schedule_type', 'sequence')
            ->where('sequence_auto_enroll_enabled', true)
            ->with(['segment', 'senderIdentity', 'sequence'])
            ->chunkById(100, function ($batch) use ($campaignService, $pacedService, &$campaigns, &$enrolled, &$skipped, &$blocked): void {
                foreach ($batch as $campaign) {
                    $campaigns++;

                    try {
                        $isPaced = $campaign->sequence_enrollment_mode === 'paced';
                        if ($isPaced) {
                            // The locked paced service checks configuration and due
                            // state before resolving the potentially large audience.
                            $result = $pacedService->evaluateDue($campaign, now());
                        } else {
                            $preflight = $campaignService->dispatchPreflight($campaign);
                            if (! $preflight['ok']) {
                                $blocked++;
                                Log::warning('[CampaignSequenceAutoEnroll] Campaign skipped by preflight.', [
                                    'campaign_id' => $campaign->id,
                                    'messages' => $preflight['messages'],
                                ]);

                                continue;
                            }

                            $result = $campaignService->launchSequence($campaign);
                        }
                        $enrolled += $result['enrolled'];
                        $skipped += $result['skipped'];
                    } catch (\Throwable $e) {
                        $blocked++;
                        Log::error('[CampaignSequenceAutoEnroll] Campaign sync failed.', [
                            'campaign_id' => $campaign->id,
                            'error_type' => $e::class,
                        ]);
                    }
                }
            });

        $summary = [
            'campaigns' => $campaigns,
            'enrolled' => $enrolled,
            'skipped' => $skipped,
            'blocked' => $blocked,
        ];
        Log::info('[CampaignSequenceAutoEnroll] Sync completed.', $summary);
        $this->info("Campagnes={$campaigns}; inscrits={$enrolled}; déjà_suivis={$skipped}; bloquées={$blocked}.");

        return self::SUCCESS;
    }
}
