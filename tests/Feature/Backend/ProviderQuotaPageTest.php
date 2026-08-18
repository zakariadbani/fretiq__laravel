<?php

namespace Tests\Feature\Backend;

use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Quota\DiscoveryQuotaService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
            'is_active' => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->commercial->assignRole('commercial');

        $this->backendOnly = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
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
        $response->assertSee('Quota découverte');
        $response->assertSee('Quota contacts');
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
            'plan_searches_left' => 700,
            'total_searches_left' => 743,
            'this_month_usage' => 257,
            'searches_per_month' => 1000,
            'plan_name' => 'Starter',
        ]);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn([
            'searches_used' => 120,
            'searches_available' => 380,
            'verifications_used' => 30,
            'verifications_available' => 70,
            'plan_name' => 'Starter',
            'reset_date' => '2026-08-01',
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSee('743');
        $response->assertSeeText('120 / 500');
        $response->assertSeeText('30 / 100');
        $response->assertSeeText('Disponible / Total : 380 / 500');
        $response->assertSeeText('0 / 500');
        $response->assertSee('Starter');
        $response->assertSeeText('01/08/2026');
        $response->assertSee('Utilisé / Total', false);
        $response->assertSee('Réservé / Total', false);
        $response->assertSee('Restant / Total', false);
        $response->assertSee('Restant plan', false);
        $response->assertSeeText("Les lancements consomment le quota disponible au moment de l'exécution", false);
    }

    /**
     * @dataProvider hunterQuotaCases
     */
    public function test_hunter_quota_values_keep_provider_usage_separate_from_app_bundle_reservations(
        int $searchesUsed,
        int $searchesAvailable,
        int $searchesReserved,
        string $expectedSearches,
        string $expectedSearchesAvailable,
        int $verificationsUsed,
        int $verificationsAvailable,
        string $expectedVerifications,
        string $expectedReserved,
        string $expectedRemaining,
        int $expectedOverbooked
    ): void {
        $this->reserveProviderQuota(hunterSearches: $searchesReserved);

        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn(null);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn([
            'searches_used' => $searchesUsed,
            'searches_available' => $searchesAvailable,
            'verifications_used' => $verificationsUsed,
            'verifications_available' => $verificationsAvailable,
            'plan_name' => 'Starter',
            'reset_date' => '2026-08-01',
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSee('Recherches de contacts - Utilisé / Total', false);
        $response->assertSee('Vérifications - Utilisé / Total', false);
        $response->assertSeeText($expectedSearches);
        $response->assertSeeText('Disponible / Total : '.$expectedSearchesAvailable);
        $response->assertSeeText($expectedVerifications);
        $response->assertSee('Réservé / Total', false);
        $response->assertSee('Restant / Total', false);
        $response->assertSeeText($expectedReserved);
        $response->assertSeeText($expectedRemaining);

        if ($expectedOverbooked > 0) {
            $response->assertSeeText("Surquota {$expectedOverbooked} au-delà du disponible");
        } else {
            $response->assertDontSeeText('Surquota');
        }

        if ($searchesAvailable < 0) {
            $response->assertDontSeeText($searchesUsed.' / '.$searchesAvailable);
        }

        if ($verificationsAvailable < 0) {
            $response->assertDontSeeText($verificationsUsed.' / '.$verificationsAvailable);
        }
    }

    public static function hunterQuotaCases(): array
    {
        return [
            'empty quota' => [0, 500, 0, '0 / 500', '500 / 500', 0, 100, '0 / 100', '0 / 500', '500 / 500', 0],
            'full quota' => [500, 0, 0, '500 / 500', '0 / 500', 100, 0, '100 / 100', '0 / 500', '0 / 500', 0],
            'reserved bundles' => [120, 380, 25, '120 / 500', '380 / 500', 30, 70, '30 / 100', '25 / 500', '355 / 500', 0],
            'bundles exceed provider availability' => [120, 20, 50, '120 / 140', '20 / 140', 30, 5, '30 / 35', '50 / 140', '0 / 140', 30],
            'exceeded provider quota' => [520, -20, 0, '520 / 500', '0 / 500', 130, -30, '130 / 100', '0 / 500', '0 / 500', 0],
            'exceeded provider quota with reservations' => [520, -20, 10, '520 / 500', '0 / 500', 130, -30, '130 / 100', '10 / 500', '0 / 500', 10],
        ];
    }

    public function test_hunter_unavailable_usage_keeps_app_bundle_reservations_visible(): void
    {
        $this->reserveProviderQuota(hunterSearches: 7);

        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn(null);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn([
            'searches_used' => null,
            'searches_available' => null,
            'verifications_used' => null,
            'verifications_available' => null,
            'plan_name' => null,
            'reset_date' => null,
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSeeText('Disponible / Total : —');
        $response->assertSee('Réservé / Total', false);
        $response->assertSee('Restant / Total', false);
        $response->assertSeeText('7 / —');
        $response->assertDontSeeText('Surquota');
    }

    public function test_serpapi_reserved_remaining_and_overbooking_are_labeled(): void
    {
        $this->reserveProviderQuota(serpapiSearches: 5);

        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn([
            'plan_searches_left' => 0,
            'total_searches_left' => 3,
            'this_month_usage' => 997,
            'searches_per_month' => 1000,
            'plan_name' => 'Starter',
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

    public function test_refresh_forgets_the_provider_caches_and_redirects(): void
    {
        Cache::put('provider.serpapi.account.v2', true, now()->addMinutes(10));
        Cache::put('provider.hunter.account.v2', true, now()->addMinutes(10));

        $response = $this->actingAs($this->superadmin)->post(route('admin.provider-quota.refresh'));

        $response->assertRedirect(route('admin.provider-quota.index'));
        $response->assertSessionHas('success');
        $this->assertFalse(Cache::has('provider.serpapi.account.v2'));
        $this->assertFalse(Cache::has('provider.hunter.account.v2'));
    }

    public function test_refresh_is_rate_limited(): void
    {
        $this->actingAs($this->superadmin)->post(route('admin.provider-quota.refresh'));

        Cache::put('provider.serpapi.account.v2', true, now()->addMinutes(10));
        Cache::put('provider.hunter.account.v2', true, now()->addMinutes(10));

        $response = $this->actingAs($this->superadmin)->post(route('admin.provider-quota.refresh'));

        $response->assertSessionHas('warning');
        $this->assertTrue(Cache::has('provider.serpapi.account.v2'));
        $this->assertTrue(Cache::has('provider.hunter.account.v2'));
    }

    public function test_refresh_is_forbidden_for_admin(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.provider-quota.refresh'));

        $response->assertStatus(403);
    }

    public function test_freshness_timestamp_renders(): void
    {
        $serp = Mockery::mock(CompanyDiscoveryService::class);
        $serp->shouldReceive('accountUsage')->andReturn([
            'plan_searches_left' => 700,
            'total_searches_left' => 743,
            'this_month_usage' => 257,
            'searches_per_month' => 1000,
            'plan_name' => 'Starter',
            'fetched_at' => '2026-08-18T14:32:00+02:00',
        ]);
        $this->instance(CompanyDiscoveryService::class, $serp);

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn([
            'searches_used' => 120,
            'searches_available' => 380,
            'verifications_used' => 30,
            'verifications_available' => 70,
            'plan_name' => 'Starter',
            'reset_date' => '2026-08-01',
            'fetched_at' => '2026-08-18T14:32:00+02:00',
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSeeText('Données du 18/08/2026 14:32');
    }

    public function test_consumption_panel_reports_calls_by_outcome(): void
    {
        ProviderCall::query()->create([
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'engine' => 'hunter',
            'idempotency_key' => str_repeat('a', 64),
            'status' => 'succeeded',
            'http_status' => 200,
            'reserved_units' => 1,
            'consumed_units' => 1,
            'attempt_count' => 1,
        ]);
        ProviderCall::query()->create([
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'engine' => 'hunter',
            'idempotency_key' => str_repeat('b', 64),
            'status' => 'failed',
            'http_status' => 429,
            'reserved_units' => 1,
            'attempt_count' => 1,
        ]);
        ProviderCall::query()->create([
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'engine' => 'hunter',
            'idempotency_key' => str_repeat('c', 64),
            'status' => 'failed',
            'http_status' => 429,
            'reserved_units' => 1,
            'attempt_count' => 1,
        ]);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSeeText('Capacité contacts');
        $response->assertSeeTextInOrder(['Recherche de contacts', '3', '1', '0', '2', '1']);
    }

    public function test_consumption_panel_shows_no_fake_zeros_without_ledger_rows(): void
    {
        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSeeText('Aucun appel enregistré sur la période.');
    }

    private function reserveProviderQuota(int $serpapiSearches = 0, int $hunterSearches = 0): void
    {
        if ($serpapiSearches <= 0 && $hunterSearches <= 0) {
            return;
        }

        $criteria = ProspectCriteria::create([
            'name' => 'Critère Provider Quota '.uniqid(),
            'sectors' => ['transport'],
            'countries' => ['France'],
            'daily_limit' => 10,
            'is_active' => true,
        ]);

        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'status' => 'pending',
            'credits_reserved' => $serpapiSearches,
            'searches_reserved' => $serpapiSearches,
            'searches_consumed' => 0,
            'consumed' => 0,
            'contact_credits_reserved' => $hunterSearches,
            'contact_consumed' => 0,
            'quota_date' => Carbon::today()->toDateString(),
        ]);
    }
}
