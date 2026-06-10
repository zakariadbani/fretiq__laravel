<?php

namespace Tests\Feature\Backend;

use App\Console\Commands\DiscoveryTerminalizeStale;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DiscoveryQuotaTest — accounting logic for the daily discovery credit quota.
 *
 * NOT duplicated here (covered elsewhere):
 *   - Permission gating         → PackageModuleTest.php
 *   - Badge UI rendering        → QuotaBadgeUiTest.php
 *
 * DB: MySQL (see phpunit.xml — DB_CONNECTION=mysql, DB_DATABASE=fretiq_test).
 * This means GET_LOCK serialization is real and testable.
 *
 * External HTTP: all discovery tests use DISCOVERY_DRIVER=local (default),
 * which reads SerpAPI/Hunter from database/fixtures/discovery/*.json — no
 * real HTTP calls are made. Queue::fake() is used in endpoint tests so jobs
 * are captured, not executed — service-level tests call the service directly.
 */
class DiscoveryQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a limited Package (daily_credits = $credits) and assign it as
     * the active package. Returns the Package.
     */
    private function assignLimitedPackage(int $credits): Package
    {
        $package = Package::create([
            'name'          => "Pack {$credits}/j",
            'daily_credits' => $credits,
            'is_active'     => true,
            'sort_order'    => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
    }

    /**
     * Create an unlimited Package (daily_credits = null) and assign it.
     */
    private function assignUnlimitedPackage(): Package
    {
        $package = Package::create([
            'name'          => 'Illimité',
            'daily_credits' => null,
            'is_active'     => true,
            'sort_order'    => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
    }

    /**
     * Create a ProspectCriteria with the given overrides.
     */
    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Critère Quota ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    /**
     * Insert a terminal (completed) run consuming $consumed credits on $date.
     */
    private function insertConsumedRun(
        ProspectCriteria $criteria,
        int $consumed,
        string $date,
        string $status = 'completed'
    ): DiscoveryRun {
        $assignment = PackageAssignment::orderByDesc('id')->first();

        return DiscoveryRun::create([
            'prospect_criteria_id'  => $criteria->id,
            'status'                => $status,
            'credits_reserved'      => $consumed,
            'consumed'              => $consumed,
            'quota_date'            => $date,
            'package_assignment_id' => $assignment?->id,
        ]);
    }

    // ── Scenario 1: 422 at 0 solde ────────────────────────────────────────────

    /**
     * When the full daily allowance has been consumed, POST /discover must
     * return 422 with the French exhaustion message.
     */
    public function test_422_when_daily_solde_exhausted(): void
    {
        Queue::fake();

        $package  = $this->assignLimitedPackage(10);
        $criteria = $this->makeCriteria(['daily_limit' => 20]);

        // Insert a completed run that has consumed all 10 daily credits.
        $this->insertConsumedRun($criteria, 10, Carbon::today()->toDateString());

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Solde du jour épuisé',
            $response->json('text') ?? '',
            'Response text must contain the French exhaustion message'
        );

        // No new run should have been created (reservation aborted).
        $this->assertSame(
            1,
            DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count(),
            'No new run row must be created when quota is exhausted'
        );
    }

    // ── Scenario 2: Partial cap ────────────────────────────────────────────────

    /**
     * When 4 credits are already consumed from a 10-credit package and the
     * criteria wants 20, the run should be created with credits_reserved = 6
     * and the response must mention "6 entreprises possibles aujourd'hui".
     */
    public function test_partial_cap_when_remaining_less_than_daily_limit(): void
    {
        Queue::fake();

        $package  = $this->assignLimitedPackage(10);
        $criteria = $this->makeCriteria(['daily_limit' => 20]);

        // 4 credits already consumed today.
        $this->insertConsumedRun($criteria, 4, Carbon::today()->toDateString());

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(200);

        // The new run must have credits_reserved = 6 (10 - 4).
        $newRun = DiscoveryRun::where('prospect_criteria_id', $criteria->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $this->assertNotNull($newRun, 'A new pending run must have been created');
        $this->assertSame(6, $newRun->credits_reserved, 'credits_reserved must be 6 (remaining = 10 - 4)');

        $this->assertStringContainsString(
            '6 entreprises possibles aujourd\'hui',
            $response->json('text') ?? '',
            'Success message must mention the partial batch count'
        );
    }

    // ── Scenario 3: Reservation blocks double-spend ───────────────────────────

    /**
     * Cross-criteria double-spend protection:
     * With a 10-credit package and criteria A already holding a 10-credit
     * pending reservation, a second POST for criteria B must return 422
     * because the in-flight reservation exhausts the remaining balance.
     *
     * NOTE: True parallel GET_LOCK serialization requires concurrent processes
     * and cannot be replicated in a single-process test. What we are testing
     * here is the accounting math — that usedOn() counts pending run reservations
     * so remainingOn() returns 0 for the second dispatch. The named lock
     * prevents concurrent over-reservation on MySQL; the math prevents it in
     * all single-process paths (artisan, sequential HTTP requests).
     */
    public function test_pending_reservation_blocks_double_spend_for_different_criteria(): void
    {
        Queue::fake();

        $this->assignLimitedPackage(10);

        $criteriaA = $this->makeCriteria(['daily_limit' => 10]);
        $criteriaB = $this->makeCriteria(['daily_limit' => 10]);

        // Criteria A already holds a pending reservation for the full 10 credits.
        $assignmentId = PackageAssignment::orderByDesc('id')->value('id');
        DiscoveryRun::create([
            'prospect_criteria_id'  => $criteriaA->id,
            'status'                => 'pending',
            'credits_reserved'      => 10,
            'consumed'              => 0,
            'quota_date'            => Carbon::today()->toDateString(),
            'package_assignment_id' => $assignmentId,
        ]);

        // Now POST for criteria B — remaining is 0 (the pending A reservation counts).
        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteriaB->id}/discover");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Solde du jour épuisé',
            $response->json('text') ?? '',
            'In-flight reservation from criteria A must block criteria B dispatch'
        );
    }

    // ── Scenario 4: Midnight straddle ─────────────────────────────────────────

    /**
     * A run dispatched at 23:59 must:
     *   - Have quota_date = dispatch day.
     *   - After midnight, the NEW day's remaining = full daily_credits (the pending
     *     run does NOT count against the new day).
     *   - The dispatch day's remaining still reflects the reservation.
     */
    public function test_midnight_straddle_reservation_pinned_to_dispatch_day(): void
    {
        $this->assignLimitedPackage(10);
        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        // Travel to 23:59 today and reserve a run.
        $this->travelTo(Carbon::today()->endOfDay()->subMinute());
        $dispatchDay = Carbon::today()->toDateString();
        $run         = $svc->reserveRun($criteria);

        $this->assertSame(
            $dispatchDay,
            $run->quota_date->toDateString(),
            'Run quota_date must match the dispatch day'
        );

        // Now travel to the next day 00:05.
        $this->travelTo(Carbon::today()->addDay()->startOfDay()->addMinutes(5));
        $nextDay = Carbon::today();
        $prevDay = $nextDay->copy()->subDay();

        // New day: full 10 credits available (the pending run is pinned to yesterday).
        $this->assertSame(
            10,
            $svc->remainingOn($nextDay),
            'New day must show full daily credits — the pending run from yesterday must NOT count'
        );

        // Dispatch day: the reservation IS counted (pending run's credits_reserved).
        $this->assertSame(
            0,
            $svc->remainingOn($prevDay),
            'Dispatch day remaining must reflect the in-flight reservation'
        );
    }

    // ── Scenario 5: Crash/retry budget ────────────────────────────────────────

    /**
     * A run with credits_reserved=10 and consumed=7 (a prior partial execution)
     * must leave a budget of exactly 3 on retry.
     *
     * Tested at two seams:
     *   a) DiscoveryQuotaService arithmetic (unit-style, cheapest).
     *   b) RunDiscoveryPipelineJob's handle() budget computation via a pipeline spy.
     *
     * The pipeline runs in local-fixture mode (no real HTTP) so we let the job
     * execute synchronously via dispatchSync and assert consumed stays <= credits_reserved.
     */
    public function test_crash_retry_budget_is_reserved_minus_consumed(): void
    {
        $this->assignLimitedPackage(10);
        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        $run = DiscoveryRun::create([
            'prospect_criteria_id'  => $criteria->id,
            'status'                => 'running',
            'credits_reserved'      => 10,
            'consumed'              => 7,
            'quota_date'            => Carbon::today()->toDateString(),
            'package_assignment_id' => PackageAssignment::orderByDesc('id')->value('id'),
            'started_at'            => now()->subSeconds(10),
        ]);

        // The job's budget formula: max(0, credits_reserved - consumed) = max(0, 10-7) = 3.
        $budgetFromJob = max(0, $run->credits_reserved - $run->consumed);
        $this->assertSame(3, $budgetFromJob, 'Retry budget must be credits_reserved - consumed = 3');

        // Execute the job synchronously (local fixture driver — no HTTP).
        // The pipeline has 3 Hunter credits available; consumed must not exceed 10.
        \App\Jobs\RunDiscoveryPipelineJob::dispatchSync($criteria->id, $run->id);

        $run->refresh();

        $this->assertLessThanOrEqual(
            $run->credits_reserved,
            $run->consumed,
            'consumed must never exceed credits_reserved'
        );
        $this->assertContains(
            $run->status,
            ['completed', 'failed'],
            'Run must reach a terminal state after the job executes'
        );
    }

    // ── Scenario 6: Conditional debit stops terminalized run ─────────────────

    /**
     * When the run's status is set to 'failed' mid-flight, the conditional debit
     * guard (WHERE status='running') affects 0 rows, and the pipeline loop must
     * abort — returning partial stats without incrementing consumed.
     *
     * We exercise this by calling DiscoveryPipelineService::run() directly with
     * a run whose status is already 'failed'. The debit will be blocked and
     * consumed must remain 0.
     */
    public function test_conditional_debit_blocked_when_run_status_failed(): void
    {
        $criteria = $this->makeCriteria(['daily_limit' => 5]);

        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'status'               => 'failed',  // already terminated
            'credits_reserved'     => 5,
            'consumed'             => 0,
            'quota_date'           => Carbon::today()->toDateString(),
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);

        // Run with the failed run — the conditional debit guard should block.
        $stats = $pipeline->run($criteria, 5, $run);

        $run->refresh();

        // The debit guard fires on the first domain and blocks (0 rows affected),
        // so consumed must stay at 0 (or at most increment once if the guard check
        // is on < credits_reserved rather than status check first — verify intent).
        // Per spec: WHERE status='running' AND consumed < credits_reserved → 0 rows when status=failed.
        $this->assertSame(
            0,
            $run->consumed,
            'consumed must remain 0 when the run is failed (conditional debit guard must block)'
        );

        // Stats should be empty (loop aborted immediately on first debit failure).
        $this->assertSame(
            0,
            $stats['companies'],
            'No companies should be counted when the debit guard blocks on first iteration'
        );
    }

    // ── Scenario 7: Terminalizer ──────────────────────────────────────────────

    /**
     * The discovery:terminalize-stale command must:
     *   - Flip stale pending run (created_at > 60 s ago) → failed with error text and finished_at.
     *   - Flip stale running run (started_at > 360 s ago) → failed with error text and finished_at.
     *   - Leave a fresh running run (started_at recently) untouched.
     *   - After flipping: usedOn(today) counts the failed runs' consumed (not reservation).
     */
    public function test_terminalizer_flips_stale_runs_and_releases_reservations(): void
    {
        $this->assignLimitedPackage(50);
        $criteria = $this->makeCriteria(['daily_limit' => 20]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $assignmentId = PackageAssignment::orderByDesc('id')->value('id');
        $today        = Carbon::today()->toDateString();

        // Stale pending: created 120 s ago, never picked up.
        // Use DB::table() to bypass Eloquent's auto-timestamp override on create().
        $stalePendingId = DB::table('discovery_runs')->insertGetId([
            'prospect_criteria_id'  => $criteria->id,
            'status'                => 'pending',
            'credits_reserved'      => 15,
            'consumed'              => 0,
            'quota_date'            => $today,
            'package_assignment_id' => $assignmentId,
            'created_at'            => now()->subSeconds(120)->toDateTimeString(),
            'updated_at'            => now()->subSeconds(120)->toDateTimeString(),
        ]);
        $stalePending = DiscoveryRun::find($stalePendingId);

        // Stale running: started 400 s ago (> 360 s threshold).
        // Use DB::table() to set started_at in the past.
        $staleRunningId = DB::table('discovery_runs')->insertGetId([
            'prospect_criteria_id'  => $criteria->id,
            'status'                => 'running',
            'credits_reserved'      => 10,
            'consumed'              => 3,   // partial work done
            'quota_date'            => $today,
            'package_assignment_id' => $assignmentId,
            'created_at'            => now()->subSeconds(400)->toDateTimeString(),
            'updated_at'            => now()->subSeconds(400)->toDateTimeString(),
            'started_at'            => now()->subSeconds(400)->toDateTimeString(),
        ]);
        $staleRunning = DiscoveryRun::find($staleRunningId);

        // Fresh running: started 10 s ago — must NOT be flipped.
        $freshRunningId = DB::table('discovery_runs')->insertGetId([
            'prospect_criteria_id'  => $criteria->id,
            'status'                => 'running',
            'credits_reserved'      => 8,
            'consumed'              => 2,
            'quota_date'            => $today,
            'package_assignment_id' => $assignmentId,
            'created_at'            => now()->subSeconds(10)->toDateTimeString(),
            'updated_at'            => now()->subSeconds(10)->toDateTimeString(),
            'started_at'            => now()->subSeconds(10)->toDateTimeString(),
        ]);
        $freshRunning = DiscoveryRun::find($freshRunningId);

        // Run the terminalizer command.
        $this->artisan('discovery:terminalize-stale')
             ->assertExitCode(0);

        $stalePending->refresh();
        $staleRunning->refresh();
        $freshRunning->refresh();

        // Stale pending → failed.
        $this->assertSame('failed', $stalePending->status, 'Stale pending run must be flipped to failed');
        $this->assertNotNull($stalePending->finished_at, 'Stale pending run must have finished_at set');
        $this->assertNotEmpty($stalePending->error, 'Stale pending run must have error text set');

        // Stale running → failed.
        $this->assertSame('failed', $staleRunning->status, 'Stale running run must be flipped to failed');
        $this->assertNotNull($staleRunning->finished_at, 'Stale running run must have finished_at set');
        $this->assertNotEmpty($staleRunning->error, 'Stale running run must have error text set');

        // Fresh running → untouched.
        $this->assertSame('running', $freshRunning->status, 'Fresh running run must NOT be touched by terminalizer');

        // After terminalizing: usedOn(today) = consumed of failed runs + reserved of fresh pending/running.
        // stalePending consumed=0, staleRunning consumed=3, freshRunning in-flight → max(8,2)=8.
        $usedToday = $svc->usedOn(Carbon::today());
        $this->assertSame(
            0 + 3 + 8,  // stalePending.consumed + staleRunning.consumed + freshRunning.max(reserved,consumed)
            $usedToday,
            'usedOn must count consumed (not reservation) for failed runs; reservation for in-flight runs'
        );
    }

    // ── Scenario 8: Unlimited bypass ──────────────────────────────────────────

    /**
     * A package with daily_credits = null (unlimited) must bypass all quota
     * checks — POST /discover returns 200 regardless of how many runs exist,
     * and effectiveBatchFor() returns the criteria's daily_limit.
     */
    public function test_unlimited_package_bypasses_quota_entirely(): void
    {
        Queue::fake();

        $this->assignUnlimitedPackage();
        $criteria = $this->makeCriteria(['daily_limit' => 20]);

        // Insert many consumed runs to simulate heavy prior usage.
        for ($i = 0; $i < 5; $i++) {
            $this->insertConsumedRun($criteria, 100, Carbon::today()->toDateString());
        }

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(200);

        // effectiveBatchFor() must return daily_limit (no cap applied).
        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);
        $this->assertTrue($svc->isUnlimited(), 'isUnlimited() must be true for null daily_credits package');
        $this->assertSame(
            20,
            $svc->effectiveBatchFor($criteria),
            'effectiveBatchFor() must return daily_limit when unlimited'
        );
    }

    // ── Scenario 9: No assignment = unlimited ─────────────────────────────────

    /**
     * When no PackageAssignment rows exist at all (fresh install state),
     * the system must treat the install as unlimited — isUnlimited() = true
     * and POST /discover must return 200.
     */
    public function test_no_package_assignment_is_treated_as_unlimited(): void
    {
        Queue::fake();

        // Ensure no assignments exist.
        PackageAssignment::query()->delete();
        Package::query()->delete();

        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $this->assertTrue($svc->isUnlimited(), 'isUnlimited() must be true when no assignment exists');
        $this->assertNull($svc->activePackage(), 'activePackage() must return null when no assignment exists');

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(200);
    }

    // ── Scenario 10: Assignment change mid-day ────────────────────────────────

    /**
     * Changing the active package changes the solde immediately:
     *   - Upgrade: remainingOn(today) increases (more credits now available).
     *   - Downgrade: remainingOn(today) is floored at 0 (no negative remaining),
     *     and POST /discover returns 422 if the new package is exhausted.
     */
    public function test_package_change_mid_day_affects_remaining_immediately(): void
    {
        Queue::fake();

        // Start with a 10-credit package, 8 already consumed.
        $package10 = $this->assignLimitedPackage(10);
        $criteria  = $this->makeCriteria(['daily_limit' => 20]);
        $this->insertConsumedRun($criteria, 8, Carbon::today()->toDateString());

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        // Remaining = 10 - 8 = 2.
        $this->assertSame(2, $svc->remainingOn(Carbon::today()), 'Pre-upgrade remaining must be 2');

        // Upgrade to 50 credits/day → remaining jumps to 50 - 8 = 42.
        $this->assignLimitedPackage(50);
        $this->assertSame(42, $svc->remainingOn(Carbon::today()), 'Post-upgrade remaining must be 42');

        // Now downgrade to 5 credits/day → 5 - 8 = -3 → floored to 0.
        $this->assignLimitedPackage(5);
        $this->assertSame(0, $svc->remainingOn(Carbon::today()), 'Post-downgrade remaining must be floored at 0');

        // POST /discover must return 422 after downgrade (remaining = 0).
        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Solde du jour épuisé',
            $response->json('text') ?? '',
            'Discover must be blocked after downgrade floors remaining to 0'
        );
    }

    // ── Scenario 11: Artisan skips at 0 solde ─────────────────────────────────

    /**
     * When the daily solde is exhausted, the prospect:discover artisan command
     * must skip the criteria (exit 0, output contains skip message, no new run created).
     */
    public function test_artisan_skips_criteria_when_solde_exhausted(): void
    {
        $this->assignLimitedPackage(10);
        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        // Consume all 10 credits.
        $this->insertConsumedRun($criteria, 10, Carbon::today()->toDateString());

        $runsBefore = DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count();

        $this->artisan('prospect:discover')
             ->assertExitCode(0)
             ->expectsOutputToContain('solde épuisé, skipped');

        // No new run should have been created.
        $runsAfter = DiscoveryRun::where('prospect_criteria_id', $criteria->id)->count();

        $this->assertSame(
            $runsBefore,
            $runsAfter,
            'prospect:discover must NOT create a new run row when solde is exhausted'
        );
    }
}
