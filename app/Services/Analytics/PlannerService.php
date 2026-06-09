<?php

namespace App\Services\Analytics;

use App\Models\CampaignRun;

/**
 * PlannerService — provides a FullCalendar-compatible event feed from CampaignRun rows.
 *
 * Colors follow Bootstrap 5 / Metronic semantic:
 *   scheduled → #3e97ff  (primary blue)
 *   sending   → #f6c000  (warning yellow)
 *   sent      → #50cd89  (success green)
 *   failed    → #f1416c  (danger red)
 *   canceled  → #a1a5b7  (gray / secondary)
 */
class PlannerService
{
    /**
     * Color map keyed by CampaignRun.status.
     *
     * @var array<string, string>
     */
    private const STATUS_COLORS = [
        'scheduled' => '#3e97ff',
        'sending'   => '#f6c000',
        'sent'      => '#50cd89',
        'failed'    => '#f1416c',
        'canceled'  => '#a1a5b7',
    ];

    /**
     * Return a FullCalendar-shaped event array for the given date range.
     *
     * Each event:
     * [
     *   'id'    => (string) run id,
     *   'title' => campaign name,
     *   'start' => ISO 8601 datetime string (run_at),
     *   'color' => hex color string based on run status,
     *   'url'   => route to admin.campaigns.view for the parent campaign,
     * ]
     *
     * @param  string|null  $start  ISO date string (inclusive), or null for no lower bound.
     * @param  string|null  $end    ISO date string (exclusive), or null for no upper bound.
     * @return array<int, array<string, string>>
     */
    public function runsFeed(?string $start = null, ?string $end = null): array
    {
        $query = CampaignRun::with('campaign')
            ->orderBy('run_at');

        if ($start !== null) {
            $query->where('run_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('run_at', '<', $end);
        }

        return $query
            ->get()
            ->map(function (CampaignRun $run): array {
                $color = self::STATUS_COLORS[$run->status] ?? '#a1a5b7';

                return [
                    'id'    => (string) $run->id,
                    'title' => $run->campaign?->name ?? "Run #{$run->id}",
                    'start' => $run->run_at?->toIso8601String() ?? '',
                    'color' => $color,
                    'url'   => $run->campaign_id
                        ? route('admin.campaigns.view', $run->campaign_id)
                        : '',
                ];
            })
            ->values()
            ->all();
    }
}
