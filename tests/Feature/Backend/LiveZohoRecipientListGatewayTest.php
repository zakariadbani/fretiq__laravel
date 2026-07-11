<?php

namespace Tests\Feature\Backend;

use App\Services\Zoho\LiveZohoRecipientListGateway;
use App\Services\Zoho\ZohoCampaignsClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveZohoRecipientListGatewayTest extends TestCase
{
    public function test_it_creates_a_campaign_owned_list_with_approved_seed_contacts_and_only_adds_missing_contacts(): void
    {
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        Http::preventStrayRequests();
        $subscribers = ['historique@acme.test', 'jean@acme.test'];
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*addlistandcontacts*' => Http::response(['code' => 0, 'listkey' => 'stable-list-7'], 200),
            '*getmailinglists*' => Http::response(['code' => 0, 'list_of_details' => [['listkey' => 'stable-list-7']]], 200),
            '*getlistsubscribers*' => fn () => Http::response(['code' => 0, 'list_of_details' => array_map(fn (string $email) => ['contact_email' => $email], $subscribers)], 200),
            '*addlistsubscribersinbulk*' => function (\Illuminate\Http\Client\Request $request) use (&$subscribers) {
                $subscribers = array_values(array_unique(array_merge($subscribers, explode(',', $request['emailids']))));

                return Http::response(['code' => 0], 200);
            },
        ]);

        $gateway = new LiveZohoRecipientListGateway(app(ZohoCampaignsClient::class));
        $listKey = $gateway->ensureCampaignList(7, 'Fretiq — Campagne #7', [['Contact Email' => 'jean@acme.test']]);
        $before = $gateway->listEmails($listKey);
        $gateway->addContacts($listKey, [['Contact Email' => 'marie@acme.test']]);

        $this->assertSame('stable-list-7', $listKey);
        $this->assertSame(['historique@acme.test', 'jean@acme.test'], $before);
        Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => $request->url() === 'https://campaigns.zoho.com/api/v1.1/addlistsubscribersinbulk'
            && $request['emailids'] === 'marie@acme.test');
        Http::assertNotSent(fn (\Illuminate\Http\Client\Request $request) => str_contains($request->url(), '/createCampaign') || str_contains($request->url(), '/sendcampaign'));
    }
}
