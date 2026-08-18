<?php

namespace App\Services\Prospecting;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Services\Discovery\DomainCanonicalizer;
use Illuminate\Support\Str;

final class ProspectReviewPresenter
{
    /** @var array<string, array{label:string,weight:int}> */
    private const DOMAIN_SOURCES = [
        'provided' => ['label' => 'Domaine fourni', 'weight' => 80],
        'finder' => ['label' => 'Recherche de domaine', 'weight' => 60],
        'google' => ['label' => 'Recherche web', 'weight' => 45],
        'maps' => ['label' => 'Cartographie', 'weight' => 35],
    ];

    /** @var list<string> */
    private const IDENTITY_STOP_WORDS = [
        'and', 'company', 'contact', 'corp', 'eurl', 'france', 'gmbh', 'group', 'groupe',
        'inc', 'international', 'llc', 'ltd', 'ma', 'maroc', 'morocco', 'officiel', 'official',
        'plc', 'sa', 'sarl', 'sas', 'site', 'the', 'web',
    ];

    public function __construct(private readonly DomainCanonicalizer $domains) {}

    /**
     * Single URL builder for the review workspace — the batch-hosted
     * "À vérifier" tab (a pane on the batch view page). 'tab' and 'batch' are
     * stripped from the query: the batch is implied by the route, and a GET
     * filter form replacing the query string must never re-inject a param
     * this builder deliberately left out. The URL always carries the
     * '#prospect_batch_review' pane fragment so a full page load (redirect,
     * bookmark, shared link) lands directly on the review tab instead of the
     * default Aperçu pane.
     *
     * @param array<string, mixed> $query
     */
    public function workspaceUrl(int $hostBatchId, array $query = [], bool $absolute = true): string
    {
        $filtered = array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
        unset($filtered['tab'], $filtered['batch']);

        return route('admin.prospect_batches.view', ['id' => $hostBatchId] + $filtered, $absolute).'#prospect_batch_review';
    }

    /** @return array{code:string,label:string,description:string,color:string,kind:string} */
    public function itemIssue(ProspectBatchItem $item): array
    {
        $candidate = $item->status === 'failed' ? ($item->error_code ?: $item->domain_reason) : ($item->domain_reason ?: $item->error_code);
        $candidate = $candidate === 'too_many_requests' ? 'usage_limit' : $candidate;
        $known = is_string($candidate) ? ($this->reasonOptions()[$candidate] ?? null) : null;

        return [
            'code' => $known === null ? 'unknown' : $candidate,
            'label' => $known['label'] ?? 'Vérification manuelle requise',
            'description' => $known['description'] ?? 'Cette entreprise nécessite une décision avant de poursuivre.',
            'color' => $known['color'] ?? 'secondary',
            'kind' => $item->status === 'failed' ? 'interrupted' : 'review',
        ];
    }

    /** @return array{status:string,terminal:bool,level:string,title:string,message:string,result:?string,next_step:?string} */
    public function retryOutcome(ProspectBatchItem $item): array
    {
        $status = in_array($item->status, ProspectBatchItem::STATUSES, true)
            ? $item->status
            : 'failed';

        if ($status === 'pending') {
            return [
                'status' => $status,
                'terminal' => false,
                'level' => 'primary',
                'title' => 'Relance en cours · '.$item->company_name,
                'message' => $item->company_name.' attend son traitement en arrière-plan.',
                'result' => null,
                'next_step' => 'Vous pouvez quitter cette page : le résultat s’affichera ici dès qu’il sera disponible.',
            ];
        }

        if ($status === 'processing') {
            return [
                'status' => $status,
                'terminal' => false,
                'level' => 'primary',
                'title' => 'Relance en cours · '.$item->company_name,
                'message' => $item->company_name.' est en cours de traitement.',
                'result' => null,
                'next_step' => 'Attendez la fin du traitement ; ne relancez pas cette entreprise une seconde fois.',
            ];
        }

        if (in_array($status, ['ready', 'promoted'], true)) {
            return [
                'status' => $status,
                'terminal' => true,
                'level' => 'success',
                'title' => 'Relance terminée · '.$item->company_name,
                'message' => 'Le traitement de '.$item->company_name.' est terminé.',
                'result' => null,
                'next_step' => 'Continuez avec l’entreprise suivante dans la file.',
            ];
        }

        if (in_array($status, ['review', 'failed'], true)) {
            $issue = $this->itemIssue($item);

            return [
                'status' => $status,
                'terminal' => true,
                'level' => $status === 'failed' ? 'danger' : 'warning',
                'title' => $issue['label'].' · '.$item->company_name,
                'message' => $item->company_name.' : '.$issue['description'],
                'result' => 'Le traitement reste interrompu ; aucune étape suivante n’a été lancée.',
                'next_step' => 'Ouvrez le détail pour vérifier le blocage et choisir l’action adaptée.',
            ];
        }

        return $this->skippedOutcome($item, $status);
    }

    /** @return array{status:string,terminal:bool,level:string,title:string,message:string,result:string,next_step:string} */
    private function skippedOutcome(ProspectBatchItem $item, string $status): array
    {
        $reason = (string) ($item->domain_reason ?: $item->error_code);
        $criterionName = $item->batch?->criteria?->name;
        $criterionLabel = is_string($criterionName) && $criterionName !== ''
            ? ' Le critère concerné est « '.$criterionName.' ».'
            : '';

        [$title, $message, $result, $nextStep] = match ($reason) {
            'criterion_inactive' => [
                'Relance non effectuée · '.$item->company_name,
                'Le critère est inactif.'.$criterionLabel,
                'Aucun score, enrichissement ni recherche de contacts n’a été lancé.',
                'Réactivez le critère puis relancez la découverte.',
            ],
            'same_criterion_rejected' => [
                'Entreprise déjà exclue · '.$item->company_name,
                'Cette entreprise avait déjà été rejetée pour ce même critère.',
                'Aucun nouveau score, enrichissement ni recherche de contacts n’a été lancé.',
                'Aucune action n’est requise, sauf si vous souhaitez réexaminer l’exclusion dans le lot.',
            ],
            'excluded_by_criteria' => [
                'Entreprise hors critère · '.$item->company_name,
                'Le score de cette entreprise n’atteint pas le seuil demandé.',
                'L’entreprise a été scorée puis exclue avant l’enrichissement et la recherche de contacts.',
                'Aucune action n’est requise. Ajustez le critère uniquement si le seuil doit changer.',
            ],
            'quota_exhausted' => [
                'Recherche non effectuée · '.$item->company_name,
                'Le quota de recherche disponible ne permettait pas de poursuivre.',
                'Le traitement s’est arrêté avant la collecte de nouvelles données.',
                'Vérifiez le quota de recherche, puis relancez si nécessaire.',
            ],
            'provider_unavailable' => [
                'Recherche interrompue · '.$item->company_name,
                'Le service externe nécessaire était indisponible.',
                'Le traitement n’a pas pu aller jusqu’à son résultat final.',
                'Réessayez lorsque le service de recherche est de nouveau disponible.',
            ],
            'reviewer_rejected', 'not_a_match', 'not_relevant', 'bad_data' => [
                'Entreprise exclue du lot · '.$item->company_name,
                'Une décision manuelle a exclu cette entreprise du lot.',
                'Aucun traitement supplémentaire n’a été lancé après cette décision.',
                'Aucune action n’est requise. Consultez le lot si cette exclusion doit être réexaminée.',
            ],
            default => [
                'Traitement arrêté · '.$item->company_name,
                'Cette entreprise a quitté le flux avant la fin du traitement.',
                'Aucun traitement supplémentaire n’est en cours pour cette entreprise.',
                'Consultez le lot pour vérifier la raison enregistrée avant toute nouvelle action.',
            ],
        };

        return [
            'status' => $status,
            'terminal' => true,
            'level' => 'warning',
            'title' => $title,
            'message' => $message,
            'result' => $result,
            'next_step' => $nextStep,
        ];
    }

    /**
     * @return array{
     *     issue:array{code:string,label:string,description:string,color:string,kind:string},
     *     checks:list<array{label:string,detail:string,tone:string,icon:string}>,
     *     attention_count:int,
     *     primary_candidate:?array{domain:string,selectable:bool,sources:list<string>,score:int,confidence:string,explanation:string},
     *     alternatives:list<array{domain:string,selectable:bool,sources:list<string>,score:int,confidence:string,explanation:string}>,
     *     primary_action:string,
     *     primary_label:string,
     *     requires_reissue_confirmation:bool,
     *     correct:string,
     *     missing:string,
     *     next:string,
     *     contact_collection_resume:bool,
     *     selected_domain:?string,
     *     imported_contacts_count:int,
     *     domains_read_only:bool,
     *     retry_cost_note:?string,
     *     retry_blocked:bool,
     *     retry_blocked_message:?string,
     *     retry_blocked_criteria_id:?int,
     *     retry_blocked_criteria_name:?string
     * }
     */
    public function companyDecision(ProspectBatchItem $item): array
    {
        $issue = $this->itemIssue($item);
        $candidates = $this->domainCandidates($item, $issue['code']);
        $primaryCandidate = null;

        foreach ($candidates as $candidate) {
            if ($candidate['selectable']) {
                $primaryCandidate = $candidate;
                break;
            }
        }

        $collision = in_array($issue['code'], ['registrable_domain_collision', 'recovered_registrable_collision'], true);
        $requiresReissueConfirmation = $item->error_code === 'provider_outcome_uncertain';
        $processing = is_array(data_get($item->source_metadata, 'processing')) ? data_get($item->source_metadata, 'processing') : [];
        $selectedDomain = is_string($item->selected_domain) && $item->selected_domain !== '' ? $item->selected_domain : null;
        $contactCollectionResume = $item->status === 'failed'
            && $selectedDomain !== null
            && ($processing['company_enrichment_done'] ?? false) === true;
        $enrichmentResume = $item->status === 'failed'
            && $selectedDomain !== null
            && ($processing['resolution_done'] ?? false) === true
            && ($processing['company_enrichment_done'] ?? false) !== true;
        $importedContactsCount = (int) ($item->getAttribute('imported_contacts_count') ?? 0);
        $contactSearchIncomplete = ! (($processing['domain_search_done'] ?? false) === true);
        $limitedRecovery = $contactCollectionResume
            && $contactSearchIncomplete
            && in_array($item->error_code, ['pagination_error', 'provider_call_not_replayable'], true);
        if ($contactCollectionResume || $enrichmentResume) {
            if (collect($candidates)->doesntContain(fn (array $candidate): bool => $candidate['domain'] === $selectedDomain)) {
                $candidates[] = [
                    'domain' => $selectedDomain,
                    'selectable' => false,
                    'sources' => ['Donnée locale'],
                    'score' => 0,
                    'confidence' => 'Domaine utilisé',
                    'explanation' => 'Domaine déjà utilisé pour les données collectées.',
                ];
            }
            $primaryCandidate = collect($candidates)->firstWhere('domain', $selectedDomain) ?? $primaryCandidate;
        }
        $alternatives = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $primaryCandidate === null || $candidate['domain'] !== $primaryCandidate['domain'],
        ));

        if ($collision) {
            $primaryAction = 'compare_collision';
            $primaryLabel = 'Comparer avant de décider';
        } elseif ($item->status === 'failed' || $requiresReissueConfirmation || $primaryCandidate === null) {
            $primaryAction = 'retry';
            $primaryLabel = $contactCollectionResume
                ? 'Relancer la recherche de contacts'
                : ($enrichmentResume ? 'Relancer l’enrichissement' : ($requiresReissueConfirmation ? 'Relancer avec confirmation' : 'Réessayer cette entreprise'));
        } else {
            $primaryAction = 'approve_domain';
            $primaryLabel = 'Confirmer '.$primaryCandidate['domain'];
        }

        $batch = $item->batch;
        $criteria = $batch?->criteria;
        $retryBlocked = $primaryAction === 'retry'
            && $batch?->prospect_criteria_id !== null
            && ! ($criteria?->is_active ?? false);
        $retryBlockedMessage = null;
        if ($retryBlocked) {
            $retryBlockedMessage = $criteria === null
                ? 'Le critère lié à ce lot n’est plus disponible. Sélectionnez un critère actif avant de relancer cette entreprise.'
                : 'Le critère « '.$criteria->name.' » est inactif. Réactivez-le avant de relancer cette entreprise.';
            $primaryLabel = 'Relance indisponible';
        }

        $checks = $this->companyChecks($item, $issue['code'], $primaryCandidate);
        [$correct, $missing, $next] = $this->decisionSummary($item, $issue['code'], $primaryAction);
        if ($contactCollectionResume) {
            $contactSummary = match ($importedContactsCount) {
                0 => null,
                1 => '1 contact déjà importé.',
                default => $importedContactsCount.' contacts déjà importés.',
            };
            $correct = 'Domaine '.$selectedDomain.' et enrichissement entreprise conservés'.($contactSummary !== null ? ' ; '.$contactSummary : '.');
            $missing = 'La collecte de contacts doit reprendre à partir du domaine déjà validé.';
            $next = $limitedRecovery
                ? 'Conserve les données déjà collectées et relance uniquement la recherche de contacts.'
                : 'Conserve les données déjà collectées avant de reprendre le traitement.';
        }
        if ($enrichmentResume) {
            $correct = 'Domaine '.$selectedDomain.' conservé.';
            $missing = 'L’enrichissement de l’entreprise doit reprendre à partir du domaine déjà validé.';
            $next = 'Conserve les données déjà collectées avant de reprendre l’enrichissement.';
        }
        if ($issue['code'] === 'usage_limit') {
            $next = 'La limite d’utilisation du fournisseur est atteinte. Vérifiez le quota avant une relance manuelle.';
        }

        return [
            'issue' => $issue,
            'checks' => $checks,
            'attention_count' => count(array_filter($checks, static fn (array $check): bool => in_array($check['tone'], ['warning', 'danger'], true))),
            'primary_candidate' => $primaryCandidate,
            'alternatives' => $alternatives,
            'primary_action' => $primaryAction,
            'primary_label' => $primaryLabel,
            'requires_reissue_confirmation' => $requiresReissueConfirmation,
            'correct' => $correct,
            'missing' => $missing,
            'next' => $next,
            'contact_collection_resume' => $contactCollectionResume,
            'selected_domain' => $selectedDomain,
            'imported_contacts_count' => $importedContactsCount,
            'domains_read_only' => $contactCollectionResume || $enrichmentResume,
            'retry_cost_note' => $limitedRecovery ? 'Jusqu’à 10 adresses · 0 à 1 unité' : null,
            'enrichment_resume' => $enrichmentResume,
            'retry_blocked' => $retryBlocked,
            'retry_blocked_message' => $retryBlockedMessage,
            'retry_blocked_criteria_id' => $criteria?->getKey() === null ? null : (int) $criteria->getKey(),
            'retry_blocked_criteria_name' => $criteria?->name,
        ];
    }

    /** @return array{label:string,description:string,color:string,icon:string} */
    public function batchNextState(ProspectBatch $batch, int $manualPending): array
    {
        if (in_array($batch->status, ['queued', 'running'], true)) {
            return ['label' => 'Reprise en cours', 'description' => 'Le traitement continue en arrière-plan.', 'color' => 'primary', 'icon' => 'bi-arrow-repeat'];
        }
        if ($batch->status === 'completed') {
            return ['label' => 'Lot terminé', 'description' => 'Toutes les étapes enregistrées sont terminées.', 'color' => 'success', 'icon' => 'bi-check-circle'];
        }
        if ($batch->status === 'failed') {
            return ['label' => 'Traitement en échec — consulter le lot', 'description' => 'Le détail du lot indique les prochaines vérifications.', 'color' => 'danger', 'icon' => 'bi-x-octagon'];
        }
        if ($manualPending > 0) {
            return ['label' => 'Votre décision est requise', 'description' => $manualPending.' décision(s) restent à traiter.', 'color' => 'warning', 'icon' => 'bi-person-check'];
        }

        return ['label' => 'Décisions terminées — consulter le lot', 'description' => 'Aucune décision manuelle ne reste sur ce lot.', 'color' => 'info', 'icon' => 'bi-box-arrow-up-right'];
    }

    /** @return array<string,array{label:string,description:string,color:string}> */
    public function reasonOptions(): array
    {
        return config('global.data.prospect_review_reasons', []);
    }

    /**
     * @return list<array{domain:string,selectable:bool,sources:list<string>,score:int,confidence:string,explanation:string}>
     */
    private function domainCandidates(ProspectBatchItem $item, string $issueCode): array
    {
        $metadata = is_array($item->source_metadata) ? $item->source_metadata : [];
        $resolution = is_array(data_get($metadata, 'resolution')) ? data_get($metadata, 'resolution') : [];
        $persistedDomains = [];

        foreach (array_values((array) $item->domain_alternatives) as $index => $value) {
            $canonical = $this->domains->canonicalize((string) $value);
            if ($canonical !== null) {
                $persistedDomains[] = ['canonical' => $canonical, 'index' => $index];
            }
        }

        $selectableHosts = array_fill_keys(array_values(array_map(
            static fn (array $entry): string => $entry['canonical']->host,
            array_filter($persistedDomains, static fn (array $entry): bool => ! $entry['canonical']->isPlatform),
        )), true);
        $candidates = [];
        $sequence = 0;

        foreach (self::DOMAIN_SOURCES as $key => $definition) {
            $rows = is_array($resolution[$key] ?? null) ? array_values($resolution[$key]) : [];
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $canonical = $this->domains->canonicalize((string) ($row['domain'] ?? ''));
                if ($canonical === null) {
                    continue;
                }

                $domain = $canonical->host;
                if (! isset($candidates[$domain])) {
                    $candidates[$domain] = $this->emptyCandidate(
                        $domain,
                        $canonical->registrableDomain,
                        isset($selectableHosts[$domain]),
                        $sequence++,
                    );
                }

                $sourceScore = $definition['weight'] + max(0, 20 - $index);
                if (($row['perfect_match'] ?? false) === true) {
                    $sourceScore += 5;
                }

                $name = $row['company_name'] ?? $row['name'] ?? null;
                $candidates[$domain]['_source_score'] = max($candidates[$domain]['_source_score'], $sourceScore);
                $candidates[$domain]['_name_score'] = max(
                    $candidates[$domain]['_name_score'],
                    $this->nameAffinity($item->company_name, $name),
                );
                $candidates[$domain]['_row_country_score'] = max(
                    $candidates[$domain]['_row_country_score'],
                    $this->rowCountryAffinity($item->country, $row['country'] ?? null),
                );
                if (! in_array($definition['label'], $candidates[$domain]['sources'], true)) {
                    $candidates[$domain]['sources'][] = $definition['label'];
                }
            }
        }

        foreach ($persistedDomains as $entry) {
            $canonical = $entry['canonical'];
            $index = $entry['index'];
            $domain = $canonical->host;
            if (! isset($candidates[$domain])) {
                $candidates[$domain] = $this->emptyCandidate(
                    $domain,
                    $canonical->registrableDomain,
                    ! $canonical->isPlatform,
                    $sequence++,
                );
                $candidates[$domain]['_source_score'] = max(1, 15 - $index);
                $candidates[$domain]['sources'][] = 'Donnée locale';
            } else {
                $candidates[$domain]['selectable'] = ! $canonical->isPlatform;
            }
        }

        foreach ($candidates as &$candidate) {
            $candidate['_domain_score'] = $this->domainAffinity($item->company_name, $candidate['_registrable_domain']);
            $candidate['_country_score'] = $this->domainCountryAffinity($item->country, $candidate['_registrable_domain']);
            $candidate['score'] = $candidate['_source_score']
                + $candidate['_name_score']
                + $candidate['_domain_score']
                + $candidate['_country_score']
                + $candidate['_row_country_score']
                + max(0, count($candidate['sources']) - 1) * 15;
            if (! $candidate['selectable']) {
                $candidate['score'] -= 1000;
            }
            $candidate['confidence'] = $this->candidateConfidence($candidate, $issueCode);
            $candidate['explanation'] = $this->candidateExplanation($candidate);
        }
        unset($candidate);

        uasort($candidates, static function (array $left, array $right): int {
            return [$right['selectable'], $right['score'], -$right['_first_seen'], $left['domain']]
                <=> [$left['selectable'], $left['score'], -$left['_first_seen'], $right['domain']];
        });

        return array_values(array_map(static fn (array $candidate): array => [
            'domain' => $candidate['domain'],
            'selectable' => $candidate['selectable'],
            'sources' => $candidate['sources'],
            'score' => $candidate['score'],
            'confidence' => $candidate['confidence'],
            'explanation' => $candidate['explanation'],
        ], $candidates));
    }

    /** @return array<string, mixed> */
    private function emptyCandidate(string $domain, string $registrableDomain, bool $selectable, int $firstSeen): array
    {
        return [
            'domain' => $domain,
            'selectable' => $selectable,
            'sources' => [],
            'score' => 0,
            'confidence' => '',
            'explanation' => '',
            '_registrable_domain' => $registrableDomain,
            '_source_score' => 0,
            '_name_score' => 0,
            '_domain_score' => 0,
            '_country_score' => 0,
            '_row_country_score' => 0,
            '_first_seen' => $firstSeen,
        ];
    }

    private function nameAffinity(string $expected, mixed $actual): int
    {
        if (! is_string($actual) || trim($actual) === '') {
            return 0;
        }

        $expectedName = $this->identityName($expected);
        $actualName = $this->identityName($actual);
        if ($expectedName === '' || $actualName === '') {
            return 0;
        }
        if ($expectedName === $actualName) {
            return 80;
        }
        if (str_starts_with($actualName.' ', $expectedName.' ') || str_starts_with($expectedName.' ', $actualName.' ')) {
            return 70;
        }

        $expectedTokens = $this->identityTokens($expectedName);
        $actualTokens = $this->identityTokens($actualName);
        if ($expectedTokens === [] || $actualTokens === []) {
            return 0;
        }

        $matches = 0;
        foreach ($expectedTokens as $expectedToken) {
            if ($this->containsAgreeingToken($actualTokens, $expectedToken)) {
                $matches++;
            }
        }

        return (int) round(70 * ($matches / count($expectedTokens)));
    }

    private function domainAffinity(string $companyName, string $registrableDomain): int
    {
        $label = explode('.', $registrableDomain)[0] ?? '';
        $expectedTokens = $this->identityTokens($companyName);
        $domainTokens = $this->identityTokens($label);
        if ($expectedTokens === [] || $domainTokens === []) {
            return 0;
        }

        $expectedCompact = implode('', $expectedTokens);
        $domainCompact = implode('', $domainTokens);
        if ($expectedCompact !== '' && $expectedCompact === $domainCompact) {
            return 60;
        }

        $matches = 0;
        foreach ($expectedTokens as $expectedToken) {
            if ($this->containsAgreeingToken($domainTokens, $expectedToken)) {
                $matches++;
            }
        }

        return min(80, $matches * 45);
    }

    private function rowCountryAffinity(?string $expected, mixed $actual): int
    {
        $expected = strtoupper(trim((string) $expected));
        $actual = strtoupper(trim((string) $actual));
        if ($expected === '' || $actual === '') {
            return 0;
        }

        return $expected === $actual ? 20 : -15;
    }

    private function domainCountryAffinity(?string $expected, string $registrableDomain): int
    {
        $expected = strtoupper(trim((string) $expected));
        if ($expected === '') {
            return 0;
        }

        $labels = explode('.', $registrableDomain);
        $tld = strtoupper((string) end($labels));
        if (strlen($tld) !== 2) {
            return 0;
        }
        if ($tld === 'UK') {
            $tld = 'GB';
        }

        return $tld === $expected ? 25 : -15;
    }

    /** @return list<string> */
    private function identityTokens(string $value): array
    {
        $normalized = $this->identityName($value);

        return array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, self::IDENTITY_STOP_WORDS, true),
        ));
    }

    private function identityName(mixed $value): string
    {
        $value = Str::lower(Str::ascii((string) $value));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private function tokensAgree(string $expected, string $actual): bool
    {
        if ($expected === $actual) {
            return true;
        }
        if (min(strlen($expected), strlen($actual)) >= 4
            && (str_starts_with($expected, $actual) || str_starts_with($actual, $expected))) {
            return true;
        }

        return min(strlen($expected), strlen($actual)) >= 6
            && abs(strlen($expected) - strlen($actual)) <= 1
            && levenshtein($expected, $actual) <= 1;
    }

    /** @param list<string> $tokens */
    private function containsAgreeingToken(array $tokens, string $expected): bool
    {
        foreach ($tokens as $token) {
            if ($this->tokensAgree($expected, $token)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $candidate */
    private function candidateConfidence(array $candidate, string $issueCode): string
    {
        if (! $candidate['selectable']) {
            return 'Plateforme écartée';
        }
        if (in_array($issueCode, ['registrable_domain_collision', 'recovered_registrable_collision'], true)) {
            return 'À comparer';
        }
        if ($candidate['score'] >= 170) {
            return in_array($issueCode, ['ambiguous_domain', 'domain_identity_conflict', 'platform_domain'], true)
                ? 'Confiance moyenne'
                : 'Confiance forte';
        }

        return $candidate['score'] >= 90 ? 'Confiance moyenne' : 'À vérifier';
    }

    /** @param array<string, mixed> $candidate */
    private function candidateExplanation(array $candidate): string
    {
        if (! $candidate['selectable']) {
            return 'Plateforme ou annuaire : ce domaine ne peut pas être choisi comme site officiel.';
        }
        if (count($candidate['sources']) > 1) {
            return 'Plusieurs sources indépendantes proposent ce domaine.';
        }
        if ($candidate['_name_score'] >= 60) {
            return 'Le nom du résultat correspond fortement au nom de l’entreprise.';
        }
        if ($candidate['_domain_score'] >= 45) {
            return 'Le domaine reprend un nom distinctif de l’entreprise.';
        }

        return 'Piste trouvée par '.implode(', ', $candidate['sources']).' ; vérifiez-la avant confirmation.';
    }

    /**
     * @param  array{domain:string,selectable:bool,sources:list<string>,score:int,confidence:string,explanation:string}|null  $primaryCandidate
     * @return list<array{label:string,detail:string,tone:string,icon:string}>
     */
    private function companyChecks(ProspectBatchItem $item, string $issueCode, ?array $primaryCandidate): array
    {
        $collision = in_array($issueCode, ['registrable_domain_collision', 'recovered_registrable_collision'], true);
        $identityConflict = $issueCode === 'domain_identity_conflict';
        $domainMissing = $issueCode === 'missing_domain' || $primaryCandidate === null;
        $domainInterrupted = $item->status === 'failed' && $primaryCandidate === null;

        $domainCheck = match (true) {
            $domainMissing && ! $domainInterrupted => ['Site officiel', 'Aucun site fiable', 'danger', 'bi-x-lg'],
            $domainInterrupted => ['Site officiel', 'Recherche interrompue', 'warning', 'bi-exclamation-lg'],
            default => ['Site officiel', $primaryCandidate['domain'], 'success', 'bi-check-lg'],
        };
        $identityCheck = match (true) {
            $collision => ['Identité', 'Domaine déjà associé', 'danger', 'bi-x-lg'],
            $identityConflict => ['Identité', 'Conflit détecté', 'danger', 'bi-x-lg'],
            $item->status === 'failed' && $primaryCandidate !== null => ['Identité', 'Domaine déjà trouvé', 'success', 'bi-check-lg'],
            $primaryCandidate !== null => ['Identité', 'À confirmer', 'warning', 'bi-exclamation-lg'],
            default => ['Identité', 'Non vérifiée', 'secondary', 'bi-dash'],
        };
        $importedContactsCount = (int) ($item->getAttribute('imported_contacts_count') ?? 0);
        $contactsCheck = match (true) {
            $importedContactsCount === 1 => ['Contacts importés', '1 contact importé', 'success', 'bi-check-lg'],
            $importedContactsCount > 1 => ['Contacts importés', $importedContactsCount.' contacts importés', 'success', 'bi-check-lg'],
            $item->status === 'failed' && $primaryCandidate !== null => ['Contacts importés', 'Recherche incomplète', 'warning', 'bi-exclamation-lg'],
            default => ['Contacts importés', 'Après validation du domaine', 'secondary', 'bi-dash'],
        };

        return array_map(static fn (array $check): array => [
            'label' => $check[0],
            'detail' => $check[1],
            'tone' => $check[2],
            'icon' => $check[3],
        ], [
            ['Données importées', 'Nom disponible', 'success', 'bi-check-lg'],
            $domainCheck,
            $identityCheck,
            $contactsCheck,
        ]);
    }

    /** @return array{string,string,string} */
    private function decisionSummary(ProspectBatchItem $item, string $issueCode, string $primaryAction): array
    {
        if ($item->status === 'failed') {
            return [
                'Les données déjà enregistrées',
                'Reprendre l’étape interrompue',
                'Fretiq reprendra uniquement cette entreprise à l’étape qui s’est arrêtée.',
            ];
        }

        return match ($issueCode) {
            'missing_domain' => ['Le nom de l’entreprise', 'Un site officiel fiable', 'Fretiq relancera uniquement la recherche de cette entreprise.'],
            'registrable_domain_collision', 'recovered_registrable_collision' => ['Le domaine a été trouvé', 'Comparer l’entreprise déjà associée', 'Aucune nouvelle entreprise ne sera créée tant que le conflit reste ouvert.'],
            'platform_domain' => ['Des résultats ont été trouvés', 'Écarter les plateformes et confirmer un site officiel', 'Après confirmation, Fretiq utilisera uniquement le domaine retenu.'],
            default => [
                'Des pistes de domaine ont été trouvées',
                'Confirmer l’identité du site officiel',
                $primaryAction === 'approve_domain'
                    ? 'Après confirmation, Fretiq utilisera uniquement ce domaine puis poursuivra le traitement.'
                    : 'Fretiq reprendra uniquement cette entreprise.',
            ],
        };
    }
}
