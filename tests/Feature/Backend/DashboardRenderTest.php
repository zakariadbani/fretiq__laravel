<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardRenderTest — HTTP smoke tests for GET /admin/dashboard.
 *
 * The dashboard calls AnalyticsService::dashboardKpis() / funnel() /
 * engagementOverTime() / topCampaigns() synchronously. These tests verify
 * that no exception (e.g. division-by-zero) is thrown even on an empty
 * database, and that the response is a valid 200.
 */
class DashboardRenderTest extends TestCase
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

    /**
     * Superadmin can render the dashboard on an empty database without errors.
     *
     * This is the primary regression guard: AnalyticsService must not throw a
     * division-by-zero or missing-key exception when no campaign data exists.
     */
    public function test_superadmin_can_view_dashboard_on_empty_data(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/dashboard')
            ->assertStatus(200);
    }

    /**
     * Unauthenticated request is redirected to login (auth middleware).
     */
    public function test_guest_is_redirected_from_dashboard(): void
    {
        $this->get('/admin/dashboard')
            ->assertRedirect();
    }

    public function test_legacy_dashboard_redirects_to_admin_dashboard_for_backend_users(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/dashboard')
            ->assertRedirect('/admin/dashboard');
    }

    public function test_roleless_user_cannot_access_legacy_dashboard(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertForbidden()
            ->assertSee('Votre compte n’a pas les droits nécessaires');
    }

    public function test_unverified_user_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_active'         => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/dashboard')
            ->assertRedirect(route('verification.notice'))
            ->assertDontSee('Campagnes actives')
            ->assertDontSee('Taux d\'ouverture');
    }

    public function test_roleless_user_cannot_access_admin_dashboard(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/dashboard')
            ->assertForbidden()
            ->assertSee('Votre compte n’a pas les droits nécessaires')
            ->assertDontSee('Campagnes actives')
            ->assertDontSee('Taux d\'ouverture');
    }

    public function test_empty_dashboard_renders_both_chart_empty_states(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('data-testid="dashboard-engagement-chart"', false)
            ->assertSee('data-testid="dashboard-engagement-empty"', false)
            ->assertSee('data-testid="dashboard-funnel-chart"', false)
            ->assertSee('data-testid="dashboard-funnel-empty"', false)
            ->assertDontSee('id="dashboard-engagement-chart-canvas"', false)
            ->assertDontSee('id="dashboard-funnel-chart-canvas"', false);
    }
}
