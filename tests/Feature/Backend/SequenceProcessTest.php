<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendSequenceStepJob;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepSend;
use App\Models\Setting;
use App\Services\Campaign\SequenceService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Service-level tests for SequenceService.
 *
 * Mail::fake() is active for every test — no real SMTP calls.
 */
class SequenceProcessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeContact(string $email = 'j@acme.test'): Contact
    {
        $co = Company::create([
            'name'                 => 'Acme',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'J',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeTemplate(string $name = 'Template Seq'): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => $name,
            'subject'      => 'Sujet séquence',
            'html_content' => '<p>Bonjour {{contact.name}}</p>',
        ]);
    }

    /**
     * Build a sequence with 2 steps.
     *   step 1: delay_days=0 (send immediately)
     *   step 2: delay_days=3 (send 3 days later)
     */
    private function makeTwoStepSequence(bool $stopOnReply = false): Sequence
    {
        $seq = Sequence::create([
            'name'          => 'Seq 2 étapes',
            'is_active'     => true,
            'stop_on_reply' => $stopOnReply,
        ]);

        $tpl = $this->makeTemplate('Step1 Tpl');
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $tpl->id,
            'subject'     => 'Étape 1',
        ]);

        $tpl2 = $this->makeTemplate('Step2 Tpl');
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 2,
            'delay_days'  => 3,
            'template_id' => $tpl2->id,
            'subject'     => 'Étape 2',
        ]);

        return $seq;
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * enroll() creates an active enrollment with current_step=0 and next_send_at~now.
     * sendStep() sends step 1, creates SequenceStepSend(step_no=1),
     * advances current_step to 1, sets next_send_at ~3 days out,
     * and triggers SequenceStepMailable.
     */
    public function test_enroll_and_send_first_step(): void
    {
        // Pinned to a Monday: without Carbon::setTestNow(), the "next_send_at
        // ≈ now()->addDays(3)" assertion below runs against real wall-clock
        // time, so it passes on some days and fails on others once a future
        // chunk makes SequenceService shift follow-up steps off weekends
        // (step 2's delay_days=3 could land on a Sat/Sun and shift forward).
        // Monday + 3 days = Thursday, never a weekend, so the assertion stays
        // exact regardless of that future change.
        // Pinned in UTC (not 'Europe/Paris'): Carbon::setTestNow() with a
        // non-UTC mock changes the DEFAULT timezone that createFromFormat()
        // (used by Eloquent's datetime cast on every model retrieval) falls
        // back to when no explicit tz is given — silently reinterpreting
        // every 'datetime'-cast attribute read during the mock in that
        // timezone and corrupting comparisons by the UTC offset.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00:00', 'UTC'));

        $seq     = $this->makeTwoStepSequence();
        $contact = $this->makeContact();
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);

        $this->assertNotNull($enrollment);
        $this->assertSame('active', $enrollment->status);
        $this->assertSame(0, (int) $enrollment->current_step);

        $service->sendStep($enrollment);

        // SequenceStepSend for step 1 must exist
        $this->assertDatabaseHas('sequence_step_sends', [
            'enrollment_id' => $enrollment->id,
            'step_no'       => 1,
        ]);

        $enrollment->refresh();

        $this->assertSame(1, (int) $enrollment->current_step, 'current_step must be advanced to 1');

        // next_send_at should be roughly 3 days from now (step 2 delay_days=3)
        $this->assertNotNull($enrollment->next_send_at);
        $expectedNextSend = now()->addDays(3);
        $diffMinutes      = abs($enrollment->next_send_at->diffInMinutes($expectedNextSend));
        $this->assertLessThanOrEqual(2, $diffMinutes, 'next_send_at should be ~3 days from now');

        Mail::assertSent(SequenceStepMailable::class);
    }

    /**
     * A step subject containing {{contact.first_name}} must be rendered in the
     * sent mailable's envelope subject — not leaked as a literal token.
     */
    public function test_step_subject_renders_contact_first_name_merge_tag(): void
    {
        $seq = Sequence::create([
            'name'          => 'Seq merge-tag sujet',
            'is_active'     => true,
            'stop_on_reply' => false,
        ]);

        $tpl = $this->makeTemplate('Merge Tag Tpl');
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $tpl->id,
            'subject'     => 'Bonjour {{contact.first_name}}, une question',
        ]);

        // Contact name is multi-word so the first-name split is exercised.
        $co = Company::create([
            'name'                 => 'Acme',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        $contact = Contact::create([
            'company_id'  => $co->id,
            'email'       => 'karim@acme.test',
            'name'        => 'Karim Bennani',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        $service    = app(SequenceService::class);
        $enrollment = $service->enroll($seq, $contact);

        $service->sendStep($enrollment);

        Mail::assertSent(SequenceStepMailable::class, function (SequenceStepMailable $mail) {
            $subject = $mail->envelope()->subject;

            $this->assertStringNotContainsString(
                '{{contact.first_name}}',
                $subject,
                'Merge tag must not leak into the subject'
            );

            return $subject === 'Bonjour Karim, une question';
        });
    }

    /**
     * Sending step 1 then step 2 (with next_send_at set to the past between sends)
     * results in enrollment.status='completed' and next_send_at=null after step 2.
     */
    public function test_sequence_completes_after_last_step(): void
    {
        $seq     = $this->makeTwoStepSequence();
        $contact = $this->makeContact('j2@acme.test');
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);

        // Send step 1
        $service->sendStep($enrollment);
        $enrollment->refresh();

        // Fast-forward: pretend step 2 is due now
        $enrollment->update(['next_send_at' => now()->subSecond()]);

        // Send step 2
        $service->sendStep($enrollment);
        $enrollment->refresh();

        $this->assertSame('completed', $enrollment->status, 'Enrollment must be completed after last step');
        $this->assertNull($enrollment->next_send_at, 'next_send_at must be null after completion');

        // Both step sends must exist
        $this->assertDatabaseHas('sequence_step_sends', ['enrollment_id' => $enrollment->id, 'step_no' => 1]);
        $this->assertDatabaseHas('sequence_step_sends', ['enrollment_id' => $enrollment->id, 'step_no' => 2]);
    }

    /**
     * Calling sendStep when the step's SequenceStepSend already has a
     * provider_message_id must NOT create a duplicate row.
     * The UNIQUE(enrollment_id, step_no) constraint is the durable backstop.
     */
    public function test_step_send_is_idempotent(): void
    {
        $seq     = $this->makeTwoStepSequence();
        $contact = $this->makeContact('j3@acme.test');
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);

        // First send — normal path
        $service->sendStep($enrollment);

        $countAfterFirst = SequenceStepSend::where('enrollment_id', $enrollment->id)
            ->where('step_no', 1)
            ->count();

        // Simulate a retry: reset current_step so sendStep targets step 1 again,
        // but leave the existing SequenceStepSend with its provider_message_id.
        $enrollment->update(['current_step' => 0]);

        $service->sendStep($enrollment->fresh());

        $countAfterSecond = SequenceStepSend::where('enrollment_id', $enrollment->id)
            ->where('step_no', 1)
            ->count();

        $this->assertSame($countAfterFirst, $countAfterSecond, 'Step send count must not increase on retry');
        $this->assertSame(1, $countAfterSecond, 'Exactly one SequenceStepSend row must exist for step 1');
    }

    /**
     * stopForReply() stops active enrollments in sequences with stop_on_reply=true.
     */
    public function test_stop_for_reply(): void
    {
        $seq     = $this->makeTwoStepSequence(stopOnReply: true);
        $contact = $this->makeContact('j4@acme.test');
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);

        $this->assertSame('active', $enrollment->status);

        $stopped = $service->stopForReply($contact, 'replied');

        $this->assertSame(1, $stopped, 'One enrollment must be stopped');

        $enrollment->refresh();

        $this->assertSame('stopped', $enrollment->status, 'Enrollment status must be stopped');
    }

    /**
     * stopForReply() must NOT stop enrollments in sequences where stop_on_reply=false.
     */
    public function test_stop_for_reply_respects_stop_on_reply_flag(): void
    {
        $seq     = $this->makeTwoStepSequence(stopOnReply: false);
        $contact = $this->makeContact('j5@acme.test');
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);

        $stopped = $service->stopForReply($contact, 'replied');

        $this->assertSame(0, $stopped, 'Enrollment in non-stop_on_reply sequence must not be stopped');

        $enrollment->refresh();

        $this->assertSame('active', $enrollment->status, 'Enrollment must remain active');
    }

    // ── Chunk 6: BusinessCalendarService wiring ─────────────────────────────────

    /**
     * advanceEnrollment() must SHIFT a follow-up step's next_send_at off a
     * weekend, preserving the local wall-clock time — never skip it (that would
     * silently drop the step). Step 2's delay_days=1 from a Friday enrollment
     * lands on Saturday, which is the scenario that must shift.
     */
    public function test_advance_enrollment_shifts_a_saturday_landing_follow_up_to_monday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 09:00:00', 'UTC')); // Friday, 09:00 UTC

        $seq = Sequence::create([
            'name'          => 'Seq weekend shift',
            'is_active'     => true,
            'stop_on_reply' => false,
        ]);
        $tpl1 = $this->makeTemplate('Weekend Step1');
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $tpl1->id,
            'subject'     => 'Étape 1',
        ]);
        $tpl2 = $this->makeTemplate('Weekend Step2');
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 2,
            'delay_days'  => 1, // Friday + 1 day = Saturday
            'template_id' => $tpl2->id,
            'subject'     => 'Étape 2',
        ]);

        $contact = $this->makeContact('saturday-landing@acme.test');
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);
        $service->sendStep($enrollment);

        $enrollment->refresh();

        $this->assertNotNull($enrollment->next_send_at);
        // Friday 09:00 UTC + 1 day = Saturday 09:00 UTC — must shift to Monday,
        // preserving the 09:00 UTC wall time (Europe/Paris is the default
        // decouverte.timezone; no DST boundary crossed between Fri and Mon here).
        $this->assertSame(
            '2026-08-03 09:00',
            $enrollment->next_send_at->format('Y-m-d H:i'),
            'next_send_at must shift from Saturday to Monday, preserving wall time'
        );
    }

    /**
     * When an enrollment has no campaign attribution, advanceEnrollment() must
     * resolve the timezone via the decouverte.timezone fallback (resolveTimezone(null))
     * — not throw, and not silently ignore the setting.
     */
    public function test_advance_enrollment_falls_back_to_decouverte_timezone_when_campaign_is_null(): void
    {
        Setting::set('decouverte.timezone', 'America/New_York');

        // 2026-08-01 02:00 UTC = Friday 2026-07-31 22:00 America/New_York (EDT,
        // UTC-4) but Saturday 2026-08-01 04:00 Europe/Paris (CEST, UTC+2).
        // Only the correct fallback (America/New_York) sees this instant as a
        // Friday — Europe/Paris (or a hardcoded UTC default) would see Saturday
        // and shift; America/New_York must NOT shift.
        Carbon::setTestNow(Carbon::parse('2026-08-01 02:00:00', 'UTC'));

        $seq = Sequence::create([
            'name'          => 'Seq null campaign fallback',
            'is_active'     => true,
            'stop_on_reply' => false,
        ]);
        $tpl1 = $this->makeTemplate('Fallback Step1');
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $tpl1->id,
            'subject'     => 'Étape 1',
        ]);
        $tpl2 = $this->makeTemplate('Fallback Step2');
        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 2,
            'delay_days'  => 0,
            'template_id' => $tpl2->id,
            'subject'     => 'Étape 2',
        ]);

        $contact = $this->makeContact('null-campaign@acme.test');
        $service = app(SequenceService::class);

        // No campaign passed — enrollment.campaign_id stays null.
        $enrollment = $service->enroll($seq, $contact);
        $this->assertNull($enrollment->campaign_id);

        $service->sendStep($enrollment);
        $enrollment->refresh();

        $this->assertNotNull($enrollment->next_send_at);
        $this->assertTrue(
            $enrollment->next_send_at->equalTo(Carbon::parse('2026-08-01 02:00:00', 'UTC')),
            'next_send_at must stay unshifted — America/New_York (the decouverte.timezone fallback) sees Friday, not Saturday'
        );
    }

    /**
     * enroll() must set next_send_at = now() even on a Saturday — enrolling is a
     * deliberate act, step 1 always goes out immediately. Only follow-up steps
     * shift (advanceEnrollment()).
     */
    public function test_enroll_on_a_saturday_still_sets_next_send_at_to_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:00:00', 'UTC')); // Saturday

        $seq     = $this->makeTwoStepSequence();
        $contact = $this->makeContact('saturday-enroll@acme.test');
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);

        $this->assertTrue(
            $enrollment->next_send_at->equalTo(Carbon::parse('2026-08-01 09:00:00', 'UTC')),
            'enroll() must NOT shift next_send_at off a Saturday'
        );
    }

    /**
     * processDue() must HOLD an enrollment whose next_send_at already sits on a
     * blocked day — dispatch nothing, and leave the row byte-identical. On the
     * next allowed day, the SAME unmutated row becomes due and dispatches. This
     * is what makes the chunk-10 backfill command optional rather than mandatory.
     */
    public function test_process_due_holds_a_saturday_row_and_dispatches_it_unmutated_on_monday(): void
    {
        Queue::fake();

        // Create the enrollment on a weekday so its next_send_at can legitimately
        // be set to a Saturday instant (simulating a pre-existing, un-migrated row).
        Carbon::setTestNow(Carbon::parse('2026-07-30 09:00:00', 'UTC')); // Thursday

        $seq     = $this->makeTwoStepSequence();
        $contact = $this->makeContact('held-row@acme.test');
        $service = app(SequenceService::class);

        $enrollment = $service->enroll($seq, $contact);
        $enrollment->update(['next_send_at' => Carbon::parse('2026-08-01 09:00:00', 'UTC')]); // Saturday
        $beforeHold = $enrollment->fresh()->getAttributes();

        // Tick on the Saturday itself — next_send_at <= now() is true, but the
        // day is blocked, so the enrollment must be held, not dispatched.
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:30:00', 'UTC'));
        $dispatchedOnSaturday = $service->processDue();

        $this->assertSame(0, $dispatchedOnSaturday, 'A Saturday-due enrollment must be held, not dispatched');
        Queue::assertNotPushed(SendSequenceStepJob::class);

        $afterHold = $enrollment->fresh()->getAttributes();
        $this->assertSame($beforeHold, $afterHold, 'The held row must be byte-identical — processDue() must not mutate it');

        // Tick on the following Monday — the SAME unmutated row is now due.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:30:00', 'UTC'));
        $dispatchedOnMonday = $service->processDue();

        $this->assertSame(1, $dispatchedOnMonday, 'The same held row must dispatch once Monday arrives');
        Queue::assertPushed(SendSequenceStepJob::class, fn ($job) => $job->enrollmentId === $enrollment->id);
    }
}
