<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Services\Analytics\AnalyticsService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service-level tests for AnalyticsService.
 *
 * Asserts exact key names / percentage convention by reading the service source
 * rather than guessing: open_rate is (opened/sent)*100, conversion_rate is
 * (demandes_30d/emails_sent_30d)*100. Both are floats, 0.0 when sent=0.
 */
class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->service = app(AnalyticsService::class);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeCompany(string $source = 'manual'): Company
    {
        return Company::create([
            'name'                 => 'ACME ' . uniqid(),
            'relationship'         => 'client',
            'source'               => $source,
            'qualification_status' => 'pending',
        ]);
    }

    private function makeContact(Company $company): Contact
    {
        return Contact::create([
            'company_id'  => $company->id,
            'email'       => 'contact_' . uniqid() . '@test.test',
            'name'        => 'Test Contact',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeCampaignWithRun(array $runAttributes = []): Campaign
    {
        $segment  = Segment::create(['name' => 'Seg ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl ' . uniqid(),
            'subject'      => 'Sujet test',
            'html_content' => '<p>Hello</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL',
            'email' => 'noreply_' . uniqid() . '@tcl.test',
        ]);

        $campaign = Campaign::create([
            'name'               => 'Campaign ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);

        CampaignRun::create(array_merge([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'one_shot_' . now()->format('Y-m-d'),
            'run_at'         => now(),
            'status'         => 'sent',
            'stats_sent'     => 0,
            'stats_opened'   => 0,
            'conversion_count' => 0,
        ], $runAttributes));

        return $campaign;
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * With no data in the database, dashboardKpis() returns all expected keys
     * with 0 (or 0.0) values — no division-by-zero exception.
     */
    public function test_dashboard_kpis_empty_safe(): void
    {
        $kpis = $this->service->dashboardKpis();

        // All nine keys must be present.
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
            $this->assertArrayHasKey($key, $kpis, "dashboardKpis() must return key '{$key}'");
        }

        // Division guards: both rates must be 0 (not an exception, not NaN)
        $this->assertSame(0.0, (float) $kpis['open_rate'],
            'open_rate must be 0.0 when no emails have been sent');
        $this->assertSame(0.0, (float) $kpis['conversion_rate'],
            'conversion_rate must be 0.0 when no emails have been sent');
    }

    /**
     * With seeded data, dashboardKpis() returns correct computed values.
     *
     * Seed: 1 Company, 1 Contact, 1 Campaign + 1 CampaignRun (sent=10, opened=5, conversion_count=2)
     *       + 2 Demandes captured today.
     *
     * Expected:
     *   companies       >= 1
     *   contacts        >= 1
     *   emails_sent_30d >= 10
     *   open_rate       = (5/10)*100 = 50.0
     *   demandes        >= 2
     *   conversion_rate = (2/10)*100 = 20.0
     */
    public function test_dashboard_kpis_computed(): void
    {
        $company = $this->makeCompany();
        $this->makeContact($company);

        // Campaign run within the 30-day window
        $this->makeCampaignWithRun([
            'run_at'           => now(),
            'stats_sent'       => 10,
            'stats_opened'     => 5,
            'stats_clicked'    => 1,
            'conversion_count' => 2,
        ]);

        // Two demandes captured today
        Demande::create(['captured_at' => now(), 'kind' => 'inbound', 'status' => 'new']);
        Demande::create(['captured_at' => now(), 'kind' => 'inbound', 'status' => 'new']);

        $kpis = $this->service->dashboardKpis();

        $this->assertGreaterThanOrEqual(1, $kpis['companies'],
            'companies must count the seeded Company');
        $this->assertGreaterThanOrEqual(1, $kpis['contacts'],
            'contacts must count the seeded Contact');
        $this->assertGreaterThanOrEqual(10, $kpis['emails_sent_30d'],
            'emails_sent_30d must include the seeded run stats_sent=10');
        $this->assertGreaterThanOrEqual(2, $kpis['demandes'],
            'demandes must count the seeded Demande rows');

        // open_rate = (5/10)*100 = 50.0
        $this->assertEqualsWithDelta(50.0, (float) $kpis['open_rate'], 0.01,
            'open_rate must be 50.0 (5 opens out of 10 sent)');
    }

    /**
     * funnel() returns the six expected stage keys in declared order,
     * each mapping to a non-negative integer.
     */
    public function test_funnel_returns_ordered_stages(): void
    {
        $funnel = $this->service->funnel();

        $expectedStages = [
            'Découvertes',
            'Contactées',
            'Ouvertures',
            'Clics',
            'Réponses',
            'Demandes',
        ];

        foreach ($expectedStages as $stage) {
            $this->assertArrayHasKey($stage, $funnel,
                "funnel() must return stage key '{$stage}'");
            $this->assertIsInt($funnel[$stage],
                "funnel stage '{$stage}' must be an integer");
            $this->assertGreaterThanOrEqual(0, $funnel[$stage],
                "funnel stage '{$stage}' must be >= 0");
        }

        // Keys must appear in the declared order
        $this->assertSame($expectedStages, array_keys($funnel),
            'funnel() stage keys must be in the canonical order');
    }

    /**
     * topCampaigns() returns rows sorted by conversion_rate descending
     * with the required keys: id, name, sent, demandes, conversion_rate.
     */
    public function test_top_campaigns(): void
    {
        // Campaign A: 10 sent, 2 conversions → rate = 20.0
        $this->makeCampaignWithRun([
            'stats_sent'       => 10,
            'conversion_count' => 2,
        ]);

        // Campaign B: 20 sent, 1 conversion → rate = 5.0
        $this->makeCampaignWithRun([
            'stats_sent'       => 20,
            'conversion_count' => 1,
        ]);

        $rows = $this->service->topCampaigns(5);

        $this->assertIsArray($rows, 'topCampaigns() must return an array');
        $this->assertGreaterThanOrEqual(2, count($rows),
            'topCampaigns() must include both seeded campaigns');

        // Each row must have the documented keys
        $requiredKeys = ['id', 'name', 'sent', 'demandes', 'conversion_rate'];
        foreach ($rows as $row) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $row,
                    "topCampaigns() row must contain key '{$key}'");
            }
        }

        // Sorted descending by conversion_rate
        for ($i = 1; $i < count($rows); $i++) {
            $this->assertGreaterThanOrEqual(
                $rows[$i]['conversion_rate'],
                $rows[$i - 1]['conversion_rate'],
                'topCampaigns() rows must be sorted by conversion_rate descending'
            );
        }

        // The first row should be Campaign A (rate=20.0) which is highest
        $this->assertEqualsWithDelta(20.0, (float) $rows[0]['conversion_rate'], 0.01,
            'First row must be the campaign with conversion_rate=20.0');
    }
}
