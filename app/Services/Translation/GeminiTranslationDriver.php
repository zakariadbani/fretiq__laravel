<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiTranslationDriver — translates campaign template copy via Google Gemini.
 *
 * Transport mirrors GeminiScoringDriver exactly:
 *   - API key via x-goog-api-key header (never in the URL)
 *   - Model from config('services.gemini.model', 'gemini-2.5-flash')
 *   - generationConfig: response_mime_type = 'application/json', maxOutputTokens = 8192
 *   - Http::timeout(60)
 *   - Reads candidates.0.content.parts.0.text
 *   - Strips ```json fences before json_decode
 *   - Returns null + Log::warning on every failure; NEVER returns a partial result.
 *
 * Body translation strategy: HtmlTextSegmenter extracts an ordered array of
 * visible text runs; the LLM sees only those plain-text fragments plus subject
 * and preview_text. After response validation the translated runs are positionally
 * re-inserted back into the original token stream and reassembled — markup is
 * never touched by the LLM.
 *
 * Guards (any failure → null + Log::warning, previous EN row untouched):
 *   - Blank GEMINI_API_KEY
 *   - HTTP error / exception
 *   - candidates.0.finishReason == 'MAX_TOKENS'
 *   - JSON decode failure
 *   - Missing 'subject' string or 'runs' array
 *   - runs array length !== input runs length
 *   - {{...}} multiset not preserved per run (or in subject / non-empty preview)
 *   - Final tag-multiset differs between translated and original HTML
 */
class GeminiTranslationDriver
{
    public function __construct(
        private readonly HtmlTextSegmenter $segmenter,
    ) {}

    /**
     * Translate a campaign template from $sourceLang to $targetLang.
     *
     * @param  string      $subject
     * @param  string|null $preview   null or '' when no preview
     * @param  string      $html      Full HTML body
     * @param  string      $sourceLang e.g. 'fr'
     * @param  string      $targetLang e.g. 'en'
     *
     * @return array{subject: string, preview_text: string|null, html_content: string}|null
     *         null on any failure (caller keeps previous translation intact).
     */
    public function translate(
        string  $subject,
        ?string $preview,
        string  $html,
        string  $sourceLang,
        string  $targetLang,
    ): ?array {
        // ── 1. API key guard ───────────────────────────────────────────────────
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            Log::warning('[GeminiTranslationDriver] Clé API Gemini non configurée — traduction ignorée.', [
                'target_lang' => $targetLang,
            ]);
            return null;
        }

        // ── 2. Segment the HTML body ───────────────────────────────────────────
        $tokens     = $this->segmenter->segment($html);
        $runObjects = $this->segmenter->translatableRuns($tokens);

        // Extract just the text values for the prompt (ordered array)
        $inputRuns = array_column($runObjects, 'value');

        // ── 3. Build prompt ───────────────────────────────────────────────────
        $model  = config('services.gemini.model', 'gemini-2.5-flash');
        $prompt = $this->buildPrompt($subject, $preview, $inputRuns, $sourceLang, $targetLang);

        // ── 4. Call Gemini ────────────────────────────────────────────────────
        try {
            $response = Http::timeout(60)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                    [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'response_mime_type' => 'application/json',
                            'maxOutputTokens'    => 8192,
                        ],
                    ]
                );

            if ($response->failed()) {
                Log::warning('[GeminiTranslationDriver] Réponse HTTP échouée depuis Gemini.', [
                    'status'      => $response->status(),
                    'target_lang' => $targetLang,
                ]);
                return null;
            }

            // ── 5. Check finishReason ─────────────────────────────────────────
            $finishReason = $response->json('candidates.0.finishReason');
            if ($finishReason === 'MAX_TOKENS') {
                Log::warning('[GeminiTranslationDriver] Gemini a atteint MAX_TOKENS — traduction rejetée.', [
                    'target_lang' => $targetLang,
                ]);
                return null;
            }

            // ── 6. Extract text ───────────────────────────────────────────────
            $text = $response->json('candidates.0.content.parts.0.text');

            if (! is_string($text) || $text === '') {
                Log::warning('[GeminiTranslationDriver] Réponse Gemini vide ou structure inattendue.', [
                    'target_lang' => $targetLang,
                ]);
                return null;
            }

            // ── 7. Parse + validate ───────────────────────────────────────────
            return $this->parseAndAssemble(
                $text,
                $subject,
                $preview,
                $html,
                $tokens,
                $runObjects,
                $inputRuns,
                $targetLang,
            );

        } catch (\Throwable $e) {
            Log::warning('[GeminiTranslationDriver] Exception lors de l\'appel Gemini — traduction ignorée.', [
                'target_lang' => $targetLang,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildPrompt(
        string  $subject,
        ?string $preview,
        array   $runs,
        string  $sourceLang,
        string  $targetLang,
    ): string {
        $runsJson    = json_encode($runs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $previewJson = json_encode($preview ?? '', JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are translating B2B freight forwarding marketing email copy from {$sourceLang} to {$targetLang} for TCL France.

Translate the following fields:
- subject: "{$subject}"
- preview_text: {$previewJson}
- runs: {$runsJson}

The "runs" field is an ordered JSON array of visible text fragments extracted from an HTML email body.

Rules you MUST follow:
1. Return ONLY strict JSON — no markdown, no code fences, no extra keys:
   {"subject": "...", "preview_text": "...", "runs": ["...", ...]}
2. The "runs" array MUST have EXACTLY the same number of elements in the same order as the input.
3. Keep every {{...}} merge tag (e.g. {{contact.name}}, {{unsubscribe_url}}) verbatim — do NOT translate or reorder them.
4. Whitespace-only fragments MUST be echoed unchanged.
5. Do NOT add, remove, merge, split, or reorder array elements.
6. Translate subject and preview_text as plain text strings.
7. If preview_text input is null or empty string, return null or empty string respectively.
PROMPT;
    }

    /**
     * Parse the raw Gemini text, validate all guards, reassemble translated HTML.
     *
     * @param  array<int, array{type: string, value: string}> $tokens
     * @param  array<int, array{index: int, value: string}>   $runObjects
     * @param  array<int, string>                             $inputRuns
     */
    private function parseAndAssemble(
        string  $text,
        string  $originalSubject,
        ?string $originalPreview,
        string  $originalHtml,
        array   $tokens,
        array   $runObjects,
        array   $inputRuns,
        string  $targetLang,
    ): ?array {
        // Strip markdown fences (```json … ```)
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (! is_array($data)) {
            Log::warning('[GeminiTranslationDriver] JSON non décodable dans la réponse Gemini.', [
                'target_lang' => $targetLang,
                'raw'         => mb_substr($text, 0, 500),
            ]);
            return null;
        }

        // ── Guard: subject must be a non-empty string ─────────────────────────
        if (! isset($data['subject']) || ! is_string($data['subject']) || trim($data['subject']) === '') {
            Log::warning('[GeminiTranslationDriver] Champ "subject" manquant ou invalide.', [
                'target_lang' => $targetLang,
            ]);
            return null;
        }

        // ── Guard: runs must be an array of the exact same length ─────────────
        if (! isset($data['runs']) || ! is_array($data['runs'])) {
            Log::warning('[GeminiTranslationDriver] Champ "runs" absent ou non-tableau.', [
                'target_lang' => $targetLang,
            ]);
            return null;
        }

        if (count($data['runs']) !== count($inputRuns)) {
            Log::warning('[GeminiTranslationDriver] Longueur des runs incorrecte — attendu ' . count($inputRuns) . ', reçu ' . count($data['runs']), [
                'target_lang'    => $targetLang,
                'expected_count' => count($inputRuns),
                'received_count' => count($data['runs']),
            ]);
            return null;
        }

        $translatedRuns = array_values($data['runs']);

        // Ensure all runs are strings
        foreach ($translatedRuns as $i => $run) {
            if (! is_string($run)) {
                Log::warning("[GeminiTranslationDriver] Run #{$i} n'est pas une chaîne.", [
                    'target_lang' => $targetLang,
                ]);
                return null;
            }
        }

        // ── Guard: {{...}} multiset preserved per run ─────────────────────────
        foreach ($inputRuns as $i => $inputRun) {
            if (! $this->mergeTagsOrderedEqual($inputRun, $translatedRuns[$i])) {
                Log::warning("[GeminiTranslationDriver] Merge tags non préservés dans le run #{$i}.", [
                    'target_lang' => $targetLang,
                    'input'       => mb_substr($inputRun, 0, 200),
                    'translated'  => mb_substr($translatedRuns[$i], 0, 200),
                ]);
                return null;
            }
        }

        // ── Guard: {{...}} multiset preserved in subject ──────────────────────
        if (! $this->mergeTagsOrderedEqual($originalSubject, $data['subject'])) {
            Log::warning('[GeminiTranslationDriver] Merge tags non préservés dans le subject.', [
                'target_lang' => $targetLang,
            ]);
            return null;
        }

        // ── Guard: {{...}} multiset preserved in preview (if non-empty) ───────
        $translatedPreview = $data['preview_text'] ?? null;
        if (is_string($translatedPreview)) {
            $translatedPreview = $translatedPreview === '' ? null : $translatedPreview;
        } else {
            $translatedPreview = null;
        }

        if ($originalPreview !== null && $originalPreview !== '') {
            $checkPreview = $translatedPreview ?? '';
            if (! $this->mergeTagsOrderedEqual($originalPreview, $checkPreview)) {
                Log::warning('[GeminiTranslationDriver] Merge tags non préservés dans preview_text.', [
                    'target_lang' => $targetLang,
                ]);
                return null;
            }
        }

        // ── Re-insert translated runs into token stream ───────────────────────
        $mutatedTokens = $tokens; // shallow copy (scalar values)
        foreach ($runObjects as $j => $runObj) {
            $mutatedTokens[$runObj['index']]['value'] = $translatedRuns[$j];
        }

        $translatedHtml = $this->segmenter->reassemble($mutatedTokens);

        // ── Final guard: HTML tag multiset must be identical ──────────────────
        if (! $this->tagMultisetEqual($originalHtml, $translatedHtml)) {
            Log::warning('[GeminiTranslationDriver] Multiset des balises HTML modifié après traduction — rejeté.', [
                'target_lang' => $targetLang,
            ]);
            return null;
        }

        return [
            'subject'      => trim($data['subject']),
            'preview_text' => $translatedPreview !== null ? trim($translatedPreview) : $translatedPreview,
            'html_content' => $translatedHtml,
        ];
    }

    /**
     * Check that the ORDERED occurrence sequence of {{...}} merge tags is identical in two strings.
     *
     * Using sorted multiset comparison allowed reordered tags to pass; this version
     * compares the tags in their literal left-to-right order, so any reordering is caught.
     */
    private function mergeTagsOrderedEqual(string $a, string $b): bool
    {
        preg_match_all('/\{\{[^}]+\}\}/u', $a, $am);
        preg_match_all('/\{\{[^}]+\}\}/u', $b, $bm);

        return $am[0] === $bm[0];
    }

    /**
     * Check that the multiset of HTML tags is identical in two HTML strings.
     */
    private function tagMultisetEqual(string $a, string $b): bool
    {
        preg_match_all('/<[^>]+>/s', $a, $aMatches);
        preg_match_all('/<[^>]+>/s', $b, $bMatches);

        $aTags = $aMatches[0];
        $bTags = $bMatches[0];
        sort($aTags);
        sort($bTags);

        return $aTags === $bTags;
    }
}
