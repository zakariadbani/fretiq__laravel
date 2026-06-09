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
}
