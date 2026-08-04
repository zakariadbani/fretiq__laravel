<?php

namespace Tests\Feature\Backend;

use App\DataTables\Backend\DemandesDataTable;
use App\DataTables\Backend\InboxEmailsDataTable;
use App\DataTables\Backend\SuppressionsDataTable;
use App\Jobs\FetchInboxJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Segment;
use App\Models\InboxEmail;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InboxModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $viewer;
    private User $unprivileged;
    private InboxEmail $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('superadmin');

        $this->viewer = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->viewer->givePermissionTo(['backend.access', 'view inbox']);

        $this->unprivileged = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->unprivileged->givePermissionTo('backend.access');

        $identity = SenderIdentity::create(['name' => 'Inbox sender', 'email' => 'sender@example.test']);
        $this->email = InboxEmail::create([
            'sender_identity_id' => $identity->id,
            'message_id' => 'module-message@example.test',
            'from_email' => 'prospect@example.test',
            'subject' => 'Module reply',
            'body_html' => '<p>Safe body</p>',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'received_at' => now(),
        ]);
    }

    public function test_index_and_detail_require_view_permission(): void
    {
        $this->actingAs($this->viewer)->get(route('admin.inbox.index'))->assertOk();
        $this->actingAs($this->viewer)->get(route('admin.inbox.view', $this->email))->assertOk()->assertSee('Safe body', false);

        $this->actingAs($this->unprivileged)->get(route('admin.inbox.index'))->assertForbidden();
        $this->actingAs($this->unprivileged)->get(route('admin.inbox.view', $this->email))->assertForbidden();
    }

    public function test_status_and_resync_require_edit_permission(): void
    {
        Queue::fake();

        $this->actingAs($this->viewer)
            ->postJson(route('admin.inbox.status', $this->email), ['status' => InboxEmail::STATUS_TRAITE])
            ->assertForbidden();
        $this->actingAs($this->viewer)->postJson(route('admin.inbox.resync'))->assertForbidden();

        $this->actingAs($this->admin)->postJson(route('admin.inbox.resync'))->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_status_validation_and_processed_timestamp_behavior(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('admin.inbox.status', $this->email), ['status' => 'invalid'])
            ->assertUnprocessable();

        $this->actingAs($this->admin)
            ->postJson(route('admin.inbox.status', $this->email), ['status' => InboxEmail::STATUS_NOUVEAU])
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('message', 'Le statut est déjà à jour.');
        $this->assertNull($this->email->fresh()->processed_at);

        $this->actingAs($this->admin)
            ->postJson(route('admin.inbox.status', $this->email), ['status' => InboxEmail::STATUS_TRAITE])
            ->assertOk()
            ->assertJsonPath('changed', true);
        $this->assertSame(InboxEmail::STATUS_TRAITE, $this->email->fresh()->status);
        $this->assertNotNull($this->email->fresh()->processed_at);
        $processedAt = $this->email->fresh()->processed_at;

        $this->actingAs($this->admin)
            ->postJson(route('admin.inbox.status', $this->email), ['status' => InboxEmail::STATUS_NOUVEAU])
            ->assertOk()
            ->assertJsonPath('changed', false)
            ->assertJsonPath('status', InboxEmail::STATUS_TRAITE);
        $this->assertSame(InboxEmail::STATUS_TRAITE, $this->email->fresh()->status);
        $this->assertTrue($processedAt->equalTo($this->email->fresh()->processed_at));
    }

    public function test_processed_message_hides_status_and_triage_controls(): void
    {
        $this->email->update([
            'status' => InboxEmail::STATUS_IGNORE,
            'processed_at' => now(),
            'triage_action' => 'automatic',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.inbox.view', $this->email))
            ->assertOk()
            ->assertSee('Tri actuel')
            ->assertDontSee('data-url="'.route('admin.inbox.status', $this->email).'"', false)
            ->assertDontSee('data-inbox-triage=', false);
    }

    public function test_manual_replied_button_requires_create_demandes(): void
    {
        $recipient = $this->attachCampaignRecipient();
        $operator = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $operator->givePermissionTo(['backend.access', 'view inbox', 'create demandes']);

        $this->actingAs($this->viewer)
            ->get(route('admin.inbox.view', $this->email))
            ->assertOk()
            ->assertDontSee('btn btn-success w-100', false);

        $this->actingAs($operator)
            ->get(route('admin.inbox.view', $this->email))
            ->assertOk()
            ->assertSee('btn btn-success w-100', false)
            ->assertSee(route('admin.campaigns.markReplied', [$recipient->run->campaign_id, $recipient->id]), false);
    }

    public function test_datatable_is_latest_first(): void
    {
        $this->email->update(['received_at' => now()->subDay()]);
        $latest = InboxEmail::create([
            'sender_identity_id' => $this->email->sender_identity_id,
            'message_id' => 'latest@example.test',
            'from_email' => 'latest@example.test',
            'subject' => 'Latest reply',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'received_at' => now(),
        ]);

        $this->inboxDataTable()
            ->assertOk()
            ->assertJsonPath('data.0.id', $latest->id);
    }

    public function test_index_shows_last_successful_poll_status(): void
    {
        $identity = SenderIdentity::findOrFail($this->email->sender_identity_id);
        $identity->update(['is_active' => true, 'imap_enabled' => true]);
        SenderIdentity::whereKey($identity->id)->update(['last_polled_at' => '2026-08-04 08:30:00']);

        $inactive = SenderIdentity::create([
            'name' => 'Inactive inbox',
            'email' => 'inactive@example.test',
            'is_active' => false,
            'imap_enabled' => true,
        ]);
        SenderIdentity::whereKey($inactive->id)->update(['last_polled_at' => '2026-08-05 08:30:00']);
        $this->email->update(['received_at' => '2026-08-06 08:30:00']);

        $this->actingAs($this->admin)
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertSee('Dernière relève réussie')
            ->assertSee('04/08/2026 10:30')
            ->assertDontSee('05/08/2026 10:30')
            ->assertDontSee('06/08/2026 10:30');
    }

    public function test_index_distinguishes_never_polled_from_no_active_inbox(): void
    {
        $identity = SenderIdentity::findOrFail($this->email->sender_identity_id);

        $this->actingAs($this->admin)
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertSee('Aucune boîte active')
            ->assertSee(route('admin.sender_identities.index'), false);

        $identity->update(['is_active' => true, 'imap_enabled' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertSee('Relève jamais effectuée');

        $this->actingAs($this->viewer)
            ->get(route('admin.inbox.index'))
            ->assertOk()
            ->assertDontSee(route('admin.sender_identities.index'), false);
    }

    public function test_datatables_have_module_specific_permission_safe_empty_states(): void
    {
        $this->actingAs($this->admin);

        $demandeLanguage = (new DemandesDataTable(new Demande(), request()))->html()->getOptions()['language'];
        $inboxLanguage = (new InboxEmailsDataTable(new InboxEmail(), request()))->html()->getOptions()['language'];
        $suppressionLanguage = (new SuppressionsDataTable(new Suppression(), request()))->html()->getOptions()['language'];

        $this->assertStringContainsString(route('admin.demandes.create'), $demandeLanguage['emptyTable']);
        $this->assertStringContainsString(route('admin.sender_identities.index'), $inboxLanguage['emptyTable']);
        $this->assertStringContainsString(route('admin.suppressions.create'), $suppressionLanguage['emptyTable']);
        $this->assertStringContainsString('filtre', $demandeLanguage['zeroRecords']);
        $this->assertStringContainsString('filtre', $inboxLanguage['zeroRecords']);
        $this->assertStringContainsString('filtre', $suppressionLanguage['zeroRecords']);

        $this->actingAs($this->viewer);

        $this->assertStringNotContainsString(
            route('admin.demandes.create'),
            (new DemandesDataTable(new Demande(), request()))->html()->getOptions()['language']['emptyTable']
        );
        $this->assertStringNotContainsString(
            route('admin.sender_identities.index'),
            (new InboxEmailsDataTable(new InboxEmail(), request()))->html()->getOptions()['language']['emptyTable']
        );
        $this->assertStringNotContainsString(
            route('admin.suppressions.create'),
            (new SuppressionsDataTable(new Suppression(), request()))->html()->getOptions()['language']['emptyTable']
        );
    }
    public function test_datatable_applies_status_and_sender_filters(): void
    {
        $secondIdentity = SenderIdentity::create(['name' => 'Second inbox', 'email' => 'second@example.test']);
        $this->email->update(['received_at' => now()->subDays(3)]);
        $target = InboxEmail::create([
            'sender_identity_id' => $secondIdentity->id,
            'message_id' => 'target@example.test',
            'from_email' => 'target@example.test',
            'subject' => 'Target reply',
            'status' => InboxEmail::STATUS_TRAITE,
            'received_at' => now(),
        ]);
        InboxEmail::create([
            'sender_identity_id' => $secondIdentity->id,
            'message_id' => 'wrong-status@example.test',
            'from_email' => 'wrong-status@example.test',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'received_at' => now()->subDay(),
        ]);
        InboxEmail::create([
            'sender_identity_id' => $this->email->sender_identity_id,
            'message_id' => 'wrong-sender@example.test',
            'from_email' => 'wrong-sender@example.test',
            'status' => InboxEmail::STATUS_TRAITE,
            'received_at' => now()->subDays(2),
        ]);

        $filtered = $this->inboxDataTable([
            'status' => InboxEmail::STATUS_TRAITE,
            'sender_identity_id' => $secondIdentity->id,
        ]);
        $filtered->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
    }

    public function test_permissions_and_suivi_menu_entry_are_registered(): void
    {
        $commercial = Role::findByName('commercial');
        $this->assertTrue($commercial->hasPermissionTo('view inbox'));
        $this->assertTrue($commercial->hasPermissionTo('edit inbox'));

        $menu = collect(config('global.menu.main'))->firstWhere('title', 'Boîte de réception');
        $this->assertSame('view inbox', $menu['permission']);
        $this->assertSame('admin/inbox', $menu['path']);
    }

    private function attachCampaignRecipient(): CampaignRecipient
    {
        $company = Company::create(['name' => 'Reply company', 'source' => 'manual']);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => $this->email->from_email,
            'name' => 'Reply contact',
            'source' => 'manual',
            'status' => 'new',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
        $segment = Segment::create(['name' => 'Inbox segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Inbox template',
            'subject' => 'Inbox reply',
            'html_content' => '<p>Hello</p>',
        ]);
        $campaign = Campaign::create([
            'name' => 'Inbox campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $this->email->sender_identity_id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'inbox-module-' . uniqid(),
            'run_at' => now(),
            'status' => 'sent',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
        ]);
        $this->email->update(['contact_id' => $contact->id, 'campaign_recipient_id' => $recipient->id]);

        return $recipient->load('run');
    }

    private function inboxDataTable(array $filters = [])
    {
        $columns = collect(['id', 'from_email', 'subject', 'sender_identity', 'contact', 'status', 'received_at', 'action'])
            ->map(fn (string $name) => [
                'data' => $name,
                'name' => $name,
                'searchable' => in_array($name, ['id', 'from_email', 'subject'], true) ? 'true' : 'false',
                'orderable' => $name === 'received_at' ? 'true' : 'false',
            ])->all();

        $params = array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'columns' => $columns,
            'order' => [['column' => 6, 'dir' => 'desc']],
        ], $filters);

        return $this->actingAs($this->admin)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/admin/inbox?' . http_build_query($params));
    }
}
