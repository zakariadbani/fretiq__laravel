<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\DB;

/**
 * AnalyticsService — computed-on-read metrics for the prospection dashboard.
 *
 * All data is derived from existing columns; no schema changes are required.
 * Every division is guarded against zero to return 0.0 gracefully on first run.
 */
class AnalyticsService
{
    /** @return array<string, mixed> */
    public function dashboardData(): array
    {
        return [
            'kpis' => $this->dashboardKpis(),
            'funnel' => $this->funnel(),
            'engagementOverTime' => $this->engagementOverTime(),
            'topCampaigns' => $this->topCampaigns(),
            'campaigns' => $this->campaignOverview(),
            'planning' => $this->planningOverview(),
            'criteria' => $this->criteriaOverview(),
            'enterprises' => $this->enterpriseOverview(),
        ];
    }
    // ── Dashboard KPIs ─────────────────────────────────────────────────────────

    /**
     * Return labelled KPI array for the dashboard header cards.
     *
     * Metrics:
     *   companies          — total Company rows
     *   contacts           — total Contact rows (excluding soft-deleted)
     *   active_campaigns   — campaigns with is_active=true
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
        $runStats = CampaignRun::executed()
            ->where('run_at', '>=', $since)
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
            'active_campaigns' => Campaign::where('name', 'not like', 'E2E\_FIXTURE %')->where('is_active', true)->count(),
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

        // Aggregate opens/clicks/replies per ISO week
        $opensRaw   = $this->aggregateByWeek('opened_at', $since);
        $clicksRaw  = $this->aggregateByWeek('clicked_at', $since);
        $repliesRaw = $this->aggregateByWeek('replied_at', $since);

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

    /**
     * Aggregate CampaignRecipient rows by ISO week for a single timestamp column.
     *
     * Shared by engagementOverTime()'s three identical opens/clicks/replies queries.
     *
     * @return \Illuminate\Support\Collection<string, int>  cnt indexed by iso_week label
     */
    private function aggregateByWeek(string $column, $since)
    {
        return CampaignRecipient::where($column, '>=', $since)
            ->selectRaw("DATE_FORMAT({$column}, '%x-W%v') AS iso_week, COUNT(*) AS cnt")
            ->groupBy('iso_week')
            ->pluck('cnt', 'iso_week');
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
            ->where('campaigns.name', 'not like', 'E2E\_FIXTURE %')
            ->where('campaign_runs.status', 'sent')
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
    /** @return array<string, mixed> */
    private function campaignOverview(bool $includeRows = true): array
    {
        $rows = $includeRows ? Campaign::query()
            ->where('name', 'not like', 'E2E\_FIXTURE %')
            ->withCount(['runs as executed_runs' => fn ($query) => $query->executed()])
            ->withSum(['runs as sent' => fn ($query) => $query->executed()], 'stats_sent')
            ->withSum(['runs as opened' => fn ($query) => $query->executed()], 'stats_opened')
            ->withSum(['runs as clicked' => fn ($query) => $query->executed()], 'stats_clicked')
            ->orderByDesc('is_active')
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(function (Campaign $campaign): array {
                $sent = (int) ($campaign->sent ?? 0);

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'schedule_type' => $campaign->schedule_type,
                    'schedule_label' => config("global.data.schedule_types.{$campaign->schedule_type}.label", $campaign->schedule_type),
                    'is_active' => (bool) $campaign->is_active,
                    'scheduled_at' => $campaign->effectiveScheduledAt(),
                    'executed_runs' => (int) $campaign->executed_runs,
                    'sent' => $sent,
                    'open_rate' => $sent > 0 ? round(((int) $campaign->opened / $sent) * 100, 1) : null,
                    'click_rate' => $sent > 0 ? round(((int) $campaign->clicked / $sent) * 100, 1) : null,
                ];
            })
            ->all() : [];

        return [
            'total' => Campaign::where('name', 'not like', 'E2E\_FIXTURE %')->count(),
            'active' => Campaign::where('name', 'not like', 'E2E\_FIXTURE %')->where('is_active', true)->count(),
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function planningOverview(): array
    {
        $base = Campaign::query()
            ->where('name', 'not like', 'E2E\_FIXTURE %')
            ->where('is_active', true)
            ->where('schedule_type', '!=', 'sequence')
            ->whereRaw('COALESCE(next_run_at, scheduled_at) IS NOT NULL');

        $map = static fn (Campaign $campaign): array => [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'schedule_type' => $campaign->schedule_type,
            'schedule_label' => config("global.data.schedule_types.{$campaign->schedule_type}.label", $campaign->schedule_type),
            'scheduled_at' => $campaign->effectiveScheduledAt(),
            'timezone' => $campaign->scheduleTimezone(),
        ];

        $now = now();
        $counts = (clone $base)
            ->selectRaw(
                'SUM(CASE WHEN COALESCE(next_run_at, scheduled_at) >= ? THEN 1 ELSE 0 END) AS upcoming_count, SUM(CASE WHEN COALESCE(next_run_at, scheduled_at) < ? THEN 1 ELSE 0 END) AS overdue_count',
                [$now, $now]
            )
            ->first();

        $upcoming = (clone $base)
            ->whereRaw('COALESCE(next_run_at, scheduled_at) >= ?', [$now])
            ->orderByRaw('COALESCE(next_run_at, scheduled_at)')
            ->limit(5)
            ->get()
            ->map($map)
            ->all();

        $overdue = (clone $base)
            ->whereRaw('COALESCE(next_run_at, scheduled_at) < ?', [$now])
            ->orderByRaw('COALESCE(next_run_at, scheduled_at) DESC')
            ->limit(5)
            ->get()
            ->map($map)
            ->all();

        return [
            'upcoming' => $upcoming,
            'overdue' => $overdue,
            'upcoming_count' => (int) ($counts->upcoming_count ?? 0),
            'overdue_count' => (int) ($counts->overdue_count ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function criteriaOverview(): array
    {
        $rows = ProspectCriteria::query()
            ->withCount(['companies', 'contacts'])
            ->with('latestDiscoveryRun')
            ->orderByDesc('is_active')
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(function (ProspectCriteria $criteria): array {
                $run = $criteria->latestDiscoveryRun;

                return [
                    'id' => $criteria->id,
                    'name' => $criteria->name,
                    'is_active' => (bool) $criteria->is_active,
                    'auto_run' => (bool) $criteria->auto_run,
                    'auto_enrich' => (bool) $criteria->auto_enrich,
                    'run_at_hour' => $criteria->run_at_hour,
                    'companies_count' => (int) $criteria->companies_count,
                    'contacts_count' => (int) $criteria->contacts_count,
                    'latest_run_status' => $run?->status,
                    'latest_run_at' => $run?->finished_at ?? $run?->started_at ?? $run?->created_at,
                ];
            })
            ->all();

        return [
            'total' => ProspectCriteria::count(),
            'active' => ProspectCriteria::where('is_active', true)->count(),
            'auto_run' => ProspectCriteria::where('is_active', true)->where('auto_run', true)->count(),
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function enterpriseOverview(): array
    {
        $sources = Company::query()
            ->selectRaw('source, COUNT(*) AS aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source')
            ->map(fn ($count) => (int) $count)
            ->all();

        $qualification = Company::query()
            ->selectRaw('qualification_status, COUNT(*) AS aggregate')
            ->groupBy('qualification_status')
            ->pluck('aggregate', 'qualification_status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $enrichment = Company::query()
            ->selectRaw("COALESCE(enrichment_status, 'not_attempted') AS status, COUNT(*) AS aggregate")
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return [
            'total' => Company::count(),
            'with_contacts' => Company::whereHas('contacts')->count(),
            'qualification' => $qualification,
            'sources' => $sources,
            'enrichment' => $enrichment,
            'recent' => Company::query()
                ->withCount('contacts')
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'source', 'qualification_status', 'enrichment_status', 'created_at'])
                ->map(fn (Company $company): array => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'source' => $company->source,
                    'qualification_status' => $company->qualification_status,
                    'enrichment_status' => $company->enrichment_status,
                    'contacts_count' => (int) $company->contacts_count,
                    'created_at' => $company->created_at,
                ])
                ->all(),
        ];
    }
}
