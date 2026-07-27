<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Campaign\CampaignWaveZohoListSyncService;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use App\Services\Zoho\ZohoCampaignsClient;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
     * VERIFIED — 2026-07-21, live `GET {api_url}/contact/allfields?type=json` → STATUS 200.
     * The company field is DISPLAY_NAME "Company Name", FIELD_DISPLAY_NAME "COMPANYNAME",
     * FIELD_NAME "companyname". The merge tag itself, `$[COMPANYNAME]$`, was confirmed
     * directly from Zoho's own merge-tag picker in the campaign editor (not doc-sourced).
     * A prior version of this map used `$[COMPANY]$`, which is not a real Zoho Campaigns
     * merge tag — Zoho emitted it back to recipients literally instead of substituting the
     * company name. The company tag must use that exact form: Zoho delivers the
     * pipe-separated fallback form literally. The first-name tag keeps Zoho's documented
     * fallback form `$[TAG|value_for_email|value_for_social]$`.
     *
     * NOT MAPPED — {{company.sector}} has no known Zoho Campaigns equivalent and is
     * therefore absent from this map: it passes through to Zoho unchanged (i.e. the
     * literal `{{company.sector}}` reaches the recipient). UNVERIFIED against the live
     * Zoho merge-tag list — if Zoho exposes a sector/industry subscriber field, add the
     * mapping here during Phase 5 tinker verification.
     */
    private const MERGE_TAG_MAP = [
        '{{contact.name}}'       => '$[FNAME|client|client]$',
        '{{contact.first_name}}' => '$[FNAME|client|client]$',
        '{{contact.email}}'      => '$[EMAIL]$',
        '{{company.name}}'       => '$[COMPANYNAME]$',
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
     * Legacy templates keep the placement of their {{unsubscribe_url}} link. Templates
     * authored via the builder carry no unsubscribe section at all (Zoho is expected to
     * manage unsubscribe) — for those, this method appends one driver-owned visible
     * footer containing Zoho's unsubscribe merge tag, GATED behind
     * `config('services.zoho.append_unsubscribe_fallback')` (env `ZOHO_APPEND_UNSUBSCRIBE_FALLBACK`,
     * default true).
     *
     * UNVERIFIED — "Zoho appends its own managed unsubscribe footer on API/content-URL
     * campaigns" is doctrine, not a confirmed Zoho behavior. Per CLAUDE.md §1 (empirical-
     * verification rule), this flag may ONLY be flipped to false after:
     *   1. Sending a live Zoho test campaign via the content-URL flow used by dispatchRun().
     *   2. Confirming empirically that Zoho injects its own unsubscribe footer/link.
     *   3. Recording `STATUS: 200` + the exact received footer markup in the task/PR notes.
     * Until then this defaults to true — client campaigns (not just prospects) send via
     * Zoho when cold-send is disabled (SegmentService::applyColdGateStage only excludes
     * relationship=prospect), so an opt-out link is a compliance requirement (LCEN/CNIL),
     * not merely a style choice.
     *
     * The normalizer-FAILURE rescue is NOT gated by this flag: when
     * UnsubscribeHtmlNormalizer::normalize() hits its PCRE failure branch (backtrack/JIT
     * stack limit, malformed markup) it cannot guarantee the original link survived, so
     * this method still appends its own footer unconditionally in that case — it is the
     * last line of defense against a zero-opt-out send. A clean normalize() result that
     * simply found no unsubscribe link (the common builder-authored case) is what the
     * flag actually gates. This distinction is read directly off normalize()'s explicit
     * third return element (`$failed`) — never inferred from global PCRE error state,
     * which subsequent successful preg_* calls (including later matches within the same
     * normalize() call) can silently reset.
     */
    public static function prepareHtmlContent(string $html): string
    {
        $html = str_replace(
            array_keys(SectionCatalog::LEGACY_IMAGE_URL_MAP),
            array_values(SectionCatalog::LEGACY_IMAGE_URL_MAP),
            $html,
        );
        $html = self::translateMergeTags($html);
        [$html, $hasUnsubscribeLink, $normalizationFailed] = UnsubscribeHtmlNormalizer::normalize(
            $html,
            '$[LI:UNSUBSCRIBE]$',
        );

        if ($hasUnsubscribeLink) {
            return $html;
        }

        if (! $normalizationFailed && ! self::shouldAppendUnsubscribeFallback()) {
            return $html;
        }

        $footer = '<div style="margin-top:24px;font-size:11px;color:#888;font-family:sans-serif;">'
            . 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels. '
            . '<a href="$[LI:UNSUBSCRIBE]$" style="color:#888;">Se désabonner</a>'
            . '</div>';

        return UnsubscribeHtmlNormalizer::insertBeforeDocumentEnd($html, $footer);
    }

    /**
     * Read the append_unsubscribe_fallback safety gate, defaulting to true when the
     * Laravel config repository isn't bound (pure-unit test context with no framework
     * bootstrap). Never throws — this must be safe to call from any context.
     */
    private static function shouldAppendUnsubscribeFallback(): bool
    {
        if (! Container::getInstance()->bound('config')) {
            return true;
        }

        return (bool) config('services.zoho.append_unsubscribe_fallback', true);
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
        $run->loadMissing('sequenceStep.template');
        $template = $run->sequenceStep?->template ?? $campaign->template ?? $campaign->load('template')->template;
        $sender   = $campaign->senderIdentity ?? $campaign->load('senderIdentity')->senderIdentity;

        // ── 0. Refuse a zero-opt-out send ──────────────────────────────────────
        // Fail-closed preflight: compose the exact HTML the signed zoho-content
        // route (routes/web.php) will later serve to Zoho and refuse the ENTIRE
        // dispatch — before any Zoho API call, so no subscriber/campaign is ever
        // created for a send that would carry no unsubscribe link — when that
        // composed HTML carries no $[LI:UNSUBSCRIBE]$ tag. This happens exactly
        // when append_unsubscribe_fallback is disabled AND the template supplies
        // no unsubscribe link of its own (builder-authored templates never do —
        // see prepareHtmlContent()'s docblock). Checking the ACTUAL composed
        // output (rather than re-deriving the flag + normalizer-failure logic
        // here) keeps this guard from silently drifting out of sync with
        // prepareHtmlContent() if that logic ever changes.
        $preparedHtml = self::prepareHtmlContent((string) $template->html_content);
        if (! str_contains($preparedHtml, '$[LI:UNSUBSCRIBE]$')) {
            throw new \RuntimeException(
                'Envoi Zoho refusé : le contenu composé ne contient aucun lien de désabonnement '
                . '($[LI:UNSUBSCRIBE]$). Activez services.zoho.append_unsubscribe_fallback '
                . '(ZOHO_APPEND_UNSUBSCRIBE_FALLBACK=true) ou ajoutez un lien de désabonnement au modèle avant de réessayer.'
            );
        }

        // ── 1. Resolve the Zoho mailing list key ───────────────────────────────
        // Zoho Campaigns expects an existing list key; arbitrary per-run keys are
        // not auto-created by the bulk-subscriber endpoint.
        $listKey = trim((string) ($run->zoho_list_key ?: $campaign->zoho_list_key ?: config('services.zoho.campaigns.list_key')));
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

        if ($run->sequence_step_id === null) {
            $this->zohoClient->addListSubscribers($listKey, $contactPayload);
        }

        // ── 3. Create the campaign in Zoho ─────────────────────────────────────
        // Translate local placeholders ({{contact.name}} etc.) to Zoho merge tags
        // before submitting to the API — recipients would otherwise see literal braces.
        $subject = self::translateMergeTags($run->sequenceStep?->subject ?: $campaign->subject ?: $template->subject);
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
        $fromName = $sender?->name ?: config('mail.from.name', 'Fretiq');

        Log::info('[ZohoCampaignsDriver] Création de la campagne Zoho.', [
            'run_id'    => $run->id,
            'subject'   => $subject,
            'from'      => $fromEmail,
            'list_key'  => $listKey,
        ]);

        $campaignKey = trim((string) $run->zoho_campaign_key);
        $hadCampaignKey = $campaignKey !== '';
        if (! $hadCampaignKey) {
            $nameSuffix = " - C{$campaign->id} - R{$run->id} - " . now()->format('Ymd');
            // Zoho createCampaign rejects some special characters (`&` confirmed) with
            // code 7006. Sanitize before Str::limit so the 191-char budget still holds.
            $safeName = CampaignWaveZohoListSyncService::sanitizeCampaignName($campaign->name, $campaign->id);
            $name = 'Fretiq ' . Str::limit($safeName, 191 - mb_strlen('Fretiq ' . $nameSuffix), '') . $nameSuffix;
            $createResponse = $this->zohoClient->createCampaign(
                name:        $name,
                subject:     $subject,
                fromEmail:   $fromEmail,
                fromName:    $fromName,
                listKey:     $listKey,
                contentUrl:  $contentUrl,
            );

            $campaignKey = $createResponse['campaignKey']
                ?? $createResponse['campaignkey']
                ?? $createResponse['data']['campaignkey']
                ?? $createResponse['data']['campaignKey']
                ?? null;

            if (! $campaignKey) {
                throw new \RuntimeException(
                    '[ZohoCampaignsDriver] Zoho did not return a campaignkey. Response: ' . json_encode($createResponse)
                );
            }
            $run->update(['zoho_campaign_key' => $campaignKey]);
        }

        if ($hadCampaignKey
            && $run->driver_ref === 'zoho-send-uncertain') {
            throw new \RuntimeException('Envoi Zoho incertain : reconciliation manuelle requise.');
        }

        if ($hadCampaignKey
            && $run->driver_ref === 'zoho-send-attempted') {
            return [
                'list_key' => $listKey,
                'campaign_key' => $campaignKey,
                'contacts_subscribed' => count($contactPayload),
                'status' => 'send_already_attempted',
            ];
        }

        // ponytail: at-most-once marker may miss a send if the worker dies before the provider request;
        // replace with provider-status reconciliation once that endpoint is empirically verified.
        $run->update(['driver_ref' => 'zoho-send-attempted']);

        Log::info('[ZohoCampaignsDriver] Déclenchement de l\'envoi Zoho.', [
            'run_id'       => $run->id,
            'campaign_key' => $campaignKey,
        ]);

        try {
            $this->zohoClient->sendCampaign($campaignKey);
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            $definiteRejection = str_contains($message, 'sendCampaign')
                && (str_contains($message, '(HTTP') || str_contains($message, 'erreur API'));
            $run->update(['driver_ref' => $definiteRejection ? 'zoho-send-failed' : 'zoho-send-uncertain']);
            throw $exception;
        }

        // ── 5. Persist Zoho keys on the run ───────────────────────────────────
        $run->update([
            'zoho_list_key'     => $listKey,
            'zoho_campaign_key' => $campaignKey,
            'driver_ref'        => $run->sequence_step_id !== null
                ? 'zoho-send-attempted'
                : $this->driverName(),
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
