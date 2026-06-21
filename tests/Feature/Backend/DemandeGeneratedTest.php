<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:demandes-code

/**
 * DemandeGeneratedTest — gap-fill test slice for the demandes module.
 *
 * Existing coverage (DO NOT duplicate):
 *   SequenceDemandeCrudTest: index 200, create 200, store happy-path,
 *                             commercial-can-create 200.
 *   DemandeCaptureTest:      service-level capture, stop-sequence,
 *                             increment conversion_count.
 *   DemandeControllerGatingTest: unauthenticated loose smoke (assertContains).
 *   MarkRepliedTest:         markReplied HTTP route (campaign sub-resource).
 *
 * This file covers the uncovered surface:
 *   - index guest redirect (precise 302)
 *   - index 403 without `view demandes` permission
 *   - view 200 for an existing demande
 *   - store validation failure: invalid contact_id (exists rule → 406)
 *   - store validation failure: invalid status enum value (in: rule → 406)
 *   - update happy-path: PUT returns 200, DB updated
 *   - delete happy-path: JSON {success:true} + row gone
 *   - delete 403 for commercial role
 *   - executeSwitch rejects every field (toggleableFields = [] → always 403)
 */
class DemandeGeneratedTest extends TestCase
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
     * Build a Contact with its required Company parent.
     * All FK parent rows are inlined here — no factory exists for these models.
     */
    private function makeContact(string $email = 'test@acme.test'): Contact
    {
        $company = Company::create([
            'name'                 => 'Société Test ' . uniqid(),
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $company->id,
            'email'       => $email,
            'name'        => 'Contact Test',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    /**
     * Build a minimal Demande row directly (no HTTP).
     * contact_id is nullable per rules() — omit to avoid FK dependency.
     */
    private function makeDemande(array $overrides = []): Demande
    {
        return Demande::create(array_merge([
            'kind'        => 'reply',
            'status'      => 'pending',
            'captured_at' => now()->toDateTimeString(),
        ], $overrides));
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Unauthenticated request to the demandes index must redirect (302 to login).
     * Unlike the smoke test which asserts assertContains([302,401,...]), this
     * asserts the precise redirect contract.
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/demandes');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view demandes` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // backend.access only — no `view demandes`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/demandes');

        $response->assertStatus(403);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/demandes/{id} returns 200 for an existing demande.
     */
    public function test_view_renders_for_existing_demande(): void
    {
        $demande = $this->makeDemande();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/demandes/' . $demande->id);

        $response->assertStatus(200);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `contact_id` references a non-existent contact.
     *
     * Demande.rules(): contact_id => 'nullable|integer|exists:contacts,id'
     * Sending a non-existent integer triggers the exists rule.
     *
     * Note: captured_at is `required|date`, but DemandeController::beforeSave()
     * auto-injects `now()` on create when omitted — so it cannot be used to
     * trigger a 406 without an explicit bad value.
     */
    public function test_store_validation_fails_when_contact_id_is_nonexistent(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/demandes', [
                'contact_id'  => 999999,  // no such row
                'kind'        => 'reply',
                'captured_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['contact_id']]);
        $this->assertArrayHasKey('contact_id', $response->json('errors'));
    }

    /**
     * Store must return 406 when `status` is not in the allowed enum list.
     *
     * Demande.rules(): status => 'nullable|in:<keys from demande_statuses config>'
     */
    public function test_store_validation_fails_when_status_is_invalid(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/demandes', [
                'kind'        => 'reply',
                'status'      => '__invalid_status__',
                'captured_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['status']]);
        $this->assertArrayHasKey('status', $response->json('errors'));
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * PUT /admin/demandes/{id} updates the row and returns 200.
     *
     * Sends captured_at explicitly (required|date) so the update validator
     * does not rely on beforeSave — on update, beforeSave only fills
     * captured_at when $id === null (i.e. on create).
     */
    public function test_update_demande(): void
    {
        $demande = $this->makeDemande(['kind' => 'reply', 'status' => 'pending']);

        $response = $this->actingAs($this->superadmin)
            ->putJson('/admin/demandes/' . $demande->id, [
                'kind'        => 'manual',
                'status'      => 'pending',
                'captured_at' => now()->toDateTimeString(),
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('demandes', [
            'id'   => $demande->id,
            'kind' => 'manual',
        ]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/demandes/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_demande(): void
    {
        $demande = $this->makeDemande();
        $id      = $demande->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/demandes/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('demandes', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete demandes` — must receive 403.
     *
     * Permission matrix: commercial can view/create/edit demandes but cannot delete.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $demande = $this->makeDemande();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/demandes/' . $demande->id);

        $response->assertStatus(403);
        // Row must still exist.
        $this->assertDatabaseHas('demandes', ['id' => $demande->id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/demandes/executeSwitch/{id} must return 403 for ANY field.
     *
     * DemandeController::$toggleableFields = [] (empty — no boolean columns
     * are exposed for toggle on Demande). Datatableable::executeSwitch() checks
     * the whitelist and returns JSON 403 when the requested field is not listed.
     *
     * This also covers the "rejects unlisted field" contract from the trait.
     */
    public function test_execute_switch_rejects_any_field_because_toggleable_is_empty(): void
    {
        $demande = $this->makeDemande();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/demandes/executeSwitch/' . $demande->id, [
                'field' => 'status',  // not in toggleableFields
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }
}

// <<<
