<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Services\Campaign\CampaignZohoListSyncService;
use App\Services\Campaign\SegmentService;
use App\Services\Zoho\ZohoRecipientListGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignZohoListSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'prospecting.cold_send_enabled' => false,
            'services.zoho.campaigns.topic_id' => '',
        ]);
    }

    public function test_sync_ensures_and_persists_one_campaign_list_then_reuses_it(): void
    {
        [$campaign, $jean, $marie] = $this->campaignWithTwoEligibleContacts();

        $gateway = new class implements ZohoRecipientListGateway {
            public array $remoteEmails = ['jean@acme.test', 'contact-historique@acme.test'];
            public array $added = [];
            public array $ensured = [];
            public int $createCampaignCalls = 0;
            public int $sendCampaignCalls = 0;

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                $this->ensured[] = compact('campaignId', 'listName');

                return 'zoho-list-stable-' . $campaignId;
            }

            public function listEmails(string $listKey): array
            {
                return $this->remoteEmails;
            }

            public function addContacts(string $listKey, array $contacts): void
            {
                $this->added = array_merge($this->added, $contacts);
                $this->remoteEmails = array_values(array_unique(array_merge(
                    $this->remoteEmails,
                    array_column($contacts, 'Contact Email'),
                )));
            }

            public function createCampaign(): void { $this->createCampaignCalls++; }
            public function sendCampaign(): void { $this->sendCampaignCalls++; }
        };

        $service = new CampaignZohoListSyncService(app(SegmentService::class), $gateway);
        $first = $service->sync($campaign);
        $campaign->refresh();
        $second = $service->sync($campaign);

        $this->assertSame('synchronized', $first['status']);
        $this->assertTrue($first['verified']);
        $this->assertSame('zoho-list-stable-' . $campaign->id, $campaign->zoho_list_key);
        $this->assertCount(1, $gateway->ensured);
        $this->assertSame($campaign->id, $gateway->ensured[0]['campaignId']);
        $this->assertStringContainsString('Fretiq Campaign ' . $campaign->id, $gateway->ensured[0]['listName']);
        $this->assertSame(['marie@acme.test'], array_column($gateway->added, 'Contact Email'));
        $this->assertContains('contact-historique@acme.test', $gateway->remoteEmails);
        $this->assertSame(0, $gateway->createCampaignCalls);
        $this->assertSame(0, $gateway->sendCampaignCalls);

        $this->assertSame('synchronized', $second['status']);
        $this->assertTrue($second['verified']);
        $this->assertCount(1, $gateway->ensured);
        $this->assertSame(0, $second['added']);

        $run = $first['run'];
        $this->assertSame('prepared', $run->status);
        $this->assertSame('zoho-list-stable-' . $campaign->id, $run->zoho_list_key);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $jean->id, 'status' => 'queued']);
        $this->assertDatabaseHas('campaign_recipients', ['campaign_run_id' => $run->id, 'contact_id' => $marie->id, 'status' => 'queued']);
    }

    /**
     * Zoho only delivers a campaign to contacts subscribed to its topic
     * ("rubrique"). Seed contacts pushed by ensureCampaignList() at list
     * creation are already list members but were never topic-subscribed, so
     * when a topic is configured, sync() must push the FULL target audience
     * through addContacts() — not just the membership diff — otherwise those
     * seed contacts are silently skipped by Zoho's send. The reported 'added'
     * count still reflects the membership diff, not the topic-subscribe count.
     */
    public function test_sync_pushes_all_target_contacts_when_a_zoho_topic_is_configured(): void
    {
        config(['services.zoho.campaigns.topic_id' => 'topic-99']);

        [$campaign] = $this->campaignWithTwoEligibleContacts();

        $gateway = new class implements ZohoRecipientListGateway {
            public array $remoteEmails = ['jean@acme.test', 'contact-historique@acme.test'];
            public array $added = [];
            public int $createCampaignCalls = 0;
            public int $sendCampaignCalls = 0;

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                return 'zoho-list-stable-' . $campaignId;
            }

            public function listEmails(string $listKey): array
            {
                return $this->remoteEmails;
            }

            public function addContacts(string $listKey, array $contacts): void
            {
                $this->added = array_merge($this->added, $contacts);
                $this->remoteEmails = array_values(array_unique(array_merge(
                    $this->remoteEmails,
                    array_column($contacts, 'Contact Email'),
                )));
            }

            public function createCampaign(): void { $this->createCampaignCalls++; }
            public function sendCampaign(): void { $this->sendCampaignCalls++; }
        };

        $summary = (new CampaignZohoListSyncService(app(SegmentService::class), $gateway))->sync($campaign);

        // jean@acme.test was already a list member (in remoteEmails), yet it must
        // still be pushed through addContacts() so Zoho subscribes it to the topic.
        $this->assertSame(
            ['jean@acme.test', 'marie@acme.test'],
            array_column($gateway->added, 'Contact Email'),
        );
        // 'added' still reports the membership diff (only marie was missing), not
        // the topic-subscribe count (both contacts).
        $this->assertSame(1, $summary['added']);
        $this->assertTrue($summary['verified']);
    }

    /**
     * Complementary case: with no topic configured, only the contacts missing
     * from the Zoho list are pushed through addContacts() — the legacy,
     * membership-diff-only behaviour.
     */
    public function test_sync_pushes_only_missing_contacts_when_no_zoho_topic_is_configured(): void
    {
        config(['services.zoho.campaigns.topic_id' => '']);

        [$campaign] = $this->campaignWithTwoEligibleContacts();

        $gateway = new class implements ZohoRecipientListGateway {
            public array $remoteEmails = ['jean@acme.test', 'contact-historique@acme.test'];
            public array $added = [];
            public int $createCampaignCalls = 0;
            public int $sendCampaignCalls = 0;

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                return 'zoho-list-stable-' . $campaignId;
            }

            public function listEmails(string $listKey): array
            {
                return $this->remoteEmails;
            }

            public function addContacts(string $listKey, array $contacts): void
            {
                $this->added = array_merge($this->added, $contacts);
                $this->remoteEmails = array_values(array_unique(array_merge(
                    $this->remoteEmails,
                    array_column($contacts, 'Contact Email'),
                )));
            }

            public function createCampaign(): void { $this->createCampaignCalls++; }
            public function sendCampaign(): void { $this->sendCampaignCalls++; }
        };

        $summary = (new CampaignZohoListSyncService(app(SegmentService::class), $gateway))->sync($campaign);

        $this->assertSame(['marie@acme.test'], array_column($gateway->added, 'Contact Email'));
        $this->assertSame(1, $summary['added']);
        $this->assertTrue($summary['verified']);
    }

    public function test_sync_is_successful_when_unrelated_existing_list_contacts_are_retained(): void
    {
        [$campaign] = $this->campaignWithTwoEligibleContacts();

        $gateway = new class implements ZohoRecipientListGateway {
            public array $remoteEmails = ['contact-historique@acme.test'];
            public int $createCampaignCalls = 0;
            public int $sendCampaignCalls = 0;

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string { return 'zoho-list-' . $campaignId; }
            public function listEmails(string $listKey): array { return $this->remoteEmails; }
            public function addContacts(string $listKey, array $contacts): void
            {
                $this->remoteEmails = array_values(array_unique(array_merge($this->remoteEmails, array_column($contacts, 'Contact Email'))));
            }
            public function createCampaign(): void { $this->createCampaignCalls++; }
            public function sendCampaign(): void { $this->sendCampaignCalls++; }
        };

        $summary = (new CampaignZohoListSyncService(app(SegmentService::class), $gateway))->sync($campaign);

        $this->assertTrue($summary['verified']);
        $this->assertContains('contact-historique@acme.test', $gateway->remoteEmails);
        $this->assertSame(0, $gateway->createCampaignCalls);
        $this->assertSame(0, $gateway->sendCampaignCalls);
    }

    public function test_unavailable_gateway_creates_no_snapshot_recipients_or_campaign_list_key(): void
    {
        [$campaign] = $this->campaignWithTwoEligibleContacts();

        $gateway = new class implements ZohoRecipientListGateway {
            public int $createCampaignCalls = 0;
            public int $sendCampaignCalls = 0;

            public function ensureCampaignList(int $campaignId, string $listName, array $seedContacts): string
            {
                throw new \LogicException('La création ou la lecture des listes Zoho n’est pas vérifiée.');
            }

            public function listEmails(string $listKey): array { throw new \LogicException('Ne doit jamais être appelé.'); }
            public function addContacts(string $listKey, array $contacts): void { throw new \LogicException('Ne doit jamais être appelé.'); }
            public function createCampaign(): void { $this->createCampaignCalls++; }
            public function sendCampaign(): void { $this->sendCampaignCalls++; }
        };

        try {
            (new CampaignZohoListSyncService(app(SegmentService::class), $gateway))->sync($campaign);
            $this->fail('Expected unavailable recipient-list gateway to block synchronization.');
        } catch (\LogicException $exception) {
            $this->assertSame('La création ou la lecture des listes Zoho n’est pas vérifiée.', $exception->getMessage());
        }

        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('campaign_recipients', 0);
        $this->assertNull($campaign->fresh()->zoho_list_key);
        $this->assertSame(0, $gateway->createCampaignCalls);
        $this->assertSame(0, $gateway->sendCampaignCalls);
    }

    /** @return array{Campaign, Contact, Contact} */
    private function campaignWithTwoEligibleContacts(): array
    {
        $company = Company::create([
            'name' => 'Acme', 'relationship' => 'client', 'source' => 'manual', 'qualification_status' => 'pending',
        ]);
        $jean = Contact::create([
            'company_id' => $company->id, 'email' => 'jean@acme.test', 'name' => 'Jean Dupont', 'status' => 'new',
            'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
        ]);
        $marie = Contact::create([
            'company_id' => $company->id, 'email' => 'marie@acme.test', 'name' => 'Marie Dupont', 'status' => 'new',
            'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
        ]);
        $segment = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Modèle', 'subject' => 'Sujet', 'html_content' => '<p>Bonjour</p>']);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'noreply@tcl.test']);
        $campaign = Campaign::create([
            'name' => 'Prospection France', 'segment_id' => $segment->id, 'template_id' => $template->id,
            'sender_identity_id' => $sender->id, 'schedule_type' => 'one_shot', 'scheduled_at' => now(), 'timezone' => 'Europe/Paris',
        ]);

        return [$campaign, $jean, $marie];
    }
}
