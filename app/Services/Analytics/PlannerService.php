<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Services\Campaign\CampaignSchedulerService;
use Carbon\Carbon;

/**
 * PlannerService — provides a FullCalendar-compatible event feed.
 *
 * The feed merges two sources:
 *   1. Materialised CampaignRun rows (real runs — scheduled, sent, done, …).
 *   2. Virtual "projected" events synthesised from recurring campaign definitions
 *      for future occurrences not yet materialised in the DB.
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
     * Maximum projected events emitted per campaign within the window.
     * Prevents memory blow-up on very dense recurrences over huge windows.
     */
    private const PROJECTION_EMIT_CAP = 200;

    /**
     * Absolute traversal backstop per campaign (cursor advance iterations).
     * Guards runaway loops when next_run_at is far behind the window start
     * (e.g. navigating 400 days ahead of a daily campaign's cursor).
     */
    private const PROJECTION_TRAVERSAL_CAP = 5000;

    public function __construct(
        private readonly CampaignSchedulerService $scheduler,
    ) {}

    /**
     * Return a FullCalendar-shaped event array for the given date range.
     *
     * Merges two sources:
     *   - Materialised CampaignRun rows (real runs).
     *   - Virtual "projected" events for future recurring occurrences not yet
     *     in the DB (synthesised from campaign.next_run_at + recurrence rules).
     *
     * Real-run query keeps its current null-means-unbounded semantics so existing
     * no-arg callers stay unchanged. Projection uses UTC-parsed window bounds
     * because FullCalendar sends tz-offset ISO strings (e.g. 2026-05-31T00:00:00+02:00).
     *
     * Each event shape:
     * [
     *   'id'             => (string) run id  OR  "projected-{id}-{YmdHis}",
     *   'title'          => campaign name or "Run #id",
     *   'start'          => ISO 8601 datetime string (run_at or projected cursor),
     *   'color'          => hex resolved from status bootstrap color name,
     *   'url'            => route admin.campaigns.view, or '' when no campaign,
     *   'extendedProps'  => [
     *       'status'      => raw status string  OR  'projected',
     *       'statusLabel' => human label from config  OR  'Planifiée (récurrence)',
     *       'statusColor' => bootstrap color name  OR  'info',
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

        // ── 1. Real runs ───────────────────────────────────────────────────────
        $query = CampaignRun::with(['campaign.sequence.steps', 'sequenceStep'])
            ->orderBy('run_at');

        if ($start !== null) {
            $query->where('run_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('run_at', '<', $end);
        }

        $runs   = $query->get();
        $events = $runs
            ->map(function (CampaignRun $run) use ($statusConfig): array {
                $meta         = $statusConfig[$run->status] ?? [];
                $statusColor  = $meta['color'] ?? 'secondary';
                $statusLabel  = $meta['label'] ?? $run->status;
                $hex          = self::BOOTSTRAP_HEX_COLORS[$statusColor] ?? self::BOOTSTRAP_HEX_COLORS['secondary'];

                $campaign       = $run->campaign;
                $sequence       = $campaign?->sequence;
                $isSequenceWave = str_starts_with($run->occurrence_key, 'sequence-wave-');
                $waveMeta       = $this->numericWaveMeta($run->occurrence_key);
                $waveNumber     = $waveMeta['waveNumber'] ?? null;
                $stepNumber     = $run->sequenceStep?->step_no
                    ?? ($waveMeta['stepNumber'] ?? null)
                    ?? ($waveMeta !== null ? $sequence?->steps->first()?->step_no : null);
                $titleParts = [$campaign?->name ?? "Run #{$run->id}"];

                if ($isSequenceWave && $sequence?->name) {
                    $titleParts[] = $sequence->name;
                }
                if ($waveNumber !== null) {
                    $titleParts[] = "Vague {$waveNumber}";
                }
                if ($isSequenceWave && $stepNumber !== null) {
                    $titleParts[] = "\u{00C9}tape {$stepNumber}";
                }

                return [
                    'id'    => (string) $run->id,
                    'title' => implode(" \u{00B7} ", $titleParts),
                    'start' => $run->run_at?->toIso8601String() ?? '',
                    'color' => $hex,
                    'url'   => $run->campaign_id
                        ? route('admin.campaigns.view', $run->campaign_id)
                        : '',
                    'extendedProps' => [
                        'status'      => $run->status,
                        'statusLabel' => $statusLabel,
                        'statusColor' => $statusColor,
                        'eventKind'    => $isSequenceWave ? 'sequence-wave' : 'campaign-run',
                        'sequenceName' => $isSequenceWave ? $sequence?->name : null,
                        'waveNumber'   => $waveNumber,
                        'stepNumber'   => $isSequenceWave ? $stepNumber : null,
                        'companyLimit' => $isSequenceWave ? $campaign?->pacedDailyCompanyLimit() : null,
                        'launchable'   => null,
                    ],
                ];
            })
            ->all();

        // ── 2. Projected future occurrences ────────────────────────────────────
        // Only meaningful when there is at least one bound so we have a finite
        // projection window. Null window = unbounded; skip projection.
        if ($start !== null || $end !== null) {
            $projectedHex = self::BOOTSTRAP_HEX_COLORS['info'];

            // Parse window bounds to UTC Carbons.
            // FullCalendar sends tz-offset ISO strings (e.g. 2026-05-31T00:00:00+02:00).
            // Carbon::parse() handles these correctly; ->utc() normalises for comparison.
            $windowStart = $start ? Carbon::parse($start)->utc() : now()->utc();
            $windowEnd   = $end   ? Carbon::parse($end)->utc()   : now()->utc()->addMonths(3);

            // Build dedup hash from the real-run results (O(1) lookup below).
            // Key: "{campaign_id}|{YmdHis}" — exact-timestamp match.
            $realRunKeys = [];
            foreach ($runs as $run) {
                if ($run->campaign_id && $run->run_at) {
                    $realRunKeys[$run->campaign_id . '|' . $run->run_at->copy()->utc()->format('YmdHis')] = true;
                }
            }

            // Query recurring definitions that could have future occurrences.
            // Only is_active=true: paused campaigns (is_active=false) are frozen
            // and must not appear in the projection.
            $recurringCampaigns = Campaign::where('schedule_type', 'recurring')
                ->where('is_active', true)
                ->whereNotNull('next_run_at')
                ->get();

            foreach ($recurringCampaigns as $campaign) {
                $cursor    = $campaign->next_run_at->copy()->utc();
                $tz        = $campaign->timezone ?? 'UTC';
                $recurrence = $campaign->recurrence ?? [];

                $emitted    = 0;
                $traversals = 0;

                // Emit-then-advance: emit cursor if in window, then advance.
                // Pre-window iterations (cursor < windowStart) skip without counting
                // toward the emit cap (they do count toward traversal backstop).
                while ($cursor !== null && $cursor->lt($windowEnd)) {
                    if ($traversals++ >= self::PROJECTION_TRAVERSAL_CAP) {
                        break;
                    }

                    if ($cursor->gte($windowStart)) {
                        // Check emit cap (in-window only).
                        if ($emitted >= self::PROJECTION_EMIT_CAP) {
                            break;
                        }

                        $dedupKey = $campaign->id . '|' . $cursor->format('YmdHis');

                        if (!isset($realRunKeys[$dedupKey])) {
                            $events[] = [
                                'id'    => 'projected-' . $campaign->id . '-' . $cursor->format('YmdHis'),
                                'title' => $campaign->name,
                                'start' => $cursor->toIso8601String(),
                                'color' => $projectedHex,
                                'url'   => route('admin.campaigns.view', $campaign->id),
                                'extendedProps' => [
                                    'status'      => 'projected',
                                    'statusLabel' => 'Planifiée (récurrence)',
                                    'statusColor' => 'info',
                                    'eventKind'    => 'recurring-projection',
                                    'sequenceName' => null,
                                    'waveNumber'   => null,
                                    'stepNumber'   => null,
                                    'companyLimit' => null,
                                    'launchable'   => null,
                                ],
                            ];
                        }

                        $emitted++;
                    }

                    // Advance cursor via CampaignSchedulerService::computeNextRun()
                    // which is DST-correct after Fix 0.
                    $cursor = $this->scheduler->computeNextRun($recurrence, $cursor, $tz);
                }
            }

            // Later sequence waves depend on the audience remaining after this one.
            $sequenceCampaigns = Campaign::with(['sequence.steps', 'segment', 'senderIdentity'])
                ->where('schedule_type', 'sequence')
                ->where('sequence_enrollment_mode', 'paced')
                ->whereNotNull('next_run_at')
                ->get();
            $waveKeysByCampaign = CampaignRun::query()
                ->select(['campaign_id', 'occurrence_key'])
                ->whereIn('campaign_id', $sequenceCampaigns->pluck('id'))
                ->where('occurrence_key', 'like', 'sequence-wave-%')
                ->get()
                ->groupBy('campaign_id');


            foreach ($sequenceCampaigns as $campaign) {
                $cursor = $campaign->next_run_at->copy()->utc();

                if ($cursor->lt($windowStart) || ! $cursor->lt($windowEnd)) {
                    continue;
                }

                $maxWave = 0;
                foreach ($waveKeysByCampaign->get($campaign->id, []) as $run) {
                    $waveMeta = $this->numericWaveMeta($run->occurrence_key);
                    $maxWave = max($maxWave, $waveMeta['waveNumber'] ?? 0);
                }

                // Only a real base wave suppresses its projection; a follow-up
                // step may legitimately share the same timestamp.
                $deduped = $runs->contains(function (CampaignRun $run) use ($campaign, $cursor): bool {
                    return $run->campaign_id === $campaign->id
                        && $run->run_at?->copy()->utc()->equalTo($cursor)
                        && preg_match('/^sequence-wave-\d+$/', $run->occurrence_key) === 1;
                });

                if ($deduped) {
                    continue;
                }

                $sequence = $campaign->sequence;
                $stepNumber = $sequence?->steps->first()?->step_no;
                $waveNumber = $maxWave + 1;
                $launchable = $campaign->is_active
                    && $campaign->sequence_auto_enroll_enabled
                    && (int) $campaign->daily_company_limit >= 1
                    && $campaign->segment !== null
                    && $campaign->senderIdentity !== null
                    && $sequence !== null
                    && $sequence->is_active
                    && $sequence->steps->isNotEmpty();
                $titleParts = [$campaign->name];

                if ($sequence?->name) {
                    $titleParts[] = $sequence->name;
                }
                $titleParts[] = "Vague {$waveNumber}";
                if ($stepNumber !== null) {
                    $titleParts[] = "\u{00C9}tape {$stepNumber}";
                }

                $events[] = [
                    'id' => 'projected-sequence-' . $campaign->id . '-' . $cursor->format('YmdHis'),
                    'title' => implode(" \u{00B7} ", $titleParts),
                    'start' => $cursor->toIso8601String(),
                    'color' => self::BOOTSTRAP_HEX_COLORS[$launchable ? 'info' : 'secondary'],
                    'url' => route('admin.campaigns.view', $campaign->id),
                    'extendedProps' => [
                        'status' => 'projected',
                        'statusLabel' => $launchable ? "Planifi\u{00E9}e (s\u{00E9}quence)" : "Inactive \u{2014} ne sera pas lanc\u{00E9}e",
                        'statusColor' => $launchable ? 'info' : 'secondary',
                        'eventKind' => 'sequence-wave-projection',
                        'sequenceName' => $sequence?->name,
                        'waveNumber' => $waveNumber,
                        'stepNumber' => $stepNumber,
                        'companyLimit' => $campaign->pacedDailyCompanyLimit(),
                        'launchable' => $launchable,
                    ],
                ];
            }
        }

        // ── 3. Sort merged result by start ─────────────────────────────────────
        usort($events, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return array_values($events);
    }

    /** @return array{waveNumber: int, stepNumber: ?int}|null */
    private function numericWaveMeta(string $occurrenceKey): ?array
    {
        if (preg_match('/^sequence-wave-(\d+)(?:-step-(\d+))?$/', $occurrenceKey, $matches) !== 1) {
            return null;
        }

        return [
            'waveNumber' => (int) $matches[1],
            'stepNumber' => isset($matches[2]) ? (int) $matches[2] : null,
        ];
    }
}
