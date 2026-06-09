<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Zoho\ZohoCampaignsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncCampaignStatsJob — refreshes campaign run statistics from the appropriate source.
 *
 * For runs backed by the Zoho driver (zoho_campaign_key set):
 *   Calls ZohoCampaignsClient::getCampaignReport() and updates run stats_*.
 *   UNVERIFIED — the Zoho API call has not been live-tinker-confirmed.
 *
 * For runs backed by the local driver (no zoho_campaign_key):
 *   Recomputes stats by counting campaign_recipient rows.
 *
 * Dispatched by campaign:sync-stats (every 15 minutes, withoutOverlapping).
 *
 * @see CampaignSyncStats command
 */
class SyncCampaignStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum retry attempts. */
    public int $tries = 3;

    /** Job timeout in seconds. */
    public int $timeout = 60;

    public function __construct(
        public readonly int $runId,
    ) {}

    /**
     * Execute the stats sync for one CampaignRun.
     *
     * For Zoho runs: fetches live report from Zoho Campaigns API.
     * For local runs: recomputes from campaign_recipients table.
     */
    public function handle(): void
    {
        $run = CampaignRun::find($this->runId);

        if ($run === null) {
            Log::warning('[SyncCampaignStatsJob] CampaignRun introuvable — abandon.', [
                'run_id' => $this->runId,
            ]);
            return;
        }

        if ($run->zoho_campaign_key) {
            $this->syncFromZoho($run);
        } else {
            $this->syncFromLocal($run);
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Sync stats from Zoho Campaigns API for a Zoho-backed run.
     *
     * UNVERIFIED — getCampaignReport() API call is not live-tinker-confirmed.
     * Response field names ('sent_count', 'opened_count', etc.) are per documentation
     * and may differ in a live response. Record the actual field names after the
     * first successful live call and update this mapping.
     */
    private function syncFromZoho(CampaignRun $run): void
    {
        Log::info('[SyncCampaignStatsJob] Sync stats Zoho.', [
            'run_id'       => $run->id,
            'campaign_key' => $run->zoho_campaign_key,
        ]);

        try {
            /** @var ZohoCampaignsClient $client */
            $client = app(ZohoCampaignsClient::class);

            $report = $client->getCampaignReport($run->zoho_campaign_key);

            // UNVERIFIED — field mapping per Zoho Campaigns API documentation.
            // Actual field names must be verified against a live STATUS 200 response.
            $statsSent    = (int) ($report['sent_count']    ?? $report['nooftotalsent']    ?? $run->stats_sent    ?? 0);
            $statsOpened  = (int) ($report['opened_count']  ?? $report['noofopened']       ?? $run->stats_opened  ?? 0);
            $statsClicked = (int) ($report['clicked_count'] ?? $report['noofclicked']      ?? $run->stats_clicked ?? 0);
            $statsBounced = (int) ($report['bounced_count'] ?? $report['noofbounced']      ?? $run->stats_bounced ?? 0);

            $run->update([
                'stats_sent'    => $statsSent,
                'stats_opened'  => $statsOpened,
                'stats_clicked' => $statsClicked,
                'stats_bounced' => $statsBounced,
            ]);

            Log::info('[SyncCampaignStatsJob] Stats Zoho mises à jour.', [
                'run_id'  => $run->id,
                'sent'    => $statsSent,
                'opened'  => $statsOpened,
                'clicked' => $statsClicked,
                'bounced' => $statsBounced,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SyncCampaignStatsJob] Échec sync Zoho.', [
                'run_id' => $run->id,
                'error'  => $e->getMessage(),
            ]);
            // Do not rethrow — stats sync failure is non-critical and should not
            // block the queue or trigger alerting.
        }
    }

    /**
     * Recompute stats from campaign_recipients rows for a local-driver run.
     */
    private function syncFromLocal(CampaignRun $run): void
    {
        $sent    = CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'sent')->count();
        $opened  = CampaignRecipient::where('campaign_run_id', $run->id)->whereNotNull('opened_at')->count();
        $clicked = CampaignRecipient::where('campaign_run_id', $run->id)->whereNotNull('clicked_at')->count();
        $bounced = CampaignRecipient::where('campaign_run_id', $run->id)->whereNotNull('bounced_at')->count();

        $run->update([
            'stats_sent'    => $sent,
            'stats_opened'  => $opened,
            'stats_clicked' => $clicked,
            'stats_bounced' => $bounced,
        ]);

        Log::debug('[SyncCampaignStatsJob] Stats locales recalculées.', [
            'run_id'  => $run->id,
            'sent'    => $sent,
            'opened'  => $opened,
            'clicked' => $clicked,
            'bounced' => $bounced,
        ]);
    }
}
