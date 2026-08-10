<?php

namespace App\Services\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/** SMTP transport that never authenticates or sends a message before STARTTLS. */
class RequiredTlsEsmtpTransport extends EsmtpTransport
{
    private bool $startTlsAccepted = false;

    public function executeCommand(string $command, array $codes): string
    {
        $verb = strtoupper((string) strtok(ltrim($command), " \t\r\n"));
        if (in_array($verb, ['AUTH', 'MAIL', 'RCPT', 'DATA'], true)) {
            if (! $this->hasEncryptedChannel()) {
                throw new TransportException('STARTTLS est obligatoire avant toute authentification ou livraison SMTP.');
            }
        }

        $response = parent::executeCommand($command, $codes);
        if ($verb === 'STARTTLS') {
            $this->markStartTlsAccepted();
        }

        return $response;
    }

    protected function markStartTlsAccepted(): void
    {
        $this->startTlsAccepted = true;
    }

    protected function hasEncryptedChannel(): bool
    {
        $stream = $this->getStream();

        return $stream instanceof SocketStream
            && ($stream->isTLS() || $this->startTlsAccepted);
    }
}
