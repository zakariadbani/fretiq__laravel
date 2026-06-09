<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use Illuminate\Support\Facades\DB;

/**
 * AnalyticsService — computed-on-read metrics for the prospection dashboard.
 *
 * All data is derived from existing columns; no schema changes are required.
 * Every division is guarded against zero to return 0.0 gracefully on first run.
 */
class AnalyticsService
{
    // ── Dashboard KPIs ─────────────────────────────────────────────────────────

    /**
     * Return labelled KPI array for the dashboard header cards.
     *
     * Metrics:
     *   companies          — total Company rows
     *   contacts           — total Contact rows (excluding soft-deleted)
     *   active_campaigns   — campaigns with status in [scheduled, active]
     *   emails_sent_30d    — sum of stats_sent on runs within the last 30 days
     *   open_rate          — (sum opens / sum sent) × 100 over the last 30 days
     *   click_rate         — (sum clicks / sum sent) × 100 over the last 30 days
     *   demandes           — total Demande rows
     *   demandes_30d       — Demandes captured_at within the last 30 days
     *   conversion_rate    — (demandes_30d / emails_sent_30d) × 100, guard /0
     *
     * @return array<string, int|float>
     */
    public function dashboardKpis(): array
    {
        $since = now()->subDays(30);

        // ── Aggregate 30-day run stats in a single query ───────────────────────
        $runStats = CampaignRun::where('run_at', '>=', $since)
            ->selectRaw('
                COALESCE(SUM(stats_sent), 0)    AS total_sent,
                COALESCE(SUM(stats_opened), 0)  AS total_opened,
                COALESCE(SUM(stats_clicked), 0) AS total_clicked
            ')
            ->first();

        $emailsSent30d  = (int) ($runStats->total_sent   ?? 0);
        $totalOpened30d = (int) ($runStats->total_opened ?? 0);
        $totalClicked30d= (int) ($runStats->total_clicked ?? 0);

        $openRate  = $emailsSent30d > 0 ? round(($totalOpened30d  / $emailsSent30d) * 100, 2) : 0.0;
        $clickRate = $emailsSent30d > 0 ? round(($totalClicked30d / $emailsSent30d) * 100, 2) : 0.0;

        // ── Demande counts ─────────────────────────────────────────────────────
        $demandes30d = Demande::where('captured_at', '>=', $since)->count();

        $conversionRate = $emailsSent30d > 0
            ? round(($demandes30d / $emailsSent30d) * 100, 2)
            : 0.0;

        return [
            'companies'        => Company::count(),
            'contacts'         => Contact::count(),
            'active_campaigns' => Campaign::whereIn('status', ['scheduled', 'active'])->count(),
            'emails_sent_30d'  => $emailsSent30d,
            'open_rate'        => $openRate,
            'click_rate'       => $clickRate,
            'demandes'         => Demande::count(),
            'demandes_30d'     => $demandes30d,
            'conversion_rate'  => $conversionRate,
        ];
    }

    // ── Funnel ─────────────────────────────────────────────────────────────────

    /**
     * Return an ordered funnel stage array: label => count.
     *
     * Stages:
     *   Découvertes  — companies with source='discovered'
     *   Contactées   — distinct contact_ids appearing in campaign_recipients
     *                  (best-effort proxy for "has been reached out to")
     *   Ouvertures   — campaign_recipient rows with opened_at not null
     *   Clics        — campaign_recipient rows with clicked_at not null
     *   Réponses     — campaign_recipient rows with replied_at not null
     *   Demandes     — total Demande rows
     *
     * @return array<string, int>
     */
    public function funnel(): array
    {
        $discovered = Company::where('source', 'discovered')->count();

        $contacted = CampaignRecipient::distinct('contact_id')
            ->whereNotNull('contact_id')
            ->count('contact_id');

        $opened = CampaignRecipient::whereNotNull('opened_at')->count();

        $clicked = CampaignRecipient::whereNotNull('clicked_at')->count();

        $replied = CampaignRecipient::whereNotNull('replied_at')->count();

        $demandes = Demande::count();

        return [
            'Découvertes' => $discovered,
            'Contactées'  => $contacted,
            'Ouvertures'  => $opened,
            'Clics'       => $clicked,
            'Réponses'    => $replied,
            'Demandes'    => $demandes,
        ];
    }

    // ── Engagement over time ────────────────────────────────────────────────────

    /**
     * Return per-ISO-week opens / clicks / replies counts over the last N weeks.
     *
     * Each series is an array indexed by the same ISO-week labels.
     * Data is derived from campaign_recipients timestamps.
     *
     * Return shape:
     * [
     *   'labels'  => ['2024-W01', '2024-W02', ...],   // ISO year-week strings
     *   'series'  => [
     *     'opens'   => [12, 34, ...],
     *     'clicks'  => [3,  8, ...],
     *     'replies' => [1,  2, ...],
     *   ],
     * ]
     *
     * @param  int   $weeks  Number of past weeks to include (default 8).
     * @return array<string, mixed>
     */
    public function engagementOverTime(int $weeks = 8): array
    {
        $since = now()->startOfWeek()->subWeeks($weeks - 1);

        // Build ordered ISO-week label list (YYYY-Www)
        $labels = [];
        for ($i = 0; $i < $weeks; $i++) {
            $labels[] = now()->startOfWeek()->subWeeks($weeks - 1 - $i)->format('o-\WW');
        }

        // Aggregate opens per ISO week
        $opensRaw = CampaignRecipient::where('opened_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(opened_at, '%x-W%v') AS iso_week, COUNT(*) AS cnt")
            ->groupBy('iso_week')
            ->pluck('cnt', 'iso_week');

        // Aggregate clicks per ISO week
        $clicksRaw = CampaignRecipient::where('clicked_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(clicked_at, '%x-W%v') AS iso_week, COUNT(*) AS cnt")
            ->groupBy('iso_week')
            ->pluck('cnt', 'iso_week');

        // Aggregate replies per ISO week
        $repliesRaw = CampaignRecipient::where('replied_at', '>=', $since)
            ->selectRaw("DATE_FORMAT(replied_at, '%x-W%v') AS iso_week, COUNT(*) AS cnt")
            ->groupBy('iso_week')
            ->pluck('cnt', 'iso_week');

        // Map to label-indexed arrays, filling 0 for missing weeks
        $opens   = array_map(fn($l) => (int) ($opensRaw[$l]   ?? 0), $labels);
        $clicks  = array_map(fn($l) => (int) ($clicksRaw[$l]  ?? 0), $labels);
        $replies = array_map(fn($l) => (int) ($repliesRaw[$l] ?? 0), $labels);

        return [
            'labels' => $labels,
            'series' => [
                'opens'   => $opens,
                'clicks'  => $clicks,
                'replies' => $replies,
            ],
        ];
    }

    // ── Top campaigns ───────────────────────────────────────────────────────────

    /**
     * Return the top N campaigns ranked by conversion rate.
     *
     * Conversion rate = (sum conversion_count / sum stats_sent) × 100.
     * Campaigns with zero stats_sent are assigned rate 0 and fall to the bottom.
     *
     * Each row:
     * [
     *   'id'              => int,
     *   'name'            => string,
     *   'sent'            => int,
     *   'demandes'        => int,
     *   'conversion_rate' => float,  // percentage 0–100
     * ]
     *
     * @param  int   $limit  Maximum rows to return (default 5).
     * @return array<int, array<string, mixed>>
     */
    public function topCampaigns(int $limit = 5): array
    {
        $rows = DB::table('campaign_runs')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_runs.campaign_id')
            ->selectRaw('
                campaigns.id,
                campaigns.name,
                COALESCE(SUM(campaign_runs.stats_sent), 0)        AS total_sent,
                COALESCE(SUM(campaign_runs.conversion_count), 0)  AS total_demandes
            ')
            ->groupBy('campaigns.id', 'campaigns.name')
            ->get();

        return $rows
            ->map(function ($row) {
                $sent      = (int) $row->total_sent;
                $demandes  = (int) $row->total_demandes;
                $rate      = $sent > 0 ? round(($demandes / $sent) * 100, 2) : 0.0;

                return [
                    'id'              => (int) $row->id,
                    'name'            => $row->name,
                    'sent'            => $sent,
                    'demandes'        => $demandes,
                    'conversion_rate' => $rate,
                ];
            })
            ->sortByDesc('conversion_rate')
            ->values()
            ->take($limit)
            ->all();
    }
}
