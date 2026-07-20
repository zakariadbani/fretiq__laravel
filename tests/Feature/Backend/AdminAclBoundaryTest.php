<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminAclBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const SUPERADMIN_ONLY_PERMISSIONS = [
        'manage packages',
        'view provider quota',
        'manage roles',
        'manage permissions',
        'view settings',
        'edit settings',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_seeded_superadmin_has_all_six_restricted_permissions_and_admin_has_none(): void
    {
        $superadmin = Role::findByName('superadmin');
        $admin = Role::findByName('admin');

        foreach (self::SUPERADMIN_ONLY_PERMISSIONS as $permission) {
            $this->assertTrue($superadmin->hasPermissionTo($permission));
            $this->assertFalse($admin->hasPermissionTo($permission));
        }
    }

    public function test_seeded_admin_excludes_exactly_the_six_superadmin_only_permissions(): void
    {
        $allPermissions = Permission::query()->pluck('name')->sort()->values();
        $adminPermissions = Role::findByName('admin')->permissions->pluck('name')->sort()->values();

        $this->assertSame(
            collect(self::SUPERADMIN_ONLY_PERMISSIONS)->sort()->values()->all(),
            $allPermissions->diff($adminPermissions)->values()->all(),
        );
    }

    public function test_seeded_admin_is_forbidden_from_all_superadmin_only_pages(): void
    {
        $restrictedPages = [
            route('user-management.roles.index'),
            route('user-management.permissions.index'),
            route('admin.settings.index'),
            '/admin/observability',
            route('admin.packages.index'),
            route('admin.provider-quota.index'),
        ];

        foreach ($restrictedPages as $uri) {
            $this->actingAs($this->admin)
                ->get($uri)
                ->assertForbidden();
        }
    }
}
