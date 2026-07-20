<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// >>> custom-test-author:roles_permissions-code

/**
 * RolePermissionGeneratedTest — gap-fill test slice for the roles_permissions module.
 *
 * Existing coverage (DO NOT duplicate):
 *   - UserManagementGatingTest: unauthenticated requests return 302/401/403/405/419 for all
 *     roles and permissions resource routes.
 *   - PermissionsTest: commercial cannot delete company; commercial cannot view users;
 *     admin can load users index; users DataTable ajax draw.
 *
 * This file covers:
 * ROLES:
 *   - roles index: 200 for superadmin
 *   - roles index: 403 for commercial (no `manage roles` permission)
 *   - store: creates a new custom role (persists in DB)
 *   - store: validates required name (422 on missing)
 *   - store: rejects duplicate role name (422)
 *   - update (rename): rejected for system role `superadmin` → JSON 403 + `success=false`
 *   - update (rename): rejected for system role `admin`
 *   - update (rename): rejected for system role `commercial`
 *   - update (sync perms): allowed on system role when name unchanged
 *   - update: custom role rename succeeds
 *   - destroy: system role `superadmin` rejected → JSON 403 + `success=false`
 *   - destroy: custom role without users deleted → JSON `success=true`
 *   - destroy: custom role with assigned users blocked → JSON 403
 *   - permissions() AJAX: returns JSON `{permissions: [...]}` for a role
 *   - edit() AJAX: returns JSON `{role: {...}, permissions: [...]}` for a role
 *   - edit() non-AJAX: 404
 *   - privilege escalation guard: non-superadmin admin cannot assign `manage roles` perm
 *
 * PERMISSIONS:
 *   - permissions index: 200 for superadmin
 *   - permissions index: 403 for commercial (no `manage permissions`)
 *   - store: creates new custom permission (persists, lowercased)
 *   - store: validates required name (422 on missing)
 *   - store: rejects duplicate permission name (422)
 *   - update (rename): system permission `backend.access` rejected → JSON 403 + `success=false`
 *   - update (rename): system permission `manage roles` rejected
 *   - update: custom permission rename succeeds
 *   - destroy: system permission `backend.access` rejected → JSON 403 + `success=false`
 *   - destroy: custom permission deleted → JSON `success=true`
 *   - edit() AJAX: returns JSON `{permission: {...}}` for the modal pre-fill
 *   - edit() non-AJAX: 404
 *   - permission gate: user without `manage permissions` gets 403 on store
 */
class RolePermissionGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;
    /** An admin-level user that has `manage roles` + `manage permissions` but is NOT superadmin. */
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase does NOT run seeders; seed ACL manually.
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Flush Spatie permission cache after seeding so assertions see fresh state.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo(['manage roles', 'manage permissions']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Make an AJAX-style POST/PUT/DELETE request (simulates jQuery $.ajax).
     * The controller checks request()->ajax() via X-Requested-With header.
     */
    private function ajaxPost(string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post($url, $data);
    }

    private function ajaxPut(string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->put($url, $data);
    }

    private function ajaxDelete(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->delete($url);
    }

    /** Create a custom (non-system) role directly in DB. */
    private function makeCustomRole(string $name = 'custom-role'): Role
    {
        return Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    /** Create a custom (non-system) permission directly in DB. */
    private function makeCustomPermission(string $name = 'view custom_module'): Permission
    {
        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ROLES
    // ══════════════════════════════════════════════════════════════════════════

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_roles_index_returns_200_for_superadmin(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(route('user-management.roles.index'));

        $response->assertOk();
    }

    public function test_roles_index_returns_403_for_commercial(): void
    {
        // Commercial does not have `manage roles` — middleware blocks at HTTP layer.
        $this->assertFalse($this->commercial->can('manage roles'));

        $response = $this->actingAs($this->commercial)
            ->get(route('user-management.roles.index'));

        $response->assertStatus(403);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_role_creates_new_custom_role(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->ajaxPost(route('user-management.roles.store'), [
                'name' => 'editor',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('roles', ['name' => 'editor']);
    }

    public function test_store_role_with_permissions_assigns_them(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->ajaxPost(route('user-management.roles.store'), [
                'name'        => 'viewer',
                'permissions' => ['view companies', 'view contacts'],
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $role = Role::where('name', 'viewer')->first();
        $this->assertNotNull($role);
        $this->assertTrue($role->hasPermissionTo('view companies'));
        $this->assertTrue($role->hasPermissionTo('view contacts'));
    }

    public function test_store_role_validates_missing_name(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson(route('user-management.roles.store'), []);

        // Laravel's FormRequest / $request->validate() returns 422 JSON when the
        // request is AJAX (X-Requested-With: XMLHttpRequest) because Laravel's
        // RedirectIfAuthenticated / ValidationException logic checks
        // $request->expectsJson() — XHR requests satisfy that check.
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_role_rejects_duplicate_name(): void
    {
        $this->makeCustomRole('reports');

        $response = $this->actingAs($this->superadmin)
            ->postJson(route('user-management.roles.store'), ['name' => 'reports']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    // ── Update (rename / permissions) ─────────────────────────────────────────

    public function test_update_rejects_rename_of_system_role_superadmin(): void
    {
        $superadminRole = Role::where('name', 'superadmin')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.roles.update', $superadminRole), [
                'name' => 'super-renamed',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        // Role name must not have changed in DB.
        $this->assertDatabaseHas('roles', ['id' => $superadminRole->id, 'name' => 'superadmin']);
    }

    public function test_update_rejects_rename_of_system_role_admin(): void
    {
        $adminRole = Role::where('name', 'admin')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.roles.update', $adminRole), [
                'name' => 'admin-renamed',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('roles', ['id' => $adminRole->id, 'name' => 'admin']);
    }

    public function test_update_rejects_rename_of_system_role_commercial(): void
    {
        $commercialRole = Role::where('name', 'commercial')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.roles.update', $commercialRole), [
                'name' => 'commercial-renamed',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('roles', ['id' => $commercialRole->id, 'name' => 'commercial']);
    }

    public function test_update_allows_permission_sync_on_system_role_without_rename(): void
    {
        // Updating a system role without changing its name (just syncing perms) must succeed.
        $adminRole = Role::where('name', 'admin')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.roles.update', $adminRole), [
                'name'        => 'admin',   // same name — not a rename
                'permissions' => ['view companies'],
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
    }

    public function test_update_renames_custom_role(): void
    {
        $role = $this->makeCustomRole('old-name');

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.roles.update', $role), [
                'name' => 'new-name',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'new-name']);
    }

    public function test_update_privilege_escalation_guard_blocks_non_superadmin(): void
    {
        // Admin user (has `manage roles`) tries to assign `manage roles` permission to a custom role.
        // The privilege escalation guard should block this with 403.
        $role = $this->makeCustomRole('escalation-target');

        $response = $this->actingAs($this->admin)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept'           => 'application/json',
            ])
            ->put(route('user-management.roles.update', $role), [
                'name'        => 'escalation-target',
                'permissions' => ['manage roles'],
            ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_rejects_system_role_superadmin(): void
    {
        $superadminRole = Role::where('name', 'superadmin')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxDelete(route('user-management.roles.destroy', $superadminRole));

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('roles', ['name' => 'superadmin']);
    }

    public function test_destroy_rejects_role_with_assigned_users(): void
    {
        $role = $this->makeCustomRole('role-with-users');

        // Assign a user to this role so the users-assigned guard fires.
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole($role);

        $response = $this->actingAs($this->superadmin)
            ->ajaxDelete(route('user-management.roles.destroy', $role));

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_destroy_deletes_custom_role_without_users(): void
    {
        $role = $this->makeCustomRole('deletable-role');
        $roleId = $role->id;

        $response = $this->actingAs($this->superadmin)
            ->ajaxDelete(route('user-management.roles.destroy', $role));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    // ── AJAX endpoints ────────────────────────────────────────────────────────

    public function test_permissions_ajax_returns_json_list_for_role(): void
    {
        $role = $this->makeCustomRole('perms-read-role');
        $permission = $this->makeCustomPermission('view test_entity');
        $role->givePermissionTo($permission);

        $response = $this->actingAs($this->superadmin)
            ->get(route('user-management.roles.permissions', $role));

        $response->assertOk();
        $response->assertJsonStructure(['permissions']);

        $permissions = $response->json('permissions');
        $this->assertIsArray($permissions);
        $this->assertContains('view test_entity', $permissions);
    }

    public function test_permissions_ajax_returns_empty_array_for_role_with_no_perms(): void
    {
        // Create a role with no permissions synced.
        $role = Role::create(['name' => 'empty-role', 'guard_name' => 'web']);

        $response = $this->actingAs($this->superadmin)
            ->get(route('user-management.roles.permissions', $role));

        $response->assertOk();
        $response->assertJson(['permissions' => []]);
    }

    public function test_edit_ajax_returns_role_and_permissions_json(): void
    {
        $role = $this->makeCustomRole('ajax-edit-role');
        $permission = $this->makeCustomPermission('edit test_entity');
        $role->givePermissionTo($permission);

        $response = $this->actingAs($this->superadmin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('user-management.roles.edit', $role));

        $response->assertOk();
        $response->assertJsonStructure(['role', 'permissions']);

        $permissions = $response->json('permissions');
        $this->assertContains('edit test_entity', $permissions);
    }

    public function test_edit_non_ajax_returns_404(): void
    {
        $role = $this->makeCustomRole('non-ajax-edit-role');

        // No X-Requested-With header — controller aborts with 404.
        $response = $this->actingAs($this->superadmin)
            ->get(route('user-management.roles.edit', $role));

        $response->assertStatus(404);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PERMISSIONS
    // ══════════════════════════════════════════════════════════════════════════

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_permissions_index_returns_200_for_superadmin(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(route('user-management.permissions.index'));

        $response->assertOk();
    }

    public function test_permissions_index_returns_403_for_commercial(): void
    {
        $this->assertFalse($this->commercial->can('manage permissions'));

        $response = $this->actingAs($this->commercial)
            ->get(route('user-management.permissions.index'));

        $response->assertStatus(403);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_permission_creates_new_custom_permission(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->ajaxPost(route('user-management.permissions.store'), [
                'name' => 'VIEW New Module',   // upper-case to verify lowercasing
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        // Controller lowercases the name before persisting.
        $this->assertDatabaseHas('permissions', ['name' => 'view new module']);
    }

    public function test_store_permission_validates_missing_name(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson(route('user-management.permissions.store'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_permission_rejects_duplicate_name(): void
    {
        $this->makeCustomPermission('view duplicate_perm');

        $response = $this->actingAs($this->superadmin)
            ->postJson(route('user-management.permissions.store'), [
                'name' => 'view duplicate_perm',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_store_permission_blocked_without_manage_permissions(): void
    {
        // Commercial does not have `manage permissions` — middleware returns 403.
        $response = $this->actingAs($this->commercial)
            ->ajaxPost(route('user-management.permissions.store'), [
                'name' => 'view some_entity',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('permissions', ['name' => 'view some_entity']);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_rejects_rename_of_system_permission_backend_access(): void
    {
        $perm = Permission::where('name', 'backend.access')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.permissions.update', $perm), [
                'name' => 'backend.access.renamed',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('permissions', ['id' => $perm->id, 'name' => 'backend.access']);
    }

    public function test_update_rejects_rename_of_system_permission_manage_roles(): void
    {
        $perm = Permission::where('name', 'manage roles')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.permissions.update', $perm), [
                'name' => 'manage roles renamed',
            ]);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('permissions', ['id' => $perm->id, 'name' => 'manage roles']);
    }

    public function test_update_renames_custom_permission(): void
    {
        $perm = $this->makeCustomPermission('view old_entity');

        $response = $this->actingAs($this->superadmin)
            ->ajaxPut(route('user-management.permissions.update', $perm), [
                'name' => 'view new_entity',
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('permissions', ['id' => $perm->id, 'name' => 'view new_entity']);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_rejects_system_permission_backend_access(): void
    {
        $perm = Permission::where('name', 'backend.access')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxDelete(route('user-management.permissions.destroy', $perm));

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseHas('permissions', ['name' => 'backend.access']);
    }

    public function test_destroy_rejects_system_permission_manage_permissions(): void
    {
        $perm = Permission::where('name', 'manage permissions')->first();

        $response = $this->actingAs($this->superadmin)
            ->ajaxDelete(route('user-management.permissions.destroy', $perm));

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);
    }

    public function test_destroy_deletes_custom_permission(): void
    {
        $perm = $this->makeCustomPermission('view deletable_module');
        $permId = $perm->id;

        $response = $this->actingAs($this->superadmin)
            ->ajaxDelete(route('user-management.permissions.destroy', $perm));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('permissions', ['id' => $permId]);
    }

    // ── AJAX edit endpoint ────────────────────────────────────────────────────

    public function test_permission_edit_ajax_returns_permission_json_for_modal(): void
    {
        $perm = $this->makeCustomPermission('edit ajax_module');

        $response = $this->actingAs($this->superadmin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(route('user-management.permissions.edit', $perm));

        $response->assertOk();
        $response->assertJsonStructure(['permission']);
        $this->assertEquals('edit ajax_module', $response->json('permission.name'));
    }

    public function test_permission_edit_non_ajax_returns_404(): void
    {
        $perm = $this->makeCustomPermission('edit non_ajax_module');

        $response = $this->actingAs($this->superadmin)
            ->get(route('user-management.permissions.edit', $perm));

        $response->assertStatus(404);
    }
}

// <<<
