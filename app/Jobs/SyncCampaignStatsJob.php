<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Zoho\ZohoCampaignsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SyncCampaignStatsJob — refreshes campaign run statistics from the appropriate source.
 *
 * For runs backed by the Zoho driver (zoho_campaign_key set):
 *   Calls ZohoCampaignsClient::getCampaignReport() and updates run stats_*.
 *   Live-verified on 2026-08-02; malformed or application-error payloads fail closed.
 *
 * For runs backed by the local driver (no zoho_campaign_key):
 *   Recomputes stats by counting campaign_recipient rows.
 *
 * Dispatched by campaign:sync-stats (every 15 minutes, withoutOverlapping).
 *
 * @see CampaignSyncStats command
 */
class SyncCampaignStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum retry attempts. */
    public int $tries = 3;

    /** Job timeout in seconds. */
    public int $timeout = 60;

    /** Prevent duplicate manual/scheduled syncs for the same run. */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("campaign-stats:{$this->runId}"))->expireAfter(120)];
    }

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
            Log::channel('campaign')->warning('[SyncCampaignStatsJob] CampaignRun introuvable — abandon.', [
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
     * Live-verified fields: emails_sent_count, delivered_count, opens_count,
     * unique_clicks_count, bounces_count, and unsub_count inside
     * a successful campaign-reports[0] response.
     */
    private function syncFromZoho(CampaignRun $run): void
    {
        Log::channel('campaign')->info('[SyncCampaignStatsJob] Sync stats Zoho.', [
            'run_id'       => $run->id,
            'campaign_key' => $run->zoho_campaign_key,
        ]);

        try {
            /** @var ZohoCampaignsClient $client */
            $client = app(ZohoCampaignsClient::class);

            $payload = $client->getCampaignReport($run->zoho_campaign_key);
            if (
                (string) ($payload['code'] ?? '') !== '0'
                || ($payload['status'] ?? null) !== 'success'
                || ! isset($payload['campaign-reports'][0])
                || ! is_array($payload['campaign-reports'][0])
            ) {
                throw new \UnexpectedValueException('Zoho campaign-reports[0] missing or malformed.');
            }
            $report = $payload['campaign-reports'][0];
            $verifiedFields = [
                'emails_sent_count',
                'delivered_count',
                'opens_count',
                'unique_clicks_count',
                'bounces_count',
                'unsub_count',
            ];
            $missingFields = array_values(array_filter(
                $verifiedFields,
                fn (string $field) => ! array_key_exists($field, $report)
                    || ! is_numeric($report[$field])
                    || ! is_finite((float) $report[$field])
                    || (float) $report[$field] < 0,
            ));
            if ($missingFields !== []) {
                Log::channel('campaign')->warning('[SyncCampaignStatsJob] Zoho: champs verifies manquants.', [
                    'run_id' => $run->id,
                    'missing_fields' => $missingFields,
                ]);
                throw new \UnexpectedValueException('Zoho verified campaign counters missing or invalid.');
            }
            // Verified fields are preferred; legacy aliases preserve compatibility.
            // An absent provider field must not erase an already-known local value.
            $stats = [
                'stats_sent' => $this->reportStat(
                    $report,
                    ['emails_sent_count', 'sent_count', 'nooftotalsent', 'total_sent'],
                    $run->stats_sent,
                ),
                'stats_delivered' => $this->reportStat(
                    $report,
                    ['delivered_count', 'noofdelivered', 'total_delivered'],
                    $run->stats_delivered,
                ),
                'stats_opened' => $this->reportStat(
                    $report,
                    ['opens_count', 'opened_count', 'noofopened', 'total_opened'],
                    $run->stats_opened,
                ),
                'stats_clicked' => $this->reportStat(
                    $report,
                    ['unique_clicks_count', 'clicked_count', 'noofclicked', 'total_clicked'],
                    $run->stats_clicked,
                ),
                'stats_replied' => $this->reportStat(
                    $report,
                    ['replied_count', 'noofreplied', 'total_replied'],
                    $run->stats_replied,
                ),
                'stats_bounced' => $this->reportStat(
                    $report,
                    ['bounces_count', 'bounced_count', 'noofbounced', 'total_bounced'],
                    $run->stats_bounced,
                ),
                'stats_unsubscribed' => $this->reportStat(
                    $report,
                    ['unsub_count', 'unsubscribed_count', 'noofunsubscribed', 'noofunsubscribes', 'total_unsubscribed'],
                    $run->stats_unsubscribed,
                ),
            ];

            $run->refresh();
            $stats['stats_synced_at'] = now();
            $stats['stats_sync_error'] = str_starts_with(
                (string) $run->stats_sync_error,
                'Recipient event sync failed',
            ) ? $run->stats_sync_error : null;
            $run->update($stats);

            Log::channel('campaign')->info('[SyncCampaignStatsJob] Stats Zoho mises à jour.', [
                'run_id' => $run->id,
                ...$stats,
            ]);
        } catch (\Throwable $e) {
            $run->update(['stats_sync_error' => $e->getMessage()]);
            Log::channel('campaign')->error('[SyncCampaignStatsJob] Échec sync Zoho.', [
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

        Log::channel('campaign')->debug('[SyncCampaignStatsJob] Stats locales recalculées.', [
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

            if (array_key_exists($alias, $report) && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                return CampaignRun::normalizeKpi($value);
            }
        }

        return CampaignRun::normalizeKpi($stored);
    }
}
