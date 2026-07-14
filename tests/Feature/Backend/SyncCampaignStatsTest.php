<?php

namespace Tests\Feature\Backend;

use App\Jobs\SyncCampaignStatsJob;
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
                'sent_count'    => 50,
                'opened_count'  => 20,
                'clicked_count' => 8,
                'bounced_count' => 2,
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
}
