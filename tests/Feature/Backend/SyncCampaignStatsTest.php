<?php

namespace Tests\Feature\Backend;

use App\Jobs\SyncCampaignStatsJob;
use App\Jobs\SyncMailjetEventsJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * SyncCampaignStatsTest — tests for SyncCampaignStatsJob and campaign:sync-stats command.
 *
 * Two scenarios:
 *   1. Local driver run — stats recomputed from campaign_recipients rows.
 *   2. Zoho-backed run  — stats fetched from faked Zoho Campaigns API.
 *
 * Http::fake is used for the Zoho scenario; no live API calls are made.
 */
class SyncCampaignStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeContact(string $email = 'sync@acme.test'): Contact
    {
        $co = Company::create([
            'name'                 => 'Acme Sync',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Jean Sync',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeSentRun(array $runAttributes = []): CampaignRun
    {
        $segment  = Segment::create(['name' => 'Sync Segment', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Sync Template',
            'subject'      => 'Sujet sync',
            'html_content' => '<p>Sync test</p>',
        ]);
        $sender   = SenderIdentity::create([
            'name'  => 'TCL Sync',
            'email' => 'sync@tcl.test',
        ]);
        $campaign = Campaign::create([
            'name'               => 'Campaign Sync',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now()->subHour(),
            'timezone'           => 'Europe/Paris',
        ]);

        return CampaignRun::create(array_merge([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'oneshot-sync-' . now()->format('YmdHis') . rand(1, 9999),
            'run_at'         => now()->subHour(),
            'status'         => 'sent',
            'stats_sent'     => 0,
            'stats_delivered'=> 0,
            'stats_opened'   => 0,
            'stats_clicked'  => 0,
            'stats_bounced'  => 0,
            'stats_unsubscribed' => 0,
            'stats_replied'  => 0,
        ], $runAttributes));
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Local run: SyncCampaignStatsJob recomputes stats_opened from recipients
     * that have opened_at set (and stats_sent from status='sent' count).
     */
    public function test_local_run_stats_recomputed_from_recipients(): void
    {
        $contact1 = $this->makeContact('open1@acme.test');
        $contact2 = $this->makeContact('open2@acme.test');
        $contact3 = $this->makeContact('noopen@acme.test');

        $run = $this->makeSentRun(); // no zoho_campaign_key → local path

        // 3 sent recipients, 2 of which have opened_at
        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact1->id,
            'status'          => 'sent',
            'sent_at'         => now()->subMinutes(30),
            'opened_at'       => now()->subMinutes(20),
        ]);
        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact2->id,
            'status'          => 'sent',
            'sent_at'         => now()->subMinutes(30),
            'opened_at'       => now()->subMinutes(15),
        ]);
        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact3->id,
            'status'          => 'sent',
            'sent_at'         => now()->subMinutes(30),
            'opened_at'       => null,
        ]);

        // Dispatch the sync job synchronously (QUEUE_CONNECTION=sync in phpunit.xml)
        SyncCampaignStatsJob::dispatch($run->id);

        $run->refresh();

        $this->assertSame(3, (int) $run->stats_sent,   'stats_sent must equal 3 (3 sent recipients)');
        $this->assertSame(2, (int) $run->stats_opened, 'stats_opened must equal 2 (2 with opened_at)');
    }

    /** A completed local run writes every cached recipient KPI. */
    public function test_completed_local_run_recomputes_all_counters(): void
    {
        $run = $this->makeSentRun(['finished_at' => now()->subMinute()]);
        $sentAt = now()->subMinutes(30);

        foreach ([
            ['all-events@acme.test', 'replied', ['sent_at' => $sentAt, 'opened_at' => now()->subMinutes(20), 'clicked_at' => now()->subMinutes(15), 'replied_at' => now()->subMinutes(10)]],
            ['bounce@acme.test', 'bounced', ['sent_at' => $sentAt, 'bounced_at' => now()->subMinutes(8)]],
            ['unsubscribe@acme.test', 'unsubscribed', ['sent_at' => $sentAt]],
            ['delivered@acme.test', 'delivered', ['sent_at' => $sentAt]],
        ] as [$email, $status, $timestamps]) {
            CampaignRecipient::create(array_merge([
                'campaign_run_id' => $run->id,
                'contact_id' => $this->makeContact($email)->id,
                'status' => $status,
            ], $timestamps));
        }

        SyncCampaignStatsJob::dispatch($run->id);

        $run->refresh();

        $this->assertSame(4, (int) $run->stats_sent);
        $this->assertSame(2, (int) $run->stats_delivered);
        $this->assertSame(1, (int) $run->stats_opened);
        $this->assertSame(1, (int) $run->stats_clicked);
        $this->assertSame(1, (int) $run->stats_replied);
        $this->assertSame(1, (int) $run->stats_bounced);
        $this->assertSame(1, (int) $run->stats_unsubscribed);
    }

    public function test_sync_stats_command_dispatches_and_recomputes_local(): void
    {
        $contact = $this->makeContact('cmd@acme.test');
        $run     = $this->makeSentRun();

        CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id'      => $contact->id,
            'status'          => 'sent',
            'sent_at'         => now()->subMinutes(10),
            'opened_at'       => now()->subMinutes(5),
        ]);

        // Run the Artisan command (queue is sync, so job runs immediately)
        $this->artisan('campaign:sync-stats')->assertExitCode(0);

        $run->refresh();

        $this->assertSame(1, (int) $run->stats_sent,   'Command must update stats_sent');
        $this->assertSame(1, (int) $run->stats_opened, 'Command must update stats_opened from opened_at');
    }

    /**
     * Zoho run: SyncCampaignStatsJob fetches the report from the faked Zoho API
     * and updates the run's stats columns.
     */
    public function test_zoho_run_stats_synced_from_api(): void
    {
        // Fake Zoho credentials so the auth service attempts refresh (intercepted)
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id'     => 'x',
            'services.zoho.campaigns.client_secret'  => 'y',
        ]);

        Http::fake([
            '*oauth/v2/token*' => Http::response([
                'access_token' => 'fake-at',
                'expires_in'   => 3600,
            ], 200),

            '*campaignreports*' => Http::response([
                'status' => 'success',
                'code' => '0',
                'campaign-reports' => [[
                    'emails_sent_count' => '50',
                    'delivered_count' => '48',
                    'opens_count' => '20',
                    'unique_clicks_count' => '8',
                    'bounces_count' => '2',
                    'unsub_count' => '0',
                ]],
            ], 200),
        ]);

        // Create a Zoho-backed run (has zoho_campaign_key set)
        $run = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-SYNC-001',
            'driver_ref'        => 'zoho',
        ]);

        // Dispatch the sync job — should call getCampaignReport via Http::fake
        SyncCampaignStatsJob::dispatch($run->id);

        $run->refresh();

        $this->assertSame(50, (int) $run->stats_sent,    'stats_sent from Zoho report');
        $this->assertSame(20, (int) $run->stats_opened,  'stats_opened from Zoho report');
        $this->assertSame(8,  (int) $run->stats_clicked, 'stats_clicked from Zoho report');
        $this->assertSame(2,  (int) $run->stats_bounced, 'stats_bounced from Zoho report');

        // Verify the request was sent to the Zoho endpoint
        Http::assertSent(fn ($req) => str_contains($req->url(), 'campaignreports'));
    }

    public function test_zoho_run_uses_live_verified_aggregate_aliases(): void
    {
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);

        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*campaignreports*' => Http::response([
                'status' => 'success',
                'code' => '0',
                'campaign-reports' => [[
                    'emails_sent_count' => '80',
                    'delivered_count' => '74',
                    'opens_count' => '32',
                    'unique_clicks_count' => '9',
                    'bounces_count' => '6',
                    'unsub_count' => '2',
                ]],
            ], 200),
        ]);

        $run = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-VERIFIED',
            'driver_ref' => 'zoho',
        ]);

        SyncCampaignStatsJob::dispatch($run->id);

        $run->refresh();
        $this->assertSame(80, (int) $run->stats_sent);
        $this->assertSame(74, (int) $run->stats_delivered);
        $this->assertSame(32, (int) $run->stats_opened);
        $this->assertSame(9, (int) $run->stats_clicked);
        $this->assertSame(6, (int) $run->stats_bounced);
        $this->assertSame(2, (int) $run->stats_unsubscribed);
    }

    public function test_zoho_sync_fails_closed_when_a_verified_aggregate_field_is_missing(): void
    {
        Log::spy();
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*campaignreports*' => Http::response([
                'code' => '0',
                'status' => 'success',
                'campaign-reports' => [['emails_sent_count' => '1']],
            ], 200),
        ]);
        $previousSync = now()->subDay()->startOfSecond();
        $run = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-MISSING-FIELD',
            'stats_opened' => 9,
            'stats_synced_at' => $previousSync,
        ]);

        SyncCampaignStatsJob::dispatch($run->id);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context) => str_contains($message, 'champs verifies manquants')
                && in_array('opens_count', $context['missing_fields'] ?? [], true),
        );
        $run->refresh();
        $this->assertSame(9, $run->stats_opened);
        $this->assertTrue($run->stats_synced_at->equalTo($previousSync));
        $this->assertStringContainsString('missing or invalid', $run->stats_sync_error);
    }

    public function test_successful_zoho_sync_records_its_freshness(): void
    {
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*campaignreports*' => Http::response([
                'code' => '0',
                'status' => 'success',
                'campaign-reports' => [[
                    'emails_sent_count' => '1',
                    'delivered_count' => '1',
                    'opens_count' => '0',
                    'unique_clicks_count' => '0',
                    'bounces_count' => '0',
                    'unsub_count' => '0',
                ]],
            ], 200),
        ]);
        $this->travelTo(now()->startOfSecond());

        $run = $this->makeSentRun(['zoho_campaign_key' => 'CK-FRESH']);
        SyncCampaignStatsJob::dispatch($run->id);

        $this->assertNotNull($run->refresh()->stats_synced_at);
        $this->assertTrue($run->stats_synced_at->equalTo(now()));
    }

    public function test_failed_zoho_sync_records_error_without_clobbering_send_failure(): void
    {
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*campaignreports*' => Http::response(['message' => 'temporary failure'], 500),
        ]);
        $run = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-ERROR',
            'failure_reason' => 'Original send failure',
        ]);

        SyncCampaignStatsJob::dispatch($run->id);

        $run->refresh();
        $this->assertSame('Original send failure', $run->failure_reason);
        $this->assertStringContainsString('getCampaignReport', $run->stats_sync_error);
        $this->assertNull($run->stats_synced_at);
    }

    public function test_successful_zoho_sync_clears_the_previous_sync_error(): void
    {
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        Http::fake([
            '*oauth/v2/token*' => Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200),
            '*campaignreports*' => Http::response([
                'code' => '0',
                'status' => 'success',
                'campaign-reports' => [[
                    'emails_sent_count' => '1',
                    'delivered_count' => '1',
                    'opens_count' => '0',
                    'unique_clicks_count' => '0',
                    'bounces_count' => '0',
                    'unsub_count' => '0',
                ]],
            ], 200),
        ]);
        $run = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-RECOVERED',
            'stats_sync_error' => 'old error',
        ]);

        SyncCampaignStatsJob::dispatch($run->id);

        $this->assertNull($run->refresh()->stats_sync_error);
    }

    public function test_recipient_event_sync_applies_the_verified_zoho_outcomes_by_run_email(): void
    {
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        $run = $this->makeSentRun(['zoho_campaign_key' => 'CK-EVENTS']);
        $recipients = collect([
            'sentcontacts' => 'sent@acme.test',
            'openedcontacts' => 'opened@acme.test',
            'clickedcontacts' => 'clicked@acme.test',
            'optoutcontacts' => 'optout@acme.test',
            'unsentcontacts' => 'unsent@acme.test',
            'spamcontacts' => 'spam@acme.test',
        ])->mapWithKeys(function (string $email, string $action) use ($run) {
            $recipient = CampaignRecipient::create([
                'campaign_run_id' => $run->id,
                'contact_id' => $this->makeContact($email)->id,
                'status' => in_array($action, ['sentcontacts', 'unsentcontacts'], true) ? 'queued' : 'sent',
                'sent_at' => in_array($action, ['sentcontacts', 'unsentcontacts'], true) ? null : now()->subHour(),
            ]);

            return [$action => $recipient];
        });

        Http::fake(function ($request) use ($recipients) {
            if (str_contains($request->url(), 'oauth/v2/token')) {
                return Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $action = $query['action'] ?? '';
            $recipient = $recipients->get($action);
            if ($recipient === null) {
                return Http::response(['status' => 'error', 'code' => '6303'], 200);
            }

            $row = [
                'contactemailaddress' => strtoupper($recipient->contact->email),
                'sent_time' => (string) (now()->subMinutes(30)->getTimestampMs()),
            ];
            if ($action === 'openedcontacts') {
                $row['openreports'] = ['message-1' => now()->subMinutes(20)->toIso8601String()];
            }

            $rows = [$row];
            if ($action === 'sentcontacts') {
                $rows[] = [
                    'contactemailaddress' => strtoupper($recipients['unsentcontacts']->contact->email),
                    'sent_time' => $row['sent_time'],
                ];
            }

            return Http::response(['status' => 'success', 'code' => '0', 'list_of_details' => $rows], 200);
        });

        \App\Jobs\SyncCampaignRecipientEventsJob::dispatchSync($run->id);

        $this->assertSame('sent', $recipients['sentcontacts']->refresh()->status);
        $this->assertNotNull($recipients['sentcontacts']->sent_at);
        $this->assertSame('opened', $recipients['openedcontacts']->refresh()->status);
        $this->assertNotNull($recipients['openedcontacts']->opened_at);
        $this->assertSame('clicked', $recipients['clickedcontacts']->refresh()->status);
        $this->assertNull($recipients['clickedcontacts']->clicked_at, 'Zoho sent_time is not click-time evidence.');
        $this->assertSame('unsubscribed', $recipients['optoutcontacts']->refresh()->status);
        $this->assertSame('skipped', $recipients['unsentcontacts']->refresh()->status);
        $this->assertSame('zoho_unsent', $recipients['unsentcontacts']->skip_reason);
        $this->assertDatabaseHas('suppressions', ['email' => 'optout@acme.test', 'reason' => 'unsubscribe']);
        $this->assertDatabaseHas('suppressions', ['email' => 'spam@acme.test', 'reason' => 'complaint']);
    }

    public function test_recipient_event_sync_rejects_rows_without_a_verified_email_key_and_records_the_error(): void
    {
        $run = $this->makeSentRun(['zoho_campaign_key' => 'CK-MALFORMED-RECIPIENT']);
        $client = $this->mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $client->shouldReceive('getCampaignRecipientsData')
            ->once()
            ->with('CK-MALFORMED-RECIPIENT', 'sentcontacts', 1, 100)
            ->andReturn([['contactstatus' => 'sent']]);
        $job = new \App\Jobs\SyncCampaignRecipientEventsJob($run->id);

        try {
            $job->handle($client, app(\App\Services\Campaign\CampaignFeedbackService::class));
            $this->fail('Expected malformed Zoho recipient row rejection.');
        } catch (\UnexpectedValueException $exception) {
            $job->failed($exception);
        }

        $this->assertSame(
            'Recipient event sync failed. Inspect protected logs.',
            $run->refresh()->stats_sync_error,
        );
    }

    public function test_recipient_event_sync_error_does_not_persist_provider_response_pii(): void
    {
        $run = $this->makeSentRun(['zoho_campaign_key' => 'CK-PRIVATE-ERROR']);
        $job = new \App\Jobs\SyncCampaignRecipientEventsJob($run->id);
        $sentinel = 'private-recipient@example.test';

        $job->failed(new \RuntimeException("Zoho response body: {$sentinel}"));

        $error = (string) $run->refresh()->stats_sync_error;
        $this->assertSame('Recipient event sync failed. Inspect protected logs.', $error);
        $this->assertStringNotContainsString($sentinel, $error);
    }

    public function test_recipient_event_sync_parses_the_first_open_from_zoho_map_text(): void
    {
        config([
            'services.zoho.campaigns.refresh_token' => 'fake-rt',
            'services.zoho.campaigns.client_id' => 'x',
            'services.zoho.campaigns.client_secret' => 'y',
        ]);
        $run = $this->makeSentRun(['zoho_campaign_key' => 'CK-OPEN-MAP']);
        $contact = $this->makeContact('open-map@acme.test');
        $recipient = CampaignRecipient::create([
            'campaign_run_id' => $run->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'sent_at' => '2026-08-04 08:00:00',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'oauth/v2/token')) {
                return Http::response(['access_token' => 'fake-at', 'expires_in' => 3600], 200);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['action'] ?? '') !== 'openedcontacts') {
                return Http::response(['status' => 'error', 'code' => '6303'], 200);
            }

            return Http::response([
                'status' => 'success',
                'code' => '0',
                'list_of_details' => [[
                    'contactemailaddress' => 'open-map@acme.test',
                    'sent_time' => '1775293200000',
                    'openreports' => '{m1=2026-08-04T10:30:00+00:00, m2=2026-08-04T11:00:00+00:00}',
                ]],
            ], 200);
        });

        \App\Jobs\SyncCampaignRecipientEventsJob::dispatchSync($run->id);

        $this->assertSame(
            '2026-08-04 10:30:00',
            $recipient->refresh()->opened_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_sync_command_dispatches_both_zoho_jobs_only_for_the_last_thirty_days(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $recent = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-RECENT',
            'run_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        $old = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-OLD',
            'run_at' => now()->subDays(31),
            'finished_at' => now()->subDays(31),
        ]);

        $this->artisan('campaign:sync-stats --zoho-only')->assertExitCode(0);

        \Illuminate\Support\Facades\Queue::assertPushed(SyncCampaignStatsJob::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\SyncCampaignRecipientEventsJob::class,
            fn ($job) => $job->runId === $recent->id,
        );
        \Illuminate\Support\Facades\Queue::assertNotPushed(
            \App\Jobs\SyncCampaignRecipientEventsJob::class,
            fn ($job) => $job->runId === $old->id,
        );
    }

    /**
     * The command routes a mailjet-channel run's feedback sync to
     * SyncMailjetEventsJob, never SyncCampaignRecipientEventsJob — this is
     * the only wiring that ever runs the mailjet sync job. A zoho run must
     * still route the old way when both are present in the same sweep.
     */
    public function test_sync_command_dispatches_mailjet_events_job_only_for_a_mailjet_run(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $mailjetRun = $this->makeSentRun([
            'run_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);
        $mailjetRun->campaign->update(['delivery_channel' => 'mailjet']);

        $zohoRun = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-COMMAND-ZOHO',
            'run_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        $this->artisan('campaign:sync-stats')->assertExitCode(0);

        \Illuminate\Support\Facades\Bus::assertDispatched(
            SyncMailjetEventsJob::class,
            fn ($job) => $job->runId === $mailjetRun->id,
        );
        \Illuminate\Support\Facades\Bus::assertNotDispatched(
            \App\Jobs\SyncCampaignRecipientEventsJob::class,
            fn ($job) => $job->runId === $mailjetRun->id,
        );
        \Illuminate\Support\Facades\Bus::assertDispatched(
            \App\Jobs\SyncCampaignRecipientEventsJob::class,
            fn ($job) => $job->runId === $zohoRun->id,
        );
        \Illuminate\Support\Facades\Bus::assertNotDispatched(
            SyncMailjetEventsJob::class,
            fn ($job) => $job->runId === $zohoRun->id,
        );
    }

    public function test_stats_sync_scope_uses_finished_time_and_falls_back_to_run_time_when_unfinished(): void
    {
        $cutoff = now()->subDays(30);
        $unfinishedRecent = $this->makeSentRun([
            'run_at' => now()->subDays(29),
            'finished_at' => null,
        ]);
        $finishedRecent = $this->makeSentRun([
            'run_at' => now()->subDays(45),
            'finished_at' => now()->subDay(),
        ]);
        $finishedOld = $this->makeSentRun([
            'run_at' => now()->subDay(),
            'finished_at' => now()->subDays(31),
        ]);

        $ids = CampaignRun::query()
            ->eligibleForStatsSync($cutoff)
            ->pluck('id');

        $this->assertTrue($ids->contains($unfinishedRecent->id));
        $this->assertTrue($ids->contains($finishedRecent->id));
        $this->assertFalse($ids->contains($finishedOld->id));
    }

    public function test_zoho_sync_preserves_stored_count_when_provider_count_is_negative(): void
    {
        $client = $this->mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $client->shouldReceive('getCampaignReport')
            ->once()
            ->andReturn([
                'status' => 'success',
                'code' => '0',
                'campaign-reports' => [[
                    'emails_sent_count' => -1,
                    'delivered_count' => 6,
                    'opens_count' => 5,
                    'unique_clicks_count' => 4,
                    'bounces_count' => 1,
                    'unsub_count' => 0,
                ]],
            ]);
        $run = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-NEGATIVE',
            'stats_sent' => 7,
        ]);

        SyncCampaignStatsJob::dispatchSync($run->id);

        $this->assertSame(7, $run->refresh()->stats_sent);
    }

    public function test_stats_sync_job_is_unique_and_overlap_protected_per_run(): void
    {
        $job = new SyncCampaignStatsJob(42);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
        $this->assertSame('42', $job->uniqueId());
        $this->assertContainsOnlyInstancesOf(
            \Illuminate\Queue\Middleware\WithoutOverlapping::class,
            $job->middleware(),
        );
    }

    public function test_stats_job_rejects_a_malformed_report_even_when_the_client_returns_it(): void
    {
        $client = $this->mock(\App\Services\Zoho\ZohoCampaignsClient::class);
        $client->shouldReceive('getCampaignReport')
            ->once()
            ->andReturn([
                'status' => 'success',
                'code' => '0',
                'campaign-reports' => [],
            ]);

        $run = $this->makeSentRun([
            'zoho_campaign_key' => 'CK-MALFORMED-JOB',
            'stats_sync_error' => 'previous error',
        ]);

        SyncCampaignStatsJob::dispatchSync($run->id);

        $run->refresh();
        $this->assertNull($run->stats_synced_at);
        $this->assertNotNull($run->stats_sync_error);
        $this->assertStringContainsString('campaign-reports', $run->stats_sync_error);
    }
}
