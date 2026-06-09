<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Zoho\ZohoCampaignsClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ZohoCampaignsDriver — CampaignsClient implementation backed by Zoho Campaigns API.
 *
 * UNVERIFIED — This driver has not been live-tinker-confirmed.
 * Zoho Campaigns OAuth is not yet provisioned. This driver is built against the
 * documented Zoho Campaigns API v1.1, but NO live STATUS 200 has been recorded for
 * any of its API calls.
 *
 * Per the fretiq empirical-verification rule (CLAUDE.md §1):
 *   - Obtain Zoho Campaigns OAuth + wire config('services.zoho.campaigns.*').
 *   - Live-tinker each endpoint (addListSubscribers, createCampaign, sendCampaign).
 *   - Record STATUS 200 in task/PR notes.
 *   - Only then flip config('services.zoho.driver') to 'zoho'.
 *
 * Architecture note: Zoho Campaigns is LIST/CAMPAIGN-based, not per-recipient SMTP.
 * The driver contract's `send()` method is NOT how Zoho sends — Zoho dispatches
 * to the entire list at once. Use `dispatchRun()` for Zoho-backed sends.
 *
 * @see campaign-automation.md §7 (driver boundary)
 * @see compliance-deliverability.md §3 (hard deliverability gate)
 */
class ZohoCampaignsDriver implements CampaignsClient
{
    public function __construct(
        private readonly ZohoCampaignsClient $zohoClient,
    ) {}

    // ── CampaignsClient contract ───────────────────────────────────────────────

    /**
     * Return the driver identifier stored in campaign_runs.driver_ref.
     */
    public function driverName(): string
    {
        return 'zoho';
    }

    /**
     * NOT VALID for the Zoho driver — Zoho is list/campaign-based, not per-recipient.
     *
     * Zoho Campaigns dispatches to an entire mailing list at once via the API;
     * it does not support single-recipient HTTP-triggered sends. Calling this method
     * indicates the caller is using the wrong path for the Zoho driver.
     *
     * Use `dispatchRun()` instead to:
     *   1. Build a Zoho mailing list from the run's eligible contacts.
     *   2. Create a campaign targeting that list.
     *   3. Trigger the send via Zoho's API.
     *
     * @throws \LogicException  Always — this method must never be called for the Zoho driver.
     */
    public function send(
        CampaignRecipient $recipient,
        Campaign $campaign,
        CampaignRun $run,
        string $trackingToken,
        string $unsubscribeUrl,
    ): string {
        throw new \LogicException(
            'ZohoCampaignsDriver envoie au niveau run, pas par destinataire individuel. '
            . 'Utilisez dispatchRun() à la place de send().'
        );
    }

    // ── Zoho-specific run-level dispatch ───────────────────────────────────────

    /**
     * Dispatch an entire campaign run via Zoho Campaigns.
     *
     * UNVERIFIED — API calls within are not live-tinker-confirmed (Zoho Campaigns OAuth
     * not provisioned). Per the empirical-verification rule, run a live tinker
     * POST + record STATUS 200 before relying on this in production.
     *
     * Flow:
     *   1. Build a list key scoped to this run (fretiq-run-{run_id}).
     *   2. Subscribe all eligible $contacts to that Zoho list.
     *   3. Create a Zoho campaign with the run's template content.
     *   4. Trigger immediate send via Zoho's sendcampaign endpoint.
     *   5. Persist zoho_list_key + zoho_campaign_key on the run.
     *
     * A missing-OAuth RuntimeException (from ZohoAuthService) surfaces clearly here —
     * it is the correct gated behavior when Campaigns OAuth is not provisioned.
     *
     * @param  CampaignRun  $run       The run to dispatch. Must have campaign.template + campaign.senderIdentity loaded.
     * @param  Collection   $contacts  Eligible Contact models (compliance-filtered by SegmentService).
     * @return array                   Summary: ['list_key', 'campaign_key', 'contacts_subscribed', 'status'].
     *
     * @throws \RuntimeException  On OAuth failure (campaigns refresh_token missing) or HTTP failure.
     */
    public function dispatchRun(CampaignRun $run, Collection $contacts): array
    {
        $campaign = $run->campaign;
        $template = $campaign->template ?? $campaign->load('template')->template;
        $sender   = $campaign->senderIdentity ?? $campaign->load('senderIdentity')->senderIdentity;

        // ── 1. Build a deterministic list key for this run ─────────────────────
        $listKey = 'fretiq-run-' . $run->id;

        // ── 2. Subscribe eligible contacts to the Zoho list ───────────────────
        $contactPayload = $contacts->map(function ($contact) {
            return [
                'Contact Email' => $contact->email,
                'First Name'    => $contact->first_name ?? '',
                'Last Name'     => $contact->last_name ?? '',
                'Company'       => optional($contact->company)->name ?? '',
            ];
        })->values()->toArray();

        Log::info('[ZohoCampaignsDriver] Ajout des abonnés à la liste Zoho.', [
            'run_id'   => $run->id,
            'list_key' => $listKey,
            'count'    => count($contactPayload),
        ]);

        $this->zohoClient->addListSubscribers($listKey, $contactPayload);

        // ── 3. Create the campaign in Zoho ─────────────────────────────────────
        $subject   = $campaign->subject ?: $template->subject;
        $fromEmail = $sender?->email ?? config('mail.from.address', 'noreply@fretiq.fr');

        Log::info('[ZohoCampaignsDriver] Création de la campagne Zoho.', [
            'run_id'    => $run->id,
            'subject'   => $subject,
            'from'      => $fromEmail,
            'list_key'  => $listKey,
        ]);

        $createResponse = $this->zohoClient->createCampaign(
            name:        'fretiq-' . $run->id . '-' . now()->format('Ymd'),
            subject:     $subject,
            fromEmail:   $fromEmail,
            listKey:     $listKey,
            htmlContent: $template->html_content,
        );

        // Extract the campaign key from Zoho's response.
        // UNVERIFIED — field name 'campaignkey' is per documentation; may differ in live response.
        $campaignKey = $createResponse['campaignkey']
            ?? $createResponse['data']['campaignkey']
            ?? null;

        if (! $campaignKey) {
            throw new \RuntimeException(
                '[ZohoCampaignsDriver] Zoho n\'a pas retourné de campaignkey. Réponse : ' . json_encode($createResponse)
            );
        }

        // ── 4. Trigger the send ────────────────────────────────────────────────
        Log::info('[ZohoCampaignsDriver] Déclenchement de l\'envoi Zoho.', [
            'run_id'       => $run->id,
            'campaign_key' => $campaignKey,
        ]);

        $this->zohoClient->sendCampaign($campaignKey);

        // ── 5. Persist Zoho keys on the run ───────────────────────────────────
        $run->update([
            'zoho_list_key'     => $listKey,
            'zoho_campaign_key' => $campaignKey,
            'driver_ref'        => $this->driverName(),
        ]);

        Log::info('[ZohoCampaignsDriver] Run dispatché via Zoho Campaigns.', [
            'run_id'       => $run->id,
            'list_key'     => $listKey,
            'campaign_key' => $campaignKey,
            'contacts'     => count($contactPayload),
        ]);

        return [
            'list_key'            => $listKey,
            'campaign_key'        => $campaignKey,
            'contacts_subscribed' => count($contactPayload),
            'status'              => 'dispatched',
        ];
    }
}
