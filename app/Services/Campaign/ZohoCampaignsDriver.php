<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Zoho\ZohoCampaignsClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

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
    /**
     * Map of local template placeholders → Zoho Campaigns predefined merge tags.
     *
     * Tags are doc-sourced (Zoho Campaigns predefined merge tags).
     * The unsubscribe tag $[LI:UNSUBSCRIBE]$ is intended for use inside an href attribute.
     *
     * IMPORTANT — $[COMPANY]$ is UNVERIFIED: the exact company-field merge tag must be
     * confirmed against the live merge-tag list during Phase 5 tinker verification
     * (project hard rule: Zoho behavior requires empirical STATUS 200 verification —
     * docs alone are insufficient). The subscriber field 'Company' is populated at
     * addListSubscribers time; the merge tag name must match whatever Zoho exposes.
     * Correct the map if the live merge-tag list differs.
     *
     * NOT MAPPED — {{company.sector}} has no known Zoho Campaigns equivalent and is
     * therefore absent from this map: it passes through to Zoho unchanged (i.e. the
     * literal `{{company.sector}}` reaches the recipient). UNVERIFIED against the live
     * Zoho merge-tag list — if Zoho exposes a sector/industry subscriber field, add the
     * mapping here during Phase 5 tinker verification.
     */
    private const MERGE_TAG_MAP = [
        '{{contact.name}}'       => '$[FNAME]$',
        '{{contact.first_name}}' => '$[FNAME]$',
        '{{contact.email}}'      => '$[EMAIL]$',
        '{{company.name}}'       => '$[COMPANY]$',   // UNVERIFIED — confirm tag name live
        '{{unsubscribe_url}}'    => '$[LI:UNSUBSCRIBE]$',
    ];

    /**
     * Translate local template placeholders to Zoho Campaigns merge tags.
     *
     * Performs a simple str_replace of all known placeholders. Unknown/other
     * tags (e.g. {{contact.phone}}) are passed through untouched.
     *
     * @param  string  $text  Subject line or HTML content containing local placeholders.
     * @return string         Text with local placeholders replaced by Zoho merge tags.
     */
    public static function translateMergeTags(string $text): string
    {
        return str_replace(
            array_keys(self::MERGE_TAG_MAP),
            array_values(self::MERGE_TAG_MAP),
            $text
        );
    }

    /**
     * Prepare campaign HTML for Zoho while keeping unsubscribe ownership in the driver.
     *
     * Legacy templates keep the placement of their {{unsubscribe_url}} link. Clean
     * templates receive one visible footer containing Zoho's unsubscribe merge tag.
     */
    public static function prepareHtmlContent(string $html): string
    {
        $html = self::translateMergeTags($html);
        [$html, $hasUnsubscribeLink] = UnsubscribeHtmlNormalizer::normalize(
            $html,
            '$[LI:UNSUBSCRIBE]$',
        );

        if ($hasUnsubscribeLink) {
            return $html;
        }

        $footer = '<div style="margin-top:24px;font-size:11px;color:#888;font-family:sans-serif;">'
            . 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels. '
            . '<a href="$[LI:UNSUBSCRIBE]$" style="color:#888;">Se désabonner</a>'
            . '</div>';

        return UnsubscribeHtmlNormalizer::insertBeforeDocumentEnd($html, $footer);
    }
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

        // ── 1. Resolve the Zoho mailing list key ───────────────────────────────
        // Zoho Campaigns expects an existing list key; arbitrary per-run keys are
        // not auto-created by the bulk-subscriber endpoint.
        $listKey = trim((string) ($campaign->zoho_list_key ?: config('services.zoho.campaigns.list_key')));
        if ($listKey === '') {
            throw new \RuntimeException('Préparation Zoho incomplète : aucune liste Zoho vérifiée n’est associée à cette campagne.');
        }

        // ── 2. Subscribe eligible contacts to the Zoho list ───────────────────
        $contactPayload = $contacts->map(function ($contact) {
            // Contact has a single `name` column (no first_name / last_name split).
            // Full name goes into 'First Name' so $[FNAME]$ renders it.
            return [
                'Contact Email' => $contact->email,
                'First Name'    => $contact->name ?? '',
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
        // Translate local placeholders ({{contact.name}} etc.) to Zoho merge tags
        // before submitting to the API — recipients would otherwise see literal braces.
        $subject    = self::translateMergeTags($campaign->subject ?: $template->subject);
        $contentUrl = URL::temporarySignedRoute('campaigns.zoho-content', now()->addDays(7), ['run' => $run->id]);
        // The campaign's own selected sender identity is used first — each campaign
        // sends from the sender the user picked at creation time. ZOHO_DEFAULT_FROM_EMAIL
        // is only a fallback for campaigns without a sender identity, then Laravel's
        // mail.from address. Whichever address resolves MUST be a verified sender in
        // Zoho Campaigns (Settings → Sender addresses) — Zoho returns code 6610
        // "Email is not verified" otherwise.
        $fromEmail = ($sender?->email)
            ?: config('services.zoho.default_from_email')
            ?: config('mail.from.address', 'noreply@fretiq.fr');

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
            contentUrl:  $contentUrl,
        );

        // Extract the campaign key from Zoho's response.
        // UNVERIFIED — field name 'campaignkey' is per documentation; may differ in live response.
        $campaignKey = $createResponse['campaignKey']
            ?? $createResponse['campaignkey']
            ?? $createResponse['data']['campaignkey']
            ?? $createResponse['data']['campaignKey']
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
