<?php

namespace Tests\Support;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

/** Exercises Symfony's MIME/envelope lifecycle without opening a socket. */
class RecordingSmtpTransport extends SmtpTransport
{
    public array $messages = [];

    public array $mime = [];

    public ?\Throwable $failure = null;

    protected function doSend(SentMessage $message): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $wire = '';
        foreach ($message->toIterable() as $chunk) {
            $wire .= $chunk;
        }
        $this->mime[] = $wire;
        $this->messages[] = $message;
        $message->setMessageId('provider-queue-id');
    }
}
