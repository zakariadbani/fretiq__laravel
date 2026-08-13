<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;

/**
 * CampaignsClient — driver contract for campaign email delivery.
 *
 * Implementations:
 *   LocalCampaignsDriver  — sends through the application SMTP router.
 *   ZohoCampaignsDriver   — Phase 5; pushes list + campaign to Zoho Campaigns API.
 *
 * The driver boundary: fretiq owns scheduling, compliance filtering, recipient rows,
 * and analytics. The driver only executes a single email send and returns a provider
 * message ID for correlation.
 *
 * @see campaign-automation.md §7
 */
interface CampaignsClient
{
    /**
     * Send one email to a single campaign recipient.
     *
     * The driver MUST:
     *   - Use a real Mailable (never Mail::raw).
     *   - Embed the tracking pixel for the given token.
     *   - Add List-Unsubscribe headers.
     *   - Return a non-empty provider_message_id string.
     *
     * Residual idempotency note: if the process dies after the provider accepts the
     * send but before the caller records provider_message_id, the next retry will
     * re-send this recipient (provider_message_id IS NULL + status='queued'). This
     * is accepted as a rare-single duplicate. Real drivers should pass a provider
     * idempotency key (e.g. the recipient's DB id) when the provider supports it.
     *
     * @param  CampaignRecipient  $recipient      The recipient row (includes contact relation).
     * @param  Campaign           $campaign       The parent campaign (includes senderIdentity relation).
     * @param  CampaignRun        $run            The run context.
     * @param  string             $trackingToken  64-char hex token for the open-pixel.
     * @param  string             $unsubscribeUrl Signed unsubscribe URL to embed in the email.
     * @return string                             Provider message ID (non-empty).
     *
     * @throws \RuntimeException  On delivery failure. Caller catches and marks recipient for retry.
     */
    public function send(
        CampaignRecipient $recipient,
        Campaign $campaign,
        CampaignRun $run,
        string $trackingToken,
        string $unsubscribeUrl,
    ): string;

    /**
     * Return the driver name identifier (e.g. 'local', 'zoho').
     * Stored on campaign_runs.driver_ref for provenance.
     */
    public function driverName(): string;

    /**
     * Whether this concrete delivery path has a configured, recently proven
     * bounce-feedback loop for the campaign's sender.
     */
    public function supportsBounceFeedback(Campaign $campaign): bool;
}
