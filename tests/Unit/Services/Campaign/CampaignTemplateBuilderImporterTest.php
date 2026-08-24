<?php

namespace Tests\Unit\Services\Campaign;

use App\Models\CampaignTemplate;
use App\Models\SenderIdentity;
use App\Services\Campaign\CampaignTemplateBuilderImporter;
use App\Services\Campaign\CampaignTemplateHtmlImporter;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use App\Services\Campaign\TemplateBuilder\TemplateComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignTemplateBuilderImporterTest extends TestCase
{
    use RefreshDatabase;

    private function validJson(string $name = 'Relance builder', string $subject = 'On vous a manqué ?'): string
    {
        return json_encode([
            'name' => $name,
            'subject' => $subject,
            'preview_text' => 'Un aperçu de test.',
            'builder_state' => SectionCatalog::defaultState(),
        ], JSON_THROW_ON_ERROR);
    }

    public function test_it_parses_a_valid_json_file_into_a_composed_builder_row(): void
    {
        $importer = app(CampaignTemplateBuilderImporter::class);

        $rows = $importer->parse([
            ['filename' => 'relance.json', 'contents' => $this->validJson()],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Relance builder', $rows[0]['name']);
        $this->assertSame('On vous a manqué ?', $rows[0]['subject']);
        $this->assertSame('builder', $rows[0]['mode']);
        $this->assertFalse($rows[0]['will_update']);
        $this->assertIsArray($rows[0]['builder_state']);
        $this->assertStringStartsWith('<!DOCTYPE html', trim($rows[0]['html_content']));
        // Falls back to the literal default sender email — no SenderIdentity seeded in this test.
        $this->assertStringContainsString('mailto:sales@tcltransport.com', $rows[0]['html_content']);
    }

    public function test_it_rejects_a_json_file_with_an_unknown_variant(): void
    {
        $state = SectionCatalog::defaultState();
        $state['header_variant'] = 'not_a_real_variant';

        $json = json_encode([
            'name' => 'Mauvais variant',
            'subject' => 'Sujet',
            'builder_state' => $state,
        ], JSON_THROW_ON_ERROR);

        $importer = app(CampaignTemplateBuilderImporter::class);

        try {
            $importer->parse([['filename' => 'bad.json', 'contents' => $json]]);
            $this->fail('Expected a validation exception for an unknown variant.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'La valeur sélectionnée pour header variant est invalide.',
                implode(' ', $exception->errors()['json'] ?? [])
            );
        }

        $this->assertDatabaseCount('campaign_templates', 0);
    }

    public function test_it_rejects_a_json_file_with_an_out_of_bounds_slot(): void
    {
        $state = SectionCatalog::defaultState();
        $state['middle_variant'] = 'kpi';
        // kpi requires exactly SectionCatalog::KPI_COUNT rows — two is out of bounds.
        $state['slots']['kpis'] = [
            ['value' => '48 h', 'label' => 'Enlèvement'],
            ['value' => 'IATA', 'label' => 'Agent agréé'],
        ];

        $json = json_encode([
            'name' => 'Slot hors borne',
            'subject' => 'Sujet',
            'builder_state' => $state,
        ], JSON_THROW_ON_ERROR);

        $importer = app(CampaignTemplateBuilderImporter::class);

        try {
            $importer->parse([['filename' => 'bad.json', 'contents' => $json]]);
            $this->fail('Expected a validation exception for an out-of-bounds slot.');
        } catch (ValidationException $exception) {
            // Pins the SPECIFIC error (kpis size) — not just that SOME error
            // landed in the 'json' bag, which would also pass if the state
            // failed for an unrelated reason.
            $this->assertStringContainsString(
                'Le champ slots.kpis doit contenir 3 éléments.',
                implode(' ', $exception->errors()['json'] ?? [])
            );
        }
    }

    public function test_it_rejects_a_json_file_with_a_forbidden_merge_tag(): void
    {
        $state = SectionCatalog::defaultState();
        $state['slots']['hero_title'] = 'Titre avec {{unsubscribe_url}}';

        $json = json_encode([
            'name' => 'Tag interdit',
            'subject' => 'Sujet',
            'builder_state' => $state,
        ], JSON_THROW_ON_ERROR);

        $importer = app(CampaignTemplateBuilderImporter::class);

        try {
            $importer->parse([['filename' => 'bad.json', 'contents' => $json]]);
            $this->fail('Expected a validation exception for a forbidden merge tag.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'Tag de fusion non autorisé : {{unsubscribe_url}}',
                implode(' ', $exception->errors()['json'] ?? [])
            );
        }
    }

    public function test_store_upsert_writes_builder_state_and_composed_html(): void
    {
        SenderIdentity::create([
            'name' => 'Sales — TCL France',
            'email' => 'sales@tcltransport.com',
            'is_default' => true,
            'is_active' => true,
        ]);

        $importer = app(CampaignTemplateBuilderImporter::class);
        $rows = $importer->parse([
            ['filename' => 'relance.json', 'contents' => $this->validJson()],
        ]);

        $result = $importer->store($rows);
        $this->assertSame(['created' => 1, 'updated' => 0], $result);

        $template = CampaignTemplate::where('name', 'Relance builder')->firstOrFail();
        $this->assertNotNull($template->builder_state);
        $this->assertSame(SectionCatalog::defaultState()['header_variant'], $template->builder_state['header_variant']);
        $this->assertSame($rows[0]['html_content'], $template->html_content);

        // Re-importing (case-insensitive) upserts by name rather than duplicating.
        $again = $importer->parse([
            ['filename' => 'RELANCE.json', 'contents' => $this->validJson('RELANCE BUILDER')],
        ]);
        $this->assertTrue($again[0]['will_update']);
        $result = $importer->store($again);
        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertDatabaseCount('campaign_templates', 1);
    }

    public function test_html_importer_store_nullifies_a_stale_builder_state_on_upsert(): void
    {
        $builderImporter = app(CampaignTemplateBuilderImporter::class);
        $builderRows = $builderImporter->parse([
            ['filename' => 'relance.json', 'contents' => $this->validJson('Modèle partagé')],
        ]);
        $builderImporter->store($builderRows);

        $template = CampaignTemplate::where('name', 'Modèle partagé')->firstOrFail();
        $this->assertNotNull($template->builder_state);

        $htmlImporter = app(CampaignTemplateHtmlImporter::class);
        $htmlContents = "<!--\nNom : Modèle partagé\nObjet : Sujet HTML\n-->\n<p>Bonjour {{contact.first_name}}.</p>";
        $htmlRows = $htmlImporter->parse([
            ['filename' => 'modele.html', 'contents' => $htmlContents],
        ]);
        $this->assertTrue($htmlRows[0]['will_update']);
        $htmlImporter->store($htmlRows);

        $template->refresh();
        $this->assertNull($template->builder_state);
        $this->assertSame('Sujet HTML', $template->subject);
    }

    public function test_top_level_preview_text_wins_over_builder_state_preview_text(): void
    {
        $state = SectionCatalog::defaultState();
        $state['preview_text'] = 'Aperçu venant du builder_state';

        $json = json_encode([
            'name' => 'Préséance aperçu',
            'subject' => 'Sujet',
            'preview_text' => 'Aperçu venant du top-level',
            'builder_state' => $state,
        ], JSON_THROW_ON_ERROR);

        $rows = app(CampaignTemplateBuilderImporter::class)->parse([
            ['filename' => 'preview.json', 'contents' => $json],
        ]);

        $this->assertSame('Aperçu venant du top-level', $rows[0]['preview_text']);
        $this->assertSame('Aperçu venant du top-level', $rows[0]['builder_state']['preview_text']);
    }

    public function test_it_rejects_malformed_json(): void
    {
        $importer = app(CampaignTemplateBuilderImporter::class);

        try {
            $importer->parse([['filename' => 'bad.json', 'contents' => '{not valid json']]);
            $this->fail('Expected a validation exception for malformed JSON.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('JSON malformé', implode(' ', $exception->errors()['json'] ?? []));
        }
    }

    public function test_it_rejects_a_top_level_json_scalar(): void
    {
        $importer = app(CampaignTemplateBuilderImporter::class);

        try {
            $importer->parse([['filename' => 'scalar.json', 'contents' => '"just a string"']]);
            $this->fail('Expected a validation exception for a non-object JSON payload.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('objet attendu', implode(' ', $exception->errors()['json'] ?? []));
        }
    }

    public function test_it_rejects_a_top_level_json_list(): void
    {
        $importer = app(CampaignTemplateBuilderImporter::class);
        $json = json_encode([['name' => 'a'], ['name' => 'b']], JSON_THROW_ON_ERROR);

        try {
            $importer->parse([['filename' => 'list.json', 'contents' => $json]]);
            $this->fail('Expected a validation exception for a top-level JSON list.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('objet attendu', implode(' ', $exception->errors()['json'] ?? []));
        }
    }

    public function test_it_rejects_duplicate_names_within_one_json_batch(): void
    {
        $importer = app(CampaignTemplateBuilderImporter::class);

        try {
            $importer->parse([
                ['filename' => 'a.json', 'contents' => $this->validJson('Même nom')],
                ['filename' => 'b.json', 'contents' => $this->validJson('Même nom')],
            ]);
            $this->fail('Expected a validation exception for a duplicate name within the batch.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'déjà utilisé par un autre fichier',
                implode(' ', $exception->errors()['json'] ?? [])
            );
        }
    }

    public function test_it_rejects_when_the_total_composed_bytes_exceed_the_batch_cap(): void
    {
        // The real composed HTML for a valid state is only ~13 KB, so 20
        // files (MAX_FILES) can never organically reach the 2 MB
        // MAX_TOTAL_BYTES cap. Swap in a fake composer that returns an
        // oversized string via the container binding parse() already
        // resolves through app() — exercises the real summation/cap check
        // without fabricating an unrealistic builder_state.
        $this->app->bind(TemplateComposer::class, fn () => new class extends TemplateComposer
        {
            public function compose(array $state, string $locale = 'fr', ?string $contactEmail = null): string
            {
                return str_repeat('x', 150000);
            }
        });

        $files = [];
        for ($i = 0; $i < 15; $i++) {
            $files[] = ['filename' => "modele{$i}.json", 'contents' => $this->validJson("Modèle {$i}")];
        }

        try {
            app(CampaignTemplateBuilderImporter::class)->parse($files);
            $this->fail('Expected a validation exception for exceeding the total bytes cap.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('dépasse 2 Mo', implode(' ', $exception->errors()['json'] ?? []));
        }
    }
}
