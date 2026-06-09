<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use Carbon\Carbon;

/**
 * SendWindowGuard — checks whether the current time falls inside a campaign's send window.
 *
 * Send window JSON shape (campaign.send_window):
 * {
 *   "days":  [1, 2, 3, 4, 5],   // ISO day-of-week: 1=Monday … 7=Sunday (optional; omit = all days)
 *   "start": "08:00",            // HH:MM 24-hour in campaign timezone (optional; omit = 00:00)
 *   "end":   "18:00"             // HH:MM 24-hour in campaign timezone (optional; omit = 23:59)
 * }
 *
 * All comparisons are made in the campaign's timezone. When no send_window is set,
 * isWithinSendWindow() always returns true (no restriction).
 *
 * @see campaign-automation.md §5 (send windows + timezone)
 */
class SendWindowGuard
{
    /**
     * Determine whether the current moment is inside the campaign's send window.
     *
     * @param  Campaign  $campaign  Campaign with send_window (json array) and timezone filled.
     * @return bool  True = within window (send allowed); false = outside window (defer send).
     */
    public function isWithinSendWindow(Campaign $campaign): bool
    {
        $window = $campaign->send_window;

        // No send window configured — always allowed.
        if (empty($window)) {
            return true;
        }

        $tz  = $campaign->timezone ?: 'UTC';
        $now = Carbon::now($tz);

        // ── Day-of-week check (1=Mon … 7=Sun, ISO) ────────────────────────────
        if (! empty($window['days']) && is_array($window['days'])) {
            $todayIso = $now->dayOfWeekIso; // 1=Monday, 7=Sunday
            if (! in_array($todayIso, $window['days'], strict: false)) {
                return false;
            }
        }

        // ── Time-of-day check ─────────────────────────────────────────────────
        $startStr = $window['start'] ?? '00:00';
        $endStr   = $window['end']   ?? '23:59';

        [$startH, $startM] = array_map('intval', explode(':', $startStr));
        [$endH,   $endM]   = array_map('intval', explode(':', $endStr));

        $windowStart = $now->copy()->setTime($startH, $startM, 0);
        $windowEnd   = $now->copy()->setTime($endH,   $endM,   59);

        if ($now->lt($windowStart) || $now->gt($windowEnd)) {
            return false;
        }

        return true;
    }
}
