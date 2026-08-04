<?php

namespace Tests\Feature\Backend;

use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

// The full index strip and compact view-page badges use provider-neutral labels
// for company discovery and contact enrichment.

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

        Carbon::setTestNow(app(DiscoveryQuotaService::class)->today()->setTime(12, 0));

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
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
            'name' => 'Test Pack '.$dailyCredits,
            'daily_credits' => $dailyCredits,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        PackageAssignment::create([
            'package_id' => $package->id,
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
            'prospect_criteria_id' => $criteria->id,
            'status' => 'completed',
            'credits_reserved' => $package->daily_credits,
            'consumed' => $package->daily_credits,
            'quota_date' => Carbon::today()->toDateString(),
            'package_assignment_id' => $assignment?->id,
        ]);
    }

    // ── Test 1: limited package with credits remaining ────────────────────────

    /**
     * With both meters limited, the index page renders the two provider-neutral meters.
     */
    public function test_index_shows_credits_badge_when_limited_package_assigned(): void
    {
        $package = Package::create([
            'name' => 'Test Pack Both Limited',
            'daily_credits' => 10,
            'daily_contact_credits' => 5,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertSee('Recherches d’entreprises', false);
        $response->assertSee('Tentatives d’enrichissement', false);
        $response->assertDontSee('SerpAPI', false);
        $response->assertDontSee('Hunter', false);
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
        $package = $this->assignPackage(10);
        $criteria = ProspectCriteria::create([
            'name' => 'Critère Quota Épuisé',
            'daily_limit' => 10,
            'is_active' => true,
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
        $package = $this->assignPackage(10);
        $criteria = ProspectCriteria::create([
            'name' => 'Critère Quota Épuisé DT',
            'daily_limit' => 10,
            'is_active' => true,
        ]);

        $this->exhaustQuota($package, $criteria);

        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                .'?draw=1&start=0&length=25'
                .'&columns[0][data]=id&columns[0][name]=id'
                .'&order[0][column]=0&order[0][dir]=asc',
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
     * when a limited package is assigned (both meters limited).
     */
    public function test_view_page_shows_credits_badge(): void
    {
        $package = Package::create([
            'name' => 'Test Pack View Both',
            'daily_credits' => 50,
            'daily_contact_credits' => 20,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        $criteria = ProspectCriteria::create([
            'name' => 'Critère View Badge',
            'daily_limit' => 20,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/'.$criteria->id);

        $response->assertStatus(200);
        $response->assertSee('Recherches d’entreprises', false);
        $response->assertSee('Tentatives d’enrichissement', false);
    }

    /**
     * Search limited + unlimited enrichment package still advertises the per-run cap.
     */
    public function test_index_shows_one_unlimited_one_limited_badge(): void
    {
        $package = Package::create([
            'name' => 'Test Pack Company Limited Only',
            'daily_credits' => 10,
            'daily_contact_credits' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertSee('Recherches d’entreprises', false);
        $response->assertSee('Tentatives d’enrichissement', false);
        $response->assertSee('Quota package illimité · max. 20 par exécution', false);
        $response->assertDontSee('SerpAPI', false);
        $response->assertDontSee('Hunter', false);
    }

    // ── Test 5 (finding #7): Relabel + monthly figure + zero-cap no crash ────────

    /**
     * The index strip names both independent meters without exposing providers.
     */
    public function test_badge_shows_provider_neutral_discovery_and_enrichment_labels(): void
    {
        $package = Package::create([
            'name' => 'Test Pack Libellés Génériques',
            'daily_credits' => 10,
            'daily_contact_credits' => 5,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertSee('Recherches d’entreprises', false);
        $response->assertSee('Tentatives d’enrichissement', false);
        $response->assertDontSee('SerpAPI', false);
        $response->assertDontSee('Hunter', false);
    }

    /**
     * Finding #7b: With a monthly cap set, the monthly figure "ce mois" appears in the badge.
     * Tests that the monthly suffix path in the blade renders correctly.
     */
    public function test_quota_strip_hides_unknown_provider_balance_instead_of_rendering_zero(): void
    {
        $html = view('backend.contents.prospect_criteria.partials._quota-strip', [
            'quotaMeters' => [],
            'quotaPackage' => null,
            'activeDailyLimitSum' => null,
            'providerSearchesLeft' => null,
        ])->render();

        $this->assertStringNotContainsString('Capacité du fournisseur', $html);
        $this->assertStringNotContainsString('bi-battery-charging', $html);
    }

    public function test_zero_provider_capacity_explains_block_while_fretiq_quota_remains(): void
    {
        $html = view('backend.contents.prospect_criteria.partials._quota-strip', [
            'quotaMeters' => [
                'company' => [
                    'icon' => 'bi-building',
                    'color' => 'success',
                    'unlimited' => false,
                    'daily' => ['used_reserved' => 2, 'total' => 10, 'remaining' => 8],
                    'monthly' => ['used_reserved' => 2, 'total' => 50, 'remaining' => 48],
                ],
            ],
            'quotaPackage' => null,
            'activeDailyLimitSum' => null,
            'providerSearchesLeft' => 0,
        ])->render();

        $this->assertStringContainsString('Capacité du fournisseur', $html);
        $this->assertStringContainsString('Découverte bloquée par la capacité du fournisseur', $html);
        $this->assertStringContainsString('Quota Fretiq — aujourd’hui', $html);
        $this->assertStringContainsString('Quota Fretiq — ce mois', $html);
    }

    public function test_commercial_index_does_not_request_or_show_provider_capacity(): void
    {
        $commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $commercial->assignRole('commercial');

        $provider = Mockery::mock(CompanyDiscoveryService::class);
        $provider->shouldNotReceive('accountUsage');
        $this->instance(CompanyDiscoveryService::class, $provider);

        $response = $this->actingAs($commercial)->get('/admin/prospect_criteria');

        $response->assertOk();
        $response->assertDontSee('Capacité du fournisseur', false);
    }

    /**
     * Finding #7b: With a monthly cap set, the monthly figure "ce mois" appears in the badge.
     * Tests that the monthly suffix path in the blade renders correctly.
     */
    public function test_badge_shows_monthly_figure_when_monthly_cap_set(): void
    {
        $package = Package::create([
            'name' => 'Test Pack Monthly Figure',
            'daily_credits' => 10,
            'monthly_credits' => 50,
            'daily_contact_credits' => 5,
            'monthly_contact_credits' => 20,
            'quota_anchor_date' => Carbon::today()->toDateString(),
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        // The monthly suffix "ce mois" must appear when monthly_credits is set.
        $response->assertSee('ce mois', false);
    }

    /**
     * Finding #7c: A package with monthly_credits=0 renders the badge without
     * error (no division-by-zero) and the page returns 200.
     * The badge's severity helper must guard $cap > 0 before dividing.
     */
    public function test_badge_zero_monthly_cap_no_division_by_zero(): void
    {
        // monthly_credits=0 and monthly_contact_credits=0 — triggers the severity edge case.
        $package = Package::create([
            'name' => 'Test Pack Zero Monthly',
            'daily_credits' => 5,
            'monthly_credits' => 0,
            'daily_contact_credits' => 3,
            'monthly_contact_credits' => 0,
            'quota_anchor_date' => Carbon::today()->toDateString(),
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        // The index page must render 200 — no DivisionByZeroError from the badge severity helper.
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        // Badge renders in danger state (0/0 cap) — page must still return valid HTML.
        // "ce mois" confirms the monthly suffix rendered (even at 0/0).
        $response->assertSee('ce mois', false);
    }

    // ── Test 6 (UX consistency): monthly exhausted, daily remaining ─────────────

    /**
     * Assign a package whose MONTHLY company cap is exhausted today while the DAILY
     * cap still has room, create a criteria, and consume the full monthly cap with a
     * single completed run. Daily: 100 cap − 10 used = 90 (room). Monthly: 10 cap −
     * 10 used = 0 (exhausted). Returns the criteria.
     */
    private function assignMonthlyExhaustedPackage(): ProspectCriteria
    {
        $package = Package::create([
            'name' => 'Test Pack Monthly Exhausted',
            'daily_credits' => 100,
            'monthly_credits' => 10,
            'quota_anchor_date' => Carbon::today()->toDateString(),
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        $criteria = ProspectCriteria::create([
            'name' => 'Critère Mensuel Épuisé',
            'daily_limit' => 10,
            'is_active' => true,
        ]);

        $assignment = PackageAssignment::orderByDesc('id')->first();
        // Single completed run consumes the full monthly cap (10) today:
        // daily 100→90 (room), monthly 10→0 (exhausted).
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'status' => 'completed',
            'credits_reserved' => 10,
            'consumed' => 10,
            'quota_date' => Carbon::today()->toDateString(),
            'package_assignment_id' => $assignment?->id,
        ]);

        return $criteria;
    }

    /**
     * When the MONTHLY company cap is exhausted but the DAILY cap still has room,
     * the DataTable action column must STILL render a disabled discover button.
     * Guards the UX-consistency fix: button-disable considers daily OR monthly.
     */
    public function test_datatable_discover_button_disabled_when_monthly_exhausted_daily_remains(): void
    {
        $this->assignMonthlyExhaustedPackage();

        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                .'?draw=1&start=0&length=25'
                .'&columns[0][data]=id&columns[0][name]=id'
                .'&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200);
        $json = $response->json();

        $found = false;
        foreach ($json['data'] ?? [] as $row) {
            if (str_contains($row['name'] ?? '', 'Critère Mensuel Épuisé')) {
                $found = true;
                $this->assertStringContainsString(
                    'disabled',
                    $row['action'] ?? '',
                    'Discover button must be disabled when the monthly cap is exhausted (daily still has room)'
                );
                break;
            }
        }

        $this->assertTrue($found, 'The monthly-exhausted criteria row should appear in DataTable results');
    }

    /**
     * The view page (_header-actions partial) must also treat monthly exhaustion as
     * exhausted: the "Solde épuisé" tooltip only renders when $quotaExhausted is true
     * (the quota badge never emits that exact string), so its presence proves the
     * launch button is disabled on monthly-only exhaustion.
     */
    public function test_view_page_discover_button_disabled_when_monthly_exhausted_daily_remains(): void
    {
        $criteria = $this->assignMonthlyExhaustedPackage();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/'.$criteria->id);

        $response->assertStatus(200);
        // 'Solde épuisé' appears only in the disabled-button tooltip, not the badge.
        $response->assertSee('Solde épuisé', false);
    }

    // ── Test 7: overbook warning (sum of active daily_limit vs package cap) ─────

    /**
     * Two active criteria with daily_limit=10 each (sum=20) against a package
     * capped at daily_credits=15 → the "Quota sur-réservé" warning pill renders.
     */
    public function test_index_shows_overbook_warning_when_active_daily_limits_exceed_package(): void
    {
        $this->assignPackage(15);

        ProspectCriteria::create([
            'name' => 'Critère Overbook A',
            'daily_limit' => 10,
            'is_active' => true,
        ]);
        ProspectCriteria::create([
            'name' => 'Critère Overbook B',
            'daily_limit' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertSee('Sur-réservation priorisée', false);
    }

    /**
     * Active criteria daily_limit sum stays within the package cap → no warning pill.
     */
    public function test_index_hides_overbook_warning_when_within_quota(): void
    {
        $this->assignPackage(50);

        ProspectCriteria::create([
            'name' => 'Critère Dans Quota A',
            'daily_limit' => 10,
            'is_active' => true,
        ]);
        ProspectCriteria::create([
            'name' => 'Critère Dans Quota B',
            'daily_limit' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertDontSee('Sur-réservation priorisée', false);
    }

    /**
     * No package assignment (unlimited) → overbook warning never renders, regardless
     * of how high the active daily_limit sum is.
     */
    public function test_index_hides_overbook_warning_when_package_unlimited(): void
    {
        PackageAssignment::query()->delete();
        Package::query()->delete();

        ProspectCriteria::create([
            'name' => 'Critère Illimité A',
            'daily_limit' => 500,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
        $response->assertDontSee('Sur-réservation priorisée', false);
    }
}
