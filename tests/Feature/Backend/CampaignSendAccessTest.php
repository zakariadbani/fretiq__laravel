<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ACL / access-control tests for campaign scheduling and sending.
 *
 * Rules under test:
 *   - `send campaigns` is NOT granted to `commercial`.
 *   - `send campaigns` IS granted to `superadmin`.
 *   - `create campaigns` IS granted to `commercial`.
 */
class CampaignSendAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeCampaign(): Campaign
    {
        $segment = Segment::create(['name' => 'Clients', 'scope' => 'client']);

        $template = CampaignTemplate::create([
            'name'         => 'Template ACL Test',
            'subject'      => 'Sujet',
            'html_content' => '<p>Hello</p>',
        ]);

        $sender = SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);

        return Campaign::create([
            'name'               => 'Campagne ACL',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now()->addHour(),
            'timezone'           => 'Europe/Paris',
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * A commercial user MUST NOT be able to call /admin/campaigns/{id}/send.
     * The `permission:send campaigns` middleware should reject with 403.
     */
    public function test_commercial_cannot_send(): void
    {
        Mail::fake();

        $campaign = $this->makeCampaign();

        $sendResponse = $this->actingAs($this->commercial)
            ->post("/admin/campaigns/{$campaign->id}/send");

        $sendResponse->assertStatus(403);
    }

    /**
     * A commercial user MUST NOT be able to call /admin/campaigns/{id}/schedule.
     */
    public function test_commercial_cannot_schedule(): void
    {
        $campaign = $this->makeCampaign();

        $scheduleResponse = $this->actingAs($this->commercial)
            ->post("/admin/campaigns/{$campaign->id}/schedule");

        $scheduleResponse->assertStatus(403);
    }

    /**
     * A superadmin CAN call /admin/campaigns/{id}/schedule.
     * The response must be 200 (JSON) or a 302 redirect, and a CampaignRun
     * with status='scheduled' must exist in the database.
     */
    public function test_admin_can_schedule(): void
    {
        $campaign = $this->makeCampaign();
        $company = Company::create([
            'name' => 'Client ACL',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        Contact::create([
            'company_id' => $company->id,
            'email' => 'acl-client@example.test',
            'name' => 'Client ACL',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/campaigns/{$campaign->id}/schedule");

        $this->assertContains(
            $response->status(),
            [200, 302],
            "Schedule endpoint should return 200 or 302, got {$response->status()}",
        );

        $this->assertDatabaseHas('campaign_runs', [
            'campaign_id' => $campaign->id,
            'status'      => 'scheduled',
        ]);
    }

    /**
     * A commercial user HAS `create campaigns` permission, so GET /admin/campaigns/create
     * must return 200.
     */
    public function test_commercial_can_create_campaign(): void
    {
        $response = $this->actingAs($this->commercial)
            ->get('/admin/campaigns/create');

        $response->assertStatus(200);
    }

    /**
     * A commercial user HAS `view campaigns` permission, so GET /admin/campaigns
     * (index) must return 200.
     */
    public function test_commercial_can_view_campaigns_index(): void
    {
        $response = $this->actingAs($this->commercial)
            ->get('/admin/campaigns');

        $response->assertStatus(200);
    }

    public function test_commercial_cannot_manually_sync_campaign_stats(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->commercial)
            ->post("/admin/campaigns/{$campaign->id}/sync-stats")
            ->assertForbidden();
    }

    public function test_admin_manual_stats_sync_queues_both_jobs_for_recent_zoho_runs(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $campaign = $this->makeCampaign();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'manual-sync',
            'run_at' => now()->subHour(),
            'status' => 'sent',
            'zoho_campaign_key' => 'CK-MANUAL',
            'finished_at' => now()->subHour(),
        ]);

        $this->actingAs($this->superadmin)
            ->post("/admin/campaigns/{$campaign->id}/sync-stats")
            ->assertRedirect(route('admin.campaigns.view', $campaign->id));

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\SyncCampaignStatsJob::class,
            fn ($job) => $job->runId === $run->id,
        );
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\SyncCampaignRecipientEventsJob::class,
            fn ($job) => $job->runId === $run->id,
        );
    }

    public function test_admin_sees_one_stats_sync_button_for_an_eligible_zoho_run_when_latest_run_is_local(): void
    {
        $campaign = $this->makeCampaign();
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'eligible-zoho',
            'run_at' => now()->subHours(2),
            'status' => 'sent',
            'zoho_campaign_key' => 'CK-ELIGIBLE',
            'finished_at' => now()->subHours(2),
            'stats_synced_at' => now()->subMinutes(10),
        ]);
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'newer-local',
            'run_at' => now()->subHour(),
            'status' => 'sent',
            'finished_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get(route('admin.campaigns.view', $campaign->id));

        $response->assertOk();
        $response->assertSee('data-campaign-stats-sync', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-campaign-stats-sync'));
        $response->assertSeeHtml('Derni&egrave;re synchronisation Zoho');
    }

    public function test_admin_sees_a_disabled_stats_sync_button_on_view_and_not_on_edit_without_an_eligible_zoho_run(): void
    {
        $campaign = $this->makeCampaign();
        $response = $this->actingAs($this->superadmin)->get(route('admin.campaigns.view', $campaign->id));

        $response->assertOk();
        $this->assertSame(1, preg_match(
            '/<button(?=[^>]*id="btn-sync-campaign-stats")[^>]*>/s',
            $response->getContent(),
            $matches,
        ));
        $this->assertStringContainsString('type="button"', $matches[0]);
        $this->assertStringContainsString('disabled', $matches[0]);
        $this->assertStringContainsString('aria-disabled="true"', $matches[0]);
        $response->assertSee('data-bs-toggle="tooltip"', false);
        $response->assertSee('30 derniers jours', false);

        $this->actingAs($this->superadmin)
            ->get(route('admin.campaigns.edit', $campaign->id))
            ->assertOk()
            ->assertDontSee('id="btn-sync-campaign-stats"', false);
    }

    public function test_admin_sees_an_enabled_stats_sync_button_on_view_and_not_on_edit_with_an_eligible_zoho_run(): void
    {
        $campaign = $this->makeCampaign();
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'eligible-view-button',
            'run_at' => now()->subHour(),
            'status' => 'sent',
            'zoho_campaign_key' => 'CK-VIEW-BUTTON',
            'finished_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->superadmin)->get(route('admin.campaigns.view', $campaign->id));

        $response->assertOk();
        $this->assertSame(1, preg_match(
            '/<button(?=[^>]*id="btn-sync-campaign-stats")[^>]*>/s',
            $response->getContent(),
            $matches,
        ));
        $this->assertStringContainsString('type="button"', $matches[0]);
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?:\s|>)/', $matches[0]);
        $this->assertStringContainsString('aria-disabled="false"', $matches[0]);
        $this->assertStringContainsString(
            'data-url="'.route('admin.campaigns.syncStats', $campaign->id).'"',
            $matches[0],
        );

        $this->actingAs($this->superadmin)
            ->get(route('admin.campaigns.edit', $campaign->id))
            ->assertOk()
            ->assertDontSee('id="btn-sync-campaign-stats"', false);
    }
    public function test_manual_stats_sync_json_queues_only_recent_sent_zoho_runs(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $campaign = $this->makeCampaign();
        $eligible = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'eligible-json',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'zoho_campaign_key' => 'CK-JSON',
            'finished_at' => now()->subDay(),
        ]);
        foreach ([
            ['occurrence_key' => 'old-zoho', 'run_at' => now()->subDays(31), 'status' => 'sent', 'zoho_campaign_key' => 'CK-OLD', 'finished_at' => now()->subDays(31)],
            ['occurrence_key' => 'recent-local', 'run_at' => now()->subDay(), 'status' => 'sent', 'finished_at' => now()->subDay()],
            ['occurrence_key' => 'recent-prepared', 'run_at' => now()->subDay(), 'status' => 'prepared', 'zoho_campaign_key' => 'CK-PREPARED'],
        ] as $attributes) {
            CampaignRun::create(['campaign_id' => $campaign->id] + $attributes);
        }

        $response = $this->actingAs($this->superadmin)
            ->postJson(route('admin.campaigns.syncStats', $campaign->id));

        $response->assertOk()
            ->assertJson([
                'message' => html_entity_decode('Synchronisation Zoho mise en file pour 1 ex&eacute;cution(s).'),
                'redirect' => route('admin.campaigns.view', $campaign->id),
            ]);
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\SyncCampaignStatsJob::class,
            fn ($job) => $job->runId === $eligible->id,
        );
        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\SyncCampaignRecipientEventsJob::class,
            fn ($job) => $job->runId === $eligible->id,
        );
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncCampaignStatsJob::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncCampaignRecipientEventsJob::class, 1);
    }

    /**
     * The syncStats route handler is the other stats-sync entry point besides
     * the campaign:sync-stats command. A mailjet campaign must dispatch
     * SyncMailjetEventsJob, never SyncCampaignRecipientEventsJob — this is
     * the manual "Synchroniser les statistiques" button's target.
     */
    public function test_manual_stats_sync_json_queues_mailjet_events_job_for_a_mailjet_campaign(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $campaign = $this->makeCampaign();
        $campaign->update(['delivery_channel' => 'mailjet']);
        $eligible = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'eligible-mailjet-json',
            'run_at' => now()->subDay(),
            'status' => 'sent',
            'finished_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->postJson(route('admin.campaigns.syncStats', $campaign->id));

        $response->assertOk()
            ->assertJson([
                'message' => html_entity_decode('Synchronisation Mailjet mise en file pour 1 ex&eacute;cution(s).'),
                'redirect' => route('admin.campaigns.view', $campaign->id),
            ]);
        \Illuminate\Support\Facades\Bus::assertDispatched(
            \App\Jobs\SyncCampaignStatsJob::class,
            fn ($job) => $job->runId === $eligible->id,
        );
        \Illuminate\Support\Facades\Bus::assertDispatched(
            \App\Jobs\SyncMailjetEventsJob::class,
            fn ($job) => $job->runId === $eligible->id,
        );
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SyncCampaignRecipientEventsJob::class);
    }

    public function test_view_only_user_sees_zoho_sync_state_but_not_the_manual_action(): void
    {
        $campaign = $this->makeCampaign();
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sync-state',
            'run_at' => now()->subHour(),
            'status' => 'sent',
            'zoho_campaign_key' => 'CK-STATE',
            'finished_at' => now()->subHour(),
            'stats_synced_at' => now()->subMinutes(5),
            'stats_sync_error' => 'Zoho temporairement indisponible',
        ]);

        $response = $this->actingAs($this->commercial)
            ->get(route('admin.campaigns.view', $campaign->id));

        $response->assertOk();
        $response->assertSeeHtml('Derni&egrave;re synchronisation Zoho');
        $response->assertSee('Zoho temporairement indisponible');
        $response->assertDontSee('Synchroniser les statistiques');
    }
    public function test_admin_sync_button_uses_an_eligible_zoho_run_instead_of_the_latest_local_run(): void
    {
        $campaign = $this->makeCampaign();
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sync-button-zoho',
            'run_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
            'status' => 'sent',
            'zoho_campaign_key' => 'CK-BUTTON',
        ]);
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sync-button-local',
            'run_at' => now()->subHour(),
            'finished_at' => now()->subHour(),
            'status' => 'sent',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get(route('admin.campaigns.view', $campaign->id));

        $response->assertOk();
        $response->assertSee('data-campaign-stats-sync', false);
        $response->assertSee('type="button"', false);
        $response->assertSee('data-url="' . route('admin.campaigns.syncStats', $campaign->id) . '"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-campaign-stats-sync'));
    }
}
