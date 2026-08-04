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
        $this->assertTrue($recipient->bounced_at->equalTo($occurredAt));
        $this->assertDatabaseHas('suppressions', [
            'email' => 'feedback@example.test',
            'reason' => 'hard_bounce',
            'source' => 'zoho',
        ]);
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
