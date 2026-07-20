<?php

namespace Tests\Feature\Seeders;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use Database\Seeders\DefaultProspectionSeeder;
use Database\Seeders\ProspectCriteriaSeeder;
use Database\Seeders\TclFamilleSequenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TclFamilleSequenceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_fresh_english_translations_for_all_famille_templates(): void
    {
        $this->seedDependenciesAndFamilles();

        $templates = CampaignTemplate::where('name', 'like', 'Famille %')->with('translations')->get();

        $this->assertCount(12, $templates);
        $this->assertSame(12, CampaignTemplateTranslation::where('language', 'en')->count());

        foreach ($templates as $template) {
            $translation = $template->translationFor('en');

            $this->assertNotNull($translation, $template->name);
            $this->assertNotSame($template->subject, $translation->subject, $template->name);
            $this->assertNotSame($template->preview_text, $translation->preview_text, $template->name);
            $this->assertNotSame($template->html_content, $translation->html_content, $template->name);
            $this->assertTrue($translation->is_ai_generated, $template->name);
            $this->assertNull($translation->reviewed_at, $template->name);
            $this->assertSame($template->sourceHashes(), [
                'subject' => $translation->src_subject_hash,
                'preview' => $translation->src_preview_hash,
                'body' => $translation->src_body_hash,
            ], $template->name);
            $this->assertSame([], $template->staleFieldsFor($translation), $template->name);
            $this->assertSame($this->mergeTags($template->subject . $template->preview_text . $template->html_content),
                $this->mergeTags($translation->subject . $translation->preview_text . $translation->html_content),
                $template->name);
            $this->assertSame($this->htmlTagStructure($template->html_content),
                $this->htmlTagStructure($translation->html_content),
                $template->name);
            $this->assertStringContainsString('TCL Transport', $translation->html_content, $template->name);
        }

        $first = $templates->firstWhere('name', 'Famille 1 — Email 1 (J0) — Maîtrise de la contrainte');
        $this->assertSame('Secure your sensitive imports from Europe — {{company.name}}', $first->translationFor('en')->subject);
        $this->assertStringContainsString('IATA agent', $first->translationFor('en')->html_content);
    }

    public function test_famille_translations_are_idempotently_upserted(): void
    {
        $this->seedDependenciesAndFamilles();
        $translation = CampaignTemplateTranslation::first();
        $translation->update(['subject' => 'Outdated', 'reviewed_at' => now(), 'is_ai_generated' => false]);

        $this->seed(TclFamilleSequenceSeeder::class);

        $this->assertSame(12, CampaignTemplate::where('name', 'like', 'Famille %')->count());
        $this->assertSame(12, CampaignTemplateTranslation::where('language', 'en')->count());
        $translation->refresh();
        $this->assertNotSame('Outdated', $translation->subject);
        $this->assertTrue($translation->is_ai_generated);
        $this->assertNull($translation->reviewed_at);
    }

    public function test_database_seeder_no_longer_calls_legacy_sequence_seeder(): void
    {
        $source = file_get_contents(database_path('seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString(
            '\\Database\\Seeders\\' . 'SequenceSeeder::class',
            $source,
        );
    }

    private function seedDependenciesAndFamilles(): void
    {
        $this->seed(DefaultProspectionSeeder::class);
        $this->seed(ProspectCriteriaSeeder::class);
        $this->seed(TclFamilleSequenceSeeder::class);
    }

    /** @return array<int, string> */
    private function mergeTags(string $content): array
    {
        preg_match_all('/\{\{[^}]+\}\}/', $content, $matches);
        sort($matches[0]);

        return $matches[0];
    }

    /** @return array<int, string> */
    private function htmlTagStructure(string $html): array
    {
        preg_match_all('/<\/?[a-z][^>]*>/i', $html, $matches);

        return array_map(
            static fn (string $tag): string => preg_replace('/(<\/?[a-z]+).*/i', '$1>', $tag),
            $matches[0],
        );
    }
}
