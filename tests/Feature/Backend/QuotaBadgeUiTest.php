<?php

namespace Tests\Feature\Backend;

use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * QuotaBadgeUiTest — HTTP render tests for the client-facing quota badge.
 *
 * Verifies three states on the prospect_criteria index page:
 *   1. Limited package assigned with credits remaining  → "Crédits du jour" badge visible
 *   2. Limited package assigned, solde exhausted (0)    → page renders 200, button disabled
 *   3. No package assignment                            → renders 200 with "Illimité"
 *
 * Uses RefreshDatabase (SQLite or MySQL) — quota tables must exist in the test DB.
 * All three tests share the same superadmin user seeded in setUp().
 */
class QuotaBadgeUiTest extends TestCase
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
     * Create a Package with daily_credits and assign it as the active package.
     */
    private function assignPackage(int $dailyCredits): Package
    {
        $package = Package::create([
            'name'          => 'Test Pack ' . $dailyCredits,
            'daily_credits' => $dailyCredits,
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
     * Consume all daily credits for today by inserting a completed discovery_run
     * row with consumed = daily_credits and quota_date = today.
     */
    private function exhaustQuota(Package $package, ProspectCriteria $criteria): void
    {
        $assignment = PackageAssignment::orderByDesc('id')->first();

        DiscoveryRun::create([
            'prospect_criteria_id'  => $criteria->id,
            'status'                => 'completed',
            'credits_reserved'      => $package->daily_credits,
            'consumed'              => $package->daily_credits,
            'quota_date'            => Carbon::today()->toDateString(),
            'package_assignment_id' => $assignment?->id,
        ]);
    }

    // ── Test 1: limited package with credits remaining ────────────────────────

    /**
     * With a limited package assigned (10 credits/day) and none consumed,
     * the index page renders 200 and shows the "Crédits du jour" badge.
     */
    public function test_index_shows_credits_badge_when_limited_package_assigned(): void
    {
        $this->assignPackage(10);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertSee('Crédits du jour', false);
    }

    // ── Test 2: solde exhausted — button disabled ─────────────────────────────

    /**
     * With a limited package (10 credits/day) and all credits consumed today,
     * the index page renders 200 and the discovery button markup is disabled.
     *
     * We check that the DataTable action column renders a disabled button —
     * which requires loading the DataTable AJAX endpoint directly.
     */
    public function test_index_renders_200_when_quota_exhausted(): void
    {
        $package  = $this->assignPackage(10);
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Quota Épuisé',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $this->exhaustQuota($package, $criteria);

        // The index page itself must render 200 even at 0 remaining.
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
    }

    /**
     * When quota is exhausted, the DataTable action column renders a disabled
     * discover button (contains 'disabled' in its markup).
     */
    public function test_datatable_discover_button_disabled_when_quota_exhausted(): void
    {
        $package  = $this->assignPackage(10);
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Quota Épuisé DT',
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $this->exhaustQuota($package, $criteria);

        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                . '?draw=1&start=0&length=25'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);
        $json = $response->json();

        // Find the row for the exhausted-quota criteria and assert button is disabled.
        $found = false;
        foreach ($json['data'] ?? [] as $row) {
            if (str_contains($row['name'] ?? '', 'Critère Quota Épuisé DT')) {
                $found = true;
                // The action column HTML should contain 'disabled' on the discover button.
                $this->assertStringContainsString(
                    'disabled',
                    $row['action'] ?? '',
                    'Discover button must be disabled when quota is exhausted'
                );
                // Also verify the tooltip text for exhausted state.
                $this->assertStringContainsString(
                    'recharge demain',
                    $row['action'] ?? '',
                    'Discover button tooltip must mention recharge demain'
                );
                break;
            }
        }

        $this->assertTrue($found, 'The exhausted-quota criteria row should appear in DataTable results');
    }

    // ── Test 3: no assignment → unlimited ────────────────────────────────────

    /**
     * With no package assignment at all (fresh install state),
     * the index page renders 200 and shows the "Illimité" badge.
     */
    public function test_index_shows_illimite_when_no_package_assigned(): void
    {
        // Ensure no assignments exist (RefreshDatabase handles this, but be explicit).
        PackageAssignment::query()->delete();
        Package::query()->delete();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertSee('Illimité', false);
    }

    // ── Test 4: view page renders with quota badge ────────────────────────────

    /**
     * The view page for a criteria also renders 200 with the quota badge
     * when a limited package is assigned.
     */
    public function test_view_page_shows_credits_badge(): void
    {
        $this->assignPackage(50);

        $criteria = ProspectCriteria::create([
            'name'        => 'Critère View Badge',
            'daily_limit' => 20,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/' . $criteria->id);

        $response->assertStatus(200);
        $response->assertSee('Crédits du jour', false);
    }
}
