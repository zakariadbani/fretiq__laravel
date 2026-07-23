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
