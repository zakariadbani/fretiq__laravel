<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\User;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CampaignTemplateBuilderTest — the builder half of the campaign_templates
 * CRUD surface: store()/update() beforeSave() builder-mode composition +
 * builder_state round-trip, the builder/preview and builder/suggest JSON
 * endpoints, and the permission gate on both.
 */
class CampaignTemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function validBuilderState(): array
    {
        return [
            'header_variant' => 'logo_center',
            'hero_variant'   => 'white',
            'middle_variant' => 'departures',
            'footer_variant' => 'detailed',
            'preview_text'   => 'Un aperçu de campagne de test.',
            'cta' => [
                'intent' => 'quote',
                'label'  => 'Demander une cotation',
            ],
            'slots' => [
                'hero_title' => 'Optimisez vos flux de test',
                'intro'      => ['Un premier paragraphe.', 'Un second paragraphe pour {{company.name}}.'],
                'bullets'    => ['Bullet un', 'Bullet deux', 'Bullet trois'],
                'departures' => [
                    ['origin' => 'Goussainville (France)', 'frequency' => '4 départs par semaine'],
                    ['origin' => 'Barcelone (Espagne)', 'frequency' => '2 à 3 départs par semaine'],
                ],
            ],
        ];
    }

    // ── Create page render (Phase 3 frontend smoke check) ──────────────────────

    /**
     * The create page always opens in builder mode (no stored builder_state
     * yet) — asserts the Blade view compiles and renders the builder scaffold
     * (hydration payload, variant pickers, hidden editor_mode/builder_state
     * inputs) without the classic pane's required attributes leaking in.
     */
    public function test_create_page_renders_builder_by_default(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/admin/campaign_templates/create');

        $response->assertStatus(200);
        $response->assertSee('window.__campaignTemplateBuilder', false);
        $response->assertSee('id="builder_pane"', false);
        $response->assertSee('id="classic_pane"', false);
        $response->assertSee('name="editor_mode"', false);
        $response->assertSee('name="builder_state"', false);
        $response->assertSee('data-campaign-template-create-shell', false);
        $response->assertSee("Créer un modèle d'email", false);
        $response->assertSee('Étapes de création');
        $response->assertSee('1. Informations');
        $response->assertSee('2. Composer');
        $response->assertSee('3. Vérifier');
        $response->assertSee('campaign-template-create-workspace', false);
        $response->assertSee('Point de départ');
        $response->assertSee('data-preview-size="desktop"', false);
        $response->assertSee('data-preview-size="mobile"', false);
        $response->assertSee('data-preview-canvas="desktop"', false);
        $response->assertSee('campaign-template-create-preview-card', false);
        $this->assertSame(1, substr_count($response->getContent(), 'id="builder_brief_input"'));
        $response->assertDontSee('data-tinymce-html-field required', false);
    }

    public function test_create_page_does_not_reuse_stale_variant_preview_cache(): void
    {
        $stalePreview = '<a href="https://tcltransport.com/contact">Ancien CTA</a>';

        Cache::forever('builder.variant_previews.v1', [
            'headers' => array_fill_keys(SectionCatalog::HEADERS, $stalePreview),
            'footers' => array_fill_keys(SectionCatalog::FOOTERS, $stalePreview),
        ]);

        $response = $this->actingAs($this->superadmin)->get('/admin/campaign_templates/create');

        $response->assertStatus(200);
        $response->assertDontSee('https://tcltransport.com/contact', false);
        $response->assertSee('https://tcltransport.com/', false);
    }

    public function test_edit_page_keeps_existing_shell_without_create_only_preview_controls(): void
    {
        $template = CampaignTemplate::create([
            'name'          => 'Modèle à modifier',
            'subject'       => 'Sujet existant',
            'preview_text'  => 'Aperçu existant',
            'html_content'  => '<p>Contenu existant.</p>',
            'builder_state' => $this->validBuilderState(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates/' . $template->id . '/edit');

        $response->assertStatus(200);
        $response->assertSee('Modifier le modèle');
        $response->assertSee('id="builder_pane"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'id="builder_brief_input"'));
        $response->assertDontSee('data-campaign-template-create-shell', false);
        $response->assertDontSee('data-preview-size="desktop"', false);
        $response->assertDontSee('campaign-template-create-steps mb-5', false);
    }

    // ── Store — builder mode ────────────────────────────────────────────────────

    public function test_builder_store_persists_composed_html_and_array_builder_state(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaign_templates', [
                'name'          => 'Modèle Builder Test',
                'subject'       => 'Sujet du modèle builder',
                'preview_text'  => 'Un aperçu de campagne de test.',
                'editor_mode'   => 'builder',
                'builder_state' => json_encode($this->validBuilderState()),
            ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);

        $template = CampaignTemplate::where('name', 'Modèle Builder Test')->firstOrFail();

        // html_content is the composed, send-ready HTML — not the raw builder_state.
        $this->assertStringStartsWith('<!DOCTYPE html', trim($template->html_content));
        $this->assertStringContainsString(SectionCatalog::LOGO_WHITE_URL, $template->html_content);
        $this->assertStringContainsString('Optimisez vos flux de test', $template->html_content);
        $this->assertStringNotContainsString('unsubscribe', mb_strtolower($template->html_content));

        // builder_state round-trips as a PHP array (array cast), not a JSON string —
        // proves beforeSave() reassigned the DECODED array, not the raw JSON string.
        $this->assertIsArray($template->builder_state);
        $this->assertSame('logo_center', $template->builder_state['header_variant']);
        $this->assertSame('departures', $template->builder_state['middle_variant']);
        $this->assertSame('Optimisez vos flux de test', $template->builder_state['slots']['hero_title']);
    }

    /**
     * preview_text has two sources of truth (plan item 7) — the composed
     * HTML uses builder_state.preview_text, the column comes from the form
     * field. beforeSave() must assign the COLUMN from the validated state so
     * they can never diverge, even if the submitted form field disagreed
     * (stale mirror, tampered request, etc).
     */
    public function test_builder_store_preview_text_column_follows_validated_builder_state(): void
    {
        $state = $this->validBuilderState();
        $state['preview_text'] = 'Aperçu venant du builder_state.';

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaign_templates', [
                'name'          => 'Modèle Preview Text Divergent',
                'subject'       => 'Sujet',
                // Deliberately different from builder_state.preview_text —
                // simulates a stale/tampered form field.
                'preview_text'  => 'Aperçu venant du champ de formulaire.',
                'editor_mode'   => 'builder',
                'builder_state' => json_encode($state),
            ]);

        $response->assertStatus(200);

        $template = CampaignTemplate::where('name', 'Modèle Preview Text Divergent')->firstOrFail();

        $this->assertSame('Aperçu venant du builder_state.', $template->preview_text);
        $this->assertSame('Aperçu venant du builder_state.', $template->builder_state['preview_text']);
    }

    public function test_builder_store_with_invalid_state_returns_422(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'          => 'Modèle Builder Invalide',
                'subject'       => 'Sujet',
                'editor_mode'   => 'builder',
                'builder_state' => json_encode(['header_variant' => 'not_a_real_variant']),
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('campaign_templates', ['name' => 'Modèle Builder Invalide']);
    }

    public function test_builder_store_with_malformed_json_returns_422(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'          => 'Modèle JSON Cassé',
                'subject'       => 'Sujet',
                'editor_mode'   => 'builder',
                'builder_state' => '{not valid json',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('campaign_templates', ['name' => 'Modèle JSON Cassé']);
    }

    public function test_builder_store_with_unsubscribe_tag_returns_422(): void
    {
        $state = $this->validBuilderState();
        $state['slots']['hero_title'] = 'Cliquez {{unsubscribe_url}} ici';

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'          => 'Modèle Désabonnement',
                'subject'       => 'Sujet',
                'editor_mode'   => 'builder',
                'builder_state' => json_encode($state),
            ]);

        $response->assertStatus(422);
    }

    /**
     * Unknown top-level keys are rejected outright (plan item 5) rather than
     * silently discarded by normalizeSlots() later.
     */
    public function test_builder_store_with_unknown_top_level_key_returns_422(): void
    {
        $state = $this->validBuilderState();
        $state['not_a_real_field'] = 'x';

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'          => 'Modèle Clé Inconnue',
                'subject'       => 'Sujet',
                'editor_mode'   => 'builder',
                'builder_state' => json_encode($state),
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('campaign_templates', ['name' => 'Modèle Clé Inconnue']);
    }

    /**
     * A builder_state whose JSON-encoded size exceeds
     * BuilderStateValidator::MAX_ENCODED_BYTES is rejected pre-decode (plan
     * item 5) — the "trop volumineux" message proves the SIZE guard fired,
     * not an unrelated per-field max: bound.
     */
    public function test_builder_store_with_oversized_builder_state_returns_422(): void
    {
        $state = $this->validBuilderState();
        $state['slots']['hero_title'] = str_repeat('a', 25000);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'          => 'Modèle Trop Volumineux',
                'subject'       => 'Sujet',
                'editor_mode'   => 'builder',
                'builder_state' => json_encode($state),
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors.builder_state');
        $this->assertIsArray($errors);
        $this->assertStringContainsString('trop volumineux', $errors[0]);
        $this->assertDatabaseMissing('campaign_templates', ['name' => 'Modèle Trop Volumineux']);
    }

    // ── Store — classic mode ─────────────────────────────────────────────────────

    public function test_classic_store_nulls_builder_state(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaign_templates', [
                'name'         => 'Modèle Classique Test',
                'subject'      => 'Sujet du modèle classique',
                'html_content' => '<p>Bonjour {{contact.name}}, corps classique.</p>',
                'editor_mode'  => 'classic',
            ]);

        $response->assertStatus(200);

        $template = CampaignTemplate::where('name', 'Modèle Classique Test')->firstOrFail();

        $this->assertNull($template->builder_state);
        $this->assertSame('<p>Bonjour {{contact.name}}, corps classique.</p>', $template->html_content);
    }

    public function test_store_without_editor_mode_defaults_to_classic(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaign_templates', [
                'name'         => 'Modèle Sans Editor Mode',
                'subject'      => 'Sujet',
                'html_content' => '<p>Corps.</p>',
            ]);

        $response->assertStatus(200);

        $template = CampaignTemplate::where('name', 'Modèle Sans Editor Mode')->firstOrFail();

        $this->assertNull($template->builder_state);
    }

    /**
     * editor_mode is client-controlled (a hidden input toggled by JS). An
     * unrecognized value must be REJECTED with a 422, not silently coerced
     * to classic — on a builder-mode edit page the (disabled, per plan item
     * 7) html_content textarea can still carry stale HTML; coercing to
     * classic would silently downgrade the template and drop builder_state.
     */
    public function test_store_with_unknown_editor_mode_returns_422(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates', [
                'name'         => 'Modèle Editor Mode Invalide',
                'subject'      => 'Sujet',
                'html_content' => '<p>Corps.</p>',
                'editor_mode'  => 'tampered',
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('campaign_templates', ['name' => 'Modèle Editor Mode Invalide']);
    }

    // ── Update — builder mode ───────────────────────────────────────────────────

    /**
     * update() → beforeSave($id) is the untested half of the trait-alias
     * override (CampaignTemplateController::$crudableBeforeSave). Asserts a
     * builder-mode PUT actually recomposes html_content (not just re-saves the
     * previous value) and that builder_state round-trips as a PHP array.
     */
    public function test_builder_update_recomposes_html_and_keeps_array_builder_state(): void
    {
        $initialState = $this->validBuilderState();

        $template = CampaignTemplate::create([
            'name'          => 'Modèle Builder à mettre à jour',
            'subject'       => 'Sujet initial',
            'preview_text'  => $initialState['preview_text'],
            'html_content'  => '<p>contenu initial non composé</p>',
            'builder_state' => $initialState,
        ]);

        $updatedState = $this->validBuilderState();
        $updatedState['slots']['hero_title'] = 'Titre mis à jour après update()';

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/campaign_templates/' . $template->id, [
                'name'          => 'Modèle Builder à mettre à jour',
                'subject'       => 'Sujet mis à jour',
                'preview_text'  => $updatedState['preview_text'],
                'editor_mode'   => 'builder',
                'builder_state' => json_encode($updatedState),
            ]);

        $response->assertStatus(200);

        $template->refresh();

        // html_content actually changed — proves beforeSave($id) recomposed it
        // rather than leaving the previously-stored value untouched.
        $this->assertStringContainsString('Titre mis à jour après update()', $template->html_content);
        $this->assertStringNotContainsString('contenu initial non composé', $template->html_content);

        // builder_state round-trips as a PHP array (array cast), not a JSON string.
        $this->assertIsArray($template->builder_state);
        $this->assertSame('Titre mis à jour après update()', $template->builder_state['slots']['hero_title']);
    }

    /**
     * The classic-mode branch of beforeSave($id) must null out a PREVIOUSLY
     * STORED builder_state on update — not just skip setting one on a fresh
     * classic create (already covered by test_classic_store_nulls_builder_state).
     */
    public function test_classic_update_nulls_existing_builder_state(): void
    {
        $template = CampaignTemplate::create([
            'name'          => 'Modèle Builder à basculer en classique',
            'subject'       => 'Sujet initial',
            'preview_text'  => 'Aperçu initial',
            'html_content'  => '<p>contenu initial builder</p>',
            'builder_state' => $this->validBuilderState(),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/campaign_templates/' . $template->id, [
                'name'         => 'Modèle Builder à basculer en classique',
                'subject'      => 'Sujet basculé en HTML classique',
                'html_content' => '<p>Bonjour {{contact.name}}, HTML classique manuel.</p>',
                'editor_mode'  => 'classic',
            ]);

        $response->assertStatus(200);

        $template->refresh();

        $this->assertNull($template->builder_state);
        $this->assertSame('<p>Bonjour {{contact.name}}, HTML classique manuel.</p>', $template->html_content);
    }

    // ── builder/preview ───────────────────────────────────────────────────────

    public function test_builder_preview_returns_composed_html(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/preview', [
                'builder_state' => $this->validBuilderState(),
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertStringStartsWith('<!DOCTYPE html', trim($response->json('html')));
        $this->assertStringContainsString('Optimisez vos flux de test', $response->json('html'));
    }

    public function test_builder_preview_with_invalid_state_returns_422_with_errors(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/preview', [
                'builder_state' => ['header_variant' => 'not_a_real_variant'],
            ]);

        $response->assertStatus(422);
        $this->assertIsArray($response->json('errors'));
    }

    /**
     * Explicit pre-check on the preview endpoint (plan item 5) — mirrors the
     * pre-decode check in beforeSave() and short-circuits BEFORE the
     * recursive BuilderStateValidator scan runs on an oversized payload.
     */
    public function test_builder_preview_with_oversized_state_returns_422(): void
    {
        $state = $this->validBuilderState();
        $state['slots']['hero_title'] = str_repeat('a', 25000);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/preview', [
                'builder_state' => $state,
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors.builder_state');
        $this->assertIsArray($errors);
        $this->assertStringContainsString('trop volumineux', $errors[0]);
    }

    public function test_builder_preview_with_unknown_key_returns_422(): void
    {
        $state = $this->validBuilderState();
        $state['not_a_real_field'] = 'x';

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/preview', [
                'builder_state' => $state,
            ]);

        $response->assertStatus(422);
    }

    public function test_builder_preview_403_without_permission(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->postJson('/admin/campaign_templates/builder/preview', [
                'builder_state' => $this->validBuilderState(),
            ]);

        $response->assertStatus(403);
    }

    // ── builder/suggest ───────────────────────────────────────────────────────

    private function validSuggestionResponseBody(): array
    {
        $payload = [
            'subject'        => 'Sujet suggéré',
            'preview_text'   => 'Aperçu suggéré',
            'middle_variant' => 'kpi',
            'cta_intent'     => 'quote',
            'cta_label'      => 'Demander une cotation',
            'slots' => [
                'hero_title' => 'Titre suggéré',
                'intro'      => ['Paragraphe un.', 'Paragraphe deux.'],
                'bullets'    => ['Bullet un', 'Bullet deux', 'Bullet trois'],
                'kpis' => [
                    ['value' => '48 h', 'label' => 'Enlèvement'],
                    ['value' => 'IATA', 'label' => 'Agent agréé'],
                    ['value' => '100 %', 'label' => 'Suivi documentaire'],
                ],
            ],
        ];

        return [
            'candidates' => [
                [
                    'finishReason' => 'STOP',
                    'content'      => ['parts' => [['text' => json_encode($payload)]]],
                ],
            ],
        ];
    }

    public function test_builder_suggest_with_fake_gemini_returns_suggestion(): void
    {
        config(['services.gemini.api_key' => 'fake-api-key']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->validSuggestionResponseBody(), 200),
        ]);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/suggest', [
                'brief' => 'Un brief suffisamment long pour passer la validation minimale de 20 caractères.',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertSame('kpi', $response->json('suggestion.middle_variant'));
    }

    public function test_builder_suggest_without_api_key_returns_422(): void
    {
        config(['services.gemini.api_key' => null]);

        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/suggest', [
                'brief' => 'Un brief suffisamment long pour passer la validation minimale de 20 caractères.',
            ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
    }

    public function test_builder_suggest_403_without_permission(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->postJson('/admin/campaign_templates/builder/suggest', [
                'brief' => 'Un brief suffisamment long pour passer la validation minimale de 20 caractères.',
            ]);

        $response->assertStatus(403);
    }

    public function test_builder_suggest_validates_brief_min_length(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/suggest', [
                'brief' => 'trop court',
            ]);

        $response->assertStatus(422);
    }

    // ── Rate limiting (plan item 4) ──────────────────────────────────────────

    /**
     * builder_suggest spends a paid Gemini call per request — throttle:10,1.
     */
    public function test_builder_suggest_is_rate_limited(): void
    {
        config(['services.gemini.api_key' => 'fake-api-key']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->validSuggestionResponseBody(), 200),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->superadmin)
                ->postJson('/admin/campaign_templates/builder/suggest', [
                    'brief' => 'Un brief suffisamment long pour passer la validation minimale de 20 caractères.',
                ])
                ->assertStatus(200);
        }

        $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/suggest', [
                'brief' => 'Un brief suffisamment long pour passer la validation minimale de 20 caractères.',
            ])
            ->assertStatus(429);
    }

    /**
     * builder_preview fires on a 400ms keystroke debounce — looser
     * throttle:60,1, same convention as segments.preview.
     */
    public function test_builder_preview_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($this->superadmin)
                ->postJson('/admin/campaign_templates/builder/preview', [
                    'builder_state' => $this->validBuilderState(),
                ])
                ->assertStatus(200);
        }

        $this->actingAs($this->superadmin)
            ->postJson('/admin/campaign_templates/builder/preview', [
                'builder_state' => $this->validBuilderState(),
            ])
            ->assertStatus(429);
    }
}
