<?php

namespace App\Services\Zoho;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZohoCampaignsClient — thin HTTP wrapper over the Zoho Campaigns API v1.1.
 *
 * UNVERIFIED — This entire class has not been live-tinker-confirmed.
 * Zoho Campaigns OAuth is not yet provisioned (config('services.zoho.campaigns.refresh_token')
 * is blank). All endpoints, parameters, and response shapes are implemented against the
 * documented Zoho Campaigns API v1.1, but NO live STATUS 200 has been recorded.
 *
 * Per the fretiq empirical-verification rule (CLAUDE.md §1), before relying on ANY method
 * in production you MUST:
 *   1. Obtain Zoho Campaigns OAuth credentials and wire them into config/services.php.
 *   2. Run `php artisan tinker --execute "..."` against the real API.
 *   3. Record STATUS 200 + response shape in task/PR notes.
 *   4. Flip config('services.zoho.driver') from 'local' to 'zoho'.
 *
 * Driver is kept 'local' by default. This class is only instantiated when
 * config('services.zoho.driver') === 'zoho'.
 *
 * @see campaign-automation.md §7 (driver boundary)
 * @see compliance-deliverability.md §3 (hard deliverability gate)
 */
class ZohoCampaignsClient
{
    /** Base URL for the Zoho Campaigns API — overridable via config. */
    private string $apiUrl;

    /**
     * API-level `code` values from POST /json/listsubscribe that must be treated
     * as success.
     *
     * Live-verified (prod, 2026-07-21): subscribing a contact that is already a
     * list member (no prior topic association) and re-subscribing an
     * already-subscribed contact both return STATUS 200 with code "0" — Zoho does
     * not use a separate "already subscribed" code, and no double opt-in pending
     * state was observed. Code "0" is the only accepted value.
     */
    private const LISTSUBSCRIBE_ACCEPTED_CODES = ['0'];

    public function __construct(
        private readonly ZohoAuthService $authService,
    ) {
        $this->apiUrl = rtrim(
            config('services.zoho.campaigns.api_url', 'https://campaigns.zoho.com/api/v1.1'),
            '/'
        );
    }

    // ── Public API methods ─────────────────────────────────────────────────────

    /**
     * Add subscribers to a Zoho Campaigns mailing list.
     *
     * Zoho's topic ("rubrique") management only delivers a campaign to contacts
     * subscribed to that campaign's topic. Neither /addlistsubscribersinbulk nor
     * /addlistandcontacts accepts a topic_id param, so when a topic is configured
     * each contact must instead be individually subscribed via the ONLY v1.1
     * subscribe endpoint that does accept one: POST /json/listsubscribe (see
     * subscribeContactWithTopic()).
     *
     * Branch selection is driven by config('services.zoho.campaigns.topic_id'):
     *
     * - Topic configured (non-empty): loop subscribeContactWithTopic() once per
     *   (deduplicated) contact. Returns an aggregate summary, not Zoho's raw payload.
     * - No topic configured (empty): legacy bulk path, unchanged.
     *   Live-verified endpoint: POST /addlistsubscribersinbulk.
     *   Required params: resfmt=JSON, listkey, emailids (comma-separated emails; max 10).
     *
     * Zoho returns HTTP 200 even for API-level errors, so callers must inspect code/status.
     *
     * @param  string  $listKey   The Zoho Campaigns list key (e.g. from createList or a pre-existing key).
     * @param  array   $contacts  Array of contact arrays; each must have 'Contact Email'; may include
     *                            merge fields like 'First Name', 'Last Name', 'Company'.
     *                            Example: [['Contact Email' => 'foo@bar.com', 'First Name' => 'Jean'], ...]
     * @return array              Decoded JSON response from Zoho Campaigns (bulk branch), or an
     *                            aggregate ['code','status','subscribed'] summary (topic branch).
     *
     * @throws \InvalidArgumentException  If no contact carries a usable email.
     * @throws \RuntimeException  If the HTTP request fails or Zoho returns a non-2xx status.
     */
    public function addListSubscribers(string $listKey, array $contacts): array
    {
        $topicId = trim((string) config('services.zoho.campaigns.topic_id'));

        // Dedupe by lowercased email in both branches — this reuses the same
        // normalisation the legacy bulk path already applied to emailids.
        $uniqueContacts = collect($contacts)
            ->filter(fn (array $contact) => trim((string) ($contact['Contact Email'] ?? $contact['email'] ?? '')) !== '')
            ->unique(fn (array $contact) => mb_strtolower(trim((string) ($contact['Contact Email'] ?? $contact['email'] ?? ''))))
            ->values();

        if ($uniqueContacts->isEmpty()) {
            throw new \InvalidArgumentException('[ZohoCampaignsClient] Aucun email valide à ajouter à la liste Zoho.');
        }

        if ($topicId !== '') {
            foreach ($uniqueContacts as $contact) {
                $this->subscribeContactWithTopic($listKey, $contact, $topicId);
            }

            Log::info('[ZohoCampaignsClient] addListSubscribers', [
                'list_key' => $listKey,
                'count'    => $uniqueContacts->count(),
                'topic_id' => $topicId,
            ]);

            return ['code' => '0', 'status' => 'success', 'subscribed' => $uniqueContacts->count()];
        }

        $accessToken = $this->authService->getAccessToken('campaigns');

        // Zoho Campaigns bulk-subscribe expects emailids as a comma-separated
        // email list (max 10 per request). It does not accept merge-field JSON
        // on this endpoint; profile enrichment must be handled separately.
        $emailIds = $uniqueContacts
            ->map(fn (array $contact) => trim((string) ($contact['Contact Email'] ?? $contact['email'] ?? '')))
            ->implode(',');

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
        ])
            ->timeout(30)
            ->asForm()
            ->post($this->apiUrl . '/addlistsubscribersinbulk', [
                'resfmt'   => 'JSON',
                'listkey'  => $listKey,
                'emailids' => $emailIds,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                '[ZohoCampaignsClient] addListSubscribers échoué (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $payload = $response->json() ?? [];

        if (($payload['status'] ?? null) === 'error' || (string) ($payload['code'] ?? '0') !== '0') {
            throw new \RuntimeException(
                '[ZohoCampaignsClient] addListSubscribers erreur API Zoho : ' . $response->body()
            );
        }

        Log::info('[ZohoCampaignsClient] addListSubscribers', [
            'list_key' => $listKey,
            'count'    => count($contacts),
            'status'   => $response->status(),
        ]);

        return $payload;
    }

    /**
     * Subscribe a single contact to a mailing list under a specific topic ("rubrique").
     *
     * Live-verified (prod, 2026-07-21): POST /json/listsubscribe with resfmt=JSON,
     * listkey, topic_id, source, contactinfo → STATUS 200, code "0". Confirmed for
     * both a first-time subscribe and a re-subscribe of an already-subscribed
     * contact (same code both times — see LISTSUBSCRIBE_ACCEPTED_CODES). The
     * 'Company Name' contactinfo field is also live-verified: a follow-up
     * GET /getlistsubscribers returned firstname/companyname populated.
     *
     * Documented endpoint: POST /json/listsubscribe.
     * Required params: resfmt=JSON, listkey, contactinfo (JSON-encoded field=>value
     * map, 'Contact Email' mandatory), source, topic_id.
     *
     * @param  array<string, string>  $contact  Must contain 'Contact Email'; may contain
     *                                          'First Name' and 'Company'.
     *
     * @throws \RuntimeException  If the HTTP request fails, or Zoho returns an API-level
     *                            error code not in self::LISTSUBSCRIBE_ACCEPTED_CODES.
     */
    private function subscribeContactWithTopic(string $listKey, array $contact, string $topicId): array
    {
        $email = trim((string) ($contact['Contact Email'] ?? $contact['email'] ?? ''));
        if ($email === '') {
            throw new \InvalidArgumentException('[ZohoCampaignsClient] subscribeContactWithTopic nécessite un email de contact.');
        }

        $contactInfo = ['Contact Email' => $email];
        $firstName = trim((string) ($contact['First Name'] ?? ''));
        if ($firstName !== '') {
            $contactInfo['First Name'] = $firstName;
        }
        $company = trim((string) ($contact['Company'] ?? ''));
        if ($company !== '') {
            $contactInfo['Company Name'] = mb_substr($company, 0, 100);
        }

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $this->authService->getAccessToken('campaigns'),
        ])
            ->timeout(30)
            ->asForm()
            ->post($this->apiUrl . '/json/listsubscribe', [
                'resfmt'      => 'JSON',
                'listkey'     => $listKey,
                'topic_id'    => $topicId,
                'source'      => 'fretiq',
                'contactinfo' => json_encode($contactInfo),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                '[ZohoCampaignsClient] subscribeContactWithTopic échoué (HTTP ' . $response->status() . ') pour ' . $email . ' : ' . $response->body()
            );
        }

        $payload = $response->json() ?? [];
        $code = (string) ($payload['code'] ?? '0');

        if (! in_array($code, self::LISTSUBSCRIBE_ACCEPTED_CODES, true)) {
            throw new \RuntimeException(
                '[ZohoCampaignsClient] subscribeContactWithTopic erreur API Zoho pour ' . $email . ' : ' . $response->body()
            );
        }

        return $payload;
    }

    /**
     * Create a private campaign-owned list with up to ten approved seed contacts
     * and verify that its returned key is listed by Zoho before returning it.
     *
     * @param string[] $seedEmails
     */
    public function createRecipientList(string $listName, array $seedEmails): string
    {
        $emails = collect($seedEmails)
            ->map(fn (mixed $email) => trim((string) $email))
            ->filter()
            ->unique(fn (string $email) => mb_strtolower($email))
            ->take(10)
            ->values();

        if ($emails->isEmpty()) {
            throw new \InvalidArgumentException('La préparation de liste Zoho nécessite au moins un destinataire approuvé pour créer la liste dédiée.');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $this->authService->getAccessToken('campaigns'),
        ])->timeout(30)->asForm()->post($this->apiUrl . '/addlistandcontacts', [
            'resfmt' => 'JSON',
            'listname' => $listName,
            'signupform' => 'private',
            'mode' => 'newlist',
            'emailids' => $emails->implode(','),
        ]);

        $payload = $response->json() ?? [];

        // Idempotent recovery: Zoho reports the deterministic name already exists (2205) —
        // an earlier prep created the list but never persisted its key. Recover by name
        // instead of dead-locking. Same predicate as the throw in assertRecipientListSuccess,
        // so it fires only for that exact response; code 0/absent is untouched below.
        if (! $response->failed() && (string) ($payload['code'] ?? '') === '2205') {
            $existingKey = $this->findRecipientListKeyByName($listName);
            if ($existingKey !== '') {
                Log::info('[ZohoCampaignsClient] recovered existing list on 2205', [
                    'list_name' => $listName,
                    'list_key'  => $existingKey,
                ]);
                return $existingKey;
            }
            throw new \RuntimeException('[ZohoCampaignsClient] createRecipientList : liste « ' . $listName . ' » déjà présente sur Zoho mais introuvable dans getmailinglists — récupération impossible.');
        }

        $payload = $this->assertRecipientListSuccess($response, 'createRecipientList');
        $listKey = trim((string) ($payload['listkey'] ?? $payload['listKey'] ?? ''));
        if ($listKey === '') {
            throw new \RuntimeException('[ZohoCampaignsClient] createRecipientList erreur API Zoho : clé de liste absente.');
        }

        $verified = collect($this->fetchAllMailingLists())->contains(
            fn (mixed $list) => is_array($list) && trim((string) ($list['listkey'] ?? $list['listKey'] ?? '')) === $listKey
        );
        if (! $verified) {
            throw new \RuntimeException('[ZohoCampaignsClient] La liste Zoho créée ne peut pas être vérifiée avant persistance.');
        }

        return $listKey;
    }

    /** @return string[] Complete current membership, fetched page by page. */
    public function listRecipientEmails(string $listKey): array
    {
        $emails = [];
        $fromIndex = 1;
        $range = 200;

        do {
            $response = Http::withHeaders([
                'Authorization' => 'Zoho-oauthtoken ' . $this->authService->getAccessToken('campaigns'),
            ])->timeout(30)->get($this->apiUrl . '/getlistsubscribers', [
                'resfmt' => 'JSON',
                'listkey' => $listKey,
                'fromindex' => $fromIndex,
                'range' => $range,
            ]);
            $payload = $this->assertRecipientListSuccess($response, 'listRecipientEmails');
            $details = $payload['list_of_details'] ?? [];
            if (! is_array($details)) {
                $details = [];
            }

            foreach ($details as $detail) {
                $email = trim((string) (is_array($detail) ? ($detail['contact_email'] ?? $detail['Contact Email'] ?? '') : ''));
                if ($email !== '') {
                    $emails[mb_strtolower($email)] = $email;
                }
            }
            $fromIndex += count($details);
        } while (count($details) === $range);

        return array_values($emails);
    }

    /** @param array<int, array<string, string>> $contacts */
    public function addRecipientContacts(string $listKey, array $contacts): void
    {
        $this->addListSubscribers($listKey, $contacts);
    }

    /** @return string[] */
    public function listSubscribers(string $listKey): array
    {
        return $this->listRecipientEmails($listKey);
    }

    /** @return array<int, array<string,mixed>> Every mailing list, page by page. */
    private function fetchAllMailingLists(): array
    {
        $all = [];
        $fromIndex = 1;
        $range = 50;      // Zoho getmailinglists page size; confirmed-safe default
        $pages = 0;

        do {
            $response = Http::withHeaders([
                'Authorization' => 'Zoho-oauthtoken ' . $this->authService->getAccessToken('campaigns'),
            ])->timeout(30)->get($this->apiUrl . '/getmailinglists', [
                'resfmt'    => 'JSON',
                'sort'      => 'asc',
                'fromindex' => $fromIndex,
                'range'     => $range,
            ]);
            $payload = $this->assertRecipientListSuccess($response, 'getMailingLists');
            $lists = $payload['list_of_details'] ?? [];
            if (! is_array($lists)) { $lists = []; }
            foreach ($lists as $l) { if (is_array($l)) { $all[] = $l; } }
            $fromIndex += count($lists);
        } while (count($lists) === $range && ++$pages < 200); // ponytail: 200-page cap guards an unverified/ignored range param from looping

        return $all;
    }

    /**
     * Resolve an existing recipient list's key by exact (normalized) name.
     * Returns '' when there is no unambiguous single match, so the caller refuses
     * rather than recover the wrong list (add-only sync must never top up a wrong list).
     */
    private function findRecipientListKeyByName(string $listName): string
    {
        $target = mb_strtolower(trim($listName));
        if ($target === '') { return ''; }

        $matches = [];
        foreach ($this->fetchAllMailingLists() as $list) {
            if (mb_strtolower(trim((string) ($list['listname'] ?? ''))) === $target) {
                $key = trim((string) ($list['listkey'] ?? $list['listKey'] ?? ''));
                if ($key !== '') { $matches[$key] = true; }
            }
        }

        return count($matches) === 1 ? array_key_first($matches) : '';
    }

    private function assertRecipientListSuccess(\Illuminate\Http\Client\Response $response, string $operation): array
    {
        if ($response->failed()) {
            throw new \RuntimeException('[ZohoCampaignsClient] ' . $operation . ' échoué (HTTP ' . $response->status() . ') : ' . $response->body());
        }

        $payload = $response->json() ?? [];
        if (($payload['status'] ?? null) === 'error' || (string) ($payload['code'] ?? '0') !== '0') {
            throw new \RuntimeException('[ZohoCampaignsClient] ' . $operation . ' erreur API Zoho : ' . $response->body());
        }

        return $payload;
    }

    /**
     * Create a new campaign in Zoho Campaigns.
     *
     * Live-verified 2026-07-27: from_name accepted with STATUS 200 and the
     * campaign was created as a draft without calling sendCampaign.
     *
     * Documented endpoint: POST /createCampaign
     * Returns a campaign key in the response payload (field: 'campaignKey').
     *
     * @param  string  $name        Internal campaign name visible in Zoho UI.
     * @param  string  $subject     Email subject line.
     * @param  string  $fromEmail   Sender email address (must be verified in Zoho Campaigns).
     * @param  string  $fromName    Sender display name.
     * @param  string  $listKey     Target mailing list key.
     * @param  string  $contentUrl  Public URL where Zoho can import the campaign HTML.
     * @return array               Decoded JSON response; typically contains 'campaignkey'.
     *
     * @throws \RuntimeException  If the HTTP request fails or Zoho returns a non-2xx status.
     */
    public function createCampaign(
        string $name,
        string $subject,
        string $fromEmail,
        string $fromName,
        string $listKey,
        string $contentUrl,
    ): array {
        $accessToken = $this->authService->getAccessToken('campaigns');

        $payload = [
            'resfmt'       => 'JSON',
            'campaignname' => $name,
            'subject'      => $subject,
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'list_details' => json_encode([$listKey => []]),
            'content_url'  => $contentUrl,
        ];

        if ($topicId = config('services.zoho.campaigns.topic_id')) {
            $payload['topicId'] = $topicId;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
        ])
            ->timeout(30)
            ->asForm()
            ->post($this->apiUrl . '/createCampaign', $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                '[ZohoCampaignsClient] createCampaign échoué (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $payload = $response->json() ?? [];

        Log::info('[ZohoCampaignsClient] createCampaign', [
            'name'        => $name,
            'list_key'    => $listKey,
            'status'      => $response->status(),
            'campaign_key' => $payload['campaignKey'] ?? $payload['campaignkey'] ?? 'inconnu',
        ]);

        return $payload;
    }

    /**
     * Send (schedule for immediate delivery) an existing Zoho campaign.
     *
     * UNVERIFIED — endpoint/params not live-tinker-confirmed (Zoho Campaigns OAuth
     * not provisioned). Per the empirical-verification rule, run a live tinker
     * POST + record STATUS 200 before relying on this in production.
     *
     * Documented endpoint: POST /sendcampaign
     * The campaign must already have been created via createCampaign().
     *
     * @param  string  $campaignKey  The campaign key returned by createCampaign().
     * @return array                 Decoded JSON response from Zoho.
     *
     * @throws \RuntimeException  If the HTTP request fails or Zoho returns a non-2xx status.
     */
    public function sendCampaign(string $campaignKey): array
    {
        $accessToken = $this->authService->getAccessToken('campaigns');

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
        ])
            ->timeout(30)
            ->asForm()
            ->post($this->apiUrl . '/sendcampaign', [
                'resfmt'      => 'JSON',
                'campaignkey' => $campaignKey,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                '[ZohoCampaignsClient] sendCampaign échoué (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $payload = $response->json() ?? [];

        Log::info('[ZohoCampaignsClient] sendCampaign', [
            'campaign_key' => $campaignKey,
            'status'       => $response->status(),
        ]);

        return $payload;
    }

    /**
     * Retrieve the send report / statistics for a Zoho campaign.
     *
     * UNVERIFIED — endpoint/params not live-tinker-confirmed (Zoho Campaigns OAuth
     * not provisioned). Per the empirical-verification rule, run a live tinker
     * GET + record STATUS 200 before relying on this in production.
     *
     * Documented endpoint: GET /campaignreports
     * Returns stats including sent, opened, clicked, bounced counts.
     *
     * @param  string  $campaignKey  The campaign key to fetch stats for.
     * @return array                 Decoded JSON response; typically contains 'sent_count',
     *                               'opened_count', 'clicked_count', 'bounced_count'.
     *
     * @throws \RuntimeException  If the HTTP request fails or Zoho returns a non-2xx status.
     */
    public function getCampaignReport(string $campaignKey): array
    {
        $accessToken = $this->authService->getAccessToken('campaigns');

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
        ])
            ->timeout(20)
            ->get($this->apiUrl . '/campaignreports', [
                'resfmt'      => 'JSON',
                'campaignkey' => $campaignKey,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                '[ZohoCampaignsClient] getCampaignReport échoué (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        $payload = $response->json() ?? [];

        Log::debug('[ZohoCampaignsClient] getCampaignReport', [
            'campaign_key' => $campaignKey,
            'status'       => $response->status(),
        ]);

        return $payload;
    }
}
