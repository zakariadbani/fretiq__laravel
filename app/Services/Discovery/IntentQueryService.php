<?php

namespace App\Services\Discovery;

use App\Models\ProspectCriteria;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Log;

/**
 * IntentQueryService — turns free-text targeting (ai_target / ai_exclude) into
 * Google `q` search strings for SerpAPI, via the Gemini API.
 *
 * Mirrors GeminiTranslationDriver's HTTP block: api-key guard → HTTP call
 * with response_mime_type=application/json → read candidates.0.content.parts.0.text
 * → strip ```json fences → json_decode → catch(\Throwable) → NEVER throw.
 *
 * Returns [] (never throws) when:
 *   - both ai_target and ai_exclude are empty
 *   - GEMINI_API_KEY is not configured
 *   - the HTTP call fails or the response is unparseable
 *
 * Callers (CompanyDiscoveryService) fall back to buildQueries() on [].
 */
class IntentQueryService
{
    private const MAX_QUERIES = 25;

    public function __construct(
        private readonly GeminiClient $gemini = new GeminiClient(),
    ) {}

    /**
     * Expand a criteria's Cible/Exclure description into Google search query strings.
     *
     * @return list<string>
     */
    public function expand(ProspectCriteria $criteria): array
    {
        $target  = trim((string) ($criteria->ai_target  ?? ''));
        $exclude = trim((string) ($criteria->ai_exclude ?? ''));

        if ($target === '' && $exclude === '') {
            return [];
        }

        if (! $this->gemini->hasApiKey()) {
            Log::channel('discovery')->warning('[IntentQueryService] Clé API Gemini non configurée — génération de requêtes ignorée.', [
                'criteria_id' => $criteria->id ?? null,
            ]);
            return [];
        }

        $prompt = $this->buildPrompt($criteria, $target, $exclude);

        try {
            $response = $this->gemini->request($prompt, 60, [
                'response_mime_type' => 'application/json',
                'maxOutputTokens'    => 8192,
            ]);

            if ($response->failed()) {
                Log::channel('discovery')->warning('[IntentQueryService] Réponse HTTP échouée depuis Gemini.', [
                    'status'      => $response->status(),
                    'criteria_id' => $criteria->id ?? null,
                ]);
                return [];
            }

            $text = $this->gemini->extractText($response);

            if ($text === null) {
                Log::channel('discovery')->warning('[IntentQueryService] Réponse Gemini vide ou structure inattendue.', [
                    'criteria_id' => $criteria->id ?? null,
                ]);
                return [];
            }

            return $this->parseResult($text, $criteria);
        } catch (\Throwable $e) {
            Log::channel('discovery')->warning('[IntentQueryService] Exception lors de l\'appel Gemini — génération de requêtes ignorée.', [
                'criteria_id' => $criteria->id ?? null,
                'error'       => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPrompt(ProspectCriteria $criteria, string $target, string $exclude): string
    {
        $countryLabels = config('global.data.company_countries', []);
        $countries     = implode(', ', array_map(
            fn ($c) => $countryLabels[$c] ?? $c,
            $criteria->countries ?? []
        ));

        $targetBlock  = $target  !== '' ? $target  : '(non précisé)';
        $excludeBlock = $exclude !== '' ? $exclude : '(aucune exclusion précisée)';

        return <<<PROMPT
Tu es un expert en prospection B2B pour TCL France, un commissionnaire de transport.
Ton rôle est d'écrire des requêtes de recherche Google pour trouver des entreprises
correspondant à la Cible ci-dessous, en excluant celles qui correspondent à la description
À exclure grâce aux opérateurs de recherche négatifs de Google (ex: -transitaire -logistique
-"commissionnaire de transport").

Cible (entreprises à trouver):
{$targetBlock}

À exclure (entreprises à ne PAS trouver — concurrents, prestataires de transport, etc.):
{$excludeBlock}

Contexte géographique: {$countries}

Génère une liste de requêtes de recherche Google concises et efficaces (utilise des guillemets,
l'opérateur OR et des termes géographiques quand utile) qui trouvent la Cible et excluent
explicitement, via des opérateurs négatifs (- devant chaque terme ou expression à exclure),
les entreprises décrites dans À exclure.

Réponds UNIQUEMENT avec un objet JSON strict (sans markdown, sans commentaire):
{"queries": ["<requête Google 1>", "<requête Google 2>", ...]}

Génère au maximum 25 requêtes.
PROMPT;
    }

    /**
     * Parse and validate the JSON text returned by Gemini.
     *
     * @return list<string>
     */
    private function parseResult(string $text, ProspectCriteria $criteria): array
    {
        $data = $this->gemini->decodeJson($text);

        if ($data === null) {
            Log::channel('discovery')->warning('[IntentQueryService] JSON non décodable dans la réponse Gemini.', [
                'criteria_id' => $criteria->id ?? null,
                'raw'         => mb_substr($this->gemini->stripFences($text), 0, 200),
            ]);
            return [];
        }

        $queries = $data['queries'] ?? null;

        if (! is_array($queries)) {
            Log::channel('discovery')->warning('[IntentQueryService] Champ queries manquant ou invalide dans la réponse Gemini.', [
                'criteria_id' => $criteria->id ?? null,
            ]);
            return [];
        }

        $clean = [];
        foreach ($queries as $q) {
            if (is_string($q) && trim($q) !== '') {
                $clean[] = trim($q);
            }
        }

        $clean = array_values(array_unique($clean));

        return array_slice($clean, 0, self::MAX_QUERIES);
    }
}
