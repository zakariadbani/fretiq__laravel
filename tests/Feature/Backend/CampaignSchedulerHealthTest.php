<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CampaignSchedulerHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zoho.driver' => 'local']);

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        app(SettingService::class)->clearCache();
        Carbon::setTestNow(Carbon::parse('2026-07-12 10:00:00', 'UTC'));

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(SettingService::class)->clearCache();

        parent::tearDown();
    }

    private function makeCampaign(array $overrides = []): Campaign
    {
        $suffix = uniqid();
        $segment = Segment::create(['name' => "Scheduler segment {$suffix}", 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name' => "Scheduler template {$suffix}",
            'subject' => 'Scheduler subject',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name' => "Scheduler sender {$suffix}",
            'email' => "scheduler-{$suffix}@tcl.test",
        ]);

        return Campaign::create(array_replace([
            'name' => "Scheduler campaign {$suffix}",
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'one_shot',
            'scheduled_at' => now()->addDay(),
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ], $overrides));
    }

    public function test_schedule_helpers_classify_overdue_and_convert_to_paris_wall_clock(): void
    {
        $now = Carbon::now('UTC');
        $overdue = new Campaign([
            'schedule_type' => 'one_shot',
            'scheduled_at' => $now->copy()->subMinute(),
            'timezone' => 'Europe/Paris',
            'is_active' => true,
        ]);

        $this->assertTrue($overdue->isOverdue($now));
        $this->assertFalse((new Campaign([
            'schedule_type' => 'one_shot',
            'scheduled_at' => $now->copy()->addMinute(),
            'is_active' => true,
        ]))->isOverdue($now));
        $this->assertFalse((new Campaign([
            'schedule_type' => 'one_shot',
            'scheduled_at' => $now->copy()->subMinute(),
            'is_active' => false,
        ]))->isOverdue($now));
        $this->assertFalse((new Campaign([
            'schedule_type' => 'sequence',
            'scheduled_at' => $now->copy()->subMinute(),
            'is_active' => true,
        ]))->isOverdue($now));

        $local = (new Campaign([
            'next_run_at' => Carbon::parse('2026-07-12 08:30:00', 'UTC'),
            'timezone' => 'Europe/Paris',
        ]))->scheduledAtLocal();

        $this->assertNotNull($local);
        $this->assertSame('2026-07-12 10:30 Europe/Paris', $local->format('Y-m-d H:i e'));
    }

    public function test_generate_runs_command_records_success_and_is_idempotent(): void
    {
        $campaign = $this->makeCampaign([
            'schedule_type' => 'recurring',
            'recurrence' => ['frequency' => 'daily'],
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('campaigns:generate-runs')->assertExitCode(0);

        $this->assertDatabaseHas('settings', [
            'group_name' => 'campaign_scheduler',
            'setting_key' => 'commands.generate_runs.last_success_at',
        ]);
        $this->assertSame(now()->utc()->toIso8601String(), Setting::get('campaign_scheduler.commands.generate_runs.last_success_at'));
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());

        $this->artisan('campaigns:generate-runs')->assertExitCode(0);

        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());
    }

    public function test_dispatch_due_command_records_success_heartbeat(): void
    {
        $this->artisan('campaigns:dispatch-due')->assertExitCode(0);

        $this->assertDatabaseHas('settings', [
            'group_name' => 'campaign_scheduler',
            'setting_key' => 'commands.dispatch_due.last_success_at',
        ]);
        $this->assertSame(now()->utc()->toIso8601String(), Setting::get('campaign_scheduler.commands.dispatch_due.last_success_at'));
    }

    public function test_dispatch_due_claims_an_overdue_run_only_once(): void
    {
        Bus::fake();

        $company = Company::create([
            'name' => 'Scheduler client',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        Contact::create([
            'company_id' => $company->id,
            'email' => 'scheduler-client@example.test',
            'name' => 'Scheduler contact',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);

        $campaign = $this->makeCampaign();
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'overdue-dispatch',
            'run_at' => now()->subMinute(),
            'status' => 'scheduled',
        ]);

        $this->artisan('campaigns:dispatch-due')->assertExitCode(0);
        $this->artisan('campaigns:dispatch-due')->assertExitCode(0);

        $this->assertSame('sending', $run->fresh()->status);
        Bus::assertDispatchedTimes(SendCampaignJob::class, 1);
    }

    public function test_index_and_view_render_scheduler_warnings_until_all_required_commands_are_fresh(): void
    {
        $campaign = $this->makeCampaign();

        foreach (['/admin/campaigns', "/admin/campaigns/{$campaign->id}"] as $url) {
            $this->actingAs($this->superadmin)
                ->get($url)
                ->assertOk()
                ->assertSee("Le planificateur des campagnes n'est pas complet", false)
                ->assertSee('campaigns:generate-runs', false)
                ->assertSee('campaigns:dispatch-due', false);
        }

        Setting::set('campaign_scheduler.commands.generate_runs.last_success_at', now()->utc()->toIso8601String());

        foreach (['/admin/campaigns', "/admin/campaigns/{$campaign->id}"] as $url) {
            $this->actingAs($this->superadmin)
                ->get($url)
                ->assertOk()
                ->assertDontSee('campaigns:generate-runs', false)
                ->assertSee('campaigns:dispatch-due', false);
        }

        Setting::set('campaign_scheduler.commands.generate_runs.last_success_at', now()->subMinutes(3)->utc()->toIso8601String());
        Setting::set('campaign_scheduler.commands.dispatch_due.last_success_at', now()->utc()->toIso8601String());

        foreach (['/admin/campaigns', "/admin/campaigns/{$campaign->id}"] as $url) {
            $this->actingAs($this->superadmin)
                ->get($url)
                ->assertOk()
                ->assertSee('campaigns:generate-runs', false)
                ->assertDontSee('campaigns:dispatch-due', false);
        }

        Setting::set('campaign_scheduler.commands.generate_runs.last_success_at', now()->subMinute()->utc()->toIso8601String());
        Setting::set('campaign_scheduler.commands.dispatch_due.last_success_at', now()->subMinute()->utc()->toIso8601String());

        foreach (['/admin/campaigns', "/admin/campaigns/{$campaign->id}"] as $url) {
            $this->actingAs($this->superadmin)
                ->get($url)
                ->assertOk()
                ->assertDontSee("Le planificateur des campagnes n'est pas complet", false)
                ->assertDontSee('campaigns:generate-runs', false)
                ->assertDontSee('campaigns:dispatch-due', false);
        }
    }

    public function test_datatable_marks_due_campaign_as_overdue(): void
    {
        $campaign = $this->makeCampaign([
            'schedule_type' => 'recurring',
            'recurrence' => ['frequency' => 'daily'],
            'next_run_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($this->superadmin)->get(
            '/admin/campaigns?draw=1&start=0&length=25'
            . '&columns[0][data]=id&columns[0][name]=id'
            . '&order[0][column]=0&order[0][dir]=asc',
            ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'],
        );

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $campaign->id);

        $this->assertNotNull($row);
        $this->assertStringContainsString('En retard', $row['next_run_at']);
    }

    public function test_controller_stores_paris_schedule_as_utc_and_edits_it_as_paris(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->superadmin)
            ->putJson("/admin/campaigns/{$campaign->id}", [
                'name' => $campaign->name,
                'segment_id' => $campaign->segment_id,
                'template_id' => $campaign->template_id,
                'sender_identity_id' => $campaign->sender_identity_id,
                'schedule_type' => 'one_shot',
                'scheduled_at' => '2026-07-12 10:30',
                'timezone' => 'Europe/Paris',
                'is_active' => '1',
            ])
            ->assertOk();

        $campaign->refresh();
        $this->assertSame('2026-07-12 08:30:00', $campaign->scheduled_at->utc()->format('Y-m-d H:i:s'));

        $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}/edit")
            ->assertOk()
            ->assertSee('value="2026-07-12 10:30"', false);
    }
}
