<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ConsumptionPageTest — the client-facing "Ma consommation" page.
 *
 * Covers: permission gating, no price/capacity leak (client-facing page must
 * never show superadmin-only pricing/provider-capacity data), dailySeries()
 * accounting parity with usedOn()/contactUsedOn() (in-flight runs counted at
 * max(reserved, consumed) — MAJOR #1), and perCriteriaBreakdown() bucketing
 * manual-enrichment runs separately from the named criterion (MAJOR #2).
 */
class ConsumptionPageTest extends TestCase
{
    use RefreshDatabase;

    private User $commercial;
    private User $backendOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');

        // backend.access only — no "view consumption" permission.
        $this->backendOnly = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->backendOnly->givePermissionTo('backend.access');
    }

    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Critère Consommation ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    private function assignPackage(int $daily, int $dailyContact, ?float $price = 199.00): Package
    {
        $package = Package::create([
            'name'                    => 'Pack Consommation',
            'daily_credits'           => $daily,
            'daily_contact_credits'   => $dailyContact,
            'monthly_credits'         => $daily * 30,
            'monthly_contact_credits' => $dailyContact * 30,
            'price_monthly'           => $price,
            'is_active'               => true,
            'sort_order'              => 0,
        ]);

        PackageAssignment::create([
            'package_id'  => $package->id,
            'assigned_by' => null,
        ]);

        return $package;
    }

    // ── Permission gating ────────────────────────────────────────────────────

    public function test_commercial_sees_the_page_and_pack_name(): void
    {
        $package = $this->assignPackage(40, 20);

        $response = $this->actingAs($this->commercial)->get(route('admin.consumption.index'));

        $response->assertStatus(200);
        $response->assertSee($package->name);
        $response->assertSee('Utilisé + réservé', false);
        $response->assertSee('Restant', false);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->assignPackage(40, 20);

        $response = $this->actingAs($this->backendOnly)->get(route('admin.consumption.index'));

        $response->assertStatus(403);
    }

    // ── Negative leak assertions (client-facing page) ───────────────────────

    public function test_page_does_not_leak_price_or_capacity_labels(): void
    {
        config([
            'prospecting.provider_discovery_monthly_capacity' => 1000,
            'prospecting.provider_enrich_monthly_capacity'    => 2000,
        ]);

        $this->assignPackage(40, 20, 199.00);

        $response = $this->actingAs($this->commercial)->get(route('admin.consumption.index'));

        $response->assertStatus(200);
        $response->assertDontSee('199,00');
        $response->assertDontSee('199.00');
        $response->assertDontSee('€');
        $response->assertDontSee('Capacité fournisseur');
        $response->assertDontSee('Vendu au client actif');
    }

    // ── dailySeries() accounting parity (MAJOR #1) ──────────────────────────

    /**
     * dailySeries() must use the SAME accounting shape as usedOn()/contactUsedOn():
     * terminal runs count `consumed`; in-flight runs count max(reserved, consumed).
     * A pending run with credits_reserved > 0 and consumed = 0 must be counted at
     * its reserved value, not zero — otherwise the chart disagrees with the
     * today progress bar computed from usedOn() on the same page.
     */
    public function test_daily_series_is_dense_30_points_and_sums_match_used_on(): void
    {
        $this->assignPackage(50, 30);
        $criteria = $this->makeCriteria();

        $today     = Carbon::today();
        $threeDaysAgo = $today->copy()->subDays(3);
        $twoDaysAgo   = $today->copy()->subDays(2);

        // Terminal run 3 days ago: consumed = 5.
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'status'               => 'completed',
            'credits_reserved'     => 5,
            'consumed'             => 5,
            'contact_credits_reserved' => 3,
            'contact_consumed'         => 3,
            'quota_date'           => $threeDaysAgo->toDateString(),
        ]);

        // Terminal run 2 days ago: consumed = 4.
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'status'               => 'completed',
            'credits_reserved'     => 4,
            'consumed'             => 4,
            'contact_credits_reserved' => 2,
            'contact_consumed'         => 2,
            'quota_date'           => $twoDaysAgo->toDateString(),
        ]);

        // In-flight (pending) run TODAY: credits_reserved = 7, consumed = 0.
        // Must be counted at 7 (max(reserved, consumed)), not 0.
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'status'               => 'pending',
            'credits_reserved'     => 7,
            'consumed'             => 0,
            'contact_credits_reserved' => 4,
            'contact_consumed'         => 0,
            'quota_date'           => $today->toDateString(),
        ]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $start  = $today->copy()->subDays(29);
        $end    = $today->copy()->addDay();
        $series = $svc->dailySeries($start, $end);

        // Dense 30-point series: today−29 through today inclusive.
        $this->assertCount(30, $series['dates']);
        $this->assertCount(30, $series['discoveries']);
        $this->assertCount(30, $series['contacts']);
        $this->assertSame($start->toDateString(), $series['dates'][0]);
        $this->assertSame($today->toDateString(), $series['dates'][29]);

        // Verify the sums at each populated day, keyed by date.
        $byDate = array_combine($series['dates'], array_map(
            fn ($d, $c) => ['discoveries' => $d, 'contacts' => $c],
            $series['discoveries'],
            $series['contacts']
        ));

        $this->assertSame(5, $byDate[$threeDaysAgo->toDateString()]['discoveries']);
        $this->assertSame(3, $byDate[$threeDaysAgo->toDateString()]['contacts']);

        $this->assertSame(4, $byDate[$twoDaysAgo->toDateString()]['discoveries']);
        $this->assertSame(2, $byDate[$twoDaysAgo->toDateString()]['contacts']);

        // Today's point must equal usedOn(today)/contactUsedOn(today) — same
        // accounting shape, in-flight run counted at reserved value (7 / 4).
        $this->assertSame($svc->usedOn($today), $byDate[$today->toDateString()]['discoveries']);
        $this->assertSame(7, $byDate[$today->toDateString()]['discoveries']);
        $this->assertSame($svc->contactUsedOn($today), $byDate[$today->toDateString()]['contacts']);
        $this->assertSame(4, $byDate[$today->toDateString()]['contacts']);
    }

    // ── excluded_count parity (dailySeries/perCriteriaBreakdown vs usedOn) ──

    /**
     * A completed run with excluded_count > 0 (candidate rejected by AI
     * targeting, refunded) must be charged at EFFECTIVE credits
     * (consumed - excluded_count) in dailySeries() and perCriteriaBreakdown(),
     * exactly like usedOn() — otherwise the 30-day chart / per-criteria
     * "Découvertes" column overcounts vs the today/month progress bars on the
     * same page.
     */
    public function test_daily_series_and_breakdown_subtract_excluded_count_like_used_on(): void
    {
        $this->assignPackage(50, 30);
        $criteria = $this->makeCriteria();

        $today = Carbon::today();
        [$pStart, $pEnd] = app(DiscoveryQuotaService::class)->currentPeriod($today);

        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'completed',
            'credits_reserved'     => 10,
            'consumed'             => 10,
            'excluded_count'       => 3,
            'quota_date'           => $today->toDateString(),
        ]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);

        $series = $svc->dailySeries($today, $today->copy()->addDay());
        $byDate = array_combine($series['dates'], $series['discoveries']);

        $this->assertSame(7, $byDate[$today->toDateString()],
            'dailySeries() must charge effective credits (10 - 3 excluded), not raw consumed');
        $this->assertSame($svc->usedOn($today), $byDate[$today->toDateString()],
            'dailySeries() today point must equal usedOn(today) — parity guarantee');

        $rows = $svc->perCriteriaBreakdown($pStart, $pEnd);
        $row  = $rows->keyBy('label')->get($criteria->name);

        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row->consumed,
            'perCriteriaBreakdown() consumed must be effective (10 - 3 excluded)');
    }

    // ── perCriteriaBreakdown() manual-run bucketing (MAJOR #2) ──────────────

    /**
     * A manual enrichment run on a Company WITH a criteria_id must land in the
     * "Enrichissement manuel" bucket, NOT the named criterion's row — manual
     * runs carry prospect_criteria_id = company.criteria_id (service line ~729),
     * so a naive group-by would fold manual spend into a criterion's row.
     */
    public function test_per_criteria_breakdown_separates_manual_from_named_criteria(): void
    {
        $this->assignPackage(50, 30);

        $criteriaA = $this->makeCriteria(['name' => 'Critère A']);
        $criteriaB = $this->makeCriteria(['name' => 'Critère B']);

        $today = Carbon::today();
        [$pStart, $pEnd] = app(DiscoveryQuotaService::class)->currentPeriod($today);

        // Discovery run for criteria A.
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteriaA->id,
            'type'                 => 'discovery',
            'status'               => 'completed',
            'credits_reserved'     => 10,
            'consumed'             => 10,
            'companies_count'      => 8,
            'quota_date'           => $today->toDateString(),
        ]);

        // Discovery run for criteria B.
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteriaB->id,
            'type'                 => 'discovery',
            'status'               => 'completed',
            'credits_reserved'     => 6,
            'consumed'             => 6,
            'companies_count'      => 5,
            'quota_date'           => $today->toDateString(),
        ]);

        // Manual enrichment run on a company that belongs to criteria A —
        // carries prospect_criteria_id = criteriaA->id but type='manual'.
        $company = Company::create([
            'criteria_id'  => $criteriaA->id,
            'domain'       => 'manual-' . uniqid() . '.fr',
            'name'         => 'Manual Co',
            'relationship' => 'prospect',
            'source'       => 'discovered',
        ]);

        DiscoveryRun::create([
            'prospect_criteria_id'     => $criteriaA->id,   // same as criteria A!
            'type'                     => 'manual',
            'company_id'               => $company->id,
            'status'                   => 'completed',
            'credits_reserved'         => 0,
            'consumed'                 => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed'         => 1,
            'quota_date'               => $today->toDateString(),
        ]);

        /** @var DiscoveryQuotaService $svc */
        $svc = app(DiscoveryQuotaService::class);
        $rows = $svc->perCriteriaBreakdown($pStart, $pEnd);

        $byLabel = $rows->keyBy('label');

        $this->assertTrue($byLabel->has('Critère A'), 'Criteria A row must exist');
        $this->assertTrue($byLabel->has('Critère B'), 'Criteria B row must exist');
        $this->assertTrue($byLabel->has('Enrichissement manuel'), 'Manual bucket row must exist');

        // Criteria A's row must NOT include the manual run's contact credit.
        $this->assertSame(1, (int) $byLabel['Critère A']->runs_count,
            'Criteria A must have exactly 1 run (the discovery run) — manual run must not be folded in');
        $this->assertSame(10, (int) $byLabel['Critère A']->consumed);
        $this->assertSame(0, (int) $byLabel['Critère A']->contact_consumed,
            'Criteria A row must not carry the manual run\'s contact_consumed');

        // Manual bucket must carry the contact credit instead.
        $this->assertSame(1, (int) $byLabel['Enrichissement manuel']->runs_count);
        $this->assertSame(1, (int) $byLabel['Enrichissement manuel']->contact_consumed);

        // Criteria B unaffected.
        $this->assertSame(1, (int) $byLabel['Critère B']->runs_count);
        $this->assertSame(6, (int) $byLabel['Critère B']->consumed);
    }
}
