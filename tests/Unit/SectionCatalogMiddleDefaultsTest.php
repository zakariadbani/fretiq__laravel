<?php

namespace Tests\Unit;

use App\Services\Campaign\TemplateBuilder\BuilderStateValidator;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use Tests\TestCase;

/**
 * SectionCatalogMiddleDefaultsTest — SectionCatalog::middleDefaults() is the
 * single source of truth for the real TCL data the builder JS seeds into an
 * absent middle-block slot (campaign-template-builder.js
 * ensureMiddleSlotDefaults()). Asserts every SectionCatalog::MIDDLES variant
 * has a defaults entry, that entry normalizes cleanly through
 * BuilderStateValidator, and no leftover placeholder/legacy copy leaked in.
 */
class SectionCatalogMiddleDefaultsTest extends TestCase
{
    public function test_middle_defaults_covers_every_registered_middle_variant(): void
    {
        $expected = SectionCatalog::MIDDLES;
        $actual   = array_keys(SectionCatalog::middleDefaults());

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * Base slots (hero_title/intro/bullets) shared by every middle variant —
     * mirrors BuilderStateValidatorTest::validState()'s fixture shape.
     *
     * @return array<string, mixed>
     */
    private function baseSlots(): array
    {
        return [
            'hero_title' => 'Optimisez vos flux',
            'intro'      => ['Paragraphe un.', 'Paragraphe deux.'],
            'bullets'    => ['Bullet un', 'Bullet deux', 'Bullet trois'],
        ];
    }

    public function test_each_variant_default_passes_validation_and_normalizes_verbatim(): void
    {
        $middleSlotKeys = ['process_steps', 'process_highlight', 'departures', 'kpis', 'benefits', 'case_study', 'checklist_title', 'checklist_items', 'solutions', 'offer'];

        foreach (SectionCatalog::middleDefaults() as $middle => $defaults) {
            $state = SectionCatalog::defaultState();
            $state['middle_variant'] = $middle;
            $state['slots'] = array_diff_key($state['slots'], array_flip($middleSlotKeys));
            $state['slots'] = array_merge($this->baseSlots(), $state['slots'], $defaults);

            $result = (new BuilderStateValidator())->validate($state);

            foreach ($defaults as $key => $value) {
                $this->assertSame($value, $result['slots'][$key], "middle={$middle} slot={$key}");
            }
        }
    }

    public function test_middle_defaults_contain_no_placeholder_or_legacy_copy(): void
    {
        $encoded = json_encode(SectionCatalog::middleDefaults(), JSON_UNESCAPED_UNICODE);

        $this->assertDoesNotMatchRegularExpression('/picking|48 ?h|25 ans|21 ans|EVERGREEN|un client|à compléter|Collecte|Dégroupement MEAD/i', $encoded);
    }

    public function test_all_solution_urls_target_the_tcl_transport_site(): void
    {
        $solutions = SectionCatalog::middleDefaults()['solutions']['solutions'];
        $baseUrl   = rtrim((string) config('prospecting.site.base_url'), '/') . '/';

        $this->assertCount(4, $solutions);

        foreach ($solutions as $solution) {
            $this->assertStringStartsWith($baseUrl, $solution['url']);
            $this->assertArrayHasKey('link_label', $solution);
            $this->assertIsString($solution['link_label']);
            $this->assertNotSame('', trim($solution['link_label']));
        }
    }
}
