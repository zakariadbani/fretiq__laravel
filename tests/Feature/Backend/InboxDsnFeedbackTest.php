<?php

namespace Tests\Feature\Backend;

use App\Jobs\FetchInboxJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Services\Inbox\DeliveryStatusNotificationParser;
use App\Services\Inbox\InboxImapService;
use App\Services\Inbox\ReplyMatchingService;
use Carbon\Carbon;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InboxDsnFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_accepts_only_structured_campaign_hard_bounces(): void
    {
        $result = app(DeliveryStatusNotificationParser::class)->parse(implode("\r\n", [
            'Content-Type: multipart/report; report-type=delivery-status',
            'Original-Message-ID: <campaign-recipient-42@fretiq.local>',
            'Action: failed',
            'Status: 5.1.1',
            'Diagnostic-Code: smtp; 550 5.1.1 verification@example.test unavailable',
        ]));

        $this->assertSame([
            'target_type' => 'campaign_recipient',
            'target_id' => 42,
            'outcome' => 'hard_bounce',
            'detail' => 'smtp; 550 5.1.1 [redacted] unavailable',
        ], $result);
    }

    public function test_parser_accepts_structured_sequence_soft_bounces_and_rejects_free_text(): void
    {
        $parser = app(DeliveryStatusNotificationParser::class);
        $result = $parser->parse("Content-Type: message/delivery-status\nOriginal-Message-ID: <sequence-send-84@fretiq.local>\nAction: delayed\nStatus: 4.2.0");

        $this->assertSame('sequence_send', $result['target_type']);
        $this->assertSame(84, $result['target_id']);
        $this->assertSame('soft_bounce', $result['outcome']);
        $this->assertNull($parser->parse('Mailbox unavailable: campaign-recipient-84@fretiq.local'));
        $this->assertNull($parser->parse("Original-Message-ID: <campaign-recipient-84@fretiq.local>\nAction: failed\nStatus: 5.1.1"));
    }

    public function test_fetch_job_applies_a_matching_dsn_without_treating_it_as_a_reply(): void
    {
        $recipient = $this->recipient();
        $identity = SenderIdentity::create([
            'name' => 'DSN inbox', 'email' => 'sender@example.test', 'is_active' => true,
            'imap_host' => 'imap.example.test', 'imap_username' => 'inbox@example.test',
            'imap_password' => 'secret', 'imap_enabled' => true,
        ]);
        $raw = "Content-Type: multipart/report; report-type=delivery-status\r\nOriginal-Message-ID: <campaign-recipient-{$recipient->id}@fretiq.local>\r\nAction: failed\r\nStatus: 5.1.1";

        (new FetchInboxJob($identity->id))->handle(new DsnInboxImapService($raw), new DsnFailingMatcher);

        $this->assertSame('bounced', $recipient->fresh()->status);
        $this->assertSame('hard', $recipient->fresh()->bounce_type);
    }

    private function recipient(): CampaignRecipient
    {
        $company = Company::create(['name' => 'DSN Co', 'relationship' => 'client', 'source' => 'manual', 'qualification_status' => 'pending']);
        $contact = Contact::create([
            'company_id' => $company->id, 'email' => 'dsn@example.test', 'name' => 'DSN',
            'status' => 'new', 'source' => 'manual', 'legal_basis' => 'relationship', 'email_kind' => 'role',
        ]);
        $segment = Segment::create(['name' => 'DSN segment', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'DSN template', 'subject' => 'DSN', 'html_content' => '<p>DSN</p>']);
        $sender = SenderIdentity::create(['name' => 'DSN sender', 'email' => 'campaign-sender@example.test']);
        $campaign = Campaign::create([
            'name' => 'DSN campaign', 'segment_id' => $segment->id, 'template_id' => $template->id,
            'sender_identity_id' => $sender->id, 'schedule_type' => 'one_shot', 'scheduled_at' => now(), 'timezone' => 'Europe/Paris',
        ]);
        $run = CampaignRun::create(['campaign_id' => $campaign->id, 'occurrence_key' => 'dsn-'.uniqid(), 'run_at' => now(), 'status' => 'sent']);

        return CampaignRecipient::create([
            'campaign_run_id' => $run->id, 'contact_id' => $contact->id, 'status' => 'sent', 'sent_at' => now()->subMinute(),
        ]);
    }
}

class DsnInboxImapService extends InboxImapService
{
    public function __construct(private readonly string $raw) {}

    public function streamAll(SenderIdentity $identity, ?Carbon $since = null): Generator
    {
        yield (object) [];
    }

    public function rawMessage(mixed $message): string
    {
        return $this->raw;
    }
}

class DsnFailingMatcher extends ReplyMatchingService
{
    public function match(\App\Models\InboxEmail $email, ?string $references = null): void
    {
        throw new RuntimeException('A DSN must not be passed to the reply matcher.');
    }
}
