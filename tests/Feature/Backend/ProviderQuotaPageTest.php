<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\HunterEnrichmentService;
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
            'searches_available'      => 500.0,
            'verifications_used'      => 30,
            'verifications_available' => 100,
            'plan_name'               => 'Starter',
            'reset_date'              => '2026-08-01',
        ]);
        $this->instance(HunterEnrichmentService::class, $hunter);

        $response = $this->actingAs($this->superadmin)->get(route('admin.provider-quota.index'));

        $response->assertStatus(200);
        $response->assertSee('743');
        $response->assertSee('120');
        $response->assertSee('500');
        $response->assertSee('Starter');
    }
}