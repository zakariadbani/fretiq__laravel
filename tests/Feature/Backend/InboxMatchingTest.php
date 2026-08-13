<?php

namespace Tests\Feature\Backend;

use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\InboxEmail;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepSend;
use App\Services\Inbox\ReplyMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_recipient_thread_links_both_foreign_keys_and_records_reply(): void
    {
        $contact = $this->contact('campaign@example.test');
        $recipient = $this->recipient($contact, 'sent');
        $email = $this->email($contact->email, '<campaign-recipient-' . $recipient->id . '@fretiq.local>');

        app(ReplyMatchingService::class)->match($email);

        $this->assertSame($contact->id, $email->fresh()->contact_id);
        $this->assertSame($recipient->id, $email->fresh()->campaign_recipient_id);
        $this->assertSame('replied', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->replied_at);
    }

    public function test_sequence_thread_links_contact_records_reply_and_stops_enrollment(): void
    {
        $contact = $this->contact('sequence@example.test');
        $sequence = Sequence::create(['name' => 'Reply sequence', 'is_active' => true]);
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'current_step' => 1,
            'status' => 'active',
        ]);
        $send = SequenceStepSend::create(['enrollment_id' => $enrollment->id, 'step_no' => 1, 'status' => 'sent']);
        $email = $this->email($contact->email);

        app(ReplyMatchingService::class)->match($email, '<sequence-send-' . $send->id . '@fretiq.local>');

        $this->assertSame($send->id, $email->fresh()->sequence_step_send_id);
        $this->assertSame($contact->id, $email->fresh()->contact_id);
        $this->assertNull($email->fresh()->campaign_recipient_id);
        $this->assertSame('replied', $send->fresh()->status);
        $this->assertSame('stopped', $enrollment->fresh()->status);
        $this->assertSame('replied', $enrollment->fresh()->stopped_reason);
    }

    public function test_case_insensitive_sender_email_is_used_as_fallback(): void
    {
        $contact = $this->contact('MixedCase@Example.Test');
        $email = $this->email('mixedcase@example.test');

        app(ReplyMatchingService::class)->match($email, '<not-a-fretiq-id@example.test>');

        $this->assertSame($contact->id, $email->fresh()->contact_id);
        $this->assertNull($email->fresh()->campaign_recipient_id);
        $this->assertSame(InboxEmail::STATUS_NOUVEAU, $email->fresh()->status);
    }

    public function test_unknown_thread_headers_leave_message_unmatched(): void
    {
        $email = $this->email('nobody@example.test', '<garbage>');

        app(ReplyMatchingService::class)->match($email, 'invalid references');

        $this->assertNull($email->fresh()->contact_id);
        $this->assertNull($email->fresh()->campaign_recipient_id);
        $this->assertSame(InboxEmail::STATUS_NOUVEAU, $email->fresh()->status);
    }

    public function test_sequence_mailable_exposes_the_thread_message_id(): void
    {
        $contact = $this->contact('header@example.test');
        $messageId = 'sequence-send-321@fretiq.local';
        $mailable = new SequenceStepMailable(
            step: new SequenceStep(),
            contact: $contact,
            subjectLine: 'Follow-up',
            trackingToken: 'tracking-token',
            unsubscribeUrl: 'https://example.test/unsubscribe',
            messageId: $messageId,
        );

        $this->assertSame($messageId, $mailable->headers()->messageId);
    }

    public function test_forged_thread_header_does_not_link_an_unrelated_sender(): void
    {
        $contact = $this->contact('real-prospect@example.test');
        $recipient = $this->recipient($contact, 'sent');
        $email = $this->email('attacker@example.test', '<campaign-recipient-' . $recipient->id . '@fretiq.local>');

        app(ReplyMatchingService::class)->match($email);

        $this->assertNull($email->fresh()->contact_id);
        $this->assertNull($email->fresh()->campaign_recipient_id);
        $this->assertSame('sent', $recipient->fresh()->status);
    }

    private function contact(string $email): Contact
    {
        $company = Company::create(['name' => $email, 'source' => 'manual']);

        return Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'name' => $email,
            'source' => 'manual',
            'email_kind' => 'role',
        ]);
    }

    private function recipient(Contact $contact, string $status): CampaignRecipient
    {
        $segment = Segment::create(['name' => 'Reply segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Reply template',
            'subject' => 'Reply subject',
            'html_content' => '<p>Hello</p>',
        ]);
        $sender = SenderIdentity::create(['name' => 'Reply sender', 'email' => 'sender@example.test']);
        $campaign = Campaign::create([
            'name' => 'Reply campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
        ]);
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'reply-' . uniqid(),
            'run_at' => now(),
            'status' => 'sent',
        ]);

        return CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => $status,
        ]);
    }

    private function email(string $from, ?string $inReplyTo = null): InboxEmail
    {
        return InboxEmail::create([
            'message_id' => uniqid('message-', true) . '@example.test',
            'in_reply_to' => $inReplyTo,
            'from_email' => $from,
            'subject' => 'Reply',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'received_at' => now(),
        ]);
    }
}
