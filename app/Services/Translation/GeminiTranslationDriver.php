<?php

namespace App\Services\Translation;

use App\Services\Gemini\GeminiClient;
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
 * visible text runs; Gemini receives those plain-text fragments in small chunks
 * plus subject and preview_text. After response validation the translated runs
 * are positionally re-inserted back into the original token stream and
 * reassembled — markup is never touched by Gemini.
 *
 * Guards (any failure → null + Log::warning, previous EN row untouched):
 *   - Blank GEMINI_API_KEY
 *   - HTTP error / exception
 *   - candidates.0.finishReason == 'MAX_TOKENS'
 *   - JSON decode failure
 *   - Missing 'subject' string or 'runs' array
 *   - runs array length !== input chunk length
 *   - {{...}} merge tag order not preserved per run (or in subject / non-empty preview)
 *   - Final tag-multiset differs between translated and original HTML
 */
class GeminiTranslationDriver
{
    /**
     * Keep each Gemini request small enough that it does not merge/split runs on
     * large imported Zoho HTML. The whole translation still fails if any chunk is
     * structurally invalid, preserving the existing fail-safe behavior.
     */
    private const RUN_CHUNK_SIZE = 20;

    public function __construct(
        private readonly HtmlTextSegmenter $segmenter,
        private readonly GeminiClient $gemini = new GeminiClient(),
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
        if (! $this->gemini->hasApiKey()) {
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

        // ── 3. Translate visible text in bounded chunks ────────────────────────
        $chunks = $inputRuns === [] ? [[]] : array_chunk($inputRuns, self::RUN_CHUNK_SIZE);

        $translatedSubject = null;
        $translatedPreview = null;
        $translatedRuns    = [];

        foreach ($chunks as $chunkIndex => $chunkRuns) {
            $chunkResult = $this->translateChunk(
                subject: $subject,
                preview: $preview,
                runs: $chunkRuns,
                sourceLang: $sourceLang,
                targetLang: $targetLang,
                chunkIndex: $chunkIndex,
            );

            if ($chunkResult === null) {
                return null;
            }

            $translatedSubject ??= $chunkResult['subject'];
            $translatedPreview ??= $chunkResult['preview_text'];
            array_push($translatedRuns, ...$chunkResult['runs']);
        }

        // ── 4. Re-insert translated runs into token stream ─────────────────────
        if (count($translatedRuns) !== count($inputRuns)) {
            Log::warning('[GeminiTranslationDriver] Longueur totale des runs incorrecte après découpage — attendu ' . count($inputRuns) . ', reçu ' . count($translatedRuns), [
                'target_lang'    => $targetLang,
                'expected_count' => count($inputRuns),
                'received_count' => count($translatedRuns),
            ]);
            return null;
        }

        $mutatedTokens = $tokens; // shallow copy (scalar values)
        foreach ($runObjects as $j => $runObj) {
            $mutatedTokens[$runObj['index']]['value'] = $translatedRuns[$j];
        }

        $translatedHtml = $this->segmenter->reassemble($mutatedTokens);

        // ── Final guard: HTML tag multiset must be identical ──────────────────
        if (! $this->tagMultisetEqual($html, $translatedHtml)) {
            Log::warning('[GeminiTranslationDriver] Multiset des balises HTML modifié après traduction — rejeté.', [
                'target_lang' => $targetLang,
            ]);
            return null;
        }

        return [
            'subject'      => trim((string) $translatedSubject),
            'preview_text' => $translatedPreview !== null ? trim($translatedPreview) : null,
            'html_content' => $translatedHtml,
        ];
    }

    /**
     * Translate one run chunk and return validated translated runs.
     *
     * @param  array<int, string> $runs
     * @return array{subject: string, preview_text: string|null, runs: array<int, string>}|null
     */
    private function translateChunk(
        string $subject,
        ?string $preview,
        array $runs,
        string $sourceLang,
        string $targetLang,
        int $chunkIndex,
    ): ?array {
        $prompt = $this->buildPrompt($subject, $preview, $runs, $sourceLang, $targetLang);

        try {
            $response = $this->gemini->request($prompt, 60, [
                'response_mime_type' => 'application/json',
                'maxOutputTokens'    => 8192,
            ]);

            if ($response->failed()) {
                Log::warning('[GeminiTranslationDriver] Réponse HTTP échouée depuis Gemini.', [
                    'status'      => $response->status(),
                    'target_lang' => $targetLang,
                    'chunk_index' => $chunkIndex,
                ]);
                return null;
            }

            $finishReason = $response->json('candidates.0.finishReason');
            if ($finishReason === 'MAX_TOKENS') {
                Log::warning('[GeminiTranslationDriver] Gemini a atteint MAX_TOKENS — traduction rejetée.', [
                    'target_lang' => $targetLang,
                    'chunk_index' => $chunkIndex,
                ]);
                return null;
            }

            $text = $this->gemini->extractText($response);

            if ($text === null) {
                Log::warning('[GeminiTranslationDriver] Réponse Gemini vide ou structure inattendue.', [
                    'target_lang' => $targetLang,
                    'chunk_index' => $chunkIndex,
                ]);
                return null;
            }

            return $this->parseChunkResponse(
                text: $text,
                originalSubject: $subject,
                originalPreview: $preview,
                inputRuns: $runs,
                targetLang: $targetLang,
                chunkIndex: $chunkIndex,
            );
        } catch (\Throwable $e) {
            Log::warning('[GeminiTranslationDriver] Exception lors de l\'appel Gemini — traduction ignorée.', [
                'target_lang' => $targetLang,
                'chunk_index' => $chunkIndex,
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
     * Parse one chunk response and validate all non-HTML guards.
     *
     * @param  array<int, string> $inputRuns
     * @return array{subject: string, preview_text: string|null, runs: array<int, string>}|null
     */
    private function parseChunkResponse(
        string $text,
        string $originalSubject,
        ?string $originalPreview,
        array $inputRuns,
        string $targetLang,
        int $chunkIndex,
    ): ?array {
        $data = $this->gemini->decodeJson($text);

        if ($data === null) {
            Log::warning('[GeminiTranslationDriver] JSON non décodable dans la réponse Gemini.', [
                'target_lang' => $targetLang,
                'chunk_index' => $chunkIndex,
                'raw'         => mb_substr($this->gemini->stripFences($text), 0, 500),
            ]);
            return null;
        }

        // ── Guard: subject must be a non-empty string ─────────────────────────
        if (! isset($data['subject']) || ! is_string($data['subject']) || trim($data['subject']) === '') {
            Log::warning('[GeminiTranslationDriver] Champ "subject" manquant ou invalide.', [
                'target_lang' => $targetLang,
                'chunk_index' => $chunkIndex,
            ]);
            return null;
        }

        // ── Guard: runs must be an array of the exact same length ─────────────
        if (! isset($data['runs']) || ! is_array($data['runs'])) {
            Log::warning('[GeminiTranslationDriver] Champ "runs" absent ou non-tableau.', [
                'target_lang' => $targetLang,
                'chunk_index' => $chunkIndex,
            ]);
            return null;
        }

        if (count($data['runs']) !== count($inputRuns)) {
            Log::warning('[GeminiTranslationDriver] Longueur des runs incorrecte — attendu ' . count($inputRuns) . ', reçu ' . count($data['runs']), [
                'target_lang'    => $targetLang,
                'chunk_index'    => $chunkIndex,
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
                    'chunk_index' => $chunkIndex,
                ]);
                return null;
            }
        }

        // ── Guard: {{...}} order preserved per run ────────────────────────────
        foreach ($inputRuns as $i => $inputRun) {
            if (! $this->mergeTagsOrderedEqual($inputRun, $translatedRuns[$i])) {
                Log::warning("[GeminiTranslationDriver] Merge tags non préservés dans le run #{$i}.", [
                    'target_lang' => $targetLang,
                    'chunk_index' => $chunkIndex,
                    'input'       => mb_substr($inputRun, 0, 200),
                    'translated'  => mb_substr($translatedRuns[$i], 0, 200),
                ]);
                return null;
            }
        }

        // ── Guard: {{...}} order preserved in subject ─────────────────────────
        if (! $this->mergeTagsOrderedEqual($originalSubject, $data['subject'])) {
            Log::warning('[GeminiTranslationDriver] Merge tags non préservés dans le subject.', [
                'target_lang' => $targetLang,
                'chunk_index' => $chunkIndex,
            ]);
            return null;
        }

        // ── Guard: {{...}} order preserved in preview (if non-empty) ──────────
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
                    'chunk_index' => $chunkIndex,
                ]);
                return null;
            }
        }

        return [
            'subject'      => trim($data['subject']),
            'preview_text' => $translatedPreview,
            'runs'         => $translatedRuns,
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
