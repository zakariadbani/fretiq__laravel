<?php

namespace Tests\Feature\Backend;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * PackageModuleTest — permission gating + basic render tests.
 *
 * Tests the "manage packages" single-permission gate for superadmin-only access.
 * Uses RefreshDatabase — requires a test DB (SQLite or MySQL) with migrations run.
 *
 * Note: discovery_runs / packages / package_assignments tables must exist in the
 * test DB. These are created by the migrations in database/migrations/2026_06_10_4000xx.
 * Run `php artisan migrate --env=testing` before the first run if the test DB is fresh.
 */
class PackageModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $admin;
    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permission
        $managePackages = Permission::firstOrCreate([
            'name'       => 'manage packages',
            'guard_name' => 'web',
        ]);

        // Create backend.access permission (required to pass the backend middleware)
        $backendAccess = Permission::firstOrCreate([
            'name'       => 'backend.access',
            'guard_name' => 'web',
        ]);

        // Superadmin role — gets manage packages
        $superadminRole = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $superadminRole->givePermissionTo($managePackages);
        $superadminRole->givePermissionTo($backendAccess);

        // Admin role — does NOT get manage packages
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->givePermissionTo($backendAccess);
        // Explicitly NOT giving manage packages to admin

        // Create users
        $this->superadmin = User::factory()->create();
        $this->superadmin->assignRole($superadminRole);

        $this->admin = User::factory()->create();
        $this->admin->assignRole($adminRole);

        // Create a test package
        $this->package = Package::create([
            'name'          => 'Pack Test',
            'daily_credits' => 50,
            'price_monthly' => 49.00,
            'is_active'     => true,
            'sort_order'    => 1,
        ]);
    }

    // ── Index page ───────────────────────────────────────────────────────────

    /** @test */
    public function superadmin_can_access_packages_index(): void
    {
        $response = $this->actingAs($this->superadmin)->get(route('admin.packages.index'));

        $response->assertStatus(200);
    }

    /** @test */
    public function admin_cannot_access_packages_index(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.packages.index'));

        $response->assertStatus(403);
    }

    // ── View page ────────────────────────────────────────────────────────────

    /** @test */
    public function superadmin_can_access_package_view_page(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(route('admin.packages.view', $this->package->id));

        $response->assertStatus(200);
        $response->assertSee('nav-line-tabs', false);
    }

    /** @test */
    public function admin_cannot_access_package_view_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.packages.view', $this->package->id));

        $response->assertStatus(403);
    }

    // ── Edit page ────────────────────────────────────────────────────────────

    /** @test */
    public function superadmin_can_access_package_edit_page(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(route('admin.packages.edit', $this->package->id));

        $response->assertStatus(200);
        $response->assertSee('sticky', false);
    }

    /** @test */
    public function admin_cannot_access_package_edit_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.packages.edit', $this->package->id));

        $response->assertStatus(403);
    }

    /**
     * POST /admin/packages with daily_contact_credits persists the field.
     */
    public function test_package_store_accepts_daily_contact_credits(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/packages', [
                'name'                  => 'Pack Contact',
                'daily_credits'         => 50,
                'daily_contact_credits' => 20,
                'is_active'             => true,
                'sort_order'            => 1,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('packages', [
            'name'                  => 'Pack Contact',
            'daily_contact_credits' => 20,
        ]);
    }

    /**
     * POST /admin/packages with no daily_contact_credits stores NULL (unlimited contact meter).
     */
    public function test_package_store_accepts_null_contact_credits_as_unlimited(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/packages', [
                'name'          => 'Pack Unlimited Contact',
                'daily_credits' => 50,
                'is_active'     => true,
                'sort_order'    => 1,
            ]);

        $response->assertStatus(200);

        $package = \App\Models\Package::where('name', 'Pack Unlimited Contact')->first();
        $this->assertNotNull($package, 'Package row must be created');
        $this->assertNull($package->daily_contact_credits,
            'daily_contact_credits must be NULL when not provided (unlimited)');
    }
}
