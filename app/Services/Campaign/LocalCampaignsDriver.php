<?php

namespace App\Services\Campaign;

use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * LocalCampaignsDriver — development/test implementation of CampaignsClient.
 *
 * Sends via Laravel Mail, which in dev routes to Mailpit and in tests respects
 * Mail::fake(). Never uses Mail::raw() — a real CampaignMailable is always used.
 *
 * Residual idempotency note: if the process dies after Mail::send() completes but
 * before the caller persists provider_message_id, the next retry will re-send
 * (recipient still has status='queued' + provider_message_id IS NULL). This is
 * accepted for the local driver. A real SMTP/Zoho driver should pass a provider-
 * level idempotency key (e.g. the recipient DB id as a Message-ID header) to
 * prevent the rare double-send.
 *
 * ZohoCampaignsDriver is Phase 5 — do NOT build it yet.
 */
class LocalCampaignsDriver implements CampaignsClient
{
    /**
     * Send the campaign email to one recipient and return a local message ID.
     *
     * @throws \RuntimeException  Propagated from Mail on delivery failure.
     */
    public function send(
        CampaignRecipient $recipient,
        Campaign $campaign,
        CampaignRun $run,
        string $trackingToken,
        string $unsubscribeUrl,
    ): string {
        // Eager-load relations needed by the Mailable if not already loaded.
        $contact  = $recipient->contact ?? $recipient->load('contact')->contact;
        $template = $campaign->template  ?? $campaign->load('template')->template;

        // The subject comes from the campaign (may override the template default).
        // Merge tags are substituted per-recipient so each contact sees their own name.
        $rawSubject = $campaign->subject ?: $template->subject;
        $subject    = CampaignMailable::renderMergeTags($rawSubject, $contact, $unsubscribeUrl);

        $mailable = new CampaignMailable(
            campaign:       $campaign,
            template:       $template,
            contact:        $contact,
            subjectLine:    $subject,
            trackingToken:  $trackingToken,
            unsubscribeUrl: $unsubscribeUrl,
        );

        Mail::to($contact->email)->send($mailable);

        // Generate a local provider message ID — unique per send.
        return 'local-' . Str::uuid()->toString();
    }

    /**
     * Return the driver identifier stored in campaign_runs.driver_ref.
     */
    public function driverName(): string
    {
        return 'local';
    }
}
