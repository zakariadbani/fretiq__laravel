<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:companies-code

/**
 * CompanyGeneratedTest — gap-fill test slice for the companies module.
 *
 * Existing coverage (DO NOT duplicate):
 *   CompaniesTest:         index 200, create 200, datatable AJAX JSON, store happy-path, update happy-path.
 *   CompanyManualEnrichTest: ALL enrich cases (403, 422 no-domain, 422 quota, 200, 409, 500, regression).
 *
 * This file covers the uncovered surface:
 *   - index 403 / guest redirect (no view companies permission)
 *   - store validation failure: missing required `name`
 *   - store validation failure: duplicate `domain`
 *   - view 200 for existing company
 *   - delete happy-path: JSON {success:true} + DB row gone
 *   - commercial role forbidden from delete (403)
 *   - executeSwitch sets is_active active / inactive (isolated per-direction)
 */
class CompanyGeneratedTest extends TestCase
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

    private function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'                 => 'Société Test ' . uniqid(),
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'is_active'            => true,
        ], $overrides));
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Unauthenticated request to companies index must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/companies');

        // Laravel auth middleware redirects guests to the login page.
        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view companies` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view companies`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/companies');

        $response->assertStatus(403);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/companies/{id} returns 200 for an existing company.
     */
    public function test_view_renders_for_existing_company(): void
    {
        $company = $this->makeCompany();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/companies/' . $company->id);

        $response->assertStatus(200);
        // company name is rendered in both the <title> section and the breadcrumb span.
        $response->assertSee($company->name);
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
            ->postJson('/admin/companies', [
                // name intentionally omitted
                'relationship'         => 'prospect',
                'source'               => 'manual',
                'qualification_status' => 'pending',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
        // Verify the field key exists in errors regardless of message text.
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    /**
     * Store must return 406 when `domain` violates the unique constraint.
     *
     * Company.rules(): domain => 'nullable|string|max:191|unique:companies,domain,{id}'
     */
    public function test_store_validation_fails_on_duplicate_domain(): void
    {
        // Pre-create a company with the domain to trigger the unique rule.
        $this->makeCompany(['domain' => 'acme-dup.com']);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/companies', [
                'name'   => 'Autre ACME SARL',
                'domain' => 'acme-dup.com',
                'source' => 'manual',
            ]);

        $response->assertStatus(406);
        $this->assertArrayHasKey('domain', $response->json('errors'));
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/companies/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_company(): void
    {
        $company = $this->makeCompany();
        $id = $company->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/companies/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('companies', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete companies` permission — must receive 403.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $company = $this->makeCompany();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/companies/' . $company->id);

        $response->assertStatus(403);
        // The company must still exist.
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/companies/executeSwitch/{id} with field=is_active, state='1' sets active.
     *
     * Isolated per-direction with its own fresh company; state sent as a STRING
     * to match real browser AJAX (form values post as strings).
     * CompanyController::$toggleableFields = ['is_active']
     * Datatableable::executeSwitch() checks the whitelist, writes (int) state, returns {success:true}.
     */
    public function test_execute_switch_sets_active(): void
    {
        $company = $this->makeCompany(['is_active' => 0]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/companies/executeSwitch/' . $company->id, [
                'field' => 'is_active',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'is_active' => 1]);
    }

    /**
     * PUT /admin/companies/executeSwitch/{id} with field=is_active, state='0' sets inactive.
     *
     * Isolated per-direction with its own fresh company; state sent as a STRING
     * to match real browser AJAX (form values post as strings).
     */
    public function test_execute_switch_sets_inactive(): void
    {
        $company = $this->makeCompany(['is_active' => 1]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/companies/executeSwitch/' . $company->id, [
                'field' => 'is_active',
                'state' => '0',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'is_active' => 0]);
    }

    /**
     * executeSwitch must return 403 when an unlisted field is requested.
     *
     * Datatableable returns JSON 403 for fields not in $toggleableFields.
     */
    public function test_execute_switch_rejects_unlisted_field(): void
    {
        $company = $this->makeCompany();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/companies/executeSwitch/' . $company->id, [
                'field' => 'name',   // not in toggleableFields
                'state' => 1,
            ]);

        $response->assertStatus(403);
    }

    // ── explainScore ──────────────────────────────────────────────────────────

    /**
     * POST /admin/companies/{id}/explain-score returns 200 and writes ai_explanation
     * when the company has a non-null ai_score.
     *
     * ScoreExplanationService is mocked — no outbound Gemini HTTP call occurs.
     */
    public function test_explain_score_returns_200_and_writes_explanation_when_score_present(): void
    {
        $company = $this->makeCompany(['ai_score' => 75, 'domain' => 'explain-score-test-' . uniqid() . '.com']);

        $knownExplanation = 'Ce prospect présente un fort potentiel dans le contexte fret TCL France.';

        $this->mock(\App\Services\Scoring\ScoreExplanationService::class, function ($mock) use ($company, $knownExplanation) {
            $mock->shouldReceive('explain')
                ->once()
                ->with(\Mockery::on(fn ($arg) => $arg instanceof \App\Models\Company && $arg->id === $company->id))
                ->andReturn($knownExplanation);
        });

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/companies/' . $company->id . '/explain-score');

        $response->assertStatus(200);
        $response->assertJson([
            'message'     => 'success',
            'text'        => 'Récapitulatif IA généré.',
            'explanation' => $knownExplanation,
        ]);

        $this->assertDatabaseHas('companies', [
            'id'             => $company->id,
            'ai_explanation' => $knownExplanation,
        ]);
    }

    /**
     * POST /admin/companies/{id}/explain-score returns 422 when ai_score is null.
     *
     * ScoreExplanationService::explain() must NOT be called (no score to explain).
     * The ai_explanation column stays null.
     */
    public function test_explain_score_returns_422_when_no_score(): void
    {
        $company = $this->makeCompany(['ai_score' => null]);

        $this->mock(\App\Services\Scoring\ScoreExplanationService::class, function ($mock) {
            $mock->shouldNotReceive('explain');
        });

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/companies/' . $company->id . '/explain-score');

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'text'    => 'Aucun score IA à expliquer.',
        ]);

        // ai_explanation must remain null — no DB write on this path.
        $this->assertDatabaseHas('companies', [
            'id'             => $company->id,
            'ai_explanation' => null,
        ]);
    }

    /**
     * POST /admin/companies/{id}/explain-score returns 403 for a user who
     * only has backend.access but NOT `edit companies`.
     *
     * The middleware blocks before the controller body runs.
     */
    public function test_explain_score_403_for_user_without_edit_companies(): void
    {
        $company = $this->makeCompany(['ai_score' => 60]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->postJson('/admin/companies/' . $company->id . '/explain-score');

        $response->assertStatus(403);
    }
    public function test_rejected_company_direct_view_is_available_and_marked_archived(): void
    {
        $company = $this->makeCompany(['qualification_status' => 'rejected']);

        $this->assertNull(Company::find($company->id));

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/companies/' . $company->id);

        $response->assertOk()
            ->assertSee($company->name)
            ->assertSee('Cette entreprise est archivée', false)
            ->assertSee('Restaurer', false);
    }

    public function test_rejected_company_direct_view_requires_view_permission(): void
    {
        $company = $this->makeCompany(['qualification_status' => 'rejected']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->givePermissionTo('backend.access');

        $this->actingAs($user)
            ->get('/admin/companies/' . $company->id)
            ->assertStatus(403);
    }

    public function test_commercial_can_restore_a_rejected_company_to_pending(): void
    {
        $company = $this->makeCompany(['qualification_status' => 'rejected']);

        $this->actingAs($this->commercial)
            ->post('/admin/companies/' . $company->id . '/restore')
            ->assertRedirect('/admin/companies/' . $company->id);

        $this->assertDatabaseHas('companies', [
            'id'                     => $company->id,
            'qualification_status'   => 'pending',
        ]);
        $this->assertNotNull(Company::find($company->id));
    }

    public function test_restore_requires_edit_companies_permission(): void
    {
        $company = $this->makeCompany(['qualification_status' => 'rejected']);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->givePermissionTo('backend.access');

        $this->actingAs($user)
            ->post('/admin/companies/' . $company->id . '/restore')
            ->assertStatus(403);

        $this->assertDatabaseHas('companies', [
            'id'                     => $company->id,
            'qualification_status'   => 'rejected',
        ]);
    }
}

// <<<
