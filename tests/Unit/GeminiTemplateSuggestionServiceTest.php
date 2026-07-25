<?php

namespace Tests\Unit;

use App\Services\Campaign\TemplateBuilder\GeminiTemplateSuggestionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GeminiTemplateSuggestionServiceTest — every HTTP call is Http::fake()'d,
 * never hits the network. Mirrors the guard-discipline test pattern used by
 * tests/Feature/Backend/GeminiTranslationDriverTest.php.
 */
class GeminiTemplateSuggestionServiceTest extends TestCase
{
    private function service(): GeminiTemplateSuggestionService
    {
        return new GeminiTemplateSuggestionService();
    }

    private function setApiKey(?string $key = 'fake-api-key'): void
    {
        config([
            'services.gemini.api_key' => $key,
            'services.gemini.model'   => 'gemini-2.5-flash',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validSuggestionPayload(): array
    {
        return [
            'subject'        => 'Optimisez vos flux Europe-Maroc',
            'preview_text'   => 'Un aperçu percutant.',
            'middle_variant' => 'kpi',
            'cta_intent'     => 'quote',
            'cta_label'      => 'Demander une cotation',
            'slots'          => [
                'hero_title' => 'Optimisez vos flux',
                'intro'      => ['Un premier paragraphe.', 'Un second paragraphe pour {{company.name}}.'],
                'bullets'    => ['Bullet un', 'Bullet deux', 'Bullet trois'],
                'kpis' => [
                    ['value' => '48 h', 'label' => 'Enlèvement'],
                    ['value' => 'IATA', 'label' => 'Agent agréé'],
                    ['value' => '100 %', 'label' => 'Suivi documentaire'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForMiddle(string $middle): array
    {
        $payload = $this->validSuggestionPayload();
        $payload['middle_variant'] = $middle;

        foreach ([
            'departures', 'kpis', 'benefits', 'process_steps', 'process_highlight',
            'case_study', 'checklist_title', 'checklist_items', 'solutions', 'offer',
        ] as $key) {
            unset($payload['slots'][$key]);
        }

        if ($middle === 'departures') {
            $payload['slots']['departures'] = [
                ['origin' => 'Casablanca', 'frequency' => 'Chaque semaine'],
                ['origin' => 'Paris', 'frequency' => 'Deux fois par semaine'],
            ];
        } elseif ($middle === 'kpi') {
            $payload['slots']['kpis'] = [
                ['value' => '48 h', 'label' => 'Enlèvement'],
                ['value' => 'IATA', 'label' => 'Agent agréé'],
                ['value' => '100 %', 'label' => 'Suivi documentaire'],
            ];
        } elseif ($middle === 'benefits') {
            $payload['slots']['benefits'] = [
                ['title' => 'Réactivité', 'text' => 'Un interlocuteur mobilisé.'],
                ['title' => 'Visibilité', 'text' => 'Des informations à chaque étape.'],
                ['title' => 'Souplesse', 'text' => 'Une réponse adaptée.'],
            ];
        } elseif ($middle === 'process') {
            $payload['slots']['process_steps'] = ['Collecte', 'Acheminement', 'Dégroupement'];
            $payload['slots']['process_highlight'] = 'Une chaîne accompagnée de bout en bout.';
        } elseif ($middle === 'case_study') {
            $payload['slots']['case_study'] = [
                'title' => 'Flux urgent',
                'challenge' => 'Réduire les ruptures.',
                'solution' => 'Pilotage TCL dédié.',
                'result' => 'Délais stabilisés.',
            ];
        } elseif ($middle === 'checklist') {
            $payload['slots']['checklist_title'] = 'Préparer votre départ';
            $payload['slots']['checklist_items'] = ['Documents validés', 'Marchandise prête', 'Contact confirmé'];
        } elseif ($middle === 'solutions') {
            $payload['slots']['solutions'] = [
                ['title' => 'Route', 'text' => 'Départs réguliers.'],
                ['title' => 'Aérien', 'text' => 'Gestion des urgences.'],
            ];
        } elseif ($middle === 'offer') {
            $payload['slots']['offer'] = [
                'title' => 'Offre dédiée',
                'description' => 'Une étude adaptée à vos flux.',
                'highlight' => 'Étude personnalisée',
            ];
        }

        return $payload;
    }

    private function geminiResponseBody(string $jsonText, string $finishReason = 'STOP'): array
    {
        return [
            'candidates' => [
                [
                    'finishReason' => $finishReason,
                    'content'      => [
                        'parts' => [
                            ['text' => $jsonText],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function fakeGemini(array $body, int $status = 200): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($body, $status),
        ]);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_valid_response_returns_normalized_suggestion(): void
    {
        $this->setApiKey();
        $this->fakeGemini($this->geminiResponseBody(json_encode($this->validSuggestionPayload())));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNotNull($result);
        $this->assertSame('kpi', $result['middle_variant']);
        $this->assertSame('quote', $result['cta_intent']);
        $this->assertSame('Demander une cotation', $result['cta_label']);
    }

    public function test_valid_response_supports_every_middle_variant_and_keeps_only_its_slots(): void
    {
        $variantKeys = [
            'departures' => ['departures'],
            'kpi' => ['kpis'],
            'benefits' => ['benefits'],
            'process' => ['process_steps', 'process_highlight'],
            'case_study' => ['case_study'],
            'checklist' => ['checklist_title', 'checklist_items'],
            'solutions' => ['solutions'],
            'offer' => ['offer'],
        ];

        $this->setApiKey();
        $sequence = Http::sequence();
        foreach (array_keys($variantKeys) as $middle) {
            $sequence->push($this->geminiResponseBody(json_encode($this->payloadForMiddle($middle))));
        }
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => $sequence,
        ]);

        foreach ($variantKeys as $middle => $expectedSpecificKeys) {
            $result = $this->service()->suggest('Un brief de test suffisamment long pour générer chaque bloc disponible.');

            $this->assertNotNull($result, "middle={$middle}");
            $this->assertSame($middle, $result['middle_variant'], "middle={$middle}");
            $this->assertSame(
                array_merge(['hero_title', 'intro', 'bullets'], $expectedSpecificKeys),
                array_keys($result['slots']),
                "middle={$middle}"
            );
        }
    }

    public function test_cross_variant_slots_are_dropped_from_normalized_suggestion(): void
    {
        $this->setApiKey();
        $payload = $this->payloadForMiddle('case_study');
        $payload['slots']['offer'] = [
            'title' => 'Inactive',
            'description' => 'Must not survive.',
            'highlight' => 'Inactive',
        ];
        $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour produire une étude de cas.');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('case_study', $result['slots']);
        $this->assertArrayNotHasKey('offer', $result['slots']);
    }

    public function test_prompt_describes_each_new_middle_specific_shape_without_array_placeholder(): void
    {
        $this->setApiKey();
        $this->fakeGemini($this->geminiResponseBody(json_encode($this->payloadForMiddle('offer'))));

        $this->service()->suggest('Un brief suffisamment long pour inspecter le contrat JSON envoyé au modèle.');

        Http::assertSent(function ($request) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            $this->assertStringNotContainsString('"<middle-specific key>": [...]', $prompt);
            $this->assertStringContainsString('case_study: "case_study": {"title": "...", "challenge": "...", "solution": "...", "result": "..."}', $prompt);
            $this->assertStringContainsString('checklist: "checklist_title": "...", "checklist_items": ["...", "...", "..."]', $prompt);
            $this->assertStringContainsString('process: "process_steps": ["...", "...", "..."], "process_highlight": "..."', $prompt);
            $this->assertStringContainsString('offer: "offer": {"title": "...", "description": "...", "highlight": "..."}', $prompt);

            return true;
        });
    }

    public function test_response_wrapped_in_markdown_fences_still_parses(): void
    {
        $this->setApiKey();
        $fenced = "```json\n" . json_encode($this->validSuggestionPayload()) . "\n```";
        $this->fakeGemini($this->geminiResponseBody($fenced));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNotNull($result);
    }

    public function test_process_response_returns_normalized_suggestion(): void
    {
        $this->setApiKey();
        $payload = $this->validSuggestionPayload();
        $payload['middle_variant'] = 'process';
        $payload['cta_intent'] = 'services';
        $payload['cta_label'] = 'Découvrir nos services';
        unset($payload['slots']['kpis']);
        $payload['slots']['process_steps'] = ['Collecte', 'Acheminement', 'Dégroupement MEAD'];
        $payload['slots']['process_highlight'] = 'Des solutions logistiques sur mesure.';
        $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

        $result = $this->service()->suggest('Présenter la chaîne logistique TCL avec un lien vers les services.');

        $this->assertNotNull($result);
        $this->assertSame('process', $result['middle_variant']);
        $this->assertSame('services', $result['cta_intent']);
    }

    public function test_offer_response_returns_only_active_middle_slots(): void
    {
        $this->setApiKey();
        $payload = $this->validSuggestionPayload();
        $payload['middle_variant'] = 'offer';
        unset($payload['slots']['kpis']);
        $payload['slots']['offer'] = [
            'title' => '  Offre dédiée  ',
            'description' => 'Une étude adaptée à vos flux.',
            'highlight' => 'Étude personnalisée',
        ];
        $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

        $result = $this->service()->suggest('Présenter une offre transport personnalisée sans bouton supplémentaire.');

        $this->assertSame('Offre dédiée', $result['slots']['offer']['title']);
        $this->assertArrayNotHasKey('kpis', $result['slots']);
    }

    public function test_markup_and_merge_tag_in_new_nested_slots_return_null(): void
    {
        foreach (['<b>Offre</b>', '{{unsubscribe_url}}'] as $unsafe) {
            $this->setApiKey();
            $payload = $this->validSuggestionPayload();
            $payload['middle_variant'] = 'offer';
            unset($payload['slots']['kpis']);
            $payload['slots']['offer'] = ['title' => $unsafe, 'description' => 'Description', 'highlight' => 'Important'];
            $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

            $this->assertNull($this->service()->suggest('Présenter une offre transport personnalisée sans bouton supplémentaire.'));
        }
    }

    // ── Guards — every failure returns null ─────────────────────────────────────

    public function test_empty_api_key_returns_null_without_calling_http(): void
    {
        $this->setApiKey(null);
        Http::fake(); // no stub registered — any real call would throw/record unexpectedly

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_bad_enum_value_returns_null(): void
    {
        $this->setApiKey();
        $payload = $this->validSuggestionPayload();
        $payload['middle_variant'] = 'not_a_real_variant';
        $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    public function test_missing_required_key_returns_null(): void
    {
        $this->setApiKey();
        $payload = $this->validSuggestionPayload();
        unset($payload['cta_label']);
        $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    public function test_max_tokens_finish_reason_returns_null(): void
    {
        $this->setApiKey();
        $this->fakeGemini($this->geminiResponseBody(json_encode($this->validSuggestionPayload()), 'MAX_TOKENS'));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    /**
     * Any finishReason other than a clean 'STOP' is rejected — not just
     * MAX_TOKENS. SAFETY/RECITATION/OTHER previously fell through unchecked.
     */
    public function test_safety_finish_reason_returns_null(): void
    {
        $this->setApiKey();
        $this->fakeGemini($this->geminiResponseBody(json_encode($this->validSuggestionPayload()), 'SAFETY'));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    public function test_missing_finish_reason_returns_null(): void
    {
        $this->setApiKey();
        $body = $this->geminiResponseBody(json_encode($this->validSuggestionPayload()));
        unset($body['candidates'][0]['finishReason']);
        $this->fakeGemini($body);

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    /**
     * "The AI never emits HTML" is prompt-only otherwise — a response
     * containing literal markup must still be rejected server-side, in case
     * the model drifts from the instruction.
     */
    public function test_html_markup_in_response_returns_null(): void
    {
        $this->setApiKey();
        $payload = $this->validSuggestionPayload();
        $payload['slots']['hero_title'] = 'Titre avec <b>markup</b> littéral';
        $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    public function test_http_failure_returns_null(): void
    {
        $this->setApiKey();
        $this->fakeGemini(['error' => 'server error'], 500);

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    public function test_undecodable_json_returns_null(): void
    {
        $this->setApiKey();
        $this->fakeGemini($this->geminiResponseBody('not valid json at all {'));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }

    public function test_unsubscribe_tag_in_response_returns_null(): void
    {
        $this->setApiKey();
        $payload = $this->validSuggestionPayload();
        $payload['slots']['hero_title'] = 'Cliquez {{unsubscribe_url}} ici';
        $this->fakeGemini($this->geminiResponseBody(json_encode($payload)));

        $result = $this->service()->suggest('Un brief de test suffisamment long pour passer la validation min:20.');

        $this->assertNull($result);
    }
}
