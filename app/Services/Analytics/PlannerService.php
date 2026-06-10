<?php

namespace App\Services\Analytics;

use App\Models\CampaignRun;

/**
 * PlannerService — provides a FullCalendar-compatible event feed from CampaignRun rows.
 *
 * Status meta is driven by config('global.data.campaign_run_statuses'), which maps each status
 * key to a Bootstrap color name ('primary', 'info', 'success', etc.).
 * The Bootstrap name is resolved to a Metronic hex via BOOTSTRAP_HEX_COLORS.
 * extendedProps carries statusLabel and statusColor for the Blade/JS event-click modal.
 */
class PlannerService
{
    /**
     * Bootstrap color name → Metronic hex palette.
     *
     * @var array<string, string>
     */
    private const BOOTSTRAP_HEX_COLORS = [
        'primary'   => '#3e97ff',
        'info'      => '#7239ea',
        'success'   => '#50cd89',
        'warning'   => '#f6c000',
        'danger'    => '#f1416c',
        'secondary' => '#a1a5b7',
        'dark'      => '#181c32',
    ];

    /**
     * Return a FullCalendar-shaped event array for the given date range.
     *
     * Each event shape:
     * [
     *   'id'             => (string) run id,
     *   'title'          => campaign name or "Run #id",
     *   'start'          => ISO 8601 datetime string (run_at),
     *   'color'          => hex resolved from status bootstrap color name,
     *   'url'            => route admin.campaigns.view, or '' when no campaign,
     *   'extendedProps'  => [
     *       'status'      => raw status string,
     *       'statusLabel' => human label from config (fallback: raw status),
     *       'statusColor' => bootstrap color name from config (fallback: 'secondary'),
     *   ],
     * ]
     *
     * @param  string|null  $start  ISO date string (inclusive), or null for no lower bound.
     * @param  string|null  $end    ISO date string (exclusive), or null for no upper bound.
     * @return array<int, array<string, mixed>>
     */
    public function runsFeed(?string $start = null, ?string $end = null): array
    {
        $statusConfig = config('global.data.campaign_run_statuses', []);

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
            ->map(function (CampaignRun $run) use ($statusConfig): array {
                $meta         = $statusConfig[$run->status] ?? [];
                $statusColor  = $meta['color'] ?? 'secondary';
                $statusLabel  = $meta['label'] ?? $run->status;
                $hex          = self::BOOTSTRAP_HEX_COLORS[$statusColor] ?? self::BOOTSTRAP_HEX_COLORS['secondary'];

                return [
                    'id'    => (string) $run->id,
                    'title' => $run->campaign?->name ?? "Run #{$run->id}",
                    'start' => $run->run_at?->toIso8601String() ?? '',
                    'color' => $hex,
                    'url'   => $run->campaign_id
                        ? route('admin.campaigns.view', $run->campaign_id)
                        : '',
                    'extendedProps' => [
                        'status'      => $run->status,
                        'statusLabel' => $statusLabel,
                        'statusColor' => $statusColor,
                    ],
                ];
            })
            ->values()
            ->all();
    }
}
