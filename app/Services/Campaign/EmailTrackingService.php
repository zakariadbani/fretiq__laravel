<?php

namespace App\Services\Campaign;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\EmailTrackingEvent;
use App\Models\SequenceStepSend;
use Carbon\CarbonInterface;

/**
 * EmailTrackingService — records open/click events from the 1×1 tracking
 * pixel and the click-through redirect.
 *
 * Classifies each open/click as human or machine based on the User-Agent
 * string. The stats_opened counter on CampaignRun is incremented at most
 * once per unique human recipient (first unique human open only).
 *
 * Machine opens (Google Image Proxy, Apple Mail Privacy Protection, etc.) are
 * counted but do NOT increment run.stats_opened or set recipient.opened_at.
 */
class EmailTrackingService
{
    /**
     * CampaignRecipient statuses a click may advance FROM into 'clicked'.
     * Never downgrades a terminal outcome (unsubscribed/bounced/replied) —
     * those are simply not in this list.
     */
    private const CLICKABLE_RECIPIENT_STATUSES = ['queued', 'sent', 'delivered', 'opened'];

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

        // Update the trackable (CampaignRecipient or SequenceStepSend) and any
        // owning aggregate — only on the first unique human open per trackable.
        if (! $isFirstHumanOpen) {
            return;
        }

        $this->markOpened($event->trackable_type, $event->trackable_id, $now);
    }

    /**
     * Record a click-through event for the given tracking token, then let the
     * caller redirect to the (already signature-verified) destination.
     *
     * Same human/machine UA filter as recordOpen(); a machine click records
     * nothing (never advances clicked_at/status). A genuine click also
     * implies the message was opened, even if the pixel never loaded —
     * common, since many clients block remote images by default.
     *
     * Returns silently on unknown tokens or a machine UA — the caller must
     * redirect regardless, so a tracking miss never breaks the recipient's click.
     */
    public function recordClick(string $token, ?string $ip, ?string $userAgent): void
    {
        if (strlen($token) !== 64 || ! ctype_xdigit($token)) {
            return;
        }

        /** @var EmailTrackingEvent|null $event */
        $event = EmailTrackingEvent::where('token', $token)->first();

        if ($event === null || $this->isMachineOpen($userAgent)) {
            return;
        }

        $now = now();

        if ($event->event !== 'clicked') {
            $event->update(['event' => 'clicked']);
        }

        $this->markOpened($event->trackable_type, $event->trackable_id, $now);

        match ($event->trackable_type) {
            CampaignRecipient::class => $this->markCampaignRecipientClicked($event->trackable_id, $now),
            SequenceStepSend::class  => $this->markSequenceStepSendClicked($event->trackable_id, $now),
            default => null,
        };
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Mark a trackable as opened (first open only) — dispatches by trackable
     * type. Shared by recordOpen()'s first-human-open branch and
     * recordClick()'s "a click implies an open" step.
     */
    private function markOpened(string $trackableType, int $trackableId, CarbonInterface $now): void
    {
        match ($trackableType) {
            CampaignRecipient::class => $this->markCampaignRecipientOpened($trackableId, $now),
            SequenceStepSend::class  => $this->markSequenceStepSendOpened($trackableId, $now),
            default => null,
        };
    }

    private function markCampaignRecipientOpened(int $recipientId, CarbonInterface $now): void
    {
        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::find($recipientId);

        if ($recipient === null || $recipient->opened_at !== null) {
            return;
        }

        $recipient->update(['opened_at' => $now]);

        // Increment run.stats_opened atomically — only on first unique human open.
        CampaignRun::where('id', $recipient->campaign_run_id)
            ->increment('stats_opened');
    }

    private function markSequenceStepSendOpened(int $stepSendId, CarbonInterface $now): void
    {
        /** @var SequenceStepSend|null $stepSend */
        $stepSend = SequenceStepSend::find($stepSendId);

        if ($stepSend === null || $stepSend->opened_at !== null) {
            return;
        }

        $stepSend->update(['opened_at' => $now]);
    }

    private function markCampaignRecipientClicked(int $recipientId, CarbonInterface $now): void
    {
        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::find($recipientId);

        if ($recipient === null) {
            return;
        }

        $attributes = [];
        if ($recipient->clicked_at === null) {
            $attributes['clicked_at'] = $now;
        }
        if (in_array($recipient->status, self::CLICKABLE_RECIPIENT_STATUSES, true)) {
            $attributes['status'] = 'clicked';
        }

        if ($attributes !== []) {
            $recipient->update($attributes);
        }
    }

    /** First-write-wins, mirroring markSequenceStepSendOpened(). */
    private function markSequenceStepSendClicked(int $stepSendId, CarbonInterface $now): void
    {
        /** @var SequenceStepSend|null $stepSend */
        $stepSend = SequenceStepSend::find($stepSendId);

        if ($stepSend === null || $stepSend->clicked_at !== null) {
            return;
        }

        $stepSend->update(['clicked_at' => $now]);
    }

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
