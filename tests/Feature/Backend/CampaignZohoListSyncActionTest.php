<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Zoho\ZohoRecipientListGateway;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignZohoListSyncActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_and_verify_a_campaign_list_without_creating_or_sending_a_campaign(): void
    {
        Http::preventStrayRequests();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['prospecting.cold_send_enabled' => false]);

        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('superadmin');
        $company = Company::create([
            'name' => 'Acme', 'relationship' => 'client', 'source' => 'manual', 'qualification_status' => 'pending',
        ]);
        Contact::create([
            'company_id' => $company->id, 'email' => 'jean@acme.test', 'name' => 'Jean', 'status' => 'new',
            'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
        ]);
        $segment = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Modèle', 'subject' => 'Sujet', 'html_content' => '<p>Bonjour</p>']);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'noreply@tcl.test']);
        $campaign = Campaign::create([
            'name' => 'Campagne Zoho', 'segment_id' => $segment->id, 'template_id' => $template->id,
            'sender_identity_id' => $sender->id, 'schedule_type' => 'one_shot', 'scheduled_at' => now(), 'timezone' => 'Europe/Paris',
        ]);

        $gateway = new class implements ZohoRecipientListGateway {
            public array $remoteEmails = [];
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
        $this->app->instance(ZohoRecipientListGateway::class, $gateway);

        $this->actingAs($admin)->get("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee('Ajouter et vérifier la liste Zoho');

        $this->actingAs($admin)->post("/admin/campaigns/{$campaign->id}/sync-zoho-list")
            ->assertOk()
            ->assertJsonPath('status', 'synchronized')
            ->assertJsonPath('verified', true)
            ->assertJsonPath('added', 1);

        $this->assertSame(0, $gateway->createCampaignCalls);
        $this->assertSame(0, $gateway->sendCampaignCalls);
        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'zoho_list_key' => 'zoho-list-' . $campaign->id,
        ]);
        $this->assertDatabaseHas('campaign_runs', [
            'campaign_id' => $campaign->id,
            'status' => 'prepared',
            'zoho_list_key' => 'zoho-list-' . $campaign->id,
        ]);
    }
}
