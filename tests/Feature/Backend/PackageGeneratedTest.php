<?php

namespace Tests\Feature\Backend;

use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:packages-code

/**
 * PackageGeneratedTest — gap-fill test slice for the packages module.
 *
 * Existing coverage (DO NOT duplicate):
 *   PackageModuleTest: index 200 (superadmin), index 403 (admin without manage packages),
 *                      view 200 (superadmin), view 403 (admin), edit 200 (superadmin), edit 403 (admin).
 *
 * This file covers the uncovered surface:
 *   - index guest redirect
 *   - index 403 for commercial role (no manage packages)
 *   - store happy-path: row persisted, JSON 200 {message:'success'}
 *   - store validation failure: missing required `name` → 406
 *   - store validation failure: negative daily_credits → 406
 *   - update happy-path: row mutated, JSON 200 {message:'success'}
 *   - delete happy-path: JSON {success:true} + DB row gone
 *   - delete 403 for commercial role (no manage packages)
 *   - executeSwitch sets is_active → active (state='1')
 *   - executeSwitch sets is_active → inactive (state='0')
 *   - executeSwitch rejects unlisted field (403)
 *   - assign happy-path: PackageAssignment row created, assigned_by = auth user, redirect
 *   - assign validation failure: missing package_id → redirect with validation error
 *   - assign permission gate: commercial cannot call assign (403 — no manage packages)
 */
class PackageGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase does NOT run seeders; seed ACL manually.
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

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
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Insert a Package row directly (no factory exists for this model).
     */
    private function makePackage(array $overrides = []): Package
    {
        static $counter = 0;
        $counter++;

        return Package::create(array_merge([
            'name'          => 'Pack Test ' . $counter,
            'daily_credits' => 50,
            'price_monthly' => 49.00,
            'is_active'     => true,
            'sort_order'    => $counter,
        ], $overrides));
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Unauthenticated request to packages index must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/packages');

        $response->assertRedirect();
    }

    /**
     * Commercial role does NOT have `manage packages` — must receive 403 on index.
     */
    public function test_index_403_for_commercial_role(): void
    {
        $response = $this->actingAs($this->commercial)
            ->get('/admin/packages');

        $response->assertStatus(403);
    }

    // ── Store happy-path ──────────────────────────────────────────────────────

    /**
     * POST /admin/packages with valid data persists the row and returns JSON 200 {message:'success'}.
     *
     * Crudable::store() returns JSON 200 with a `redirect` field (not an HTTP 3xx).
     */
    public function test_store_creates_package(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/packages', [
                'name'          => 'Pack Découverte',
                'daily_credits' => 100,
                'price_monthly' => 79.00,
                'is_active'     => true,
                'sort_order'    => 1,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('packages', [
            'name'          => 'Pack Découverte',
            'daily_credits' => 100,
        ]);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `name` is missing (required rule).
     *
     * Package::rules(): name => 'required|string|max:255'
     * Crudable calls $model->validator() and returns JSON 406 on failure.
     */
    public function test_store_validation_fails_when_name_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/packages', [
                // name intentionally omitted
                'daily_credits' => 50,
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    /**
     * Store must return 406 when `daily_credits` violates the min:0 rule.
     *
     * Package::rules(): daily_credits => 'nullable|integer|min:0'
     */
    public function test_store_validation_fails_on_negative_daily_credits(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/packages', [
                'name'          => 'Pack Invalide',
                'daily_credits' => -5,
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['daily_credits']]);
        $this->assertArrayHasKey('daily_credits', $response->json('errors'));
    }

    /**
     * Store must return 406 when `daily_contact_credits` violates the min:0 rule.
     *
     * Package::rules(): daily_contact_credits => 'nullable|integer|min:0'
     */
    public function test_store_validation_fails_on_negative_daily_contact_credits(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/packages', [
                'name'                  => 'Pack Invalide',
                'daily_contact_credits' => -3,
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['daily_contact_credits']]);
        $this->assertArrayHasKey('daily_contact_credits', $response->json('errors'));
    }

    // ── Update happy-path ─────────────────────────────────────────────────────

    /**
     * PUT /admin/packages/{id} with valid data mutates the row and returns JSON 200.
     */
    public function test_update_mutates_package(): void
    {
        $package = $this->makePackage(['name' => 'Pack Original', 'daily_credits' => 30]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/packages/' . $package->id, [
                'name'          => 'Pack Modifié',
                'daily_credits' => 75,
                'price_monthly' => 59.00,
                'is_active'     => true,
                'sort_order'    => 1,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('packages', [
            'id'            => $package->id,
            'name'          => 'Pack Modifié',
            'daily_credits' => 75,
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/packages/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_package(): void
    {
        $package = $this->makePackage();
        $id      = $package->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/packages/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('packages', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `manage packages` — must receive 403 on delete.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $package = $this->makePackage();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/packages/' . $package->id);

        $response->assertStatus(403);
        // The row must still exist.
        $this->assertDatabaseHas('packages', ['id' => $package->id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/packages/executeSwitch/{id} with field=is_active, state='1' sets active.
     *
     * State sent as a STRING to match real browser AJAX (form values post as strings).
     * PackageController::$toggleableFields = ['is_active']
     */
    public function test_execute_switch_sets_active(): void
    {
        $package = $this->makePackage(['is_active' => false]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/packages/executeSwitch/' . $package->id, [
                'field' => 'is_active',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('packages', ['id' => $package->id, 'is_active' => 1]);
    }

    /**
     * PUT /admin/packages/executeSwitch/{id} with field=is_active, state='0' sets inactive.
     *
     * Isolated per-direction with its own fresh package.
     */
    public function test_execute_switch_sets_inactive(): void
    {
        $package = $this->makePackage(['is_active' => true]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/packages/executeSwitch/' . $package->id, [
                'field' => 'is_active',
                'state' => '0',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('packages', ['id' => $package->id, 'is_active' => 0]);
    }

    /**
     * executeSwitch must return 403 when an unlisted field is requested.
     *
     * PackageController::$toggleableFields = ['is_active']; any other field → 403.
     */
    public function test_execute_switch_rejects_unlisted_field(): void
    {
        $package = $this->makePackage();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/packages/executeSwitch/' . $package->id, [
                'field' => 'name',   // not in toggleableFields
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }

    // ── Assign action ─────────────────────────────────────────────────────────

    /**
     * POST /admin/packages/assign with a valid package_id creates a PackageAssignment row.
     *
     * assign() validates package_id, creates PackageAssignment{package_id, assigned_by=auth()->id()},
     * flashes success, then returns redirect()->back() (HTTP redirect, not JSON).
     * The PackageAssignment row is asserted in package_assignments table.
     */
    public function test_assign_creates_package_assignment_row(): void
    {
        $package = $this->makePackage();

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/packages/assign', [
                'package_id' => $package->id,
            ]);

        // assign() returns redirect()->back() (302), not JSON.
        $response->assertRedirect();

        // The assignment row must have been created with the correct foreign keys.
        $this->assertDatabaseHas('package_assignments', [
            'package_id'  => $package->id,
            'assigned_by' => $this->superadmin->id,
        ]);
    }

    /**
     * REAL-CONTRACT TEST: assign() validates package_id BEFORE writing.
     *
     * assign() runs $request->validate(['package_id' => 'required|integer|exists:packages,id'])
     * and only calls PackageAssignment::create() AFTER validation passes. A JSON request that
     * fails validation gets Laravel's standard 422 with a `package_id` error key and NO row is
     * written. We assert via postJson() to get the deterministic 422 + JSON errors envelope
     * (a plain post() would 302-redirect "back", which is host/referer-dependent and flaky).
     *
     * This single test documents both failure modes (missing AND non-existent package_id) since
     * the `required` and `exists` rules share one error key and one short-circuit path. The
     * former separate `..._not_found` test was redundant and has been removed.
     */
    public function test_assign_validates_package_id_before_writing(): void
    {
        // The migration seeds 1 default package_assignments row, so the table is
        // never empty. Capture the baseline and assert no NEW row is written.
        $before = PackageAssignment::count();

        // Missing package_id → `required` fails.
        $missing = $this->actingAs($this->superadmin)
            ->postJson('/admin/packages/assign', [
                // package_id intentionally omitted
            ]);

        $missing->assertStatus(422);
        $missing->assertJsonValidationErrors(['package_id']);

        // Non-existent package_id → `exists` fails.
        $notFound = $this->actingAs($this->superadmin)
            ->postJson('/admin/packages/assign', [
                'package_id' => 99999,   // no such package row
            ]);

        $notFound->assertStatus(422);
        $notFound->assertJsonValidationErrors(['package_id']);

        // Neither rejected request wrote an assignment row.
        $this->assertSame($before, PackageAssignment::count(), 'rejected assign must not create an assignment');
    }

    /**
     * assign route is guarded by `permission:manage packages`.
     *
     * The PackageController constructor registers the gate for assign alongside the
     * other write actions. A commercial user (no `manage packages` permission) must
     * receive 403 and NO PackageAssignment row may be written for them.
     */
    public function test_assign_denied_for_commercial_without_manage_packages(): void
    {
        $package = $this->makePackage();

        $response = $this->actingAs($this->commercial)
            ->post('/admin/packages/assign', [
                'package_id' => $package->id,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('package_assignments', [
            'package_id'  => $package->id,
            'assigned_by' => $this->commercial->id,
        ]);
    }
}

// <<<
