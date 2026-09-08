<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendSmtpReservationJob;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\InboxEmail;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SmtpSendReservation;
use App\Models\Suppression;
use App\Services\Campaign\CampaignProspectingEligibilityService;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use App\Services\Campaign\SegmentCsvImporter;
use App\Services\Campaign\SequenceService;
use App\Services\Campaign\SmtpCampaignsDriver;
use App\Services\Campaign\SmtpSendReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContinuousR1ProspectingTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app->make('db')->connection()->getDatabaseName() !== 'fretiq_test') {
            throw new \RuntimeException('These tests require the disposable fretiq_test database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-09 08:00:00', 'UTC'));
        config(['services.zoho.driver' => 'local', 'mail.smtp_mode' => 'mailpit', 'prospecting.cold_send_enabled' => true]);
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recipient_reply_timestamp_wins_over_stale_verification(): void
    {
        [$campaign, $contact] = $this->fixture();
        $contact->update(['email_verification_checked_at' => now()->subDays(31)]);
        $this->recipient($campaign, $contact, ['status' => 'sent', 'replied_at' => now()]);
        $this->assertSame('engaged', $this->policy()->sendReason($campaign, $contact));
    }

    public function test_campaign_smtp_acceptance_counts_even_before_recipient_finalization(): void
    {
        // Reservation lives on ANOTHER campaign — contacted_recently deliberately
        // excludes the current campaign's own history (see constat #4 / plan §A).
        [$campaign, $contact] = $this->fixture(['exclude_pending_companies' => false]);
        $other = $this->otherCampaign($campaign);
        $recipient = $this->recipient($other, $contact, ['status' => 'failed']);
        SmtpSendReservation::create(['campaign_id' => $other->id, 'sender_identity_id' => $campaign->sender_identity_id,
            'source_type' => 'campaign_recipient', 'source_id' => $recipient->id, 'status' => 'accepted',
            'accepted_at' => now()->subDay(), 'reserved_for' => now()->subDay()]);
        $this->assertSame('contacted_recently', $this->policy()->sendReason($campaign, $contact));
    }

    public function test_exact_seven_day_and_thirty_day_boundaries_are_allowed(): void
    {
        [$campaign, $contact] = $this->fixture();
        $other = $this->otherCampaign($campaign);
        $contact->update(['email_verification_checked_at' => now()->subDays(30)]);
        $recipient = $this->recipient($other, $contact, ['status' => 'sent', 'sent_at' => now()->subDays(7)]);
        $this->assertNull($this->policy()->sendReason($campaign, $contact));
        $recipient->update(['sent_at' => now()->subDays(7)->addSecond()]);
        $this->assertSame('contacted_recently', $this->policy()->sendReason($campaign, $contact));
    }

    public function test_newest_future_verification_does_not_hide_an_eligible_company_contact(): void
    {
        [$campaign, $contact] = $this->fixture();
        $future = $this->contact($contact->company, ['email_verification_checked_at' => now()->addDay()]);
        $rankedGroups = collect([$contact->company_id => collect([$future, $contact])]);
        $selected = $this->policy()->select($campaign, $rankedGroups, 10);
        $this->assertSame([$contact->id], $selected['contacts']->pluck('id')->all());
    }

    public function test_other_company_contact_request_and_unlinked_inbox_sender_are_excluded(): void
    {
        [$campaign, $contact] = $this->fixture();
        $other = $this->contact($contact->company);
        Demande::create(['contact_id' => $other->id, 'kind' => 'import', 'status' => 'nouvelle', 'captured_at' => now()]);
        $this->assertSame('engaged', $this->policy()->sendReason($campaign, $contact));
        Demande::query()->delete();
        InboxEmail::create(['sender_identity_id' => $campaign->sender_identity_id, 'message_id' => 'fixture-inbox',
            'from_email' => '  '.strtoupper($other->email).' ', 'received_at' => now(), 'status' => 'nouveau']);
        $this->assertSame('engaged', $this->policy()->sendReason($campaign, $contact));
    }

    public function test_invalid_and_bounced_addresses_are_permanent_exclusions(): void
    {
        [$campaign, $contact] = $this->fixture();
        $contact->update(['email_verification_status' => 'invalid']);
        $this->assertSame('invalid_email', $this->policy()->sendReason($campaign, $contact));
        $contact->update(['email_verification_status' => 'valid', 'email_verification_source' => 'bounce']);
        $this->assertSame('bounced', $this->policy()->sendReason($campaign, $contact));
    }

    public function test_company_claim_is_shared_by_different_campaigns_in_the_same_sequence(): void
    {
        // already_enrolled is now a SELECTION-only guard (plan §A), enforced by
        // PacedSequenceEnrollmentService::selectBatch()/evaluateDue() — not by a
        // bare SequenceService::enroll() call, which now rechecks SAFETY only.
        [$campaign, $contact] = $this->fixture();
        $second = $this->otherCampaign($campaign);
        $this->contact($contact->company); // a second, still-unenrolled contact at the same company

        $service = app(PacedSequenceEnrollmentService::class);
        $first = $service->evaluateDue($campaign, now());
        $this->assertSame(1, $first['enrolled']);

        $secondResult = $service->evaluateDue($second->fresh(), now());
        $this->assertSame(0, $secondResult['enrolled']);
        $this->assertSame(['already_enrolled' => 1], $secondResult['excluded']);
        $this->assertSame(1, SequenceEnrollment::count());
    }

    public function test_own_pending_work_can_send_without_self_exclusion(): void
    {
        [$campaign, $contact] = $this->fixture();
        $reservation = $this->reserve($campaign, $contact);
        $this->deliver($reservation);
        Mail::assertSent(SequenceStepMailable::class, 1);
        $this->assertSame('sent', $reservation->fresh()->status);
        $this->assertSame('completed', SequenceEnrollment::first()->status);
        $this->assertSame(0, CampaignRecipient::count());
    }

    public function test_freshness_expiry_after_enrollment_stops_the_enrollment_and_releases_the_reservation(): void
    {
        [$campaign, $contact] = $this->fixture();
        $reservation = $this->reserve($campaign, $contact);
        $contact->update(['email_verification_checked_at' => now()->subDays(31)]);
        $this->deliver($reservation);
        Mail::assertNothingSent();
        $this->assertSame('released', $reservation->fresh()->status);
        $enrollment = SequenceEnrollment::first();
        $this->assertSame('stopped', $enrollment->status);
        $this->assertSame('verification_stale', $enrollment->stopped_reason);
    }

    public function test_request_after_enrollment_stops_the_queued_email(): void
    {
        [$campaign, $contact] = $this->fixture();
        $reservation = $this->reserve($campaign, $contact);
        Demande::create(['contact_id' => $contact->id, 'kind' => 'import', 'captured_at' => now()]);
        $this->deliver($reservation);
        Mail::assertNothingSent();
        $this->assertSame('stopped', SequenceEnrollment::first()->status);
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_accepted_message_is_finalized_before_new_exclusions_without_resending(): void
    {
        [$campaign, $contact] = $this->fixture();
        $reservation = $this->reserve($campaign, $contact);
        $reservation->update(['status' => 'accepted', 'accepted_at' => now(), 'provider_message_id' => 'accepted-fixture']);
        Suppression::create(['email' => $contact->email, 'reason' => 'unsubscribe']);
        $this->deliver($reservation);
        Mail::assertNothingSent();
        $this->assertSame('sent', $reservation->fresh()->status);
        $this->assertSame('completed', SequenceEnrollment::first()->status);
    }

    public function test_dynamic_preview_and_daily_enrollment_pick_up_future_companies_without_reentry(): void
    {
        [$campaign, $contact] = $this->fixture();
        $campaign->update(['daily_company_limit' => 1]);
        $service = app(PacedSequenceEnrollmentService::class);
        $preview = $service->previewDailyBatch($campaign);
        $first = $service->evaluateDue($campaign, now());
        $this->assertSame($preview['contact_count'], $first['enrolled']);
        $this->assertSame(1, $first['enrolled']);
        $this->assertFalse($service->evaluateDue($campaign->fresh(), now())['processed_due']);
        Carbon::setTestNow(now()->addDay());
        $this->assertSame(0, $service->evaluateDue($campaign->fresh(), now())['enrolled']);
        $new = $this->contact(Company::create(['name' => 'New company', 'relationship' => 'prospect', 'is_active' => true]));
        Carbon::setTestNow(now()->addDay());
        $this->assertSame(1, $service->evaluateDue($campaign->fresh(), now())['enrolled']);
        $this->assertSame(2, SequenceEnrollment::count());
    }

    public function test_csv_parser_preserves_opt_in_rules_without_warnings(): void
    {
        $this->fixture();
        $json = str_replace('"', '""', json_encode(['prospecting_rules' => ['enabled' => true, 'contact_gap_days' => 7]]));
        $rows = app(SegmentCsvImporter::class)->parse("name;scope;filter_json;notes\nFresh;prospect;\"{$json}\";\n", 'segments.csv');
        $this->assertTrue($rows[0]['filter']['prospecting_rules']['enabled']);
        $this->assertSame([], $rows[0]['warnings']);
    }

    public function test_same_campaign_sibling_pending_work_no_longer_defers_the_send(): void
    {
        // pending_work is no longer evaluated at send time at all for SAME-campaign
        // siblings (constat #2 livelock fix) — the send simply goes out.
        [$campaign, $contact] = $this->fixture();
        $reservation = $this->reserve($campaign, $contact);
        $other = $this->contact($contact->company);
        $this->recipient($campaign, $other, ['status' => 'queued']);
        $this->deliver($reservation);
        Mail::assertSent(SequenceStepMailable::class, 1);
        $this->assertSame('sent', $reservation->fresh()->status);
    }

    public function test_uncertain_reservation_outcome_is_never_retried(): void
    {
        [$campaign, $contact] = $this->fixture();
        $reservation = $this->reserve($campaign, $contact);
        $reservation->update(['status' => 'uncertain', 'attempted_at' => now()]);
        $this->deliver($reservation);
        $this->assertSame('uncertain', $reservation->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_historical_sent_reservation_does_not_block_a_company_forever(): void
    {
        [$campaign, $contact] = $this->fixture();
        $recipient = $this->recipient($campaign, $contact, ['status' => 'sent', 'sent_at' => now()->subDays(8)]);
        SmtpSendReservation::create(['campaign_id' => $campaign->id, 'sender_identity_id' => $campaign->sender_identity_id,
            'source_type' => 'campaign_recipient', 'source_id' => $recipient->id, 'status' => 'sent',
            'accepted_at' => now()->subDays(8), 'sent_at' => now()->subDays(8), 'reserved_for' => now()->subDays(8)]);
        $this->assertNull($this->policy()->sendReason($campaign, $contact));
    }

    public function test_reply_after_enrollment_stops_the_queued_email(): void
    {
        [$campaign, $contact] = $this->fixture();
        $reservation = $this->reserve($campaign, $contact);
        $this->recipient($campaign, $contact, ['status' => 'sent', 'replied_at' => now()]);
        $this->deliver($reservation);
        Mail::assertNothingSent();
        $this->assertSame('engaged', SequenceEnrollment::first()->stopped_reason);
    }

    public function test_future_batch_preview_shows_candidates_without_claiming_an_immediate_enrollment(): void
    {
        [$campaign] = $this->fixture();
        $campaign->update(['next_run_at' => now()->addDay()]);
        $preview = app(PacedSequenceEnrollmentService::class)->previewDailyBatch($campaign);
        $this->assertSame(0, $preview['contact_count']);
        $this->assertSame(1, $preview['research_pool_count']);
        $this->assertSame(1, $preview['next_batch_company_count']);
        $this->assertSame(1, $preview['scanned_companies']);
        $this->assertSame(0, SequenceEnrollment::count());
    }

    public function test_two_step_sequence_second_step_sent_by_the_same_campaign_does_not_defer(): void
    {
        [$campaign, $contact] = $this->fixture();
        $template = CampaignTemplate::create(['name' => 'Fixture template step 2', 'subject' => 'Step 2', 'html_content' => '<p>Bonjour,</p><p>Étape 2</p>']);
        SequenceStep::create(['sequence_id' => $campaign->sequence_id, 'step_no' => 2, 'delay_days' => 1, 'template_id' => $template->id]);

        $reservation = $this->reserve($campaign, $contact);
        $this->deliver($reservation);
        Mail::assertSent(SequenceStepMailable::class, 1);
        $enrollment = SequenceEnrollment::first();
        $this->assertSame(1, $enrollment->current_step);
        $this->assertNotNull($enrollment->next_send_at);

        Carbon::setTestNow($enrollment->next_send_at->copy()->addSecond());
        app(SequenceService::class)->sendStep($enrollment->fresh());
        $reservation2 = SmtpSendReservation::where('id', '!=', $reservation->id)->firstOrFail();
        $this->deliver($reservation2);
        Mail::assertSent(SequenceStepMailable::class, 2);
        $this->assertSame('sent', $reservation2->fresh()->status);
        $this->assertSame('completed', $enrollment->fresh()->status);
    }

    public function test_other_campaign_send_two_days_ago_defers_to_the_exact_gap_date_then_sends(): void
    {
        [$campaign, $contact] = $this->fixture();
        $other = $this->otherCampaign($campaign);
        $this->recipient($other, $contact, ['status' => 'sent', 'sent_at' => now()->subDays(2)]);

        $reservation = $this->reserve($campaign, $contact);
        $this->deliver($reservation);
        Mail::assertNothingSent();
        $fresh = $reservation->fresh();
        $this->assertSame('reserved', $fresh->status);
        $this->assertTrue($fresh->reserved_for->equalTo(now()->addDays(5)));

        Carbon::setTestNow($fresh->reserved_for->copy()->addSecond());
        $this->deliver($fresh);
        Mail::assertSent(SequenceStepMailable::class, 1);
        $this->assertSame('sent', $fresh->fresh()->status);
    }

    public function test_two_campaigns_sharing_a_sequence_still_respect_the_contact_gap(): void
    {
        [$campaign, $contact] = $this->fixture(['once_per_sequence_company' => false]);
        $second = $this->otherCampaign($campaign);
        $otherContact = $this->contact($contact->company);

        $reservation = $this->reserve($campaign, $contact);
        $this->deliver($reservation);
        Mail::assertSent(SequenceStepMailable::class, 1);

        $service = app(SequenceService::class);
        $enrollment2 = $service->enroll($second->sequence, $otherContact, $second);
        $this->assertNotNull($enrollment2);
        $service->sendStep($enrollment2->fresh());
        $reservation2 = SmtpSendReservation::where('id', '!=', $reservation->id)->firstOrFail();
        $this->deliver($reservation2);
        Mail::assertSent(SequenceStepMailable::class, 1);
        $this->assertSame('reserved', $reservation2->fresh()->status);
        $this->assertTrue($reservation2->fresh()->reserved_for->gt(now()));
    }

    public function test_one_contact_per_company_false_enrolls_two_contacts_in_the_same_batch_and_both_send_same_day(): void
    {
        [$campaign, $contact] = $this->fixture(['one_contact_per_company' => false]);
        $this->contact($contact->company);

        $result = app(PacedSequenceEnrollmentService::class)->activate($campaign);
        $this->assertSame(2, $result['enrolled']);
        $this->assertSame(1, $result['companies']);
        $this->assertSame(2, SequenceEnrollment::count());

        foreach (SequenceEnrollment::all() as $enrollment) {
            app(SequenceService::class)->sendStep($enrollment);
        }
        $reservations = SmtpSendReservation::orderBy('id')->get();
        $this->assertCount(2, $reservations);

        $this->deliver($reservations[0]);
        $this->assertSame('sent', $reservations[0]->fresh()->status);

        // Sender-identity SMTP pacing (unrelated to prospecting eligibility) may
        // push the second send later the SAME business day — advance past it
        // rather than fighting the throttle; the point under test is that
        // prospecting eligibility itself never blocks the sibling (constat #2).
        $second = $reservations[1]->fresh();
        if ($second->status === 'reserved' && $second->reserved_for->gt(now())) {
            Carbon::setTestNow($second->reserved_for->copy()->addSecond());
        }
        $this->deliver($second);
        $this->assertSame('sent', $second->fresh()->status);

        Mail::assertSent(SequenceStepMailable::class, 2);
        $this->assertSame(
            $reservations[0]->fresh()->sent_at->toDateString(),
            $second->fresh()->sent_at->toDateString(),
        );
    }

    public function test_narrow_pending_work_defers_for_an_older_other_campaign_reservation(): void
    {
        // Narrow pending_work is reservation-vs-reservation only (constat #2 livelock
        // fix): an enrollment on the OTHER campaign, with no reservation of its own,
        // must never defer — see test_two_opted_in_campaigns_on_the_same_company_defer_only_the_newer_reservation.
        [$campaign, $contact] = $this->fixture();
        $other = $this->otherCampaign($campaign);
        $otherContact = $this->contact($contact->company);
        $otherRecipient = $this->recipient($other, $otherContact, ['status' => 'queued']);
        SmtpSendReservation::create([
            'campaign_id' => $other->id, 'sender_identity_id' => $campaign->sender_identity_id,
            'source_type' => 'campaign_recipient', 'source_id' => $otherRecipient->id,
            'status' => 'reserved', 'reserved_for' => now()->addHour(),
        ]);

        // Do not use the reserve() helper's SmtpSendReservation::firstOrFail() —
        // it would pick up the "other" reservation created above instead of this
        // campaign's own row.
        $service = app(SequenceService::class);
        $enrollment = $service->enroll($campaign->sequence, $contact, $campaign);
        $service->sendStep($enrollment);
        $reservation = SmtpSendReservation::where('campaign_id', $campaign->id)->firstOrFail();
        $this->deliver($reservation);
        Mail::assertNothingSent();
        $fresh = $reservation->fresh();
        $this->assertSame('reserved', $fresh->status);
        $this->assertTrue($fresh->reserved_for->equalTo(now()->addMinutes(15)));
    }

    public function test_two_opted_in_campaigns_on_the_same_company_defer_only_the_newer_reservation(): void
    {
        // Regression for the mutual-defer livelock (constat #2): two opted-in
        // campaigns on DIFFERENT sequences, same company. Enrollment A precedes
        // enrollment B (t0<t1); reservation A precedes reservation B (t2<t3).
        // With the old enrollment/created_at comparison both saw each other as
        // "older" and deferred forever. The fix ties strictly to reservation id,
        // so only the newer reservation (B) defers; the older (A) sends.
        [$campaignA, $contact] = $this->fixture();
        $templateB = CampaignTemplate::create(['name' => 'Second sequence template', 'subject' => 'B', 'html_content' => '<p>Bonjour,</p><p>B</p>']);
        $sequenceB = Sequence::create(['name' => 'Second fixture sequence', 'is_active' => true]);
        SequenceStep::create(['sequence_id' => $sequenceB->id, 'step_no' => 1, 'delay_days' => 0, 'template_id' => $templateB->id]);
        // A distinct sender identity keeps SmtpSendReservationService's own
        // same-mailbox fairness ordering (claimWhenDue()'s "earlierPending"
        // check) from interfering with — or masking — the cross-campaign
        // narrow-pending-work check under test here.
        $senderB = SenderIdentity::create(['name' => 'Fixture sender B', 'email' => 'sender-b@example.test', 'is_active' => true, 'smtp_daily_limit' => 50, 'smtp_hourly_limit' => 10]);
        $campaignB = Campaign::create(['name' => 'Second campaign', 'segment_id' => $campaignA->segment_id, 'sequence_id' => $sequenceB->id,
            'sender_identity_id' => $senderB->id, 'delivery_channel' => 'smtp', 'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced', 'is_active' => true, 'sequence_auto_enroll_enabled' => true,
            'daily_company_limit' => 10, 'smtp_daily_email_limit' => 10, 'email_verification_policy' => 'verified_only',
            'timezone' => 'Europe/Paris', 'next_run_at' => now()->subMinute()]);

        $service = app(SequenceService::class);
        $enrollmentA = $service->enroll($campaignA->sequence, $contact, $campaignA); // t0
        Carbon::setTestNow(now()->addMinute());
        $enrollmentB = $service->enroll($campaignB->sequence, $contact, $campaignB); // t1

        Carbon::setTestNow(now()->addMinute());
        $service->sendStep($enrollmentA->fresh());
        $reservationA = SmtpSendReservation::firstOrFail(); // t2
        Carbon::setTestNow(now()->addMinute());
        $service->sendStep($enrollmentB->fresh());
        $reservationB = SmtpSendReservation::where('id', '!=', $reservationA->id)->firstOrFail(); // t3

        // Deliver the newer reservation first, while the older one is still
        // pending — this is the exact scenario the mutual-defer bug produced.
        $this->deliver($reservationB);
        $this->deliver($reservationA);

        Mail::assertSent(SequenceStepMailable::class, 1);
        $this->assertSame('sent', $reservationA->fresh()->status);
        $freshB = $reservationB->fresh();
        $this->assertSame('reserved', $freshB->status);
        $this->assertTrue($freshB->reserved_for->equalTo(now()->addMinutes(15)));
    }

    public function test_narrow_pending_work_ignores_a_stale_other_campaign_recipient_older_than_24h(): void
    {
        [$campaign, $contact] = $this->fixture();
        $other = $this->otherCampaign($campaign);
        $otherContact = $this->contact($contact->company);
        $otherRecipient = $this->recipient($other, $otherContact, ['status' => 'queued']);
        CampaignRecipient::whereKey($otherRecipient->id)->update(['created_at' => now()->subDays(2)]);

        $reservation = $this->reserve($campaign, $contact);
        $this->deliver($reservation);
        Mail::assertSent(SequenceStepMailable::class, 1);
        $this->assertSame('sent', $reservation->fresh()->status);
    }

    public function test_daily_company_limit_fills_despite_a_leading_ineligible_company(): void
    {
        // A bounce is filtered upstream by SegmentService::resolve()'s own
        // compliance funnel — it never reaches the eligibility scan at all, so
        // it cannot demonstrate the scan skipping past an excluded company.
        // verification_stale is a prospecting_rules-only concept, so it DOES
        // reach select() as a leading, excluded, still-ranked-first company.
        [$campaign, $contact] = $this->fixture();
        $campaign->update(['daily_company_limit' => 2]);
        $contact->company->update(['ai_score' => 100]);
        $contact->update(['email_verification_checked_at' => now()->subDays(31)]);
        for ($i = 1; $i <= 4; $i++) {
            $company = Company::create(['name' => "Ranked company {$i}", 'relationship' => 'prospect', 'is_active' => true, 'ai_score' => 90 - $i]);
            $this->contact($company);
        }

        $result = app(PacedSequenceEnrollmentService::class)->activate($campaign);
        $this->assertSame(2, $result['enrolled']);
        $this->assertSame(2, $result['companies']);
        $this->assertSame(['verification_stale' => 1], $result['excluded']);
        $this->assertDatabaseMissing('sequence_enrollments', ['contact_id' => $contact->id]);
    }

    public function test_select_caps_the_scan_on_an_unhappy_path_of_all_excluded_companies(): void
    {
        [$campaign, $contact] = $this->fixture();
        $contact->update(['email_verification_checked_at' => now()->subDays(31)]);
        $rankedGroups = collect([$contact->company_id => collect([$contact])]);
        for ($i = 1; $i <= 2; $i++) {
            $company = Company::create(['name' => "Stale company {$i}", 'relationship' => 'prospect', 'is_active' => true]);
            $stale = $this->contact($company, ['email_verification_checked_at' => now()->subDays(31)]);
            $rankedGroups->put($company->id, collect([$stale]));
        }

        $result = $this->policy()->select($campaign, $rankedGroups, 1, maxScan: 2);

        $this->assertTrue($result['scan_capped']);
        $this->assertSame(2, $result['scanned_companies']);
    }

    public function test_preview_daily_batch_caps_the_scan_well_below_the_cron_ticks_cap(): void
    {
        // previewDailyBatch() runs on every campaign view/edit render — it must
        // use a much tighter scan cap than evaluateDue()'s cron-tick default.
        [$campaign, $contact] = $this->fixture();
        $campaign->update(['daily_company_limit' => 1]);
        $contact->update(['email_verification_checked_at' => now()->subDays(31)]);
        for ($i = 1; $i <= 39; $i++) {
            $company = Company::create(['name' => "Stale company {$i}", 'relationship' => 'prospect', 'is_active' => true]);
            $this->contact($company, ['email_verification_checked_at' => now()->subDays(31)]);
        }

        $preview = app(PacedSequenceEnrollmentService::class)->previewDailyBatch($campaign->fresh());
        $this->assertTrue($preview['scan_capped']);
        $this->assertSame(30, $preview['scanned_companies']);
    }

    public function test_is_manual_segment_with_prospecting_rules_enabled_is_rejected_with_422(): void
    {
        $this->admin();
        $segment = Segment::create(['name' => 'Manual candidate', 'scope' => 'prospect', 'is_manual' => false, 'filter' => null]);
        $response = $this->putJson(route('admin.segments.update', $segment), [
            'name' => $segment->name, 'scope' => 'prospect', 'is_manual' => 1,
            'filter' => ['prospecting_rules' => ['enabled' => '1']],
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['filter.prospecting_rules.enabled']);
        $this->assertFalse($segment->fresh()->is_manual);
    }

    public function test_one_shot_smtp_campaign_on_a_rules_segment_gets_the_full_audience(): void
    {
        [$campaign, $contact] = $this->fixture();
        $this->contact($contact->company);
        $another = Company::create(['name' => 'Another prospect', 'relationship' => 'prospect', 'is_active' => true]);
        $this->contact($another);
        $template = CampaignTemplate::create(['name' => 'One-shot template', 'subject' => 'One-shot', 'html_content' => '<p>Bonjour,</p><p>One-shot</p>']);

        $oneShot = Campaign::create(['name' => 'One-shot on rules segment', 'segment_id' => $campaign->segment_id,
            'sender_identity_id' => $campaign->sender_identity_id, 'template_id' => $template->id,
            'delivery_channel' => 'smtp', 'schedule_type' => 'one_shot', 'is_active' => true,
            'email_verification_policy' => 'verified_only', 'timezone' => 'Europe/Paris']);

        $preflight = app(CampaignService::class)->dispatchPreflight($oneShot);
        $this->assertTrue($preflight['ok'], implode(' ', $preflight['messages']));
        $this->assertSame(3, $preflight['contact_count']);
    }

    public function test_cold_send_disabled_stops_the_reservation_via_the_existing_contact_eligibility_path(): void
    {
        [$campaign, $contact] = $this->fixture();
        config(['prospecting.cold_send_enabled' => false]);
        $reservation = $this->reserve($campaign, $contact);
        $this->deliver($reservation);
        Mail::assertNothingSent();
        $enrollment = SequenceEnrollment::first();
        $this->assertSame('stopped', $enrollment->status);
        $this->assertSame('cold_send_disabled', $enrollment->stopped_reason);
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_segment_form_retains_rules_on_save(): void
    {
        [$campaign] = $this->fixture();
        $this->admin();
        $segment = $campaign->segment;
        $this->get(route('admin.segments.edit', $segment))->assertOk()->assertSee('filter[prospecting_rules][enabled]', false);
        $payload = ['name' => $segment->name, 'scope' => 'prospect', 'is_manual' => 0,
            'filter' => ['prospecting_rules' => ['enabled' => '1', 'contact_gap_days' => '7', 'verification_max_age_days' => '30']]];
        $this->putJson(route('admin.segments.update', $segment), $payload)->assertSuccessful();
        $this->assertTrue($segment->fresh()->filter['prospecting_rules']['enabled']);
    }

    public function test_segment_form_rejects_invalid_day_limits_without_changing_saved_rules(): void
    {
        [$campaign] = $this->fixture();
        $this->admin();
        $segment = $campaign->segment;
        $this->putJson(route('admin.segments.update', $segment), ['name' => $segment->name, 'scope' => 'prospect', 'is_manual' => 0,
            'filter' => ['prospecting_rules' => ['enabled' => '1', 'contact_gap_days' => 0]]])->assertUnprocessable();
        $this->assertSame(['enabled' => true], $segment->fresh()->filter['prospecting_rules']);
    }

    public function test_campaign_edit_and_view_show_matching_eligibility_summary(): void
    {
        [$campaign] = $this->fixture();
        $this->admin();
        foreach ([route('admin.campaigns.edit', $campaign), route('admin.campaigns.view', $campaign)] as $url) {
            $this->get($url)->assertOk()->assertSee('data-testid="continuous-prospecting-summary"', false)->assertSee('Prochain lot estimé');
        }
        $this->assertSame(0, SequenceEnrollment::count());
        Mail::assertNothingSent();
    }

    public function test_view_and_edit_hide_continuous_prospecting_summary_when_not_opted_in(): void
    {
        // Bandeau leak regression: a paced sequence campaign whose segment has NO
        // prospecting rules enabled must never render the continuous-prospecting
        // summary — it previously leaked live previewDailyBatch() data (visual QA,
        // campaign id 1, legacy Zoho channel).
        [$campaign] = $this->fixture();
        $campaign->segment->update(['filter' => null]);
        $this->admin();
        foreach ([route('admin.campaigns.edit', $campaign), route('admin.campaigns.view', $campaign)] as $url) {
            // Note: a `document.querySelector('[data-testid="continuous-prospecting-summary"]')`
            // reference lives in the page's own JS on every edit load, so assert on the
            // section's actual server-rendered content instead of the raw testid string.
            $this->get($url)->assertOk()->assertDontSee('Prospection continue — état enregistré');
        }
    }

    public function test_view_shows_the_full_audience_not_the_daily_batch_for_an_opted_in_campaign(): void
    {
        // dispatchPreflight() must return the full resolved audience — the KPI
        // tile/tab and campaignProgress both key off it, and a batch-limited
        // slice removes already-enrolled companies, permanently freezing
        // progress at 0%.
        [$campaign, $contact] = $this->fixture();
        $this->contact($contact->company); // a second, still-unenrolled contact at the same company
        $another = Company::create(['name' => 'Another prospect', 'relationship' => 'prospect', 'is_active' => true]);
        $this->contact($another);
        $campaign->update(['daily_company_limit' => 1]);

        $reservation = $this->reserve($campaign, $contact);
        $this->deliver($reservation);
        Mail::assertSent(SequenceStepMailable::class, 1);

        $preflight = app(CampaignService::class)->dispatchPreflight($campaign->fresh());
        $this->assertSame(2, $preflight['company_count']);

        $this->admin();
        $response = $this->get(route('admin.campaigns.view', $campaign))->assertOk();
        $this->assertSame(2, $response->viewData('currentAudience')->pluck('company_id')->unique()->count());
        $this->assertSame(2, $response->viewData('campaignProgress')['audience_companies']);
    }

    private function admin(): void
    {
        $this->seed([\Database\Seeders\Acl\RolesSeeder::class, \Database\Seeders\Acl\PermissionsSeeder::class]);
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');
        $this->actingAs($user);
    }

    private function fixture(array $rules = []): array
    {
        $company = Company::create(['name' => 'Fixture company', 'relationship' => 'prospect', 'is_active' => true, 'qualification_status' => 'pending']);
        $contact = $this->contact($company);
        $segment = Segment::create(['name' => 'Dynamic fixture', 'scope' => 'prospect', 'filter' => ['prospecting_rules' => ['enabled' => true] + $rules]]);
        $sender = SenderIdentity::create(['name' => 'Fixture sender', 'email' => 'sender@example.test', 'is_active' => true, 'smtp_daily_limit' => 50, 'smtp_hourly_limit' => 10]);
        $template = CampaignTemplate::create(['name' => 'Fixture template', 'subject' => 'Fixture', 'html_content' => '<p>Bonjour,</p><p>Fixture</p>']);
        $sequence = Sequence::create(['name' => 'Fixture sequence', 'is_active' => true]);
        SequenceStep::create(['sequence_id' => $sequence->id, 'step_no' => 1, 'delay_days' => 0, 'template_id' => $template->id]);
        $campaign = Campaign::create(['name' => 'Fixture campaign', 'segment_id' => $segment->id, 'sequence_id' => $sequence->id,
            'sender_identity_id' => $sender->id, 'delivery_channel' => 'smtp', 'schedule_type' => 'sequence', 'sequence_enrollment_mode' => 'paced',
            'is_active' => true, 'sequence_auto_enroll_enabled' => true, 'daily_company_limit' => 10, 'smtp_daily_email_limit' => 10,
            'email_verification_policy' => 'verified_only', 'timezone' => 'Europe/Paris', 'next_run_at' => now()->subMinute()]);

        return [$campaign, $contact];
    }

    /** A second campaign sharing the same segment/sequence/sender — used to model "another campaign" contact history. */
    private function otherCampaign(Campaign $campaign): Campaign
    {
        $other = $campaign->replicate();
        $other->name = 'Other campaign ' . uniqid();
        $other->save();

        return $other;
    }

    private function contact(Company $company, array $attributes = []): Contact
    {
        return Contact::create($attributes + ['name' => 'Fixture contact', 'company_id' => $company->id, 'email' => uniqid('fixture').'@example.test', 'email_kind' => 'role',
            'email_verification_status' => 'valid', 'email_verification_source' => 'import', 'email_verification_checked_at' => now()]);
    }

    private function recipient(Campaign $campaign, Contact $contact, array $attributes): CampaignRecipient
    {
        $run = CampaignRun::create(['campaign_id' => $campaign->id, 'occurrence_key' => uniqid('fixture-'), 'run_at' => now(), 'status' => 'sent']);

        return CampaignRecipient::create($attributes + ['campaign_run_id' => $run->id, 'contact_id' => $contact->id]);
    }

    private function policy(): CampaignProspectingEligibilityService
    {
        return app(CampaignProspectingEligibilityService::class);
    }

    private function reserve(Campaign $campaign, Contact $contact): SmtpSendReservation
    {
        $service = app(SequenceService::class);
        $enrollment = $service->enroll($campaign->sequence, $contact, $campaign);
        $service->sendStep($enrollment);

        return SmtpSendReservation::firstOrFail();
    }

    private function deliver(SmtpSendReservation $reservation): void
    {
        $reservation->update(['reserved_for' => now()->subSecond()]);
        (new SendSmtpReservationJob($reservation->id))->handle(app(SmtpSendReservationService::class), app(SmtpCampaignsDriver::class), app(CampaignService::class), app(SequenceService::class));
    }
}
