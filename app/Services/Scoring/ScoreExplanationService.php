<?php

namespace App\Services\Scoring;

use App\Models\Company;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Log;

/**
 * ScoreExplanationService — generates a French narrative explaining a company's
 * current AI lead score. Explain-only: never reads or writes ai_score.
 *
 * Priority:
 *   1. Gemini API (when GEMINI_API_KEY is configured)
 *   2. Deterministic French fallback (no network, always returns a non-empty string)
 */
class ScoreExplanationService
{
    public function __construct(
        private readonly GeminiClient $gemini = new GeminiClient(),
    ) {}

    /**
     * Generate a French explanation for a company's current ai_score.
     * Returns '' when ai_score is null (nothing to explain).
     */
    public function explain(Company $company): string
    {
        if ($company->ai_score === null) {
            return '';
        }

        $profile = [
            'name'                 => $company->name,
            'domain'               => $company->domain,
            'sector'               => $company->sector,
            'country'              => $company->country,
            'estimated_size'       => $company->estimated_size,
            'qualification_status' => $company->qualification_status,
            'relationship'         => $company->relationship,
            'source'               => $company->source,
            'contacts_count'       => $company->contacts()->count(),
            'ai_score'             => (int) $company->ai_score,
            'ai_explanation'       => $company->ai_explanation,
        ];

        $gemini = $this->viaGemini($profile);

        if ($gemini !== null) {
            return $gemini;
        }

        return $this->fallback($profile);
    }

    private function viaGemini(array $profile): ?string
    {
        if (! $this->gemini->hasApiKey()) {
            return null;
        }

        $score   = $profile['ai_score'];
        $name    = $profile['name']    ?? '';
        $domain  = $profile['domain']  ?? '';
        $sector  = $profile['sector']  ?? '';
        $country = $profile['country'] ?? '';
        $size    = $profile['estimated_size'] ?? '';
        $status  = $profile['qualification_status'] ?? '';
        $rel     = $profile['relationship'] ?? '';
        $source  = $profile['source'] ?? '';
        $contacts = (int) ($profile['contacts_count'] ?? 0);

        $prompt = <<<PROMPT
Tu es un expert en prospection B2B fret pour TCL France (commissionnaire de transport).

Voici le profil du prospect :
- Nom : {$name}
- Domaine : {$domain}
- Secteur : {$sector}
- Pays : {$country}
- Taille estimée : {$size}
- Statut de qualification : {$status}
- Relation : {$rel}
- Source : {$source}
- Nombre de contacts : {$contacts}
- Score IA actuel : {$score}/100

En 2 à 4 phrases en français, explique POURQUOI ce score de {$score}/100 est justifié pour ce prospect dans le contexte d'une campagne de prospection fret international.

Réponds UNIQUEMENT avec un objet JSON strict (sans markdown, sans commentaire) :
{"explanation": "<2 à 4 phrases en français>"}
PROMPT;

        try {
            $response = $this->gemini->request($prompt, 20, [
                'response_mime_type' => 'application/json',
            ]);

            if ($response->failed()) {
                Log::warning('[ScoreExplanationService] Réponse HTTP échouée depuis Gemini.', [
                    'company' => $profile['name'] ?? '',
                    'status'  => $response->status(),
                ]);
                return null;
            }

            $text = $this->gemini->extractText($response);

            if ($text === null) {
                Log::warning('[ScoreExplanationService] Réponse Gemini vide ou structure inattendue.', [
                    'company' => $profile['name'] ?? '',
                ]);
                return null;
            }

            $data = $this->gemini->decodeJson($text);

            if ($data === null) {
                return null;
            }

            $explanation = $data['explanation'] ?? null;

            if (! is_string($explanation) || trim($explanation) === '') {
                return null;
            }

            return trim($explanation);

        } catch (\Throwable $e) {
            Log::warning('[ScoreExplanationService] Exception lors de l\'appel Gemini.', [
                'company' => $profile['name'] ?? '',
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fallback(array $profile): string
    {
        $score = (int) $profile['ai_score'];

        if ($score >= 70) {
            $tier = 'fort potentiel';
        } elseif ($score >= 40) {
            $tier = 'potentiel modéré';
        } else {
            $tier = 'faible potentiel';
        }

        $parts = [];
        $parts[] = "Ce prospect obtient un score de {$score}/100, ce qui correspond à un {$tier} dans le contexte de la prospection fret TCL France.";

        $details = [];
        if (! empty($profile['sector'])) {
            $details[] = "secteur : {$profile['sector']}";
        }
        if (! empty($profile['country'])) {
            $details[] = "pays : {$profile['country']}";
        }
        if (! empty($profile['domain'])) {
            $details[] = "domaine : {$profile['domain']}";
        }
        if ($profile['contacts_count'] > 0) {
            $count = (int) $profile['contacts_count'];
            $details[] = "{$count} contact(s) identifié(s)";
        }

        if (! empty($details)) {
            $parts[] = "Les éléments pris en compte incluent : " . implode(', ', $details) . ".";
        }

        if (! empty($profile['ai_explanation'])) {
            $parts[] = "Détail : " . $profile['ai_explanation'];
        }

        return implode(' ', $parts);
    }
}
