<?php

namespace Tests\Feature\Seeders;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use App\Services\Campaign\TemplateBuilder\BuilderStateValidator;
use App\Services\Campaign\TemplateBuilder\TemplateComposer;
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

        $expectedMiddles = [
            'Famille 1 — Email 1 (J0) — Maîtrise de la contrainte' => 'benefits',
            'Famille 1 — Email 2 (J+4) — Preuve concrète' => 'case_study',
            'Famille 1 — Email 3 (J+9) — Contenu expert' => 'checklist',
            'Famille 1 — Email 4 (J+15) — CTA direct' => 'offer',
            'Famille 2 — Email 1 (J0) — Angle coût' => 'departures',
            'Famille 2 — Email 2 (J+4) — Preuve sociale chiffrée' => 'case_study',
            'Famille 2 — Email 3 (J+9) — Urgence & saisonnalité' => 'checklist',
            'Famille 2 — Email 4 (J+15) — Offre tarifaire' => 'offer',
            'Famille 3 — Email 1 (J0) — Gestion de projet' => 'process',
            'Famille 3 — Email 2 (J+4) — Cas concret' => 'case_study',
            'Famille 3 — Email 3 (J+9) — Accompagnement amont' => 'solutions',
            'Famille 3 — Email 4 (J+15) — CTA planning' => 'offer',
        ];
        $expectedIntents = array_fill_keys(array_keys($expectedMiddles), 'services');
        foreach ([
            'Famille 1 — Email 4 (J+15) — CTA direct',
            'Famille 2 — Email 1 (J0) — Angle coût',
            'Famille 2 — Email 2 (J+4) — Preuve sociale chiffrée',
            'Famille 2 — Email 4 (J+15) — Offre tarifaire',
        ] as $name) {
            $expectedIntents[$name] = 'quote';
        }
        $expectedIntents['Famille 3 — Email 4 (J+15) — CTA planning'] = 'warehouse_tour';
        $expectedBullets = [
            1 => ['Gestion dédiée des marchandises sensibles ou réglementées', 'Coordination directe avec les compagnies aériennes grâce à notre statut IATA', 'Maîtrise documentaire et douanière de bout en bout'],
            2 => ['Départs réguliers depuis la France, l’Espagne et le Portugal', 'Groupage optimisé pour réduire le coût unitaire', 'Capacités FCL/LCL planifiées selon vos volumes'],
            3 => ['Pilotage logistique de bout en bout, du sourcing au chantier', 'Stockage flexible en MEAD avec WMS et picking', 'Livraisons coordonnées avec le planning du projet'],
        ];
        $expectedEnglishBullets = [
            1 => ['Dedicated handling of sensitive or regulated goods', 'Direct airline coordination through our IATA agent status', 'End-to-end customs and documentation control'],
            2 => ['Regular departures from France, Spain and Portugal', 'Optimised consolidation to reduce unit transport costs', 'FCL/LCL capacity planned around your volumes'],
            3 => ['End-to-end logistics management, from sourcing to the project site', 'Flexible customs-bonded storage with WMS and picking', 'Deliveries coordinated with the project schedule'],
        ];

        foreach ($templates as $template) {
            $translation = $template->translationFor('en');
            preg_match('/Famille ([123])/', $template->name, $familyMatch);
            $family = (int) $familyMatch[1];

            $this->assertNotNull($translation, $template->name);
            $this->assertNotSame($template->subject, $translation->subject, $template->name);
            $this->assertNotSame($template->preview_text, $translation->preview_text, $template->name);
            $this->assertNotSame($template->html_content, $translation->html_content, $template->name);
            $this->assertFalse($translation->is_ai_generated, $template->name);
            $this->assertNull($translation->reviewed_at, $template->name);
            $this->assertSame('logo_tagline', $template->builder_state['header_variant'], $template->name);
            $this->assertSame('navy', $template->builder_state['hero_variant'], $template->name);
            $this->assertSame('compact', $template->builder_state['footer_variant'], $template->name);
            $this->assertSame($expectedMiddles[$template->name], $template->builder_state['middle_variant'], $template->name);
            $this->assertSame($expectedIntents[$template->name], $template->builder_state['cta']['intent'], $template->name);
            $this->assertSame($expectedBullets[$family], $template->builder_state['slots']['bullets'], $template->name);
            foreach ($expectedEnglishBullets[$family] as $bullet) {
                $this->assertStringContainsString($bullet, $translation->html_content, $template->name);
            }
            $this->assertNotContains($template->builder_state['slots']['closing_line'], $template->builder_state['slots']['bullets'], $template->name);
            $this->assertSame($template->preview_text, $template->builder_state['preview_text'], $template->name);
            $this->assertEquals($template->builder_state, app(BuilderStateValidator::class)->validate($template->builder_state), $template->name);
            $this->assertSame($template->html_content, app(TemplateComposer::class)->compose($template->builder_state), $template->name);
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

        $last = $templates->firstWhere('name', 'Famille 3 — Email 4 (J+15) — CTA planning');
        $tourUrl = 'https://tcltransport.com/visite-virtuelle-360/entrepot/';
        $this->assertSame('Visiter nos entrepôts en 3D', $last->builder_state['cta']['label']);
        $this->assertSame('Échange de 20 minutes', $last->builder_state['slots']['offer']['highlight']);
        $this->assertStringNotContainsString('https://', implode(' ', $last->builder_state['slots']['intro']));
        $this->assertStringNotContainsString('https://', $last->builder_state['slots']['offer']['description']);
        $this->assertStringContainsString('href="' . $tourUrl . '"', $last->html_content);
        $this->assertStringContainsString('Take a 3D tour of our warehouses', $last->translationFor('en')->html_content);
        $this->assertStringContainsString('20-minute discussion', $last->translationFor('en')->html_content);
    }

    public function test_untouched_legacy_template_is_upgraded_to_corrected_builder_content(): void
    {
        $this->seedDependenciesAndFamilles();

        $legacy = CampaignTemplate::where('name', 'Famille 3 — Email 4 (J+15) — CTA planning')->firstOrFail();
        $translation = $legacy->translationFor('en');
        $method = new \ReflectionMethod(TclFamilleSequenceSeeder::class, 'famille3Templates');
        $method->setAccessible(true);
        $definition = $method->invoke(app(TclFamilleSequenceSeeder::class))[3];

        CampaignTemplate::whereKey($legacy->id)->update([
            'subject' => $definition['subject'],
            'preview_text' => $definition['preview_text'],
            'html_content' => $definition['html_content'],
            'builder_state' => null,
            'updated_at' => $legacy->created_at,
        ]);
        CampaignTemplateTranslation::whereKey($translation->id)->update([
            'subject' => 'Manually corrected legacy translation',
            'is_ai_generated' => false,
        ]);

        $this->seed(TclFamilleSequenceSeeder::class);

        $legacy->refresh();
        $translation->refresh();
        $this->assertSame('warehouse_tour', $legacy->builder_state['cta']['intent']);
        $this->assertSame('Visiter nos entrepôts en 3D', $legacy->builder_state['cta']['label']);
        $this->assertStringNotContainsString('https://', implode(' ', $legacy->builder_state['slots']['intro']));
        $this->assertSame('Manually corrected legacy translation', $translation->subject);
        $this->assertFalse($translation->is_ai_generated);
    }
    public function test_rerun_preserves_existing_classic_builder_and_manual_translation_edits(): void
    {
        $this->seedDependenciesAndFamilles();

        $classic = CampaignTemplate::firstOrFail();
        $classicTranslation = $classic->translationFor('en');
        $classic->update([
            'subject' => 'Sujet HTML modifié manuellement',
            'preview_text' => 'Aperçu HTML modifié manuellement',
            'html_content' => '<p>Contenu HTML modifié manuellement</p>',
            'builder_state' => null,
        ]);
        $classicTranslation->update([
            'subject' => 'Manually edited classic subject',
            'html_content' => '<p>Manually edited classic English HTML</p>',
            'is_ai_generated' => false,
        ]);

        $builder = CampaignTemplate::where('id', '!=', $classic->id)->firstOrFail();
        $builderTranslation = $builder->translationFor('en');
        $manualState = $builder->builder_state;
        $manualState['slots']['hero_title'] = 'Titre modifié manuellement';
        $manualHtml = app(TemplateComposer::class)->compose($manualState);
        $builder->update([
            'subject' => 'Sujet builder modifié manuellement',
            'preview_text' => 'Aperçu builder modifié manuellement',
            'html_content' => $manualHtml,
            'builder_state' => $manualState,
        ]);
        $builderTranslation->update([
            'subject' => 'Manually edited builder subject',
            'html_content' => '<p>Manually edited builder English HTML</p>',
            'is_ai_generated' => false,
            'reviewed_at' => now(),
        ]);

        $this->seed(TclFamilleSequenceSeeder::class);

        $this->assertSame(12, CampaignTemplate::where('name', 'like', 'Famille %')->count());
        $this->assertSame(12, CampaignTemplateTranslation::where('language', 'en')->count());
        $classic->refresh();
        $classicTranslation->refresh();
        $builder->refresh();
        $builderTranslation->refresh();

        $this->assertSame('Sujet HTML modifié manuellement', $classic->subject);
        $this->assertSame('<p>Contenu HTML modifié manuellement</p>', $classic->html_content);
        $this->assertNull($classic->builder_state);
        $this->assertSame('Manually edited classic subject', $classicTranslation->subject);
        $this->assertSame('<p>Manually edited classic English HTML</p>', $classicTranslation->html_content);

        $this->assertSame('Sujet builder modifié manuellement', $builder->subject);
        $this->assertSame($manualHtml, $builder->html_content);
        $this->assertSame($manualState, $builder->builder_state);
        $this->assertSame('Manually edited builder subject', $builderTranslation->subject);
        $this->assertSame('<p>Manually edited builder English HTML</p>', $builderTranslation->html_content);
        $this->assertNotNull($builderTranslation->reviewed_at);
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
