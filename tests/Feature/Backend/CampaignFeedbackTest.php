<?php

namespace Tests\Feature\Backend;

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
use App\Models\SequenceStepSend;
use App\Services\Campaign\CampaignFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function recipient(string $status = 'sent'): CampaignRecipient
    {
        $company = Company::create([
            'name' => 'Feedback Co',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => 'feedback@example.test',
            'name' => 'Feedback Contact',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
        $segment = Segment::create(['name' => 'Feedback segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Feedback template',
            'subject' => 'Feedback',
            'html_content' => '<p>Feedback</p>',
        ]);
        $sender = SenderIdentity::create(['name' => 'TCL', 'email' => 'sender@example.test']);
        $campaign = Campaign::create([
            'name' => 'Feedback campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'scheduled_at' => now(),
            'timezone' => 'Europe/Paris',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'feedback-'.uniqid(),
            'run_at' => now(),
            'status' => 'sent',
        ]);

        return CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => $status,
            'sent_at' => now()->subMinute(),
        ]);
    }

    public function test_hard_bounce_updates_the_recipient_and_suppresses_its_email(): void
    {
        $recipient = $this->recipient();
        $occurredAt = now()->startOfSecond();

        app(CampaignFeedbackService::class)->apply(
            $recipient,
            'hard_bounce',
            $occurredAt,
            'Mailbox unavailable',
            'zoho',
        );

        $recipient->refresh();
        $this->assertSame('bounced', $recipient->status);
        $this->assertSame('Mailbox unavailable', $recipient->bounce_reason);
        $this->assertSame('hard', $recipient->bounce_type);
        $this->assertTrue($recipient->bounced_at->equalTo($occurredAt));
        $this->assertDatabaseHas('suppressions', [
            'email' => 'feedback@example.test',
            'reason' => 'hard_bounce',
            'source' => 'zoho',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => $recipient->contact_id,
            'email_verification_status' => 'invalid',
            'email_verification_source' => 'bounce',
        ]);
    }

    public function test_first_soft_bounce_does_not_suppress(): void
    {
        $recipient = $this->recipient();

        app(CampaignFeedbackService::class)->apply($recipient, 'soft_bounce', now(), 'Temporary failure', 'dsn');

        $this->assertSame('bounced', $recipient->fresh()->status);
        $this->assertSame('soft', $recipient->fresh()->bounce_type);
        $this->assertDatabaseMissing('suppressions', ['email' => 'feedback@example.test']);
        $this->assertSame('invalid', $recipient->contact->fresh()->email_verification_status);
        $this->assertSame('bounce', $recipient->contact->fresh()->email_verification_source);
    }

    public function test_second_soft_bounce_within_thirty_days_suppresses(): void
    {
        $first = $this->recipient();
        $run = CampaignRun::create([
            'campaign_id' => $first->run->campaign_id,
            'occurrence_key' => 'feedback-second-'.uniqid(),
            'run_at' => now(),
            'status' => 'sent',
        ]);
        $second = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $first->contact_id,
            'status' => 'sent',
            'sent_at' => now()->subMinute(),
        ]);

        $service = app(CampaignFeedbackService::class);
        $service->apply($first, 'soft_bounce', now()->subDay(), null, 'dsn');
        $service->apply($second, 'soft_bounce', now(), null, 'dsn');

        $this->assertDatabaseHas('suppressions', [
            'email' => 'feedback@example.test',
            'reason' => 'soft_bounce',
            'source' => 'dsn',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => $first->contact_id,
            'email_verification_status' => 'invalid',
        ]);
    }

    public function test_anomalous_bounce_rate_pauses_campaign(): void
    {
        $first = $this->recipient();
        $campaign = $first->run->campaign;
        $campaign->update(['is_active' => true]);
        for ($i = 1; $i < 20; $i++) {
            $company = Company::create([
                'name' => 'Rate Co '.$i,
                'relationship' => 'client', 'source' => 'manual', 'qualification_status' => 'pending',
            ]);
            $contact = Contact::create([
                'company_id' => $company->id, 'email' => "rate-{$i}@example.test", 'name' => 'Rate',
                'status' => 'new', 'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
            ]);
            CampaignRecipient::create([
                'campaign_run_id' => $first->campaign_run_id, 'contact_id' => $contact->id,
                'status' => 'sent', 'sent_at' => now()->subMinute(),
            ]);
        }
        $second = CampaignRecipient::query()->where('campaign_run_id', $first->campaign_run_id)->whereKeyNot($first->id)->firstOrFail();
        $service = app(CampaignFeedbackService::class);
        $service->apply($first, 'hard_bounce', now(), null, 'dsn');
        $service->apply($second, 'hard_bounce', now(), null, 'dsn');

        $this->assertFalse((bool) $campaign->fresh()->is_active);
    }

    public function test_mixed_campaign_and_sequence_soft_bounces_suppress_and_stop_enrollment(): void
    {
        $recipient = $this->recipient();
        $sequence = Sequence::create(['name' => 'Feedback sequence', 'is_active' => true]);
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $recipient->contact_id,
            'campaign_id' => $recipient->run->campaign_id,
            'current_step' => 1,
            'status' => 'active',
        ]);
        $send = SequenceStepSend::create([
            'enrollment_id' => $enrollment->id,
            'step_no' => 1,
            'status' => 'sent',
            'sent_at' => now()->subMinute(),
        ]);

        $service = app(CampaignFeedbackService::class);
        $service->apply($recipient, 'soft_bounce', now()->subHour(), null, 'dsn');
        $service->apply($send, 'soft_bounce', now(), null, 'dsn');

        $this->assertSame('bounced', $send->fresh()->status);
        $this->assertSame('soft', $send->fresh()->bounce_type);
        $this->assertSame('stopped', $enrollment->fresh()->status);
        $this->assertSame('soft_bounce', $enrollment->fresh()->stopped_reason);
        $this->assertDatabaseHas('suppressions', ['email' => 'feedback@example.test', 'reason' => 'soft_bounce']);
    }

    public function test_sequence_only_anomalous_bounce_rate_pauses_campaign(): void
    {
        $recipient = $this->recipient();
        $campaign = $recipient->run->campaign;
        $campaign->update(['is_active' => true]);
        $recipient->delete();
        $sequence = Sequence::create(['name' => 'Sequence rate', 'is_active' => true]);
        $sends = collect();

        for ($i = 0; $i < 20; $i++) {
            $company = Company::create([
                'name' => 'Sequence Rate '.$i,
                'relationship' => 'client', 'source' => 'manual', 'qualification_status' => 'pending',
            ]);
            $contact = Contact::create([
                'company_id' => $company->id, 'email' => "sequence-rate-{$i}@example.test", 'name' => 'Rate',
                'status' => 'new', 'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
            ]);
            $enrollment = SequenceEnrollment::create([
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'current_step' => 1,
                'status' => 'active',
            ]);
            $sends->push(SequenceStepSend::create([
                'enrollment_id' => $enrollment->id,
                'step_no' => 1,
                'status' => 'sent',
                'sent_at' => now()->subMinute(),
            ]));
        }

        $service = app(CampaignFeedbackService::class);
        $service->apply($sends[0], 'hard_bounce', now(), null, 'dsn');
        $service->apply($sends[1], 'hard_bounce', now(), null, 'dsn');

        $this->assertFalse((bool) $campaign->fresh()->is_active);
    }

    public function test_repeated_complaint_feedback_creates_one_suppression(): void
    {
        $recipient = $this->recipient();
        $service = app(CampaignFeedbackService::class);

        $service->apply($recipient, 'complaint', now(), null, 'zoho');
        $service->apply($recipient, 'complaint', now(), null, 'zoho');

        $this->assertSame(1, \App\Models\Suppression::where('email', 'feedback@example.test')->count());
        $this->assertDatabaseHas('suppressions', [
            'email' => 'feedback@example.test',
            'reason' => 'complaint',
            'source' => 'zoho',
        ]);
    }

    public function test_unsubscribe_marks_the_recipient_and_suppresses_its_email(): void
    {
        $recipient = $this->recipient();

        app(CampaignFeedbackService::class)->apply($recipient, 'unsubscribe', now(), null, 'zoho');

        $this->assertSame('unsubscribed', $recipient->refresh()->status);
        $this->assertDatabaseHas('suppressions', [
            'email' => 'feedback@example.test',
            'reason' => 'unsubscribe',
            'source' => 'zoho',
        ]);
    }

    public function test_opened_feedback_never_downgrades_replied_or_unsubscribed(): void
    {
        $recipient = $this->recipient('replied');
        $service = app(CampaignFeedbackService::class);

        $service->apply($recipient, 'opened', now()->startOfSecond());
        $this->assertSame('replied', $recipient->refresh()->status);
        $this->assertNotNull($recipient->opened_at);

        $recipient->update(['status' => 'unsubscribed', 'opened_at' => null]);
        $service->apply($recipient, 'opened', now()->startOfSecond());
        $this->assertSame('unsubscribed', $recipient->refresh()->status);
        $this->assertNotNull($recipient->opened_at);
    }

    public function test_clicked_feedback_never_downgrades_replied_or_unsubscribed(): void
    {
        $recipient = $this->recipient('replied');
        $service = app(CampaignFeedbackService::class);

        $service->apply($recipient, 'clicked', now()->startOfSecond());
        $this->assertSame('replied', $recipient->refresh()->status);
        $this->assertNotNull($recipient->clicked_at);

        $recipient->update(['status' => 'unsubscribed', 'clicked_at' => null]);
        $service->apply($recipient, 'clicked', now()->startOfSecond());
        $this->assertSame('unsubscribed', $recipient->refresh()->status);
        $this->assertNotNull($recipient->clicked_at);
    }

    /**
     * SyncCampaignRecipientEventsJob::eventTime() returns null for every
     * 'clickedcontacts' row (Zoho's click report carries no timestamp).
     * Before this fix that left clicked_at permanently NULL — only status
     * was written, and a later bounce/unsubscribe event would overwrite even
     * that, losing the click signal entirely. An approximate (sync-time)
     * timestamp must be persisted instead.
     */
    public function test_zoho_clicked_without_a_timestamp_persists_the_sync_time_as_clicked_at(): void
    {
        $recipient = $this->recipient('sent');
        $before = now()->subSecond();

        app(CampaignFeedbackService::class)->apply($recipient, 'clicked', null, null, 'zoho');

        $recipient->refresh();
        $this->assertSame('clicked', $recipient->status);
        $this->assertNotNull($recipient->clicked_at, 'clicked_at must not be left NULL for a timestamp-less Zoho click');
        $this->assertTrue($recipient->clicked_at->greaterThanOrEqualTo($before));
    }

    /**
     * First-write-wins: a timestamp-less Zoho click must never overwrite an
     * earlier, already-recorded clicked_at.
     */
    public function test_zoho_clicked_without_a_timestamp_never_overwrites_an_earlier_clicked_at(): void
    {
        $recipient = $this->recipient('clicked');
        $earlier = now()->subDays(3)->startOfSecond();
        $recipient->update(['clicked_at' => $earlier]);

        app(CampaignFeedbackService::class)->apply($recipient, 'clicked', null, null, 'zoho');

        $this->assertTrue($recipient->refresh()->clicked_at->equalTo($earlier));
    }

    public function test_unsent_feedback_marks_the_recipient_skipped_without_suppression(): void
    {
        $recipient = $this->recipient();

        app(CampaignFeedbackService::class)->apply($recipient, 'unsent', now(), 'Rejected by Zoho', 'zoho');

        $recipient->refresh();
        $this->assertSame('skipped', $recipient->status);
        $this->assertSame('zoho_unsent', $recipient->skip_reason);
        $this->assertDatabaseMissing('suppressions', ['email' => 'feedback@example.test']);
    }

    public function test_sent_feedback_records_provider_evidence_for_a_queued_recipient(): void
    {
        $recipient = $this->recipient('queued');
        $recipient->update(['sent_at' => null]);
        $sentAt = now()->subMinutes(10)->startOfSecond();

        app(CampaignFeedbackService::class)->apply($recipient, 'sent', $sentAt, null, 'zoho');

        $recipient->refresh();
        $this->assertSame('sent', $recipient->status);
        $this->assertTrue($recipient->sent_at->equalTo($sentAt));
    }

    public function test_zoho_sent_evidence_without_a_verified_time_does_not_fabricate_a_timestamp(): void
    {
        $recipient = $this->recipient('queued');
        $recipient->update(['sent_at' => null]);

        app(CampaignFeedbackService::class)->apply($recipient, 'sent', null, null, 'zoho');

        $recipient->refresh();
        $this->assertSame('sent', $recipient->status);
        $this->assertNull($recipient->sent_at);
    }


    public function test_sent_feedback_does_not_downgrade_opened_evidence(): void
    {
        $recipient = $this->recipient('opened');
        $recipient->update(['sent_at' => null, 'opened_at' => now()->subMinute()]);
        $sentAt = now()->subMinutes(10)->startOfSecond();

        app(CampaignFeedbackService::class)->apply($recipient, 'sent', $sentAt, null, 'zoho');

        $recipient->refresh();
        $this->assertSame('opened', $recipient->status);
        $this->assertTrue($recipient->sent_at->equalTo($sentAt));
        $this->assertNotNull($recipient->opened_at);
    }

    /** @dataProvider terminalRecipientStatuses */
    public function test_delayed_hard_bounce_preserves_a_terminal_recipient_status(string $status): void
    {
        $recipient = $this->recipient($status);
        $occurredAt = now()->startOfSecond();

        app(CampaignFeedbackService::class)->apply(
            $recipient,
            'hard_bounce',
            $occurredAt,
            'Delayed bounce',
            'zoho',
        );

        $recipient->refresh();
        $this->assertSame($status, $recipient->status);
        $this->assertSame('Delayed bounce', $recipient->bounce_reason);
        $this->assertTrue($recipient->bounced_at->equalTo($occurredAt));
        $this->assertDatabaseHas('suppressions', [
            'email' => 'feedback@example.test',
            'reason' => 'hard_bounce',
        ]);
    }

    public static function terminalRecipientStatuses(): array
    {
        return [
            'replied' => ['replied'],
            'unsubscribed' => ['unsubscribed'],
        ];
    }
}
