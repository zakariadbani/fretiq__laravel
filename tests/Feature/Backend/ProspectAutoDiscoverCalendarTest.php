<?php

namespace Tests\Feature\Backend;

use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ProspectAutoDiscoverCalendarTest — the chunk-7 day-level BusinessCalendarService
 * gate on `prospect:auto-discover` (weekend + blackout dates).
 *
 * Every Carbon::setTestNow() below is pinned in UTC — a non-UTC mock silently
 * shifts the default timezone that Eloquent's datetime cast falls back to
 * (see SequenceProcessTest / BusinessCalendarServiceTest for the same rule).
 */
class ProspectAutoDiscoverCalendarTest extends TestCase
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
            'name' => 'Auto Critere '.uniqid(),
            'sectors' => ['transport'],
            'countries' => ['France'],
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 8,
        ], $overrides));
    }

    // ── Weekend ──────────────────────────────────────────────────────────────────

    public function test_weekend_blocks_the_whole_tick_and_exits_success(): void
    {
        // 2026-08-08 is a Saturday. planification.skip_weekends defaults to true
        // (no row in the settings table), so this must block WITHOUT the command
        // reporting failure.
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00', 'UTC'));
        Queue::fake();

        $criteria = $this->makeCriteria();

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
        $this->assertSame(0, DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count());
    }

    // ── Blackout date ────────────────────────────────────────────────────────────

    public function test_blackout_date_blocks_the_whole_tick_and_exits_success(): void
    {
        // 2026-08-12 is a Wednesday — skip_weekends is turned OFF so only the
        // blackout list can be responsible for the block.
        Setting::set('planification.skip_weekends', false);
        Setting::set('planification.blackout_dates', '2026-08-12');

        Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00', 'UTC'));
        Queue::fake();

        $criteria = $this->makeCriteria();

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
        $this->assertSame(0, DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count());
    }

    // ── Weekday — unchanged behaviour ───────────────────────────────────────────

    public function test_weekday_dispatches_as_before(): void
    {
        // 2026-08-10 is a Monday — the day-level gate must not interfere with
        // normal weekday dispatch.
        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00', 'UTC'));
        Queue::fake();

        $criteria = $this->makeCriteria();

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertPushed(RunDiscoveryPipelineJob::class, 1);
        $this->assertDatabaseHas('discovery_runs', [
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'pending',
        ]);
    }

    // ── Gate reads quotaTz(), not UTC ───────────────────────────────────────────

    public function test_gate_reads_quota_timezone_not_utc(): void
    {
        // decouverte.timezone is already Europe/Paris by default — set it
        // explicitly so the intent is unambiguous.
        Setting::set('decouverte.timezone', 'Europe/Paris');
        $this->assertSame('Europe/Paris', app(DiscoveryQuotaService::class)->quotaTz());

        // Sunday 23:30 Europe/Paris (winter, CET = UTC+1) = Sunday 22:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-01-11 22:30:00', 'UTC'));
        Queue::fake();

        $criteria = $this->makeCriteria();

        $this->artisan('prospect:auto-discover')->assertExitCode(0);

        Queue::assertNotPushed(RunDiscoveryPipelineJob::class);
        $this->assertSame(0, DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count());
    }
}
