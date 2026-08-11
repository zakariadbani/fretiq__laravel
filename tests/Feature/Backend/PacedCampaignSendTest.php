<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignCompanyDispatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmailTrackingEvent;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\CampaignsClient;
use App\Services\Campaign\EmailTrackingService;
use App\Services\Campaign\LocalCampaignsDriver;
use App\Services\Campaign\PacedCampaignBatchService;
use App\Services\Campaign\PacedCampaignRetryableException;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PacedCampaignSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zoho.driver' => 'local',
        ]);
    }

    public function test_preview_reports_contact_and_unique_company_counts(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeCompanyWithContacts(2);
        $this->makeCompanyWithContacts(1);

        $preflight = app(CampaignService::class)->dispatchPreflight($campaign);

        $this->assertTrue($preflight['ok']);
        $this->assertSame(3, $preflight['count']);
        $this->assertSame(3, $preflight['contact_count']);
        $this->assertSame(2, $preflight['company_count']);
    }

    public function test_paced_run_sends_frozen_contacts_even_when_live_segment_has_drifted_empty(): void
    {
        $campaign = $this->makeCampaign();
        $company = $this->makeCompanyWithContacts(2);
        $company->update(['sector' => 'Transport']);
        $run = $this->prepareRun($campaign);
        $sentContactIds = [];
        $this->bindDriver(function (CampaignRecipient $recipient) use (&$sentContactIds): string {
            $sentContactIds[] = $recipient->contact_id;

            return 'provider-' . $recipient->id;
        });

        $campaign->segment->update(['filter' => ['sector' => ['Finance']]]);
        $this->assertTrue(app(CampaignService::class)->dispatchPreflight($campaign)['contacts']->isEmpty());

        app(CampaignService::class)->sendRun($run);

        $this->assertCount(2, $sentContactIds);
        $this->assertSame(2, CampaignRecipient::where('campaign_run_id', $run->id)->where('status', 'sent')->count());
        $this->assertSame('sent', $run->fresh()->status);
    }

    public function test_paced_send_rechecks_the_cold_send_gate_after_snapshot(): void
    {
        $campaign = $this->makeCampaign();
        $campaign->segment->update(['scope' => 'mixed']);
        $company = Company::factory()->create(['relationship' => 'prospect']);
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_checked_at' => now(),
            'email_verification_source' => 'manual',
        ]);
        $run = $this->prepareRun($campaign);
        $sendCalls = 0;
        $this->bindDriver(function () use (&$sendCalls): string {
            $sendCalls++;
            return 'provider-prospect';
        });

        $this->app->forgetInstance(CampaignService::class);
        app(CampaignService::class)->sendRun($run);

        $this->assertSame(0, $sendCalls);
        $this->assertDatabaseHas('campaign_recipients', [
            'contact_id' => $contact->id,
            'status' => 'skipped',
            'skip_reason' => 'cold_send_disabled',
        ]);
        $this->assertSame('processed', $run->companyDispatches()->firstOrFail()->fresh()->status);
    }

    public function test_paced_send_rechecks_relationship_and_cold_send_gate_after_snapshot(): void
    {
        $campaign = $this->makeCampaign();
        $company = $this->makeCompanyWithContacts(1);
        $contact = $company->contacts()->firstOrFail();
        $contact->update(['email_kind' => 'personal']);
        $run = $this->prepareRun($campaign);
        $company->update(['relationship' => 'prospect']);
        $sendCalls = 0;
        $this->bindDriver(function () use (&$sendCalls): string {
            $sendCalls++;
            return 'provider-personal-prospect';
        });

        $this->app->forgetInstance(CampaignService::class);
        app(CampaignService::class)->sendRun($run);

        $this->assertSame(0, $sendCalls);
        $this->assertDatabaseHas('campaign_recipients', [
            'contact_id' => $contact->id,
            'status' => 'skipped',
            'skip_reason' => 'cold_send_disabled',
        ]);
    }

    public function test_client_personal_email_stays_eligible(): void
    {
        $campaign = $this->makeCampaign();
        $company = $this->makeCompanyWithContacts(1);
        $contact = $company->contacts()->firstOrFail();
        $contact->update(['email_kind' => 'personal']);
        $run = $this->prepareRun($campaign);
        $sendCalls = 0;
        $this->bindDriver(function () use (&$sendCalls): string {
            $sendCalls++;
            return 'provider-client-personal';
        });

        app(CampaignService::class)->sendRun($run);

        $this->assertSame(1, $sendCalls);
        $this->assertDatabaseHas('campaign_recipients', [
            'contact_id' => $contact->id,
            'status' => 'sent',
            'skip_reason' => null,
        ]);
    }

    public function test_partial_failure_retries_only_queued_recipient_and_reuses_tracking_event(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeCompanyWithContacts(2);
        $run = $this->prepareRun($campaign);
        $attempts = [];
        $failContactId = $run->recipients()->orderByDesc('contact_id')->value('contact_id');
        $this->bindDriver(function (CampaignRecipient $recipient) use (&$attempts, $failContactId): string {
            $attempts[$recipient->contact_id] = ($attempts[$recipient->contact_id] ?? 0) + 1;
            if ($recipient->contact_id === $failContactId && $attempts[$recipient->contact_id] === 1) {
                throw new RuntimeException('temporary SMTP outage');
            }

            return 'provider-' . $recipient->id;
        });

        try {
            app(CampaignService::class)->sendRun($run);
            $this->fail('Paced run with queued recipients should stay retryable.');
        } catch (PacedCampaignRetryableException) {
            // Expected queue retry signal.
        }

        $failedRecipient = $run->recipients()->where('contact_id', $failContactId)->firstOrFail();
        $reservedEvent = EmailTrackingEvent::query()
            ->where('trackable_type', CampaignRecipient::class)
            ->where('trackable_id', $failedRecipient->id)
            ->firstOrFail();
        $reservedToken = $reservedEvent->token;
        $this->assertSame('pending', $reservedEvent->event);
        $this->assertSame(2, EmailTrackingEvent::where('trackable_type', CampaignRecipient::class)->count());

        app(CampaignService::class)->sendRun($run->fresh());

        $this->assertSame(1, min($attempts));
        $this->assertSame(2, max($attempts));
        $this->assertSame(2, EmailTrackingEvent::where('trackable_type', CampaignRecipient::class)->count());
        $this->assertSame($reservedToken, $reservedEvent->fresh()->token);
        $this->assertSame('sent', $reservedEvent->fresh()->event);
        $this->assertSame('sent', $run->fresh()->status);
        $this->assertSame('processed', $run->companyDispatches()->firstOrFail()->fresh()->status);
    }

    public function test_suppression_skip_completes_company_dispatch_without_sending(): void
    {
        $campaign = $this->makeCampaign();
        $company = $this->makeCompanyWithContacts(1);
        $run = $this->prepareRun($campaign);
        $contact = $company->contacts()->firstOrFail();
        Suppression::create(['email' => $contact->email, 'contact_id' => $contact->id]);
        $sendCalls = 0;
        $this->bindDriver(function () use (&$sendCalls): string {
            $sendCalls++;
            return 'unexpected';
        });

        app(CampaignService::class)->sendRun($run);

        $this->assertSame(0, $sendCalls);
        $this->assertSame('skipped', $run->recipients()->firstOrFail()->status);
        $this->assertSame('processed', $run->companyDispatches()->firstOrFail()->fresh()->status);
        $this->assertSame('sent', $run->fresh()->status);
    }

    public function test_final_job_failure_marks_unresolved_dispatch_failed_and_zero_send_run_failed(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeCompanyWithContacts(1);
        $run = $this->prepareRun($campaign);
        Log::spy();
        $this->bindDriver(fn () => throw new RuntimeException('secret provider detail'));

        $failure = null;
        try {
            app(CampaignService::class)->sendRun($run);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(PacedCampaignRetryableException::class, $failure);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($run): bool {
                return $message === '[CampaignService] Failed to send to recipient.'
                    && $context['run_id'] === $run->id
                    && isset($context['recipient_id'], $context['contact_id'])
                    && ($context['exception_class'] ?? null) === RuntimeException::class
                    && ! array_key_exists('error', $context)
                    && ! str_contains(json_encode($context), 'secret provider detail');
            });

        (new SendCampaignJob($run->id))->failed($failure);

        $dispatch = CampaignCompanyDispatch::where('current_run_id', $run->id)->firstOrFail();
        $this->assertSame('failed', $dispatch->status);
        $this->assertNull($dispatch->processed_at);
        $this->assertNotNull($dispatch->last_error);
        $this->assertStringNotContainsString('secret provider detail', $dispatch->last_error);
        $pendingEvent = EmailTrackingEvent::where('trackable_type', CampaignRecipient::class)->firstOrFail();
        $this->assertSame('pending', $pendingEvent->event);
        app(EmailTrackingService::class)->recordOpen($pendingEvent->token, '127.0.0.1', 'Mozilla/5.0');
        $this->assertSame(1, $pendingEvent->fresh()->human_open_count);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
    }

    public function test_final_job_failure_preserves_partial_send_analytics_and_does_not_resend_sent_contact(): void
    {
        $campaign = $this->makeCampaign();
        $successfulCompany = $this->makeCompanyWithContacts(1);
        $failedCompany = $this->makeCompanyWithContacts(1);
        $run = $this->prepareRun($campaign);
        $firstContactId = $successfulCompany->contacts()->value('id');
        $attempts = [];
        $this->bindDriver(function (CampaignRecipient $recipient) use ($firstContactId, &$attempts): string {
            $attempts[$recipient->contact_id] = ($attempts[$recipient->contact_id] ?? 0) + 1;
            if ($recipient->contact_id !== $firstContactId) {
                throw new RuntimeException('still failing');
            }

            return 'provider-' . $recipient->id;
        });

        $failure = null;
        try {
            app(CampaignService::class)->sendRun($run);
        } catch (PacedCampaignRetryableException $exception) {
            $failure = $exception;
        }
        $this->assertNotNull($failure);

        CampaignRecipient::query()
            ->where('campaign_run_id', $run->id)
            ->where('contact_id', $firstContactId)
            ->update(['status' => 'delivered']);
        (new SendCampaignJob($run->id))->failed($failure);

        $this->assertSame('sent', $run->fresh()->status);
        $this->assertSame(1, (int) $run->fresh()->stats_sent);
        $this->assertDatabaseHas('campaign_company_dispatches', [
            'campaign_id' => $campaign->id,
            'company_id' => $successfulCompany->id,
            'status' => 'processed',
        ]);
        $this->assertDatabaseHas('campaign_company_dispatches', [
            'campaign_id' => $campaign->id,
            'company_id' => $failedCompany->id,
            'status' => 'failed',
        ]);

        app(CampaignService::class)->sendRun($run->fresh());
        $this->assertSame(1, $attempts[$firstContactId]);
    }

    public function test_paced_zoho_preflight_is_blocked_for_global_or_campaign_driver(): void
    {
        $campaign = $this->makeCampaign();
        $this->makeCompanyWithContacts(1);

        config(['services.zoho.driver' => 'zoho']);
        $global = app(CampaignService::class)->dispatchPreflight($campaign);
        $this->assertFalse($global['ok']);
        $this->assertStringContainsString('progressif', implode(' ', $global['messages']));

        config(['services.zoho.driver' => 'local']);
        $campaign->update(['driver' => 'zoho']);
        $perCampaign = app(CampaignService::class)->dispatchPreflight($campaign->fresh());
        $this->assertFalse($perCampaign['ok']);
        $this->assertStringContainsString('progressif', implode(' ', $perCampaign['messages']));
    }

    public function test_local_driver_reuses_message_and_provider_identity_for_same_recipient(): void
    {
        Mail::fake();
        $campaign = $this->makeCampaign();
        $this->makeCompanyWithContacts(1);
        $run = $this->prepareRun($campaign);
        $recipient = $run->recipients()->with('contact.company')->firstOrFail();
        $driver = app(LocalCampaignsDriver::class);

        $firstProviderId = $driver->send($recipient, $campaign, $run, str_repeat('a', 64), 'https://example.test/unsubscribe');
        $secondProviderId = $driver->send($recipient, $campaign, $run, str_repeat('a', 64), 'https://example.test/unsubscribe');
        $messageIds = Mail::sent(\App\Mail\CampaignMailable::class)
            ->map(fn ($mail) => $mail->headers()->messageId)
            ->all();

        $this->assertSame($firstProviderId, $secondProviderId);
        $this->assertCount(2, $messageIds);
        $this->assertNotNull($messageIds[0]);
        $this->assertSame($messageIds[0], $messageIds[1]);
        $this->assertStringContainsString((string) $recipient->id, $messageIds[0]);
    }

    private function makeCampaign(): Campaign
    {
        $segment = Segment::create(['name' => 'Paced segment ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => 'Paced template ' . uniqid(),
            'subject' => 'Bonjour',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name' => 'TCL',
            'email' => uniqid() . '@tcl.test',
        ]);

        return Campaign::create([
            'name' => 'Paced send ' . uniqid(),
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'paced',
            'daily_company_limit' => 20,
            'timezone' => 'Europe/Paris',
            'is_active' => true,
            'driver' => 'local',
        ]);
    }

    private function makeCompanyWithContacts(int $count): Company
    {
        $company = Company::factory()->client()->create();
        Contact::factory()->count($count)->create([
            'company_id' => $company->id,
            'email_verification_status' => 'valid',
            'email_verification_checked_at' => now(),
            'email_verification_source' => 'manual',
        ]);

        return $company;
    }

    private function prepareRun(Campaign $campaign): CampaignRun
    {
        return app(PacedCampaignBatchService::class)->prepareForDate(
            $campaign,
            Carbon::parse('2026-07-20 10:00:00', 'Europe/Paris'),
        );
    }

    private function bindDriver(callable $send): void
    {
        $this->app->instance(CampaignsClient::class, new class($send) implements CampaignsClient {
            public function __construct(private $send) {}

            public function send(
                CampaignRecipient $recipient,
                Campaign $campaign,
                \App\Models\CampaignRun $run,
                string $trackingToken,
                string $unsubscribeUrl,
            ): string {
                return ($this->send)($recipient, $campaign, $run, $trackingToken, $unsubscribeUrl);
            }

            public function driverName(): string
            {
                return 'local';
            }

            public function supportsBounceFeedback(Campaign $campaign): bool
            {
                return false;
            }
        });
    }
}
