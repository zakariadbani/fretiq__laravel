<?php

namespace Tests\Feature\Backend;

use App\Models\Segment;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:segments-code

/**
 * SegmentGeneratedTest — gap-fill test slice for the segments module.
 *
 * Existing coverage (DO NOT duplicate):
 *   SegmentsTest:              index 200, create 200, datatable AJAX, store happy-path.
 *   SegmentCrudTest:           store structured/multi/empty/no-filter, update filter/clear,
 *                              validation (name/scope/invalid-scope/bad-country/sector>20/bad-status),
 *                              commercial can store, guest store.
 *   SegmentPreviewTest:        ALL preview cases (auth, response structure, funnel math,
 *                              validation, sample ≤10, sample keys, sample order, summary,
 *   SegmentFilterPipelineTest: service-level 6-stage pipeline (scope/filter/D11a/D11b/
 *                              suppression/cold-gate/personal-exclusion/resolve parity).
 *   SegmentResolveTest:        service-level resolve() (client/prospect/mixed/suppressed/
 *                              soft-deleted).
 *
 * This file covers the uncovered surface:
 *   - index 403 for user without `view segments`
 *   - index guest redirect (GET /admin/segments)
 *   - view (detail) 200 for existing segment
 *   - edit 200 for existing segment
 *   - store happy-path JSON contract: {message:"success"} + assertDatabaseHas
 *   - update happy-path JSON contract: {message:"success"} + assertDatabaseHas
 *   - delete happy-path: JSON {success:true} + DB row gone
 *   - delete 403 for commercial role (no `delete segments` permission)
 *   - executeSwitch always-403: SegmentController::$toggleableFields is absent
 *     (resolves to []) so every executeSwitch call is rejected with 403, regardless
 *     of which field is requested. Documented as a single always-403 test.
 */
class SegmentGeneratedTest extends TestCase
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
     * Insert a segment directly without going through the controller.
     */
    private function makeSegment(array $overrides = []): Segment
    {
        return Segment::create(array_merge([
            'name'  => 'Segment Test ' . uniqid(),
            'scope' => 'client',
        ], $overrides));
    }

    // ── Access control — index ────────────────────────────────────────────────

    /**
     * Unauthenticated GET /admin/segments must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/segments');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view segments` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view segments`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/segments');

        $response->assertStatus(403);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/segments/{id} returns 200 for an existing segment.
     *
     * SegmentController::view() overrides the Crudable default to inject
     * stats from SegmentService::resolveWithStats(). The response must be 200
     * with the view rendered (not a redirect).
     */
    public function test_view_renders_for_existing_segment(): void
    {
        $segment = $this->makeSegment(['scope' => 'client']);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/segments/' . $segment->id);

        $response->assertStatus(200);
        $response->assertSee('Destinataires éligibles', false);
        $response->assertSee('destinataires éligibles', false);
    }

    // ── Edit page ─────────────────────────────────────────────────────────────

    /**
     * GET /admin/segments/{id}/edit returns 200 for an existing segment.
     *
     * SegmentController::edit() overrides the Crudable default to manually
     * build the view with stale-merge vars and stats. Must render 200.
     */
    public function test_edit_renders_for_existing_segment(): void
    {
        $segment = $this->makeSegment(['scope' => 'prospect']);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/segments/' . $segment->id . '/edit');

        $response->assertStatus(200);
    }

    // ── Store happy-path (JSON contract) ─────────────────────────────────────

    /**
     * POST /admin/segments with valid data returns 200 JSON {message:"success"}
     * and persists the row.
     *
     * SegmentsTest already asserts 200 + assertDatabaseHas; this test adds the
     * explicit JSON contract check (message:"success") and a filter-less store.
     */
    public function test_store_returns_success_json_and_persists_row(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'  => 'Prospects Mixed No Filter',
                'scope' => 'mixed',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('segments', [
            'name'  => 'Prospects Mixed No Filter',
            'scope' => 'mixed',
        ]);
    }

    // ── Update happy-path (JSON contract) ────────────────────────────────────

    /**
     * PUT /admin/segments/{id} with valid data returns 200 JSON {message:"success"}
     * and mutates the row.
     *
     * SegmentCrudTest::test_update_changes_filter() already asserts 200 + DB; this
     * test adds the explicit JSON contract assertion.
     */
    public function test_update_returns_success_json_and_mutates_row(): void
    {
        $segment = $this->makeSegment(['name' => 'Avant Mise à Jour', 'scope' => 'client']);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/segments/' . $segment->id, [
                'name'  => 'Après Mise à Jour',
                'scope' => 'prospect',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('segments', [
            'id'    => $segment->id,
            'name'  => 'Après Mise à Jour',
            'scope' => 'prospect',
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/segments/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_segment(): void
    {
        $segment = $this->makeSegment();
        $id      = $segment->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/segments/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('segments', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete segments` permission — must receive 403.
     *
     * The commercial role has view/create/edit segments but NOT delete.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $segment = $this->makeSegment();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/segments/' . $segment->id);

        $response->assertStatus(403);
        // The row must still exist.
        $this->assertDatabaseHas('segments', ['id' => $segment->id]);
    }

    // ── executeSwitch — always 403 ────────────────────────────────────────────

    /**
     * PUT /admin/segments/executeSwitch/{id} always returns 403.
     *
     * SegmentController does NOT declare a $toggleableFields property.
     * Datatableable::executeSwitch() reads `$this->toggleableFields ?? []`, which
     * resolves to an empty array, so every field name fails the whitelist check
     * and the method returns JSON 403. This is intentional — segments have no
     * toggleable boolean fields at this time.
     */
    public function test_execute_switch_always_403_because_no_toggleable_fields(): void
    {
        $segment = $this->makeSegment();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/segments/executeSwitch/' . $segment->id, [
                'field' => 'is_active',  // any field — all are rejected
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }
}

// <<<
