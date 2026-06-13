<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CampaignSchedulerService — materialises recurring CampaignRun rows.
 *
 * Design (queue-idempotency.md §4, campaign-automation.md §3):
 *   - Reads campaigns where schedule_type='recurring', status='active', is_active=true,
 *     next_run_at IS NOT NULL and <= now().
 *   - For each, inserts a CampaignRun with a deterministic occurrence_key (the unique
 *     DB constraint is the durable backstop — duplicate calls are no-ops).
 *   - Advances campaigns.next_run_at in the campaign timezone (Carbon, DST-aware).
 *   - Never "loop and send N now"; always generates exactly one run per call, then
 *     advances the cursor. The scheduler runs every minute so missed windows are
 *     caught on the next tick.
 *   - Paused campaigns: skipped entirely (not even cursor-advanced).
 */
class CampaignSchedulerService
{
    /**
     * Find all recurring campaigns due for a new run and materialise them.
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
            ->where('status', 'active')
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($campaigns as $campaign) {
            // Deterministic key: 'rec-' + next_run_at formatted in UTC (stored value).
            // Using ISO-8601-compact so the key is filesystem/log friendly and unique.
            $occ = 'rec-' . $campaign->next_run_at->format('YmdHis');

            // Insert or retrieve — the UNIQUE(campaign_id, occurrence_key) constraint
            // is the durable backstop. Concurrent callers will both succeed on the
            // firstOrCreate call, but only one INSERT wins; the other gets the existing row.
            CampaignRun::firstOrCreate(
                [
                    'campaign_id'    => $campaign->id,
                    'occurrence_key' => $occ,
                ],
                [
                    'run_at' => $campaign->next_run_at,
                    'status' => 'scheduled',
                ],
            );

            $count++;

            // Advance the cursor in the campaign timezone (DST-aware via Carbon).
            $nextRun = $this->computeNextRun(
                $campaign->recurrence ?? [],
                $campaign->next_run_at,
                $campaign->timezone ?? 'UTC',
            );

            if ($nextRun === null) {
                // Recurrence ended (past 'until') — mark the campaign done.
                $campaign->update([
                    'status'      => 'done',
                    'next_run_at' => null,
                ]);

                Log::info('[CampaignSchedulerService] Recurring campaign ended.', [
                    'campaign_id' => $campaign->id,
                    'last_run_at' => $campaign->next_run_at,
                ]);
            } else {
                $campaign->update(['next_run_at' => $nextRun]);

                Log::debug('[CampaignSchedulerService] Recurring run materialised.', [
                    'campaign_id'    => $campaign->id,
                    'occurrence_key' => $occ,
                    'next_run_at'    => $nextRun->toIso8601String(),
                ]);
            }
        }

        return $count;
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

        // Past the 'until' boundary → recurrence ended.
        if ($until !== null && $next->gt($until)) {
            return null;
        }

        // Return as UTC — Laravel stores datetimes in UTC.
        return $next->utc();
    }
}
