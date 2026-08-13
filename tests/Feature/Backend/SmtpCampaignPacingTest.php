<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendSmtpReservationJob;
use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailTrackingEvent;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Models\SmtpSendReservation;
use App\Services\Campaign\CampaignDeliveryResolver;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\SequenceService;
use App\Services\Campaign\SmtpCampaignsDriver;
use App\Services\Campaign\SmtpSendReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmtpCampaignPacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze the same real instant as 09:00 Europe/Paris while keeping the
        // application's UTC storage/parsing contract intact.
        Carbon::setTestNow(Carbon::parse('2026-08-10 07:00:00', 'UTC'));
        config([
            'app.env' => 'testing',
            'services.zoho.driver' => 'local',
            'mail.smtp_mode' => 'mailpit',
        ]);
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_smtp_run_sends_only_one_reserved_recipient_per_job(): void
    {
        [$campaign, $run] = $this->campaignRun(3);

        app(CampaignService::class)->sendRun($run);

        $this->assertSame(3, CampaignRecipient::where('campaign_run_id', $run->id)->count());
        $this->assertSame(1, SmtpSendReservation::count());
        $this->assertSame(0, CampaignRecipient::where('campaign_run_id', $run->id)->whereNotNull('sent_at')->count());
        Queue::assertPushed(SendSmtpReservationJob::class, 1);
    }

    public function test_success_schedules_the_next_recipient_at_its_reserved_time(): void
    {
        [$campaign, $run] = $this->campaignRun(2);
        app(CampaignService::class)->sendRun($run);
        $reservation = SmtpSendReservation::firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        $this->assertSame(1, CampaignRecipient::where('campaign_run_id', $run->id)->whereNotNull('sent_at')->count());
        $this->assertSame(2, SmtpSendReservation::count());
        $this->assertNotNull($campaign->fresh()->delivery_started_at);
        Queue::assertPushed(SendSmtpReservationJob::class, 2);
    }

    public function test_zoho_run_remains_one_run_level_provider_dispatch(): void
    {
        [$campaign] = $this->campaignRun(1, ['delivery_channel' => 'zoho']);
        $driver = app(CampaignDeliveryResolver::class)->resolve($campaign);

        $this->assertSame('local', $driver->driverName(), 'The local safety switch must keep explicit Zoho non-external in tests.');
    }

    public function test_smtp_retry_does_not_resend_a_recipient_with_a_provider_message_id(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        $reservation = SmtpSendReservation::firstOrFail();
        $recipient = CampaignRecipient::firstOrFail();
        $recipient->update(['status' => 'sent', 'provider_message_id' => 'already-sent', 'sent_at' => now()]);
        $reservation->update(['reserved_for' => now()->subSecond()]);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame('sent', $reservation->fresh()->status);
    }

    public function test_switching_from_smtp_before_acceptance_releases_the_slot_and_reschedules_the_run(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        $reservation = SmtpSendReservation::firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);
        $campaign->update(['delivery_channel' => 'zoho']);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
    }

    public function test_due_reservation_sweep_recovers_a_missing_delayed_job(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        Queue::fake();
        Carbon::setTestNow(now()->addMinutes(11));
        SmtpSendReservation::query()->update(['reserved_for' => now()->subMinute()]);

        $this->artisan('smtp:dispatch-reservations')->assertSuccessful();

        Queue::assertPushed(SendSmtpReservationJob::class, 1);
    }

    public function test_lowered_or_zero_limit_is_rechecked_before_transport(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        $reservation = SmtpSendReservation::firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);
        $campaign->update(['smtp_daily_email_limit' => 0]);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame('released', $reservation->fresh()->status);
    }

    public function test_incomplete_sender_configuration_defers_before_any_transport_attempt(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        $reservation = SmtpSendReservation::firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);
        config(['mail.smtp_mode' => 'sender_identity']);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame('reserved', $reservation->fresh()->status);
        $this->assertGreaterThan(now()->timestamp, $reservation->fresh()->reserved_for->timestamp);
        $this->assertSame(0, EmailTrackingEvent::count());
        $this->assertFalse($campaign->fresh()->deliverySettingsLocked());
    }

    public function test_uncertainty_persistence_failure_never_returns_post_transport_work_to_the_queue(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        $reservation = SmtpSendReservation::firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);
        $reservations = \Mockery::mock(SmtpSendReservationService::class)->makePartial();
        $reservations->shouldReceive('markUncertainAfterTransport')
            ->once()
            ->andThrow(new \RuntimeException('uncertainty persistence unavailable'));
        $driver = \Mockery::mock(SmtpCampaignsDriver::class);
        $driver->shouldReceive('supportsBounceFeedback')->once()->andReturn(false);
        $driver->shouldReceive('send')->once()->andThrow(new \RuntimeException('transport failed after DATA'));

        try {
            (new SendSmtpReservationJob($reservation->id))->handle(
                $reservations,
                $driver,
                app(CampaignService::class),
                app(SequenceService::class),
            );
            $this->fail('The transport failure must escape for queue observability.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('uncertainty persistence unavailable', $exception->getMessage());
        }

        $this->assertSame('sending', $reservation->fresh()->status);
        Carbon::setTestNow(now()->addMinutes(6));
        $this->artisan('smtp:dispatch-reservations')->assertSuccessful();
        $this->assertSame('uncertain', $reservation->fresh()->status);
    }

    public function test_cold_send_disabled_skips_prospect_before_tracking_or_transport(): void
    {
        config(['prospecting.cold_send_enabled' => false]);
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        $recipient = CampaignRecipient::firstOrFail();
        $recipient->contact->company->update(['relationship' => 'prospect']);
        $reservation = SmtpSendReservation::firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class), app(SmtpCampaignsDriver::class), app(CampaignService::class), app(SequenceService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame('skipped', $recipient->fresh()->status);
        $this->assertSame('cold_send_disabled', $recipient->fresh()->skip_reason);
        $this->assertNull($recipient->fresh()->sent_at);
        $this->assertSame(0, EmailTrackingEvent::count());
    }

    public function test_personal_prospect_is_skipped_when_cold_send_is_enabled(): void
    {
        config(['prospecting.cold_send_enabled' => true]);
        [$campaign, $run] = $this->campaignRun(1);
        app(CampaignService::class)->sendRun($run);
        $recipient = CampaignRecipient::firstOrFail();
        $recipient->contact->company->update(['relationship' => 'prospect']);
        $recipient->contact->update(['email_kind' => 'personal']);
        $reservation = SmtpSendReservation::firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class), app(SmtpCampaignsDriver::class), app(CampaignService::class), app(SequenceService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame('skipped', $recipient->fresh()->status);
        $this->assertSame('personal_email', $recipient->fresh()->skip_reason);
        $this->assertSame(0, EmailTrackingEvent::count());
    }

    public function test_accept_all_requires_feedback_capable_driver(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        $campaign->senderIdentity->update([
            'imap_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_username' => 'bounce@example.test',
            'imap_password' => 'test-password',
            'imap_encryption' => 'ssl',
        ]);
        SenderIdentity::query()->whereKey($campaign->sender_identity_id)->update([
            'last_polled_at' => now()->subMinutes(16),
            'last_poll_error' => null,
            'consecutive_poll_failures' => 0,
        ]);
        Setting::set('automatisation.cron_enabled', true);
        Setting::set('automatisation.inbox_poll', true);

        app(CampaignService::class)->sendRun($run);
        $recipient = CampaignRecipient::where('campaign_run_id', $run->id)->firstOrFail();
        $recipient->contact->update([
            'email_verification_status' => 'accept_all',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ]);
        $reservation = SmtpSendReservation::where('source_id', $recipient->id)->firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        Mail::assertNothingSent();
        $this->assertSame('skipped', $recipient->fresh()->status);
        $this->assertSame('bounce_feedback_unhealthy', $recipient->fresh()->skip_reason);
        $this->assertSame(0, EmailTrackingEvent::count());
    }

    public function test_accept_all_is_sent_when_smtp_feedback_is_healthy(): void
    {
        [$campaign, $run] = $this->campaignRun(1);
        $campaign->senderIdentity->update([
            'imap_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_username' => 'bounce@example.test',
            'imap_password' => 'test-password',
            'imap_encryption' => 'ssl',
        ]);
        SenderIdentity::query()->whereKey($campaign->sender_identity_id)->update([
            'last_polled_at' => now(),
            'last_poll_error' => null,
            'consecutive_poll_failures' => 0,
        ]);
        Setting::set('automatisation.cron_enabled', true);
        Setting::set('automatisation.inbox_poll', true);

        app(CampaignService::class)->sendRun($run);
        $recipient = CampaignRecipient::where('campaign_run_id', $run->id)->firstOrFail();
        $recipient->contact->update([
            'email_verification_status' => 'accept_all',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ]);
        $reservation = SmtpSendReservation::where('source_id', $recipient->id)->firstOrFail();
        $reservation->update(['reserved_for' => now()->subSecond()]);

        (new SendSmtpReservationJob($reservation->id))->handle(
            app(SmtpSendReservationService::class),
            app(SmtpCampaignsDriver::class),
            app(CampaignService::class),
            app(SequenceService::class),
        );

        $this->assertSame('sent', $recipient->fresh()->status);
        Mail::assertSent(CampaignMailable::class);
    }

    private function campaignRun(int $contacts, array $campaignAttributes = []): array
    {
        $segment = Segment::create(['name' => 'Clients ' . uniqid(), 'scope' => 'client']);
        for ($i = 0; $i < $contacts; $i++) {
            $company = Company::create([
                'name' => 'Company ' . uniqid(),
                'relationship' => 'client',
                'source' => 'manual',
                'qualification_status' => 'pending',
            ]);
            Contact::create([
                'company_id' => $company->id,
                'name' => 'Contact ' . $i,
                'email' => uniqid('contact') . '@example.test',
                'status' => 'new',
                'source' => 'manual',
                'legal_basis' => 'relationship',
                'email_kind' => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
            ]);
        }
        $template = CampaignTemplate::create(['name' => 'Template', 'subject' => 'Subject', 'html_content' => '<p>Hello</p>']);
        $sender = SenderIdentity::create([
            'name' => 'Sender',
            'email' => 'sender@example.test',
            'is_active' => true,
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ]);
        $campaign = Campaign::create(array_merge([
            'name' => 'SMTP campaign',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'delivery_channel' => 'smtp',
            'smtp_daily_email_limit' => 20,
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ], $campaignAttributes));
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'test-' . uniqid(),
            'run_at' => now(),
            'status' => 'scheduled',
        ]);

        return [$campaign, $run];
    }
}
