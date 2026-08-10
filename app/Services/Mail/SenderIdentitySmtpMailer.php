<?php

namespace App\Services\Mail;

use App\Models\SenderIdentity;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class SenderIdentitySmtpMailer
{
    public function usesSenderIdentityTransport(): bool
    {
        return (string) config('app.env') === 'production'
            && config('prospecting.smtp.mode', 'mailpit') === 'sender_identity';
    }

    public function send(SenderIdentity $identity, string $recipient, Mailable $mailable): void
    {
        if (! $this->usesSenderIdentityTransport()) {
            Mail::to($recipient)->send($mailable);

            return;
        }

        if (! $identity->hasCompleteSmtpConfiguration()) {
            throw new RuntimeException('Configuration SMTP incomplète pour cette identité.');
        }

        $encryption = (string) $identity->smtp_encryption;
        if ($encryption === 'none') {
            throw new RuntimeException('Une connexion SMTP chiffrée (TLS ou SSL) est obligatoire en production.');
        }

        $mailer = app(MailManager::class)->build([
            'transport' => 'smtp',
            // Symfony's SMTP transport consumes scheme/auto_tls, not Laravel's
            // historical "encryption" option when built dynamically.
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'auto_tls' => $encryption === 'tls',
            'host' => (string) $identity->smtp_host,
            'port' => (int) $identity->smtp_port,
            'username' => (string) $identity->smtp_username,
            'password' => (string) $identity->smtp_password,
            'timeout' => 30,
        ]);

        if ($encryption === 'tls') {
            $transport = new RequiredTlsEsmtpTransport(
                (string) $identity->smtp_host,
                (int) $identity->smtp_port,
                false,
            );
            $transport->setAutoTls(true)
                ->setUsername((string) $identity->smtp_username)
                ->setPassword((string) $identity->smtp_password);
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(30);
            }
            $mailer->setSymfonyTransport($transport);
        }

        $mailer->to($recipient)->send($mailable);
    }
}
