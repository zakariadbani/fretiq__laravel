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
     * Add subscribers to a Zoho Campaigns mailing list in bulk.
     *
     * UNVERIFIED — endpoint/params not live-tinker-confirmed (Zoho Campaigns OAuth
     * not provisioned). Per the empirical-verification rule, run a live tinker
     * POST + record STATUS 200 before relying on this in production.
     *
     * Documented endpoint: POST /json/listsubscriberinbulk
     * Required params: resfmt=JSON, listkey, emailids (JSON-encoded array of email+merge fields).
     *
     * @param  string  $listKey   The Zoho Campaigns list key (e.g. from createList or a pre-existing key).
     * @param  array   $contacts  Array of contact arrays; each must have 'Contact Email'; may include
     *                            merge fields like 'First Name', 'Last Name', 'Company'.
     *                            Example: [['Contact Email' => 'foo@bar.com', 'First Name' => 'Jean'], ...]
     * @return array              Decoded JSON response from Zoho Campaigns.
     *
     * @throws \RuntimeException  If the HTTP request fails or Zoho returns a non-2xx status.
     */
    public function addListSubscribers(string $listKey, array $contacts): array
    {
        $accessToken = $this->authService->getAccessToken('campaigns');

        // Zoho Campaigns bulk-subscribe expects emailids as a JSON string.
        $emailIds = json_encode(array_map(function (array $contact) {
            return [
                'Contact Email' => $contact['Contact Email'] ?? $contact['email'] ?? '',
                'First Name'    => $contact['First Name'] ?? $contact['first_name'] ?? '',
                'Last Name'     => $contact['Last Name'] ?? $contact['last_name'] ?? '',
                'Company'       => $contact['Company'] ?? $contact['company'] ?? '',
            ];
        }, $contacts));

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
        ])
            ->timeout(30)
            ->asForm()
            ->post($this->apiUrl . '/json/listsubscriberinbulk', [
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

        Log::info('[ZohoCampaignsClient] addListSubscribers', [
            'list_key' => $listKey,
            'count'    => count($contacts),
            'status'   => $response->status(),
        ]);

        return $payload;
    }

    /**
     * Create a new campaign in Zoho Campaigns.
     *
     * UNVERIFIED — endpoint/params not live-tinker-confirmed (Zoho Campaigns OAuth
     * not provisioned). Per the empirical-verification rule, run a live tinker
     * POST + record STATUS 200 before relying on this in production.
     *
     * Documented endpoint: POST /json/createcampaign
     * Returns a campaign key in the response payload (field: 'campaignkey').
     *
     * @param  string  $name        Internal campaign name visible in Zoho UI.
     * @param  string  $subject     Email subject line.
     * @param  string  $fromEmail   Sender email address (must be verified in Zoho Campaigns).
     * @param  string  $listKey     Target mailing list key.
     * @param  string  $htmlContent HTML body of the email.
     * @return array               Decoded JSON response; typically contains 'campaignkey'.
     *
     * @throws \RuntimeException  If the HTTP request fails or Zoho returns a non-2xx status.
     */
    public function createCampaign(
        string $name,
        string $subject,
        string $fromEmail,
        string $listKey,
        string $htmlContent,
    ): array {
        $accessToken = $this->authService->getAccessToken('campaigns');

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-oauthtoken ' . $accessToken,
        ])
            ->timeout(30)
            ->asForm()
            ->post($this->apiUrl . '/json/createcampaign', [
                'resfmt'       => 'JSON',
                'campaignname' => $name,
                'subject'      => $subject,
                'fromEmail'    => $fromEmail,
                'listkey'      => $listKey,
                'htmlcontent'  => $htmlContent,
                'campaigntype' => 'EmailCampaign',
            ]);

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
            'campaign_key' => $payload['campaignkey'] ?? 'inconnu',
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
     * Documented endpoint: POST /json/sendcampaign
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
            ->post($this->apiUrl . '/json/sendcampaign', [
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
     * Documented endpoint: GET /json/getcampaigndetails
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
            ->get($this->apiUrl . '/json/getcampaigndetails', [
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
