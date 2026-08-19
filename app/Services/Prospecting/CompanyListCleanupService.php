<?php

namespace App\Services\Prospecting;

use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Log;

/**
 * CompanyListCleanupService — "Nettoyer avec l'IA". Takes whatever a user
 * pasted (plain list, trade-show exhibitor export with one field per line,
 * PDF copy-paste, ...) and asks Gemini to reformat it into clean CSV that
 * lands back in the same editable textarea. It never persists, never
 * charges (no ProviderCallLedger entry — Gemini is unmetered here), and it
 * never throws — the input cap is the only cost control.
 *
 * Mirrors IntentQueryService's contract exactly: api-key guard → GeminiClient
 * request with response_mime_type=application/json → extractText() →
 * decodeJson() → per-field validation → Log::warning + neutral return on
 * any failure.
 */
class CompanyListCleanupService
{
    // ponytail: length guards, not an exact company count — MAX_LINES assumes
    // up to ~4 lines per company (the exhibitor blank-line-per-field shape),
    // so ~200 companies stays comfortably under it while genuinely oversized
    // pastes still get rejected before the Gemini call.
    private const MAX_BYTES = 50000;

    private const MAX_LINES = 800;

    private const EXPECTED_HEADER = 'company,country,city,domain,description';

    public function __construct(
        private readonly GeminiClient $gemini = new GeminiClient(),
    ) {}

    /**
     * @return array{ok: bool, text: string, count: int, error: ?string}
     */
    public function cleanup(string $rawText): array
    {
        $raw = trim($rawText);

        if ($raw === '') {
            return $this->failure('empty');
        }

        if (strlen($raw) > self::MAX_BYTES || $this->countLines($raw) > self::MAX_LINES) {
            return $this->failure('too_long');
        }

        if (! $this->gemini->hasApiKey()) {
            Log::warning('[CompanyListCleanupService] Clé API Gemini non configurée — nettoyage ignoré.');

            return $this->failure('no_api_key');
        }

        try {
            $response = $this->gemini->request($this->buildPrompt($raw), 60, [
                'response_mime_type' => 'application/json',
                'maxOutputTokens' => 8192,
            ]);

            if ($response->failed()) {
                Log::warning('[CompanyListCleanupService] Réponse HTTP échouée depuis Gemini.', [
                    'status' => $response->status(),
                ]);

                return $this->failure('gemini_failed');
            }

            $text = $this->gemini->extractText($response);

            if ($text === null) {
                Log::warning('[CompanyListCleanupService] Réponse Gemini vide ou structure inattendue.');

                return $this->failure('gemini_failed');
            }

            return $this->parseResult($text);
        } catch (\Throwable $e) {
            Log::warning('[CompanyListCleanupService] Exception lors de l\'appel Gemini — nettoyage ignoré.', [
                'error' => $e->getMessage(),
            ]);

            return $this->failure('gemini_failed');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPrompt(string $raw): string
    {
        return <<<PROMPT
Tu es un assistant qui nettoie des listes d'entreprises collées depuis des sources variées
(export de salon professionnel, copier-coller de PDF, liste en vrac, etc.) pour un usage de
prospection commerciale B2B.

Le texte source peut contenir des lignes parasites : numéro de stand, hall, pavillon, ou un
format où chaque champ (nom, pays, ville, descriptif) est séparé par des lignes vides.

Texte source (entre les balises <source></source>):
<source>
{$raw}
</source>

Ta tâche :
- Identifier chaque VRAIE entreprise mentionnée dans le texte. Ignore les lignes de stand, hall,
  pavillon ou numéro d'emplacement — ce ne sont jamais des entreprises.
- Pour chaque entreprise, produire une ligne CSV avec exactement les colonnes :
  company,country,city,domain,description
  - company : nom de l'entreprise, orthographe originale préservée.
  - country, city : si mentionnés dans le texte, sinon laisser vide.
  - domain : uniquement si un site web ou domaine explicite apparaît dans le texte, sinon vide.
  - description : le texte marketing / descriptif associé à cette entreprise, si présent, sinon vide.
- N'invente JAMAIS d'entreprise qui n'apparaît pas explicitement dans le texte source.
- Ne fusionne jamais deux entreprises différentes en une seule ligne.
- Échappe les virgules et guillemets dans les champs CSV selon les règles standard (guillemets doubles).

Réponds UNIQUEMENT avec un objet JSON strict (sans markdown, sans commentaire), sous la forme :
{"csv": "company,country,city,domain,description\\n<ligne 1>\\n<ligne 2>\\n..."}

Inclue la ligne d'en-tête. N'ajoute aucun texte hors de cet objet JSON.
PROMPT;
    }

    /**
     * @return array{ok: bool, text: string, count: int, error: ?string}
     */
    private function parseResult(string $text): array
    {
        $data = $this->gemini->decodeJson($text);

        if ($data === null) {
            Log::warning('[CompanyListCleanupService] JSON non décodable dans la réponse Gemini.', [
                'raw' => mb_substr($this->gemini->stripFences($text), 0, 200),
            ]);

            return $this->failure('malformed');
        }

        $csv = $data['csv'] ?? null;

        if (! is_string($csv) || trim($csv) === '') {
            Log::warning('[CompanyListCleanupService] Champ csv manquant ou invalide dans la réponse Gemini.');

            return $this->failure('malformed');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $lines = array_values(array_filter($lines, fn ($line) => trim($line) !== ''));

        if ($lines === []) {
            return $this->failure('malformed');
        }

        $hasHeader = str_starts_with(strtolower(str_replace(' ', '', $lines[0])), self::EXPECTED_HEADER);
        $rows = $hasHeader ? array_slice($lines, 1) : $lines;
        $header = $hasHeader ? $lines[0] : self::EXPECTED_HEADER;

        if ($rows === []) {
            return $this->failure('malformed');
        }

        return [
            'ok' => true,
            'text' => implode("\n", array_merge([$header], $rows)),
            'count' => count($rows),
            'error' => null,
        ];
    }

    private function countLines(string $text): int
    {
        return count(array_filter(preg_split('/\r\n|\r|\n/', $text), fn ($line) => trim($line) !== ''));
    }

    /**
     * @return array{ok: bool, text: string, count: int, error: ?string}
     */
    private function failure(string $reason): array
    {
        return ['ok' => false, 'text' => '', 'count' => 0, 'error' => $reason];
    }
}
