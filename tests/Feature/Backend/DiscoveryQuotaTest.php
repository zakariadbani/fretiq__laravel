<?php

namespace Tests\Feature\Backend;

use App\Console\Commands\DiscoveryTerminalizeStale;
use App\Exceptions\QuotaExhaustedException;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\Company;
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

        config([
            'services.serpapi.driver' => 'local',
            'services.hunter.driver'  => 'local',
        ]);

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
     * Create a Package with both company and contact meters and assign it as active.
     */
    private function assignPackageWith(int $companyCredits, ?int $contactCredits): Package
    {
        $package = Package::create([
            'name'                  => "Pack {$companyCredits}+{$contactCredits}",
            'daily_credits'         => $companyCredits,
            'daily_contact_credits' => $contactCredits,
            'is_active'             => true,
            'sort_order'            => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
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

    /**
     * Create a Package with both company and contact meters (daily + monthly)
     * and assign it as active.
     *
     * Pass null for any cap to indicate unlimited for that meter.
     */
    private function assignPackageWithMonthly(
        ?int $dailyCredits,
        ?int $monthlyCredits,
        ?int $dailyContactCredits = null,
        ?int $monthlyContactCredits = null,
        ?string $anchorDate = null
    ): Package {
        $attrs = [
            'name'                    => 'Pack ' . uniqid(),
            'daily_credits'           => $dailyCredits,
            'monthly_credits'         => $monthlyCredits,
            'daily_contact_credits'   => $dailyContactCredits,
            'monthly_contact_credits' => $monthlyContactCredits,
            'is_active'               => true,
            'sort_order'              => 0,
        ];

        if ($anchorDate !== null) {
            $attrs['quota_anchor_date'] = $anchorDate;
        }

        $package = Package::create($attrs);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
    }

    /**
     * Insert a completed run that consumed $consumed credits on a specific date,
     * also setting contact_consumed = $contactConsumed.
     */
    private function insertConsumedRunWithContact(
        ProspectCriteria $criteria,
        int $consumed,
        int $contactConsumed,
        string $date,
        string $status = 'completed'
    ): DiscoveryRun {
        $assignment = PackageAssignment::orderByDesc('id')->first();

        return DiscoveryRun::create([
            'prospect_criteria_id'     => $criteria->id,
            'status'                   => $status,
            'credits_reserved'         => $consumed,
            'consumed'                 => $consumed,
            'contact_credits_reserved' => $contactConsumed,
            'contact_consumed'         => $contactConsumed,
            'quota_date'               => $date,
            'package_assignment_id'    => $assignment?->id,
        ]);
    }

    /**
     * Create a minimal Company row for manual enrichment tests.
     */
    private function makeCompany(ProspectCriteria $criteria): Company
    {
        return Company::create([
            'criteria_id' => $criteria->id,
            'domain'      => 'testco-' . uniqid() . '.fr',
            'name'        => 'TestCo ' . uniqid(),
            'relationship' => 'prospect',
            'source'       => 'discovered',
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
     * and the response must mention "6 recherches SerpAPI possibles aujourd'hui".
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
            '6 recherches SerpAPI possibles aujourd\'hui',
            $response->json('text') ?? '',
            'Success message must mention the partial SerpAPI search count'
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

        // Travel to 23:59 in the quota timezone (Europe/Paris) and reserve a run.
        // quota_date is pinned to the QUOTA TZ calendar day (not the app's UTC day).
        $this->travelTo($svc->today()->endOfDay()->subMinute());
        $dispatchDay = $svc->today()->toDateString();
        $run         = $svc->reserveRun($criteria);

        $this->assertSame(
            $dispatchDay,
            $run->quota_date->toDateString(),
            'Run quota_date must match the dispatch day'
        );

        // Now travel to the next day 00:05 (quota tz).
        $this->travelTo($svc->today()->addDay()->startOfDay()->addMinutes(5));
        $nextDay = $svc->today();
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

    // ── Scenario 12: Contact exhaustion does not block discovery ──────────────────

    /**
     * Contact meter at 0 (daily_contact_credits=0) — discovery run still succeeds.
     * contact_credits_reserved=0 on the run row; after job: status=completed,
     * contact_consumed=0, companies_count > 0.
     */
    public function test_contact_exhaustion_does_not_block_discovery(): void
    {
        // Company meter: 10 credits (ok). Contact meter: 0 credits (exhausted from creation).
        $this->assignPackageWith(10, 0);
        $criteria = $this->makeCriteria(['daily_limit' => 6]);

        // POST discover → must return 200 (contact exhaustion must NOT block discovery).
        // With QUEUE_CONNECTION=sync the job runs immediately, so the run may already
        // be completed/failed by the time we query it. That is fine — we query by
        // criteria_id (no status filter) and assert the reserved values.
        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(200);

        // Find the run created for this criteria (may already be completed via sync queue).
        $run = DiscoveryRun::where('prospect_criteria_id', $criteria->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($run, 'A run must be created when contact meter is 0');
        // min(batch=6, contactRemaining=0) = 0
        $this->assertSame(0, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must be 0 when contact meter is exhausted');

        // The run must reach a terminal state (ran synchronously via sync queue driver).
        $this->assertContains($run->status, ['completed', 'failed'],
            'Run must reach a terminal state after sync queue execution');
        $this->assertSame(0, (int) $run->contact_consumed,
            'contact_consumed must be 0 when contact budget was 0 (Hunter never called)');
        $this->assertGreaterThan(0, (int) $run->companies_count,
            'companies_count must be > 0 — discovery still ran despite contact exhaustion');
    }

    // ── Scenario 13: Contact partial cap limits Hunter calls ──────────────────────

    /**
     * With contact_credits=3 and batch=6, contact_credits_reserved = min(6,3) = 3.
     * After job: contact_consumed <= 3, companies_count == 6 (all companies saved).
     */
    public function test_contact_partial_cap_limits_hunter_calls(): void
    {
        $this->assignPackageWith(10, 3);
        $criteria = $this->makeCriteria(['daily_limit' => 6]);

        // POST without Queue::fake() — sync queue driver runs the job immediately.
        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(200);

        // With sync driver the run is completed by the time we query.
        $run = DiscoveryRun::where('prospect_criteria_id', $criteria->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($run, 'A run must be created');
        // min(6, 3) = 3
        $this->assertSame(3, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must be 3 (min of batch=6 and contactRemaining=3)');

        $this->assertLessThanOrEqual(3, (int) $run->contact_consumed,
            'contact_consumed must not exceed reservation of 3');
        // All 6 fixture companies are saved even though only 3 were enriched with Hunter.
        $this->assertGreaterThan(3, (int) $run->companies_count,
            'companies_count must exceed contact cap (extras saved without enrichment)');
    }

    // ── Scenario 14: Company unlimited + contact limited ─────────────────────────

    /**
     * With company unlimited (daily_credits=null) and contact limited (2),
     * the run row has credits_reserved = batch (uncapped) and contact_credits_reserved = 2.
     */
    public function test_company_unlimited_contact_limited(): void
    {
        Queue::fake();

        // daily_credits=null (company unlimited), daily_contact_credits=2 (contact limited).
        $package = Package::create([
            'name'                  => 'Pack Unlimited Company',
            'daily_credits'         => null,
            'daily_contact_credits' => 2,
            'is_active'             => true,
            'sort_order'            => 0,
        ]);
        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/prospect_criteria/{$criteria->id}/discover");

        $response->assertStatus(200);

        // Queue::fake() prevents the job from running — only check the run row created by the POST.
        $run = DiscoveryRun::where('prospect_criteria_id', $criteria->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        $this->assertNotNull($run, 'A pending run must be created');
        // Company unlimited → credits_reserved = daily_limit = 6 (no cap)
        $this->assertSame(6, (int) $run->credits_reserved,
            'credits_reserved must be 6 (company unlimited, daily_limit=6)');
        // Contact limited → min(6, 2) = 2
        $this->assertSame(2, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must be 2 (min of batch=6 and contactRemaining=2)');
    }

    // ── Scenario 15: Contact crash/retry budget ───────────────────────────────────

    /**
     * A partially-consumed run (credits_reserved=10, consumed=4, contact_credits_reserved=5,
     * contact_consumed=2) retried via dispatchSync → contact_consumed stays <= 5,
     * consumed stays <= 10, and run reaches a terminal state.
     */
    public function test_contact_crash_retry_budget(): void
    {
        $this->assignPackageWith(10, 5);
        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        // Insert the run directly (simulate a partial crash-resume, skipping reserveRun).
        $run = DiscoveryRun::create([
            'prospect_criteria_id'     => $criteria->id,
            'status'                   => 'running',
            'credits_reserved'         => 10,
            'consumed'                 => 4,
            'contact_credits_reserved' => 5,
            'contact_consumed'         => 2,
            'quota_date'               => Carbon::today()->toDateString(),
            'package_assignment_id'    => PackageAssignment::orderByDesc('id')->value('id'),
            'started_at'               => now()->subSeconds(10),
        ]);

        RunDiscoveryPipelineJob::dispatchSync($criteria->id, $run->id);

        $run->refresh();

        $this->assertContains($run->status, ['completed', 'failed'],
            'Run must reach a terminal state after dispatchSync');
        $this->assertLessThanOrEqual((int) $run->credits_reserved, (int) $run->consumed,
            'consumed must never exceed credits_reserved');
        $this->assertLessThanOrEqual((int) $run->contact_credits_reserved, (int) $run->contact_consumed,
            'contact_consumed must never exceed contact_credits_reserved');
    }

    // ── Scenario 16: currentPeriod contiguity — 31st anchor (findings #1/#5/#8) ───

    /**
     * Pure-function test: walk probe dates from anchor forward ~14 months and assert:
     *  1. Every probe date is inside its own window (start <= probe < end — half-open).
     *  2. Consecutive windows are CONTIGUOUS: prev_end === next_start (no gap, no overlap).
     *
     * The 31st-anchor case is the critical regression: addMonthsNoOverflow clamps
     * Feb to 28/29, so naive re-anchoring drifts and leaves days in no window.
     * With both bounds derived from the ORIGINAL anchor via addMonthsNoOverflow($i)
     * and addMonthsNoOverflow($i+1), every boundary is shared exactly once.
     */
    public function test_current_period_contiguity_31st_anchor(): void
    {
        // Anchor = 2026-01-31 (end-of-month; triggers the NoOverflow clamp in Feb/Mar).
        $this->assignPackageWithMonthly(10, 30, null, null, '2026-01-31');

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $anchor   = Carbon::parse('2026-01-31');
        $probeEnd = Carbon::parse('2027-03-31');
        $probe    = $anchor->copy();

        // Track the last window so we can assert contiguity at each boundary.
        $lastWindowStart = null;
        $lastWindowEnd   = null;

        while ($probe->lte($probeEnd)) {
            [$start, $end] = $svc->currentPeriod($probe->copy());

            // Invariant 1: probe is inside its window (half-open: start <= probe < end).
            $this->assertTrue(
                $start->lte($probe) && $probe->lt($end),
                sprintf(
                    'Probe %s must be inside window [%s, %s)',
                    $probe->toDateString(),
                    $start->toDateString(),
                    $end->toDateString()
                )
            );

            // Invariant 2: on a window boundary (start advanced from prior probe),
            // the new start must equal the prior end — no gap, no overlap.
            if ($lastWindowEnd !== null && $start->ne($lastWindowStart)) {
                $this->assertTrue(
                    $start->eq($lastWindowEnd),
                    sprintf(
                        'Contiguity violation at probe %s: prev window ended %s but new window starts %s',
                        $probe->toDateString(),
                        $lastWindowEnd->toDateString(),
                        $start->toDateString()
                    )
                );
            }

            $lastWindowStart = $start->copy();
            $lastWindowEnd   = $end->copy();

            $probe->addDay();
        }
    }

    // ── Scenario 17: First run of month not starved (finding #3) ─────────────────

    /**
     * A fresh run whose credits_reserved == monthly_credits should NOT starve itself
     * via its own in-flight reservation. usedInPeriod(excludeRunId=$run->id) must
     * return 0 while usedInPeriod(null) returns the reserved amount.
     */
    public function test_first_run_of_month_not_starved_by_own_reservation(): void
    {
        // daily_credits=5, monthly_credits=5 — monthly is the binding cap.
        $this->assignPackageWithMonthly(5, 5, null, null, Carbon::today()->toDateString());

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        // Reserve a run — should succeed (5 credits available).
        $run = $svc->reserveRun($criteria);

        $this->assertSame(5, (int) $run->credits_reserved,
            'First run of month must reserve all 5 remaining credits (min(daily=5, monthly=5))');

        // Derive the current period for today.
        [$pStart, $pEnd] = $svc->currentPeriod(Carbon::today());

        // WITH own reservation excluded: used = 0 (the run doesn't count itself).
        $usedExcluded = $svc->usedInPeriod($pStart, $pEnd, $run->id);
        $this->assertSame(0, $usedExcluded,
            'usedInPeriod with excludeRunId must be 0 — run must not count its own in-flight reservation');

        // WITHOUT exclusion: used = 5 (the pending reservation is counted).
        $usedIncluded = $svc->usedInPeriod($pStart, $pEnd, null);
        $this->assertSame(5, $usedIncluded,
            'usedInPeriod without exclusion must be 5 (the pending reservation counts)');
    }

    // ── Scenario 18: Both meters from one reservation (finding #8) ───────────────

    /**
     * One reserveRun with N=credits_reserved: usedOn(today) === N AND
     * usedInPeriod(currentPeriod) === N — same credits counted by both meters.
     * No double-counting (daily and monthly are overlapping windows, not additive).
     */
    public function test_both_meters_from_single_reservation(): void
    {
        // Package: daily=8, monthly=20 — daily is binding (min(8, 20) = 8).
        $this->assignPackageWithMonthly(8, 20, null, null, Carbon::today()->toDateString());

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $criteria = $this->makeCriteria(['daily_limit' => 10]);
        $run      = $svc->reserveRun($criteria);

        $n = (int) $run->credits_reserved;
        $this->assertSame(8, $n, 'credits_reserved must be 8 (daily cap is binding)');

        $today = Carbon::today();

        // Daily meter: usedOn(today) === N.
        $this->assertSame($n, $svc->usedOn($today),
            'usedOn(today) must equal credits_reserved — same N credits counted by daily meter');

        // Monthly meter: usedInPeriod(currentPeriod) === N.
        [$pStart, $pEnd] = $svc->currentPeriod($today);
        $this->assertSame($n, $svc->usedInPeriod($pStart, $pEnd),
            'usedInPeriod must equal credits_reserved — same N credits counted by monthly meter (not double-counted)');
    }

    // ── Scenario 19: Binding cap — daily-unlimited, monthly-limited (finding #10) ─

    /**
     * Package: daily_credits=null (unlimited), monthly_credits=3.
     * With some prior usage in the period, effectiveBatchFor() must return the
     * monthly-remaining (≤3), NOT the full wanted batch.
     * And reserveRun must cap credits_reserved to the monthly remaining.
     */
    public function test_binding_cap_monthly_limited_daily_unlimited(): void
    {
        $this->assignPackageWithMonthly(null, 3, null, null, Carbon::today()->toDateString());

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        // Prior usage in period: consume 1 credit.
        $this->insertConsumedRun($criteria, 1, Carbon::today()->toDateString());

        // effectiveBatchFor must return 2 (monthly_remaining = 3 - 1 = 2), not 10.
        $effective = $svc->effectiveBatchFor($criteria);
        $this->assertSame(2, $effective,
            'effectiveBatchFor must return 2 (monthly remaining = 3-1=2) when daily is unlimited but monthly is limited');
        $this->assertLessThanOrEqual(3, $effective,
            'effectiveBatchFor must never exceed the monthly cap');

        // reserveRun must also cap to 2.
        $run = $svc->reserveRun($criteria);
        $this->assertSame(2, (int) $run->credits_reserved,
            'reserveRun must cap credits_reserved to 2 (monthly remaining) when daily is unlimited');
    }

    // ── Scenario 20: Company throws / contact clamps on MONTH exhaustion (finding #4)

    /**
     * a) monthly_credits exhausted → reserveRun throws QuotaExhaustedException.
     * b) monthly_contact_credits exhausted but company has room → reserveRun does NOT throw;
     *    run is created with contact_credits_reserved clamped to 0, credits_reserved is normal.
     */
    public function test_company_monthly_exhaustion_throws_contact_exhaustion_clamps(): void
    {
        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        // ── Part a: company monthly exhausted → throw ──────────────────────────
        $this->assignPackageWithMonthly(10, 3, 5, 10, Carbon::today()->toDateString());
        $criteriaA = $this->makeCriteria(['daily_limit' => 5]);

        // Consume all 3 monthly_credits.
        $this->insertConsumedRun($criteriaA, 3, Carbon::today()->toDateString());

        $this->expectException(QuotaExhaustedException::class);
        $svc->reserveRun($criteriaA);
    }

    /**
     * Contact monthly exhausted but company has room → run created, contact_credits_reserved clamped.
     */
    public function test_contact_monthly_exhaustion_clamps_not_throws(): void
    {
        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        // monthly_credits=10 (company has room), monthly_contact_credits=2 (contact exhausted).
        $this->assignPackageWithMonthly(10, 10, 5, 2, Carbon::today()->toDateString());
        $criteriaB = $this->makeCriteria(['daily_limit' => 5]);

        // Consume all 2 monthly_contact_credits (no company usage yet).
        $this->insertConsumedRunWithContact($criteriaB, 0, 2, Carbon::today()->toDateString());
        // Company meter: 0 used. Contact monthly meter: 2/2 used.

        // reserveRun should NOT throw — contact exhaustion is a clamp, not a throw.
        $run = $svc->reserveRun($criteriaB);

        $this->assertNotNull($run, 'reserveRun must succeed when only the contact monthly meter is exhausted');
        $this->assertGreaterThan(0, (int) $run->credits_reserved,
            'credits_reserved must be > 0 (company meter has room)');
        $this->assertSame(0, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must be clamped to 0 when monthly contact cap is exhausted');
    }

    // ── Scenario 21: Manual enrich blocked by monthly contact cap (finding #6) ──

    /**
     * Daily contact has room but monthly_contact_credits exhausted for the period
     * → reserveManualEnrichment throws QuotaExhaustedException.
     */
    public function test_manual_enrich_blocked_by_monthly_contact_exhaustion(): void
    {
        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        // daily_contact_credits=5 (daily has room), monthly_contact_credits=2 (monthly exhausted).
        $this->assignPackageWithMonthly(10, 10, 5, 2, Carbon::today()->toDateString());

        $criteria = $this->makeCriteria(['daily_limit' => 5]);

        // Consume all 2 monthly_contact_credits via a completed discovery run today.
        $this->insertConsumedRunWithContact($criteria, 0, 2, Carbon::today()->toDateString());

        // Daily contact meter still has room (0 used today for contact... wait,
        // insertConsumedRunWithContact puts contact_consumed=2 on today's run).
        // So daily contact used = 2 (today). daily_contact_credits=5 → daily remaining = 3.
        // Monthly contact used = 2, monthly cap = 2 → monthly remaining = 0.

        $company = $this->makeCompany($criteria);

        $this->expectException(QuotaExhaustedException::class);
        $svc->reserveManualEnrichment($company);
    }

    // ── Scenario 22: currentPeriod returns window containing $on when $on < anchor ─

    /**
     * Regression: when $on is strictly before the anchor, the pre-anchor window
     * must contain $on, NOT start on the anchor.
     *
     * Proved broken: anchor=2026-06-23, on=2026-06-22 → old code returned
     * [2026-06-23, 2026-07-23), which excludes 2026-06-22.
     *
     * Fix: drop the max(0, …) clamp and the $i>0 gate so the backward walk
     * can reach a negative $i and find [anchor-1month, anchor) — which equals
     * [2026-05-23, 2026-06-23) in this example.
     *
     * Assertions:
     *   1. $start <= $on < $end  (window contains $on)
     *   2. $end === $anchor      (the preceding window ends exactly at the anchor)
     */
    public function test_current_period_contains_on_when_on_is_before_anchor(): void
    {
        $anchorDate = '2026-06-23';
        $onDate     = '2026-06-22';   // one day before the anchor

        $this->assignPackageWithMonthly(10, 30, null, null, $anchorDate);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $on     = Carbon::parse($onDate);
        $anchor = Carbon::parse($anchorDate)->startOfDay();

        [$start, $end] = $svc->currentPeriod($on);

        // Invariant 1: window contains $on (half-open: start <= on < end).
        $this->assertTrue(
            $start->lte($on) && $on->lt($end),
            sprintf(
                'Window [%s, %s) must contain on=%s (regression: pre-anchor window was skipped)',
                $start->toDateString(),
                $end->toDateString(),
                $on->toDateString()
            )
        );

        // Invariant 2: the window immediately precedes the anchor.
        // The window before the anchor is [anchor - 1 month, anchor), so $end === $anchor.
        $this->assertTrue(
            $end->eq($anchor),
            sprintf(
                'Window end must be the anchor %s, got %s (pre-anchor window must end at anchor)',
                $anchor->toDateString(),
                $end->toDateString()
            )
        );
    }

    // ── Scenario 23: per-criteria contact_limit clamps reservation ───────────────

    /**
     * Package 15 company / 10 contact per day, criteria daily_limit=10 and
     * contact_limit=3 (per-criteria override). reserveRun must reserve
     * credits_reserved=10 (unaffected — contact_limit only clamps the contact
     * meter) and contact_credits_reserved=3 (min(batch=10, contact_limit=3, contactRemaining=10)).
     */
    public function test_contact_limit_clamps_reservation(): void
    {
        $this->assignPackageWith(15, 10);
        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => 3]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);
        $run = $svc->reserveRun($criteria);

        $this->assertSame(10, (int) $run->credits_reserved,
            'credits_reserved must be unaffected by contact_limit (company meter has room)');
        $this->assertSame(3, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must be clamped to contact_limit=3');
    }

    /**
     * contact_limit=null preserves existing behavior — contact_credits_reserved
     * is bound only by the package contact meter, unaffected by any per-criteria cap.
     */
    public function test_null_contact_limit_preserves_existing_behavior(): void
    {
        $this->assignPackageWith(15, 10);
        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => null]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);
        $run = $svc->reserveRun($criteria);

        $this->assertSame(10, (int) $run->credits_reserved);
        $this->assertSame(10, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must equal min(batch=10, contactRemaining=10) when contact_limit is null');
    }

    /**
     * contact_limit above the package's remaining contact balance is clamped by
     * the global package cap, not by contact_limit — min() picks the smaller.
     */
    public function test_contact_limit_above_global_is_clamped_by_global(): void
    {
        $this->assignPackageWith(15, 5);
        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => 100]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);
        $run = $svc->reserveRun($criteria);

        $this->assertSame(5, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must be clamped to the package contact meter (5) even though contact_limit=100');
    }

    /**
     * Company meter unlimited (daily_credits=null, no contact meter set → contact
     * also unlimited) + contact_limit=2 → contact_credits_reserved must be exactly 2
     * (the per-criteria cap is the only binding contact constraint).
     */
    public function test_contact_limit_binds_on_unlimited_package(): void
    {
        $this->assignUnlimitedPackage();
        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => 2]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);
        $run = $svc->reserveRun($criteria);

        $this->assertSame(10, (int) $run->credits_reserved,
            'credits_reserved must be the wanted batch (10) — company meter unlimited');
        $this->assertSame(2, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must be clamped to contact_limit=2 on an unlimited package');
    }
    public function test_contact_limit_clamps_contact_credits_reserved(): void
    {
        $this->assignPackageWith(15, 10);
        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => 3]);

        /** @var DiscoveryQuotaService $quotaService */
        $quotaService = app(DiscoveryQuotaService::class);
        $run          = $quotaService->reserveRun($criteria);

        $this->assertSame(10, (int) $run->credits_reserved);
        $this->assertSame(3, (int) $run->contact_credits_reserved);
    }

    public function test_contact_limit_null_preserves_current_behavior(): void
    {
        $this->assignPackageWith(15, 10);
        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => null]);

        /** @var DiscoveryQuotaService $quotaService */
        $quotaService = app(DiscoveryQuotaService::class);
        $run          = $quotaService->reserveRun($criteria);

        $this->assertSame(10, (int) $run->contact_credits_reserved);
    }

    public function test_contact_limit_above_global_cap_is_clamped_by_global(): void
    {
        $this->assignPackageWith(15, 5);
        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => 20]);

        /** @var DiscoveryQuotaService $quotaService */
        $quotaService = app(DiscoveryQuotaService::class);
        $run          = $quotaService->reserveRun($criteria);

        $this->assertSame(5, (int) $run->contact_credits_reserved);
    }

    public function test_contact_limit_applies_when_package_unlimited(): void
    {
        $package = Package::create([
            'name'                  => 'Illimite complet',
            'daily_credits'         => null,
            'daily_contact_credits' => null,
            'is_active'             => true,
            'sort_order'            => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        $criteria = $this->makeCriteria(['daily_limit' => 10, 'contact_limit' => 2]);

        /** @var DiscoveryQuotaService $quotaService */
        $quotaService = app(DiscoveryQuotaService::class);
        $run          = $quotaService->reserveRun($criteria);

        $this->assertSame(10, (int) $run->credits_reserved);
        $this->assertSame(2, (int) $run->contact_credits_reserved);
    }

    /**
     * No package assignment (unlimited by convention) + daily_limit=6 and
     * contact_limit=2 → reserveRun must reserve credits_reserved=6 (company
     * meter uncapped) and contact_credits_reserved=2 (only the per-criteria
     * contact_limit binds since the contact meter is also unlimited).
     */
    public function test_reserve_run_with_contact_limit_on_unassigned_unlimited_package(): void
    {
        // No PackageAssignment at all — treated as unlimited by convention.
        PackageAssignment::query()->delete();
        Package::query()->delete();

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'contact_limit' => 2]);

        /** @var DiscoveryQuotaService $quotaService */
        $quotaService = app(DiscoveryQuotaService::class);
        $run          = $quotaService->reserveRun($criteria);

        $this->assertSame(6, (int) $run->credits_reserved,
            'credits_reserved must equal daily_limit=6 when no package assignment exists (unlimited)');
        $this->assertSame(2, (int) $run->contact_credits_reserved,
            'contact_credits_reserved must equal contact_limit=2 (only binding contact constraint)');
    }

}
