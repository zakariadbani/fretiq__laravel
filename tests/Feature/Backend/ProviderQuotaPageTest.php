<?php

namespace Tests\Feature\Backend;

use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Quota\DiscoveryQuotaService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProviderQuotaPageTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $admin;
    private User $commercial;
    private User $backendOnly;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(app(DiscoveryQuotaService::class)->today()->setTime(12, 0));

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->admin->assignRole('admin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');

        $this->backendOnly = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->backendOnly->givePermissionTo('backend.access');
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_superadmin_can_view_the_page(): void
    {
        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSee('SerpAPI');
        $response->assertSee('Hunter');
        $response->assertSee('Non disponible');
        $response->assertSeeText('Utilisation des quotas');
        $response->assertSeeText('Une sur-réservation indique que les réservations dépassent la capacité disponible');
    }

    public function test_admin_is_forbidden(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(403);
    }

    public function test_commercial_is_forbidden(): void
    {
        $response = $this->actingAs($this->commercial)->get(route('admin.provider-quota.index'));

        $response->assertStatus(403);
    }

    public function test_backend_only_user_is_forbidden(): void
    {
        $response = $this->actingAs($this->backendOnly)->get(route('admin.provider-quota.index'));

        $response->assertStatus(403);
    }

    public function test_live_numbers_render_when_services_return_data(): void
    {
        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn([
            'plan_searches_left'  => 700,
            'total_searches_left' => 743,
            'this_month_usage'    => 257,
            'searches_per_month'  => 1000,
            'plan_name'           => 'Starter',
            'account_email'       => 'ops@tcl.test',
        ]);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn([
            'searches_used'           => 120,
            'searches_available'      => 380,
            'verifications_used'      => 30,
            'verifications_available' => 70,
            'plan_name'               => 'Starter',
            'reset_date'              => '2026-08-01',
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSee('743');
        $response->assertSeeText('120 / 500');
        $response->assertSeeText('30 / 100');
        $response->assertSeeText('Réservé / Total : 0 / 500');
        $response->assertSeeText('Restant / Total : 380 / 500');
        $response->assertSee('Starter');
        $response->assertSee('Utilisé / Total', false);
        $response->assertSee('Réservé / Total', false);
        $response->assertSee('Restant / Total', false);
        $response->assertSee('Restant plan', false);
        $response->assertSeeText("Les lancements consomment le quota disponible au moment de l'exécution", false);
    }

    /**
     * @dataProvider hunterQuotaCases
     */
    public function test_hunter_quota_values_render_used_reserved_total_remaining_and_overbooking(
        int $searchesUsed,
        int $searchesAvailable,
        int $searchesReserved,
        string $expectedSearches,
        string $expectedSearchesReserved,
        string $expectedSearchesRemaining,
        ?string $expectedSearchesOverbooked,
        int $verificationsUsed,
        int $verificationsAvailable,
        string $expectedVerifications
    ): void {
        $this->reserveProviderQuota(hunterSearches: $searchesReserved);

        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn(null);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn([
            'searches_used'           => $searchesUsed,
            'searches_available'      => $searchesAvailable,
            'verifications_used'      => $verificationsUsed,
            'verifications_available' => $verificationsAvailable,
            'plan_name'               => 'Starter',
            'reset_date'              => '2026-08-01',
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSee('Recherches - Utilisé / Total', false);
        $response->assertSee('Vérifications - Utilisé / Total', false);
        $response->assertSeeText($expectedSearches);
        $response->assertSeeText('Réservé / Total : '.$expectedSearchesReserved);
        $response->assertSeeText('Restant / Total : '.$expectedSearchesRemaining);
        $response->assertSeeText($expectedVerifications);
        $response->assertDontSeeText('Restant / Total : -');

        if ($searchesAvailable < 0) {
            $response->assertDontSeeText($searchesUsed.' / '.$searchesAvailable);
        }

        if ($expectedSearchesOverbooked === null) {
            $response->assertDontSeeText('Surquota '.$searchesAvailable.' au-delà du disponible');
        } else {
            $response->assertSeeText($expectedSearchesOverbooked);
        }

        if ($verificationsAvailable < 0) {
            $response->assertDontSeeText($verificationsUsed.' / '.$verificationsAvailable);
        }

        $response->assertDontSeeText('Surquota '.$verificationsAvailable.' au-delà du disponible');
    }

    public static function hunterQuotaCases(): array
    {
        return [
            'empty quota' => [0, 500, 0, '0 / 500', '0 / 500', '500 / 500', null, 0, 100, '0 / 100'],
            'full quota' => [500, 0, 0, '500 / 500', '0 / 500', '0 / 500', null, 100, 0, '100 / 100'],
            'reserved quota' => [120, 380, 25, '120 / 500', '25 / 500', '355 / 500', null, 30, 70, '30 / 100'],
            'overbooked reservation' => [120, 20, 50, '120 / 140', '50 / 140', '0 / 140', 'Surquota 30 au-delà du disponible', 30, 5, '30 / 35'],
            'exceeded provider quota' => [520, -20, 0, '520 / 500', '0 / 500', '0 / 500', null, 130, -30, '130 / 100'],
        ];
    }

    public function test_hunter_unavailable_usage_renders_reserved_as_unavailable(): void
    {
        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn(null);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn([
            'searches_used'           => null,
            'searches_available'      => null,
            'verifications_used'      => null,
            'verifications_available' => null,
            'plan_name'               => null,
            'reset_date'              => null,
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSeeText('Réservé / Total : —');
        $response->assertSeeText('Restant / Total : —');
        $response->assertDontSeeText('Surquota');
    }

    public function test_serpapi_reserved_remaining_and_overbooking_are_labeled(): void
    {
        $this->reserveProviderQuota(serpapiSearches: 5);

        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn([
            'plan_searches_left'  => 0,
            'total_searches_left' => 3,
            'this_month_usage'    => 997,
            'searches_per_month'  => 1000,
            'plan_name'           => 'Starter',
            'account_email'       => 'ops@tcl.test',
        ]);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn(null);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSee('Utilisé / Total', false);
        $response->assertSee('Réservé / Total', false);
        $response->assertSee('Restant / Total', false);
        $response->assertSeeText('997 / 1000');
        $response->assertSeeText('5 / 1000');
        $response->assertSeeText('0 / 1000');
        $response->assertSeeText('Surquota 2 au-delà du disponible');
        $response->assertDontSeeText('Restant / Total -');
    }

    private function reserveProviderQuota(int $serpapiSearches = 0, int $hunterSearches = 0): void
    {
        if ($serpapiSearches <= 0 && $hunterSearches <= 0) {
            return;
        }

        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Provider Quota '.uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        DiscoveryRun::create([
            'prospect_criteria_id'     => $criteria->id,
            'status'                   => 'pending',
            'credits_reserved'         => $serpapiSearches,
            'searches_reserved'        => $serpapiSearches,
            'searches_consumed'        => 0,
            'consumed'                 => 0,
            'contact_credits_reserved' => $hunterSearches,
            'contact_consumed'         => 0,
            'quota_date'               => Carbon::today()->toDateString(),
        ]);
    }
}
