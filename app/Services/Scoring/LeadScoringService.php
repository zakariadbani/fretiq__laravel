<?php

namespace App\Services\Scoring;

use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\Log;

/**
 * LeadScoringService — façade orchestrant les drivers de scoring.
 *
 * Lit config('services.scoring.driver') au moment de l'appel (pas au boot)
 * pour permettre les overrides de config en test.
 *
 * Stratégie:
 *   'gemini'    → essaie GeminiScoringDriver ; si null → repli sur heuristique
 *   'heuristic' (défaut) → HeuristicScoringDriver directement
 *
 * Override par critère (indépendant de SCORING_DRIVER): quand la criteria a une
 * description ai_target OU ai_exclude non vide ET que GEMINI_API_KEY est configurée,
 * le driver Gemini est forcé pour cet appel — sinon l'exclusion IA ne ferait jamais
 * rien tant que SCORING_DRIVER=heuristic (défaut). Sans clé, repli heuristique +
 * avertissement loggué (pas d'erreur silencieuse).
 *
 * La méthode score() ne lève JAMAIS d'exception. L'heuristique est le filet
 * de sécurité final et ne peut pas échouer.
 */
class LeadScoringService
{
    public function __construct(
        private readonly HeuristicScoringDriver $heuristic,
        private readonly GeminiScoringDriver    $gemini,
    ) {}

    /**
     * Score a discovery candidate against the given ProspectCriteria.
     *
     * @param  array{domain?: string, title?: string, snippet?: string, url?: string, link?: string}  $candidate
     * @return array{score: int, explanation: string, exclude: bool}
     */
    public function score(array $candidate, ProspectCriteria $criteria, ?int $timeoutSeconds = null): array
    {
        try {
            $hasIntent = trim((string) ($criteria->ai_target ?? '')) !== ''
                || trim((string) ($criteria->ai_exclude ?? '')) !== '';
            $hasGeminiKey = (bool) config('services.gemini.api_key');

            $driver = config('services.scoring.driver', 'heuristic');

            if ($hasIntent && ! $hasGeminiKey) {
                Log::channel('gemini')->warning('[LeadScoringService] Description IA renseignée mais GEMINI_API_KEY absente — repli heuristique (exclusion IA inactive).', [
                    'criteria_id' => $criteria->id ?? null,
                ]);
            }

            if ($driver === 'gemini' || ($hasIntent && $hasGeminiKey)) {
                $result = $this->gemini->score($candidate, $criteria, $timeoutSeconds);

                if ($result === null) {
                    Log::channel('gemini')->warning('[LeadScoringService] Gemini indisponible — repli heuristique', [
                        'domain'      => $candidate['domain'] ?? ($candidate['url'] ?? $candidate['link'] ?? ''),
                        'criteria_id' => $criteria->id ?? null,
                    ]);

                    // Heuristic is infallible — no null check needed.
                    return $this->heuristic->score($candidate, $criteria, $timeoutSeconds);
                }

                return $result;
            }

            // Default: heuristic driver
            return $this->heuristic->score($candidate, $criteria, $timeoutSeconds);
        } catch (\Throwable $e) {
            // Safety net: should never reach here, but we must never throw.
            Log::channel('gemini')->error('[LeadScoringService] Exception inattendue dans score() — repli heuristique.', [
                'error'       => $e->getMessage(),
                'domain'      => $candidate['domain'] ?? '',
                'criteria_id' => $criteria->id ?? null,
            ]);

            /** @var array{score: int, explanation: string, exclude: bool} */
            return $this->heuristic->score($candidate, $criteria, $timeoutSeconds) ?? [
                'score'       => 0,
                'explanation' => 'Erreur de scoring — score par défaut.',
                'exclude'     => false,
            ];
        }
    }
}
