<?php

namespace Tests\Feature\Backend;

use App\Jobs\SyncCampaignRecipientEventsJob;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncCampaignRecipientPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        Http::preventStrayRequests();
    }

    public function test_recipient_sync_advances_fromindex_by_the_page_size(): void
    {
        $run = $this->makeRun();
        $fromIndexes = [];
        $fullPage = array_fill(0, 100, ['contactemailaddress' => 'unmatched@example.test']);

        Http::fake(function ($request) use (&$fromIndexes, $fullPage) {
            if (str_contains($request->url(), 'oauth/v2/token')) {
                return Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['action'] ?? '') !== 'openedcontacts') {
                return Http::response(['status' => 'error', 'code' => '6303'], 200);
            }

            $fromIndexes[] = (int) $query['fromindex'];

            return Http::response([
                'status' => 'success',
                'code' => '0',
                'list_of_details' => count($fromIndexes) === 1 ? $fullPage : [],
            ], 200);
        });

        SyncCampaignRecipientEventsJob::dispatchSync($run->id);

        $this->assertSame([1, 101], $fromIndexes);
    }

    public function test_recipient_sync_throws_when_zoho_repeats_a_full_page(): void
    {
        $run = $this->makeRun();
        $openedRequests = 0;
        $fullPage = array_fill(0, 100, ['contactemailaddress' => 'repeated@example.test']);

        Http::fake(function ($request) use (&$openedRequests, $fullPage) {
            if (str_contains($request->url(), 'oauth/v2/token')) {
                return Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['action'] ?? '') !== 'openedcontacts') {
                return Http::response(['status' => 'error', 'code' => '6303'], 200);
            }

            $openedRequests++;
            if ($openedRequests > 2) {
                throw new \RuntimeException('test request cap reached');
            }

            return Http::response([
                'status' => 'success',
                'code' => '0',
                'list_of_details' => $fullPage,
            ], 200);
        });

        try {
            SyncCampaignRecipientEventsJob::dispatchSync($run->id);
            $this->fail('Expected repeated recipient page detection.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('repeated recipient page', $exception->getMessage());
        }

        $this->assertSame(2, $openedRequests);
    }

    private function makeRun(): CampaignRun
    {
        $segment = Segment::create(['name' => 'Pagination segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Pagination template',
            'subject' => 'Pagination',
            'html_content' => '<p>Pagination</p>',
        ]);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'pagination@tcl.test']);
        $campaign = Campaign::create([
            'name' => 'Pagination campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ]);

        return CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'pagination-'.uniqid(),
            'run_at' => now(),
            'status' => 'sent',
            'zoho_campaign_key' => 'CK-PAGINATION',
        ]);
    }
}
