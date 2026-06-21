<?php

namespace Tests\Feature\Backend;

use App\Models\Suppression;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:suppressions-code

/**
 * SuppressionGeneratedTest — gap-fill test slice for the suppressions module.
 *
 * Existing coverage (DO NOT duplicate):
 *   SuppressionControllerGatingTest: unauthenticated smoke only (assertContains 302/401/403/405).
 *
 * This file covers the uncovered surface:
 *   - index 200 (superadmin), 403 (no view permission), guest redirect (302)
 *   - store validation failures: missing email, invalid email format, duplicate email (unique)
 *   - store happy-path: row created + redirect in JSON
 *   - update happy-path: row mutated + redirect in JSON
 *   - view 200 for an existing suppression
 *   - delete happy-path: JSON {success:true} + DB row gone
 *   - delete 403 for commercial role
 *   - executeSwitch: SuppressionController defines NO $toggleableFields, so any field returns 403
 *     (the route exists; the trait guard fires immediately). Test that contract.
 *
 * executeSwitch note:
 *   SuppressionController has NO $toggleableFields property.
 *   Datatableable::executeSwitch() checks `$this->toggleableFields ?? []`; since the array is empty,
 *   every field is off-whitelist and the trait returns JSON 403. No sets_active/sets_inactive
 *   tests are authored because there is no toggleable field to set — only the rejection is testable.
 */
class SuppressionGeneratedTest extends TestCase
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
     * Create a suppression row directly (no factory — none exists for this model).
     */
    private function makeSuppression(array $overrides = []): Suppression
    {
        return Suppression::create(array_merge([
            'email'  => 'test-' . uniqid() . '@example.com',
            'reason' => 'manual',
            'source' => 'manual',
        ], $overrides));
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Unauthenticated request to suppressions index must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/suppressions');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view suppressions` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Grant only backend.access — no `view suppressions`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/suppressions');

        $response->assertStatus(403);
    }

    /**
     * GET /admin/suppressions returns 200 for superadmin (has all permissions).
     */
    public function test_index_200_for_superadmin(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/suppressions');

        $response->assertStatus(200);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/suppressions/{id} returns 200 for an existing suppression.
     */
    public function test_view_renders_for_existing_suppression(): void
    {
        $suppression = $this->makeSuppression();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/suppressions/' . $suppression->id);

        $response->assertStatus(200);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `email` is missing (required rule).
     *
     * Suppression.rules(): email => 'required|email|max:191|unique:suppressions,email,{id}'
     * Crudable::store() returns JSON 406 on validation failure.
     */
    public function test_store_validation_fails_when_email_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/suppressions', [
                // email intentionally omitted
                'reason' => 'manual',
                'source' => 'manual',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['email']]);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /**
     * Store must return 406 when `email` is not a valid email address.
     */
    public function test_store_validation_fails_on_invalid_email_format(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/suppressions', [
                'email'  => 'not-an-email',
                'reason' => 'manual',
                'source' => 'manual',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['email']]);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /**
     * Store must return 406 when `email` violates the unique constraint.
     *
     * Suppression.rules(): email => 'required|email|max:191|unique:suppressions,email,{id}'
     */
    public function test_store_validation_fails_on_duplicate_email(): void
    {
        // Pre-create a suppression with the email to trigger the unique rule.
        $this->makeSuppression(['email' => 'dup@example.com']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/suppressions', [
                'email'  => 'dup@example.com',
                'reason' => 'manual',
                'source' => 'manual',
            ]);

        $response->assertStatus(406);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    // ── Store happy-path ──────────────────────────────────────────────────────

    /**
     * Store creates a suppression row and returns JSON 200 with a redirect.
     *
     * The Crudable trait returns {message:'success', model:{...}, redirect:'...'} on success.
     */
    public function test_store_creates_suppression(): void
    {
        $email = 'store-happy-' . uniqid() . '@example.com';

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/suppressions', [
                'email'  => $email,
                'reason' => 'manual',
                'source' => 'manual',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'redirect']);
        $this->assertDatabaseHas('suppressions', ['email' => $email]);
    }

    // ── Update happy-path ─────────────────────────────────────────────────────

    /**
     * PUT /admin/suppressions/{id} updates the reason and returns JSON 200 with a redirect.
     *
     * The Crudable trait returns {message:'success', model:{...}, redirect:'...'} on success.
     */
    public function test_update_mutates_suppression(): void
    {
        $suppression = $this->makeSuppression(['reason' => 'manual']);

        $response = $this->actingAs($this->superadmin)
            ->putJson('/admin/suppressions/' . $suppression->id, [
                'email'  => $suppression->email,
                'reason' => 'hard_bounce',
                'source' => 'campaign',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'redirect']);
        $this->assertDatabaseHas('suppressions', [
            'id'     => $suppression->id,
            'reason' => 'hard_bounce',
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/suppressions/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_suppression(): void
    {
        $suppression = $this->makeSuppression();
        $id = $suppression->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/suppressions/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('suppressions', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete suppressions` permission — must receive 403.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $suppression = $this->makeSuppression();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/suppressions/' . $suppression->id);

        $response->assertStatus(403);
        // The suppression must still exist.
        $this->assertDatabaseHas('suppressions', ['id' => $suppression->id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/suppressions/executeSwitch/{id} always returns 403 for any field.
     *
     * SuppressionController defines NO $toggleableFields property.
     * Datatableable::executeSwitch() resolves `$this->toggleableFields ?? []` as an empty array,
     * so every field is off-whitelist. The trait returns JSON 403 regardless of the field name.
     *
     * The route IS registered (admin.suppressions.executeSwitch), and the permission check
     * passes (superadmin has `edit suppressions`), so this is purely the whitelist rejection.
     */
    public function test_execute_switch_rejects_any_field_because_no_toggleable_fields_defined(): void
    {
        $suppression = $this->makeSuppression();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/suppressions/executeSwitch/' . $suppression->id, [
                'field' => 'is_active',  // not in $toggleableFields (which is empty)
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }
}

// <<<
