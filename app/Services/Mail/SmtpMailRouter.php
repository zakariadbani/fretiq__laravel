<?php

namespace App\Services\Mail;

use App\Models\SenderIdentity;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class SmtpMailRouter
{
    public function mode(): string
    {
        $mode = (string) config('mail.smtp_mode');
        if (! in_array($mode, ['mailpit', 'sender_identity'], true)) {
            throw new SmtpConfigurationException('Configuration SMTP invalide : SMTP_MODE doit être mailpit ou sender_identity.');
        }

        return $mode;
    }

    public function usesSenderIdentityTransport(): bool
    {
        return $this->mode() === 'sender_identity';
    }

    public function transportLabel(?SenderIdentity $sender = null): string
    {
        return $this->mode() === 'mailpit' ? 'Mailpit' : ($sender ? 'SMTP de l’identité' : 'SMTP par défaut');
    }

    public function send(?SenderIdentity $sender, string|array $recipients, Mailable $message): ?SentMessage
    {
        if ($sender !== null) {
            $message->from($sender->email, $sender->name);

            if (filled($sender->reply_to)) {
                $message->replyTo($sender->reply_to, $sender->name);
            }
        }

        return $this->resolveMailer($sender)->to($recipients)->send($message);
    }

    /** The notification adapter uses this same resolver as application mail. */
    public function mailerFor(?SenderIdentity $sender = null): MailerContract
    {
        return $this->resolveMailer($sender);
    }

    private function resolveMailer(?SenderIdentity $sender = null): MailerContract
    {
        if ($this->mode() === 'mailpit') {
            return Mail::mailer('mailpit');
        }

        if ($sender === null) {
            return Mail::mailer('smtp');
        }

        if (! $sender->hasCompleteSmtpConfiguration()) {
            throw new SmtpConfigurationException('Configuration SMTP incomplète pour cette identité.');
        }

        $encryption = (string) $sender->smtp_encryption;
        if (! in_array($encryption, ['tls', 'ssl'], true)) {
            throw new SmtpConfigurationException('Une connexion SMTP chiffrée (TLS ou SSL) est obligatoire.');
        }

        $mailer = app(MailManager::class)->build([
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'auto_tls' => $encryption === 'tls',
            'host' => (string) $sender->smtp_host,
            'port' => (int) $sender->smtp_port,
            'username' => (string) $sender->smtp_username,
            'password' => (string) $sender->smtp_password,
            'timeout' => 30,
        ]);

        if ($encryption === 'tls') {
            $transport = new RequiredTlsEsmtpTransport((string) $sender->smtp_host, (int) $sender->smtp_port, false);
            $transport->setAutoTls(true)
                ->setUsername((string) $sender->smtp_username)
                ->setPassword((string) $sender->smtp_password);
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(30);
            }
            $mailer->setSymfonyTransport($transport);
        }

        return $mailer;
    }
}
