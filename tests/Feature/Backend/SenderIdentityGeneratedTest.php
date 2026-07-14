<?php

namespace Tests\Feature\Backend;

use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:sender_identities-code

/**
 * SenderIdentityGeneratedTest — gap-fill test slice for the sender_identities module.
 *
 * Existing coverage (DO NOT duplicate):
 *   None — no prior Feature tests exist for this module.
 *
 * This file covers:
 *   - index 200 for superadmin (view sender_identities + backend.access)
 *   - index 403 for user without view permission
 *   - index guest redirect (302)
 *   - store validation failure: missing required `name`
 *   - store validation failure: missing required `email`
 *   - store validation failure: invalid email format
 *   - store happy-path: row persisted + redirect
 *   - update happy-path: row mutated + redirect
 *   - view (detail) 200 for existing identity
 *   - delete happy-path: JSON {success:true} + DB row gone
 *   - delete 403 for commercial role (no delete sender_identities perm)
 *   - executeSwitch sets is_active → active (state='1')
 *   - executeSwitch sets is_active → inactive (state='0')
 *   - executeSwitch sets is_default → active + clears other defaults
 *   - executeSwitch rejects unlisted field (403)
 *   - afterSave single-default enforcement on store
 */
class SenderIdentityGeneratedTest extends TestCase
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
     * Insert a sender identity directly without going through the controller.
     */
    private function makeIdentity(array $overrides = []): SenderIdentity
    {
        static $counter = 0;
        $counter++;

        return SenderIdentity::create(array_merge([
            'name'       => 'Test Sender ' . $counter,
            'email'      => 'sender' . $counter . '@example.com',
            'is_default' => false,
            'is_active'  => true,
        ], $overrides));
    }

    // ── Access control ─────────────────────────────────────────────────────────

    /**
     * Superadmin with view sender_identities + backend.access sees 200.
     */
    public function test_index_returns_200_for_superadmin(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sender_identities');

        $response->assertStatus(200);
    }

    /**
     * Unauthenticated request to sender_identities index must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/sender_identities');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view sender_identities` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view sender_identities`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/sender_identities');

        $response->assertStatus(403);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/sender_identities/{id} returns 200 for an existing identity.
     */
    public function test_view_renders_for_existing_identity(): void
    {
        $identity = $this->makeIdentity();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sender_identities/' . $identity->id);

        $response->assertStatus(200);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `name` is missing (required rule).
     *
     * The Crudable trait calls $model->validator() and returns JSON 406 on failure.
     */
    public function test_store_validation_fails_when_name_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/sender_identities', [
                // name intentionally omitted
                'email' => 'valid@example.com',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    /**
     * Store must return 406 when `email` is missing (required rule).
     */
    public function test_store_validation_fails_when_email_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/sender_identities', [
                'name' => 'Identité Sans Email',
                // email intentionally omitted
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['email']]);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /**
     * Store must return 406 when `email` fails the email format rule.
     */
    public function test_store_validation_fails_on_invalid_email_format(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/sender_identities', [
                'name'  => 'Identité Email Invalide',
                'email' => 'not-a-valid-email',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['email']]);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    // ── Store happy-path ──────────────────────────────────────────────────────

    /**
     * Store with valid data persists the row and redirects.
     */
    public function test_store_creates_identity(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/sender_identities', [
                'name'  => 'TCL France',
                'email' => 'contact@tcl-france.fr',
            ]);

        // Crudable returns JSON 200 with a `redirect` field (not an HTTP 3xx).
        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('sender_identities', [
            'name'  => 'TCL France',
            'email' => 'contact@tcl-france.fr',
        ]);
    }

    // ── Update happy-path ─────────────────────────────────────────────────────

    /**
     * PUT /admin/sender_identities/{id} with valid data mutates the row and redirects.
     */
    public function test_update_mutates_identity(): void
    {
        $identity = $this->makeIdentity(['name' => 'Original Name']);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sender_identities/' . $identity->id, [
                'name'  => 'Updated Name',
                'email' => $identity->email,
            ]);

        // Crudable returns JSON 200 with a `redirect` field (not an HTTP 3xx).
        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('sender_identities', [
            'id'   => $identity->id,
            'name' => 'Updated Name',
        ]);
    }

    // SCR-52 boolean form regression coverage.
    public function test_update_persists_unchecked_boolean_fields(): void
    {
        $identity = $this->makeIdentity([
            'is_default' => true,
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sender_identities/' . $identity->id, [
                'name'       => $identity->name,
                'email'      => $identity->email,
                'is_default' => '0',
                'is_active'  => '0',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('sender_identities', [
            'id'         => $identity->id,
            'is_default' => 0,
            'is_active'  => 0,
        ]);
    }

    public function test_update_keeps_checked_boolean_fields_true(): void
    {
        $identity = $this->makeIdentity([
            'is_default' => false,
            'is_active'  => false,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sender_identities/' . $identity->id, [
                'name'       => $identity->name,
                'email'      => $identity->email,
                'is_default' => '1',
                'is_active'  => '1',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('sender_identities', [
            'id'         => $identity->id,
            'is_default' => 1,
            'is_active'  => 1,
        ]);
    }

    public function test_edit_form_places_hidden_boolean_inputs_before_checkboxes(): void
    {
        $identity = $this->makeIdentity();

        $html = $this->actingAs($this->superadmin)
            ->get('/admin/sender_identities/' . $identity->id . '/edit')
            ->assertStatus(200)
            ->getContent();

        $isDefaultHidden = strpos($html, 'type="hidden" name="is_default" value="0"');
        $isDefaultCheckbox = strpos($html, 'name="is_default"', $isDefaultHidden + 1);
        $isActiveHidden = strpos($html, 'type="hidden" name="is_active" value="0"');
        $isActiveCheckbox = strpos($html, 'name="is_active"', $isActiveHidden + 1);

        $this->assertNotFalse($isDefaultHidden);
        $this->assertNotFalse($isDefaultCheckbox);
        $this->assertLessThan($isDefaultCheckbox, $isDefaultHidden);
        $this->assertNotFalse($isActiveHidden);
        $this->assertNotFalse($isActiveCheckbox);
        $this->assertLessThan($isActiveCheckbox, $isActiveHidden);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/sender_identities/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_identity(): void
    {
        $identity = $this->makeIdentity();
        $id       = $identity->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/sender_identities/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('sender_identities', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete sender_identities` permission — must receive 403.
     *
     * The commercial role has view/create/edit sender_identities but NOT delete.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $identity = $this->makeIdentity();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/sender_identities/' . $identity->id);

        $response->assertStatus(403);
        // The row must still exist.
        $this->assertDatabaseHas('sender_identities', ['id' => $identity->id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/sender_identities/executeSwitch/{id} with field=is_active, state='1' sets active.
     *
     * State sent as a STRING to match real browser AJAX (form values post as strings).
     * SenderIdentityController::$toggleableFields = ['is_default', 'is_active']
     */
    public function test_execute_switch_sets_active(): void
    {
        $identity = $this->makeIdentity(['is_active' => 0]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sender_identities/executeSwitch/' . $identity->id, [
                'field' => 'is_active',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('sender_identities', ['id' => $identity->id, 'is_active' => 1]);
    }

    /**
     * PUT /admin/sender_identities/executeSwitch/{id} with field=is_active, state='0' sets inactive.
     *
     * Isolated per-direction with its own fresh identity.
     */
    public function test_execute_switch_sets_inactive(): void
    {
        $identity = $this->makeIdentity(['is_active' => 1]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sender_identities/executeSwitch/' . $identity->id, [
                'field' => 'is_active',
                'state' => '0',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('sender_identities', ['id' => $identity->id, 'is_active' => 0]);
    }

    /**
     * executeSwitch with field=is_default, state='1' sets identity as default
     * and clears any other identity that was previously default.
     *
     * SenderIdentityController overrides executeSwitch() to enforce single-default rule.
     */
    public function test_execute_switch_sets_default_and_clears_other_defaults(): void
    {
        // Identity A is already the default.
        $identityA = $this->makeIdentity(['is_default' => 1]);
        // Identity B is NOT default.
        $identityB = $this->makeIdentity(['is_default' => 0]);

        // Toggle B to become the default.
        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sender_identities/executeSwitch/' . $identityB->id, [
                'field' => 'is_default',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        // B is now default.
        $this->assertDatabaseHas('sender_identities', ['id' => $identityB->id, 'is_default' => 1]);
        // A must have been cleared to false (single-default rule).
        $this->assertDatabaseHas('sender_identities', ['id' => $identityA->id, 'is_default' => 0]);
    }

    /**
     * executeSwitch must return 403 when an unlisted field is requested.
     *
     * SenderIdentityController::$toggleableFields = ['is_default', 'is_active'].
     * Any other field name must be rejected with 403.
     */
    public function test_execute_switch_rejects_unlisted_field(): void
    {
        $identity = $this->makeIdentity();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sender_identities/executeSwitch/' . $identity->id, [
                'field' => 'name',   // not in toggleableFields
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }

    // ── afterSave single-default enforcement ──────────────────────────────────

    /**
     * Store with is_default=1 clears any existing default identity (afterSave hook).
     *
     * SenderIdentityController::afterSave() enforces that only one identity can be default.
     */
    public function test_store_clears_existing_default_when_new_default_saved(): void
    {
        // Pre-existing default identity.
        $existingDefault = $this->makeIdentity(['is_default' => 1]);

        // Store a new identity also marked as default.
        $this->actingAs($this->superadmin)
            ->post('/admin/sender_identities', [
                'name'       => 'Nouveau Défaut',
                'email'      => 'nouveau@tcl-france.fr',
                'is_default' => '1',
            ]);

        // The existing default must have been cleared.
        $this->assertDatabaseHas('sender_identities', [
            'id'         => $existingDefault->id,
            'is_default' => 0,
        ]);
        // The new identity must now be the sole default.
        $this->assertDatabaseHas('sender_identities', [
            'email'      => 'nouveau@tcl-france.fr',
            'is_default' => 1,
        ]);
    }
}

// <<<
