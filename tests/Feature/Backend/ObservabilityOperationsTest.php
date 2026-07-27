<?php

namespace Tests\Feature\Backend;

use App\Models\Setting;
use App\Models\User;
use App\Services\Analytics\QueueObservabilityService;
use App\Services\Settings\SettingService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ObservabilityOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->superadmin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->superadmin->assignRole('superadmin');
    }

    protected function tearDown(): void
    {
        app(SettingService::class)->clearCache();
        parent::tearDown();
    }

    public function test_active_jobs_expose_waiting_delayed_reserved_and_safe_names(): void
    {
        $now = now()->timestamp;
        $waiting = $this->insertJob(['displayName' => 'App\\Jobs\\SendCampaignJob'], null, $now);
        $delayed = $this->insertJob(['displayName' => 'App\\Jobs\\RunDiscoveryPipelineJob'], null, $now + 300);
        $reserved = $this->insertJob(['not_display_name' => true], $now, $now);

        $jobs = app(QueueObservabilityService::class)->activeJobs()->keyBy('id');

        $this->assertSame('waiting', $jobs[$waiting]->state);
        $this->assertSame('SendCampaignJob', $jobs[$waiting]->name);
        $this->assertSame('delayed', $jobs[$delayed]->state);
        $this->assertSame('reserved', $jobs[$reserved]->state);
        $this->assertSame("Job #{$reserved}", $jobs[$reserved]->name);
    }

    public function test_only_unreserved_jobs_can_be_cancelled(): void
    {
        $waiting = $this->insertJob([], null, now()->timestamp);
        $reserved = $this->insertJob([], now()->timestamp, now()->timestamp);

        $this->actingAs($this->superadmin)->post("/admin/observability/jobs/{$waiting}/cancel")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('jobs', ['id' => $waiting]);

        $this->actingAs($this->superadmin)->post("/admin/observability/jobs/{$reserved}/cancel")
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('jobs', ['id' => $reserved]);
    }

    public function test_failed_job_can_be_retried_then_forgotten(): void
    {
        $retryUuid = $this->insertFailedJob();

        $this->actingAs($this->superadmin)->post("/admin/observability/failed-jobs/{$retryUuid}/retry")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $retryUuid]);
        $this->assertDatabaseCount('jobs', 1);

        $forgetUuid = $this->insertFailedJob();
        $this->actingAs($this->superadmin)->delete("/admin/observability/failed-jobs/{$forgetUuid}")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $forgetUuid]);
    }

    public function test_retry_all_requeues_every_failed_job(): void
    {
        $this->insertFailedJob();
        $this->insertFailedJob();

        $this->actingAs($this->superadmin)->post('/admin/observability/failed-jobs/retry-all')
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_scheduler_heartbeat_and_task_switches_are_reported(): void
    {
        $heartbeat = collect(app(Schedule::class)->events())
            ->first(fn ($event) => $event->description === 'observability:scheduler-heartbeat');
        $this->assertNotNull($heartbeat);
        $heartbeat->run($this->app);
        $this->assertSame('healthy', app(QueueObservabilityService::class)->schedulerHealth()['status']);

        $tasks = app(QueueObservabilityService::class)->scheduledTasks();
        $this->assertTrue($tasks->contains('key', 'campaigns_dispatch_due'));

        $this->actingAs($this->superadmin)->patch('/admin/observability/scheduler/tasks/campaigns_dispatch_due', ['enabled' => false])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse((bool) Setting::get('automatisation.campaigns_dispatch_due'));

        $this->actingAs($this->superadmin)->patch('/admin/observability/scheduler/tasks/not_a_task', ['enabled' => false])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertFalse(Setting::has('automatisation.not_a_task'));
    }

    public function test_scheduler_global_switch_and_run_now_are_whitelisted(): void
    {
        $this->actingAs($this->superadmin)->patch('/admin/observability/scheduler', ['enabled' => false])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse((bool) Setting::get('automatisation.cron_enabled'));

        $this->actingAs($this->superadmin)->post('/admin/observability/scheduler/tasks/not_a_task/run')
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_run_now_records_result_and_send_commands_require_send_permission(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/observability/scheduler/tasks/discovery_terminalize_stale/run')
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('success', Setting::get('observability.tasks.discovery_terminalize_stale.last_result'));

        $operator = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $operator->givePermissionTo(['backend.access', 'manage roles']);

        $this->actingAs($operator)
            ->post('/admin/observability/scheduler/tasks/campaigns_dispatch_due/run')
            ->assertForbidden();
    }

    public function test_user_without_manage_roles_cannot_mutate_observability(): void
    {
        $commercial = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $commercial->assignRole('commercial');

        $this->actingAs($commercial)->patch('/admin/observability/scheduler', ['enabled' => false])->assertForbidden();
    }

    private function insertJob(array $payload, ?int $reservedAt, int $availableAt): int
    {
        return DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode($payload),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => now()->timestamp,
        ]);
    }

    private function insertFailedJob(): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'App\\Jobs\\SendCampaignJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => [],
            ]),
            'exception' => "RuntimeException: test failure\ntrace",
            'failed_at' => now(),
        ]);

        return $uuid;
    }
}
