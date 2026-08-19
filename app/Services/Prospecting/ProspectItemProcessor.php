<?php

namespace App\Services\Prospecting;

use App\Models\Company;
use App\Models\ProspectBatch;
use App\Models\DiscoveryRun;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Models\Setting;
use App\Services\Discovery\CanonicalDomain;
use App\Services\Discovery\AutomaticEnrichmentDecision;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Services\Discovery\DiscoveredContactImportService;
use App\Services\Discovery\DomainCanonicalizer;
use App\Services\Scoring\LeadScoringService;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\CriteriaCompanyNoLongerEligibleException;
use App\Exceptions\EnrichmentInFlightException;
use App\Services\Providers\Hunter\HunterClient;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderExecution;
use App\Services\Providers\ProviderRequestException;
use App\Services\Providers\SerpApi\SerpApiClient;
use App\Support\EmailKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProspectItemProcessor
{
    private const PAGE_LIMIT = 100;

    private const FREE_PLAN_PAGE_LIMIT = 10;

    private const DOMAIN_SEARCH_EMAILS_PER_UNIT = 10;

    public function __construct(
        private readonly DomainCanonicalizer $domains,
        private readonly HunterClient $hunter,
        private readonly SerpApiClient $serpApi,
        private readonly ProviderCallLedger $ledger,
        private readonly ProspectBatchService $batches,
        private readonly DiscoveredContactImportService $contacts,
        private readonly HunterVerificationStatusNormalizer $verification,
        private readonly HunterCompanySizeNormalizer $companySizes,
        private readonly LeadScoringService $scoring,
        private readonly CompanyEnrichmentService $criteriaEnrichment,
    ) {}

    public function process(ProspectBatchItem $item): void
    {
        if (! $this->claim($item)) {
            return;
        }

        try {
            $item->refresh()->loadMissing('batch');
            if ($this->criterionIsInactive($item)) {
                $this->markSkipped($item, 'criterion_inactive');

                return;
            }
            $domain = $this->resolveDomain($item);
            if ($domain === null) {
                return;
            }

            try {
                $this->batches->linkCompanyForProcessing($item);
            } catch (\LogicException $exception) {
                if ($exception->getMessage() === 'prospect_item_promotion_lock_timeout') {
                    throw new ProviderRequestException(
                        'prospect_item_promotion_lock_timeout',
                        true,
                        retryAfterSeconds: 3,
                    );
                }
                if (! in_array($exception->getMessage(), [
                    'prospect_item_domain_not_promotable',
                    'prospect_item_domain_conflict',
                ], true)) {
                    throw $exception;
                }
                $this->markReview($item, 'domain_identity_conflict');

                return;
            }
            $item->refresh()->loadMissing('batch', 'company');

            if ($this->sameCriterionRejected($item)) {
                $this->markSkipped($item, 'same_criterion_rejected');

                return;
            }
            $this->assignCurrentCriterion($item);

            $decision = $this->scoreCompanyForItem($item, $domain);
            if ($decision !== null) {
                if ($this->companyAlreadyHasContacts($item)) {
                    $this->markReady($item);

                    return;
                }
                if (! $decision->allowsEnrichment()) {
                    $this->persistDecisionSkip($item, $decision);

                    return;
                }
                $batchKey = data_get($item->batch->source_options, 'criteria_enrichment_batch_id');
                if (is_string($batchKey) && DiscoveryRun::query()
                    ->where('enrichment_batch_id', $batchKey)
                    ->where('hunter_circuit_open', true)
                    ->exists()) {
                    Company::withRejected()->whereKey($item->company_id)->update([
                        'enrichment_status' => Company::ENRICHMENT_SKIPPED_PROVIDER_UNAVAILABLE,
                        'updated_at' => now(),
                    ]);
                    $this->markSkipped($item, 'provider_unavailable');

                    return;
                }
                try {
                    $this->enrichThroughCriteriaReservation($item);
                } catch (QuotaExhaustedException) {
                    Company::withRejected()->whereKey($item->company_id)->update([
                        'enrichment_status' => Company::ENRICHMENT_SKIPPED_BUDGET,
                        'updated_at' => now(),
                    ]);
                    $this->markSkipped($item, 'quota_exhausted');

                    return;
                } catch (CriteriaCompanyNoLongerEligibleException|EnrichmentInFlightException) {
                    $this->markReady($item);

                    return;
                }
                $this->markReady($item);

                return;
            }
            $this->enrichCompany($item, $domain);
            $this->searchDomainContacts($item, $domain);
            $this->findNamedContact($item, $domain);
            $this->markReady($item);
        } catch (ProviderRequestException $exception) {
            if (in_array($exception->safeCode, [
                'provider_call_in_progress',
                'provider_call_not_settleable',
                'provider_outcome_uncertain',
            ], true)) {
                $this->markReview($item, 'provider_outcome_uncertain');

                return;
            }

            if ($exception->retryable) {
                $this->markRetryPending($item, $exception->safeCode);
                throw $exception;
            }

            $this->markFailed($item, $exception->safeCode);
        } catch (Throwable $exception) {
            // Once a provider call is running, retrying this item could repeat a
            // remote request whose Contact/provenance transaction rolled back.
            // Make the item explicitly reviewable before preserving the original
            // exception for the queue's failure telemetry.
            if ($this->hasRunningProviderCall($item)) {
                $this->markReview($item, 'provider_outcome_uncertain');
            } else {
                // The claim itself or local company-linking failed before any
                // provider request was admitted. Return it to the normal retry
                // path rather than stranding it in a review state that cannot
                // authorize a provider replay.
                $this->markRetryPending($item, 'processor_unexpected_failure');
            }

            throw $exception;
        }
    }

    private function claim(ProspectBatchItem $item): bool
    {
        $claimed = ProspectBatchItem::query()
            ->whereKey($item->getKey())
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'processing',
                'processing_started_at' => now(),
                'processed_at' => null,
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    private function resolveDomain(ProspectBatchItem $item): ?CanonicalDomain
    {
        if (($selected = $this->domains->canonicalize((string) $item->selected_domain)) !== null) {
            if ($selected->isPlatform || $this->domainCollisionReason($item, $selected) !== null) {
                $this->markReview($item, $selected->isPlatform ? 'platform_domain' : 'registrable_domain_collision');

                return null;
            }

            return $selected;
        }

        $provided = trim((string) $item->provided_domain);
        if ($provided === '') {
            $reported = data_get($this->itemMetadata($item), 'reported_domain');
            $reportedDomain = $this->domains->canonicalize((string) $reported);
            if ($reportedDomain?->isPlatform) {
                $this->persistResolutionEvidence($item, 'provided', [[
                    'domain' => $reportedDomain->host,
                    'registrable_domain' => $reportedDomain->registrableDomain,
                    'platform' => true,
                ]]);
                $this->markReview($item, 'platform_domain', [$reportedDomain->host]);

                return null;
            }
        }
        if ($provided !== '') {
            $canonical = $this->domains->canonicalize($provided);
            if ($canonical !== null && $canonical->isPlatform) {
                $this->persistResolutionEvidence($item, 'provided', [[
                    'domain' => $canonical->host,
                    'registrable_domain' => $canonical->registrableDomain,
                    'platform' => true,
                ]]);
                $this->markReview($item, 'platform_domain', [$canonical->host]);

                return null;
            }
            if ($canonical !== null && ($collision = $this->domainCollisionReason($item, $canonical)) !== null) {
                $this->persistResolutionBlocker($item, $collision);
                $this->markReview($item, $collision, [$canonical->host]);

                return null;
            }
            if ($canonical !== null) {
                $this->selectDomain($item, $canonical, 'provided_domain');

                return $canonical;
            }
        }

        $finderRows = $this->domainFinder($item);
        $perfect = array_values(array_filter($finderRows, function (array $row) use ($item): bool {
            if (($row['perfect_match'] ?? false) !== true
                || ! $this->namesAgree($item->company_name, $row['company_name'] ?? null)
                || ! $this->countriesAgree(
                    $item->country,
                    $row['country'] ?? null,
                    $row['domain'] ?? null,
                )) {
                return false;
            }

            $canonical = $this->domains->canonicalize((string) ($row['domain'] ?? ''));

            return $canonical !== null && ! $canonical->isPlatform;
        }));

        if (count($perfect) === 1) {
            $canonical = $this->domains->canonicalize((string) $perfect[0]['domain']);
            if ($canonical !== null && ($collision = $this->domainCollisionReason($item, $canonical)) === null) {
                $this->selectDomain($item, $canonical, 'hunter_perfect_match');

                return $canonical;
            }
            if (isset($collision)) {
                $this->persistResolutionBlocker($item, $collision);
            }
        }

        $this->googleSearch($item);
        $this->googleMaps($item);

        $item->refresh();
        $metadata = $this->itemMetadata($item);
        $alternatives = $this->resolutionDomains($metadata);
        $blockers = array_values(array_filter((array) data_get($metadata, 'resolution.blockers', []), 'is_string'));
        $reason = in_array('registrable_domain_collision', $blockers, true)
            ? 'registrable_domain_collision'
            : (in_array('domain_identity_conflict', $blockers, true)
                ? 'domain_identity_conflict'
                : (in_array('platform_domain', $blockers, true)
                    ? 'platform_domain'
                    : ($alternatives === [] ? 'missing_domain' : 'ambiguous_domain')));

        $this->markReview($item, $reason, $alternatives);

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function domainFinder(ProspectBatchItem $item): array
    {
        $item->refresh();
        $metadata = $this->itemMetadata($item);
        if (data_get($metadata, 'processing.domain_finder_done') === true) {
            return $this->safeEvidenceRows(data_get($metadata, 'resolution.finder', []));
        }

        $context = $this->context($item, 'domain_finder', $item->normalized_name);
        try {
            $execution = $this->hunter->domainFinder(
                $context,
                $item->company_name,
                5,
                true,
            );
        } catch (ProviderRequestException $exception) {
            if ($exception->safeCode !== 'not_found') {
                throw $exception;
            }
            $this->recordEmptyOutcome($item, $context, 'hunter', 'domain_finder', function (ProspectBatchItem $locked): void {
                $metadata = $this->itemMetadata($locked);
                data_set($metadata, 'resolution.finder', []);
                data_set($metadata, 'processing.domain_finder_done', true);
                $locked->forceFill(['source_metadata' => $metadata])->save();
            });

            return [];
        }
        if ($execution->replayed) {
            return $this->replayedEvidence($item, 'domain_finder_done', 'finder');
        }

        $rows = $this->normalizeFinderRows($execution->response?->data ?? [], true);
        $alternatives = array_column($rows, 'domain');
        $blockers = [];
        foreach ($rows as $row) {
            $canonical = $this->domains->canonicalize((string) $row['domain']);
            if ($canonical?->isPlatform) {
                $blockers[] = 'platform_domain';
            } elseif ($canonical !== null && ($collision = $this->domainCollisionReason($item, $canonical)) !== null) {
                $blockers[] = $collision;
            }
        }

        $this->settleBusinessWrite(
            $item,
            $execution,
            count($rows),
            function (ProspectBatchItem $locked) use ($rows, $alternatives, $blockers): void {
                $metadata = $this->itemMetadata($locked);
                data_set($metadata, 'resolution.finder', $rows);
                data_set($metadata, 'resolution.blockers', $this->uniqueStrings(array_merge(
                    (array) data_get($metadata, 'resolution.blockers', []),
                    $blockers,
                )));
                data_set($metadata, 'processing.domain_finder_done', true);
                $locked->forceFill([
                    'domain_alternatives' => $this->uniqueStrings(array_merge(
                        (array) $locked->domain_alternatives,
                        $alternatives,
                    )),
                    'source_metadata' => $metadata,
                ])->save();
            },
        );

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function googleSearch(ProspectBatchItem $item): array
    {
        $item->refresh();
        $metadata = $this->itemMetadata($item);
        if (data_get($metadata, 'processing.google_done') === true) {
            return $this->safeEvidenceRows(data_get($metadata, 'resolution.google', []));
        }

        $query = implode(' ', array_filter([
            $item->company_name,
            $item->city,
            $item->country,
            'site officiel',
        ]));
        $parameters = ['hl' => 'fr'];
        if ($item->country !== null) {
            $parameters['gl'] = strtolower($item->country);
        }
        if ($item->city !== null) {
            $parameters['location'] = $item->city;
        }
        $execution = $this->serpApi->search(
            $this->context($item, 'google', $query, 'google'),
            'google',
            $query,
            0,
            $parameters,
        );
        if ($execution->replayed) {
            return $this->replayedEvidence($item, 'google_done', 'google');
        }

        $providerRows = (array) data_get($execution->response?->data ?? [], 'organic_results', []);
        $rows = $this->normalizeGoogleRows($providerRows);
        $this->settleResolutionRows($item, $execution, 'google', 'google_done', $rows, count($providerRows));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function googleMaps(ProspectBatchItem $item): array
    {
        $item->refresh();
        $metadata = $this->itemMetadata($item);
        if (data_get($metadata, 'processing.maps_done') === true) {
            return $this->safeEvidenceRows(data_get($metadata, 'resolution.maps', []));
        }

        $query = implode(' ', array_filter([$item->company_name, $item->city, $item->country]));
        $parameters = ['hl' => 'fr', 'type' => 'search'];
        if ($item->country !== null) {
            $parameters['gl'] = strtolower($item->country);
        }
        if ($item->city !== null) {
            $parameters['location'] = $item->city;
        }
        $execution = $this->serpApi->search(
            $this->context($item, 'google_maps', $query, 'google_maps'),
            'google_maps',
            $query,
            0,
            $parameters,
        );
        if ($execution->replayed) {
            return $this->replayedEvidence($item, 'maps_done', 'maps');
        }

        $providerRows = (array) data_get($execution->response?->data ?? [], 'local_results', []);
        $rows = $this->normalizeMapsRows($providerRows);
        $this->settleResolutionRows($item, $execution, 'maps', 'maps_done', $rows, count($providerRows));

        return $rows;
    }

    private function enrichCompany(ProspectBatchItem $item, CanonicalDomain $domain): void
    {
        $item->refresh();
        if (data_get($this->itemMetadata($item), 'processing.company_enrichment_done') === true) {
            return;
        }

        $context = $this->context($item, 'company_enrichment', $domain->host);
        try {
            $execution = $this->hunter->companyEnrichment($context, $domain->host);
        } catch (ProviderRequestException $exception) {
            if ($exception->safeCode !== 'not_found') {
                throw $exception;
            }
            $this->recordEmptyOutcome($item, $context, 'hunter', 'company_enrichment', function (ProspectBatchItem $locked): void {
                $metadata = $this->itemMetadata($locked);
                data_set($metadata, 'company', []);
                data_set($metadata, 'processing.company_enrichment_done', true);
                $locked->forceFill(['source_metadata' => $metadata])->save();
            });

            return;
        }
        if ($execution->replayed) {
            $this->assertReplayedState($item, 'company_enrichment_done');

            return;
        }

        $data = $execution->response?->data ?? [];
        $company = $this->normalizeCompanyData($data);
        $siteCandidates = $this->normalizeSiteEmails($data);
        $this->settleBusinessWrite(
            $item,
            $execution,
            $data === [] ? 0 : 1,
            function (ProspectBatchItem $locked) use ($company, $siteCandidates): void {
                $metadata = $this->itemMetadata($locked);
                data_set($metadata, 'company', $company);
                data_set($metadata, 'processing.company_enrichment_done', true);
                $locked->forceFill(['source_metadata' => $metadata])->save();
                if ($siteCandidates !== []) {
                    $this->contacts->import($locked->company, $siteCandidates, $locked);
                }
            },
            consumedUnits: $data === [] ? 0 : $this->hunterUnits('company_enrichment'),
        );
    }

    private function scoreCompanyForItem(ProspectBatchItem $item, CanonicalDomain $domain): ?AutomaticEnrichmentDecision
    {
        $item->refresh()->loadMissing('batch', 'company');
        if ($item->company_id === null || $item->batch?->prospect_criteria_id === null) {
            return null;
        }
        if ($item->batch === null || $item->batch->criteria === null) {
            return null;
        }
        $autoScoring = (bool) Setting::get('decouverte.auto_scoring', true);
        if (data_get($this->itemMetadata($item), 'processing.company_scoring_done') === true) {
            return $this->automaticEnrichmentDecision($item, $autoScoring);
        }

        if (! $autoScoring) {
            return $this->automaticEnrichmentDecision($item, false);
        }

        $result = $this->scoring->score([
            'domain' => $domain->host,
            'url' => "https://{$domain->host}",
            'title' => (string) $item->company_name,
        ], $item->batch->criteria, 20);
        $score = (int) ($result['score'] ?? 0);
        $explanation = (string) ($result['explanation'] ?? '');
        $exclude = ($result['exclude'] ?? false) === true;

        DB::transaction(function () use ($item, $score, $explanation, $exclude): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if ($locked->status !== 'processing') {
                return;
            }
            if (data_get($this->itemMetadata($locked), 'processing.company_scoring_done') === true) {
                return;
            }

            $company = Company::withRejected()
                ->lockForUpdate()
                ->find($locked->company_id);
            if ($company === null) {
                return;
            }
            $company->forceFill([
                'ai_score' => $score,
                'ai_explanation' => $explanation,
                'qualification_status' => $exclude
                    ? 'rejected'
                    : ($company->relationship !== 'client' && $company->qualification_status === 'rejected'
                        ? 'pending'
                        : $company->qualification_status),
            ])->save();

            $metadata = $this->itemMetadata($locked);
            data_set($metadata, 'processing.company_scoring_done', true);
            data_set($metadata, 'processing.company_scoring_score', $score);
            data_set($metadata, 'processing.company_scoring_exclude', $exclude);
            $locked->forceFill(['source_metadata' => $metadata])->save();
        });

        $item->refresh()->loadMissing('batch', 'company');

        return $this->automaticEnrichmentDecision($item, true);
    }

    private function automaticEnrichmentDecision(ProspectBatchItem $item, bool $autoScoring): AutomaticEnrichmentDecision
    {
        $metadata = $this->itemMetadata($item);
        $company = Company::withRejected()->findOrFail($item->company_id);

        return AutomaticEnrichmentDecision::for(
            $item->batch->criteria,
            $autoScoring,
            $autoScoring ? (int) data_get($metadata, 'processing.company_scoring_score', $company->ai_score) : null,
            $autoScoring && data_get($metadata, 'processing.company_scoring_exclude') === true,
            $company->enrichment_status,
        );
    }

    private function criterionIsInactive(ProspectBatchItem $item): bool
    {
        $item->loadMissing('batch.criteria');

        return $item->batch?->prospect_criteria_id !== null && $item->batch?->criteria?->is_active !== true;
    }

    private function sameCriterionRejected(ProspectBatchItem $item): bool
    {
        $company = Company::withRejected()->find($item->company_id);

        return $item->batch?->prospect_criteria_id !== null
            && $company?->qualification_status === 'rejected'
            && (int) $company->criteria_id === (int) $item->batch->prospect_criteria_id;
    }

    private function assignCurrentCriterion(ProspectBatchItem $item): void
    {
        if ($item->batch?->prospect_criteria_id === null || $item->company_id === null) {
            return;
        }

        Company::withRejected()->whereKey($item->company_id)->update([
            'criteria_id' => $item->batch->prospect_criteria_id,
            'updated_at' => now(),
        ]);
        $item->refresh()->loadMissing('batch', 'company');
    }

    private function companyAlreadyHasContacts(ProspectBatchItem $item): bool
    {
        return $item->batch?->prospect_criteria_id !== null
            && $item->company_id !== null
            && Company::withRejected()->find($item->company_id)?->contacts()->exists() === true;
    }

    private function enrichThroughCriteriaReservation(ProspectBatchItem $item): void
    {
        $criteria = $item->batch?->criteria;
        if ($criteria === null) {
            return;
        }
        $batchId = DB::transaction(function () use ($item): string {
            $batch = ProspectBatch::query()->lockForUpdate()->findOrFail($item->prospect_batch_id);
            $options = is_array($batch->source_options) ? $batch->source_options : [];
            $id = $options['criteria_enrichment_batch_id'] ?? null;
            if (! is_string($id) || ! Str::isUuid($id)) {
                $id = (string) Str::uuid();
                $options['criteria_enrichment_batch_id'] = $id;
                $batch->forceFill(['source_options' => $options])->save();
            }

            return $id;
        });
        $attempts = max(1, min(20, $item->batch->items()->count()));
        $target = $criteria->contact_limit === null ? $attempts : max(1, min(20, (int) $criteria->contact_limit));
        $this->criteriaEnrichment->enrichForCriteria(
            (int) $item->company_id, $criteria, $batchId, $attempts, $target, $item,
            $this->context($item, 'domain_search', (string) $item->selected_domain),
        );
    }

    private function persistDecisionSkip(ProspectBatchItem $item, AutomaticEnrichmentDecision $decision): void
    {
        DB::transaction(function () use ($item, $decision): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if ($locked->status !== 'processing') {
                return;
            }
            $company = Company::withRejected()->lockForUpdate()->find($locked->company_id);
            if ($company === null) {
                return;
            }
            if ($decision->skipStatus !== null) {
                $company->forceFill(['enrichment_status' => $decision->skipStatus])->save();
            }
        });
        if (data_get($this->itemMetadata($item), 'processing.company_scoring_exclude') === true) {
            $this->markSkipped($item, 'excluded_by_criteria');

            return;
        }
        $this->markReady($item);
    }

    private function searchDomainContacts(ProspectBatchItem $item, CanonicalDomain $domain): void
    {
        $filters = $this->domainSearchFilters($item->batch->quality_settings ?? []);
        $filtersHash = hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR));
        $maxResults = $this->batches->domainSearchMaxResults($item->batch);

        while (true) {
            $item->refresh();
            $metadata = $this->itemMetadata($item);
            if (data_get($metadata, 'processing.domain_search_done') === true) {
                return;
            }
            $limitCap = data_get($metadata, 'processing.domain_search_limit_cap') === self::FREE_PLAN_PAGE_LIMIT
                ? self::FREE_PLAN_PAGE_LIMIT
                : self::PAGE_LIMIT;
            $effectiveMaxResults = min($maxResults, $limitCap === self::FREE_PLAN_PAGE_LIMIT
                ? self::FREE_PLAN_PAGE_LIMIT
                : $maxResults);
            $offset = max(0, min(10_000, (int) data_get($metadata, 'processing.domain_search_offset', 0)));
            if ($offset >= $effectiveMaxResults) {
                $this->markDomainSearchDone($item, $offset);

                return;
            }

            $limit = min($limitCap, $effectiveMaxResults - $offset);
            $context = $this->context(
                $item,
                'domain_search',
                "{$domain->host}|{$filtersHash}|{$offset}|{$limit}",
                reservedUnits: $this->domainSearchUnits($limit),
            );
            if ($this->hasFailedFirstPagePaginationCall($context, $offset, $limit)) {
                $this->capDomainSearchAtFreePlanLimit($item);

                continue;
            }
            try {
                $execution = $this->hunter->domainSearch(
                    $context,
                    $domain->host,
                    $filters,
                    $limit,
                    $offset,
                );
            } catch (ProviderRequestException $exception) {
                if ($exception->safeCode === 'pagination_error'
                    && $offset === 0
                    && $limit > self::FREE_PLAN_PAGE_LIMIT) {
                    $this->capDomainSearchAtFreePlanLimit($item);

                    continue;
                }
                if ($exception->safeCode !== 'not_found') {
                    throw $exception;
                }
                $this->recordEmptyOutcome($item, $context, 'hunter', 'domain_search', function (ProspectBatchItem $locked) use ($offset): void {
                    $metadata = $this->itemMetadata($locked);
                    data_set($metadata, 'processing.domain_search_offset', $offset);
                    data_set($metadata, 'processing.domain_search_done', true);
                    $locked->forceFill(['source_metadata' => $metadata])->save();
                });

                return;
            }
            if ($execution->replayed) {
                $item->refresh();
                $next = (int) data_get($this->itemMetadata($item), 'processing.domain_search_offset', $offset);
                $done = data_get($this->itemMetadata($item), 'processing.domain_search_done') === true;
                if (! $done && $next <= $offset) {
                    throw new ProviderRequestException('provider_outcome_uncertain', false);
                }

                continue;
            }

            $data = $execution->response?->data ?? [];
            $providerRows = is_array($data['emails'] ?? null)
                ? array_slice(array_values($data['emails']), 0, $limit)
                : [];
            $candidates = $this->normalizeHunterEmails($providerRows, 'hunter_domain_search', 'domain_search');
            $resultTotal = $this->boundedInteger($execution->response?->meta['results'] ?? null, 0, 10_000);
            $returned = count($providerRows);
            $nextOffset = min($effectiveMaxResults, $offset + $limit);
            $done = $returned < $limit
                || ($resultTotal !== null && $nextOffset >= $resultTotal)
                || $nextOffset >= $effectiveMaxResults;

            $this->settleBusinessWrite(
                $item,
                $execution,
                $returned,
                function (ProspectBatchItem $locked) use ($candidates, $nextOffset, $done): void {
                    if ($candidates !== []) {
                        $this->contacts->import($locked->company, $candidates, $locked);
                    }
                    $metadata = $this->itemMetadata($locked);
                    data_set($metadata, 'processing.domain_search_offset', $nextOffset);
                    data_set($metadata, 'processing.domain_search_done', $done);
                    $locked->forceFill(['source_metadata' => $metadata])->save();
                },
                ['offset' => $offset, 'limit' => $limit, 'filters_hash' => $filtersHash],
                $this->domainSearchUnits($returned),
            );

            if ($done) {
                return;
            }
        }
    }

    private function findNamedContact(ProspectBatchItem $item, CanonicalDomain $domain): void
    {
        $item->refresh();
        $metadata = $this->itemMetadata($item);
        $fullName = $this->concreteFullName($metadata['full_name'] ?? null);
        if ($fullName === null || data_get($metadata, 'processing.email_finder_done') === true) {
            return;
        }

        $context = $this->context($item, 'email_finder', "{$domain->host}|{$fullName}");
        try {
            $execution = $this->hunter->emailFinder($context, $domain->host, $fullName);
        } catch (ProviderRequestException $exception) {
            if ($exception->safeCode !== 'not_found') {
                throw $exception;
            }
            $this->recordEmptyOutcome($item, $context, 'hunter', 'email_finder', function (ProspectBatchItem $locked): void {
                $metadata = $this->itemMetadata($locked);
                data_set($metadata, 'processing.email_finder_done', true);
                $locked->forceFill(['source_metadata' => $metadata])->save();
            });

            return;
        }
        if ($execution->replayed) {
            $this->assertReplayedState($item, 'email_finder_done');

            return;
        }

        $data = $execution->response?->data ?? [];
        $candidates = $this->normalizeHunterEmails(
            $data === [] ? [] : [$data],
            'hunter_email_finder',
            'email_finder',
        );
        $this->settleBusinessWrite(
            $item,
            $execution,
            count($candidates),
            function (ProspectBatchItem $locked) use ($candidates): void {
                if ($candidates !== []) {
                    $this->contacts->import($locked->company, $candidates, $locked);
                }
                $metadata = $this->itemMetadata($locked);
                data_set($metadata, 'processing.email_finder_done', true);
                $locked->forceFill(['source_metadata' => $metadata])->save();
            },
            consumedUnits: $candidates === [] ? 0 : $this->hunterUnits('email_finder'),
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function settleResolutionRows(
        ProspectBatchItem $item,
        ProviderExecution $execution,
        string $evidenceKey,
        string $stateKey,
        array $rows,
        int $providerCount,
    ): void {
        $domains = array_values(array_filter(array_column($rows, 'domain'), 'is_string'));
        $blockers = [];
        foreach ($domains as $candidate) {
            $canonical = $this->domains->canonicalize($candidate);
            if ($canonical?->isPlatform) {
                $blockers[] = 'platform_domain';
            } elseif ($canonical !== null && ($collision = $this->domainCollisionReason($item, $canonical)) !== null) {
                $blockers[] = $collision;
            }
        }

        $this->settleBusinessWrite(
            $item,
            $execution,
            $providerCount,
            function (ProspectBatchItem $locked) use ($evidenceKey, $stateKey, $rows, $domains, $blockers): void {
                $metadata = $this->itemMetadata($locked);
                data_set($metadata, "resolution.{$evidenceKey}", $rows);
                data_set($metadata, 'resolution.blockers', $this->uniqueStrings(array_merge(
                    (array) data_get($metadata, 'resolution.blockers', []),
                    $blockers,
                )));
                data_set($metadata, "processing.{$stateKey}", true);
                $locked->forceFill([
                    'domain_alternatives' => $this->uniqueStrings(array_merge(
                        (array) $locked->domain_alternatives,
                        $domains,
                    )),
                    'source_metadata' => $metadata,
                ])->save();
            },
        );
    }

    /** @param callable(ProspectBatchItem):void $writer @param array<string, mixed> $metadata */
    private function settleBusinessWrite(
        ProspectBatchItem $item,
        ProviderExecution $execution,
        int $resultCount,
        callable $writer,
        array $metadata = [],
        ?float $consumedUnits = null,
    ): void {
        if ($execution->response === null) {
            throw new ProviderRequestException('provider_outcome_uncertain', false);
        }
        $settledUnits = (float) $execution->call->reserved_units === 0.0
            ? 0.0
            : ($consumedUnits ?? (float) $execution->call->reserved_units);

        DB::transaction(function () use ($item, $execution, $resultCount, $writer, $metadata, $settledUnits): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if ($locked->status === 'processing') {
                $locked->loadMissing('batch');
                $writer($locked);
            }
            $this->ledger->settle(
                $execution,
                $resultCount,
                $settledUnits,
                $metadata,
            );
        });

        $item->refresh();
    }

    /** @param callable(ProspectBatchItem):void $writer */
    private function recordEmptyOutcome(
        ProspectBatchItem $item,
        ProviderCallContext $context,
        string $provider,
        string $operation,
        callable $writer,
    ): void {
        DB::transaction(function () use ($item, $context, $provider, $operation, $writer): void {
            $call = ProviderCall::query()
                ->where('provider', $provider)
                ->where('operation', $operation)
                ->where('idempotency_key', $context->idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($call === null || $call->status !== 'failed' || $call->http_status !== 404
                || data_get($call->metadata, 'error_code') !== 'not_found') {
                throw new ProviderRequestException('provider_outcome_uncertain', false);
            }

            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if ($locked->status === 'processing') {
                $locked->loadMissing('batch');
                $writer($locked);
            }
            $call->forceFill([
                'status' => 'failed',
                'result_count' => 0,
                'consumed_units' => 0,
                'retry_at' => null,
                'finished_at' => $call->finished_at ?? now(),
            ])->save();
        });
        $item->refresh();
    }

    private function selectDomain(ProspectBatchItem $item, CanonicalDomain $domain, string $reason): void
    {
        DB::transaction(function () use ($item, $domain, $reason): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if ($locked->status !== 'processing') {
                return;
            }
            $metadata = $this->itemMetadata($locked);
            data_set($metadata, 'processing.resolution_done', true);
            $locked->forceFill([
                'selected_domain' => $domain->host,
                'registrable_domain' => $domain->registrableDomain,
                'domain_alternatives' => $this->uniqueStrings(array_merge(
                    (array) $locked->domain_alternatives,
                    [$domain->host],
                )),
                'domain_reason' => $reason,
                'source_metadata' => $metadata,
            ])->save();
        });
        $item->refresh();
    }

    /** @param list<string> $alternatives */
    private function markReview(ProspectBatchItem $item, string $reason, array $alternatives = []): void
    {
        DB::transaction(function () use ($item, $reason, $alternatives): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if (! in_array($locked->status, ['processing', 'failed'], true)) {
                return;
            }
            $locked->forceFill([
                'status' => 'review',
                'domain_reason' => $reason,
                'domain_alternatives' => $this->uniqueStrings(array_merge(
                    (array) $locked->domain_alternatives,
                    $alternatives,
                )),
                'error_code' => $reason === 'provider_outcome_uncertain' ? $reason : null,
                'error_message' => null,
                'processed_at' => now(),
            ])->save();
        });
        $this->batches->refreshCounters($item->batch);
    }

    private function markFailed(ProspectBatchItem $item, string $safeCode): void
    {
        $safeCode = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $safeCode) === 1
            ? $safeCode
            : 'provider_request_failed';
        ProspectBatchItem::query()
            ->whereKey($item->getKey())
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'error_code' => $safeCode,
                'error_message' => null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        $this->batches->refreshCounters($item->batch);
    }

    private function markRetryPending(ProspectBatchItem $item, string $safeCode): void
    {
        $safeCode = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $safeCode) === 1 ? $safeCode : 'provider_request_failed';
        ProspectBatchItem::query()->whereKey($item->getKey())->where('status', 'processing')->update([
            'status' => 'pending', 'error_code' => $safeCode, 'error_message' => null,
            'processed_at' => null, 'updated_at' => now(),
        ]);
        $this->batches->refreshCounters($item->batch);
    }

    private function hasRunningProviderCall(ProspectBatchItem $item): bool
    {
        return ProviderCall::query()
            ->where('prospect_batch_item_id', $item->getKey())
            ->where('status', 'running')
            ->exists();
    }

    private function markReady(ProspectBatchItem $item): void
    {
        ProspectBatchItem::query()
            ->whereKey($item->getKey())
            ->where('status', 'processing')
            ->update([
                'status' => 'ready',
                'error_code' => null,
                'error_message' => null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        $this->batches->refreshCounters($item->batch);
        $item->refresh();
    }

    private function markSkipped(ProspectBatchItem $item, string $reason): void
    {
        ProspectBatchItem::query()->whereKey($item->getKey())->where('status', 'processing')->update([
            'status' => 'skipped', 'domain_reason' => $reason, 'error_code' => null,
            'error_message' => null, 'processed_at' => now(), 'updated_at' => now(),
        ]);
        $this->batches->refreshCounters($item->batch);
        $item->refresh();
    }

    private function markDomainSearchDone(ProspectBatchItem $item, int $offset): void
    {
        DB::transaction(function () use ($item, $offset): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $metadata = $this->itemMetadata($locked);
            data_set($metadata, 'processing.domain_search_offset', $offset);
            data_set($metadata, 'processing.domain_search_done', true);
            $locked->forceFill(['source_metadata' => $metadata])->save();
        });
    }

    private function hasFailedFirstPagePaginationCall(ProviderCallContext $context, int $offset, int $limit): bool
    {
        if ($offset !== 0 || $limit <= self::FREE_PLAN_PAGE_LIMIT) {
            return false;
        }

        $call = ProviderCall::query()
            ->where('provider', 'hunter')
            ->where('operation', 'domain_search')
            ->where('idempotency_key', $context->idempotencyKey)
            ->where('prospect_batch_item_id', $context->itemId)
            ->first(['status', 'metadata']);

        return $call?->status === 'failed'
            && data_get($call->metadata, 'error_code') === 'pagination_error';
    }

    private function capDomainSearchAtFreePlanLimit(ProspectBatchItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            if ($locked->status !== 'processing') {
                return;
            }

            $metadata = $this->itemMetadata($locked);
            data_set($metadata, 'processing.domain_search_limit_cap', self::FREE_PLAN_PAGE_LIMIT);
            $locked->forceFill(['source_metadata' => $metadata])->save();
        });
        $item->refresh();
    }

    private function persistResolutionEvidence(ProspectBatchItem $item, string $key, array $rows): void
    {
        DB::transaction(function () use ($item, $key, $rows): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $metadata = $this->itemMetadata($locked);
            data_set($metadata, "resolution.{$key}", $rows);
            $locked->forceFill(['source_metadata' => $metadata])->save();
        });
    }

    private function persistResolutionBlocker(ProspectBatchItem $item, string $reason): void
    {
        DB::transaction(function () use ($item, $reason): void {
            $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $metadata = $this->itemMetadata($locked);
            data_set($metadata, 'resolution.blockers', $this->uniqueStrings(array_merge(
                (array) data_get($metadata, 'resolution.blockers', []),
                [$reason],
            )));
            $locked->forceFill(['source_metadata' => $metadata])->save();
        });
    }

    private function domainCollisionReason(ProspectBatchItem $item, CanonicalDomain $candidate): ?string
    {
        $companies = Company::withRejected()
            ->where(function ($query) use ($candidate): void {
                $query->where('registrable_domain', $candidate->registrableDomain)
                    ->orWhere('domain', $candidate->registrableDomain)
                    ->orWhere('domain', 'like', '%.'.$candidate->registrableDomain);
            })
            ->get(['id', 'domain', 'registrable_domain', 'name']);

        foreach ($companies as $company) {
            if ($item->company_id !== null && (int) $company->getKey() === (int) $item->company_id) {
                continue;
            }
            $existing = $this->domains->canonicalize((string) $company->domain);
            if ($existing === null || $existing->registrableDomain !== $candidate->registrableDomain) {
                continue;
            }
            if ($existing->host !== $candidate->host) {
                return 'registrable_domain_collision';
            }
            if (! $this->namesAgree($item->company_name, $company->name)) {
                return 'domain_identity_conflict';
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeFinderRows(array $data, bool $requestedPerfectMatch): array
    {
        $providerRows = array_is_list($data)
            ? $data
            : (is_array($data['matches'] ?? null) ? $data['matches'] : ($data === [] ? [] : [$data]));
        $rows = [];
        foreach (array_slice($providerRows, 0, 10) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $canonical = $this->domains->canonicalize((string) ($row['domain'] ?? ''));
            if ($canonical === null) {
                continue;
            }
            $country = strtoupper(trim((string) ($row['country'] ?? $row['country_code'] ?? data_get($row, 'geo.countryCode', ''))));
            $rows[] = [
                'domain' => $canonical->host,
                'registrable_domain' => $canonical->registrableDomain,
                'company_name' => $this->safeText($row['company_name'] ?? $row['organization'] ?? $row['name'] ?? null, 255),
                'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
                'perfect_match' => $requestedPerfectMatch
                    && (! array_key_exists('perfect_match', $row) || $row['perfect_match'] === true),
                'match_mode' => $requestedPerfectMatch ? 'perfect' : null,
                'platform' => $canonical->isPlatform,
            ];
        }

        return $rows;
    }

    /** @param list<mixed> $providerRows @return list<array<string, mixed>> */
    private function normalizeGoogleRows(array $providerRows): array
    {
        $rows = [];
        foreach (array_slice($providerRows, 0, 10) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $canonical = $this->domains->canonicalize((string) ($row['link'] ?? $row['website'] ?? ''));
            if ($canonical === null) {
                continue;
            }
            $rows[] = [
                'name' => $this->safeText($row['title'] ?? null, 255),
                'domain' => $canonical->host,
                'registrable_domain' => $canonical->registrableDomain,
                'engine' => 'google',
                'platform' => $canonical->isPlatform,
            ];
        }

        return $rows;
    }

    /** @param list<mixed> $providerRows @return list<array<string, mixed>> */
    private function normalizeMapsRows(array $providerRows): array
    {
        $rows = [];
        foreach (array_slice($providerRows, 0, 20) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $canonical = $this->domains->canonicalize((string) ($row['website'] ?? ''));
            $providerKey = $this->safeIdentifier($row['place_id'] ?? $row['data_id'] ?? null);
            $name = $this->safeText($row['title'] ?? $row['name'] ?? null, 255);
            $address = $this->safeText($row['address'] ?? null, 500);
            $phone = $this->safeText($row['phone'] ?? null, 50);
            if ($canonical === null && $name === null && $address === null && $phone === null && $providerKey === null) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'address' => $address,
                'phone' => $phone,
                'provider_key' => $providerKey,
                'engine' => 'google_maps',
                'domain' => $canonical?->host,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function normalizeCompanyData(array $data): array
    {
        $country = strtoupper(trim((string) data_get($data, 'geo.countryCode', $data['country'] ?? '')));

        return array_filter([
            'name' => $this->safeText($data['name'] ?? $data['organization'] ?? null, 255),
            'sector' => $this->safeText(data_get($data, 'category.industry', $data['industry'] ?? null), 100),
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null,
            'estimated_size' => $this->companySizes->normalize(data_get($data, 'metrics.employeesRange', $data['headcount'] ?? null)),
            'phone' => $this->safeText($data['phone'] ?? null, 50),
            'city' => $this->safeText(data_get($data, 'geo.city', $data['city'] ?? null), 120),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeSiteEmails(array $data): array
    {
        $values = data_get($data, 'site.emailAddresses', []);
        if (! is_array($values)) {
            return [];
        }

        $rows = [];
        foreach ($values as $value) {
            $email = is_array($value) ? ($value['value'] ?? $value['email'] ?? null) : $value;
            $email = strtolower(trim((string) $email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
                continue;
            }
            $rows[] = [
                'email' => $email,
                'source' => 'hunter_company_enrichment',
                'email_kind' => EmailKind::classify($email),
                'metadata' => ['origin' => 'company_site', 'provider' => 'hunter'],
            ];
        }

        return $rows;
    }

    /** @param list<mixed> $providerRows @return list<array<string, mixed>> */
    private function normalizeHunterEmails(array $providerRows, string $source, string $origin): array
    {
        $rows = [];
        foreach ($providerRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['value'] ?? $row['email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
                continue;
            }
            $first = $this->safeText($row['first_name'] ?? null, 120);
            $last = $this->safeText($row['last_name'] ?? null, 120);
            $name = trim(implode(' ', array_filter([$first, $last])));
            $status = $this->verification->normalize($row);
            $rows[] = [
                'email' => $email,
                'name' => $name === '' ? null : $name,
                'position' => $this->safeText($row['position'] ?? null, 120),
                'phone' => $this->safeText($row['phone_number'] ?? $row['phone'] ?? null, 50),
                'source' => $source,
                'email_kind' => EmailKind::classify($email),
                'verification_status' => $status,
                'verification_checked_at' => $status === null ? null : $this->verification->checkedAt($row),
                'verification_source' => $status === null ? null : 'hunter',
                'metadata' => array_filter([
                    'origin' => $origin,
                    'provider' => 'hunter',
                    'confidence' => $this->boundedInteger($row['confidence'] ?? null, 0, 100),
                    'department' => $this->safeToken($row['department'] ?? null),
                    'seniority' => $this->safeToken($row['seniority'] ?? null),
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function domainSearchFilters(array $settings): array
    {
        $source = is_array($settings['domain_search_filters'] ?? null)
            ? $settings['domain_search_filters']
            : $settings;
        $filters = [];
        foreach (['department', 'seniority', 'verification_status'] as $key) {
            $values = is_array($source[$key] ?? null) ? $source[$key] : [];
            $values = array_values(array_unique(array_filter(array_map(
                fn (mixed $value): ?string => $this->safeToken($value),
                $values,
            ))));
            if ($values !== []) {
                $filters[$key] = $values;
            }
        }
        if (is_bool($source['decision_maker'] ?? null)) {
            $filters['decision_maker'] = $source['decision_maker'];
        }

        return $filters;
    }

    private function context(
        ProspectBatchItem $item,
        string $operation,
        string $qualifier,
        ?string $engine = null,
        ?float $reservedUnits = null,
    ): ProviderCallContext {
        $reservedUnits ??= $engine === null
            ? $this->hunterUnits($operation)
            : (float) config('prospecting.provider_units.serpapi.search', 1);

        return new ProviderCallContext(
            hash('sha256', implode('|', [
                'prospect_item_v1',
                (string) $item->getKey(),
                $operation,
                hash('sha256', $qualifier),
            ])),
            $reservedUnits,
            batchId: (int) $item->prospect_batch_id,
            itemId: (int) $item->getKey(),
            engine: $engine,
        );
    }

    private function domainSearchUnits(int $emails): float
    {
        if ($emails <= 0) {
            return 0;
        }

        return round(
            (float) ceil($emails / self::DOMAIN_SEARCH_EMAILS_PER_UNIT)
                * $this->hunterUnits('domain_search'),
            2,
        );
    }

    private function hunterUnits(string $operation): float
    {
        return (float) config("prospecting.provider_units.hunter.{$operation}", 0);
    }

    /** @return array<string, mixed> */
    private function itemMetadata(ProspectBatchItem $item): array
    {
        $metadata = is_array($item->source_metadata) ? $item->source_metadata : [];
        $safe = [];
        foreach (['full_name', 'source', 'reported_domain'] as $key) {
            if (($value = $this->safeText($metadata[$key] ?? null, 255)) !== null) {
                $safe[$key] = $value;
            }
        }
        if (($description = $this->safeLongText($metadata['description'] ?? null, 2000)) !== null) {
            $safe['description'] = $description;
        }
        if (is_array($metadata['emails_count'] ?? null)) {
            $safe['emails_count'] = array_filter([
                'personal' => $this->boundedInteger($metadata['emails_count']['personal'] ?? null, 0, 1_000_000),
                'generic' => $this->boundedInteger($metadata['emails_count']['generic'] ?? null, 0, 1_000_000),
                'total' => $this->boundedInteger($metadata['emails_count']['total'] ?? null, 0, 1_000_000),
            ], static fn (mixed $value): bool => $value !== null);
        }
        if (is_array($metadata['processing'] ?? null)) {
            $safe['processing'] = array_filter([
                'resolution_done' => ($metadata['processing']['resolution_done'] ?? false) === true,
                'domain_finder_done' => ($metadata['processing']['domain_finder_done'] ?? false) === true,
                'google_done' => ($metadata['processing']['google_done'] ?? false) === true,
                'maps_done' => ($metadata['processing']['maps_done'] ?? false) === true,
                'company_enrichment_done' => ($metadata['processing']['company_enrichment_done'] ?? false) === true,
                'company_scoring_done' => ($metadata['processing']['company_scoring_done'] ?? false) === true,
                'company_scoring_score' => $this->boundedInteger($metadata['processing']['company_scoring_score'] ?? null, 0, 100),
                'company_scoring_exclude' => ($metadata['processing']['company_scoring_exclude'] ?? false) === true,
                'domain_search_done' => ($metadata['processing']['domain_search_done'] ?? false) === true,
                'domain_search_offset' => max(0, min(10_000, (int) ($metadata['processing']['domain_search_offset'] ?? 0))),
                'domain_search_limit_cap' => (int) ($metadata['processing']['domain_search_limit_cap'] ?? 0) === self::FREE_PLAN_PAGE_LIMIT
                    ? self::FREE_PLAN_PAGE_LIMIT
                    : 0,
                'email_finder_done' => ($metadata['processing']['email_finder_done'] ?? false) === true,
            ], static fn (mixed $value): bool => $value !== false && $value !== 0);
        }
        if (is_array($metadata['resolution'] ?? null)) {
            $safe['resolution'] = [
                'finder' => $this->safeEvidenceRows($metadata['resolution']['finder'] ?? []),
                'google' => $this->safeEvidenceRows($metadata['resolution']['google'] ?? []),
                'maps' => $this->safeEvidenceRows($metadata['resolution']['maps'] ?? []),
                'provided' => $this->safeEvidenceRows($metadata['resolution']['provided'] ?? []),
                'blockers' => $this->uniqueStrings((array) ($metadata['resolution']['blockers'] ?? [])),
            ];
        }
        if (is_array($metadata['company'] ?? null)) {
            $safe['company'] = array_filter([
                'name' => $this->safeText($metadata['company']['name'] ?? null, 255),
                'sector' => $this->safeText($metadata['company']['sector'] ?? null, 100),
                'country' => $this->safeCountry($metadata['company']['country'] ?? null),
                'estimated_size' => $this->safeText($metadata['company']['estimated_size'] ?? null, 20),
                'phone' => $this->safeText($metadata['company']['phone'] ?? null, 50),
                'city' => $this->safeText($metadata['company']['city'] ?? null, 120),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $safe;
    }

    /** @return list<array<string, mixed>> */
    private function safeEvidenceRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $safe = [];
        foreach (array_slice(array_values($rows), 0, 20) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $domain = $this->domains->canonicalize((string) ($row['domain'] ?? ''));
            $entry = array_filter([
                'name' => $this->safeText($row['name'] ?? $row['company_name'] ?? null, 255),
                'company_name' => $this->safeText($row['company_name'] ?? null, 255),
                'country' => $this->safeCountry($row['country'] ?? null),
                'address' => $this->safeText($row['address'] ?? null, 500),
                'phone' => $this->safeText($row['phone'] ?? null, 50),
                'provider_key' => $this->safeIdentifier($row['provider_key'] ?? null),
                'engine' => in_array($row['engine'] ?? null, ['google', 'google_maps'], true) ? $row['engine'] : null,
                'match_mode' => ($row['match_mode'] ?? null) === 'perfect' ? 'perfect' : null,
                'domain' => $domain?->host,
                'registrable_domain' => $domain?->registrableDomain,
                'perfect_match' => ($row['perfect_match'] ?? false) === true,
                'platform' => $domain?->isPlatform ?? (($row['platform'] ?? false) === true),
            ], static fn (mixed $value): bool => $value !== null);
            if ($entry !== []) {
                $safe[] = $entry;
            }
        }

        return $safe;
    }

    /** @return list<array<string, mixed>> */
    private function replayedEvidence(ProspectBatchItem $item, string $stateKey, string $evidenceKey): array
    {
        $item->refresh();
        $metadata = $this->itemMetadata($item);
        if (data_get($metadata, "processing.{$stateKey}") !== true) {
            throw new ProviderRequestException('provider_outcome_uncertain', false);
        }

        return $this->safeEvidenceRows(data_get($metadata, "resolution.{$evidenceKey}", []));
    }

    private function assertReplayedState(ProspectBatchItem $item, string $stateKey): void
    {
        $item->refresh();
        if (data_get($this->itemMetadata($item), "processing.{$stateKey}") !== true) {
            throw new ProviderRequestException('provider_outcome_uncertain', false);
        }
    }

    /** @return list<string> */
    private function resolutionDomains(array $metadata): array
    {
        $domains = [];
        foreach (['finder', 'google', 'maps', 'provided'] as $key) {
            foreach ($this->safeEvidenceRows(data_get($metadata, "resolution.{$key}", [])) as $row) {
                if (is_string($row['domain'] ?? null)) {
                    $domains[] = $row['domain'];
                }
            }
        }

        return $this->uniqueStrings($domains);
    }

    private function namesAgree(mixed $expected, mixed $actual): bool
    {
        $expected = $this->identityName($expected);
        $actual = $this->identityName($actual);
        if ($expected === '' || $actual === '') {
            return false;
        }

        return $expected === $actual
            || str_starts_with($actual.' ', $expected.' ')
            || str_starts_with($expected.' ', $actual.' ');
    }

    private function identityName(mixed $value): string
    {
        $value = Str::lower(Str::ascii((string) $value));
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        $tokens = array_values(array_filter(explode(' ', trim($value))));
        while ($tokens !== [] && in_array(end($tokens), [
            'sas', 'sarl', 'sa', 'eurl', 'ltd', 'llc', 'inc', 'corp', 'gmbh', 'plc',
        ], true)) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }

    private function countriesAgree(?string $expected, mixed $actual, mixed $domain): bool
    {
        if ($expected === null || trim($expected) === '') {
            return true;
        }

        $actual = strtoupper(trim((string) $actual));
        if ($actual !== '') {
            return $actual === strtoupper($expected);
        }

        $canonical = $this->domains->canonicalize((string) $domain);
        if ($canonical === null) {
            return false;
        }
        $labels = explode('.', $canonical->registrableDomain);
        $countryTld = strtoupper((string) end($labels));
        if (strlen($countryTld) !== 2) {
            return false;
        }
        $countryTld = match ($countryTld) {
            'UK' => 'GB',
            default => $countryTld,
        };

        return $countryTld === strtoupper($expected);
    }

    private function concreteFullName(mixed $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
        if ($value === '' || mb_strlen($value) > 255 || str_contains($value, '@') || str_contains($value, '://')) {
            return null;
        }
        $parts = preg_split('/\s+/u', $value) ?: [];
        if (count($parts) < 2 || preg_match('/\pL/u', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || str_contains($value, '://')) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    /**
     * Same as safeText() but allows '://' — long-form blurbs (e.g. exhibitor
     * descriptions) legitimately contain URLs.
     */
    private function safeLongText(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    private function safeToken(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $value) === 1 ? $value : null;
    }

    private function safeIdentifier(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $value) === 1 ? $value : null;
    }

    private function safeCountry(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);

        return $value === false ? null : $value;
    }

    /** @param list<mixed> $values @return list<string> */
    private function uniqueStrings(array $values): array
    {
        $safe = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 191) {
                continue;
            }
            $safe[] = trim($value);
        }
        $safe = array_values(array_unique($safe));
        sort($safe, SORT_STRING);

        return $safe;
    }
}
