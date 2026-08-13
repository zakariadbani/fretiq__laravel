<?php

namespace Tests\Unit\Services\Mail;

use App\Models\SenderIdentity;
use App\Models\User;
use App\Notifications\Channels\RoutedMailChannel;
use App\Services\Mail\SmtpConfigurationException;
use App\Services\Mail\SmtpMailRouter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class SmtpMailRouterTest extends TestCase
{
    public function test_mailpit_with_identity_uses_mailpit_and_identity_from_and_explicit_reply_to(): void
    {
        $transport = $this->arrayTransportFor('mailpit', 'mailpit');
        $identity = $this->identity(['reply_to' => 'reply@example.test']);

        app(SmtpMailRouter::class)->send(
            $identity,
            'recipient@example.test',
            new RouterTestMailable,
        );

        $message = $this->onlyMessage($transport);
        $this->assertSame('recipient@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame('sender@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame('Router sender', $message->getFrom()[0]->getName());
        $this->assertSame('reply@example.test', $message->getReplyTo()[0]->getAddress());
    }

    public function test_mailpit_without_identity_uses_global_from_and_has_no_reply_to(): void
    {
        $transport = $this->arrayTransportFor('mailpit', 'mailpit');

        app(SmtpMailRouter::class)->send(
            null,
            'recipient@example.test',
            new RouterTestMailable,
        );

        $message = $this->onlyMessage($transport);
        $this->assertSame('global@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame('Global sender', $message->getFrom()[0]->getName());
        $this->assertSame([], $message->getReplyTo());
    }

    public function test_sender_identity_mode_with_identity_uses_saved_smtp_in_local(): void
    {
        config(['mail.smtp_mode' => 'sender_identity']);
        $arrayMailer = app(MailManager::class)->mailer('array');
        $transport = $arrayMailer->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')
            ->once()
            ->with(Mockery::on(fn (array $config): bool => $config['host'] === 'smtp.identity.test'
                && $config['username'] === 'identity-user'
                && $config['scheme'] === 'smtps'))
            ->andReturn($arrayMailer);
        $this->app->instance(MailManager::class, $manager);

        app(SmtpMailRouter::class)->send(
            $this->identity(),
            'recipient@example.test',
            new RouterTestMailable,
        );

        $message = $this->onlyMessage($transport);
        $this->assertSame('sender@example.test', $message->getFrom()[0]->getAddress());
    }

    public function test_sender_identity_mode_without_identity_uses_global_smtp_and_from(): void
    {
        $transport = $this->arrayTransportFor('smtp', 'sender_identity');

        app(SmtpMailRouter::class)->send(
            null,
            'recipient@example.test',
            new RouterTestMailable,
        );

        $message = $this->onlyMessage($transport);
        $this->assertSame('global@example.test', $message->getFrom()[0]->getAddress());
        $this->assertSame([], $message->getReplyTo());
    }

    #[DataProvider('invalidModes')]
    public function test_missing_or_invalid_mode_fails_closed(?string $mode): void
    {
        config(['mail.smtp_mode' => $mode]);

        $this->expectException(SmtpConfigurationException::class);
        $this->expectExceptionMessage('SMTP_MODE doit être mailpit ou sender_identity');

        app(SmtpMailRouter::class)->mode();
    }

    public static function invalidModes(): array
    {
        return [
            'missing' => [null],
            'invalid' => ['smtp'],
        ];
    }

    public function test_sender_identity_mode_rejects_incomplete_identity_before_transport(): void
    {
        config(['mail.smtp_mode' => 'sender_identity']);

        $this->expectException(SmtpConfigurationException::class);
        $this->expectExceptionMessage('Configuration SMTP incomplète');

        app(SmtpMailRouter::class)->mailerFor(new SenderIdentity([
            'name' => 'Incomplete',
            'email' => 'incomplete@example.test',
        ]));
    }

    public function test_framework_mail_channel_resolves_to_the_routed_adapter(): void
    {
        $this->assertInstanceOf(RoutedMailChannel::class, app(MailChannel::class));
    }

    public function test_password_reset_notification_uses_the_router_selected_mailpit_transport(): void
    {
        $transport = $this->arrayTransportFor('mailpit', 'mailpit');

        $this->notifiableUser()->notify(new ResetPassword('reset-token'));

        $message = $this->onlyMessage($transport);
        $this->assertSame('notification@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('Reset Password', (string) $message->getSubject());
    }

    public function test_email_verification_notification_uses_the_router_selected_mailpit_transport(): void
    {
        $transport = $this->arrayTransportFor('mailpit', 'mailpit');

        $this->notifiableUser()->notify(new VerifyEmail);

        $message = $this->onlyMessage($transport);
        $this->assertSame('notification@example.test', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('Verify Email', (string) $message->getSubject());
    }

    private function arrayTransportFor(string $mailer, string $mode): ArrayTransport
    {
        config([
            'mail.smtp_mode' => $mode,
            'mail.from.address' => 'global@example.test',
            'mail.from.name' => 'Global sender',
            "mail.mailers.{$mailer}" => ['transport' => 'array'],
        ]);
        Mail::forgetMailers();

        $transport = Mail::mailer($mailer)->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    private function onlyMessage(ArrayTransport $transport): Email
    {
        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }

    private function identity(array $overrides = []): SenderIdentity
    {
        return new SenderIdentity(array_merge([
            'name' => 'Router sender',
            'email' => 'sender@example.test',
            'is_active' => true,
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.identity.test',
            'smtp_port' => 465,
            'smtp_username' => 'identity-user',
            'smtp_password' => 'identity-password',
            'smtp_encryption' => 'ssl',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ], $overrides));
    }

    private function notifiableUser(): User
    {
        $user = new User([
            'name' => 'Notification user',
            'email' => 'notification@example.test',
        ]);
        $user->id = 123;

        return $user;
    }
}

class RouterTestMailable extends Mailable
{
    public function build(): static
    {
        return $this->subject('Router test')->html('<p>Router test</p>');
    }
}
