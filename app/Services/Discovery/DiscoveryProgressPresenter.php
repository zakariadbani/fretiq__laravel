<?php

namespace App\Services\Discovery;

use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use DateTimeInterface;

final class DiscoveryProgressPresenter
{
    /**
     * @return array<string, bool|int|string|null>
     */
    public function present(
        ProspectCriteria $criteria,
        ?DiscoveryRun $run,
        int $companiesTotal,
        bool $localDriver = false,
    ): array {
        $status = $run?->status;
        $searchesConsumed = $run ? (int) ($run->searches_consumed ?? 0) : 0;
        $searchesReserved = $run ? (int) ($run->searches_reserved ?? $run->credits_reserved ?? 0) : 0;
        $candidatesProcessed = $run ? (int) ($run->consumed ?? 0) : 0;
        $candidatesTotal = $run ? $this->candidateTotal($run) : 0;
        $terminal = in_array($status, ['completed', 'failed'], true);
        // A provider attempt is debited before its HTTP response arrives, so search
        // counters cannot prove collection has finished. The collection service
        // persists a run-scoped marker only after its terminal result is known.
        $collectionComplete = $run !== null && (
            $terminal
            || $localDriver
            || (int) data_get(
                $criteria->discovery_cursors,
                ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY,
                0,
            ) === (int) $run->getKey()
        );
        $candidatesTotalFinal = $run === null
            || $collectionComplete;

        return [
            'status' => $status,
            'companies_count' => $run ? (int) ($run->companies_count ?? 0) : 0,
            'contacts_count' => $run ? (int) ($run->contacts_count ?? 0) : 0,
            'successful_enrichments' => $run ? (int) ($run->successful_enrichments ?? 0) : 0,
            'successful_enrichments_target' => $run ? (int) ($run->successful_enrichments_target ?? 0) : 0,
            'contact_attempts_reserved' => $run ? (int) ($run->contact_credits_reserved ?? 0) : 0,
            'contact_attempts_consumed' => $run ? (int) ($run->contact_consumed ?? 0) : 0,
            'skipped_count' => $run ? (int) ($run->skipped_count ?? 0) : 0,
            'low_score_count' => $run ? (int) ($run->low_score_count ?? 0) : 0,
            'excluded_count' => $run ? (int) ($run->excluded_count ?? 0) : 0,
            'finished_at' => $this->iso8601($run?->finished_at),
            'companies_total' => $companiesTotal,
            'stale' => $run?->isStale() ?? false,
            'error' => $this->publicError($run),
            'run_id' => $run ? (int) $run->getKey() : null,
            'prospect_criteria_id' => (int) $criteria->getKey(),
            'phase' => $run
                ? $this->phase((string) $status, $collectionComplete)
                : 'idle',
            'searches_consumed' => $searchesConsumed,
            'searches_reserved' => $searchesReserved,
            'candidates_processed' => $candidatesProcessed,
            'candidates_total' => $candidatesTotal,
            'candidates_total_final' => $candidatesTotalFinal,
            'progress_percent' => $run
                ? $this->progressPercent(
                    (string) $status,
                    $candidatesProcessed,
                    $candidatesTotal,
                    $candidatesTotalFinal,
                )
                : null,
            'heartbeat_at' => $this->iso8601($run?->updated_at),
        ];
    }

    private function candidateTotal(DiscoveryRun $run): int
    {
        if (! is_array($run->candidates_snapshot)) {
            return 0;
        }

        return count(array_filter(
            $run->candidates_snapshot,
            fn ($candidate): bool => is_array($candidate) && ! empty($candidate['domain'])
        ));
    }

    private function phase(string $status, bool $collectionComplete): string
    {
        return match ($status) {
            'pending' => 'queued',
            'completed' => 'completed',
            'failed' => 'failed',
            'running' => $collectionComplete ? 'processing' : 'collecting',
            default => 'idle',
        };
    }

    private function progressPercent(string $status, int $processed, int $total, bool $totalFinal): ?int
    {
        if ($status === 'completed') {
            return 100;
        }

        if ($status !== 'running' || ! $totalFinal || $total <= 0) {
            return null;
        }

        return min(99, (int) floor(($processed / $total) * 100));
    }

    private function iso8601(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format(DateTimeInterface::ATOM)
            : null;
    }

    /**
     * Historical rows may contain raw infrastructure exceptions from older code.
     * Expose only messages produced deliberately for operators; everything else
     * gets the stable public fallback used by current jobs.
     */
    public function publicError(?DiscoveryRun $run): ?string
    {
        $error = trim((string) ($run?->error ?? ''));

        if ($error === '') {
            return null;
        }

        if (str_starts_with($error, 'Échec de mise en file :')) {
            return DiscoveryRun::DISPATCH_FAILURE_MESSAGE;
        }

        $curated = [
            DiscoveryRun::DISPATCH_FAILURE_MESSAGE,
            DiscoveryRun::UNEXPECTED_FAILURE_MESSAGE,
            'Critère introuvable ou inactif',
            'Run bloqué détecté par le terminalizer (worker indisponible ou job perdu)',
            'Exécution obsolète remplacée par une nouvelle réservation.',
            'La découverte d’entreprises ne peut pas démarrer : la clé API du fournisseur de recherche n’est pas configurée.',
            'La découverte d’entreprises ne peut pas démarrer : aucune requête ni aucun moteur de recherche n’est activé.',
            'Service de recherche momentanément indisponible (quota de découverte épuisé) — réessayez plus tard.',
        ];

        if (in_array($error, $curated, true)
            || preg_match(
                '/^Découverte incomplète après \d+ tentatives\. Vérifiez la disponibilité des services externes puis relancez\.$/u',
                $error,
            ) === 1
        ) {
            return $error;
        }

        return DiscoveryRun::UNEXPECTED_FAILURE_MESSAGE;
    }
}
