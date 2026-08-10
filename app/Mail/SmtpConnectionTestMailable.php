<?php

namespace App\Mail;

use App\Models\SenderIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SmtpConnectionTestMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly SenderIdentity $identity)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->identity->email, $this->identity->name),
            subject: 'Test SMTP Fretiq — ' . $this->identity->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.smtp-connection-test',
            with: ['identityName' => $this->identity->name],
        );
    }
}
