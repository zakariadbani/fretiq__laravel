<?php

namespace Tests\Feature\Backend;

use App\Jobs\ArchiveSmtpSentCopyJob;
use App\Jobs\SendSmtpReservationJob;
use App\Mail\SmtpConnectionTestMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SmtpSendReservation;
use App\Models\User;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\SequenceService;
use App\Services\Campaign\SmtpCampaignsDriver;
use App\Services\Campaign\SmtpSendReservationService;
use App\Services\Mail\AmbiguousSentCopyAppendException;
use App\Services\Mail\RequiredTlsEsmtpTransport;
use App\Services\Mail\SentCopyArchivingTransport;
use App\Services\Mail\SmtpMailRouter;
use App\Services\Mail\SmtpSentCopyImapArchiver;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\RecordingSmtpTransport;
use Tests\TestCase;

class SmtpSentCopyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || $app->make('db')->connection()->getDatabaseName() !== 'fretiq_test') {
            throw new \RuntimeException('Sent-copy tests require the disposable fretiq_test database.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.smtp_mode' => 'sender_identity', 'mail.smtp_sent_copy.enabled' => true,
            'mail.smtp_sent_copy.folder' => 'INBOX.Sent', 'mail.from.address' => 'sender@example.test',
            'mail.from.name' => 'Fixture sender', 'services.zoho.driver' => 'local']);
    }

    public function test_transmitted_mime_is_encrypted_in_the_database_queue_with_no_credentials(): void
    {
        $transport = $this->transport();
        $identity = $this->identity();
        app(SmtpMailRouter::class)->send($identity, 'recipient@example.test', $this->mailable());
        $row = DB::table('jobs')->sole();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('default', $row->queue);
        $this->assertStringNotContainsString('private-body-marker', $row->payload);
        $this->assertStringNotContainsString('fixture-imap-password', $row->payload);
        $job = unserialize(app('encrypter')->decrypt($payload['data']['command']));
        $this->assertInstanceOf(ArchiveSmtpSentCopyJob::class, $job);
        $this->assertSame($transport->mime[0], $job->mime);
        $this->assertStringContainsString('List-Unsubscribe:', $job->mime);
        $this->assertStringContainsString('Content-Disposition: attachment;', $job->mime);
        $this->assertStringContainsString('private-body-marker', $job->mime);
        $this->assertStringContainsString('private-text-marker', $job->mime);
        $this->assertNotSame('provider-queue-id', $job->messageId);
        $this->assertSame(5, $payload['maxTries']);
        $this->assertSame('60,300,900,3600', $payload['backoff']);
        $this->assertTrue($payload['failOnTimeout']);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $payload['timeout']);
    }

    #[DataProvider('identityCounts')]
    public function test_default_smtp_uses_only_one_active_identity_matching_from(int $count, int $expected): void
    {
        Queue::fake();
        $this->transport();
        for ($i = 0; $i < $count; $i++) {
            $this->identity();
        }
        $this->identity(['email' => 'reply@example.test']);
        app(SmtpMailRouter::class)->send(null, 'recipient@example.test', $this->mailable());
        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, $expected);
    }

    public static function identityCounts(): array
    {
        return ['missing' => [0, 0], 'unique' => [1, 1], 'ambiguous' => [2, 0]];
    }

    public function test_cached_mailer_is_wrapped_once_and_disabling_stops_new_jobs(): void
    {
        Queue::fake();
        $transport = $this->transport();
        $this->identity();
        $router = app(SmtpMailRouter::class);
        $router->send(null, 'recipient@example.test', $this->mailable());
        $first = $router->mailerFor()->getSymfonyTransport();
        $router->send(null, 'recipient@example.test', $this->mailable());
        $this->assertSame($first, $router->mailerFor()->getSymfonyTransport());
        config(['mail.smtp_sent_copy.enabled' => false]);
        $router->send(null, 'recipient@example.test', $this->mailable());
        $this->assertCount(3, $transport->messages);
        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, 2);
    }

    public function test_smtp_rejection_never_creates_an_archive_job(): void
    {
        Queue::fake();
        $transport = $this->transport();
        $identity = $this->identity();
        $transport->failure = new TransportException('fixture rejection');
        try {
            app(SmtpMailRouter::class)->send($identity, 'recipient@example.test', $this->mailable());
            $this->fail('SMTP rejection must propagate.');
        } catch (TransportException $exception) {
            $this->assertSame('fixture rejection', $exception->getMessage());
        }
        Queue::assertNothingPushed();
    }

    public function test_queue_and_logger_failure_cannot_reverse_smtp_acceptance(): void
    {
        $transport = $this->transport();
        $identity = $this->identity();
        Queue::shouldReceive('connection')->with('database')->andThrow(new \RuntimeException('private queue error'));
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('private logger error'));
        $sent = app(SmtpMailRouter::class)->send($identity, 'recipient@example.test', $this->mailable());
        $this->assertNotNull($sent);
        $this->assertCount(1, $transport->messages);
    }

    public function test_mailpit_and_non_smtp_default_transports_do_not_archive(): void
    {
        Queue::fake();
        $this->identity();
        foreach (['mailpit', 'sender_identity'] as $mode) {
            config(['mail.smtp_mode' => $mode, 'mail.mailers.mailpit.transport' => 'array', 'mail.mailers.smtp.transport' => 'array']);
            Mail::forgetMailers();
            app(SmtpMailRouter::class)->send(null, 'recipient@example.test', $this->mailable());
        }
        Queue::assertNothingPushed();
    }

    public function test_enabled_archive_preserves_required_tls_transport(): void
    {
        $identity = $this->identity(['smtp_encryption' => 'tls']);
        $wrapped = app(SmtpMailRouter::class)->mailerFor($identity)->getSymfonyTransport();
        $this->assertInstanceOf(SentCopyArchivingTransport::class, $wrapped);
        $delegate = (new \ReflectionProperty($wrapped, 'delegate'))->getValue($wrapped);
        $this->assertInstanceOf(RequiredTlsEsmtpTransport::class, $delegate);
        $this->expectException(TransportException::class);
        $delegate->executeCommand("AUTH LOGIN\r\n", [334]);
    }

    #[DataProvider('previewModes')]
    public function test_campaign_and_sequence_preview_endpoints_archive_without_operational_records(bool $sequence): void
    {
        Queue::fake();
        $transport = $this->transport();
        $user = $this->admin();
        $campaign = $this->campaign($this->identity(), $sequence);
        $this->actingAs($user)->postJson(route('admin.campaigns.testSend', $campaign), ['recipient_email' => 'preview@example.test'])
            ->assertOk();
        $this->assertCount(1, $transport->messages);
        $body = $transport->messages[0]->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Preview Person', $body);
        $this->assertStringContainsString('/track/open/', $body);
        $this->assertStringContainsString('List-Unsubscribe:', $transport->mime[0]);
        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, fn ($job) => $job->mime === $transport->mime[0]);
        $this->assertNoOperationalRows();
    }

    public static function previewModes(): array
    {
        return ['campaign' => [false], 'sequence' => [true]];
    }

    public function test_sender_connection_test_uses_the_same_archive_hook(): void
    {
        Queue::fake();
        $transport = $this->transport();
        $identity = $this->identity();
        $this->actingAs($this->admin())->postJson(route('admin.sender_identities.testSmtp', $identity), ['receiver_email' => 'preview@example.test'])->assertOk();
        $this->assertCount(1, $transport->messages);
        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, 1);
        $this->assertNoOperationalRows();
    }

    public function test_both_notification_formats_use_the_same_archive_hook(): void
    {
        Queue::fake();
        $transport = $this->transport();
        $this->identity();
        $user = User::factory()->create(['email' => 'notification@example.test']);
        $user->notify(new ResetPassword('fixture-reset-token'));
        $user->notify(new class extends Notification
        {
            public function via($notifiable): array
            {
                return ['mail'];
            }

            public function toMail($notifiable): Mailable
            {
                return (new SmtpConnectionTestMailable(new SenderIdentity(['name' => 'Fixture sender', 'email' => 'sender@example.test'])))->to($notifiable->email);
            }
        });
        $this->assertCount(2, $transport->messages);
        $this->assertStringContainsString('fixture-reset-token', $transport->messages[0]->getOriginalMessage()->getHtmlBody());
        Queue::assertPushed(ArchiveSmtpSentCopyJob::class, 2);
    }

    public function test_queued_copy_uses_fresh_mailbox_settings_even_after_disabling_and_deactivation(): void
    {
        Queue::fake();
        $this->transport();
        $identity = $this->identity(['imap_enabled' => false]);
        app(SmtpMailRouter::class)->send($identity, 'recipient@example.test', $this->mailable());
        $job = Queue::pushed(ArchiveSmtpSentCopyJob::class)->first();
        $identity->update(['is_active' => false, 'imap_password' => 'new-fixture-password']);
        config(['mail.smtp_sent_copy.enabled' => false]);
        $archiver = Mockery::mock(SmtpSentCopyImapArchiver::class);
        $archiver->shouldReceive('archive')->once()->with(Mockery::on(fn ($fresh) => $fresh->imap_password === 'new-fixture-password' && ! $fresh->is_active), 'INBOX.Sent', $job->messageId, $job->mime, $job->sentAt);
        $job->handle($archiver);
    }

    public function test_archive_lock_contention_prevents_append_and_releases_for_a_later_attempt(): void
    {
        $identity = $this->identity();
        $job = $this->job($identity);
        $lock = Cache::lock('smtp-sent-copy:'.$identity->id.':'.hash('sha256', $job->messageId), 600);
        $this->assertTrue($lock->get());
        $archiver = Mockery::mock(SmtpSentCopyImapArchiver::class);
        $archiver->shouldNotReceive('archive');
        try {
            $job->handle($archiver);
            $this->fail('Contended archive must be retryable.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP Sent copy archive failed.', $exception->getMessage());
        } finally {
            $lock->release();
        }
        $successful = Mockery::mock(SmtpSentCopyImapArchiver::class);
        $successful->shouldReceive('archive')->once();
        $job->handle($successful);
    }

    public function test_unknown_append_is_parked_with_a_sanitized_failure_and_never_retried_inline(): void
    {
        $job = $this->job($this->identity());
        $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('fail')->once()->with(Mockery::on(fn ($exception) => $exception->getMessage() === 'SMTP Sent copy append outcome is uncertain.' && $exception->getPrevious() === null));
        $job->setJob($queueJob);
        $archiver = Mockery::mock(SmtpSentCopyImapArchiver::class);
        $archiver->shouldReceive('archive')->once()->andThrow(new AmbiguousSentCopyAppendException('private upstream content'));
        $job->handle($archiver);
    }

    public function test_pre_append_failure_is_retryable_and_sanitized(): void
    {
        $job = $this->job($this->identity());
        $archiver = Mockery::mock(SmtpSentCopyImapArchiver::class);
        $archiver->shouldReceive('archive')->once()->andThrow(new \RuntimeException('private upstream content'));
        try {
            $job->handle($archiver);
            $this->fail('Safe failure must reach the queue retry policy.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('SMTP Sent copy archive failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_archive_enqueue_failure_does_not_rearm_an_accepted_campaign_reservation(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-09T10:00:00Z'));
        Queue::fake();
        $transport = $this->transport();
        $campaign = $this->campaign($this->identity());
        $company = Company::create(['name' => 'Fixture client', 'relationship' => 'client', 'source' => 'manual']);
        Contact::create(['company_id' => $company->id, 'name' => 'Fixture contact', 'email' => 'client@example.test', 'status' => 'new', 'source' => 'manual',
            'legal_basis' => 'relationship', 'email_kind' => 'role', 'email_verification_status' => 'valid', 'email_verification_checked_at' => now()]);
        $run = CampaignRun::create(['campaign_id' => $campaign->id, 'occurrence_key' => 'sent-copy-fixture', 'run_at' => now(), 'status' => 'scheduled']);
        app(CampaignService::class)->sendRun($run);
        $reservation = SmtpSendReservation::sole();
        $reservation->update(['reserved_for' => now()->subSecond()]);
        Queue::shouldReceive('connection')->with('database')->andThrow(new \RuntimeException('fixture queue outage'));
        $job = new SendSmtpReservationJob($reservation->id);
        $handle = fn () => $job->handle(app(SmtpSendReservationService::class), app(SmtpCampaignsDriver::class), app(CampaignService::class), app(SequenceService::class));
        $handle();
        $this->assertSame('sent', CampaignRecipient::sole()->status);
        $this->assertSame('sent', $reservation->fresh()->status);
        $handle();
        $this->assertCount(1, $transport->messages);
    }

    private function transport(): RecordingSmtpTransport
    {
        $transport = new RecordingSmtpTransport;
        app(MailManager::class)->extend('smtp', fn () => $transport);
        Mail::forgetMailers();

        return $transport;
    }

    private function identity(array $extra = []): SenderIdentity
    {
        return SenderIdentity::create(array_merge(['name' => 'Fixture sender', 'email' => 'sender@example.test', 'reply_to' => 'reply@example.test',
            'is_active' => true, 'smtp_enabled' => true, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 465,
            'smtp_username' => 'fixture-smtp-user', 'smtp_password' => 'fixture-smtp-password', 'smtp_encryption' => 'ssl',
            'smtp_hourly_limit' => 10, 'smtp_daily_limit' => 50,
            'imap_host' => 'imap.example.test', 'imap_username' => 'fixture-imap-user', 'imap_password' => 'fixture-imap-password', 'imap_enabled' => false], $extra));
    }

    private function mailable(): Mailable
    {
        return new class extends Mailable
        {
            public function build(): static
            {
                return $this->subject('Fixture subject')->html('<p>private-body-marker</p>')
                    ->attachData('attachment-fixture', 'fixture.txt', ['mime' => 'text/plain'])
                    ->withSymfonyMessage(function ($message): void {
                        $message->text('private-text-marker');
                        $message->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.test/unsubscribe>');
                    });
            }
        };
    }

    private function admin(): User
    {
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $user = User::factory()->create(['name' => 'Preview Person', 'email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function campaign(SenderIdentity $identity, bool $sequence = false): Campaign
    {
        $segment = Segment::create(['name' => 'Fixture clients', 'scope' => 'client']);
        $template = CampaignTemplate::create(['name' => 'Fixture template', 'subject' => 'Hello {{contact.name}}', 'html_content' => '<p>Hello {{contact.name}}</p>']);
        $campaign = Campaign::create(['name' => 'Fixture SMTP campaign', 'segment_id' => $segment->id, 'template_id' => $template->id, 'sender_identity_id' => $identity->id,
            'schedule_type' => $sequence ? 'sequence' : 'one_shot', 'delivery_channel' => 'smtp', 'smtp_daily_email_limit' => 10, 'timezone' => 'UTC', 'is_active' => true]);
        if ($sequence) {
            $seq = Sequence::create(['name' => 'Fixture sequence', 'is_active' => false]);
            SequenceStep::create(['sequence_id' => $seq->id, 'step_no' => 1, 'delay_days' => 0, 'template_id' => $template->id]);
            $campaign->update(['sequence_id' => $seq->id]);
        }

        return $campaign;
    }

    private function job(SenderIdentity $identity): ArchiveSmtpSentCopyJob
    {
        return new ArchiveSmtpSentCopyJob($identity->id, '<fixture@example.test>', "Message-ID: <fixture@example.test>\r\n\r\nfixture", new \DateTimeImmutable('2026-09-09T10:00:00Z'), 'INBOX.Sent');
    }

    private function assertNoOperationalRows(): void
    {
        foreach (['campaign_runs', 'campaign_recipients', 'email_tracking_events', 'sequence_enrollments', 'sequence_step_sends', 'smtp_send_reservations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
