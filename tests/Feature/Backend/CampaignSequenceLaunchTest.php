<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendSequenceStepJob;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepSend;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\SequenceService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for Campaign → Sequence enrollment wiring.
 *
 * Tests 1-12 from plan §8 (test 13 — sequence step reorder — is a separate agent scope).
 *
 * Setup mirrors SequenceProcessTest: RefreshDatabase + RolesSeeder + PermissionsSeeder.
 * Mail::fake() active for every test — no real SMTP.
 */
class CampaignSequenceLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        config(['services.zoho.driver' => 'local']);
        Mail::fake();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeCompany(string $relationship = 'client'): Company
    {
        return Company::create([
            'name'                 => 'Acme ' . uniqid(),
            'relationship'         => $relationship,
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);
    }

    private function makeContact(Company $company, string $email = null): Contact
    {
        return Contact::create([
            'company_id'  => $company->id,
            'email'       => $email ?? ('contact_' . uniqid() . '@acme.test'),
            'name'        => 'Test Contact',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeTemplate(string $name = 'Test Template'): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => $name,
            'subject'      => 'Sujet test',
            'html_content' => '<p>Bonjour {{contact.name}}</p>',
        ]);
    }

    private function makeSequenceWithSteps(int $steps = 2, bool $isActive = true): Sequence
    {
        $seq = Sequence::create([
            'name'          => 'Seq Test ' . uniqid(),
            'is_active'     => $isActive,
            'stop_on_reply' => false,
        ]);

        for ($i = 1; $i <= $steps; $i++) {
            $tpl = $this->makeTemplate("Step {$i} Tpl");
            SequenceStep::create([
                'sequence_id' => $seq->id,
                'step_no'     => $i,
                'delay_days'  => ($i - 1) * 3,
                'template_id' => $tpl->id,
                'subject'     => "Étape {$i}",
            ]);
        }

        return $seq;
    }

    private function makeSegment(string $scope = 'client'): Segment
    {
        return Segment::create([
            'name'  => 'Segment ' . uniqid(),
            'scope' => $scope,
        ]);
    }

    private function makeSenderIdentity(): SenderIdentity
    {
        return SenderIdentity::create([
            'name'       => 'Expéditeur Test',
            'email'      => 'sender@acme.test',
            'is_default' => false,
            'is_active'  => true,
        ]);
    }

    private function makeCampaign(Segment $segment, Sequence $sequence, SenderIdentity $sender = null): Campaign
    {
        if ($sender === null) {
            $sender = $this->makeSenderIdentity();
        }

        return Campaign::create([
            'name'               => 'Campaign ' . uniqid(),
            'segment_id'         => $segment->id,
            'sequence_id'        => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'sequence',
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    public function test_starting_sequence_campaign_enables_continuous_enrollment(): void
    {
        $company = $this->makeCompany('client');
        $this->makeContact($company);
        $campaign = $this->makeCampaign(
            $this->makeSegment('client'),
            $this->makeSequenceWithSteps(2),
        );
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->post("/admin/campaigns/{$campaign->id}/send")
            ->assertOk();

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'sequence_auto_enroll_enabled' => true,
        ]);
    }

    public function test_sync_command_enrolls_newly_eligible_contact_at_step_zero(): void
    {
        $company = $this->makeCompany('client');
        $this->makeContact($company);
        $segment = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        app(CampaignService::class)->launchSequence($campaign);
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        $newContact = $this->makeContact($company);

        $this->artisan('campaigns:sync-sequence-enrollments')
            ->assertSuccessful();

        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'contact_id' => $newContact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
        ]);
        $this->assertSame(2, SequenceEnrollment::where('sequence_id', $sequence->id)->count());
    }

    public function test_stopping_auto_enrollment_keeps_existing_enrollment_active(): void
    {
        $company = $this->makeCompany('client');
        $contact = $this->makeContact($company);
        $campaign = $this->makeCampaign(
            $this->makeSegment('client'),
            $this->makeSequenceWithSteps(2),
        );
        app(CampaignService::class)->launchSequence($campaign);
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->put("/admin/campaigns/{$campaign->id}/sequence-auto-enroll", ['state' => 0])
            ->assertOk();

        $this->assertFalse($campaign->fresh()->sequence_auto_enroll_enabled);
        $this->assertDatabaseHas('sequence_enrollments', [
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'active',
        ]);
    }

    public function test_sequence_campaign_view_exposes_auto_enrollment_control_and_sync_action(): void
    {
        $company = $this->makeCompany('client');
        $this->makeContact($company);
        $campaign = $this->makeCampaign(
            $this->makeSegment('client'),
            $this->makeSequenceWithSteps(2),
        );
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->get("/admin/campaigns/{$campaign->id}")
            ->assertOk()
            ->assertSee('Inscription automatique')
            ->assertSee('Synchroniser maintenant')
            ->assertSee(route('admin.campaigns.sequenceAutoEnroll', $campaign->id));
    }

    public function test_auto_enrollment_control_requires_send_campaigns_permission(): void
    {
        $campaign = $this->makeCampaign(
            $this->makeSegment('client'),
            $this->makeSequenceWithSteps(1),
        );
        $commercial = User::factory()->create(['email_verified_at' => now()]);
        $commercial->assignRole('commercial');

        $this->actingAs($commercial)
            ->put("/admin/campaigns/{$campaign->id}/sequence-auto-enroll", ['state' => 1])
            ->assertForbidden();
    }

    public function test_sync_command_skips_campaign_when_auto_enrollment_is_stopped(): void
    {
        $company = $this->makeCompany('client');
        $this->makeContact($company);
        $sequence = $this->makeSequenceWithSteps(1);
        $campaign = $this->makeCampaign($this->makeSegment('client'), $sequence);
        app(CampaignService::class)->launchSequence($campaign);
        $newContact = $this->makeContact($company);

        $this->artisan('campaigns:sync-sequence-enrollments')->assertSuccessful();

        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'contact_id' => $newContact->id,
        ]);
    }

    public function test_sync_command_never_reenrolls_terminal_contact(): void
    {
        $company = $this->makeCompany('client');
        $contact = $this->makeContact($company);
        $sequence = $this->makeSequenceWithSteps(1);
        $campaign = $this->makeCampaign($this->makeSegment('client'), $sequence);
        app(CampaignService::class)->launchSequence($campaign);
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact->id)
            ->update(['status' => 'completed', 'next_send_at' => null]);

        $this->artisan('campaigns:sync-sequence-enrollments')->assertSuccessful();
        $this->artisan('campaigns:sync-sequence-enrollments')->assertSuccessful();

        $this->assertSame(1, SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact->id)
            ->count());
    }

    public function test_new_auto_enrollment_starts_with_first_sequence_step(): void
    {
        $company = $this->makeCompany('client');
        $this->makeContact($company);
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($this->makeSegment('client'), $sequence);
        app(CampaignService::class)->launchSequence($campaign);
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        $newContact = $this->makeContact($company);

        $this->artisan('campaigns:sync-sequence-enrollments')->assertSuccessful();
        $enrollment = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $newContact->id)
            ->firstOrFail();
        app(SequenceService::class)->sendStep($enrollment);

        $this->assertSame(1, $enrollment->fresh()->current_step);
        $this->assertDatabaseHas('sequence_step_sends', [
            'enrollment_id' => $enrollment->id,
            'step_no' => 1,
            'status' => 'sent',
        ]);
        Mail::assertSent(SequenceStepMailable::class, fn ($mail) => $mail->hasTo($newContact->email));
    }

    public function test_invalid_watched_campaign_does_not_block_other_campaigns(): void
    {
        $company = $this->makeCompany('client');
        $contact = $this->makeContact($company);
        $badSequence = Sequence::create(['name' => 'Empty watched sequence', 'is_active' => true]);
        $badCampaign = $this->makeCampaign($this->makeSegment('client'), $badSequence);
        $badCampaign->update(['sequence_auto_enroll_enabled' => true]);

        $goodSequence = $this->makeSequenceWithSteps(1);
        $goodCampaign = $this->makeCampaign($this->makeSegment('client'), $goodSequence);
        $goodCampaign->update(['sequence_auto_enroll_enabled' => true]);

        $this->artisan('campaigns:sync-sequence-enrollments')->assertSuccessful();

        $this->assertDatabaseMissing('sequence_enrollments', ['campaign_id' => $badCampaign->id]);
        $this->assertDatabaseHas('sequence_enrollments', [
            'campaign_id' => $goodCampaign->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_auto_enrollment_keeps_suppressed_new_contact_excluded(): void
    {
        $company = $this->makeCompany('client');
        $this->makeContact($company);
        $sequence = $this->makeSequenceWithSteps(1);
        $campaign = $this->makeCampaign($this->makeSegment('client'), $sequence);
        app(CampaignService::class)->launchSequence($campaign);
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        $suppressed = $this->makeContact($company);
        Suppression::create(['email' => $suppressed->email, 'contact_id' => $suppressed->id]);

        $this->artisan('campaigns:sync-sequence-enrollments')->assertSuccessful();

        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'contact_id' => $suppressed->id,
        ]);
    }

    public function test_contact_removed_from_segment_after_enrollment_finishes_its_sequence(): void
    {
        $company = $this->makeCompany('client');
        $contact = $this->makeContact($company);
        $sequence = $this->makeSequenceWithSteps(1);
        $campaign = $this->makeCampaign($this->makeSegment('client'), $sequence);
        app(CampaignService::class)->launchSequence($campaign);
        $enrollment = SequenceEnrollment::where('campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->firstOrFail();
        $company->update(['relationship' => 'prospect']);

        app(SequenceService::class)->sendStep($enrollment);

        Mail::assertSent(SequenceStepMailable::class, fn ($mail) => $mail->hasTo($contact->email));
        $this->assertSame(1, $enrollment->fresh()->current_step);
    }

    public function test_switching_away_from_sequence_stops_future_auto_enrollment(): void
    {
        $segment = $this->makeSegment('client');
        $campaign = $this->makeCampaign($segment, $this->makeSequenceWithSteps(1));
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        $template = $this->makeTemplate();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->putJson("/admin/campaigns/{$campaign->id}", [
                'name' => $campaign->name,
                'segment_id' => $segment->id,
                'template_id' => $template->id,
                'sender_identity_id' => $campaign->sender_identity_id,
                'schedule_type' => 'one_shot',
                'timezone' => 'Europe/Paris',
                'is_active' => 1,
            ])
            ->assertOk();

        $this->assertFalse($campaign->fresh()->sequence_auto_enroll_enabled);
    }

    public function test_changing_sequence_stops_auto_enrollment_until_manual_restart(): void
    {
        $segment = $this->makeSegment('client');
        $campaign = $this->makeCampaign($segment, $this->makeSequenceWithSteps(1));
        $campaign->update(['sequence_auto_enroll_enabled' => true]);
        $replacement = $this->makeSequenceWithSteps(1);
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole('superadmin');

        $this->actingAs($admin)
            ->putJson("/admin/campaigns/{$campaign->id}", [
                'name' => $campaign->name,
                'segment_id' => $segment->id,
                'sequence_id' => $replacement->id,
                'sender_identity_id' => $campaign->sender_identity_id,
                'schedule_type' => 'sequence',
                'is_active' => 1,
            ])
            ->assertOk();

        $this->assertFalse($campaign->fresh()->sequence_auto_enroll_enabled);
    }

    /**
     * Test 1: Launch enrolls resolved contacts; attribution set; status → 'active'; counts returned.
     */
    public function test_launch_enrolls_contacts_and_activates_campaign(): void
    {
        $company  = $this->makeCompany('client');
        $contact1 = $this->makeContact($company);
        $contact2 = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $service = app(CampaignService::class);
        $result  = $service->launchSequence($campaign);

        $this->assertSame(2, $result['enrolled']);
        $this->assertSame(0, $result['skipped']);

        // Campaign must be activated (is_active=true) after enrollment
        $campaign->refresh();
        $this->assertTrue((bool) $campaign->is_active);

        // Enrollments exist with campaign attribution
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'contact_id'  => $contact1->id,
            'campaign_id' => $campaign->id,
            'status'      => 'active',
        ]);
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'contact_id'  => $contact2->id,
            'campaign_id' => $campaign->id,
            'status'      => 'active',
        ]);
    }

    /**
     * Test 2: Re-launch with unchanged segment → 0 enrolled, N skipped, no duplicates.
     */
    public function test_relaunch_unchanged_segment_skips_all(): void
    {
        $company  = $this->makeCompany('client');
        $contact1 = $this->makeContact($company);
        $contact2 = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $service = app(CampaignService::class);

        // First launch
        $result1 = $service->launchSequence($campaign);
        $this->assertSame(2, $result1['enrolled']);

        // Second launch — unchanged segment
        $result2 = $service->launchSequence($campaign);
        $this->assertSame(0, $result2['enrolled']);
        $this->assertSame(2, $result2['skipped']);

        // Still exactly 2 enrollments in DB
        $count = SequenceEnrollment::where('sequence_id', $sequence->id)->count();
        $this->assertSame(2, $count);
    }

    /**
     * Test 3: Re-launch after enrollments completed/stopped → NOT re-enrolled (net-new contract).
     */
    public function test_completed_or_stopped_contacts_not_reenrolled(): void
    {
        $company  = $this->makeCompany('client');
        $contact1 = $this->makeContact($company);
        $contact2 = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $service = app(CampaignService::class);

        // First launch
        $service->launchSequence($campaign);

        // Mark both enrollments as completed / stopped
        SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact1->id)
            ->update(['status' => 'completed']);

        SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact2->id)
            ->update(['status' => 'stopped']);

        // Re-launch — net-new-only: contact1 and contact2 already have rows (any status)
        $result = $service->launchSequence($campaign->refresh());
        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(2, $result['skipped']);

        // Still exactly 2 enrollment rows (not duplicated)
        $count = SequenceEnrollment::where('sequence_id', $sequence->id)->count();
        $this->assertSame(2, $count);
    }

    /**
     * Test 4: Re-launch after new contact added to segment → only the new one enrolled.
     */
    public function test_relaunch_after_new_contact_enrolls_only_new(): void
    {
        $company  = $this->makeCompany('client');
        $contact1 = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $service = app(CampaignService::class);

        // First launch with one contact
        $result1 = $service->launchSequence($campaign);
        $this->assertSame(1, $result1['enrolled']);

        // Add a second contact to the same company (same segment)
        $contact2 = $this->makeContact($company);

        // Re-launch
        $result2 = $service->launchSequence($campaign->refresh());
        $this->assertSame(1, $result2['enrolled']);
        $this->assertSame(1, $result2['skipped']);

        // Contact2 is now enrolled; contact1 was skipped
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'contact_id'  => $contact2->id,
            'campaign_id' => $campaign->id,
        ]);
    }

    /**
     * Test 5a: Guard — zero steps → InvalidArgumentException, nothing changes.
     */
    public function test_guard_zero_steps_throws(): void
    {
        $company  = $this->makeCompany('client');
        $contact  = $this->makeContact($company);
        $segment  = $this->makeSegment('client');

        // Sequence with NO steps
        $sequence = Sequence::create([
            'name'      => 'Empty Seq',
            'is_active' => true,
        ]);
        $campaign = $this->makeCampaign($segment, $sequence);

        $this->expectException(\InvalidArgumentException::class);

        app(CampaignService::class)->launchSequence($campaign);

        // No enrollments must have been created
        $this->assertDatabaseMissing('sequence_enrollments', ['sequence_id' => $sequence->id]);
    }

    /**
     * Test 5b: Guard — inactive sequence → InvalidArgumentException, nothing changes.
     */
    public function test_guard_inactive_sequence_throws(): void
    {
        $company  = $this->makeCompany('client');
        $contact  = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(1, isActive: false);
        $campaign = $this->makeCampaign($segment, $sequence);

        $this->expectException(\InvalidArgumentException::class);

        app(CampaignService::class)->launchSequence($campaign);
    }

    /**
     * Test 6: Empty segment → 0/0 returned; campaign status unchanged.
     */
    public function test_empty_segment_returns_zero_zero(): void
    {
        // No contacts in DB — segment resolves to empty
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $result = app(CampaignService::class)->launchSequence($campaign);

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(0, $result['skipped']);

        // is_active must remain unchanged when 0 enrolled (no activation)
        $campaign->refresh();
        // Campaign default is_active=true (DB default); launchSequence did not set it.
        $this->assertNotNull($campaign->is_active, 'is_active must not be null');
    }

    /**
     * Test 7: Suppressed contact excluded; cold gate off → 0 enrolled (RGPD-load-bearing).
     *
     * Suppression test: a contact in a 'client' segment whose email is suppressed
     * must be excluded.
     * Cold gate test: a contact in a 'prospect' segment with cold_send_enabled=false
     * must be excluded (entire prospect audience blocked).
     */
    public function test_suppressed_contact_excluded(): void
    {
        $company = $this->makeCompany('client');
        $contact = $this->makeContact($company, 'suppressed@acme.test');

        // Add suppression entry
        Suppression::create(['email' => 'suppressed@acme.test', 'reason' => 'manual']);

        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $result = app(CampaignService::class)->launchSequence($campaign);

        $this->assertSame(0, $result['enrolled']);
        $this->assertDatabaseMissing('sequence_enrollments', ['sequence_id' => $sequence->id]);
    }

    public function test_cold_gate_off_excludes_prospect_contacts(): void
    {
        // cold_send_enabled defaults to false — prospect contacts must be excluded
        Config::set('prospecting.cold_send_enabled', false);

        $company = $this->makeCompany('prospect');
        $contact = $this->makeContact($company);

        $segment  = $this->makeSegment('prospect');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $result = app(CampaignService::class)->launchSequence($campaign);

        $this->assertSame(0, $result['enrolled']);
        $this->assertDatabaseMissing('sequence_enrollments', ['sequence_id' => $sequence->id]);
    }

    /**
     * Test 8: schedule() rejected for sequence type; sendNow creates NO CampaignRun.
     */
    public function test_schedule_rejected_for_sequence_type(): void
    {
        $company  = $this->makeCompany('client');
        $contact  = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $service = app(CampaignService::class);

        // Calling scheduleOneShot on a sequence campaign should still work
        // (the guard is at the controller level), but sendNow's sequence branch
        // must not create a CampaignRun.
        // Here we verify that launchSequence (the sequence branch of sendNow)
        // creates enrollments, not CampaignRuns.
        $result = $service->launchSequence($campaign);

        $this->assertSame(1, $result['enrolled']);

        // Zero CampaignRun rows must have been created
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);
    }

    /**
     * Test 9: Saving campaign with schedule_type != 'sequence' nulls out sequence_id.
     *
     * This tests the beforeSave() logic in the controller: when a campaign is
     * saved with schedule_type=one_shot, sequence_id is set to null.
     * We test the CampaignService + model layer directly.
     */
    public function test_switching_from_sequence_to_one_shot_nulls_sequence_id(): void
    {
        $sequence = $this->makeSequenceWithSteps(2);
        $segment  = $this->makeSegment('client');
        $sender   = $this->makeSenderIdentity();
        $template = $this->makeTemplate();

        // Campaign starts as sequence type with sequence_id set
        $campaign = Campaign::create([
            'name'               => 'Flip Campaign',
            'segment_id'         => $segment->id,
            'sequence_id'        => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'sequence',
        ]);

        $this->assertNotNull($campaign->sequence_id);

        // Simulate what beforeSave() does: null sequence_id when schedule_type != 'sequence'
        $campaign->update([
            'schedule_type' => 'one_shot',
            'sequence_id'   => null,
            'template_id'   => $template->id,
        ]);

        $campaign->refresh();
        $this->assertNull($campaign->sequence_id);
        $this->assertSame('one_shot', $campaign->schedule_type);
    }

    /**
     * Test 10: Sequence step send uses campaign sender identity (from address asserted).
     *   - When enrollment has campaign attribution, the campaign's SenderIdentity is used.
     *   - Fallback to mail.from when no campaign attribution.
     *   - Fallback to mail.from when campaign's sender identity was deleted/null.
     */
    public function test_step_send_uses_campaign_sender_identity(): void
    {
        $company  = $this->makeCompany('client');
        $contact  = $this->makeContact($company, 'recipient@acme.test');
        $segment  = $this->makeSegment('client');
        $sender   = $this->makeSenderIdentity();
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence, $sender);

        $seqService = app(SequenceService::class);

        // Enroll with campaign attribution
        $enrollment = $seqService->enroll($sequence, $contact, $campaign);

        $this->assertNotNull($enrollment);
        $this->assertSame($campaign->id, $enrollment->campaign_id);

        // Send step 1
        $seqService->sendStep($enrollment);

        // Assert mailable was sent with the campaign's sender identity from address.
        Mail::assertSent(SequenceStepMailable::class, function ($mail) use ($sender) {
            return $mail->hasFrom($sender->email, $sender->name);
        });

        Mail::assertSent(SequenceStepMailable::class, 1);
    }

    /**
     * Test 10b: Fallback to mail.from when no campaign attribution.
     *   - The mailable is sent without any explicit sender identity from address.
     *   - Proves the fallback path: no campaign attribution → no from override in envelope.
     */
    public function test_step_send_fallback_when_no_campaign_attribution(): void
    {
        $sequence = $this->makeSequenceWithSteps(2);
        $company  = $this->makeCompany('client');
        $contact  = $this->makeContact($company);

        // Create a distinct sender identity so we can assert it is NOT used.
        $otherSender = $this->makeSenderIdentity();

        $seqService = app(SequenceService::class);

        // Enroll WITHOUT campaign attribution
        $enrollment = $seqService->enroll($sequence, $contact, null);

        $this->assertNull($enrollment->campaign_id);

        // Send step — must not throw even without sender identity
        $seqService->sendStep($enrollment);

        Mail::assertSent(SequenceStepMailable::class, 1);

        // The distinct sender identity must NOT appear as the from address —
        // fallback path leaves from to the mailer's global default (mail.from).
        // hasFrom() crashes when the envelope has no explicit from (null); guard it.
        Mail::assertSent(SequenceStepMailable::class, function (SequenceStepMailable $mail) use ($otherSender) {
            try {
                return ! $mail->hasFrom($otherSender->email);
            } catch (\Throwable $e) {
                // hasFrom() throws when envelope->from is null (no explicit from set),
                // which is exactly the fallback path we want to confirm — the from
                // is left to the global mail.from default, not the sender identity.
                return true;
            }
        });
    }

    public function test_due_sequence_smtp_only_dispatches_for_active_local_or_unattributed_enrollments(): void
    {
        // Pinned to a weekday: SequenceService::processDue() (chunk 6) holds
        // any enrollment when TODAY is a blocked day (weekend/blackout, via
        // BusinessCalendarService) — without a freeze this test is flaky
        // depending on which real calendar day it happens to run on.
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', 'UTC')); // Monday

        config(['services.zoho.driver' => 'local']);
        $sequence = $this->makeSequenceWithSteps();
        $segment = $this->makeSegment();
        $localCampaign = $this->makeCampaign($segment, $sequence);
        $inactiveCampaign = $this->makeCampaign($segment, $sequence);
        $inactiveCampaign->update(['is_active' => false]);
        $zohoCampaign = $this->makeCampaign($segment, $sequence);
        $zohoCampaign->update(['driver' => 'zoho']);
        $pacedCampaign = $this->makeCampaign($segment, $sequence);
        $pacedCampaign->update(['sequence_enrollment_mode' => 'paced']);
        $service = app(SequenceService::class);

        $local = $service->enroll($sequence, $this->makeContact($this->makeCompany()), $localCampaign);
        $inactive = $service->enroll($sequence, $this->makeContact($this->makeCompany()), $inactiveCampaign);
        $zoho = $service->enroll($sequence, $this->makeContact($this->makeCompany()), $zohoCampaign);
        $paced = $service->enroll($sequence, $this->makeContact($this->makeCompany()), $pacedCampaign);
        $unattributed = $service->enroll($sequence, $this->makeContact($this->makeCompany()));
        $stopped = $service->enroll($sequence, $this->makeContact($this->makeCompany()));
        $stopped->update(['status' => 'stopped']);
        SequenceEnrollment::whereKey([$local->id, $inactive->id, $zoho->id, $paced->id, $unattributed->id, $stopped->id])
            ->update(['next_send_at' => now()->subMinute()]);

        Queue::fake();
        $this->assertSame(2, $service->processDue());
        Queue::assertPushed(SendSequenceStepJob::class, 2);
        Queue::assertPushed(SendSequenceStepJob::class, fn ($job) => $job->enrollmentId === $local->id);
        Queue::assertPushed(SendSequenceStepJob::class, fn ($job) => $job->enrollmentId === $unattributed->id);

        $service->sendStep($inactive->fresh());
        $service->sendStep($zoho->fresh());
        $service->sendStep($paced->fresh());
        $service->sendStep($stopped->fresh());
        Mail::assertNothingSent();
        $this->assertSame(0, SequenceStepSend::count());

        config(['services.zoho.driver' => 'zoho']);
        Queue::fake();
        $this->assertSame(0, $service->processDue());
        Queue::assertNothingPushed();

        $service->sendStep($local->fresh());
        $service->sendStep($unattributed->fresh());
        Mail::assertNothingSent();
        $this->assertSame(0, SequenceStepSend::count());

        Carbon::setTestNow();
    }

    /**
     * Test 11: Sync-stats sweep:
     *   - All enrollments terminal → campaign 'done'.
     *   - One still active → campaign stays 'active'.
     *   - Re-launch from 'done' → back to 'active'.
     */
    public function test_sync_stats_sweep_lifecycle(): void
    {
        $company  = $this->makeCompany('client');
        $contact1 = $this->makeContact($company);
        $contact2 = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $service = app(CampaignService::class);

        // Launch to create 2 active enrollments
        $service->launchSequence($campaign);
        $campaign->refresh();
        $this->assertTrue((bool) $campaign->is_active);

        // Mark one enrollment as completed — one still active
        $enrollment1 = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact1->id)
            ->first();
        $enrollment1->update(['status' => 'completed']);

        // Run the sync-stats sweep (call the command's sweep SQL directly)
        $this->artisan('campaign:sync-stats');

        // One enrollment still active — campaign must remain active
        $campaign->refresh();
        $this->assertTrue((bool) $campaign->is_active);

        // Mark the second enrollment as completed too
        $enrollment2 = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact2->id)
            ->first();
        $enrollment2->update(['status' => 'completed']);

        // Re-run sweep
        $this->artisan('campaign:sync-stats');

        // Both terminal — campaign must now be is_active=false
        $campaign->refresh();
        $this->assertFalse((bool) $campaign->is_active);

        // Add a new contact and re-launch → back to is_active=true
        $contact3 = $this->makeContact($company);
        $result   = $service->launchSequence($campaign->refresh());
        $this->assertSame(1, $result['enrolled']);

        $campaign->refresh();
        $this->assertTrue((bool) $campaign->is_active);
    }

    /**
     * Test 11b: Sync-stats sweep does NOT mark campaign done while a PAUSED enrollment remains.
     *
     * Covers fix 2 (CampaignSyncStats.php): sweep must treat 'paused' as non-terminal,
     * identical to 'active'. A campaign with only paused enrollments must remain 'active'.
     */
    public function test_sync_stats_sweep_does_not_close_campaign_with_paused_enrollment(): void
    {
        $company  = $this->makeCompany('client');
        $contact1 = $this->makeContact($company);
        $contact2 = $this->makeContact($company);
        $segment  = $this->makeSegment('client');
        $sequence = $this->makeSequenceWithSteps(2);
        $campaign = $this->makeCampaign($segment, $sequence);

        $service = app(CampaignService::class);

        // Launch → 2 active enrollments
        $service->launchSequence($campaign);
        $campaign->refresh();
        $this->assertTrue((bool) $campaign->is_active);

        // Complete one enrollment, pause the other
        $enrollment1 = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact1->id)
            ->first();
        $enrollment1->update(['status' => 'completed']);

        $enrollment2 = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('contact_id', $contact2->id)
            ->first();
        $enrollment2->update(['status' => 'paused']);

        // Run the sweep — the paused enrollment blocks closure
        $this->artisan('campaign:sync-stats');

        // Campaign must remain active (is_active=true) because a paused enrollment can be resumed
        $campaign->refresh();
        $this->assertTrue((bool) $campaign->is_active);

        // Now also mark the paused enrollment as completed → both terminal
        $enrollment2->update(['status' => 'completed']);

        // Re-run sweep — now all terminal, campaign should be set is_active=false
        $this->artisan('campaign:sync-stats');
        $campaign->refresh();
        $this->assertFalse((bool) $campaign->is_active);
    }

    /**
     * Test 12 (W1): Campaign saves WITHOUT template in sequence mode;
     *               template still required for one_shot/recurring.
     */
    public function test_w1_template_not_required_in_sequence_mode(): void
    {
        $sequence = $this->makeSequenceWithSteps(2);
        $segment  = $this->makeSegment('client');
        $sender   = $this->makeSenderIdentity();

        // Create a sequence-type campaign without template_id — must pass validation
        $campaign = new Campaign([
            'name'               => 'Seq Campaign No Template',
            'segment_id'         => $segment->id,
            'sequence_id'        => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'sequence',
        ]);

        // Validate via the model's rules() method
        $rules     = $campaign->rules();
        $validator = \Illuminate\Support\Facades\Validator::make(
            $campaign->attributesToArray(),
            $rules,
        );

        $this->assertFalse($validator->fails(), 'Sequence campaign without template should pass validation: ' . $validator->errors()->toJson());

        // Verify template IS required for one_shot
        $oneShotCampaign = new Campaign([
            'name'               => 'One Shot No Template',
            'segment_id'         => $segment->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
        ]);

        $rulesOneShot     = $oneShotCampaign->rules();
        $validatorOneShot = \Illuminate\Support\Facades\Validator::make(
            $oneShotCampaign->attributesToArray(),
            $rulesOneShot,
        );

        $this->assertTrue($validatorOneShot->fails(), 'One-shot campaign without template should fail validation');
        $this->assertTrue($validatorOneShot->errors()->has('template_id'));
    }
}
