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
 *
 * RefreshDatabase (matching sibling Zoho*Test conventions) rolls back the
 * ZohoToken row ZohoAuthService persists on each getAccessToken() call, so a
 * still-valid cached token from one test method can't leak into the next and
 * suppress an expected oauth/v2/token HTTP call.
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

        Http::preventStrayRequests();

        // Wire fake Zoho Campaigns credentials so ZohoAuthService attempts a refresh
        // (which Http::fake will intercept — no live network call).
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id'     => 'x',
            'services.zoho.campaigns.client_secret'  => 'y',
            // Explicit: the bulk-subscribe branch of addListSubscribers is only
            // reached when no topic is configured. Topic-branch tests below
            // override this per-test.
            'services.zoho.campaigns.topic_id'      => '',
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
     * When a Zoho topic is configured, addListSubscribers must subscribe each
     * contact individually via POST /json/listsubscribe (carrying topic_id +
     * contactinfo) and must never call the topic-less bulk endpoint.
     */
    public function test_add_list_subscribers_uses_topic_subscribe_endpoint_per_contact_when_topic_configured(): void
    {
        config(['services.zoho.campaigns.topic_id' => 'topic-99']);
        Http::preventStrayRequests();

        $seenEmails = [];
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*json/listsubscribe*' => function (\Illuminate\Http\Client\Request $request) use (&$seenEmails) {
                $seenEmails[] = json_decode($request['contactinfo'], true)['Contact Email'];

                return Http::response(['status' => 'success', 'code' => '0'], 200);
            },
        ]);

        $contacts = [
            ['Contact Email' => 'jean@acme.test', 'First Name' => 'Jean'],
            ['Contact Email' => 'marie@acme.test', 'First Name' => 'Marie'],
        ];

        $result = $this->makeClient()->addListSubscribers('LK-001', $contacts);

        $this->assertSame(['code' => '0', 'status' => 'success', 'subscribed' => 2], $result);
        $this->assertSame(['jean@acme.test', 'marie@acme.test'], $seenEmails);

        Http::assertSentCount(3); // 1 oauth + 2 listsubscribe calls
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === $this->baseUrl . '/json/listsubscribe'
                && str_contains($request->header('Authorization')[0] ?? '', 'Zoho-oauthtoken')
                && $request['resfmt'] === 'JSON'
                && $request['listkey'] === 'LK-001'
                && $request['topic_id'] === 'topic-99'
                && $request['source'] === 'fretiq'
                && json_decode($request['contactinfo'], true)['Contact Email'] === 'jean@acme.test';
        });
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), '/addlistsubscribersinbulk'));
    }

    /**
     * Live-verified (prod, 2026-07-21): 'Company Name' is a real contactinfo
     * field — a follow-up getlistsubscribers returned companyname populated.
     * addListSubscribers must include it when the contact carries a 'Company',
     * and omit the key entirely when it doesn't (no empty Company Name sent).
     */
    public function test_add_list_subscribers_with_topic_includes_company_name_when_present(): void
    {
        config(['services.zoho.campaigns.topic_id' => 'topic-99']);
        Http::preventStrayRequests();

        $seenContactInfo = [];
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*json/listsubscribe*' => function (\Illuminate\Http\Client\Request $request) use (&$seenContactInfo) {
                $seenContactInfo[] = json_decode($request['contactinfo'], true);

                return Http::response(['status' => 'success', 'code' => '0'], 200);
            },
        ]);

        $contacts = [
            ['Contact Email' => 'jean@acme.test', 'First Name' => 'Jean', 'Company' => 'Acme SAS'],
            ['Contact Email' => 'marie@acme.test', 'First Name' => 'Marie', 'Company' => ''],
        ];

        $this->makeClient()->addListSubscribers('LK-001', $contacts);

        $this->assertSame([
            'Contact Email' => 'jean@acme.test',
            'First Name' => 'Jean',
            'Company Name' => 'Acme SAS',
        ], $seenContactInfo[0]);
        $this->assertSame([
            'Contact Email' => 'marie@acme.test',
            'First Name' => 'Marie',
        ], $seenContactInfo[1]);
        $this->assertArrayNotHasKey('Company Name', $seenContactInfo[1]);
    }

    public function test_add_list_subscribers_with_topic_truncates_company_name_to_100_characters(): void
    {
        config(['services.zoho.campaigns.topic_id' => 'topic-99']);
        $company = str_repeat('É', 101);

        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*json/listsubscribe*' => Http::response(['status' => 'success', 'code' => '0'], 200),
        ]);

        $this->makeClient()->addListSubscribers('LK-001', [
            ['Contact Email' => 'jean@acme.test', 'Company' => $company],
        ]);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($company) {
            if ($request->url() !== $this->baseUrl . '/json/listsubscribe') {
                return false;
            }

            $companyName = json_decode($request['contactinfo'], true)['Company Name'];

            return mb_strlen($companyName) === 100
                && $companyName === mb_substr($company, 0, 100);
        });
    }

    /**
     * An API-level error code from /json/listsubscribe (not in the accepted-codes
     * allowlist) must raise a RuntimeException, not be silently swallowed.
     */
    public function test_add_list_subscribers_with_topic_throws_on_api_level_error(): void
    {
        config(['services.zoho.campaigns.topic_id' => 'topic-99']);
        Http::preventStrayRequests();

        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*json/listsubscribe*' => Http::response(['status' => 'error', 'code' => '1234', 'message' => 'Invalid list key'], 200),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->makeClient()->addListSubscribers('LK-001', [
            ['Contact Email' => 'jean@acme.test'],
        ]);
    }

    public function test_create_recipient_list_with_seed_contacts_verifies_the_returned_list_key_without_campaign_calls(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*addlistandcontacts*' => Http::response(['code' => 0, 'listkey' => 'list-stable-1'], 200),
            '*getmailinglists*' => Http::response([
                'code' => 0,
                'list_of_details' => [['listkey' => 'list-stable-1', 'listname' => 'Fretiq — Campagne #1']],
            ], 200),
        ]);

        $listKey = $this->makeClient()->createRecipientList('Fretiq — Campagne #1', [
            'jean@acme.test',
            'marie@acme.test',
        ]);

        $this->assertSame('list-stable-1', $listKey);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === $this->baseUrl . '/addlistandcontacts'
            && $request['resfmt'] === 'JSON'
            && $request['listname'] === 'Fretiq — Campagne #1'
            && $request['signupform'] === 'private'
            && $request['mode'] === 'newlist'
            && $request['emailids'] === 'jean@acme.test,marie@acme.test');
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => str_starts_with($request->url(), $this->baseUrl . '/getmailinglists')
            && $request->method() === 'GET');
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), '/createCampaign') || str_contains($request->url(), '/sendcampaign'));
    }

    public function test_create_recipient_list_recovers_existing_key_on_duplicate_name_error(): void
    {
        $listName = 'Fretiq — Campagne #2';
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*addlistandcontacts*' => Http::response(['status' => 'error', 'code' => '2205', 'message' => 'List already exists'], 200),
            '*getmailinglists*' => Http::response([
                'code' => 0,
                'list_of_details' => [['listkey' => 'list-existing-2', 'listname' => $listName]],
            ], 200),
        ]);

        $listKey = $this->makeClient()->createRecipientList($listName, ['jean@acme.test']);

        $this->assertSame('list-existing-2', $listKey);
    }

    public function test_create_recipient_list_recovers_existing_key_from_second_mailing_list_page(): void
    {
        $listName = 'Fretiq — Campagne paginée';
        $pageOne = array_map(
            fn (int $index) => ['listkey' => 'other-' . $index, 'listname' => 'Other list ' . $index],
            range(1, 50),
        );
        $pageRequests = [];

        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*addlistandcontacts*' => Http::response(['status' => 'error', 'code' => '2205', 'message' => 'List already exists'], 200),
            '*getmailinglists*' => function (\Illuminate\Http\Client\Request $request) use (&$pageRequests, $pageOne, $listName) {
                $pageRequests[] = [
                    'fromindex' => $request['fromindex'],
                    'range' => $request['range'],
                ];

                return Http::response([
                    'code' => 0,
                    'list_of_details' => count($pageRequests) === 1
                        ? $pageOne
                        : [['listkey' => 'list-page-2', 'listname' => $listName]],
                ], 200);
            },
        ]);

        $listKey = $this->makeClient()->createRecipientList($listName, ['jean@acme.test']);

        $this->assertSame('list-page-2', $listKey);
        $this->assertSame([
            ['fromindex' => 1, 'range' => 50],
            ['fromindex' => 51, 'range' => 50],
        ], $pageRequests);
    }

    public function test_create_recipient_list_refuses_duplicate_name_recovery_when_list_is_missing(): void
    {
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*addlistandcontacts*' => Http::response(['status' => 'error', 'code' => '2205', 'message' => 'List already exists'], 200),
            '*getmailinglists*' => Http::response([
                'code' => 0,
                'list_of_details' => [['listkey' => 'other-key', 'listname' => 'Other list']],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('introuvable dans getmailinglists');

        $this->makeClient()->createRecipientList('Fretiq — Missing', ['jean@acme.test']);
    }

    public function test_create_recipient_list_refuses_duplicate_name_recovery_when_name_is_ambiguous(): void
    {
        $listName = 'Fretiq — Duplicate';
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*addlistandcontacts*' => Http::response(['status' => 'error', 'code' => '2205', 'message' => 'List already exists'], 200),
            '*getmailinglists*' => Http::response([
                'code' => 0,
                'list_of_details' => [
                    ['listkey' => 'duplicate-1', 'listname' => $listName],
                    ['listkey' => 'duplicate-2', 'listname' => $listName],
                ],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('introuvable dans getmailinglists');

        $this->makeClient()->createRecipientList($listName, ['jean@acme.test']);
    }

    public function test_list_recipient_emails_paginates_and_add_recipient_contacts_inspects_api_code_without_campaign_calls(): void
    {
        Http::preventStrayRequests();
        $pageOne = array_map(
            fn (int $index) => ['contact_email' => 'contact' . $index . '@acme.test'],
            range(1, 200),
        );
        $subscriberPages = 0;
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*getlistsubscribers*' => function () use (&$subscriberPages, $pageOne) {
                $subscriberPages++;

                return Http::response($subscriberPages === 1
                    ? ['code' => 0, 'list_of_details' => $pageOne]
                    : ['code' => 0, 'list_of_details' => [['contact_email' => 'final@acme.test']]], 200);
            },
            '*addlistsubscribersinbulk*' => Http::response(['code' => 0, 'status' => 'success'], 200),
        ]);

        $client = $this->makeClient();
        $emails = $client->listRecipientEmails('list-stable-1');
        $this->assertCount(201, $emails);
        $this->assertSame('contact1@acme.test', $emails[0]);
        $this->assertSame('final@acme.test', $emails[200]);
        $client->addRecipientContacts('list-stable-1', [['Contact Email' => 'sophie@acme.test']]);

        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => str_starts_with($request->url(), $this->baseUrl . '/getlistsubscribers')
            && $request['listkey'] === 'list-stable-1'
            && $request['fromindex'] === 1
            && $request['range'] === 200);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => str_starts_with($request->url(), $this->baseUrl . '/getlistsubscribers')
            && $request['fromindex'] === 201);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === $this->baseUrl . '/addlistsubscribersinbulk'
            && $request['emailids'] === 'sophie@acme.test');
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), '/createCampaign') || str_contains($request->url(), '/sendcampaign'));
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
            fromName:   'TCL France',
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
                && $request['from_name'] === 'TCL France'
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
