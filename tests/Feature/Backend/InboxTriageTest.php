<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InboxEmail;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxTriageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('superadmin');
    }

    public function test_interested_reply_creates_an_attributed_demande(): void
    {
        [$email, $recipient] = $this->campaignReply();

        $this->actingAs($this->admin)
            ->post("/admin/inbox/{$email->id}/triage", ['action' => 'interested'])
            ->assertRedirect(route('admin.inbox.view', $email));

        $email->refresh();
        $this->assertDatabaseHas('demandes', [
            'id' => $email->demande_id,
            'contact_id' => $recipient->contact_id,
            'campaign_id' => $recipient->run->campaign_id,
            'campaign_run_id' => $recipient->campaign_run_id,
            'kind' => 'reply',
        ]);
        $this->assertSame('interested', $email->triage_action);
        $this->assertSame(InboxEmail::STATUS_TRAITE, $email->status);
        $this->assertNotNull($email->processed_at);
    }

    private function campaignReply(): array
    {
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'sender@example.test']);
        $company = Company::create(['name' => 'Acme', 'source' => 'manual']);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => 'prospect@example.test',
            'name' => 'Prospect',
            'source' => 'manual',
            'status' => 'new',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
        $segment = Segment::create(['name' => 'Inbox segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Inbox template',
            'subject' => 'Objet',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $campaign = Campaign::create([
            'name' => 'Inbox campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'inbox-triage-'.uniqid(),
            'run_at' => now(),
            'status' => 'sent',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
        ])->load('run');
        $email = InboxEmail::create([
            'sender_identity_id' => $sender->id,
            'message_id' => uniqid('inbox-', true).'@example.test',
            'from_email' => $contact->email,
            'subject' => 'Je suis intéressé',
            'body_text' => 'Merci de me rappeler.',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'contact_id' => $contact->id,
            'campaign_recipient_id' => $recipient->id,
            'received_at' => now(),
        ]);

        return [$email, $recipient];
    }
}
