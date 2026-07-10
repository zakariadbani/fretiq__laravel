<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for the translation controller routes:
 *   POST /admin/campaign_templates/{id}/translate        (admin.campaign_templates.translate)
 *   POST /admin/campaign_templates/{id}/translation      (admin.campaign_templates.saveTranslation)
 *   POST /admin/campaign_templates/{id}/translation/review (admin.campaign_templates.markReviewed)
 *
 * All Gemini calls are Http::fake()'d — never hits the network.
 */
class TemplateTranslationRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $viewOnlyUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Admin has 'edit campaign_templates' (via superadmin role)
        $this->adminUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->adminUser->assignRole('superadmin');

        // Commercial has 'view campaign_templates' but no 'edit campaign_templates'
        // Actually commercial has edit — use a plain user with no roles for 403 tests
        $this->viewOnlyUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // viewOnlyUser has no role → no permissions → 403
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeTemplate(array $overrides = []): CampaignTemplate
    {
        return CampaignTemplate::create(array_merge([
            'name'         => 'Modèle Traduction',
            'subject'      => 'Expédition internationale depuis la France',
            'html_content' => '<p>Bonjour {{contact.name}}, nous offrons des solutions de fret.</p>',
            'preview_text' => 'Solutions de transport international',
        ], $overrides));
    }

    /**
     * Build a valid Gemini API response for 2 text runs.
     */
    private function geminiResponse(array $runs, string $subject = 'International shipping from France', string $preview = 'International transport solutions'): array
    {
        return [
            'candidates' => [
                [
                    'finishReason' => 'STOP',
                    'content'      => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'subject'      => $subject,
                                    'preview_text' => $preview,
                                    'runs'         => $runs,
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ── /translate — 403 without permission ───────────────────────────────────

    public function test_translate_returns_403_without_edit_permission(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->viewOnlyUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $response->assertStatus(403);
    }

    // ── /translate — success path ─────────────────────────────────────────────

    public function test_translate_creates_en_row_on_success(): void
    {
        $template = $this->makeTemplate();

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(['Hello {{contact.name}}, we offer freight solutions.']),
                200
            ),
        ]);

        config([
            'services.gemini.api_key' => 'fake-key',
            'services.gemini.model'   => 'gemini-2.0-flash',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('campaign_template_translations', [
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'is_ai_generated'      => 1,
            'reviewed_at'          => null,
        ]);
    }

    public function test_translate_can_generate_fr_base_from_existing_en_translation(): void
    {
        $template = $this->makeTemplate([
            'subject'      => '',
            'html_content' => '',
            'preview_text' => null,
        ]);

        CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Your Morocco freight solution',
            'html_content'         => '<p>Hello {{contact.name}}, we operate weekly trailers.</p>',
            'preview_text'         => 'Weekly trailers to Morocco',
            'is_ai_generated'      => false,
            'src_subject_hash'     => md5(''),
            'src_preview_hash'     => md5(''),
            'src_body_hash'        => md5(''),
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(
                    ['Bonjour {{contact.name}}, nous opérons des remorques hebdomadaires.'],
                    'Votre solution fret Maroc',
                    'Remorques hebdomadaires vers le Maroc'
                ),
                200
            ),
        ]);

        config(['services.gemini.api_key' => 'fake-key']);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate", [
                'source_language' => 'en',
                'target_language' => 'fr',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('source_language', 'en')
            ->assertJsonPath('target_language', 'fr')
            ->assertJsonPath('base.subject', 'Votre solution fret Maroc');

        $template->refresh();
        $this->assertSame('Votre solution fret Maroc', $template->subject);
        $this->assertSame('Remorques hebdomadaires vers le Maroc', $template->preview_text);
        $this->assertStringContainsString('Bonjour {{contact.name}}', $template->html_content);

        $translation = $template->translationFor('en');
        $hashes = $template->sourceHashes();
        $this->assertSame($hashes['subject'], $translation->src_subject_hash);
        $this->assertSame($hashes['preview'], $translation->src_preview_hash);
        $this->assertSame($hashes['body'], $translation->src_body_hash);
    }

    public function test_translate_sets_sub_hashes_from_current_base(): void
    {
        $template = $this->makeTemplate();

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(['Hello {{contact.name}}, we offer freight solutions.']),
                200
            ),
        ]);

        config(['services.gemini.api_key' => 'fake-key']);

        $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $tr = CampaignTemplateTranslation::where([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
        ])->firstOrFail();

        $expectedHashes = $template->sourceHashes();

        $this->assertSame($expectedHashes['subject'], $tr->src_subject_hash);
        $this->assertSame($expectedHashes['preview'], $tr->src_preview_hash);
        $this->assertSame($expectedHashes['body'], $tr->src_body_hash);
    }

    public function test_translate_json_response_contains_expected_shape(): void
    {
        $template = $this->makeTemplate();

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(['Hello {{contact.name}}, we offer freight solutions.']),
                200
            ),
        ]);

        config(['services.gemini.api_key' => 'fake-key']);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'translations' => [
                    '*' => ['language', 'subject', 'preview_text', 'html_content', 'is_ai_generated', 'reviewed', 'stale_fields'],
                ],
            ]);

        $translation = $response->json('translations.0');
        $this->assertSame('en', $translation['language']);
        $this->assertTrue($translation['is_ai_generated']);
        $this->assertFalse($translation['reviewed']);
        $this->assertSame([], $translation['stale_fields']);
    }

    public function test_translate_called_twice_upserts_single_row(): void
    {
        $template = $this->makeTemplate();

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(['Hello {{contact.name}}, we offer freight solutions.']),
                200
            ),
        ]);

        config(['services.gemini.api_key' => 'fake-key']);

        $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $this->assertDatabaseCount('campaign_template_translations', 1);
    }

    // ── /translate — Gemini failure keeps previous EN row ────────────────────

    public function test_gemini_http_failure_leaves_existing_en_row_untouched(): void
    {
        $template = $this->makeTemplate();

        // Create an existing EN row with known content
        $existingTr = CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Previous EN Subject',
            'html_content'         => '<p>Previous EN content</p>',
            'preview_text'         => 'Previous EN preview',
            'is_ai_generated'      => true,
            'src_subject_hash'     => md5($template->subject),
            'src_preview_hash'     => md5($template->preview_text ?? ''),
            'src_body_hash'        => md5($template->html_content),
        ]);

        // Gemini returns 500 → driver returns null → existing row UNTOUCHED
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500),
        ]);

        config(['services.gemini.api_key' => 'fake-key']);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $response->assertStatus(200)
            ->assertJsonPath('success', false);

        // The existing row must still have its previous content
        $this->assertDatabaseHas('campaign_template_translations', [
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Previous EN Subject',
            'html_content'         => '<p>Previous EN content</p>',
        ]);
    }

    public function test_blank_api_key_leaves_existing_en_row_untouched(): void
    {
        $template = $this->makeTemplate();

        CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Safe EN Subject',
            'html_content'         => '<p>Safe EN content</p>',
            'preview_text'         => null,
            'is_ai_generated'      => true,
            'src_subject_hash'     => md5($template->subject),
            'src_preview_hash'     => md5(''),
            'src_body_hash'        => md5($template->html_content),
        ]);

        config(['services.gemini.api_key' => null]);

        Http::fake(); // no calls expected

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $response->assertStatus(200)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('campaign_template_translations', [
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Safe EN Subject',
        ]);

        Http::assertNothingSent();
    }

    // ── /saveTranslation ──────────────────────────────────────────────────────

    public function test_save_translation_returns_403_without_permission(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->viewOnlyUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation", [
                'language'     => 'en',
                'subject'      => 'Manual EN subject',
                'html_content' => '<p>Manual EN content</p>',
            ]);

        $response->assertStatus(403);
    }

    public function test_save_translation_sets_is_ai_generated_false(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation", [
                'language'     => 'en',
                'subject'      => 'Manual EN subject',
                'html_content' => '<p>Manual EN content</p>',
                'preview_text' => 'Manual preview',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('campaign_template_translations', [
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Manual EN subject',
            'is_ai_generated'      => 0,
            'reviewed_at'          => null,
        ]);
    }

    public function test_save_translation_snapshots_current_base_hashes(): void
    {
        $template = $this->makeTemplate();

        $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation", [
                'language'     => 'en',
                'subject'      => 'Manual EN subject',
                'html_content' => '<p>Manual EN content</p>',
            ]);

        $tr = CampaignTemplateTranslation::where([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
        ])->firstOrFail();

        $expectedHashes = $template->sourceHashes();

        $this->assertSame($expectedHashes['subject'], $tr->src_subject_hash);
        $this->assertSame($expectedHashes['preview'], $tr->src_preview_hash);
        $this->assertSame($expectedHashes['body'], $tr->src_body_hash);
    }

    public function test_save_translation_row_is_not_stale_immediately_after_save(): void
    {
        $template = $this->makeTemplate();

        $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation", [
                'language'     => 'en',
                'subject'      => 'Manual EN subject',
                'html_content' => '<p>Manual EN content</p>',
            ]);

        $tr = CampaignTemplateTranslation::where([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
        ])->firstOrFail();

        $stale = $template->staleFieldsFor($tr);
        $this->assertSame([], $stale, 'Immediately after saveManual the translation must not be stale');
    }

    public function test_save_translation_then_mutate_base_makes_stale(): void
    {
        $template = $this->makeTemplate();

        $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation", [
                'language'     => 'en',
                'subject'      => 'Manual EN subject',
                'html_content' => '<p>Manual EN content</p>',
            ]);

        // Now mutate the base subject
        $template->subject = 'Sujet complètement changé après la traduction';
        $template->save();
        $template->refresh();

        $tr = CampaignTemplateTranslation::where([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
        ])->firstOrFail();

        $stale = $template->staleFieldsFor($tr);
        $this->assertContains('subject', $stale);
    }

    // ── /markReviewed ─────────────────────────────────────────────────────────

    public function test_mark_reviewed_returns_403_without_permission(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->viewOnlyUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation/review", [
                'language' => 'en',
                'reviewed' => true,
            ]);

        $response->assertStatus(403);
    }

    public function test_mark_reviewed_true_sets_reviewed_at(): void
    {
        $template = $this->makeTemplate();
        $hashes = $template->sourceHashes();

        CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'EN subject',
            'html_content'         => '<p>EN content</p>',
            'is_ai_generated'      => true,
            'src_subject_hash'     => $hashes['subject'],
            'src_preview_hash'     => $hashes['preview'],
            'src_body_hash'        => $hashes['body'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation/review", [
                'language' => 'en',
                'reviewed' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('reviewed', true);

        $tr = CampaignTemplateTranslation::where([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
        ])->firstOrFail();

        $this->assertNotNull($tr->reviewed_at);
    }

    public function test_mark_reviewed_false_clears_reviewed_at(): void
    {
        $template = $this->makeTemplate();
        $hashes = $template->sourceHashes();

        CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'EN subject',
            'html_content'         => '<p>EN content</p>',
            'is_ai_generated'      => true,
            'reviewed_at'          => now(),
            'src_subject_hash'     => $hashes['subject'],
            'src_preview_hash'     => $hashes['preview'],
            'src_body_hash'        => $hashes['body'],
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation/review", [
                'language' => 'en',
                'reviewed' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('reviewed', false);

        $tr = CampaignTemplateTranslation::where([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
        ])->firstOrFail();

        $this->assertNull($tr->reviewed_at);
    }

    public function test_mark_reviewed_returns_404_when_no_translation_exists(): void
    {
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translation/review", [
                'language' => 'en',
                'reviewed' => true,
            ]);

        $response->assertStatus(404);
    }

    // ── FIX 3 regression: server-side overwrite guard ─────────────────────────

    /**
     * POST /translate without overwrite, when a manually-edited EN row exists,
     * must return HTTP 409 with requires_confirmation=true and leave the row unchanged.
     */
    public function test_translate_returns_409_for_manual_row_without_overwrite(): void
    {
        $template = $this->makeTemplate();
        $hashes   = $template->sourceHashes();

        // Create a manually-edited EN row (is_ai_generated=false)
        CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Manually edited EN subject',
            'html_content'         => '<p>Manually edited EN content</p>',
            'preview_text'         => 'Manual EN preview',
            'is_ai_generated'      => false,
            'src_subject_hash'     => $hashes['subject'],
            'src_preview_hash'     => $hashes['preview'],
            'src_body_hash'        => $hashes['body'],
        ]);

        // Fake Gemini — should NOT be called (guard fires first)
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(['Hello {{contact.name}}, we offer freight solutions.']),
                200
            ),
        ]);

        config([
            'services.gemini.api_key' => 'fake-key',
            'services.gemini.model'   => 'gemini-2.0-flash',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('requires_confirmation', true);

        // Row must be unchanged — still is_ai_generated=false
        $this->assertDatabaseHas('campaign_template_translations', [
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Manually edited EN subject',
            'is_ai_generated'      => 0,
        ]);
    }

    /**
     * POST /translate with overwrite=1, when a manually-edited EN row exists,
     * must succeed (200) and replace the row (is_ai_generated=true).
     */
    public function test_translate_with_overwrite_replaces_manual_row(): void
    {
        $template = $this->makeTemplate();
        $hashes   = $template->sourceHashes();

        // Create a manually-edited EN row
        CampaignTemplateTranslation::create([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Manually edited EN subject',
            'html_content'         => '<p>Manually edited EN content</p>',
            'preview_text'         => 'Manual EN preview',
            'is_ai_generated'      => false,
            'src_subject_hash'     => $hashes['subject'],
            'src_preview_hash'     => $hashes['preview'],
            'src_body_hash'        => $hashes['body'],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse(['Hello {{contact.name}}, we offer freight solutions.']),
                200
            ),
        ]);

        config([
            'services.gemini.api_key' => 'fake-key',
            'services.gemini.model'   => 'gemini-2.0-flash',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->postJson("/admin/campaign_templates/{$template->id}/translate", [
                'overwrite' => 1,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Row must now be is_ai_generated=true (replaced by AI)
        $this->assertDatabaseHas('campaign_template_translations', [
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'is_ai_generated'      => 1,
        ]);

        // And it must NOT still have the manually-edited subject
        $this->assertDatabaseMissing('campaign_template_translations', [
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Manually edited EN subject',
        ]);
    }
}
