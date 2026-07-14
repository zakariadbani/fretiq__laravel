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

        if (! $run->isExecuted()) {
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

            // UNVERIFIED — conservative aliases only. An absent or malformed
            // provider field must not erase an already-known local value.
            $stats = [
                'stats_sent' => $this->reportStat(
                    $report,
                    ['sent_count', 'nooftotalsent', 'total_sent'],
                    $run->stats_sent,
                ),
                'stats_delivered' => $this->reportStat(
                    $report,
                    ['delivered_count', 'noofdelivered', 'total_delivered'],
                    $run->stats_delivered,
                ),
                'stats_opened' => $this->reportStat(
                    $report,
                    ['opened_count', 'noofopened', 'total_opened'],
                    $run->stats_opened,
                ),
                'stats_clicked' => $this->reportStat(
                    $report,
                    ['clicked_count', 'noofclicked', 'total_clicked'],
                    $run->stats_clicked,
                ),
                'stats_replied' => $this->reportStat(
                    $report,
                    ['replied_count', 'noofreplied', 'total_replied'],
                    $run->stats_replied,
                ),
                'stats_bounced' => $this->reportStat(
                    $report,
                    ['bounced_count', 'noofbounced', 'total_bounced'],
                    $run->stats_bounced,
                ),
                'stats_unsubscribed' => $this->reportStat(
                    $report,
                    ['unsubscribed_count', 'noofunsubscribed', 'noofunsubscribes', 'total_unsubscribed'],
                    $run->stats_unsubscribed,
                ),
            ];

            $run->update($stats);

            Log::info('[SyncCampaignStatsJob] Stats Zoho mises à jour.', [
                'run_id' => $run->id,
                ...$stats,
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
        $counts = CampaignRecipient::where('campaign_run_id', $run->id)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN sent_at IS NOT NULL OR status IN ('sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'unsubscribed') THEN 1 ELSE 0 END), 0) AS sent,
                COALESCE(SUM(CASE WHEN status IN ('delivered', 'opened', 'clicked', 'replied') THEN 1 ELSE 0 END), 0) AS delivered,
                COALESCE(SUM(CASE WHEN opened_at IS NOT NULL OR status IN ('opened', 'clicked') THEN 1 ELSE 0 END), 0) AS opened,
                COALESCE(SUM(CASE WHEN clicked_at IS NOT NULL OR status = 'clicked' THEN 1 ELSE 0 END), 0) AS clicked,
                COALESCE(SUM(CASE WHEN replied_at IS NOT NULL OR status = 'replied' THEN 1 ELSE 0 END), 0) AS replied,
                COALESCE(SUM(CASE WHEN bounced_at IS NOT NULL OR status = 'bounced' THEN 1 ELSE 0 END), 0) AS bounced,
                COALESCE(SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END), 0) AS unsubscribed
            ")
            ->first();

        $stats = [
            'stats_sent'         => CampaignRun::normalizeKpi($counts?->sent),
            'stats_delivered'    => CampaignRun::normalizeKpi($counts?->delivered),
            'stats_opened'       => CampaignRun::normalizeKpi($counts?->opened),
            'stats_clicked'      => CampaignRun::normalizeKpi($counts?->clicked),
            'stats_replied'      => CampaignRun::normalizeKpi($counts?->replied),
            'stats_bounced'      => CampaignRun::normalizeKpi($counts?->bounced),
            'stats_unsubscribed' => CampaignRun::normalizeKpi($counts?->unsubscribed),
        ];

        $run->update($stats);

        Log::debug('[SyncCampaignStatsJob] Stats locales recalculées.', [
            'run_id' => $run->id,
            ...$stats,
        ]);
    }

    /**
     * Prefer the first valid provider value; preserve the normalized stored value
     * when Zoho does not supply a usable alias.
     *
     * @param array<string, mixed> $report
     * @param array<int, string> $aliases
     */
    private function reportStat(array $report, array $aliases, mixed $stored): int
    {
        foreach ($aliases as $alias) {
            $value = $report[$alias] ?? null;

            if (array_key_exists($alias, $report) && is_numeric($value) && is_finite((float) $value)) {
                return CampaignRun::normalizeKpi($value);
            }
        }

        return CampaignRun::normalizeKpi($stored);
    }
}
