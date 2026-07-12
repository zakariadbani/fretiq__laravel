<?php

namespace App\Services\Gemini;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * GeminiClient — thin HTTP wrapper shared by the Gemini-backed services
 * (GeminiScoringDriver, IntentQueryService, ScoreExplanationService,
 * GeminiTranslationDriver).
 *
 * Encapsulates ONLY the byte-identical mechanics every caller repeated:
 *   - the generateContent POST call (x-goog-api-key header, contents/
 *     generationConfig body)
 *   - extracting candidates.0.content.parts.0.text
 *   - stripping ```json markdown fences + json_decode
 *
 * Deliberately thin: this class never logs and never throws on its own —
 * every caller keeps its own try/catch, its own failed()/empty-text checks,
 * and its own Log::warning messages so existing logging + fallback behavior
 * is preserved exactly. request() lets transport exceptions propagate so
 * callers' existing catch(\Throwable) blocks keep working unchanged.
 */
class GeminiClient
{
    /**
     * Whether GEMINI_API_KEY is configured. Callers guard on this before
     * building a prompt / making a request, each with their own log message.
     */
    public function hasApiKey(): bool
    {
        return ! empty(config('services.gemini.api_key'));
    }

    /**
     * Call Gemini's generateContent endpoint. Throws on transport failure —
     * callers wrap this in their own try/catch (unchanged) to preserve their
     * existing exception logging.
     */
    public function request(string $prompt, int $timeout, array $generationConfig): Response
    {
        $apiKey = config('services.gemini.api_key');
        $model  = config('services.gemini.model', 'gemini-2.5-flash');

        return Http::timeout($timeout)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => $generationConfig,
                ]
            );
    }

    /**
     * Extract candidates.0.content.parts.0.text, or null when absent/empty.
     */
    public function extractText(Response $response): ?string
    {
        $text = $response->json('candidates.0.content.parts.0.text');

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * Strip markdown code fences (```json ... ```) Gemini sometimes wraps
     * JSON responses in.
     */
    public function stripFences(string $text): string
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);

        return trim($text);
    }

    /**
     * Fence-strip + json_decode. Returns null on non-array/invalid JSON.
     * Callers that need the stripped text for a decode-failure log call
     * stripFences() themselves (pure/deterministic — no extra HTTP cost).
     */
    public function decodeJson(string $text): ?array
    {
        $data = json_decode($this->stripFences($text), true);

        return is_array($data) ? $data : null;
    }
}
