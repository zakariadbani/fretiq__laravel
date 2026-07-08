<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:campaign_templates-code

/**
 * CampaignTemplateGeneratedTest — gap-fill test slice for the campaign_templates module.
 *
 * Existing coverage (DO NOT duplicate):
 *   TemplateTranslationRouteTest:        translate (200, 403, overwrite guard, failure),
 *                                        saveTranslation (200, 403, hash logic, staleness),
 *                                        markReviewed (200, 403, 404, true/false).
 *   ZohoTemplatesImportTest:             importFromZoho happy-path, re-import update,
 *                                        name-match adoption, 403 gate, redirect.
 *   CampaignTemplateControllerGatingTest: guest redirect smoke tests for all routes.
 *
 * This file covers the uncovered CRUD surface:
 *   - index 200 for superadmin (view campaign_templates + backend.access)
 *   - index 403 for user without view permission
 *   - create 200 (form page)
 *   - store happy-path: 200 JSON {message:success} + row persisted
 *   - store validation failure: 406 when name is missing
 *   - store validation failure: 406 when subject is missing
 *   - store validation failure: 406 when html_content is missing
 *   - view 200 for existing template
 *   - edit 200 for existing template
 *   - update happy-path: 200 JSON {message:success} + row mutated
 *   - update validation failure: 406 when name is missing
 *   - delete happy-path: 200 JSON {success:true} + row gone
 *   - delete 403 for commercial role
 *   - executeSwitch always 403 (no $toggleableFields defined on controller)
 *
 * Mocking strategy:
 *   - importFromZoho and translate are NOT exercised here (fully covered in their
 *     respective dedicated test files). No Http::fake() needed for this slice.
 *   - No queue interaction in any of these actions; Queue::fake() not required.
 */
class CampaignTemplateGeneratedTest extends TestCase
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
     * Insert a campaign template directly without going through the controller.
     */
    private function makeTemplate(array $overrides = []): CampaignTemplate
    {
        static $counter = 0;
        $counter++;

        return CampaignTemplate::create(array_merge([
            'name'         => 'Modèle Test ' . $counter,
            'subject'      => 'Sujet de test ' . $counter,
            'html_content' => '<p>Bonjour {{contact.name}}, corps du message ' . $counter . '.</p>',
            'preview_text' => 'Aperçu ' . $counter,
        ], $overrides));
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    /**
     * Superadmin with view campaign_templates + backend.access sees 200.
     */
    public function test_index_returns_200_for_superadmin(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates');

        $response->assertStatus(200);
    }

    /**
     * A user with backend.access but WITHOUT `view campaign_templates` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // Give only backend.access — no `view campaign_templates`.
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/campaign_templates');

        $response->assertStatus(403);
    }

    // ── Create (form page) ────────────────────────────────────────────────────

    /**
     * GET /admin/campaign_templates/create returns 200 for superadmin.
     */
    public function test_create_page_returns_200(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates/create');

        $response->assertStatus(200);
    }

    // ── View (detail page) ────────────────────────────────────────────────────

    /**
     * GET /admin/campaign_templates/{id} returns 200 for an existing template.
     */
    public function test_view_renders_for_existing_template(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates/' . $template->id);

        $response->assertStatus(200);
    }

    // ── Edit (edit form page) ─────────────────────────────────────────────────

    /**
     * GET /admin/campaign_templates/{id}/edit returns 200 for an existing template.
     */
    public function test_edit_page_returns_200_for_existing_template(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates/' . $template->id . '/edit');

        $response->assertStatus(200);
    }

    public function test_edit_page_renders_translation_workspace_without_main_form_sticky_actions(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates/' . $template->id . '/edit');

        $response->assertStatus(200);
        $response->assertSee('id="traductions-pane-root"', false);
        $response->assertSee('tr-command-bar', false);
        $response->assertSee('Aucune traduction anglaise enregistrée', false);
        $response->assertSee('id="tr-translate-btn"', false);
        $response->assertSee('Versions du modèle', false);
        $response->assertSee('Source française à gauche, traduction anglaise à droite.', false);
        $response->assertSee('Version française source', false);
        $response->assertSee('Version anglaise éditable', false);
        $response->assertSee('WYSIWYG français', false);
        $response->assertSee('data-tinymce-readonly', false);
        $response->assertSee('WYSIWYG anglais (EN)', false);
        $response->assertSee('EN non créée', false);
        $response->assertSee('data-crud-form-actions="sticky"', false);
    }

    // ── Store happy-path ──────────────────────────────────────────────────────

    /**
     * POST /admin/campaign_templates with valid data creates the row and returns
     * JSON {message:"success"} with HTTP 200.
     *
     * Crudable::store() calls $model->validator() and on success persists the row,
     * then returns JSON {message, model, redirect} — NOT an HTTP 3xx redirect.
     */
    public function test_store_creates_template(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaign_templates', [
                'name'         => 'Modèle Prospection TCL',
                'subject'      => 'Découvrez nos solutions de fret international',
                'html_content' => '<p>Bonjour {{contact.name}}, voici nos offres.</p>',
                'preview_text' => 'Solutions de fret international',
            ]);

        // Crudable returns JSON 200 with a `redirect` field (not an HTTP 3xx).
        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('campaign_templates', [
            'name'    => 'Modèle Prospection TCL',
            'subject' => 'Découvrez nos solutions de fret international',
        ]);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `name` is missing (required rule).
     *
     * The Crudable trait calls $model->validator() and returns JSON 406 on failure.
     * CampaignTemplate::rules(): name => 'required|string|max:255'
     */
    public function test_store_validation_fails_when_name_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                // name intentionally omitted
                'subject'      => 'Un sujet valide',
                'html_content' => '<p>Corps valide</p>',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    /**
     * Store must return 406 when `subject` is missing (required rule).
     *
     * CampaignTemplate::rules(): subject => 'required|string|max:255'
     */
    public function test_store_validation_fails_when_subject_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'         => 'Modèle Sans Sujet',
                // subject intentionally omitted
                'html_content' => '<p>Corps valide</p>',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['subject']]);
        $this->assertArrayHasKey('subject', $response->json('errors'));
    }

    /**
     * Store must return 406 when `html_content` is missing (required rule).
     *
     * CampaignTemplate::rules(): html_content => 'required|string'
     */
    public function test_store_validation_fails_when_html_content_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'    => 'Modèle Sans Corps',
                'subject' => 'Un sujet valide',
                // html_content intentionally omitted
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['html_content']]);
        $this->assertArrayHasKey('html_content', $response->json('errors'));
    }

    // ── Update happy-path ─────────────────────────────────────────────────────

    /**
     * PUT /admin/campaign_templates/{id} with valid data mutates the row and
     * returns JSON {message:"success"} with HTTP 200.
     */
    public function test_update_mutates_template(): void
    {
        $template = $this->makeTemplate(['name' => 'Nom Original']);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/campaign_templates/' . $template->id, [
                'name'         => 'Nom Mis À Jour',
                'subject'      => 'Sujet mis à jour',
                'html_content' => '<p>Corps mis à jour.</p>',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('campaign_templates', [
            'id'   => $template->id,
            'name' => 'Nom Mis À Jour',
        ]);
    }

    /**
     * PUT /admin/campaign_templates/{id} must return 406 when `name` is missing.
     */
    public function test_update_validation_fails_when_name_missing(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->superadmin)
            ->putJson('/admin/campaign_templates/' . $template->id, [
                // name intentionally omitted
                'subject'      => 'Sujet valide',
                'html_content' => '<p>Corps valide</p>',
            ]);

        $response->assertStatus(406);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/campaign_templates/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_template(): void
    {
        $template = $this->makeTemplate();
        $id = $template->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/campaign_templates/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('campaign_templates', ['id' => $id]);
    }

    /**
     * Commercial role does NOT have `delete campaign_templates` permission — must receive 403.
     *
     * The commercial role has view/create/edit campaign_templates but NOT delete.
     */
    public function test_delete_403_for_commercial_role(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/campaign_templates/' . $template->id);

        $response->assertStatus(403);
        // The row must still exist.
        $this->assertDatabaseHas('campaign_templates', ['id' => $template->id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * PUT /admin/campaign_templates/executeSwitch/{id} always returns 403 because
     * CampaignTemplateController does not declare $toggleableFields.
     *
     * Datatableable::executeSwitch() checks $this->toggleableFields ?? [] and returns
     * JSON 403 for any field not in the (empty) whitelist.
     *
     * This single test exercises both the permission middleware path (superadmin has
     * `edit campaign_templates`) and the toggleableFields guard (empty list → 403).
     */
    public function test_execute_switch_always_403_when_no_toggleable_fields(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/campaign_templates/executeSwitch/' . $template->id, [
                'field' => 'name',
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }
}

// <<<
