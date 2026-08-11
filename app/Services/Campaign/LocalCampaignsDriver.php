<?php

namespace App\Services\Campaign;

use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use Illuminate\Support\Facades\Mail;

/**
 * LocalCampaignsDriver — development/test implementation of CampaignsClient.
 *
 * Sends via Laravel Mail, which in dev routes to Mailpit and in tests respects
 * Mail::fake(). Never uses Mail::raw() — a real CampaignMailable is always used.
 *
 * Retry identity is stable per CampaignRecipient: both Message-ID and the local
 * provider reference derive from its database ID. SMTP/provider deduplication is
 * still best-effort; exact-once delivery is impossible without provider-side
 * reconciliation after a process dies between acceptance and local persistence.
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

        // Resolve the language-appropriate body + subject for this contact's country.
        // resolveFor() is N+1-safe when template.translations is already eager-loaded
        // (CampaignService::sendRun loads it); on the driver path without that preload
        // it does one extra query per recipient, which is acceptable.
        $resolved = $template->resolveFor($contact->company?->country);

        // The subject comes from the campaign (may override the template default).
        // Merge tags are substituted per-recipient so each contact sees their own name.
        $rawSubject = $campaign->subject ?: $resolved['subject'];
        $subject    = CampaignMailable::renderMergeTags($rawSubject, $contact, $unsubscribeUrl);
        $messageId  = 'campaign-recipient-' . $recipient->id . '@fretiq.local';

        $mailable = new CampaignMailable(
            campaign:       $campaign,
            template:       $template,
            contact:        $contact,
            subjectLine:    $subject,
            trackingToken:  $trackingToken,
            unsubscribeUrl: $unsubscribeUrl,
            resolvedHtml:   $resolved['html_content'],
            language:       $resolved['language'],
            messageId:      $messageId,
        );

        Mail::to($contact->email)->send($mailable);

        // Stable retry identity. Provider dedupe remains best-effort.
        return 'local-recipient-' . $recipient->id;
    }

    /**
     * Return the driver identifier stored in campaign_runs.driver_ref.
     */
    public function driverName(): string
    {
        return 'local';
    }

    public function supportsBounceFeedback(Campaign $campaign): bool
    {
        return false;
    }
}
