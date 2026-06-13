<?php

namespace App\Services\Scoring;

use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GeminiScoringDriver — AI-powered lead scoring via Google Gemini API.
 *
 * Returns null (with a Log::warning) when:
 *   - GEMINI_API_KEY is not configured
 *   - The HTTP request fails or throws
 *   - The response cannot be parsed as valid JSON
 *   - The JSON does not contain a score in [0-100] and a non-empty explanation
 *
 * The key is sent in the x-goog-api-key header, never in the URL.
 * generationConfig requests application/json to get a clean JSON body.
 */
class GeminiScoringDriver implements ScoringDriverInterface
{
    public function score(array $candidate, ProspectCriteria $criteria): ?array
    {
        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            Log::warning('[GeminiScoringDriver] Clé API Gemini non configurée — scoring Gemini ignoré.', [
                'domain' => $candidate['domain'] ?? ($candidate['url'] ?? $candidate['link'] ?? ''),
            ]);
            return null;
        }

        $model  = config('services.gemini.model', 'gemini-2.0-flash');
        $prompt = $this->buildPrompt($candidate, $criteria);

        try {
            $response = Http::timeout(20)
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
                        ],
                    ]
                );

            if ($response->failed()) {
                Log::warning('[GeminiScoringDriver] Réponse HTTP échouée depuis Gemini.', [
                    'status' => $response->status(),
                    'domain' => $candidate['domain'] ?? '',
                ]);
                return null;
            }

            $text = $response->json('candidates.0.content.parts.0.text');

            if (! is_string($text) || $text === '') {
                Log::warning('[GeminiScoringDriver] Réponse Gemini vide ou structure inattendue.', [
                    'domain' => $candidate['domain'] ?? '',
                ]);
                return null;
            }

            return $this->parseResult($text, $candidate);
        } catch (\Throwable $e) {
            Log::warning('[GeminiScoringDriver] Exception lors de l\'appel Gemini — scoring ignoré.', [
                'domain' => $candidate['domain'] ?? '',
                'error'  => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPrompt(array $candidate, ProspectCriteria $criteria): string
    {
        $domain    = $candidate['domain']  ?? parse_url($candidate['url'] ?? $candidate['link'] ?? '', PHP_URL_HOST) ?? '';
        $title     = $candidate['title']   ?? '';
        $snippet   = $candidate['snippet'] ?? '';
        $url       = $candidate['url']     ?? $candidate['link'] ?? '';

        $sectors   = implode(', ', $criteria->sectors           ?? []);
        $countries = implode(', ', $criteria->countries         ?? []);
        $positions = implode(', ', $criteria->target_positions  ?? []);
        $name      = $criteria->name ?? '';

        return <<<PROMPT
Tu es un expert en prospection B2B pour TCL France, un commissionnaire de transport.
Évalue la pertinence de ce prospect pour une campagne de prospection fret.

Candidat:
- Domaine: {$domain}
- Titre: {$title}
- Description: {$snippet}
- URL: {$url}

Critères de prospection:
- Nom: {$name}
- Secteurs cibles: {$sectors}
- Pays cibles: {$countries}
- Postes cibles: {$positions}

Réponds UNIQUEMENT avec un objet JSON strict (sans markdown, sans commentaire):
{"score": <entier entre 0 et 100>, "explanation": "<1-2 phrases en français expliquant le score>"}

Le score reflète la probabilité que ce prospect soit un chargeur ou une entreprise intéressée
par des services de fret international (transit, freight forwarding, logistique).
PROMPT;
    }

    /**
     * Parse and validate the JSON text returned by Gemini.
     *
     * @return array{score: int, explanation: string}|null
     */
    private function parseResult(string $text, array $candidate): ?array
    {
        // Strip potential markdown code fences (```json ... ```)
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (! is_array($data)) {
            Log::warning('[GeminiScoringDriver] JSON non décodable dans la réponse Gemini.', [
                'domain' => $candidate['domain'] ?? '',
                'raw'    => mb_substr($text, 0, 200),
            ]);
            return null;
        }

        $score       = $data['score']       ?? null;
        $explanation = $data['explanation'] ?? null;

        if (! is_int($score) && ! (is_numeric($score) && is_string($score) && ctype_digit(ltrim((string)$score, '-')))) {
            // Accept numeric string as int
            if (is_numeric($score)) {
                $score = (int) $score;
            } else {
                Log::warning('[GeminiScoringDriver] Champ score manquant ou invalide dans la réponse Gemini.', [
                    'domain' => $candidate['domain'] ?? '',
                    'score'  => $score,
                ]);
                return null;
            }
        }

        $score = (int) $score;

        if ($score < 0 || $score > 100) {
            Log::warning('[GeminiScoringDriver] Score hors limites (0-100) dans la réponse Gemini.', [
                'domain' => $candidate['domain'] ?? '',
                'score'  => $score,
            ]);
            return null;
        }

        if (! is_string($explanation) || trim($explanation) === '') {
            Log::warning('[GeminiScoringDriver] Explication manquante ou vide dans la réponse Gemini.', [
                'domain' => $candidate['domain'] ?? '',
            ]);
            return null;
        }

        return [
            'score'       => $score,
            'explanation' => trim($explanation),
        ];
    }
}
