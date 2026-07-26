<?php

// >>> custom-test-author:dashboard-code

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
use Tests\TestCase;

/**
 * DashboardGeneratedTest — HTTP-level tests for GET /admin/dashboard.
 *
 * Covers the gaps left by DashboardRenderTest (which only does guest-redirect +
 * superadmin-200-on-empty-db) and AnalyticsServiceTest (service-only, no HTTP):
 *
 *   1. View contract — assertViewIs + assertViewHas for all four keys the
 *      controller passes (kpis, funnel, engagementOverTime, topCampaigns).
 *   2. KPI sub-keys — kpis array contains the nine expected scalar keys.
 *   3. Commercial role — has backend.access and must see 200.
 *   4. User without backend.access — must see 403 (permission middleware gate).
 *   5. Smoke with seeded data — HTTP 200 with real campaign + run rows in DB.
 *
 * The permission gate is `permission:backend.access` (constructor middleware).
 * There is no separate per-dashboard permission beyond backend.access.
 *
 * Route: admin.dashboard — GET /admin/dashboard
 * Controller: App\Http\Controllers\Backend\ProspectionDashboardController@index
 */
class DashboardGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    /** @var string Dashboard URI — matches the route registration in routes/Backend/backend.php */
    private const DASHBOARD_URI = '/admin/dashboard';

    /** @var string View name returned by the controller */
    private const DASHBOARD_VIEW = 'backend.contents.dashboard.index';

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

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Create a minimal but structurally valid Campaign + one CampaignRun.
     * Used to confirm the analytics queries do not throw on real rows.
     */
    private function seedCampaignWithRun(array $runAttributes = []): Campaign
    {
        $segment  = Segment::create(['name' => 'Seg ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl ' . uniqid(),
            'subject'      => 'Sujet test',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Test',
            'email' => 'noreply_' . uniqid() . '@tcl.test',
        ]);

        $campaign = Campaign::create([
            'name'               => 'Campagne ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);

        CampaignRun::create(array_merge([
            'campaign_id'      => $campaign->id,
            'occurrence_key'   => 'one_shot_' . now()->format('Y-m-d'),
            'run_at'           => now(),
            'status'           => 'sent',
            'stats_sent'       => 0,
            'stats_opened'     => 0,
            'conversion_count' => 0,
        ], $runAttributes));

        return $campaign;
    }

    // ── View contract ──────────────────────────────────────────────────────────

    /**
     * The controller must render the dashboard view (not a redirect, not a generic
     * layout without the correct template name).
     */
    public function test_dashboard_renders_correct_view(): void
    {
        $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI)
            ->assertStatus(200)
            ->assertViewIs(self::DASHBOARD_VIEW);
    }

    /**
     * All four data keys the controller passes to the view must be present.
     *
     * Controller code (ProspectionDashboardController@index):
     *   return view('backend.contents.dashboard.index', [
     *       'kpis'              => $analytics->dashboardKpis(),
     *       'funnel'            => $analytics->funnel(),
     *       'engagementOverTime'=> $analytics->engagementOverTime(),
     *       'topCampaigns'      => $analytics->topCampaigns(),
     *   ]);
     */
    public function test_dashboard_view_receives_all_data_keys(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI);

        $response->assertStatus(200);
        $response->assertViewHas('kpis');
        $response->assertViewHas('funnel');
        $response->assertViewHas('engagementOverTime');
        $response->assertViewHas('topCampaigns');
    }

    /**
     * The kpis array must contain the nine documented scalar keys (same keys
     * verified at service level by AnalyticsServiceTest, but now confirmed to
     * flow through the HTTP stack intact).
     */
    public function test_dashboard_kpis_view_data_has_expected_keys(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI);

        $response->assertStatus(200);

        $kpis = $response->viewData('kpis');

        $expectedKeys = [
            'companies',
            'contacts',
            'active_campaigns',
            'emails_sent_30d',
            'open_rate',
            'click_rate',
            'demandes',
            'demandes_30d',
            'conversion_rate',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $kpis,
                "kpis view data must contain key '{$key}'");
        }
    }

    /**
     * The funnel view data must contain the six canonical stage keys in order.
     * (HTTP-level counterpart to AnalyticsServiceTest::test_funnel_returns_ordered_stages)
     */
    public function test_dashboard_funnel_view_data_has_expected_stages(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI);

        $response->assertStatus(200);

        $funnel = $response->viewData('funnel');

        $expectedStages = [
            'Découvertes',
            'Contactées',
            'Ouvertures',
            'Clics',
            'Réponses',
            'Demandes',
        ];

        $this->assertSame($expectedStages, array_keys($funnel),
            'funnel view data must contain exactly the six canonical stages in order');
    }

    /**
     * The engagementOverTime view data must contain the 'labels' and 'series' keys,
     * and series must contain 'opens', 'clicks', 'replies'.
     */
    public function test_dashboard_engagement_over_time_shape(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI);

        $response->assertStatus(200);

        $engagement = $response->viewData('engagementOverTime');

        $this->assertArrayHasKey('labels', $engagement,
            'engagementOverTime must contain a labels key');
        $this->assertArrayHasKey('series', $engagement,
            'engagementOverTime must contain a series key');
        $this->assertArrayHasKey('opens', $engagement['series'],
            'engagementOverTime.series must contain opens');
        $this->assertArrayHasKey('clicks', $engagement['series'],
            'engagementOverTime.series must contain clicks');
        $this->assertArrayHasKey('replies', $engagement['series'],
            'engagementOverTime.series must contain replies');

        // labels and series arrays must be the same length (default 8 weeks)
        $this->assertCount(
            count($engagement['labels']),
            $engagement['series']['opens'],
            'series.opens length must equal labels length'
        );
    }

    // ── Role-based access ──────────────────────────────────────────────────────

    /**
     * A user with the commercial role has backend.access and must see 200.
     *
     * Spec requirement: "GET /admin/dashboard returns 200 for commercial role"
     */
    public function test_commercial_role_can_view_dashboard(): void
    {
        $commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $commercial->assignRole('commercial');

        $this->actingAs($commercial)
            ->get(self::DASHBOARD_URI)
            ->assertStatus(200);
    }

    /**
     * A user who is authenticated but lacks backend.access must be rejected
     * with 403 (the permission middleware returns Forbidden, not a redirect).
     *
     * This confirms the gate is enforced at the controller middleware level,
     * not just in the route group (backend.php registers the dashboard outside
     * the route group but the controller constructor adds the middleware directly).
     */
    public function test_user_without_backend_access_gets_403(): void
    {
        // Create a user with NO role and NO backend.access permission.
        $bareUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);

        $this->actingAs($bareUser)
            ->get(self::DASHBOARD_URI)
            ->assertStatus(403);
    }

    // ── Smoke with real data ───────────────────────────────────────────────────

    /**
     * Dashboard renders 200 when the database contains at least one Company,
     * Contact, Campaign, and CampaignRun.  Smoke-tests that no analytics query
     * throws an exception or division-by-zero on real non-empty data.
     *
     * Deliberately keeps the seeded volume small (1 campaign, 1 run, 10 sent,
     * 4 opens, 1 click) — just enough for both the 30-day window aggregation and
     * the topCampaigns join to exercise non-trivial code paths.
     */
    public function test_dashboard_renders_200_with_seeded_data(): void
    {
        $company = Company::create([
            'name'                 => 'ACME Test ' . uniqid(),
            'relationship'         => 'prospect',
            'source'               => 'discovered',
            'qualification_status' => 'pending',
        ]);

        Contact::create([
            'company_id'  => $company->id,
            'email'       => 'contact_' . uniqid() . '@acme.test',
            'name'        => 'Marie Dupont',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        $this->seedCampaignWithRun([
            'run_at'           => now(),
            'stats_sent'       => 10,
            'stats_opened'     => 4,
            'stats_clicked'    => 1,
            'conversion_count' => 1,
        ]);

        $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI)
            ->assertStatus(200)
            ->assertViewIs(self::DASHBOARD_VIEW);
    }

    /**
     * Dashboard kpis reflect the seeded data: companies >= 1, emails_sent_30d >= 10.
     * Confirms the analytics values flow from the DB through the controller to the
     * view without being dropped or zeroed.
     */
    public function test_dashboard_kpis_reflect_seeded_data(): void
    {
        Company::create([
            'name'                 => 'BetaCorp ' . uniqid(),
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'qualified',
        ]);

        $this->seedCampaignWithRun([
            'run_at'       => now(),
            'stats_sent'   => 15,
            'stats_opened' => 6,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI);

        $response->assertStatus(200);

        $kpis = $response->viewData('kpis');

        $this->assertGreaterThanOrEqual(1, $kpis['companies'],
            'kpis.companies must reflect the seeded Company');
        $this->assertGreaterThanOrEqual(15, $kpis['emails_sent_30d'],
            'kpis.emails_sent_30d must reflect the seeded CampaignRun stats_sent=15');
        $this->assertEqualsWithDelta(40.0, (float) $kpis['open_rate'], 0.01,
            'kpis.open_rate must be 40.0 (6 opens / 15 sent × 100)');
    }
    public function test_dashboard_renders_each_whitelisted_prototype(): void
    {
        foreach ([1, 2, 3, 4] as $prototype) {
            $this->actingAs($this->superadmin)
                ->get(self::DASHBOARD_URI.'?prototype='.$prototype)
                ->assertOk()
                ->assertViewHas('prototype', $prototype)
                ->assertSee('data-prototype="'.$prototype.'"', false);
        }
    }

    public function test_invalid_prototype_falls_back_to_one(): void
    {
        $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI.'?prototype=99')
            ->assertOk()
            ->assertViewHas('prototype', 1)
            ->assertSee('data-prototype="1"', false);
    }

    public function test_dashboard_view_receives_operational_payload(): void
    {
        $response = $this->actingAs($this->superadmin)->get(self::DASHBOARD_URI);

        foreach (['campaigns', 'planning', 'criteria', 'enterprises'] as $key) {
            $response->assertViewHas($key);
            $this->assertIsArray($response->viewData($key));
        }

        $this->assertArrayHasKey('rows', $response->viewData('campaigns'));
        $this->assertArrayHasKey('upcoming', $response->viewData('planning'));
        $this->assertArrayHasKey('rows', $response->viewData('criteria'));
        $this->assertArrayHasKey('recent', $response->viewData('enterprises'));
    }
    public function test_planning_counts_are_not_capped_by_display_limit(): void
    {
        foreach (range(1, 6) as $index) {
            $campaign = $this->seedCampaignWithRun();
            $campaign->update([
                'is_active' => true,
                'schedule_type' => 'one_shot',
                'scheduled_at' => now()->subDays($index),
            ]);
        }

        $planning = $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI.'?prototype=2')
            ->viewData('planning');

        $this->assertSame(6, $planning['overdue_count']);
        $this->assertCount(5, $planning['overdue']);
    }

    public function test_backend_only_user_does_not_receive_protected_detail_rows(): void
    {
        Company::create([
            'name' => 'Entreprise protégée',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        \App\Models\ProspectCriteria::create(['name' => 'Critère protégé']);
        $this->seedCampaignWithRun();

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)->get(self::DASHBOARD_URI.'?prototype=3');

        $response->assertOk();
        $this->assertSame([], $response->viewData('campaigns')['rows']);
        $this->assertSame([], $response->viewData('planning')['upcoming']);
        $this->assertSame([], $response->viewData('planning')['overdue']);
        $this->assertSame([], $response->viewData('criteria')['rows']);
        $this->assertSame([], $response->viewData('enterprises')['recent']);
    }
}

// <<<
