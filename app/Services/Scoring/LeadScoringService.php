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
     * @return array{score: int, explanation: string}
     */
    public function score(array $candidate, ProspectCriteria $criteria): array
    {
        try {
            $driver = config('services.scoring.driver', 'heuristic');

            if ($driver === 'gemini') {
                $result = $this->gemini->score($candidate, $criteria);

                if ($result === null) {
                    Log::warning('[LeadScoringService] Gemini indisponible — repli heuristique', [
                        'domain'      => $candidate['domain'] ?? ($candidate['url'] ?? $candidate['link'] ?? ''),
                        'criteria_id' => $criteria->id ?? null,
                    ]);

                    // Heuristic is infallible — no null check needed.
                    return $this->heuristic->score($candidate, $criteria);
                }

                return $result;
            }

            // Default: heuristic driver
            return $this->heuristic->score($candidate, $criteria);
        } catch (\Throwable $e) {
            // Safety net: should never reach here, but we must never throw.
            Log::error('[LeadScoringService] Exception inattendue dans score() — repli heuristique.', [
                'error'       => $e->getMessage(),
                'domain'      => $candidate['domain'] ?? '',
                'criteria_id' => $criteria->id ?? null,
            ]);

            /** @var array{score: int, explanation: string} */
            return $this->heuristic->score($candidate, $criteria) ?? [
                'score'       => 0,
                'explanation' => 'Erreur de scoring — score par défaut.',
            ];
        }
    }
}
