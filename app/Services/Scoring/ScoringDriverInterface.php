<?php

namespace App\Services\Scoring;

use App\Models\ProspectCriteria;

/**
 * ScoringDriverInterface — contract for lead-scoring drivers.
 *
 * Each driver receives a raw discovery candidate and the active ProspectCriteria
 * and returns a scored result or null when scoring is impossible (e.g. missing
 * API key, network error, unparseable response).
 *
 * Return shape: ['score' => int 0-100, 'explanation' => string (français)]
 */
interface ScoringDriverInterface
{
    /**
     * Score a discovery candidate against the given criteria.
     *
     * @param  array{domain?: string, title?: string, snippet?: string, url?: string, link?: string}  $candidate
     * @param  ProspectCriteria  $criteria
     * @return array{score: int, explanation: string}|null
     */
    public function score(array $candidate, ProspectCriteria $criteria): ?array;
}
