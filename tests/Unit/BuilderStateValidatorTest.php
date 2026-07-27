<?php

namespace Tests\Unit;

use App\Services\Campaign\TemplateBuilder\BuilderStateValidator;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * BuilderStateValidatorTest — the field-level validation authority for
 * builder_state (validate()) and AI suggestions (validateSuggestion()).
 */
class BuilderStateValidatorTest extends TestCase
{
    private function validator(): BuilderStateValidator
    {
        return new BuilderStateValidator();
    }

    /**
     * @param  array<string, mixed>  $slotOverrides
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validState(string $middle = 'departures', array $slotOverrides = [], array $overrides = []): array
    {
        $slots = [
            'hero_title' => 'Optimisez vos flux',
            'intro'      => ['Paragraphe un.', 'Paragraphe deux.'],
            'bullets'    => ['Bullet un', 'Bullet deux', 'Bullet trois'],
        ];

        if ($middle === 'departures') {
            $slots['departures'] = [
                ['origin' => 'Goussainville (France)', 'frequency' => '4 départs par semaine'],
                ['origin' => 'Barcelone (Espagne)', 'frequency' => '2 à 3 départs par semaine'],
            ];
        } elseif ($middle === 'kpi') {
            $slots['kpis'] = [
                ['value' => '48 h', 'label' => 'Enlèvement'],
                ['value' => 'IATA', 'label' => 'Agent agréé'],
                ['value' => '100 %', 'label' => 'Suivi documentaire'],
            ];
        } elseif ($middle === 'benefits') {
            $slots['benefits'] = [
                ['title' => 'Réactivité', 'text' => 'Un interlocuteur mobilisé.'],
                ['title' => 'Visibilité', 'text' => 'Informations à chaque étape.'],
                ['title' => 'Souplesse', 'text' => 'Réponse adaptée à vos délais.'],
            ];
        } elseif ($middle === 'process') {
            $slots['process_steps'] = ['Collecte', 'Acheminement', 'Dégroupement MEAD'];
            $slots['process_highlight'] = 'Des solutions logistiques sur mesure adaptées à chaque besoin.';
        } elseif ($middle === 'case_study') {
            $slots['case_study'] = [
                'title' => 'Un flux urgent sécurisé',
                'challenge' => 'Réduire les ruptures sur un axe critique.',
                'solution' => 'Une réponse TCL coordonnée de bout en bout.',
                'result' => 'Des livraisons stabilisées.',
            ];
        } elseif ($middle === 'checklist') {
            $slots['checklist_title'] = 'Votre départ est-il prêt ?';
            $slots['checklist_items'] = ['Documents validés', 'Marchandise prête', 'Contact confirmé'];
        } elseif ($middle === 'solutions') {
            $slots['solutions'] = [
                ['title' => 'Route', 'text' => 'Des départs adaptés à vos délais.'],
                ['title' => 'Aérien', 'text' => 'Une réponse pour vos urgences.'],
            ];
        } elseif ($middle === 'offer') {
            $slots['offer'] = [
                'title' => 'Une solution dédiée',
                'description' => 'Construisons un schéma transport adapté à votre besoin.',
                'highlight' => 'Étude personnalisée',
            ];
        }

        $slots = array_replace($slots, $slotOverrides);

        return array_replace([
            'header_variant' => 'logo_center',
            'hero_variant'   => 'white',
            'middle_variant' => $middle,
            'footer_variant' => 'detailed',
            'preview_text'   => 'Un aperçu.',
            'cta'            => ['intent' => 'quote', 'label' => 'Demander une cotation'],
            'slots'          => $slots,
        ], $overrides);
    }

    // ── Happy path / normalize ───────────────────────────────────────────────

    public function test_valid_state_normalizes_and_trims(): void
    {
        $state = $this->validState('departures', overrides: [
            'cta' => ['intent' => 'quote', 'label' => '  Demander une cotation  '],
        ]);
        $state['slots']['hero_title'] = '  Optimisez vos flux  ';

        $result = $this->validator()->validate($state);

        $this->assertSame('Optimisez vos flux', $result['slots']['hero_title']);
        $this->assertSame('Demander une cotation', $result['cta']['label']);
        $this->assertSame('departures', $result['middle_variant']);
        $this->assertArrayHasKey('departures', $result['slots']);
        $this->assertArrayNotHasKey('kpis', $result['slots']);
        $this->assertArrayNotHasKey('benefits', $result['slots']);
        $this->assertArrayNotHasKey('process_steps', $result['slots']);
        $this->assertArrayNotHasKey('process_highlight', $result['slots']);
    }
    public function test_include_first_name_defaults_true_preserves_false_and_rejects_non_boolean(): void
    {
        $legacy = $this->validator()->validate($this->validState());
        $disabled = $this->validator()->validate($this->validState(overrides: [
            'include_first_name' => false,
        ]));

        $this->assertTrue($legacy['include_first_name']);
        $this->assertFalse($disabled['include_first_name']);

        $invalid = $this->validState(overrides: ['include_first_name' => 'yes']);

        $this->expectException(ValidationException::class);
        $this->validator()->validate($invalid);
    }


    public function test_valid_state_for_each_middle_variant_passes(): void
    {
        foreach (SectionCatalog::MIDDLES as $middle) {
            $result = $this->validator()->validate($this->validState($middle));

            $this->assertSame($middle, $result['middle_variant'], "middle={$middle}");
        }
    }

    public function test_new_middle_variants_are_registered_and_normalized(): void
    {
        $this->assertSame(
            ['process', 'departures', 'kpi', 'benefits', 'case_study', 'checklist', 'solutions', 'offer'],
            SectionCatalog::MIDDLES
        );

        $caseStudy = $this->validState('case_study');
        $caseStudy['slots']['case_study']['title'] = '  Un flux urgent sécurisé  ';
        $result = $this->validator()->validate($caseStudy);

        $this->assertSame('Un flux urgent sécurisé', $result['slots']['case_study']['title']);
        $this->assertArrayNotHasKey('kpis', $result['slots']);
    }

    public function test_new_middle_nested_unknown_key_is_rejected(): void
    {
        $state = $this->validState('offer');
        $state['slots']['offer']['html'] = '<b>non</b>';

        try {
            $this->validator()->validate($state);
            $this->fail('Expected nested unknown offer key to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots.offer', $e->errors());
        }
    }

    public function test_checklist_cardinality_and_solution_cardinality_are_bounded(): void
    {
        foreach ([
            $this->validState('checklist', ['checklist_items' => ['Un', 'Deux']]),
            $this->validState('solutions', ['solutions' => [['title' => 'Route', 'text' => 'Texte']]]),
        ] as $state) {
            try {
                $this->validator()->validate($state);
                $this->fail('Expected bounded collection validation failure.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_checklist_with_six_items_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('checklist', [
            'checklist_items' => ['Un', 'Deux', 'Trois', 'Quatre', 'Cinq', 'Six'],
        ]));
    }

    public function test_solutions_with_five_items_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('solutions', [
            'solutions' => array_fill(0, 5, ['title' => 'Route', 'text' => 'Solution transport.']),
        ]));
    }

    public function test_unknown_solution_row_key_is_rejected(): void
    {
        $state = $this->validState('solutions');
        $state['slots']['solutions'][0]['icon'] = 'truck';

        try {
            $this->validator()->validate($state);
            $this->fail('Expected unknown solution row key to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots.solutions.0', $e->errors());
        }
    }

    public function test_unknown_merge_tag_in_new_offer_field_is_rejected(): void
    {
        $state = $this->validState('offer');
        $state['slots']['offer']['highlight'] = 'Pour {{contact.phone}}';

        try {
            $this->validator()->validate($state);
            $this->fail('Expected merge tag in offer highlight to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots.offer.highlight', $e->errors());
        }
    }

    // ── Unknown variant ids rejected ─────────────────────────────────────────

    public function test_unknown_header_variant_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(overrides: ['header_variant' => 'nope']));
    }

    public function test_unknown_middle_variant_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(overrides: ['middle_variant' => 'nope']));
    }

    public function test_unknown_footer_variant_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(overrides: ['footer_variant' => 'nope']));
    }

    public function test_unknown_cta_intent_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(overrides: [
            'cta' => ['intent' => 'nope', 'label' => 'Label'],
        ]));
    }

    // ── Forbidden merge tag ───────────────────────────────────────────────────

    public function test_unsubscribe_url_tag_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState(slotOverrides: [
                'hero_title' => 'Cliquez {{unsubscribe_url}} ici',
            ]));
            $this->fail('Expected ValidationException for {{unsubscribe_url}}.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots.hero_title', $e->errors());
        }
    }

    public function test_unknown_merge_tag_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState(slotOverrides: [
                'bullets' => ['{{contact.phone}}', 'Bullet deux', 'Bullet trois'],
            ]));
            $this->fail('Expected ValidationException for unknown merge tag.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots.bullets.0', $e->errors());
        }
    }

    public function test_allowed_merge_tags_pass(): void
    {
        $result = $this->validator()->validate($this->validState(slotOverrides: [
            'hero_title' => 'Bonjour {{contact.first_name}}',
            'intro'      => ['Pour {{company.name}}.', 'Contact : {{contact.email}}, {{contact.name}}.'],
        ]));

        $this->assertStringContainsString('{{contact.first_name}}', $result['slots']['hero_title']);
    }

    /**
     * 'company.sector' is deliberately NOT in ALLOWED_MERGE_TAGS — see
     * SectionCatalog docblock — because ZohoCampaignsDriver::MERGE_TAG_MAP has
     * no verified mapping for it and would leak the literal tag to recipients.
     */
    public function test_company_sector_merge_tag_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState(slotOverrides: [
                'hero_title' => 'Bonjour depuis le secteur {{company.sector}}',
            ]));
            $this->fail('Expected ValidationException for {{company.sector}}.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots.hero_title', $e->errors());
        }
    }

    // ── Bounds ────────────────────────────────────────────────────────────────

    public function test_hero_title_over_120_chars_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(slotOverrides: [
            'hero_title' => str_repeat('a', 121),
        ]));
    }

    public function test_intro_with_zero_paragraphs_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(slotOverrides: ['intro' => []]));
    }

    public function test_intro_with_four_paragraphs_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(slotOverrides: [
            'intro' => ['Un.', 'Deux.', 'Trois.', 'Quatre.'],
        ]));
    }

    public function test_intro_item_over_500_chars_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(slotOverrides: [
            'intro' => [str_repeat('a', 501)],
        ]));
    }

    public function test_bullets_with_one_item_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(slotOverrides: ['bullets' => ['Seul.']]));
    }

    public function test_bullets_with_five_items_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(slotOverrides: [
            'bullets' => ['Un', 'Deux', 'Trois', 'Quatre', 'Cinq'],
        ]));
    }

    public function test_bullet_item_over_200_chars_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(slotOverrides: [
            'bullets' => [str_repeat('a', 201), 'Bullet deux'],
        ]));
    }

    public function test_kpis_with_two_items_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('kpi', slotOverrides: [
            'kpis' => [
                ['value' => '48 h', 'label' => 'Enlèvement'],
                ['value' => 'IATA', 'label' => 'Agent agréé'],
            ],
        ]));
    }

    public function test_kpi_label_over_40_chars_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('kpi', slotOverrides: [
            'kpis' => [
                ['value' => '48 h', 'label' => str_repeat('a', 41)],
                ['value' => 'IATA', 'label' => 'Agent agréé'],
                ['value' => '100 %', 'label' => 'Suivi documentaire'],
            ],
        ]));
    }

    public function test_benefits_with_four_items_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('benefits', slotOverrides: [
            'benefits' => [
                ['title' => 'Un', 'text' => 'Texte un'],
                ['title' => 'Deux', 'text' => 'Texte deux'],
                ['title' => 'Trois', 'text' => 'Texte trois'],
                ['title' => 'Quatre', 'text' => 'Texte quatre'],
            ],
        ]));
    }

    public function test_departures_with_one_row_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('departures', slotOverrides: [
            'departures' => [
                ['origin' => 'Goussainville (France)', 'frequency' => '4 départs par semaine'],
            ],
        ]));
    }

    public function test_departures_with_six_rows_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = ['origin' => "Origine {$i}", 'frequency' => 'Chaque semaine'];
        }

        $this->validator()->validate($this->validState('departures', slotOverrides: ['departures' => $rows]));
    }

    public function test_process_requires_exactly_three_steps(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('process', slotOverrides: [
            'process_steps' => ['Collecte', 'Acheminement'],
        ]));
    }

    public function test_process_highlight_over_200_chars_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState('process', slotOverrides: [
            'process_highlight' => str_repeat('a', 201),
        ]));
    }

    public function test_cta_label_over_60_chars_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator()->validate($this->validState(overrides: [
            'cta' => ['intent' => 'quote', 'label' => str_repeat('a', 61)],
        ]));
    }

    // ── Header → hero correspondence ─────────────────────────────────────────

    public function test_hero_variant_mismatched_with_header_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState(overrides: [
                'header_variant' => 'logo_center',
                'hero_variant'   => 'navy',
            ]));
            $this->fail('Expected ValidationException for hero/header mismatch.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('hero_variant', $e->errors());
        }
    }

    public function test_hero_variant_matching_header_passes(): void
    {
        $result = $this->validator()->validate($this->validState(overrides: [
            'header_variant' => 'logo_tagline',
            'hero_variant'   => 'navy',
        ]));

        $this->assertSame('navy', $result['hero_variant']);
    }

    // ── validateSuggestion() — never throws, returns null on drift ─────────────

    private function validSuggestion(string $middle = 'kpi'): array
    {
        $slots = [
            'hero_title' => 'Optimisez vos flux',
            'intro'      => ['Paragraphe un.', 'Paragraphe deux.'],
            'bullets'    => ['Bullet un', 'Bullet deux', 'Bullet trois'],
        ];

        if ($middle === 'kpi') {
            $slots['kpis'] = [
                ['value' => '48 h', 'label' => 'Enlèvement'],
                ['value' => 'IATA', 'label' => 'Agent agréé'],
                ['value' => '100 %', 'label' => 'Suivi documentaire'],
            ];
        } elseif ($middle === 'process') {
            $slots['process_steps'] = ['Collecte', 'Acheminement', 'Dégroupement MEAD'];
            $slots['process_highlight'] = 'Des solutions logistiques sur mesure.';
        }

        return [
            'subject'        => 'Sujet de test',
            'preview_text'   => 'Aperçu de test',
            'middle_variant' => $middle,
            'cta_intent'     => 'quote',
            'cta_label'      => 'Demander une cotation',
            'slots'          => $slots,
        ];
    }

    public function test_valid_suggestion_returns_normalized_array(): void
    {
        $result = $this->validator()->validateSuggestion($this->validSuggestion());

        $this->assertNotNull($result);
        $this->assertSame('kpi', $result['middle_variant']);
        $this->assertSame('quote', $result['cta_intent']);
    }

    public function test_valid_process_suggestion_returns_normalized_array(): void
    {
        $suggestion = $this->validSuggestion('process');
        $suggestion['cta_intent'] = 'services';
        $suggestion['cta_label'] = 'Découvrir nos services';

        $result = $this->validator()->validateSuggestion($suggestion);

        $this->assertNotNull($result);
        $this->assertSame('process', $result['middle_variant']);
        $this->assertSame(['Collecte', 'Acheminement', 'Dégroupement MEAD'], $result['slots']['process_steps']);
        $this->assertSame('services', $result['cta_intent']);
    }

    public function test_suggestion_with_unknown_middle_variant_returns_null(): void
    {
        $suggestion = $this->validSuggestion();
        $suggestion['middle_variant'] = 'not_a_variant';

        $this->assertNull($this->validator()->validateSuggestion($suggestion));
    }

    public function test_suggestion_with_unsubscribe_tag_returns_null(): void
    {
        $suggestion = $this->validSuggestion();
        $suggestion['slots']['hero_title'] = 'Cliquez {{unsubscribe_url}}';

        $this->assertNull($this->validator()->validateSuggestion($suggestion));
    }

    public function test_suggestion_missing_slots_returns_null(): void
    {
        $suggestion = $this->validSuggestion();
        unset($suggestion['slots']);

        $this->assertNull($this->validator()->validateSuggestion($suggestion));
    }

    public function test_suggestion_with_cta_label_over_bound_returns_null(): void
    {
        $suggestion = $this->validSuggestion();
        $suggestion['cta_label'] = str_repeat('a', 61);

        $this->assertNull($this->validator()->validateSuggestion($suggestion));
    }

    // ── Unknown keys rejected at every object level ─────────────────────────

    public function test_unknown_top_level_key_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState(overrides: ['not_a_real_field' => 'x']));
            $this->fail('Expected ValidationException for unknown top-level key.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('builder_state', $e->errors());
        }
    }

    public function test_unknown_cta_key_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState(overrides: [
                'cta' => ['intent' => 'quote', 'label' => 'Label', 'extra' => 'x'],
            ]));
            $this->fail('Expected ValidationException for unknown cta key.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cta', $e->errors());
        }
    }

    public function test_unknown_slots_key_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState(slotOverrides: ['not_a_real_slot' => 'x']));
            $this->fail('Expected ValidationException for unknown slots key.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots', $e->errors());
        }
    }

    public function test_unknown_departure_row_key_is_rejected(): void
    {
        try {
            $this->validator()->validate($this->validState('departures', slotOverrides: [
                'departures' => [
                    ['origin' => 'Goussainville (France)', 'frequency' => '4 départs par semaine', 'extra' => 'x'],
                    ['origin' => 'Barcelone (Espagne)', 'frequency' => '2 à 3 départs par semaine'],
                ],
            ]));
            $this->fail('Expected ValidationException for unknown departure row key.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slots.departures.0', $e->errors());
        }
    }

    /**
     * A payload padded with a large number of never-validated sibling keys
     * — the DoS class item 5 guards against — is rejected outright rather
     * than silently walked by the merge-tag scan.
     */
    public function test_payload_with_many_unknown_sibling_keys_is_rejected(): void
    {
        $state = $this->validState();
        for ($i = 0; $i < 500; $i++) {
            $state["junk_{$i}"] = str_repeat('x', 50);
        }

        try {
            $this->validator()->validate($state);
            $this->fail('Expected ValidationException for padded unknown-key payload.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('builder_state', $e->errors());
        }
    }

    // ── Oversized payload rejected ───────────────────────────────────────────

    public function test_oversized_encoded_state_is_rejected(): void
    {
        $state = $this->validState();
        $state['slots']['hero_title'] = str_repeat('a', BuilderStateValidator::MAX_ENCODED_BYTES + 1000);

        try {
            $this->validator()->validate($state);
            $this->fail('Expected ValidationException for oversized builder_state.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('builder_state', $e->errors());
        }
    }

    public function test_oversized_encoded_suggestion_returns_null(): void
    {
        $suggestion = $this->validSuggestion();
        $suggestion['slots']['hero_title'] = str_repeat('a', BuilderStateValidator::MAX_ENCODED_BYTES + 1000);

        $this->assertNull($this->validator()->validateSuggestion($suggestion));
    }

    // ── Markup rejection — validateSuggestion() ONLY, never validate() ──────

    /**
     * validate() (the manual/builder-form path) applies NO markup-rejection
     * rule — a human typing a literal "<" (e.g. pasting a code snippet) must
     * get whatever the existing bound-only rules already give them, not a
     * new opaque rejection designed for AI output.
     */
    public function test_validate_does_not_reject_markup_in_hero_title(): void
    {
        $result = $this->validator()->validate($this->validState(slotOverrides: [
            'hero_title' => 'Titre avec <b>markup</b> littéral',
        ]));

        $this->assertSame('Titre avec <b>markup</b> littéral', $result['slots']['hero_title']);
    }

    public function test_suggestion_with_html_markup_in_hero_title_returns_null(): void
    {
        $suggestion = $this->validSuggestion();
        $suggestion['slots']['hero_title'] = 'Titre avec <b>markup</b>';

        $this->assertNull($this->validator()->validateSuggestion($suggestion));
    }

    public function test_suggestion_with_html_comment_in_intro_returns_null(): void
    {
        $suggestion = $this->validSuggestion();
        $suggestion['slots']['intro'][0] = 'Paragraphe <!-- commentaire --> suspect.';

        $this->assertNull($this->validator()->validateSuggestion($suggestion));
    }

    /**
     * "<" used as a plain comparison symbol (never followed by a letter,
     * "/", or "!") must NOT trip the markup guard — French B2B copy
     * legitimately uses it ("délai <48h").
     */
    public function test_suggestion_with_less_than_symbol_as_comparison_is_not_rejected(): void
    {
        $suggestion = $this->validSuggestion();
        $suggestion['slots']['hero_title'] = 'Enlèvement en <48h garanti';

        $this->assertNotNull($this->validator()->validateSuggestion($suggestion));
    }
}
