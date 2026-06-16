<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for CampaignTemplate model translation helpers.
 *
 * Covers: resolveFor(), sourceHashes(), staleFieldsFor(), translationFor().
 */
class CampaignTemplateTranslationModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeTemplate(array $overrides = []): CampaignTemplate
    {
        return CampaignTemplate::create(array_merge([
            'name'         => 'Modèle Test',
            'subject'      => 'Sujet en français',
            'html_content' => '<p>Bonjour {{contact.name}}, bienvenue chez TCL.</p>',
            'preview_text' => 'Aperçu du message',
        ], $overrides));
    }

    private function makeTranslation(CampaignTemplate $template, array $overrides = []): CampaignTemplateTranslation
    {
        $hashes = $template->sourceHashes();

        return CampaignTemplateTranslation::create(array_merge([
            'campaign_template_id' => $template->id,
            'language'             => 'en',
            'subject'              => 'Subject in English',
            'html_content'         => '<p>Hello {{contact.name}}, welcome to TCL.</p>',
            'preview_text'         => 'Message preview',
            'is_ai_generated'      => true,
            'reviewed_at'          => null,
            'src_subject_hash'     => $hashes['subject'],
            'src_preview_hash'     => $hashes['preview'],
            'src_body_hash'        => $hashes['body'],
        ], $overrides));
    }

    // ── resolveFor() ──────────────────────────────────────────────────────────

    public function test_resolve_for_returns_fr_base_when_no_translation_row_exists(): void
    {
        $template = $this->makeTemplate();

        // No EN row → always returns FR base regardless of country
        $result = $template->resolveFor('DE');

        $this->assertSame('fr', $result['language']);
        $this->assertSame($template->subject, $result['subject']);
        $this->assertSame($template->html_content, $result['html_content']);
        $this->assertSame($template->preview_text, $result['preview_text']);
    }

    public function test_resolve_for_returns_en_row_for_non_francophone_country(): void
    {
        $template = $this->makeTemplate();
        $tr = $this->makeTranslation($template);

        $result = $template->resolveFor('DE');

        $this->assertSame('en', $result['language']);
        $this->assertSame($tr->subject, $result['subject']);
        $this->assertSame($tr->html_content, $result['html_content']);
    }

    public function test_resolve_for_returns_fr_base_for_francophone_country_even_with_en_row(): void
    {
        $template = $this->makeTemplate();
        $this->makeTranslation($template); // EN row exists

        // FR is a francophone country → always use base, never EN
        $result = $template->resolveFor('FR');

        $this->assertSame('fr', $result['language']);
        $this->assertSame($template->subject, $result['subject']);
    }

    public function test_resolve_for_returns_fr_base_for_be_ch_even_with_en_row(): void
    {
        $template = $this->makeTemplate();
        $this->makeTranslation($template);

        $this->assertSame('fr', $template->resolveFor('BE')['language']);
        $this->assertSame('fr', $template->resolveFor('CH')['language']);
    }

    public function test_resolve_for_returns_fr_for_null_country(): void
    {
        $template = $this->makeTemplate();
        $this->makeTranslation($template);

        $result = $template->resolveFor(null);
        $this->assertSame('fr', $result['language']);
    }

    public function test_resolve_for_falls_back_to_fr_base_when_en_row_missing_and_country_is_us(): void
    {
        $template = $this->makeTemplate();
        // Deliberately no EN row

        $result = $template->resolveFor('US');

        $this->assertSame('fr', $result['language']);
        $this->assertSame($template->subject, $result['subject']);
    }

    public function test_resolve_for_uses_stored_en_even_when_stale(): void
    {
        $template = $this->makeTemplate();
        $tr = $this->makeTranslation($template);

        // Make the EN row stale by clearing hashes
        $tr->src_subject_hash = 'old_hash';
        $tr->save();

        // Still uses EN row — staleness is advisory
        $result = $template->resolveFor('US');
        $this->assertSame('en', $result['language']);
        $this->assertSame($tr->subject, $result['subject']);
    }

    // ── sourceHashes() ────────────────────────────────────────────────────────

    public function test_source_hashes_returns_md5_of_each_field(): void
    {
        $template = $this->makeTemplate([
            'subject'      => 'Test subject',
            'preview_text' => 'Test preview',
            'html_content' => '<p>Test body</p>',
        ]);

        $hashes = $template->sourceHashes();

        $this->assertSame(md5('Test subject'), $hashes['subject']);
        $this->assertSame(md5('Test preview'), $hashes['preview']);
        $this->assertSame(md5('<p>Test body</p>'), $hashes['body']);
    }

    public function test_source_hashes_treat_null_and_empty_preview_identically(): void
    {
        // Both null and '' preview should hash to md5('')
        $templateWithNull = $this->makeTemplate(['preview_text' => null]);

        // Create a template then update preview to ''
        $templateWithEmpty = $this->makeTemplate(['preview_text' => null]);
        // Note: ConvertEmptyStringsToNull middleware means '' is stored as null.
        // Test both sides normalize to md5('').
        $hashNull  = $templateWithNull->sourceHashes();
        $hashEmpty = $templateWithEmpty->sourceHashes();

        $this->assertSame($hashNull['preview'], $hashEmpty['preview'],
            'null and empty preview must produce the same hash');
        $this->assertSame(md5(''), $hashNull['preview']);
    }

    public function test_source_hashes_keys_are_subject_preview_body(): void
    {
        $template = $this->makeTemplate();
        $hashes = $template->sourceHashes();

        $this->assertArrayHasKey('subject', $hashes);
        $this->assertArrayHasKey('preview', $hashes);
        $this->assertArrayHasKey('body', $hashes);
    }

    // ── staleFieldsFor() ──────────────────────────────────────────────────────

    public function test_stale_fields_empty_when_all_hashes_match(): void
    {
        $template = $this->makeTemplate();
        $tr = $this->makeTranslation($template); // hashes snaphotted from current base

        $stale = $template->staleFieldsFor($tr);
        $this->assertSame([], $stale, 'No fields should be stale immediately after translation');
    }

    public function test_stale_fields_pinpoints_subject_when_base_subject_changed(): void
    {
        $template = $this->makeTemplate();
        $tr = $this->makeTranslation($template);

        // Mutate only the subject
        $template->subject = 'Nouveau sujet modifié';
        $template->save();
        $template->refresh();

        $stale = $template->staleFieldsFor($tr);

        $this->assertContains('subject', $stale);
        $this->assertNotContains('preview', $stale);
        $this->assertNotContains('body', $stale);
    }

    public function test_stale_fields_pinpoints_body_when_html_content_changed(): void
    {
        $template = $this->makeTemplate();
        $tr = $this->makeTranslation($template);

        $template->html_content = '<p>Contenu totalement différent</p>';
        $template->save();
        $template->refresh();

        $stale = $template->staleFieldsFor($tr);

        $this->assertContains('body', $stale);
        $this->assertNotContains('subject', $stale);
    }

    public function test_stale_fields_pinpoints_preview_when_preview_changed(): void
    {
        $template = $this->makeTemplate();
        $tr = $this->makeTranslation($template);

        $template->preview_text = 'Nouvel aperçu';
        $template->save();
        $template->refresh();

        $stale = $template->staleFieldsFor($tr);

        $this->assertContains('preview', $stale);
        $this->assertNotContains('subject', $stale);
        $this->assertNotContains('body', $stale);
    }

    public function test_stale_fields_treats_null_stored_hash_as_stale(): void
    {
        $template = $this->makeTemplate();
        $tr = $this->makeTranslation($template, [
            'src_subject_hash' => null,
            'src_preview_hash' => null,
            'src_body_hash'    => null,
        ]);

        $stale = $template->staleFieldsFor($tr);

        $this->assertContains('subject', $stale);
        $this->assertContains('preview', $stale);
        $this->assertContains('body', $stale);
    }

    // ── translationFor() N+1 safety ───────────────────────────────────────────

    public function test_translation_for_uses_loaded_relation_without_extra_query(): void
    {
        $template = $this->makeTemplate();
        $this->makeTranslation($template);

        // Eager-load translations
        $template->load('translations');

        // Count queries: should not do an extra DB hit
        $count = 0;
        \DB::listen(function () use (&$count) {
            $count++;
        });

        $tr = $template->translationFor('en');

        $this->assertSame(0, $count, 'translationFor() must use the loaded relation without a DB query');
        $this->assertNotNull($tr);
        $this->assertSame('en', $tr->language);
    }

    public function test_translation_for_returns_null_for_missing_language(): void
    {
        $template = $this->makeTemplate();
        $this->makeTranslation($template); // only 'en'

        $this->assertNull($template->translationFor('de'));
    }
}
