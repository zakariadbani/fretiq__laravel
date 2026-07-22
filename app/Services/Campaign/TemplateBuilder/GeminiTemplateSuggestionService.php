<?php

namespace App\Services\Campaign\TemplateBuilder;

use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Log;

/**
 * GeminiTemplateSuggestionService — AI-suggests builder copy from a free-text brief.
 *
 * Same guard discipline as GeminiTranslationDriver: strict JSON schema, enum
 * whitelists (middle_variant / cta_intent), merge-tag whitelist, null +
 * Log::warning on ANY drift, graceful no-API-key degrade (builder stays fully
 * usable manually — see plan "Key design decisions" #3). The AI NEVER emits
 * HTML — only structured copy that BuilderStateValidator::validateSuggestion()
 * checks against exactly the same bounds as manually-entered state.
 */
class GeminiTemplateSuggestionService
{
    public function __construct(
        private readonly GeminiClient $gemini = new GeminiClient(),
        private readonly BuilderStateValidator $validator = new BuilderStateValidator(),
    ) {}

    /**
     * @return array{subject: string, preview_text: string, middle_variant: string, slots: array, cta_intent: string, cta_label: string}|null
     *         null on any failure — caller falls back to manual entry.
     */
    public function suggest(string $brief): ?array
    {
        if (! $this->gemini->hasApiKey()) {
            Log::warning('[GeminiTemplateSuggestionService] Clé API Gemini non configurée — suggestion ignorée.');

            return null;
        }

        try {
            $response = $this->gemini->request($this->buildPrompt($brief), 60, [
                'response_mime_type' => 'application/json',
                'maxOutputTokens'    => 4096,
            ]);

            if ($response->failed()) {
                Log::warning('[GeminiTemplateSuggestionService] Réponse HTTP échouée depuis Gemini.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            // Reject any finishReason other than a clean 'STOP' — MAX_TOKENS
            // (truncated output), SAFETY, RECITATION, OTHER, and a missing/null
            // reason were previously let through unchecked; only MAX_TOKENS was
            // explicitly rejected, so a SAFETY-filtered or otherwise incomplete
            // candidate could still reach validateSuggestion().
            $finishReason = $response->json('candidates.0.finishReason');
            if ($finishReason !== 'STOP') {
                Log::warning('[GeminiTemplateSuggestionService] finishReason non-STOP — suggestion rejetée.', [
                    'finish_reason' => $finishReason,
                ]);

                return null;
            }

            $text = $this->gemini->extractText($response);
            if ($text === null) {
                Log::warning('[GeminiTemplateSuggestionService] Réponse Gemini vide ou structure inattendue.');

                return null;
            }

            $data = $this->gemini->decodeJson($text);
            if ($data === null) {
                Log::warning('[GeminiTemplateSuggestionService] JSON non décodable dans la réponse Gemini.', [
                    'raw' => mb_substr($this->gemini->stripFences($text), 0, 500),
                ]);

                return null;
            }

            $validated = $this->validator->validateSuggestion($data);
            if ($validated === null) {
                Log::warning('[GeminiTemplateSuggestionService] Suggestion Gemini invalide ou hors bornes — rejetée.', [
                    'raw' => mb_substr(json_encode($data), 0, 500),
                ]);

                return null;
            }

            return $validated;
        } catch (\Throwable $e) {
            Log::warning('[GeminiTemplateSuggestionService] Exception lors de l\'appel Gemini — suggestion ignorée.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildPrompt(string $brief): string
    {
        $catalog = SectionCatalog::describeForPrompt();
        $brief   = trim($brief);

        // Single source of truth — SectionCatalog::ALLOWED_MERGE_TAGS — so this
        // rule can never drift from the list BuilderStateValidator actually
        // enforces (describeForPrompt() above already injects the same list;
        // this keeps rule 5's explicit examples in lockstep with it too).
        $allowedTags = implode(', ', array_map(
            fn (string $t) => '{{' . $t . '}}',
            SectionCatalog::ALLOWED_MERGE_TAGS
        ));

        return <<<PROMPT
You are drafting the copy for a B2B freight-forwarding prospection email for TCL France (TCL Transport), targeting freight/logistics decision-makers. Voice: professional, concise, confident, French B2B — never casual, never salesy hype.

Content brief from the user:
"""
{$brief}
"""

{$catalog}

Rules you MUST follow:
1. Return ONLY strict JSON — no markdown, no code fences, no extra keys:
   {"subject": "...", "preview_text": "...", "middle_variant": "...", "cta_intent": "...", "cta_label": "...", "slots": {"hero_title": "...", "intro": ["...", "..."], "bullets": ["...", "...", "..."], "<middle-specific key>": [...]}}
2. Only include the ONE middle-specific slots key that matches your chosen middle_variant (departures, kpis, or benefits) — never include more than one.
3. Never write HTML, markdown, or any markup — plain text only in every field.
4. Never mention unsubscribing, opting out, or any link/URL text — that is handled outside this content entirely.
5. You may use ONLY these merge tags, verbatim, and only where natural (never invent others, never use {{unsubscribe_url}}): {$allowedTags}. Do NOT include a greeting ("Bonjour ...") — that line is added automatically.
6. Respect every bound given above exactly (array lengths, character limits).
7. subject: a concise email subject line, plain text, may include a merge tag.
8. preview_text: a short preheader sentence (under 150 characters), plain text.
9. cta_label: a short call-to-action button label (e.g. "Demander une cotation"), matching the tone of cta_intent.
PROMPT;
    }
}
