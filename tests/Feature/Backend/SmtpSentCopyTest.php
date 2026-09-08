<?php

namespace Tests\Feature\Backend;

use App\Jobs\ArchiveSmtpSentCopyJob;
use App\Models\SenderIdentity;
use App\Services\Mail\SmtpSentCopyDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SmtpSentCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_smtp_mime_queues_an_encrypted_sent_copy_without_credentials(): void
    {
        Queue::fake();
        config(['mail.smtp_sent_copy.enabled' => true, 'mail.smtp_sent_copy.folder' => 'INBOX.Sent']);
        $identity = SenderIdentity::create([
            'name' => 'Archive sender', 'email' => 'archive@example.test', 'is_active' => true,
            'imap_host' => 'imap.example.test', 'imap_username' => 'archive-user', 'imap_password' => 'secret',
        ]);
        $email = (new Email)->from($identity->email)->to('recipient@example.test')->subject('Archived')->text('exact body');
        $sent = new SentMessage($email, Envelope::create($email));

        app(SmtpSentCopyDispatcher::class)->dispatchAfterAccepted($sent, $identity->id);

        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, function (ArchiveSmtpSentCopyJob $job) use ($identity): bool {
            return $job->senderIdentityId === $identity->id
                && $job->folder === 'INBOX.Sent'
                && str_contains($job->mime, 'Subject: Archived')
                && str_contains($job->mime, 'exact body')
                && ! str_contains($job->mime, 'secret');
        });
    }

    public function test_disabled_sent_copy_does_not_queue_after_smtp_acceptance(): void
    {
        Queue::fake();
        config(['mail.smtp_sent_copy.enabled' => false]);
        $email = (new Email)->from('archive@example.test')->to('recipient@example.test')->subject('Disabled')->text('body');

        app(SmtpSentCopyDispatcher::class)->dispatchAfterAccepted(new SentMessage($email, Envelope::create($email)), null);

        Queue::assertNothingPushed();
    }

    public function test_explicit_sender_deactivation_after_acceptance_does_not_discard_its_copy(): void
    {
        Queue::fake();
        config(['mail.smtp_sent_copy.enabled' => true]);
        $identity = SenderIdentity::create(['name' => 'Fixture', 'email' => 'sender@example.test', 'is_active' => false,
            'imap_host' => 'imap.example.test', 'imap_username' => 'fixture', 'imap_password' => 'fixture-secret']);
        $email = (new Email)->from($identity->email)->to('recipient@example.test')->subject('Accepted')->text('fixture');
        app(SmtpSentCopyDispatcher::class)->dispatchAfterAccepted(new SentMessage($email, Envelope::create($email)), $identity->id);
        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, 1);
    }

    public function test_archive_deduplication_uses_the_message_id_in_final_mime_not_the_smtp_provider_id(): void
    {
        Queue::fake();
        config(['mail.smtp_sent_copy.enabled' => true]);
        $identity = SenderIdentity::create([
            'name' => 'Archive sender', 'email' => 'archive@example.test', 'is_active' => true,
            'imap_host' => 'imap.example.test', 'imap_username' => 'archive-user', 'imap_password' => 'secret',
        ]);
        $email = (new Email)->from($identity->email)->to('recipient@example.test')->subject('Provider id')->text('body');
        $sent = new SentMessage($email, Envelope::create($email));
        $mimeMessageId = $sent->getMessageId();
        $sent->setMessageId('smtp-provider-queue-id');

        app(SmtpSentCopyDispatcher::class)->dispatchAfterAccepted($sent, $identity->id);

        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, fn (ArchiveSmtpSentCopyJob $job): bool => $job->messageId === '<'.$mimeMessageId.'>');
    }
}
