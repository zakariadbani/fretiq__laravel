<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * sequences:reslice — make backlog sequence-wave runs eligible for the daily
 * send-cap gate (SequenceWaveService::enforceDailyCap()) to re-slice them.
 *
 * Never dispatches a Zoho send itself: resetting a run to 'prepared' with a
 * cleared zoho_list_key/driver_ref is exactly the state SequenceWaveService::
 * recover() and SyncCampaignWaveZohoListJob already know how to re-drive —
 * the scheduler drains the reset runs on its normal cadence, one capped
 * slice per day.
 */
class SequencesReslice extends Command
{
    protected $signature = 'sequences:reslice {--dry-run} {--campaign=}';

    protected $description = 'Reset stuck sequence-wave runs so the daily send-cap gate can drain their backlog across multiple days.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $campaignId = $this->option('campaign');

        $query = CampaignRun::query()
            ->where('occurrence_key', 'like', 'sequence-wave-%')
            ->whereIn('status', ['failed', 'prepared', 'scheduled'])
            // Ambiguous driver_refs mean Zoho may already have accepted the send —
            // blanking the keys and re-driving these would risk a provider
            // double-send. See Campaign::AMBIGUOUS_ZOHO_DRIVER_REFS / CampaignController:267.
            ->whereNotIn('driver_ref', Campaign::AMBIGUOUS_ZOHO_DRIVER_REFS)
            ->whereHas('recipients', fn ($recipients) => $recipients->where('status', 'queued'));

        if ($campaignId !== null) {
            $query->where('campaign_id', (int) $campaignId);
        }

        $runs = $query->with('recipients')->get();

        if ($runs->isEmpty()) {
            $this->info('No stuck sequence-wave runs with queued recipients found.');

            return self::SUCCESS;
        }

        $cap = (int) Setting::get('planification.daily_send_cap', 0);

        foreach ($runs as $run) {
            $queued = $run->recipients->where('status', 'queued')->count();

            if ($dryRun) {
                $slices = $cap > 0 ? (int) ceil($queued / $cap) : 1;
                $this->line("Run #{$run->id} (campaign {$run->campaign_id}, {$run->occurrence_key}): {$queued} queued -> ~{$slices} day(s) at cap={$cap}.");

                continue;
            }

            $run->update([
                'status' => 'prepared',
                'zoho_list_key' => null,
                'zoho_campaign_key' => null,
                'driver_ref' => 'zoho-wave-pending',
                'failure_reason' => null,
            ]);
            $this->info("Run #{$run->id} reset to 'prepared' — will re-sync and re-slice on the next scheduler pass.");
        }

        return self::SUCCESS;
    }
}
