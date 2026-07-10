<?php

namespace Tests\Feature\Backend;

use App\Services\Zoho\ZohoCampaignsClient;
use App\Services\Zoho\ZohoAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ZohoCampaignsClientTest — unit-level tests for the thin HTTP wrapper.
 *
 * All external HTTP is faked via Http::fake().
 * The OAuth endpoint is faked too so no real token refresh is attempted.
 * No live Zoho Campaigns credentials are required.
 */
class ZohoCampaignsClientTest extends TestCase
{
    use RefreshDatabase;

    /** Base URL used by the client (matches config default). */
    private string $baseUrl = 'https://campaigns.zoho.com/api/v1.1';

    /** OAuth token endpoint (accounts.zoho.com). */
    private string $oauthUrl = 'https://accounts.zoho.com/oauth/v2/token';

    protected function setUp(): void
    {
        parent::setUp();

        // Wire fake Zoho Campaigns credentials so ZohoAuthService attempts a refresh
        // (which Http::fake will intercept — no live network call).
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id'     => 'x',
            'services.zoho.campaigns.client_secret'  => 'y',
        ]);
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    /**
     * Build the client under test using the real ZohoAuthService (which will hit
     * Http::fake for token refresh).
     */
    private function makeClient(): ZohoCampaignsClient
    {
        return app(ZohoCampaignsClient::class);
    }

    /**
     * Returns a fake Http response factory array that covers both the OAuth
     * token endpoint and a given campaigns API endpoint.
     *
     * @param  string  $campaignsPattern   URL pattern (wildcard OK) for the campaign endpoint.
     * @param  array   $campaignsResponse  Decoded JSON to return from that endpoint.
     */
    private function fakeOAuthAndEndpoint(string $campaignsPattern, array $campaignsResponse): void
    {
        Http::fake([
            // OAuth refresh
            '*oauth/v2/token*' => Http::response([
                'access_token' => 'fake-at',
                'expires_in'   => 3600,
            ], 200),

            // Campaigns API
            $campaignsPattern => Http::response($campaignsResponse, 200),
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * addListSubscribers posts to /json/listsubscriberinbulk with the
     * Authorization header and listkey param, and returns the decoded body.
     */
    public function test_add_list_subscribers_posts_expected_shape(): void
    {
        $fakeResponse = ['status' => 'success', 'code' => '0', 'message' => 'Added'];

        Http::fake([
            '*oauth/v2/token*' => Http::response([
                'access_token' => 'fake-at',
                'expires_in'   => 3600,
            ], 200),

            '*addlistsubscribersinbulk*' => Http::response($fakeResponse, 200),
        ]);

        $contacts = [
            [
                'Contact Email' => 'jean@acme.test',
                'First Name'    => 'Jean',
                'Last Name'     => 'Dupont',
                'Company'       => 'Acme',
            ],
        ];

        $result = $this->makeClient()->addListSubscribers('LK-001', $contacts);

        // Assert the decoded response is returned
        $this->assertSame($fakeResponse, $result);

        // Assert a request was made to the bulk-subscribe endpoint
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === $this->baseUrl . '/addlistsubscribersinbulk'
                && str_contains($request->header('Authorization')[0] ?? '', 'Zoho-oauthtoken')
                && $request['listkey'] === 'LK-001'
                && $request['resfmt'] === 'JSON'
                && $request['emailids'] === 'jean@acme.test';
        });
    }

    /**
     * createCampaign posts to Zoho's documented /createCampaign endpoint and
     * uses the live-verified parameter names from Zoho docs: from_email,
     * list_details, and content_url. sendCampaign uses /sendcampaign.
     */
    public function test_create_and_send_campaign(): void
    {
        $createResponse = ['campaignKey' => 'CK-001', 'status' => 'success'];
        $sendResponse   = ['status' => 'success', 'message' => 'Sent'];

        Http::fake([
            '*oauth/v2/token*' => Http::response([
                'access_token' => 'fake-at',
                'expires_in'   => 3600,
            ], 200),

            '*createCampaign*' => Http::response($createResponse, 200),
            '*sendcampaign*'   => Http::response($sendResponse, 200),
        ]);

        $client = $this->makeClient();

        // createCampaign
        $createResult = $client->createCampaign(
            name:       'Test Campaign',
            subject:    'Hello Subject',
            fromEmail:  'sender@fretiq.fr',
            listKey:    'LK-001',
            contentUrl: 'https://fretiq.test/campaign-runs/1/zoho-content?signature=fake',
        );

        $this->assertSame('CK-001', $createResult['campaignKey']);
        $this->assertSame('success', $createResult['status']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === $this->baseUrl . '/createCampaign'
                && str_contains($request->header('Authorization')[0] ?? '', 'Zoho-oauthtoken')
                && $request['campaignname'] === 'Test Campaign'
                && $request['from_email'] === 'sender@fretiq.fr'
                && $request['list_details'] === json_encode(['LK-001' => []])
                && $request['content_url'] === 'https://fretiq.test/campaign-runs/1/zoho-content?signature=fake'
                && $request['resfmt'] === 'JSON';
        });

        // sendCampaign
        $sendResult = $client->sendCampaign('CK-001');

        $this->assertSame('success', $sendResult['status']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === $this->baseUrl . '/sendcampaign'
                && str_contains($request->header('Authorization')[0] ?? '', 'Zoho-oauthtoken')
                && $request['campaignkey'] === 'CK-001';
        });
    }

    /**
     * getCampaignReport calls GET /campaignreports and returns the
     * raw decoded payload (stats parsing happens in SyncCampaignStatsJob).
     */
    public function test_get_campaign_report_parses_stats(): void
    {
        $reportPayload = [
            'sent_count'    => 120,
            'opened_count'  => 45,
            'clicked_count' => 12,
            'bounced_count' => 3,
        ];

        Http::fake([
            '*oauth/v2/token*' => Http::response([
                'access_token' => 'fake-at',
                'expires_in'   => 3600,
            ], 200),

            '*campaignreports*' => Http::response($reportPayload, 200),
        ]);

        $result = $this->makeClient()->getCampaignReport('CK-001');

        // The client returns the raw decoded payload
        $this->assertSame(120, $result['sent_count']);
        $this->assertSame(45,  $result['opened_count']);
        $this->assertSame(12,  $result['clicked_count']);
        $this->assertSame(3,   $result['bounced_count']);

        // Verify GET request was sent to the right endpoint
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return str_contains($request->url(), 'campaignreports')
                && $request->method() === 'GET'
                && str_contains($request->header('Authorization')[0] ?? '', 'Zoho-oauthtoken');
        });
    }
}
