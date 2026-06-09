<?php

namespace App\Services\Campaign;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\EmailTrackingEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EmailTrackingService — records open events from the 1×1 tracking pixel.
 *
 * Classifies each open as human or machine based on the User-Agent string.
 * The stats_opened counter on CampaignRun is incremented at most once per
 * unique human recipient (first unique human open only).
 *
 * Machine opens (Google Image Proxy, Apple Mail Privacy Protection, etc.) are
 * counted but do NOT increment run.stats_opened or set recipient.opened_at.
 */
class EmailTrackingService
{
    /**
     * Known bot/proxy User-Agent substrings that indicate a machine open.
     * Add more as they are identified from production logs.
     */
    private const MACHINE_UA_PATTERNS = [
        'GoogleImageProxy',
        'Google Image Proxy',
        'YahooMailProxy',
        'DuckDuckGo',
        'Outlook',
        'MailChimpBot',
        'Apple-Mail',
        'Thunderbird',
        'Lotus-Notes',
        'Windows Live Mail',
        'Zimbra',
        'Postfix',
        'aiohttp',
        'python-requests',
        'Go-http-client',
        'curl/',
        'wget/',
        'Premail',
        'PreviewLoader',
        'Mail Sender',
    ];

    /**
     * Record an open event for the given tracking token.
     *
     * Idempotent-ish: each pixel load increments the relevant counter, but
     * run.stats_opened is only incremented once per unique human recipient
     * (guarded by recipient.opened_at being non-null on subsequent calls).
     *
     * Returns silently on unknown tokens (invalid / expired tokens).
     */
    public function recordOpen(string $token, ?string $ip, ?string $userAgent): void
    {
        if (strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return;
        }

        /** @var EmailTrackingEvent|null $event */
        $event = EmailTrackingEvent::where('token', $token)->first();

        if ($event === null) {
            // Unknown token — could be a stale/forged request; ignore silently.
            return;
        }

        $isMachine = $this->isMachineOpen($userAgent);
        $now       = now();

        if ($isMachine) {
            $event->increment('machine_open_count');
            $event->update(['last_opened_ip' => $ip]);
            return;
        }

        // Human open — update counters and timestamps.
        $isFirstHumanOpen = $event->first_human_open_at === null;

        $event->increment('human_open_count');
        $event->update([
            'last_human_open_at' => $now,
            'last_opened_ip'     => $ip,
            'first_human_open_at' => $event->first_human_open_at ?? $now,
        ]);

        // Update the trackable (CampaignRecipient) and the run stats
        // only on the first unique human open per recipient.
        if (! $isFirstHumanOpen) {
            return;
        }

        if ($event->trackable_type !== CampaignRecipient::class) {
            return;
        }

        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::find($event->trackable_id);

        if ($recipient === null) {
            return;
        }

        $alreadyOpened = $recipient->opened_at !== null;

        if (! $alreadyOpened) {
            $recipient->update(['opened_at' => $now]);

            // Increment run.stats_opened atomically — only on first unique human open.
            CampaignRun::where('id', $recipient->campaign_run_id)
                ->increment('stats_opened');
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Classify a User-Agent as machine (bot/proxy) or human.
     *
     * Default: human (conservative — it's better to slightly over-count human
     * opens than to silently suppress real engagement signals).
     */
    private function isMachineOpen(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return false; // Treat missing UA as human (e.g. some email clients omit it).
        }

        foreach (self::MACHINE_UA_PATTERNS as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
