<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Campaign\CampaignSchedulerService;
use App\Services\Scheduling\BusinessCalendarService;
use Carbon\Carbon;

/**
 * PlannerService — provides a FullCalendar-compatible event feed.
 *
 * The feed merges three sources:
 *   1. Materialised CampaignRun rows (real runs — scheduled, sent, done, …).
 *   2. Virtual "projected" events synthesised from recurring campaign definitions
 *      for future occurrences not yet materialised in the DB.
 *   3. Materialised discovery runs plus future automatic projections.
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
     *
     * Business-calendar note: a blocked day burns a traversal without
     * burning an emit slot (see the recurring-projection loop below), so
     * over a normal week that's ~7 traversals per 5 emits — roughly a 1.4×
     * multiplier versus an all-days recurrence. Still far under this cap
     * even for a long-lived daily campaign.
     */
    private const PROJECTION_TRAVERSAL_CAP = 5000;

    public function __construct(
        private readonly CampaignSchedulerService $scheduler,
        private readonly BusinessCalendarService $calendar,
    ) {}

    /**
     * Return a FullCalendar-shaped event array for the given date range.
     *
     * Merges three sources:
     *   - Materialised CampaignRun rows (real runs).
     *   - Virtual "projected" events for future recurring occurrences not yet
     *     in the DB (synthesised from campaign.next_run_at + recurrence rules).
     *   - Materialised discovery runs plus future automatic projections.
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
     * @param  string       $timezone  Timezone for bounds without an explicit offset.
     * @return array<int, array<string, mixed>>
     */
    public function runsFeed(?string $start = null, ?string $end = null, string $timezone = 'UTC'): array
    {
        $statusConfig = config('global.data.campaign_run_statuses', []);
        $startUtc = $start !== null ? Carbon::parse($start, $timezone)->utc() : null;
        $endUtc = $end !== null ? Carbon::parse($end, $timezone)->utc() : null;

        // ── 1. Real runs ───────────────────────────────────────────────────────
        $query = CampaignRun::with(['campaign.sequence.steps', 'sequenceStep'])
            ->whereDoesntHave('campaign', fn ($campaign) => $campaign->where('name', 'like', 'E2E\_FIXTURE %'))
            // Canceled occurrences belong to history, not Planning. A paused
            // non-sequence campaign hides only work that is still safe to defer;
            // sending, sent, and failed runs remain visible as truthful history.
            ->where('status', '!=', 'canceled')
            ->where(function ($runQuery) {
                $runQuery->whereIn('status', ['sending', 'sent', 'failed'])
                    ->orWhereDoesntHave('campaign')
                    ->orWhereHas('campaign', function ($campaignQuery) {
                        $campaignQuery->where('schedule_type', 'sequence')
                            ->orWhere('is_active', true);
                    });
            })
            ->orderBy('run_at');

        if ($startUtc !== null) {
            $query->where('run_at', '>=', $startUtc);
        }

        if ($endUtc !== null) {
            $query->where('run_at', '<', $endUtc);
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

        $discoveryQuery = DiscoveryRun::with('prospectCriteria')
            ->where('type', 'discovery')
            ->orderBy('created_at');

        if ($startUtc !== null) {
            $discoveryQuery->where('created_at', '>=', $startUtc);
        }

        if ($endUtc !== null) {
            $discoveryQuery->where('created_at', '<', $endUtc);
        }

        $discoveryStatusConfig = config('global.data.discovery_run_statuses', []);
        $discoveryRuns = $discoveryQuery->get();
        $materializedDiscoveryDays = [];

        foreach ($discoveryRuns as $run) {
            $criteria = $run->prospectCriteria;
            if ($criteria === null) {
                continue;
            }

            $meta = $discoveryStatusConfig[$run->status] ?? [];
            $statusColor = $meta['color'] ?? 'secondary';
            $eventAt = $run->created_at;
            $materializedDate = $run->quota_date?->toDateString()
                ?? $eventAt->copy()->setTimezone($timezone)->toDateString();
            $materializedDiscoveryDays[$criteria->id.'|'.$materializedDate] = true;

            $events[] = [
                'id' => 'discovery-run-'.$run->id,
                'title' => "D\u{00E9}couverte \u{00B7} ".$criteria->name,
                'start' => $eventAt->toIso8601String(),
                'color' => self::BOOTSTRAP_HEX_COLORS[$statusColor] ?? self::BOOTSTRAP_HEX_COLORS['secondary'],
                'url' => route('admin.prospect_criteria.view', $run->prospect_criteria_id),
                'extendedProps' => [
                    'status' => $run->status,
                    'statusLabel' => $meta['label'] ?? $run->status,
                    'statusColor' => $statusColor,
                    'eventKind' => 'discovery-run',
                ],
            ];
        }

        // ── 2. Projected future occurrences ────────────────────────────────────
        // Only meaningful when there is at least one bound so we have a finite
        // projection window. Null window = unbounded; skip projection.
        if ($start !== null || $end !== null) {
            $projectedHex = self::BOOTSTRAP_HEX_COLORS['info'];

            // Parse window bounds to UTC Carbons.
            // FullCalendar sends tz-offset ISO strings (e.g. 2026-05-31T00:00:00+02:00).
            // Carbon::parse() handles these correctly; ->utc() normalises for comparison.
            $windowStart = $startUtc ?? now()->utc();
            $windowEnd = $endUtc ?? now()->utc()->addMonths(3);

            $quotaRuns = DiscoveryRun::query()
                ->where('type', 'discovery')
                ->whereNotNull('prospect_criteria_id')
                ->whereBetween('quota_date', [
                    $windowStart->copy()->setTimezone($timezone)->toDateString(),
                    $windowEnd->copy()->setTimezone($timezone)->toDateString(),
                ])
                ->get(['prospect_criteria_id', 'quota_date']);

            foreach ($quotaRuns as $run) {
                $materializedDiscoveryDays[$run->prospect_criteria_id.'|'.$run->quota_date->toDateString()] = true;
            }

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
                ->where('name', 'not like', 'E2E\_FIXTURE %')
                ->where('is_active', true)
                ->whereNotNull('next_run_at')
                ->get();

            foreach ($recurringCampaigns as $campaign) {
                $cursor    = $campaign->next_run_at->copy()->utc();
                $tz        = $campaign->timezone ?? 'UTC';
                $recurrence = $campaign->recurrence ?? [];

                // Business-calendar handling mirrors CampaignSchedulerService::
                // generateDueRuns() exactly (see that class's docblock): daily-like
                // frequencies are SKIPPED on a blocked day (no event at all — a
                // shift would pile a Sat- and a Sun-anchored daily occurrence onto
                // the same Monday); weekly/monthly are SHIFTED forward instead
                // (skipping a Saturday-anchored weekly campaign would mean it
                // silently never projects, ever).
                $frequency   = $recurrence['frequency'] ?? 'daily';
                $isDailyLike = $frequency !== 'weekly' && $frequency !== 'monthly';

                $emitted    = 0;
                $traversals = 0;

                // Emit-then-advance: emit $emitAt if in window, then advance $cursor.
                // Pre-window iterations (emitAt < windowStart) skip without counting
                // toward the emit cap (they do count toward traversal backstop). The
                // loop CONDITION still tests the unshifted $cursor against $windowEnd
                // (mirroring how generateDueRuns() advances from the unshifted
                // anchor) — the emit decision below is what moved to $emitAt.
                // shiftToAllowed() only ever shifts FORWARD (never earlier than its
                // input — see BusinessCalendarService's hard contract), so once
                // $cursor >= $windowEnd the loop already stops; no in-window
                // occurrence can be skipped at the trailing edge by continuing to
                // gate the while-condition on $cursor.
                while ($cursor !== null && $cursor->lt($windowEnd)) {
                    if ($traversals++ >= self::PROJECTION_TRAVERSAL_CAP) {
                        break;
                    }

                    // Daily-like on a blocked day: advance without emitting.
                    // computeNextRun() already skip-loops daily-like frequencies
                    // internally, so in practice this only ever fires on the very
                    // FIRST cursor (the raw, not-yet-normalised next_run_at anchor
                    // straight from the DB) — every subsequent cursor value it
                    // hands back has already been walked past any blocked days.
                    if ($isDailyLike && $this->calendar->isBlocked($cursor, $tz)) {
                        $cursor = $this->scheduler->computeNextRun($recurrence, $cursor, $tz);

                        continue;
                    }

                    // Weekly/monthly: the occurrence still falls on $cursor, but
                    // it DELIVERS on the next allowed day. Daily-like: $cursor is
                    // already unblocked at this point (see above), so shifting is
                    // a no-op — computed anyway to keep one code path.
                    $emitAt = $isDailyLike ? $cursor : $this->calendar->shiftToAllowed($cursor, $tz);

                    // Gate the emit decision on $emitAt, NOT the unshifted $cursor.
                    // A weekly/monthly cursor that sits just before $windowStart on
                    // a blocked day (e.g. a Sunday) can shift INTO the window (e.g.
                    // the following Monday) — CampaignSchedulerService::
                    // generateDueRuns() will materialise a real run there, so the
                    // projection must show it too. Testing the unshifted $cursor
                    // would silently drop that occurrence — and the loop does NOT
                    // self-correct: skipping the emit here does not re-try this
                    // occurrence, the cursor simply advances to a different
                    // occurrence a whole cycle later. The mirror case also applies:
                    // a cursor inside the window whose shift pushes $emitAt to or
                    // past $windowEnd must NOT be rendered on the page the user
                    // asked for.
                    if ($emitAt->gte($windowStart) && $emitAt->lt($windowEnd)) {
                        // Check emit cap (in-window only).
                        if ($emitted >= self::PROJECTION_EMIT_CAP) {
                            break;
                        }

                        // CRITICAL: dedup on $emitAt (the shifted value), never on
                        // $cursor (the unshifted one). $realRunKeys is keyed on the
                        // materialised run's real run_at, which generateDueRuns()
                        // now WRITES SHIFTED — keying this lookup on the unshifted
                        // cursor would miss the match and render every already-
                        // materialised shifted run TWICE (the real run + a phantom
                        // projection at the wrong, unshifted timestamp).
                        $dedupKey = $campaign->id . '|' . $emitAt->format('YmdHis');

                        if (!isset($realRunKeys[$dedupKey])) {
                            $events[] = [
                                'id'    => 'projected-' . $campaign->id . '-' . $emitAt->format('YmdHis'),
                                'title' => $campaign->name,
                                'start' => $emitAt->toIso8601String(),
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

                    // Advance cursor via CampaignSchedulerService::computeNextRun(),
                    // FROM THE UNSHIFTED $cursor — never from $emitAt — exactly as
                    // generateDueRuns() advances from the anchor, not the shifted
                    // run_at. Also DST-correct after Fix 0.
                    $cursor = $this->scheduler->computeNextRun($recurrence, $cursor, $tz);
                }
            }

            // Later sequence waves depend on the audience remaining after this one.
            $sequenceCampaigns = Campaign::with(['sequence.steps', 'segment', 'senderIdentity'])
                ->where('name', 'not like', 'E2E\_FIXTURE %')
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
                // Shift BEFORE the window check: PacedSequenceEnrollmentService::
                // evaluateDue() already normalises a blocked-day cursor to the
                // next allowed local day at evaluation time (its own
                // shiftToAllowed-based while loop), so this projection is
                // showing where the cursor WILL land, not where it raw-sits in
                // the DB. Checking the window against the unshifted value would
                // both wrongly DROP a cursor that shifts INTO the window and
                // wrongly SHOW one that shifts OUT of it.
                $tz     = $campaign->scheduleTimezone();
                $cursor = $this->calendar->shiftToAllowed($campaign->next_run_at->copy()->utc(), $tz);

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

            $projectionDate = $windowStart->copy()->setTimezone($timezone)->startOfDay();
            $today = today($timezone);
            if ($projectionDate->lt($today)) {
                $projectionDate = $today;
            }

            $autoCriteria = ProspectCriteria::query()
                ->where('is_active', true)
                ->where('auto_run', true)
                ->whereNotNull('run_at_hour')
                ->get();

            foreach ($autoCriteria as $criteria) {
                $date = $projectionDate->copy();
                $emitted = 0;
                $traversals = 0;
                while ($emitted < self::PROJECTION_EMIT_CAP) {
                    // Traversal backstop for symmetry with the recurring-projection
                    // loop above. Not strictly load-bearing here — the loop below
                    // always terminates via the unconditional $cursorUtc >= $windowEnd
                    // break, and $windowEnd is always finite (defaults to now()+3mo)
                    // — but cheap insurance against a future change that removes
                    // that guarantee.
                    if ($traversals++ >= self::PROJECTION_TRAVERSAL_CAP) {
                        break;
                    }

                    // $date is already in $timezone (built above) — isBlockedDate()
                    // reads it as-is, no re-conversion.
                    if ($this->calendar->isBlockedDate($date)) {
                        $date->addDay()->startOfDay();

                        continue;
                    }

                    $cursorUtc = $date->copy()->setTime((int) $criteria->run_at_hour, 0)->utc();
                    if ($cursorUtc->gte($windowEnd)) {
                        break;
                    }

                    if ($cursorUtc->gte($windowStart) && ! isset($materializedDiscoveryDays[$criteria->id.'|'.$date->toDateString()])) {
                        $events[] = [
                            'id' => 'projected-discovery-'.$criteria->id.'-'.$cursorUtc->format('YmdHis'),
                            'title' => "D\u{00E9}couverte \u{00B7} {$criteria->name}",
                            'start' => $cursorUtc->toIso8601String(),
                            'color' => self::BOOTSTRAP_HEX_COLORS['dark'],
                            'url' => route('admin.prospect_criteria.view', $criteria->id),
                            'extendedProps' => [
                                'status' => 'projected',
                                'statusLabel' => "D\u{00E9}couverte automatique",
                                'statusColor' => 'dark',
                                'eventKind' => 'discovery-projection',
                            ],
                        ];
                        $emitted++;
                    }

                    $date->addDay()->startOfDay();
                }
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
