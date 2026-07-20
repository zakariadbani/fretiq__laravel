<?php

namespace App\Services\Scoring;

use App\Models\ProspectCriteria;
use App\Services\Gemini\GeminiClient;
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
    public function __construct(
        private readonly GeminiClient $gemini = new GeminiClient(),
    ) {}

    public function score(array $candidate, ProspectCriteria $criteria, ?int $timeoutSeconds = null): ?array
    {
        if (! $this->gemini->hasApiKey()) {
            Log::warning('[GeminiScoringDriver] Clé API Gemini non configurée — scoring Gemini ignoré.', [
                'domain' => $candidate['domain'] ?? ($candidate['url'] ?? $candidate['link'] ?? ''),
            ]);
            return null;
        }

        $prompt = $this->buildPrompt($candidate, $criteria);

        try {
            $response = $this->gemini->request($prompt, max(1, $timeoutSeconds ?? 20), [
                'response_mime_type' => 'application/json',
            ]);

            if ($response->failed()) {
                Log::warning('[GeminiScoringDriver] Réponse HTTP échouée depuis Gemini.', [
                    'status' => $response->status(),
                    'domain' => $candidate['domain'] ?? '',
                ]);
                return null;
            }

            $text = $this->gemini->extractText($response);

            if ($text === null) {
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

        $aiTarget  = trim((string) ($criteria->ai_target  ?? ''));
        $aiExclude = trim((string) ($criteria->ai_exclude ?? ''));

        $targetBlock  = $aiTarget  !== '' ? "- Cible: {$aiTarget}\n"      : '';
        $excludeBlock = $aiExclude !== '' ? "- À exclure: {$aiExclude}\n" : '';

        // Homepage excerpt (HomepageSnapshotService). When absent, the prompt stays
        // byte-identical to the pre-Phase-2 version — both blocks collapse to ''.
        $excerpt = $candidate['homepage_excerpt'] ?? null;
        $hasExcerpt = is_string($excerpt) && trim($excerpt) !== '';

        $homepageBlock = $hasExcerpt
            ? "\nExtrait de la page d'accueil du site:\n\"\"\"\n" . trim($excerpt) . "\n\"\"\""
            : '';

        $junkBlock = $hasExcerpt
            ? "\nSi l'extrait de la page d'accueil montre qu'il s'agit d'un site d'actualités ou de presse, "
              . "d'un annuaire en ligne, d'un hébergeur de documents ou de PDF, d'un site d'offres d'emploi, "
              . "d'une institution publique ou d'une encyclopédie — et non d'une entreprise qui expédie des "
              . "marchandises — attribue un score entre 0 et 10 et mets exclude à false: ce n'est pas un "
              . "concurrent, seulement un résultat sans valeur. Le champ exclude reste réservé aux véritables "
              . "concurrents (transporteur, transitaire, commissionnaire de transport, logisticien).\n"
            : '';

        return <<<PROMPT
Tu es un expert en prospection B2B pour TCL France, un commissionnaire de transport.
Évalue la pertinence de ce prospect pour une campagne de prospection fret.

Candidat:
- Domaine: {$domain}
- Titre: {$title}
- Description: {$snippet}
- URL: {$url}{$homepageBlock}

Critères de prospection:
- Nom: {$name}
- Secteurs cibles: {$sectors}
- Pays cibles: {$countries}
- Postes cibles: {$positions}
{$targetBlock}{$excludeBlock}
Classe la pertinence du candidat par rapport à la Cible ci-dessus si elle est précisée.
Si le candidat correspond à la description "À exclure" (ex: transporteur, transitaire,
commissionnaire de transport ou logisticien concurrent), mets exclude à true et explique
pourquoi dans explanation.
{$junkBlock}
Réponds UNIQUEMENT avec un objet JSON strict (sans markdown, sans commentaire):
{"score": <entier entre 0 et 100>, "explanation": "<1-2 phrases en français expliquant le score>", "exclude": <true ou false>}

Le score reflète la probabilité que ce prospect soit un chargeur ou une entreprise intéressée
par des services de fret international (transit, freight forwarding, logistique).
PROMPT;
    }

    /**
     * Parse and validate the JSON text returned by Gemini.
     *
     * @return array{score: int, explanation: string, exclude: bool}|null
     */
    private function parseResult(string $text, array $candidate): ?array
    {
        $data = $this->gemini->decodeJson($text);

        if ($data === null) {
            Log::warning('[GeminiScoringDriver] JSON non décodable dans la réponse Gemini.', [
                'domain' => $candidate['domain'] ?? '',
                'raw'    => mb_substr($this->gemini->stripFences($text), 0, 200),
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
            'exclude'     => (bool) ($data['exclude'] ?? false),
        ];
    }
}
