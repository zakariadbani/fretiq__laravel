<?php

namespace Tests\Feature\Console;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for `sequences:repair-stalled` — the data-repair sweep for the
 * production scheduling stall (active enrollments left with
 * next_send_at=NULL, campaign_recipients stuck 'queued' on a terminal wave
 * run). See SequenceWaveService / PacedSequenceEnrollmentService for the
 * root-cause fix (next_send_at is no longer nulled for wave-managed
 * enrollments) — this command only repairs rows that stalled before that
 * fix shipped.
 */
class SequencesRepairStalledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['services.zoho.driver' => 'zoho']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_reports_counts_without_mutating_anything(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC')); // Monday
        $sequence = $this->twoStepSequence();
        $campaign = $this->wavedCampaign($sequence);
        $contact = $this->contact('dry-run@example.test');
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'active',
            'last_sent_at' => now(),
            'next_send_at' => null,
        ]);

        $this->artisan('sequences:repair-stalled')
            ->expectsOutputToContain('[DRY-RUN (pass --execute to apply)]')
            ->expectsOutputToContain('Enrollments rescheduled (next_send_at recomputed): 1')
            ->assertExitCode(0);

        $this->assertNull($enrollment->fresh()->next_send_at, 'Dry run must not mutate any row');
    }

    /**
     * Root-cause invariant repair: an active enrollment stuck with
     * next_send_at=NULL after sending step 1 gets a real next_send_at,
     * computed from last_sent_at + the next step's delay_days, shifted via
     * the same BusinessCalendarService the live pipeline uses.
     */
    public function test_execute_reschedules_stalled_enrollment_from_last_sent_at_and_step_delay(): void
    {
        // Monday 09:00 UTC + 3 days = Thursday — no weekend to shift across,
        // so the expected instant is exact (same anchoring technique as
        // SequenceProcessTest::test_enroll_and_send_first_step).
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC'));
        $sequence = $this->twoStepSequence(); // step1 delay=0, step2 delay=3
        $campaign = $this->wavedCampaign($sequence);
        $contact = $this->contact('reschedule@example.test');
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'active',
            'last_sent_at' => now(),
            'next_send_at' => null,
        ]);

        $this->artisan('sequences:repair-stalled', ['--execute' => true])
            ->expectsOutputToContain('[APPLIED]')
            ->expectsOutputToContain('Enrollments rescheduled (next_send_at recomputed): 1')
            ->assertExitCode(0);

        $enrollment->refresh();
        $this->assertSame('active', $enrollment->status);
        $this->assertNotNull($enrollment->next_send_at);
        $this->assertTrue($enrollment->next_send_at->equalTo(Carbon::parse('2026-08-06 09:00:00', 'UTC')));
    }

    /**
     * An active enrollment with no further step left is itself a data bug
     * (should already be 'completed') — repaired by transitioning to that
     * terminal status, satisfying "valid next_send_at OR terminal status".
     */
    public function test_execute_completes_enrollment_with_no_further_step(): void
    {
        $sequence = $this->oneStepSequence();
        $campaign = $this->wavedCampaign($sequence);
        $contact = $this->contact('exhausted@example.test');
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'active',
            'last_sent_at' => now(),
            'next_send_at' => null,
        ]);

        $this->artisan('sequences:repair-stalled', ['--execute' => true])
            ->expectsOutputToContain('Enrollments completed (no further step exists): 1')
            ->assertExitCode(0);

        $enrollment->refresh();
        $this->assertSame('completed', $enrollment->status);
        $this->assertNull($enrollment->next_send_at);
    }

    /**
     * Post-fix shape: next_send_at is a real timestamp (never NULL for a
     * wave-managed enrollment) but stuck 30h in the past, and no
     * CampaignRun/CampaignRecipient exists at all for the next step — the
     * wave was never (or no longer) tracked. This must be detected and
     * recomputed exactly like the legacy NULL shape.
     */
    public function test_execute_reschedules_enrollment_with_stale_past_next_send_at_and_no_live_wave_run(): void
    {
        // Monday 09:00 UTC + 3 days = Thursday — no weekend to shift across.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC'));
        $sequence = $this->twoStepSequence(); // step1 delay=0, step2 delay=3
        $campaign = $this->wavedCampaign($sequence);
        $contact = $this->contact('stale-past@example.test');
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'active',
            'last_sent_at' => now(),
            'next_send_at' => now()->subHours(30),
        ]);

        $this->artisan('sequences:repair-stalled', ['--execute' => true])
            ->expectsOutputToContain('[APPLIED]')
            ->expectsOutputToContain('Enrollments rescheduled (next_send_at recomputed): 1')
            ->assertExitCode(0);

        $enrollment->refresh();
        $this->assertSame('active', $enrollment->status);
        $this->assertTrue($enrollment->next_send_at->equalTo(Carbon::parse('2026-08-06 09:00:00', 'UTC')));
    }

    /**
     * The same stale-past shape, but a live (prepared) wave run still
     * tracks this exact enrollment via a queued CampaignRecipient — it is
     * simply not due yet (or delayed for a legitimate reason), so it must
     * be left alone rather than rescheduled out from under the live wave.
     */
    public function test_execute_leaves_stale_past_next_send_at_alone_when_its_wave_run_is_still_live(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC'));
        $sequence = $this->twoStepSequence();
        $campaign = $this->wavedCampaign($sequence);
        $step2 = $sequence->steps()->where('step_no', 2)->firstOrFail();
        $contact = $this->contact('stale-but-live@example.test');
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'active',
            'last_sent_at' => now(),
            'next_send_at' => now()->subHours(30),
        ]);
        $liveWave = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step2->id,
            'occurrence_key' => 'sequence-wave-000002',
            'run_at' => now()->addHour(),
            'status' => 'prepared',
            'driver_ref' => 'zoho-wave-pending',
        ]);
        CampaignRecipient::create([
            'campaign_run_id' => $liveWave->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
        ]);

        $this->artisan('sequences:repair-stalled', ['--execute' => true])
            ->expectsOutputToContain('Enrollments rescheduled (next_send_at recomputed): 0')
            ->assertExitCode(0);

        $enrollment->refresh();
        $this->assertSame('active', $enrollment->status);
        $this->assertTrue(
            $enrollment->next_send_at->lt(now()->subHours(24)),
            'a still-tracked (live wave) enrollment must be left alone even if overdue'
        );
    }

    /**
     * campaign_recipients stuck 'queued' on a terminal wave run: a stopped
     * enrollment's recipient is terminally skip-able (marked skipped with
     * the enrollment's own stopped_reason); a still-active, still-matching,
     * still-eligible enrollment's recipient is genuinely requeue-able and
     * must be left untouched.
     */
    public function test_execute_resolves_stuck_queued_recipients_per_business_rules(): void
    {
        $sequence = $this->oneStepSequence();
        $campaign = $this->wavedCampaign($sequence);
        $step = $sequence->steps()->firstOrFail();

        $stoppedContact = $this->contact('stopped@example.test');
        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $stoppedContact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'stopped',
            'stopped_reason' => 'invalid_email',
            'next_send_at' => null,
        ]);

        $activeContact = $this->contact('requeueable@example.test');
        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $activeContact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'active',
            'next_send_at' => now(),
        ]);

        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'driver_ref' => 'zoho',
        ]);
        $stoppedRecipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $stoppedContact->id,
            'status' => 'queued',
        ]);
        $requeueableRecipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $activeContact->id,
            'status' => 'queued',
        ]);

        $this->artisan('sequences:repair-stalled', ['--execute' => true])
            ->expectsOutputToContain('Recipients marked skipped (terminally ineligible/stale): 1')
            ->expectsOutputToContain('Recipients left queued (requeue-able, resolve on next run): 1')
            ->assertExitCode(0);

        $stoppedRecipient->refresh();
        $this->assertSame('skipped', $stoppedRecipient->status);
        $this->assertSame('invalid_email', $stoppedRecipient->skip_reason);

        $requeueableRecipient->refresh();
        $this->assertSame('queued', $requeueableRecipient->status, 'A still-active, still-eligible recipient must be left alone');
    }

    /**
     * Running --execute a second time on an already-repaired state must be a
     * true no-op: zero further mutations, same counts trend to zero (except
     * the still-legitimately-queued requeue-able row, which is expected to
     * keep reporting as "left alone" every run, not "resolved").
     */
    public function test_repair_command_is_idempotent_on_second_execute(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC')); // Monday
        $sequence = $this->twoStepSequence();
        $campaign = $this->wavedCampaign($sequence);

        $staleContact = $this->contact('idempotent-stale@example.test');
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $staleContact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 1,
            'status' => 'active',
            'last_sent_at' => now(),
            'next_send_at' => null,
        ]);

        $step = $sequence->steps()->where('step_no', 1)->firstOrFail();
        $stoppedContact = $this->contact('idempotent-stopped@example.test');
        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $stoppedContact->id,
            'campaign_id' => $campaign->id,
            'current_step' => 0,
            'status' => 'stopped',
            'stopped_reason' => 'suppressed',
            'next_send_at' => null,
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $step->id,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'driver_ref' => 'zoho',
        ]);
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $stoppedContact->id,
            'status' => 'queued',
        ]);

        $this->artisan('sequences:repair-stalled', ['--execute' => true])
            ->expectsOutputToContain('Enrollments rescheduled (next_send_at recomputed): 1')
            ->expectsOutputToContain('Recipients marked skipped (terminally ineligible/stale): 1')
            ->assertExitCode(0);

        $enrollmentStateAfterFirst = $enrollment->fresh()->getAttributes();
        $recipientStateAfterFirst = $recipient->fresh()->getAttributes();

        $this->artisan('sequences:repair-stalled', ['--execute' => true])
            ->expectsOutputToContain('Enrollments rescheduled (next_send_at recomputed): 0')
            ->expectsOutputToContain('Enrollments completed (no further step exists): 0')
            ->expectsOutputToContain('Recipients marked skipped (terminally ineligible/stale): 0')
            ->assertExitCode(0);

        $this->assertSame($enrollmentStateAfterFirst, $enrollment->fresh()->getAttributes(), 'Second run must not mutate the already-repaired enrollment');
        $this->assertSame($recipientStateAfterFirst, $recipient->fresh()->getAttributes(), 'Second run must not mutate the already-skipped recipient');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function template(string $name): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name' => $name,
            'subject' => 'Sujet',
            'html_content' => '<p>Bonjour</p>',
        ]);
    }

    private function oneStepSequence(): Sequence
    {
        $sequence = Sequence::create(['name' => 'Repair seq 1-step ' . uniqid(), 'is_active' => true]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 1,
            'delay_days' => 0,
            'template_id' => $this->template('Repair step1 ' . uniqid())->id,
            'subject' => 'Etape 1',
        ]);

        return $sequence;
    }

    private function twoStepSequence(): Sequence
    {
        $sequence = $this->oneStepSequence();
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 2,
            'delay_days' => 3,
            'template_id' => $this->template('Repair step2 ' . uniqid())->id,
            'subject' => 'Etape 2',
        ]);

        return $sequence;
    }

    private function contact(string $email): Contact
    {
        $company = Company::create([
            'name' => 'Repair Co ' . uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'name' => 'Repair Contact',
            'source' => 'manual',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ]);
    }

    /** A zoho-driven, paced sequence campaign — the shape that stalls in prod. */
    private function wavedCampaign(Sequence $sequence): Campaign
    {
        $segment = Segment::create(['name' => 'Repair segment ' . uniqid(), 'scope' => 'client']);
        $sender = SenderIdentity::create([
            'name' => 'Repair sender ' . uniqid(),
            'email' => uniqid('repair-sender') . '@example.test',
            'is_active' => true,
        ]);

        return Campaign::create([
            'name' => 'Repair campaign ' . uniqid(),
            'segment_id' => $segment->id,
            'sender_identity_id' => $sender->id,
            'sequence_id' => $sequence->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'delivery_channel' => null,
            'daily_company_limit' => 20,
            'next_run_at' => now()->addDay(),
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ]);
    }
}
