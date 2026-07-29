<?php

namespace Tests\Feature\Backend;

use App\Models\InboxEmail;
use App\Models\SenderIdentity;
use App\Services\Inbox\InboxImapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingestion_deduplicates_persists_threading_and_sanitizes_html(): void
    {
        $identity = $this->identity();
        $service = app(InboxImapService::class);
        $message = new InboxMessageStub(
            messageId: '<reply-123@example.test>',
            from: 'Prospect@Example.Test',
            html: '<p>Hello</p><script>alert(1)</script><img src="https://tracker.test/pixel" onerror="alert(2)">',
            inReplyTo: '<campaign-recipient-42@fretiq.local>',
            references: '<older@example.test> <campaign-recipient-42@fretiq.local>',
        );

        $stored = $service->storeMessage($identity, $message);

        $this->assertNotNull($stored);
        $this->assertSame('reply-123@example.test', $stored->message_id);
        $this->assertSame('<campaign-recipient-42@fretiq.local>', $stored->in_reply_to);
        $this->assertSame('prospect@example.test', $stored->from_email);
        $this->assertStringNotContainsStringIgnoringCase('<script', $stored->body_html);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $stored->body_html);
        $this->assertStringNotContainsString('https://tracker.test', $stored->body_html);
        $this->assertSame('<older@example.test> <campaign-recipient-42@fretiq.local>', $service->threadReferences($message));
        $this->assertNull($service->storeMessage($identity, $message));
        $this->assertSame(1, InboxEmail::count());
    }

    public function test_missing_and_oversized_message_ids_get_deterministic_hashes(): void
    {
        $identity = $this->identity();
        $service = app(InboxImapService::class);
        $missing = new InboxMessageStub(messageId: '', from: 'one@example.test', subject: 'Missing ID');

        $first = $service->storeMessage($identity, $missing);
        $this->assertMatchesRegularExpression('/^sha1:[a-f0-9]{40}$/', $first->message_id);
        $this->assertNull($service->storeMessage($identity, $missing));
        $sameMetadataDifferentBody = $service->storeMessage($identity, new InboxMessageStub(
            messageId: '',
            from: 'one@example.test',
            subject: 'Missing ID',
            html: '<p>Different reply body</p>',
        ));
        $this->assertNotNull($sameMetadataDifferentBody);
        $this->assertNotSame($first->message_id, $sameMetadataDifferentBody->message_id);


        $oversizedId = str_repeat('x', 192);
        $oversized = $service->storeMessage($identity, new InboxMessageStub(
            messageId: $oversizedId,
            from: 'two@example.test',
            subject: 'Oversized ID',
        ));
        $this->assertSame('sha1:' . sha1($oversizedId), $oversized->message_id);
        $this->assertLessThanOrEqual(191, strlen($oversized->message_id));
    }

    public function test_messages_sent_by_the_identity_are_excluded(): void
    {
        $identity = $this->identity();
        $message = new InboxMessageStub(messageId: 'self@example.test', from: 'SENDER@EXAMPLE.TEST');

        $this->assertNull(app(InboxImapService::class)->storeMessage($identity, $message));
        $this->assertSame(0, InboxEmail::count());
    }

    private function identity(): SenderIdentity
    {
        return SenderIdentity::create([
            'name' => 'Inbox sender',
            'email' => 'sender@example.test',
            'is_active' => true,
        ]);
    }
}

class InboxMessageStub
{
    public string $in_reply_to;
    public string $references;

    public function __construct(
        private readonly string $messageId,
        private readonly string $from,
        private readonly string $subject = 'Reply subject',
        private readonly string $html = '<p>Reply body</p>',
        string $inReplyTo = '',
        string $references = '',
        private readonly string $date = '2026-07-28 10:00:00',
    ) {
        $this->in_reply_to = $inReplyTo;
        $this->references = $references;
    }

    public function getFrom(): array
    {
        return [(object) ['mail' => $this->from, 'personal' => 'Prospect']];
    }

    public function getTo(): array
    {
        return [(object) ['mail' => 'sender@example.test']];
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getHTMLBody(): string
    {
        return $this->html;
    }

    public function getTextBody(): string
    {
        return '';
    }
}
