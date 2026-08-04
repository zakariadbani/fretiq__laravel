<?php

// >>> custom-test-author:dashboard-code

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignRecipient;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\DiscoveryRun;
use App\Models\InboxEmail;
use App\Models\ProspectCriteria;
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

    private function seedProtectedDashboardData(): void
    {
        $company = Company::create([
            'name' => 'Entreprise dashboard '.uniqid(),
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'qualified',
        ]);
        $contact = Contact::create([
            'company_id' => $company->id,
            'email' => 'dashboard_'.uniqid().'@example.test',
            'name' => 'Contact dashboard',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
        $campaign = $this->seedCampaignWithRun([
            'stats_sent' => 10,
            'stats_opened' => 4,
            'stats_clicked' => 2,
            'conversion_count' => 1,
        ]);
        $campaign->update([
            'is_active' => true,
            'scheduled_at' => now()->addDay(),
        ]);
        CampaignRecipient::create([
            'campaign_run_id' => $campaign->runs()->firstOrFail()->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'opened_at' => now(),
            'clicked_at' => now(),
            'replied_at' => now(),
        ]);
        ProspectCriteria::create([
            'name' => 'Critere dashboard '.uniqid(),
            'is_active' => true,
            'auto_run' => true,
        ]);
        Demande::create([
            'kind' => 'reply',
            'status' => 'pending',
            'captured_at' => now(),
        ]);
    }

    private function dashboardUserWithout(string $permission): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $permissions = [
            'backend.access',
            'view campaigns',
            'view prospect_criteria',
            'view companies',
            'view contacts',
            'view demandes',
            'view inbox',
        ];
        $user->givePermissionTo(array_values(array_diff($permissions, [$permission])));

        return $user;
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

    public function test_dashboard_renders_each_section_once_without_prototype_selector(): void
    {
        $this->seedProtectedDashboardData();
        $sections = [
            'dashboard-shell',
            'dashboard-operational-priorities',
            'dashboard-executive-kpis',
            'dashboard-engagement-chart',
            'dashboard-funnel-chart',
            'dashboard-planning-overdue',
            'dashboard-planning-upcoming',
            'dashboard-campaign-health',
            'dashboard-top-campaigns',
            'dashboard-discovery-summary',
            'dashboard-criteria-yield',
            'dashboard-enterprise-qualification-contacts',
            'dashboard-recent-enterprises',
            'dashboard-send-incidents',
            'dashboard-inbox',
        ];

        $response = $this->actingAs($this->superadmin)->get(self::DASHBOARD_URI)->assertOk();
        $legacy = $this->actingAs($this->superadmin)->get(self::DASHBOARD_URI.'?prototype=4')->assertOk();

        foreach ([$response, $legacy] as $page) {
            $page->assertViewMissing('prototype')
                ->assertSee('id="dashboard-engagement-chart-canvas"', false)
                ->assertSee('id="dashboard-funnel-chart-canvas"', false)
                ->assertSee('data-testid="dashboard-engagement-fallback"', false)
                ->assertSee('data-testid="dashboard-funnel-fallback"', false)
                ->assertSee('data-testid="dashboard-engagement-summary"', false)
                ->assertSee('data-testid="dashboard-funnel-summary"', false)
                ->assertSee('Campagnes totales')
                ->assertSee('<th>Type</th>', false)
                ->assertSee('<th>Mode</th>', false)
                ->assertSee('Dernière exécution')
                ->assertSee('Couverture contacts')
                ->assertDontSee('dashboard-prototype-switcher')
                ->assertDontSee('data-prototype=', false)
                ->assertDontSee('data-testid="dashboard-engagement-empty"', false)
                ->assertDontSee('data-testid="dashboard-funnel-empty"', false);
            $this->assertSame(2, substr_count($page->getContent(), 'role="img"'));
            foreach ($sections as $section) {
                $this->assertSame(1, substr_count($page->getContent(), 'data-testid="'.$section.'"'), $section.' must render exactly once');
            }
        }
    }

    public function test_dashboard_reports_reply_and_send_work_queues(): void
    {
        InboxEmail::create([
            'message_id' => 'dashboard-reply@example.test',
            'from_email' => 'prospect@example.test',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'received_at' => now(),
        ]);
        InboxEmail::create([
            'message_id' => 'dashboard-processed-reply@example.test',
            'from_email' => 'processed@example.test',
            'status' => InboxEmail::STATUS_NOUVEAU,
            'processed_at' => now(),
            'received_at' => now()->subMinute(),
        ]);

        $this->seedCampaignWithRun(['status' => 'failed']);
        $this->seedCampaignWithRun([
            'status' => 'sending',
            'started_at' => now()->subHour(),
        ]);

        $operations = $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI)
            ->viewData('operations');

        $this->assertSame(1, $operations['replies_awaiting_triage']);
        $this->assertSame(1, $operations['failed_sends']);
        $this->assertSame(1, $operations['stale_sends']);
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
            ->get(self::DASHBOARD_URI)
            ->viewData('planning');

        $this->assertSame(6, $planning['overdue_count']);
        $this->assertCount(5, $planning['overdue']);
    }

    public function test_mixed_scope_user_receives_demandes_without_protected_campaign_data(): void
    {
        Company::create([
            'name' => 'Entreprise protégée',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        \App\Models\ProspectCriteria::create(['name' => 'Critère protégé']);
        $campaign = $this->seedCampaignWithRun([
            'stats_sent' => 100,
            'stats_opened' => 50,
            'stats_clicked' => 20,
        ]);
        $campaign->update(['is_active' => true]);
        \App\Models\Demande::create([
            'kind' => 'reply',
            'status' => 'pending',
            'captured_at' => now(),
        ]);

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(['backend.access', 'view demandes']);

        $response = $this->actingAs($user)->get(self::DASHBOARD_URI);

        $response->assertOk();
        $campaigns = $response->viewData('campaigns');
        $kpis = $response->viewData('kpis');
        $this->assertSame(0, $campaigns['total']);
        $this->assertSame(0, $campaigns['active']);
        $this->assertSame([], $campaigns['rows']);
        $this->assertSame(0, $kpis['active_campaigns']);
        $this->assertSame(0, $kpis['emails_sent_30d']);
        $this->assertSame(0, $kpis['open_rate']);
        $this->assertSame(0, $kpis['click_rate']);
        $this->assertSame(0, $kpis['conversion_rate']);
        $this->assertSame(1, $kpis['demandes']);
        $this->assertSame(1, $kpis['demandes_30d']);
        $this->assertSame([], $response->viewData('planning')['upcoming']);
        $this->assertSame([], $response->viewData('planning')['overdue']);
        $this->assertSame([], $response->viewData('criteria')['rows']);
        $this->assertSame([], $response->viewData('enterprises')['recent']);
    }

    public function test_backend_only_user_receives_no_domain_aggregates(): void
    {
        $this->seedProtectedDashboardData();
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)->get(self::DASHBOARD_URI)->assertOk();

        $this->assertSame(['total' => 0, 'active' => 0, 'rows' => []], $response->viewData('campaigns'));
        $this->assertSame(['upcoming' => [], 'overdue' => [], 'upcoming_count' => 0, 'overdue_count' => 0], $response->viewData('planning'));
        $this->assertSame(['total' => 0, 'active' => 0, 'auto_run' => 0, 'rows' => []], $response->viewData('criteria'));
        $this->assertSame([
            'total' => 0,
            'with_contacts' => 0,
            'qualification' => [],
            'sources' => [],
            'enrichment' => [],
            'recent' => [],
        ], $response->viewData('enterprises'));
        $this->assertSame([], $response->viewData('funnel'));
        $this->assertSame([], $response->viewData('topCampaigns'));
        $this->assertSame(['labels' => [], 'series' => ['opens' => [], 'clicks' => [], 'replies' => []]], $response->viewData('engagementOverTime'));
        foreach (['companies', 'contacts', 'active_campaigns', 'emails_sent_30d', 'open_rate', 'click_rate', 'demandes', 'demandes_30d'] as $key) {
            $this->assertSame(0, $response->viewData('kpis')[$key]);
        }
        $this->assertSame(0.0, $response->viewData('kpis')['conversion_rate']);
        $this->assertSame(['replies_awaiting_triage' => 0, 'failed_sends' => 0, 'stale_sends' => 0], $response->viewData('operations'));
    }

    public function test_dashboard_scrubs_campaign_data_without_campaign_permission(): void
    {
        $this->seedProtectedDashboardData();
        $response = $this->actingAs($this->dashboardUserWithout('view campaigns'))->get(self::DASHBOARD_URI)->assertOk();

        $this->assertSame(['total' => 0, 'active' => 0, 'rows' => []], $response->viewData('campaigns'));
        $this->assertSame(['upcoming' => [], 'overdue' => [], 'upcoming_count' => 0, 'overdue_count' => 0], $response->viewData('planning'));
        $this->assertSame(['labels' => [], 'series' => ['opens' => [], 'clicks' => [], 'replies' => []]], $response->viewData('engagementOverTime'));
        $this->assertSame([], $response->viewData('funnel'));
        $this->assertSame([], $response->viewData('topCampaigns'));
        foreach (['active_campaigns', 'emails_sent_30d', 'open_rate', 'click_rate', 'conversion_rate'] as $key) {
            $this->assertSame(0, $response->viewData('kpis')[$key]);
        }
    }

    public function test_dashboard_scrubs_criteria_data_without_criteria_permission(): void
    {
        $this->seedProtectedDashboardData();
        $response = $this->actingAs($this->dashboardUserWithout('view prospect_criteria'))->get(self::DASHBOARD_URI)->assertOk();

        $this->assertSame(['total' => 0, 'active' => 0, 'auto_run' => 0, 'rows' => []], $response->viewData('criteria'));
        $this->assertNotEmpty($response->viewData('campaigns')['rows']);
        $this->assertNotEmpty($response->viewData('enterprises')['recent']);
    }

    public function test_dashboard_scrubs_company_data_without_company_permission(): void
    {
        $this->seedProtectedDashboardData();
        $criteria = ProspectCriteria::query()->firstOrFail();
        Company::query()->firstOrFail()->update(['criteria_id' => $criteria->id]);
        $response = $this->actingAs($this->dashboardUserWithout('view companies'))->get(self::DASHBOARD_URI)->assertOk();

        $this->assertSame([
            'total' => 0,
            'with_contacts' => 0,
            'qualification' => [],
            'sources' => [],
            'enrichment' => [],
            'recent' => [],
        ], $response->viewData('enterprises'));
        $this->assertSame(0, $response->viewData('kpis')['companies']);
        $this->assertSame(1, $response->viewData('kpis')['contacts']);
        $this->assertSame([], $response->viewData('funnel'));
        $this->assertNotEmpty($response->viewData('topCampaigns'), 'top campaigns only requires campaign and demande permissions');
        $this->assertSame([0], array_column($response->viewData('criteria')['rows'], 'companies_count'));
        $this->assertSame([1], array_column($response->viewData('criteria')['rows'], 'contacts_count'));
    }

    public function test_dashboard_scrubs_contact_aggregates_without_contact_permission(): void
    {
        $this->seedProtectedDashboardData();
        $criteria = ProspectCriteria::query()->firstOrFail();
        Company::query()->firstOrFail()->update(['criteria_id' => $criteria->id]);

        $response = $this->actingAs($this->dashboardUserWithout('view contacts'))->get(self::DASHBOARD_URI)->assertOk();

        $this->assertSame(0, $response->viewData('kpis')['contacts']);
        $this->assertSame(0, $response->viewData('enterprises')['with_contacts']);
        $this->assertSame([0], array_column($response->viewData('enterprises')['recent'], 'contacts_count'));
        $this->assertSame([0], array_column($response->viewData('criteria')['rows'], 'contacts_count'));
        $this->assertSame([1], array_column($response->viewData('criteria')['rows'], 'companies_count'));
        $this->assertSame([], $response->viewData('funnel'));
    }

    public function test_dashboard_renders_discovery_schedule_and_latest_run_status(): void
    {
        $criteria = ProspectCriteria::create([
            'name' => 'Critere automatique en echec',
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 7,
        ]);
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'failed',
            'finished_at' => now(),
        ]);

        $this->actingAs($this->superadmin)
            ->get(self::DASHBOARD_URI)
            ->assertOk()
            ->assertSee('07h')
            ->assertSee(config('global.data.discovery_run_statuses.failed.label'))
            ->assertSee('badge-light-danger', false);
    }

    public function test_dashboard_scrubs_demande_and_cross_domain_data_without_demande_permission(): void
    {
        $this->seedProtectedDashboardData();
        $response = $this->actingAs($this->dashboardUserWithout('view demandes'))->get(self::DASHBOARD_URI)->assertOk();

        $kpis = $response->viewData('kpis');
        $this->assertSame(0, $kpis['demandes']);
        $this->assertSame(0, $kpis['demandes_30d']);
        $this->assertSame(0.0, $kpis['conversion_rate']);
        $this->assertSame([], $response->viewData('funnel'));
        $this->assertSame([], $response->viewData('topCampaigns'));
        $this->assertSame(1, array_sum($response->viewData('engagementOverTime')['series']['opens']));
    }
}

// <<<
