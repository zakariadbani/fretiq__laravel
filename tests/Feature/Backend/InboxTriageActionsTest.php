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
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepSend;
use App\Services\Inbox\ReplyMatchingService;
use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxTriageActionsTest extends TestCase
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

    public function test_interested_triage_is_idempotent(): void
    {
        [$email, $recipient] = $this->campaignReply();

        $this->actingAs($this->admin)
            ->post(route('admin.inbox.triage', $email), ['action' => 'interested'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)
            ->post(route('admin.inbox.triage', $email), ['action' => 'interested'])
            ->assertSessionHas('info', 'Ce message est deja traite. Aucune modification appliquee.')
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('demandes', 1);
        $this->assertSame(1, $recipient->run->fresh()->conversion_count);
    }

    public function test_not_interested_records_the_reply_without_a_demande(): void
    {
        [$email, $recipient] = $this->campaignReply();

        $this->actingAs($this->admin)
            ->post(route('admin.inbox.triage', $email), ['action' => 'not_interested'])
            ->assertRedirect(route('admin.inbox.view', $email));

        $this->assertSame('replied', $recipient->fresh()->status);
        $this->assertSame(InboxEmail::STATUS_IGNORE, $email->fresh()->status);
        $this->assertDatabaseCount('demandes', 0);
    }

    public function test_automatic_message_is_classified_without_recording_a_reply(): void
    {
        [$email, $recipient] = $this->campaignReply();

        $this->actingAs($this->admin)
            ->post(route('admin.inbox.triage', $email), ['action' => 'automatic'])
            ->assertRedirect(route('admin.inbox.view', $email));

        $this->assertSame('sent', $recipient->fresh()->status);
        $this->assertSame('automatic', $email->fresh()->triage_action);
        $this->assertSame(InboxEmail::STATUS_IGNORE, $email->fresh()->status);
    }

    public function test_interested_triage_also_requires_create_demandes_permission(): void
    {
        [$email] = $this->campaignReply();
        $operator = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $operator->givePermissionTo(['backend.access', 'view inbox', 'edit inbox']);

        $this->actingAs($operator)
            ->post(route('admin.inbox.triage', $email), ['action' => 'interested'])
            ->assertForbidden();

        $this->assertDatabaseCount('demandes', 0);
    }

    public function test_triage_actions_are_visible_to_an_authorized_operator(): void
    {
        [$email] = $this->campaignReply();

        $this->actingAs($this->admin)
            ->get(route('admin.inbox.view', $email))
            ->assertOk()
            ->assertSee('data-inbox-triage="interested"', false)
            ->assertSee('data-inbox-triage="not_interested"', false)
            ->assertSee('data-inbox-triage="automatic"', false);
    }

    public function test_fresh_email_without_contact_shows_safe_triage_guidance(): void
    {
        $email = InboxEmail::create([
            'message_id' => 'no-contact-guidance@example.test',
            'from_email' => 'unknown@example.test',
            'subject' => 'Unmatched reply',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'received_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.inbox.view', $email))
            ->assertOk()
            ->assertSeeText("Classez ce message, ou associez d'abord un contact pour créer une demande.")
            ->assertDontSee('met &agrave; jour son contact', false)
            ->assertDontSee('data-inbox-triage="interested"', false)
            ->assertSee('data-inbox-triage="not_interested"', false)
            ->assertSee('data-inbox-triage="automatic"', false);
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
        ])->load(['run', 'contact']);
        $email = InboxEmail::create([
            'sender_identity_id' => $sender->id,
            'message_id' => uniqid('inbox-', true).'@example.test',
            'from_email' => $contact->email,
            'subject' => 'Reponse prospect',
            'body_text' => 'Merci de me rappeler.',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'contact_id' => $contact->id,
            'campaign_recipient_id' => $recipient->id,
            'received_at' => now(),
        ]);

        return [$email, $recipient];
    }

    public function test_distinct_unattributed_emails_create_distinct_demandes_while_same_row_retry_is_idempotent(): void
    {
        [$sourceEmail, $recipient] = $this->campaignReply();
        $firstEmail = InboxEmail::create([
            'sender_identity_id' => $sourceEmail->sender_identity_id,
            'message_id' => uniqid('unattributed-first-', true).'@example.test',
            'from_email' => $recipient->contact->email,
            'subject' => 'Premier besoin sans attribution',
            'body_text' => 'Premier besoin.',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'contact_id' => $recipient->contact_id,
            'received_at' => now(),
        ]);
        $secondEmail = InboxEmail::create([
            'sender_identity_id' => $sourceEmail->sender_identity_id,
            'message_id' => uniqid('unattributed-second-', true).'@example.test',
            'from_email' => $recipient->contact->email,
            'subject' => 'Deuxieme besoin sans attribution',
            'body_text' => 'Deuxieme besoin.',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'contact_id' => $recipient->contact_id,
            'received_at' => now()->addSecond(),
        ]);

        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $firstEmail), ['action' => 'interested']);
        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $secondEmail), ['action' => 'interested']);
        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $firstEmail), ['action' => 'interested']);

        $firstEmail->refresh();
        $secondEmail->refresh();
        $this->assertDatabaseCount('demandes', 2);
        $this->assertNotNull($firstEmail->demande_id);
        $this->assertNotNull($secondEmail->demande_id);
        $this->assertNotSame($firstEmail->demande_id, $secondEmail->demande_id);
    }

    public function test_distinct_emails_for_the_same_attributed_reply_reuse_one_demande(): void
    {
        [$firstEmail, $recipient] = $this->campaignReply();
        $secondEmail = $firstEmail->replicate();
        $secondEmail->message_id = uniqid('inbox-duplicate-', true).'@example.test';
        $secondEmail->save();

        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $firstEmail), ['action' => 'interested']);
        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $secondEmail), ['action' => 'interested']);

        $firstEmail->refresh();
        $secondEmail->refresh();
        $this->assertDatabaseCount('demandes', 1);
        $this->assertSame($firstEmail->demande_id, $secondEmail->demande_id);
        $this->assertSame(1, $recipient->run->fresh()->conversion_count);
    }

    public function test_interested_triage_cannot_be_reversed_by_a_later_action(): void
    {
        [$email, $recipient] = $this->campaignReply();
        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $email), ['action' => 'interested']);
        $this->actingAs($this->admin)
            ->post(route('admin.inbox.triage', $email), ['action' => 'not_interested'])
            ->assertSessionHas('info', 'Ce message est deja traite. Aucune modification appliquee.')
            ->assertSessionMissing('success');

        $email->refresh();
        $this->assertSame('interested', $email->triage_action);
        $this->assertSame(InboxEmail::STATUS_TRAITE, $email->status);
        $this->assertSame('replied', $recipient->fresh()->status);
        $this->assertDatabaseCount('demandes', 1);
    }

    public function test_not_interested_triage_cannot_be_reversed_by_a_later_action(): void
    {
        [$email, $recipient] = $this->campaignReply();
        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $email), ['action' => 'not_interested']);
        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $email), ['action' => 'interested']);

        $email->refresh();
        $this->assertSame('not_interested', $email->triage_action);
        $this->assertSame(InboxEmail::STATUS_IGNORE, $email->status);
        $this->assertSame('unqualified', $recipient->contact->fresh()->status);
        $this->assertDatabaseCount('demandes', 0);
    }

    public function test_interested_sequence_reply_uses_step_send_attribution(): void
    {
        [$sourceEmail, $recipient] = $this->campaignReply();
        $sequence = Sequence::create(['name' => 'Attributed sequence', 'is_active' => true]);
        $campaign = $recipient->run->campaign;
        $campaign->update(['sequence_id' => $sequence->id]);
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $recipient->contact_id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'active',
        ]);
        $send = SequenceStepSend::create([
            'enrollment_id' => $enrollment->id,
            'campaign_run_id' => $recipient->campaign_run_id,
            'step_no' => 1,
            'status' => 'sent',
        ]);
        $email = InboxEmail::create([
            'sender_identity_id' => $sourceEmail->sender_identity_id,
            'message_id' => uniqid('sequence-reply-', true).'@example.test',
            'in_reply_to' => '<sequence-send-'.$send->id.'@fretiq.local>',
            'from_email' => $recipient->contact->email,
            'subject' => 'Sequence reply',
            'body_text' => 'Interested',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'received_at' => now(),
        ]);

        app(ReplyMatchingService::class)->match($email);

        $this->actingAs($this->admin)->post(route('admin.inbox.triage', $email), ['action' => 'interested']);

        $email->refresh();
        $this->assertSame($send->id, $email->sequence_step_send_id);
        $this->assertDatabaseHas('demandes', [
            'id' => $email->demande_id,
            'contact_id' => $recipient->contact_id,
            'campaign_id' => $campaign->id,
            'campaign_run_id' => $recipient->campaign_run_id,
            'sequence_id' => $sequence->id,
        ]);
        $this->assertSame('replied', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->replied_at);

        \App\Jobs\SyncCampaignStatsJob::dispatchSync($recipient->campaign_run_id);
        $this->assertSame(1, $recipient->run->fresh()->stats_replied);
    }
}
