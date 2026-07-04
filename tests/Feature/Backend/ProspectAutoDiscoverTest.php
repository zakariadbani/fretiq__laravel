<?php

namespace Tests\Feature\Backend;

use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Models\User;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProspectAutoDiscoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Auto Critere ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    private function createRunToday(ProspectCriteria $criteria, string $status): DiscoveryRun
    {
        return DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => $status,
            'quota_date'           => Carbon::today()->toDateString(),
            'credits_reserved'     => 5,
            'consumed'             => 0,
        ]);
    }

    public function test_dispatches_job_and_creates_run_for_due_criteria_and_proves_paris_timezone_conversion(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $criteria = $this->makeCriteria([
            'is_active'   => true,
            'auto_run'    => true,
            'run_at_hour' => 11,
        ]);

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        $this->assertDatabaseHas('discovery_runs', [
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'pending',
            'quota_date'           => Carbon::today()->toDateString(),
        ]);
        Queue::assertPushed(RunDiscoveryPipelineJob::class, 1);
    }

    /**
     * THE double-fire reproducer (BLOCK bug): a criterion with run_at_hour=0 sits
     * in the band between UTC-midnight and Paris-midnight. Two scheduler ticks in
     * the SAME Paris calendar day (2026-01-11 Paris) must produce exactly ONE run
     * + ONE dispatched job — not two.
     *
     * Winter date (CET, UTC+1): 2026-01-10 23:30 UTC = 2026-01-11 00:30 Paris.
     * 2026-01-11 00:30 UTC = 2026-01-11 01:30 Paris. Both ticks land on Paris day
     * 2026-01-11 — with the old UTC-quota_date bug they'd get quota_date
     * 2026-01-10 and 2026-01-11 respectively (two reservations); with the fix
     * (Paris quota_date via DiscoveryQuotaService::today()) both write
     * quota_date=2026-01-11 → the second tick's whereDoesntHave() sees the first
     * run and skips.
     */
    public function test_boundary_straddle_does_not_double_fire_within_same_paris_day(): void
    {
        // Default quota tz is Europe/Paris — assert the setting resolves to it.
        $this->assertSame('Europe/Paris', app(DiscoveryQuotaService::class)->quotaTz());

        Queue::fake();

        $criteria = $this->makeCriteria([
            'is_active'   => true,
            'auto_run'    => true,
            'run_at_hour' => 0,
        ]);

        // Tick 1: 2026-01-10 23:30 UTC = 2026-01-11 00:30 Paris.
        Carbon::setTestNow(Carbon::parse('2026-01-10 23:30:00', 'UTC'));
        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        $this->assertSame(
            1,
            DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count(),
            'First tick must create exactly one run'
        );
        Queue::assertPushed(RunDiscoveryPipelineJob::class, 1);

        // Tick 2: 2026-01-11 00:30 UTC = 2026-01-11 01:30 Paris — SAME Paris day.
        Carbon::setTestNow(Carbon::parse('2026-01-11 00:30:00', 'UTC'));
        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        $this->assertSame(
            1,
            DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count(),
            'Second tick (same Paris day) must NOT create a second run — this is the double-fire bug reproducer'
        );
        Queue::assertPushed(RunDiscoveryPipelineJob::class, 1);

        // Both reservations (well, the single one) must be pinned to the Paris day.
        $this->assertDatabaseHas('discovery_runs', [
            'prospect_criteria_id' => $criteria->id,
            'quota_date'           => '2026-01-11',
        ]);
    }

    /**
     * Toggling decouverte.timezone to UTC must change the gate's calendar day —
     * proving the setting actually drives behavior, not just quotaTz() in isolation.
     *
     * With tz=UTC, 2026-01-10 23:30 UTC and 2026-01-11 00:30 UTC are DIFFERENT
     * UTC calendar days (2026-01-10 vs 2026-01-11), so both ticks are allowed to
     * fire — two runs, two jobs.
     */
    public function test_utc_setting_drives_gate_by_utc_calendar_day(): void
    {
        Setting::set('decouverte.timezone', 'UTC');
        $this->assertSame('UTC', app(DiscoveryQuotaService::class)->quotaTz());

        Queue::fake();

        $criteria = $this->makeCriteria([
            'is_active'   => true,
            'auto_run'    => true,
            'run_at_hour' => 0,
        ]);

        // Tick 1: 2026-01-10 23:30 UTC — UTC day 2026-01-10.
        Carbon::setTestNow(Carbon::parse('2026-01-10 23:30:00', 'UTC'));
        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        $this->assertDatabaseHas('discovery_runs', [
            'prospect_criteria_id' => $criteria->id,
            'quota_date'           => '2026-01-10',
        ]);

        // Tick 2: 2026-01-11 00:30 UTC — a NEW UTC day (2026-01-11) → allowed to fire again.
        Carbon::setTestNow(Carbon::parse('2026-01-11 00:30:00', 'UTC'));
        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        $this->assertSame(
            2,
            DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count(),
            'With quota tz=UTC, the two ticks fall on different UTC calendar days — both must fire'
        );
        Queue::assertPushed(RunDiscoveryPipelineJob::class, 2);
        $this->assertDatabaseHas('discovery_runs', [
            'prospect_criteria_id' => $criteria->id,
            'quota_date'           => '2026-01-11',
        ]);
    }

    public function test_skips_criteria_before_scheduled_hour(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $criteria = $this->makeCriteria([
            'is_active'   => true,
            'auto_run'    => true,
            'run_at_hour' => 20,
        ]);

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        $this->assertSame(0, DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count());
        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
    }

    public function test_skips_when_pending_run_exists_today(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true, 'auto_run' => true, 'run_at_hour' => 8]);
        $this->createRunToday($criteria, 'pending');

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
        $this->assertSame(1, DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count());
    }

    public function test_skips_when_completed_run_exists_today(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true, 'auto_run' => true, 'run_at_hour' => 8]);
        $this->createRunToday($criteria, 'completed');

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
        $this->assertSame(1, DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count());
    }

    public function test_skips_when_failed_run_exists_today(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true, 'auto_run' => true, 'run_at_hour' => 8]);
        $this->createRunToday($criteria, 'failed');

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
        $this->assertSame(1, DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count());
    }

    public function test_skips_inactive_and_non_auto_run_criteria(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $inactive = $this->makeCriteria(['is_active' => false, 'auto_run' => true, 'run_at_hour' => 8]);
        $notAuto  = $this->makeCriteria(['is_active' => true, 'auto_run' => false, 'run_at_hour' => 8]);

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
        $this->assertSame(0, DiscoveryRun::whereIn('prospect_criteria_id', [$inactive->id, $notAuto->id])->count());
    }

    public function test_quota_exhausted_criteria_skipped_without_aborting_command(): void
    {
        $package = Package::create([
            'name'          => 'Pack 0/j',
            'daily_credits' => 0,
            'is_active'     => true,
            'sort_order'    => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $this->makeCriteria(['is_active' => true, 'auto_run' => true, 'run_at_hour' => 8]);

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
    }

    public function test_runs_again_next_day(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true, 'auto_run' => true, 'run_at_hour' => 8]);

        $this->artisan('prospect:auto-discover')->assertExitCode(0);
        Queue::assertPushed(RunDiscoveryPipelineJob::class, 1);

        Carbon::setTestNow('2026-07-05 10:00:00');

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertPushed(RunDiscoveryPipelineJob::class, 2);
    }

    public function test_manual_discover_endpoint_still_allowed_after_failed_auto_run(): void
    {
        Carbon::setTestNow('2026-07-04 10:00:00');
        Queue::fake();

        $criteria = $this->makeCriteria(['is_active' => true, 'auto_run' => true, 'run_at_hour' => 8]);
        $this->createRunToday($criteria, 'failed');

        $this->artisan('prospect:auto-discover')->assertExitCode(0);
        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);

        $superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $superadmin->assignRole('superadmin');

        $response = $this->actingAs($superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(200);
    }
}