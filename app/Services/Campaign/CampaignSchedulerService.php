<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Services\Scheduling\BusinessCalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CampaignSchedulerService — materialises recurring and paced CampaignRun rows.
 *
 * Design (queue-idempotency.md §4, campaign-automation.md §3):
 *   - Reads campaigns where schedule_type is recurring or paced, is_active=true,
 *     next_run_at IS NOT NULL and <= now().
 *   - For each, inserts a CampaignRun with a deterministic occurrence_key (the unique
 *     DB constraint is the durable backstop — duplicate calls are no-ops).
 *   - Advances campaigns.next_run_at in the campaign timezone (Carbon, DST-aware).
 *   - Never "loop and send N now"; always generates exactly one run per call, then
 *     advances the cursor. The scheduler runs every minute so missed windows are
 *     caught on the next tick.
 *   - Paused campaigns: skipped entirely (not even cursor-advanced).
 *
 * Business-calendar handling (Planification settings — skip weekends / blackout
 * dates) is deliberately frequency-dependent:
 *   - 'daily'  occurrences on a blocked day are SKIPPED entirely (no run row at
 *     all): shifting would pile a Sat-anchored AND a Sun-anchored daily
 *     occurrence onto the same Monday, producing two runs the same day.
 *   - 'weekly'/'monthly' occurrences are SHIFTED forward to the next allowed day
 *     instead: skipping a Saturday-anchored weekly campaign would mean it
 *     silently never runs, ever again.
 *   - The occurrence_key is ALWAYS derived from the unshifted `next_run_at`
 *     ("anchor"), never the shifted value — two shifted occurrences landing on
 *     the same Monday still get distinct keys, so UNIQUE(campaign_id,
 *     occurrence_key) keeps its exact current meaning and no key migration is
 *     needed. Only `run_at` (the stored value) reflects the shift.
 */
class CampaignSchedulerService
{
    /** Mirrors BusinessCalendarService::MAX_SHIFT_ITERATIONS — same fail-open rationale. */
    private const MAX_DAILY_SKIP_ITERATIONS = 366;

    public function __construct(
        private readonly PacedCampaignBatchService $pacedBatchService,
        private readonly BusinessCalendarService $calendar,
    ) {}

    /**
     * Find all recurring/paced campaigns due for a new run and materialise them.
     *
     * Idempotent: CampaignRun::firstOrCreate with the unique(campaign_id, occurrence_key)
     * key means concurrent/repeated calls produce exactly one run row.
     *
     * @return int  Number of new CampaignRun rows created (or found already existing).
     */
    public function generateDueRuns(): int
    {
        $count = 0;

        $campaigns = Campaign::where('schedule_type', 'recurring')
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($campaigns as $campaign) {
            $tz = $campaign->timezone ?? 'UTC';

            // The anchor is the RAW next_run_at — never mutated by shifting. The
            // occurrence_key is derived from it (below) and computeNextRun() also
            // advances FROM it, not from any shifted value. See the class docblock.
            $anchor = $campaign->next_run_at;

            // Deterministic key: 'rec-' + anchor formatted in UTC (stored value).
            // Using ISO-8601-compact so the key is filesystem/log friendly and unique.
            $occ = 'rec-' . $anchor->format('YmdHis');

            $frequency = $campaign->recurrence['frequency'] ?? 'daily';

            if ($this->isDailyLike($frequency) && $this->calendar->isBlocked($anchor, $tz)) {
                // Skip vs shift depends on frequency — see class docblock. No
                // CampaignRun row is created for this occurrence; the cursor
                // still advances below so the scheduler picks up the next one.
                Log::channel('campaign')->debug('[CampaignSchedulerService] Daily-like occurrence skipped (blocked day).', [
                    'campaign_id'    => $campaign->id,
                    'occurrence_key' => $occ,
                ]);
            } else {
                // Insert or retrieve — the UNIQUE(campaign_id, occurrence_key) constraint
                // is the durable backstop. Concurrent callers will both succeed on the
                // firstOrCreate call, but only one INSERT wins; the other gets the existing row.
                CampaignRun::firstOrCreate(
                    [
                        'campaign_id'    => $campaign->id,
                        'occurrence_key' => $occ,
                    ],
                    [
                        // Shifted for weekly/monthly (or an unblocked daily) — the key
                        // above still comes from the unshifted anchor.
                        'run_at' => $this->calendar->shiftToAllowed($anchor, $tz),
                        'status' => 'scheduled',
                    ],
                );

                $count++;

                Log::channel('campaign')->debug('[CampaignSchedulerService] Recurring run materialised.', [
                    'campaign_id'    => $campaign->id,
                    'occurrence_key' => $occ,
                ]);
            }

            // Advance the cursor FROM THE ANCHOR (never from a shifted value) in
            // the campaign timezone (DST-aware via Carbon).
            $nextRun = $this->computeNextRun(
                $campaign->recurrence ?? [],
                $anchor,
                $tz,
            );

            if ($nextRun === null) {
                // Recurrence ended (past 'until') — pause the campaign.
                $campaign->update([
                    'is_active'   => false,
                    'next_run_at' => null,
                ]);

                Log::channel('campaign')->info('[CampaignSchedulerService] Recurring campaign ended.', [
                    'campaign_id' => $campaign->id,
                    'last_run_at' => $campaign->next_run_at,
                ]);
            } else {
                $campaign->update(['next_run_at' => $nextRun]);

                Log::channel('campaign')->debug('[CampaignSchedulerService] Recurring cursor advanced.', [
                    'campaign_id'    => $campaign->id,
                    'occurrence_key' => $occ,
                    'next_run_at'    => $nextRun->toIso8601String(),
                ]);
            }
        }

        $pacedCampaigns = Campaign::where('schedule_type', 'paced')
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($pacedCampaigns as $campaign) {
            // Candidate query is only an optimization. evaluateDue() locks and
            // rechecks the fresh row before any run/cursor mutation.
            $run = $this->pacedBatchService->evaluateDue($campaign, now());

            if ($run?->wasRecentlyCreated) {
                $count++;
            }

            Log::channel('campaign')->debug('[CampaignSchedulerService] Paced batch evaluated.', [
                'campaign_id' => $campaign->id,
                'occurrence_key' => $run?->occurrence_key,
                'next_run_at' => $campaign->fresh()?->next_run_at?->toIso8601String(),
            ]);
        }

        return $count;
    }

    /** Advance one Monday-Friday occurrence while preserving local wall time. */
    public function computeNextBusinessRun(Carbon $from, string $tz): Carbon
    {
        return $this->pacedBatchService->computeNextBusinessRun($from, $tz);
    }

    /**
     * Compute the next run timestamp for a recurring campaign.
     *
     * Interprets the recurrence array:
     *   - 'frequency' (required) : 'daily' | 'weekly' | 'monthly'
     *   - 'interval'  (optional) : positive int, default 1
     *   - 'until'     (optional) : ISO-8601 date string — if the computed next run
     *                              falls strictly AFTER this date, returns null (ended).
     *
     * All arithmetic is performed in the campaign timezone so that DST transitions
     * (e.g. Europe/Paris clocks moving 1 h forward/back) don't drift the send time
     * by an hour. $from is a UTC Carbon instance; we re-interpret it in $tz using
     * ->copy()->setTimezone($tz) (NOT Carbon::parse($from, $tz) — that ignores the
     * $tz arg when $from is already a Carbon, keeping UTC and causing DST drift).
     * Interval math then runs in the local timezone; the result is converted back to
     * UTC for storage via ->utc().
     *
     * Business-calendar note: 'daily' (and the unknown-frequency fallback, which
     * the match() below also treats as daily) occurrences landing on a blocked
     * day are pushed forward day-by-day until an allowed day is found — SKIPPED,
     * not shifted like weekly/monthly (see the class docblock for why). This is
     * the loop below; the `until` check is duplicated INSIDE it (not only after
     * it) because otherwise a daily campaign with a distant `until` could churn
     * through the full MAX_DAILY_SKIP_ITERATIONS bound before ever noticing it
     * had already passed the boundary.
     *
     * A weekly/monthly occurrence is NOT skip-looped here — generateDueRuns()
     * shifts it forward (via BusinessCalendarService::shiftToAllowed()) instead.
     * That shift can land AFTER `until`; such an occurrence is still delivered —
     * `until` bounds when an occurrence falls due, not the already-computed
     * delivery instant after shifting.
     *
     * @param  array       $recurrence  Decoded recurrence JSON.
     * @param  Carbon      $from        The current next_run_at (UTC Carbon instance).
     * @param  string      $tz          IANA timezone identifier (e.g. 'Europe/Paris').
     * @return Carbon|null              UTC Carbon of the next occurrence, or null if ended.
     */
    public function computeNextRun(array $recurrence, Carbon $from, string $tz): ?Carbon
    {
        $frequency = $recurrence['frequency'] ?? 'daily';
        $interval  = max(1, (int) ($recurrence['interval'] ?? 1));
        $until     = isset($recurrence['until'])
            ? Carbon::parse($recurrence['until'], $tz)->endOfDay()
            : null;

        // Re-interpret the UTC $from in the campaign timezone.
        // setTimezone() on an existing Carbon instance correctly shifts to the target
        // tz (preserving the instant), unlike Carbon::parse($from, $tz) which silently
        // ignores the $tz argument when $from is already a Carbon object.
        $localFrom = $from->copy()->setTimezone($tz);

        $next = match ($frequency) {
            'weekly'  => $localFrom->addWeeks($interval),
            'monthly' => $localFrom->addMonths($interval),
            default   => $localFrom->addDays($interval),   // 'daily' + unknown → daily
        };

        if ($this->isDailyLike($frequency)) {
            // $next is already a LOCAL Carbon (setTimezone($tz) above) — use
            // isBlockedDate(), not isBlocked(), to avoid a redundant conversion.
            $iterations = 0;

            while ($this->calendar->isBlockedDate($next)) {
                if ($until !== null && $next->gt($until)) {
                    return null;
                }

                $iterations++;
                if ($iterations > self::MAX_DAILY_SKIP_ITERATIONS) {
                    // Fail-open, mirroring BusinessCalendarService::shiftToAllowed():
                    // stop advancing and fall through with whatever $next currently
                    // is rather than looping forever or throwing.
                    Log::channel('campaign')->error('[CampaignSchedulerService] computeNextRun exceeded '.self::MAX_DAILY_SKIP_ITERATIONS.' consecutive blocked days while skipping a daily-like occurrence.', [
                        'frequency' => $frequency,
                        'from'      => $from->toIso8601String(),
                        'tz'        => $tz,
                    ]);

                    break;
                }

                $next->addDays($interval);
            }
        }

        // Past the 'until' boundary → recurrence ended.
        if ($until !== null && $next->gt($until)) {
            return null;
        }

        // Return as UTC — Laravel stores datetimes in UTC.
        return $next->utc();
    }

    /** 'weekly' and 'monthly' shift; everything else (including unknown/missing) skips like 'daily'. */
    private function isDailyLike(string $frequency): bool
    {
        return $frequency !== 'weekly' && $frequency !== 'monthly';
    }
}
