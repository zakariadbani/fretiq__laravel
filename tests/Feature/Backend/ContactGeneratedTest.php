<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:contacts-code

/**
 * ContactGeneratedTest — gap-fill test slice for the contacts module.
 *
 * Existing coverage (DO NOT duplicate):
 *   ContactsTest:              index 200, create 200, datatable AJAX JSON, store happy-path.
 *   ContactControllerGatingTest: loose guest-redirect smoke (assertContains 302/401/…) for all routes.
 *
 * This file covers the uncovered surface:
 *   - index 403 for authenticated user missing `view contacts`
 *   - index guest → 302 redirect (strict assertion)
 *   - store validation failure: missing required `name`
 *   - store validation failure: missing required `email`
 *   - store validation failure: missing required `company_id`
 *   - store validation failure: duplicate `email` (unique constraint)
 *   - view 200 for existing contact
 *   - update happy-path: returns 200 and DB is updated
 *   - delete happy-path: JSON {success:true} + DB row gone (soft-delete)
 *   - commercial role forbidden from delete (403)
 *   - executeSwitch: always 403 because ContactController declares no $toggleableFields
 *
 * IMPORTANT — no $toggleableFields on ContactController:
 *   Datatableable::executeSwitch() reads `$this->toggleableFields ?? []`.
 *   ContactController never declares that property, so the whitelist is always empty.
 *   Every executeSwitch call returns HTTP 403 regardless of field name.
 *   The test below asserts this current contract.
 *   If toggleableFields are added in the future, update these tests accordingly.
 */
class ContactGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User    $superadmin;
    private User    $commercial;
    private Company $company;

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

        // Contact requires a parent company (FK + required validation rule).
        // There is no CompanyFactory — create directly via Eloquent.
        $this->company = Company::create([
            'name'   => 'Société Parente Test',
            'source' => 'manual',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a Contact row directly via Eloquent (no factory exists).
     */
    private function makeContact(array $overrides = []): Contact
    {
        return Contact::create(array_merge([
            'company_id'   => $this->company->id,
            'name'         => 'Contact Test ' . uniqid(),
            'email'        => 'contact-' . uniqid() . '@test.fr',
            'source'       => 'manual',
            'status'       => 'new',
            'legal_basis'  => 'relationship',
            'email_kind'   => 'role',
        ], $overrides));
    }

    // ── Access control — index ────────────────────────────────────────────────

    /**
     * Unauthenticated request to contacts index must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/contacts');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view contacts` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view contacts`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/contacts');

        $response->assertStatus(403);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/contacts/{id} returns 200 for an existing contact.
     */
    public function test_view_renders_for_existing_contact(): void
    {
        $contact = $this->makeContact();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/contacts/' . $contact->id);

        $response->assertStatus(200);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `name` is missing (required rule).
     *
     * Crudable calls $model->validator() and returns JSON 406 on failure.
     */
    public function test_store_validation_fails_when_name_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/contacts', [
                'company_id' => $this->company->id,
                'email'      => 'no-name@test.fr',
                'source'     => 'manual',
                // name intentionally omitted
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
            ->postJson('/admin/contacts', [
                'company_id' => $this->company->id,
                'name'       => 'Sans Email',
                'source'     => 'manual',
                // email intentionally omitted
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['email']]);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    /**
     * Store must return 406 when `company_id` is missing (required|exists rule).
     */
    public function test_store_validation_fails_when_company_id_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/contacts', [
                'name'   => 'Sans Entreprise',
                'email'  => 'no-company@test.fr',
                'source' => 'manual',
                // company_id intentionally omitted
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['company_id']]);
        $this->assertArrayHasKey('company_id', $response->json('errors'));
    }

    /**
     * Store must return 406 when `email` violates the unique constraint.
     *
     * Contact.rules(): email => 'required|email|max:191|unique:contacts,email,{id}'
     */
    public function test_store_validation_fails_on_duplicate_email(): void
    {
        // Pre-create a contact with the email to trigger the unique rule.
        $this->makeContact(['email' => 'dup@acme.test']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/contacts', [
                'company_id' => $this->company->id,
                'name'       => 'Autre Contact',
                'email'      => 'dup@acme.test',
                'source'     => 'manual',
            ]);

        $response->assertStatus(406);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * PUT /admin/contacts/{id} with valid data returns 200 and updates the DB.
     *
     * Crudable::update() returns JSON {message, model, redirect} on success.
     */
    public function test_update_contact(): void
    {
        $contact = $this->makeContact(['name' => 'Nom Original', 'email' => 'original@test.fr']);

        $response = $this->actingAs($this->superadmin)
            ->putJson('/admin/contacts/' . $contact->id, [
                'company_id' => $this->company->id,
                'name'       => 'Nom Modifié',
                'email'      => 'original@test.fr', // same email — no unique conflict
                'source'     => 'manual',
                'status'     => 'contacted',
                'legal_basis' => 'relationship',
                'email_kind' => 'personal',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contacts', [
            'id'   => $contact->id,
            'name' => 'Nom Modifié',
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/contacts/{id} soft-deletes the row and returns JSON {success:true}.
     *
     * Contact uses SoftDeletes — assertDatabaseMissing checks the default scope
     * (which excludes soft-deleted rows), so a soft-deleted row satisfies the assertion.
     */
    public function test_delete_removes_contact(): void
    {
        $contact = $this->makeContact();
        $id      = $contact->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/contacts/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('contacts', ['id' => $id, 'deleted_at' => null]);
    }

    /**
     * Commercial role does NOT have `delete contacts` permission — must receive 403.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $contact = $this->makeContact();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/contacts/' . $contact->id);

        $response->assertStatus(403);
        // The contact must still be present (not soft-deleted).
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'deleted_at' => null]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/contacts/executeSwitch/{id} always returns 403.
     *
     * ContactController declares NO $toggleableFields property.
     * Datatableable::executeSwitch() reads `$this->toggleableFields ?? []`
     * and rejects any field not in the whitelist with HTTP 403.
     * Since the whitelist is always empty, every call returns 403.
     *
     * This test documents the current contract. If $toggleableFields is added
     * to ContactController in the future, replace this test with per-direction
     * tests (sets_active / sets_inactive) as on CompanyGeneratedTest.
     */
    public function test_execute_switch_always_rejects_when_no_toggleable_fields(): void
    {
        $contact = $this->makeContact();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/contacts/executeSwitch/' . $contact->id, [
                'field' => 'status',  // any field — whitelist is empty
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }
}

// <<<
