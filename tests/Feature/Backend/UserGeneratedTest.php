<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:users-code

/**
 * UserGeneratedTest — gap-fill test slice for the users module.
 *
 * Existing coverage (DO NOT duplicate):
 *   UserManagementGatingTest: unauthenticated 302/401/403/405 smoke for all user routes.
 *
 * This file covers the uncovered surface (all authenticated paths):
 *   - index 200 with view users + backend.access; 403 without; guest → 302
 *   - store: valid create persists + assigns role (200 JSON)
 *   - store validation failures: duplicate email → 422; missing name → 422; superadmin role → 422
 *   - SECURITY: assigning superadmin role rejected (422 from not_in:superadmin rule)
 *   - SECURITY: self-delete rejected (403 JSON {success:false})
 *   - SECURITY: self-deactivation rejected (422 JSON {errors.is_active})
 *   - SECURITY: self-role-change rejected (422 JSON {errors.role})
 *   - SECURITY: editing a superadmin target is blocked (abort 403)
 *   - update happy-path (non-self target) → 200 JSON
 *   - delete happy-path (non-self, non-last-admin target) → 200 JSON {success:true} + DB row gone
 *   - last-admin lockout on delete → 403 JSON {success:false}
 *   - commercial role → 403 on index, store, update, delete (no users permissions)
 *
 * Architecture note:
 *   UserController uses explicit $request->validate() — validation failures are 422
 *   (NOT 406 Crudable trait path). Security guards inside update() return 422 JSON.
 *   self-delete guard is inside delete() and returns 403 JSON.
 */
class UserGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $admin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase does NOT run seeders — seed ACL manually.
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Superadmin — has all permissions.
        $this->superadmin = User::factory()->create([
            'email'             => 'sa-test@fretiq.test',
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        // Admin — has all permissions (incl. view/create/edit/delete users).
        $this->admin = User::factory()->create([
            'email'             => 'admin-test@fretiq.test',
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->admin->assignRole('admin');

        // Commercial — has backend.access but NO users permissions.
        $this->commercial = User::factory()->create([
            'email'             => 'commercial-test@fretiq.test',
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a target user (admin role) that can be safely edited/deleted
     * by the acting user in tests.
     */
    private function makeTargetUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'email'             => 'target-' . uniqid() . '@fretiq.test',
            'email_verified_at' => now(),
            'is_active'         => true,
        ], $overrides));
        $user->assignRole('admin');
        return $user;
    }

    /**
     * Valid store payload with safe defaults.
     */
    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Nouveau User ' . uniqid(),
            'email'                 => 'new-' . uniqid() . '@fretiq.test',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
            'role'                  => 'commercial',
            'is_active'             => true,
        ], $overrides);
    }

    // ── Access control (index) ────────────────────────────────────────────────

    /**
     * Unauthenticated GET /admin/users must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/users');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view users` must receive 403.
     */
    public function test_index_403_for_user_without_view_users_permission(): void
    {
        // commercial has backend.access but no view users
        $response = $this->actingAs($this->commercial)
            ->get('/admin/users');

        $response->assertStatus(403);
    }

    /**
     * Superadmin (has view users + backend.access) sees the index at 200.
     */
    public function test_index_200_for_superadmin(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/users');

        $response->assertStatus(200);
    }

    /**
     * Admin role (has view users) also sees the index at 200.
     */
    public function test_index_200_for_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/users');

        $response->assertStatus(200);
    }

    // ── Store — happy path ────────────────────────────────────────────────────

    /**
     * POST /admin/users with valid data:
     *   - returns 200 JSON with message:'success'
     *   - persists the user row in DB
     *   - assigns the requested role
     */
    public function test_store_creates_user_and_assigns_role(): void
    {
        $payload = $this->validStorePayload(['role' => 'commercial']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/users', $payload);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        $this->assertDatabaseHas('users', ['email' => $payload['email']]);

        $created = User::where('email', $payload['email'])->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole('commercial'));
    }

    // ── Store — validation failures (all return 422) ──────────────────────────

    /**
     * store must return 422 when `name` is missing.
     */
    public function test_store_422_when_name_missing(): void
    {
        $payload = $this->validStorePayload(['name' => '']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/users', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * store must return 422 when email already exists.
     */
    public function test_store_422_on_duplicate_email(): void
    {
        $existing = $this->makeTargetUser(['email' => 'dupe@fretiq.test']);

        $payload = $this->validStorePayload(['email' => 'dupe@fretiq.test']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/users', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    /**
     * store must return 422 when password_confirmation does not match.
     */
    public function test_store_422_on_password_mismatch(): void
    {
        $payload = $this->validStorePayload([
            'password'              => 'secret123',
            'password_confirmation' => 'different999',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/users', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }

    // ── SECURITY: superadmin role assignment blocked (store) ──────────────────

    /**
     * SECURITY GUARD: assigning the 'superadmin' role via store must be rejected.
     *
     * The `role` field has rule `not_in:superadmin` so $request->validate()
     * returns 422 JSON with a validation error on 'role'.
     */
    public function test_store_422_when_superadmin_role_assigned(): void
    {
        $payload = $this->validStorePayload(['role' => 'superadmin']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/users', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);

        // Ensure no user was created with that email.
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    // ── SECURITY: superadmin role assignment blocked (update) ─────────────────

    /**
     * SECURITY GUARD: assigning the 'superadmin' role via update must be rejected.
     *
     * Same `not_in:superadmin` rule on 'role' in update() validation.
     */
    public function test_update_422_when_superadmin_role_assigned(): void
    {
        $target = $this->makeTargetUser(); // currently admin role

        $response = $this->actingAs($this->superadmin)
            ->putJson('/admin/users/' . $target->id, [
                'name'      => $target->name,
                'email'     => $target->email,
                'role'      => 'superadmin',   // blocked
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);

        // Role must not have changed.
        $this->assertTrue($target->fresh()->hasRole('admin'));
    }

    // ── SECURITY: editing a superadmin target is blocked ─────────────────────

    /**
     * SECURITY GUARD: PUT to update a user who already has the superadmin role
     * must abort(403).
     *
     * The guard checks $user->hasRole('superadmin') before validation and calls abort(403).
     */
    public function test_update_403_when_target_is_superadmin(): void
    {
        // The superadmin created in setUp is itself a superadmin target.
        $response = $this->actingAs($this->admin)
            ->putJson('/admin/users/' . $this->superadmin->id, [
                'name'      => 'Hacked Name',
                'email'     => $this->superadmin->email,
                'role'      => 'admin',
                'is_active' => true,
            ]);

        $response->assertStatus(403);
    }

    /**
     * SECURITY GUARD: GET edit form for a superadmin also abort(403).
     */
    public function test_edit_form_403_when_target_is_superadmin(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/users/' . $this->superadmin->id . '/edit');

        $response->assertStatus(403);
    }

    // ── SECURITY: self-role-change blocked ───────────────────────────────────

    /**
     * SECURITY GUARD: a user cannot change their own role.
     *
     * The update() guard checks `$isSelf && $newRole !== $currRole`
     * and returns 422 JSON with errors.role.
     */
    public function test_update_422_on_self_role_change(): void
    {
        // admin tries to change their own role to commercial.
        $response = $this->actingAs($this->admin)
            ->putJson('/admin/users/' . $this->admin->id, [
                'name'      => $this->admin->name,
                'email'     => $this->admin->email,
                'role'      => 'commercial',   // different from current 'admin'
                'is_active' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.role.0', fn ($v) => str_contains($v, 'rôle'));
    }

    // ── SECURITY: self-deactivation blocked ──────────────────────────────────

    /**
     * SECURITY GUARD: a user cannot deactivate their own account.
     *
     * The update() guard checks `$isSelf && $newIsActive === false`
     * and returns 422 JSON with errors.is_active.
     */
    public function test_update_422_on_self_deactivation(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson('/admin/users/' . $this->admin->id, [
                'name'      => $this->admin->name,
                'email'     => $this->admin->email,
                'role'      => 'admin',         // same role — no role-change guard triggered
                'is_active' => false,            // self-deactivation
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.is_active.0', fn ($v) => str_contains($v, 'désactiver'));
    }

    // ── SECURITY: self-deletion blocked ──────────────────────────────────────

    /**
     * SECURITY GUARD: a user cannot delete themselves.
     *
     * delete() checks `(int) $id === Auth::id()` BEFORE finding the user
     * and returns 403 JSON {success:false, msg:...}.
     */
    public function test_delete_403_for_self_deletion(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson('/admin/users/' . $this->admin->id);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);

        // Admin must still exist.
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    // ── SECURITY: last-admin lockout on delete ────────────────────────────────

    /**
     * SECURITY GUARD: cannot delete the last active admin.
     *
     * Scenario: only one admin-role user exists (the target). Attempting to delete
     * it must return 403 JSON {success:false}.
     *
     * We need a separate actor who can delete but is NOT the last admin.
     * Use superadmin as actor (has delete users), delete a DIFFERENT admin
     * when that admin is the only remaining active admin/superadmin besides superadmin.
     *
     * Re-framing: make a fresh non-admin actor with delete users permission,
     * and make the target the only remaining admin so the lockout fires.
     */
    public function test_delete_403_for_last_active_admin(): void
    {
        // Create a user with only delete users + backend.access (not an admin).
        $actor = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $actor->givePermissionTo(['backend.access', 'delete users']);

        // Create an admin target. At this point: superadmin (setUp) + this.admin + target
        // are all active admins/superadmins. We need target to be the LAST one.
        // Deactivate superadmin and this.admin so only the target remains active-admin.
        $this->superadmin->update(['is_active' => false]);
        $this->admin->update(['is_active' => false]);

        $lastAdmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $lastAdmin->assignRole('admin');

        // Now lastAdmin is the only active admin/superadmin.
        $response = $this->actingAs($actor)
            ->deleteJson('/admin/users/' . $lastAdmin->id);

        $response->assertStatus(403);
        $response->assertJson(['success' => false]);

        // The last admin must still exist.
        $this->assertDatabaseHas('users', ['id' => $lastAdmin->id]);
    }

    // ── Update — happy path ───────────────────────────────────────────────────

    /**
     * PUT /admin/users/{id} with valid data on a non-self target:
     *   - returns 200 JSON with message:'success'
     *   - persists the updated name in DB
     *   - syncs the role
     */
    public function test_update_happy_path_for_non_self_target(): void
    {
        $target = $this->makeTargetUser(); // currently admin role

        $response = $this->actingAs($this->superadmin)
            ->putJson('/admin/users/' . $target->id, [
                'name'      => 'Updated Name',
                'email'     => $target->email,
                'role'      => 'commercial',
                'is_active' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        $this->assertDatabaseHas('users', [
            'id'   => $target->id,
            'name' => 'Updated Name',
        ]);
        $this->assertTrue($target->fresh()->hasRole('commercial'));
    }

    // ── Delete — happy path ───────────────────────────────────────────────────

    /**
     * DELETE /admin/users/{id} on a non-self, non-last-admin target:
     *   - returns 200 JSON {success:true}
     *   - removes the user row from DB (hard delete — User has no SoftDeletes)
     */
    public function test_delete_removes_non_self_target(): void
    {
        $target = $this->makeTargetUser(); // admin role, but not the last active admin
        $id     = $target->id;

        $response = $this->actingAs($this->superadmin)
            ->deleteJson('/admin/users/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Hard delete — the row must be gone.
        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    // ── Commercial role — 403 on all user-management actions ─────────────────

    /**
     * Commercial role has NO users permissions at all.
     * POST store must return 403 (middleware blocks before controller).
     */
    public function test_commercial_cannot_store_user(): void
    {
        $payload = $this->validStorePayload();

        $response = $this->actingAs($this->commercial)
            ->postJson('/admin/users', $payload);

        $response->assertStatus(403);
    }

    /**
     * Commercial role has NO edit users permission — PUT update must return 403.
     */
    public function test_commercial_cannot_update_user(): void
    {
        $target = $this->makeTargetUser();

        $response = $this->actingAs($this->commercial)
            ->putJson('/admin/users/' . $target->id, [
                'name'      => $target->name,
                'email'     => $target->email,
                'role'      => 'commercial',
                'is_active' => true,
            ]);

        $response->assertStatus(403);
    }

    /**
     * Commercial role has NO delete users permission — DELETE must return 403.
     */
    public function test_commercial_cannot_delete_user(): void
    {
        $target = $this->makeTargetUser();

        $response = $this->actingAs($this->commercial)
            ->deleteJson('/admin/users/' . $target->id);

        $response->assertStatus(403);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/users/{id} returns 200 for a user with view users permission.
     */
    public function test_view_renders_for_existing_user(): void
    {
        $target = $this->makeTargetUser();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/users/' . $target->id);

        $response->assertStatus(200);
        // name is rendered in the <title> section and the breadcrumb span.
        $response->assertSee($target->name);
        // email is available; assert it too to cover a distinct field.
        $response->assertSee($target->email);
    }
}

// <<<
