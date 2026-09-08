<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** Archives only after the delegated SMTP transport has accepted a message. */
class SentCopyArchivingTransport implements TransportInterface
{
    public function __construct(
        private readonly TransportInterface $delegate,
        private readonly SmtpSentCopyDispatcher $dispatcher,
        private readonly ?int $senderIdentityId,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $sent = $this->delegate->send($message, $envelope);

        if ($sent !== null) {
            try {
                $this->dispatcher->dispatchAfterAccepted($sent, $this->senderIdentityId);
            } catch (\Throwable) {
                try {
                    Log::warning('SMTP Sent copy was not queued.', ['reason' => 'queue_failure']);
                } catch (\Throwable) {
                }
            }
        }

        return $sent;
    }

    public function __toString(): string
    {
        return (string) $this->delegate;
    }
}
