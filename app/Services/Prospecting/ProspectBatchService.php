<?php

namespace App\Services\Prospecting;

use App\Jobs\FinalizeProspectBatchJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\Company;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Discovery\DomainCanonicalizer;
use App\Services\Discovery\HunterDiscoverService;
use App\Services\Providers\Hunter\HunterClient;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderExecution;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class ProspectBatchService
{
    private const DISCOVER_LIMIT = 100;

    private const DOMAIN_SEARCH_PAGE_LIMIT = 100;

    private const DOMAIN_SEARCH_MIN_RESULTS = 10;

    private const DOMAIN_SEARCH_MAX_RESULTS = 500;

    /** @var array<string, int> */
    private const DOMAIN_SEARCH_PRESET_RESULTS = [
        'lean' => 10,
        'balanced' => 100,
        'deep' => 200,
    ];

    public function __construct(
        private readonly DomainCanonicalizer $domains,
        private readonly HunterDiscoverService $discover,
        private readonly HunterClient $hunter,
        private readonly ProviderCallLedger $ledger,
    ) {}

    public function createListBatch(User $creator, array $rows, array $options = []): ProspectBatch
    {
        if ($rows === []) {
            throw new InvalidArgumentException('prospect_batch_requires_items');
        }

        return DB::transaction(function () use ($creator, $rows, $options): ProspectBatch {
            $name = trim((string) ($options['name'] ?? 'Import entreprises '.now()->format('Y-m-d H:i')));

            if ($name === '' || mb_strlen($name) > 255) {
                throw new InvalidArgumentException('prospect_batch_name_invalid');
            }

            $qualityPreset = trim((string) ($options['quality_preset'] ?? 'balanced'));

            if ($qualityPreset === '' || mb_strlen($qualityPreset) > 16) {
                throw new InvalidArgumentException('prospect_batch_quality_invalid');
            }

            $batch = ProspectBatch::query()->create([
                'source_type' => 'company_list',
                'name' => $name,
                'created_by' => $creator->getKey(),
                'status' => 'draft',
                'quality_preset' => $qualityPreset,
                'quality_settings' => is_array($options['quality_settings'] ?? null)
                    ? $options['quality_settings']
                    : [],
                'source_options' => is_array($options['source_options'] ?? null)
                    ? $options['source_options']
                    : [],
            ]);

            $this->stageItems($batch, $rows);

            return $batch->fresh();
        });
    }

    /**
     * Local-only Discover preview. It deliberately performs no provider or
     * ledger operation and persists no draft.
     *
     * @return array{prompt:string,prompt_hash:string,targeting:array<string,mixed>,estimate:array<string,mixed>}
     */
    public function previewDiscover(
        ProspectCriteria $criteria,
        string $target,
        ?string $exclude,
        array $options = [],
    ): array {
        $targeting = $this->validatedDiscoverTargeting($criteria, $target, $exclude);
        $limit = $this->discoverLimit($options['limit'] ?? self::DISCOVER_LIMIT);

        return [
            'prompt' => $this->discover->buildPrompt($criteria, $targeting['target'], $targeting['exclude']),
            'prompt_hash' => $this->discover->promptHash($criteria, $targeting['target'], $targeting['exclude']),
            'targeting' => $targeting,
            'estimate' => $this->discoverEstimate($limit),
        ];
    }

    public function createDiscoverBatch(
        User $creator,
        ProspectCriteria $criteria,
        string $target,
        ?string $exclude,
        array $options = [],
    ): ProspectBatch {
        $targeting = $this->validatedDiscoverTargeting($criteria, $target, $exclude);
        $name = $this->normalizeText($options['name'] ?? 'Discover '.$criteria->name.' '.now()->format('Y-m-d H:i'));
        $qualityPreset = strtolower($this->normalizeText($options['quality_preset'] ?? 'balanced'));
        $qualitySettings = is_array($options['quality_settings'] ?? null) ? $options['quality_settings'] : [];
        $limit = $this->discoverLimit($options['limit'] ?? self::DISCOVER_LIMIT);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new InvalidArgumentException('prospect_batch_name_invalid');
        }
        if ($qualityPreset === '' || mb_strlen($qualityPreset) > 16
            || preg_match('/^[a-z][a-z0-9_-]*$/', $qualityPreset) !== 1) {
            throw new InvalidArgumentException('prospect_batch_quality_invalid');
        }

        return DB::transaction(function () use (
            $creator,
            $criteria,
            $targeting,
            $name,
            $qualityPreset,
            $qualitySettings,
            $limit,
        ): ProspectBatch {
            $persistedCriteria = ProspectCriteria::query()->lockForUpdate()->findOrFail($criteria->getKey());
            $targeting = $this->validatedDiscoverTargeting(
                $persistedCriteria,
                $targeting['target'],
                $targeting['exclude'],
            );
            $prompt = $this->discover->buildPrompt(
                $persistedCriteria,
                $targeting['target'],
                $targeting['exclude'],
            );
            $promptHash = $this->discover->promptHash(
                $persistedCriteria,
                $targeting['target'],
                $targeting['exclude'],
            );

            $existing = ProspectBatch::query()
                ->where('source_type', 'discover')
                ->where('created_by', $creator->getKey())
                ->where('prospect_criteria_id', $persistedCriteria->getKey())
                ->where('status', 'draft')
                ->whereNull('cost_confirmed_at')
                ->lockForUpdate()
                ->latest('id')
                ->get()
                ->first(function (ProspectBatch $candidate) use ($promptHash, $qualityPreset, $limit): bool {
                    $source = is_array($candidate->source_options) ? $candidate->source_options : [];
                    $cursor = is_array($candidate->source_cursor) ? $candidate->source_cursor : [];

                    return hash_equals((string) ($source['prompt_hash'] ?? ''), $promptHash)
                        && $candidate->quality_preset === $qualityPreset
                        && (int) ($cursor['limit'] ?? self::DISCOVER_LIMIT) === $limit;
                });

            if ($existing !== null) {
                return $existing->fresh();
            }

            $filtersHash = $this->discover->filtersHash([]);
            $estimate = $this->discoverEstimate($limit);
            $batch = ProspectBatch::query()->create([
                'source_type' => 'discover',
                'name' => $name,
                'created_by' => $creator->getKey(),
                'prospect_criteria_id' => $persistedCriteria->getKey(),
                'status' => 'draft',
                'quality_preset' => $qualityPreset,
                'quality_settings' => $qualitySettings,
                'source_options' => [
                    'targeting' => $targeting,
                    'prompt' => $prompt,
                    'prompt_hash' => $promptHash,
                    'filters' => null,
                ],
                'source_cursor' => [
                    'offset' => 0,
                    'filters_hash' => $filtersHash,
                    'exhausted' => false,
                    'results' => null,
                    'limit' => $limit,
                    'initialized' => false,
                    'prompt_hash' => $promptHash,
                ],
                'estimate' => $estimate,
            ]);

            return $batch->fresh();
        });
    }

    public function stageItems(ProspectBatch $batch, iterable $rows): int
    {
        return DB::transaction(function () use ($batch, $rows): int {
            $persisted = ProspectBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            $defaultRowNumber = 0;

            foreach ($rows as $row) {
                $defaultRowNumber++;

                if (! is_array($row)) {
                    throw new InvalidArgumentException('prospect_batch_row_invalid');
                }

                $rowNumber = filter_var(
                    $row['row_number'] ?? $defaultRowNumber,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 4_294_967_295]],
                );
                $companyName = $this->normalizeText($row['company_name'] ?? '');
                $city = $this->nullableText($row['city'] ?? null);
                $country = $this->normalizeCountryCode($row['country'] ?? null);
                $originalInput = (string) ($row['original_input'] ?? $companyName);

                if ($rowNumber === false
                    || $companyName === ''
                    || mb_strlen($companyName) > 255
                    || ($city !== null && mb_strlen($city) > 120)
                    || strlen($originalInput) > 4096
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $originalInput) === 1) {
                    throw new InvalidArgumentException('prospect_batch_row_invalid');
                }

                $providedDomain = $this->canonicalDomain($row['provided_domain'] ?? null);
                $attributes = [
                    'original_input' => $originalInput,
                    'company_name' => $companyName,
                    'normalized_name' => $this->normalizeName($companyName),
                    'country' => $country,
                    'city' => $city,
                    'provided_domain' => $providedDomain,
                    'source_metadata' => is_array($row['source_metadata'] ?? null)
                        ? $row['source_metadata']
                        : null,
                ];

                $existing = ProspectBatchItem::query()
                    ->where('prospect_batch_id', $persisted->getKey())
                    ->where('row_number', $rowNumber)
                    ->first();

                if ($existing === null) {
                    ProspectBatchItem::query()->create($attributes + [
                        'prospect_batch_id' => $persisted->getKey(),
                        'row_number' => $rowNumber,
                        'status' => 'pending',
                    ]);
                } elseif ($persisted->status === 'draft' && $persisted->cost_confirmed_at === null) {
                    $existing->forceFill($attributes)->save();
                }
            }

            $this->recomputeCounters($persisted);

            return $persisted->items()->count();
        });
    }

    /** @return array<string, mixed> */
    public function estimate(ProspectBatch $batch): array
    {
        return DB::transaction(function () use ($batch): array {
            $persisted = ProspectBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            $estimate = $this->buildEstimate($persisted);
            $encoded = json_encode($estimate, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $persisted->setRawAttributes(array_replace($persisted->getAttributes(), ['estimate' => $encoded]));
            $persisted->save();
            $batch->setRawAttributes($persisted->getAttributes(), true);

            return $estimate;
        });
    }

    public function domainSearchMaxResults(ProspectBatch $batch): int
    {
        $settings = is_array($batch->quality_settings) ? $batch->quality_settings : [];
        $override = filter_var($settings['domain_search_max_results'] ?? null, FILTER_VALIDATE_INT);
        if ($override !== false) {
            return max(self::DOMAIN_SEARCH_MIN_RESULTS, min(self::DOMAIN_SEARCH_MAX_RESULTS, $override));
        }

        $preset = strtolower(trim((string) $batch->quality_preset));

        return self::DOMAIN_SEARCH_PRESET_RESULTS[$preset]
            ?? self::DOMAIN_SEARCH_PRESET_RESULTS['balanced'];
    }

    public function confirmAndDispatch(ProspectBatch $batch, User $actor): ProspectBatch
    {
        if (! $actor->can('run prospect resolution')) {
            throw new AuthorizationException('prospect_batch_run_forbidden');
        }

        return DB::transaction(function () use ($batch): ProspectBatch {
            $persisted = ProspectBatch::query()->lockForUpdate()->findOrFail($batch->getKey());

            if ($persisted->cost_confirmed_at !== null) {
                throw new LogicException('prospect_batch_already_confirmed');
            }

            if ($persisted->status !== 'draft') {
                throw new LogicException('prospect_batch_not_draft');
            }

            if (! is_array($persisted->estimate)) {
                throw new LogicException('prospect_batch_estimate_required');
            }

            $currentEstimate = $this->buildEstimate($persisted);

            if (! $this->estimateMatches($persisted->estimate, $currentEstimate)) {
                throw new LogicException('prospect_batch_estimate_stale');
            }

            if ($persisted->source_type === 'discover') {
                if ($persisted->prospect_criteria_id === null) {
                    throw new LogicException('prospect_discover_criteria_required');
                }

                $criteria = ProspectCriteria::query()
                    ->lockForUpdate()
                    ->findOrFail($persisted->prospect_criteria_id);
                $activeDiscover = ProspectBatch::query()
                    ->where('source_type', 'discover')
                    ->where('prospect_criteria_id', $criteria->getKey())
                    ->whereIn('status', ['queued', 'running'])
                    ->whereKeyNot($persisted->getKey())
                    ->lockForUpdate()
                    ->exists();
                if ($activeDiscover) {
                    throw new LogicException('prospect_discover_active_batch_exists');
                }

                $source = is_array($persisted->source_options) ? $persisted->source_options : [];
                $cursor = is_array($persisted->source_cursor) ? $persisted->source_cursor : [];
                $promptHash = (string) ($source['prompt_hash'] ?? '');
                if (preg_match('/^[a-f0-9]{64}$/', $promptHash) !== 1
                    || ! hash_equals($promptHash, (string) ($cursor['prompt_hash'] ?? ''))) {
                    throw new LogicException('prospect_discover_prompt_stale');
                }

                $criteria->forceFill([
                    'hunter_discover_filters' => null,
                    'hunter_discover_prompt_hash' => $promptHash,
                    'hunter_discover_offset' => 0,
                    'hunter_discover_exhausted' => false,
                ])->save();
            }

            $persisted->forceFill([
                'status' => 'queued',
                'cost_confirmed_at' => now(),
                'error' => null,
            ])->save();

            $batchId = (int) $persisted->getKey();
            DB::afterCommit(function () use ($batchId): void {
                // Discover provider I/O belongs exclusively to the queued
                // finalizer. Confirmation must remain a fast durable write.
                if (ProspectBatch::query()->whereKey($batchId)->value('source_type') === 'discover') {
                    FinalizeProspectBatchJob::dispatch($batchId);

                    return;
                }

                $this->dispatchPendingItems($batchId);
            });

            return $persisted;
        });
    }

    /**
     * Reopen a discover batch stranded by FinalizeProspectBatchJob::failed()
     * (open cursor, no more scheduled attempts) so a fresh finalizer can
     * finish collecting it. Deliberately bypasses confirmAndDispatch() —
     * that throws prospect_batch_already_confirmed once cost_confirmed_at is
     * set, which every batch reaching this trap already has.
     */
    public function resumeDiscoverBatch(ProspectBatch $batch, User $actor): ProspectBatch
    {
        if (! $actor->can('run prospect resolution')) {
            throw new AuthorizationException('prospect_batch_run_forbidden');
        }

        return DB::transaction(function () use ($batch): ProspectBatch {
            $persisted = ProspectBatch::query()->lockForUpdate()->findOrFail($batch->getKey());

            if ($persisted->source_type !== 'discover'
                || $persisted->cost_confirmed_at === null
                || $persisted->prospect_criteria_id === null) {
                throw new LogicException('prospect_discover_not_resumable');
            }

            $cursor = is_array($persisted->source_cursor) ? $persisted->source_cursor : [];
            if (($cursor['exhausted'] ?? false) === true) {
                throw new LogicException('prospect_discover_not_resumable');
            }

            $criteria = ProspectCriteria::query()->lockForUpdate()->findOrFail($persisted->prospect_criteria_id);
            $source = is_array($persisted->source_options) ? $persisted->source_options : [];
            $promptHash = (string) ($source['prompt_hash'] ?? '');
            $offset = filter_var(
                $cursor['offset'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 10_000]],
            );

            // A fresh Discover confirm on this same criteria resets
            // hunter_discover_offset/exhausted and overwrites the prompt
            // hash (see confirmAndDispatch() above). If that happened after
            // this batch stranded, its cursor is orphaned — refuse
            // explicitly here rather than letting claimDiscoverPage() throw
            // prospect_discover_cursor_stale deep inside the finalizer.
            $cursorMatches = $offset !== false
                && preg_match('/^[a-f0-9]{64}$/', $promptHash) === 1
                && hash_equals($promptHash, (string) $criteria->hunter_discover_prompt_hash)
                && hash_equals($promptHash, (string) ($cursor['prompt_hash'] ?? ''))
                && (int) $criteria->hunter_discover_offset === $offset
                && (bool) $criteria->hunter_discover_exhausted === false;

            if (! $cursorMatches) {
                throw new LogicException('prospect_discover_resume_criteria_stale');
            }

            $persisted->forceFill([
                'status' => 'queued',
                'error' => null,
            ])->save();

            $batchId = (int) $persisted->getKey();
            DB::afterCommit(static function () use ($batchId): void {
                FinalizeProspectBatchJob::dispatch($batchId);
            });

            return $persisted;
        });
    }

    public function collectDiscoverPage(ProspectBatch $batch): int
    {
        $lock = Cache::lock('prospect-discover-page:'.$batch->getKey(), 150);
        if (! $lock->get()) {
            throw new LogicException('prospect_discover_page_in_progress');
        }

        try {
            $claim = $this->claimDiscoverPage($batch);
            if ($claim === null) {
                return 0;
            }

            $execution = $this->hunter->discover(
                new ProviderCallContext(
                    $claim['idempotency_key'],
                    0,
                    batchId: (int) $batch->getKey(),
                    engine: 'discover',
                ),
                $claim['payload'],
            );

            // A succeeded ledger row can only exist after the items/cursor and
            // settlement committed together. There is no response to replay.
            if ($execution->replayed) {
                return 0;
            }

            return $this->settleDiscoverPage($execution, $claim);
        } finally {
            $lock->release();
        }
    }

    public function promoteItem(ProspectBatchItem $item, ?User $actor = null): Company
    {
        $canonical = $this->domains->canonicalize((string) $item->selected_domain);
        if ($canonical === null || $canonical->isPlatform) {
            throw new LogicException('prospect_item_domain_not_promotable');
        }

        $lock = Cache::lock('prospect-promote:'.hash('sha256', $canonical->registrableDomain), 30);
        try {
            $acquired = $lock->block(3);
        } catch (LockTimeoutException) {
            throw new LogicException('prospect_item_promotion_lock_timeout');
        }
        if (! $acquired) {
            throw new LogicException('prospect_item_promotion_lock_timeout');
        }

        try {
            $result = DB::transaction(function () use ($item, $canonical): array {
                $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
                if ($locked->status === 'promoted' && $locked->company_id !== null) {
                    return ['company' => Company::withRejected()->findOrFail($locked->company_id), 'conflict' => null];
                }
                if (! in_array($locked->status, ['ready', 'review'], true)) {
                    throw new LogicException('prospect_item_not_promotable');
                }

                $selected = $this->domains->canonicalize((string) $locked->selected_domain);
                if ($selected === null || $selected->isPlatform
                    || $selected->registrableDomain !== $canonical->registrableDomain) {
                    throw new LogicException('prospect_item_domain_not_promotable');
                }

                $matches = $this->companiesForRegistrableDomain($selected, true);
                $exact = $matches->first(fn (Company $company): bool => $this->canonicalHost($company->domain) === $selected->host);
                $related = $matches->reject(fn (Company $company): bool => $exact !== null && $company->is($exact));
                $conflict = $related->isNotEmpty()
                    || ($exact !== null && ! $this->companyNamesAgree($locked->company_name, $exact->name));
                if ($conflict) {
                    $locked->forceFill([
                        'status' => 'review',
                        'domain_reason' => 'registrable_domain_collision',
                        'processed_at' => now(),
                    ])->save();

                    return ['company' => null, 'conflict' => 'prospect_item_domain_conflict'];
                }

                $company = $exact;
                if ($company === null) {
                    try {
                        $company = Company::query()->create($this->newCompanyAttributes($locked, $selected));
                    } catch (QueryException $exception) {
                        if (! $this->isUniqueConstraintViolation($exception)) {
                            throw $exception;
                        }
                        $company = Company::withRejected()
                            ->where('domain', $selected->host)
                            ->lockForUpdate()
                            ->first();
                        if ($company === null) {
                            throw $exception;
                        }
                        if (! $this->companyNamesAgree($locked->company_name, $company->name)) {
                            $locked->forceFill([
                                'status' => 'review',
                                'domain_reason' => 'domain_identity_conflict',
                                'processed_at' => now(),
                            ])->save();

                            return ['company' => null, 'conflict' => 'prospect_item_domain_conflict'];
                        }
                    }
                }

                $this->fillEmptyCompanyFields($company, $locked, $selected);
                $locked->forceFill([
                    'company_id' => $company->getKey(),
                    'status' => 'promoted',
                    'processed_at' => now(),
                    'error_code' => null,
                    'error_message' => null,
                ])->save();

                return ['company' => $company->fresh(), 'conflict' => null];
            });
        } finally {
            $lock->release();
        }

        $this->refreshCounters($item->batch);
        if ($result['company'] === null) {
            throw new LogicException((string) $result['conflict']);
        }

        return $result['company'];
    }

    /** Link the resolved company before provider contact calls without promoting the review item. */
    public function linkCompanyForProcessing(ProspectBatchItem $item): Company
    {
        if ($item->company_id !== null) {
            return Company::withRejected()->findOrFail($item->company_id);
        }

        $canonical = $this->domains->canonicalize((string) $item->selected_domain);
        if ($canonical === null || $canonical->isPlatform) {
            throw new LogicException('prospect_item_domain_not_promotable');
        }

        $lock = Cache::lock('prospect-promote:'.hash('sha256', $canonical->registrableDomain), 30);
        try {
            $acquired = $lock->block(3);
        } catch (LockTimeoutException) {
            throw new LogicException('prospect_item_promotion_lock_timeout');
        }
        if (! $acquired) {
            throw new LogicException('prospect_item_promotion_lock_timeout');
        }

        try {
            return DB::transaction(function () use ($item, $canonical): Company {
                $locked = ProspectBatchItem::query()->lockForUpdate()->findOrFail($item->getKey());
                if ($locked->company_id !== null) {
                    return Company::withRejected()->findOrFail($locked->company_id);
                }

                $selected = $this->domains->canonicalize((string) $locked->selected_domain);
                if ($selected === null || $selected->isPlatform
                    || $selected->registrableDomain !== $canonical->registrableDomain) {
                    throw new LogicException('prospect_item_domain_not_promotable');
                }

                $matches = $this->companiesForRegistrableDomain($selected, true);
                $company = $matches->first(
                    fn (Company $candidate): bool => $this->canonicalHost($candidate->domain) === $selected->host,
                );
                $related = $matches->reject(fn (Company $candidate): bool => $company !== null && $candidate->is($company));

                if ($related->isNotEmpty()
                    || ($company !== null && ! $this->companyNamesAgree($locked->company_name, $company->name))) {
                    throw new LogicException('prospect_item_domain_conflict');
                }

                if ($company === null) {
                    try {
                        $company = Company::query()->create($this->newCompanyAttributes($locked, $selected));
                    } catch (QueryException $exception) {
                        if (! $this->isUniqueConstraintViolation($exception)) {
                            throw $exception;
                        }

                        $company = Company::withRejected()
                            ->where('domain', $selected->host)
                            ->lockForUpdate()
                            ->first();
                        if ($company === null) {
                            throw $exception;
                        }
                        if (! $this->companyNamesAgree($locked->company_name, $company->name)) {
                            throw new LogicException('prospect_item_domain_conflict');
                        }
                    }
                }

                $this->fillEmptyCompanyFields($company, $locked, $selected);
                $locked->forceFill(['company_id' => $company->getKey()])->save();

                return $company->fresh();
            });
        } finally {
            $lock->release();
        }
    }

    public function refreshCounters(ProspectBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $persisted = ProspectBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            $this->recomputeCounters($persisted);
        });
    }

    /**
     * Single home for "must this batch's criterion be active before an item
     * can be retried" — shared by ProspectReviewController::decideItem()
     * (single item) and the retry-drain preview/job (bulk) so the rule and
     * its French message can't drift between the two paths. Null means
     * retry is not blocked by the criterion; $batch->criteria must already
     * be eager-loaded by the caller (no query here).
     */
    public function retryBlockedByCriterion(?ProspectBatch $batch): ?string
    {
        if ($batch === null || $batch->prospect_criteria_id === null || ($batch->criteria?->is_active ?? false)) {
            return null;
        }

        return $batch->criteria === null
            ? 'Le critère lié à ce lot n’est plus disponible. Sélectionnez un critère actif avant de relancer cette entreprise.'
            : 'Le critère « '.$batch->criteria->name.' » est inactif. Réactivez-le avant de relancer cette entreprise.';
    }

    /**
     * Retry-drain preflight for a single item: the 3 skip reasons that don't
     * require touching the provider-call ledger. Budget exhaustion is
     * deliberately NOT decided here — authorizing a retry is inherently a
     * "try it under lock and see" operation
     * (ProviderCallLedger::authorizeKnownFailureRetryForItem), so the drain
     * job attempts it directly and catches the exhausted exception, while
     * the read-only preview asks ProviderCallLedger::retryHeadroomForItem()
     * instead of duplicating the cap logic here.
     *
     * @return ?string one of 'provider_outcome_uncertain'|'criterion_inactive'|'retry_window_open', or null when nothing here blocks a retry
     */
    public function preflightRetrySkipReason(ProspectBatchItem $item): ?string
    {
        if ($item->error_code === 'provider_outcome_uncertain') {
            // Requires a per-item confirm_provider_reissue checkbox
            // (decideItem()'s other retry authorization path) — never swept
            // into a blind bulk action.
            return 'provider_outcome_uncertain';
        }
        if ($this->retryBlockedByCriterion($item->batch) !== null) {
            return 'criterion_inactive';
        }
        if ($this->ledger->hasOpenRetryWindow((int) $item->getKey())) {
            return 'retry_window_open';
        }

        return null;
    }

    /**
     * Dry-run tally for the retry-drain confirm dialog: how many of the
     * given (already filter-scoped, already capped) failed items would
     * actually be retried right now, and why the rest would be skipped.
     * Never mutates anything — the drain job re-checks every item under
     * lock before touching it, since this count can go stale between
     * preview and confirm (another admin action, a cleared rate limit, …).
     *
     * @param  iterable<int, ProspectBatchItem>  $items  each with batch.criteria eager-loaded
     * @return array{total_matching:int,considered:int,eligible_count:int,skipped:array<string,int>,estimated_units:float,min_attempt_headroom:?int}
     */
    public function summarizeRetryDrain(iterable $items, int $totalMatching): array
    {
        $skipped = ['retry_window_open' => 0, 'budget_exhausted' => 0, 'provider_outcome_uncertain' => 0, 'criterion_inactive' => 0];
        $eligible = 0;
        $minHeadroom = null;
        $considered = 0;

        foreach ($items as $item) {
            $considered++;
            $reason = $this->preflightRetrySkipReason($item);

            if ($reason === null) {
                $headroom = $this->ledger->retryHeadroomForItem((int) $item->getKey(), (string) $item->error_code);
                if ($headroom === 0) {
                    $reason = 'budget_exhausted';
                } else {
                    $eligible++;
                    if ($headroom !== null) {
                        $minHeadroom = $minHeadroom === null ? $headroom : min($minHeadroom, $headroom);
                    }
                }
            }

            if ($reason !== null) {
                $skipped[$reason]++;
            }
        }

        return [
            'total_matching' => $totalMatching,
            'considered' => $considered,
            'eligible_count' => $eligible,
            'skipped' => $skipped,
            'estimated_units' => round($eligible * (float) config('prospecting.provider_units.hunter.domain_search', 1), 2),
            'min_attempt_headroom' => $minHeadroom,
        ];
    }

    /** @return array<string, mixed>|null */
    private function claimDiscoverPage(ProspectBatch $batch): ?array
    {
        return DB::transaction(function () use ($batch): ?array {
            $persisted = ProspectBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            if ($persisted->source_type !== 'discover') {
                throw new LogicException('prospect_batch_not_discover');
            }
            if ($persisted->cost_confirmed_at === null) {
                throw new LogicException('prospect_discover_cost_not_confirmed');
            }
            if (! in_array($persisted->status, ['queued', 'running'], true)) {
                throw new LogicException('prospect_discover_not_collectable');
            }
            if ($persisted->prospect_criteria_id === null) {
                throw new LogicException('prospect_discover_criteria_required');
            }

            $criteria = ProspectCriteria::query()
                ->lockForUpdate()
                ->findOrFail($persisted->prospect_criteria_id);
            $source = is_array($persisted->source_options) ? $persisted->source_options : [];
            $cursor = is_array($persisted->source_cursor) ? $persisted->source_cursor : [];
            $promptHash = (string) ($source['prompt_hash'] ?? '');
            $prompt = (string) ($source['prompt'] ?? '');

            if (preg_match('/^[a-f0-9]{64}$/', $promptHash) !== 1
                || ! hash_equals($promptHash, (string) $criteria->hunter_discover_prompt_hash)
                || ! hash_equals($promptHash, (string) ($cursor['prompt_hash'] ?? ''))) {
                throw new LogicException('prospect_discover_prompt_stale');
            }

            $offset = filter_var(
                $cursor['offset'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 10_000]],
            );
            $limit = filter_var(
                $cursor['limit'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 100]],
            );
            if ($offset === false || $limit === false) {
                throw new LogicException('prospect_discover_cursor_invalid');
            }

            $initialized = ($cursor['initialized'] ?? false) === true;
            $exhausted = ($cursor['exhausted'] ?? false) === true;
            if ((int) $criteria->hunter_discover_offset !== $offset
                || (bool) $criteria->hunter_discover_exhausted !== $exhausted) {
                throw new LogicException('prospect_discover_cursor_stale');
            }
            if ($exhausted || $offset >= 10_000) {
                return null;
            }

            if ($initialized) {
                if (! array_key_exists('filters', $source) || ! is_array($source['filters'])) {
                    throw new LogicException('prospect_discover_filters_missing');
                }
                $filters = $this->discover->normalizeFilters($source['filters']);
            } else {
                if (trim($prompt) === '') {
                    throw new LogicException('prospect_discover_prompt_missing');
                }
                $filters = [];
            }
            $filtersHash = $this->discover->filtersHash($filters);
            if (! hash_equals($filtersHash, (string) ($cursor['filters_hash'] ?? ''))) {
                throw new LogicException('prospect_discover_filters_stale');
            }

            $payload = $initialized
                ? array_merge($filters, ['limit' => $limit, 'offset' => $offset])
                : ['query' => $prompt, 'limit' => $limit];

            return [
                'batch_id' => (int) $persisted->getKey(),
                'criteria_id' => (int) $criteria->getKey(),
                'prompt_hash' => $promptHash,
                'filters_hash' => $filtersHash,
                'filters' => $filters,
                'offset' => $offset,
                'limit' => $limit,
                'initialized' => $initialized,
                'payload' => $payload,
                'idempotency_key' => hash('sha256', "discover|{$persisted->getKey()}|{$promptHash}|{$filtersHash}|{$offset}"),
            ];
        });
    }

    /** @param array<string, mixed> $claim */
    private function settleDiscoverPage(ProviderExecution $execution, array $claim): int
    {
        if ($execution->response === null) {
            throw new LogicException('prospect_discover_response_missing');
        }

        $response = $execution->response;
        $providerRows = array_is_list($response->data) ? $response->data : [];
        $returnedCount = count($providerRows);
        $rows = $this->discover->normalizeBatchRows($providerRows);
        $filters = $claim['initialized']
            ? $claim['filters']
            : $this->discover->normalizeFilters($response->meta['filters'] ?? []);
        $filtersHash = $this->discover->filtersHash($filters);
        $limit = $this->boundedMetaInteger($response->meta['limit'] ?? null, 1, 100)
            ?? (int) $claim['limit'];
        $results = $this->boundedMetaInteger($response->meta['results'] ?? null, 0, 10_000);
        $nextOffset = min(10_000, (int) $claim['offset'] + $limit);
        $exhausted = $returnedCount === 0
            || $returnedCount < $limit
            || (! $claim['initialized'] && $filters === [])
            || ($results !== null && $nextOffset >= $results)
            || $nextOffset >= 10_000;

        return DB::transaction(function () use (
            $execution,
            $claim,
            $rows,
            $returnedCount,
            $filters,
            $filtersHash,
            $limit,
            $results,
            $nextOffset,
            $exhausted,
        ): int {
            $batch = ProspectBatch::query()->lockForUpdate()->findOrFail($claim['batch_id']);
            $criteria = ProspectCriteria::query()->lockForUpdate()->findOrFail($claim['criteria_id']);
            $source = is_array($batch->source_options) ? $batch->source_options : [];
            $cursor = is_array($batch->source_cursor) ? $batch->source_cursor : [];

            $stale = $batch->source_type !== 'discover'
                || $batch->cost_confirmed_at === null
                || ! in_array($batch->status, ['queued', 'running'], true)
                || ! hash_equals((string) $claim['prompt_hash'], (string) ($source['prompt_hash'] ?? ''))
                || ! hash_equals((string) $claim['prompt_hash'], (string) $criteria->hunter_discover_prompt_hash)
                || (int) ($cursor['offset'] ?? -1) !== (int) $claim['offset']
                || (($cursor['initialized'] ?? false) === true) !== (bool) $claim['initialized']
                || ! hash_equals((string) $claim['filters_hash'], (string) ($cursor['filters_hash'] ?? ''));

            if ($stale) {
                $this->ledger->settle($execution, $returnedCount, 0, [
                    'reason' => 'stale_cursor',
                    'offset' => (int) $claim['offset'],
                    'limit' => $limit,
                    'filters_hash' => $filtersHash,
                ]);

                return 0;
            }

            if (! $claim['initialized']) {
                $source['filters'] = $filters;
            }

            $insertedItemIds = $this->stageDiscoverRows($batch, $rows, (int) $claim['offset']);
            $batch->forceFill([
                'status' => 'running',
                'source_options' => $source,
                'source_cursor' => [
                    'offset' => $nextOffset,
                    'filters_hash' => $filtersHash,
                    'exhausted' => $exhausted,
                    'results' => $results,
                    'limit' => $limit,
                    'initialized' => true,
                    'prompt_hash' => $claim['prompt_hash'],
                ],
                'started_at' => $batch->started_at ?? now(),
            ])->save();
            $criteria->forceFill([
                'hunter_discover_filters' => $filters,
                'hunter_discover_prompt_hash' => $claim['prompt_hash'],
                'hunter_discover_offset' => $nextOffset,
                'hunter_discover_exhausted' => $exhausted,
            ])->save();
            $this->recomputeCounters($batch);
            $this->ledger->settle($execution, $returnedCount, 0, [
                'filters_hash' => $filtersHash,
                'offset' => (int) $claim['offset'],
                'limit' => $limit,
            ]);

            $batchId = (int) $batch->getKey();
            DB::afterCommit(static function () use ($insertedItemIds, $batchId): void {
                foreach ($insertedItemIds as $itemId) {
                    ProcessProspectBatchItemJob::dispatch($itemId);
                }

                FinalizeProspectBatchJob::dispatch($batchId)->delay(now()->addSeconds(15));
            });

            return count($insertedItemIds);
        });
    }

    /** @param list<array<string, mixed>> $rows @return list<int> */
    private function stageDiscoverRows(ProspectBatch $batch, array $rows, int $offset): array
    {
        $inserted = [];
        foreach ($rows as $row) {
            $position = filter_var(
                $row['position'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 99]],
            );
            if ($position === false) {
                continue;
            }

            $companyName = $this->normalizeText($row['company_name'] ?? '');
            if ($companyName === '' || mb_strlen($companyName) > 255) {
                continue;
            }
            $country = strtoupper(trim((string) ($row['country'] ?? '')));
            if ($country === '' || ! array_key_exists($country, config('global.data.company_countries', []))) {
                $country = null;
            }
            $providedDomain = $this->canonicalDomain($row['provided_domain'] ?? null);
            $rowNumber = $offset + $position + 1;
            $originalInput = $companyName.($providedDomain !== null ? ' | '.$providedDomain : '');

            $item = ProspectBatchItem::query()->firstOrCreate([
                'prospect_batch_id' => $batch->getKey(),
                'row_number' => $rowNumber,
            ], [
                'original_input' => $originalInput,
                'company_name' => $companyName,
                'normalized_name' => $this->normalizeName($companyName),
                'country' => $country,
                'city' => $this->boundedNullable($row['city'] ?? null, 120),
                'provided_domain' => $providedDomain,
                'status' => 'pending',
                'source_metadata' => is_array($row['source_metadata'] ?? null)
                    ? $row['source_metadata']
                    : ['source' => 'hunter_discover'],
            ]);

            if ($item->wasRecentlyCreated) {
                $inserted[] = (int) $item->getKey();
            }
        }

        return $inserted;
    }

    private function dispatchPendingItems(int $batchId): void
    {
        $itemIds = ProspectBatchItem::query()
            ->where('prospect_batch_id', $batchId)
            ->where('status', 'pending')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($itemIds as $itemId) {
            ProcessProspectBatchItemJob::dispatch($itemId);
        }

        FinalizeProspectBatchJob::dispatch($batchId)->delay(now()->addSeconds(15));
    }

    /** @return array<string, mixed> */
    private function discoverEstimate(int $limit): array
    {
        $attempts = min(20, $limit);
        $hunterUnits = round($attempts * (
            (float) config('prospecting.provider_units.hunter.domain_search', 1)
            + (float) config('prospecting.provider_units.hunter.company_enrichment', 0.2)
        ), 2);

        return [
            'items' => $limit,
            'free' => ['prompt' => 1, 'staging_max' => $limit],
            'calls' => ['hunter_discover' => 1, 'hunter_domain_search' => $attempts, 'hunter_company_enrichment' => $attempts],
            'reserved_units' => ['hunter' => $hunterUnits, 'serpapi' => 0.0],
            'page_limit' => $limit,
        ];
    }

    /**
     * @return array{target:string,exclude:?string,sectors:list<string>,countries:list<string>,sizes:list<string>}
     */
    private function validatedDiscoverTargeting(
        ProspectCriteria $criteria,
        string $target,
        ?string $exclude,
    ): array {
        $targeting = $this->discover->targetingSnapshot($criteria, $target, $exclude);
        if (($targeting['target'] === '' && $targeting['exclude'] === null)
            || mb_strlen($targeting['target']) > 2000
            || ($targeting['exclude'] !== null && mb_strlen($targeting['exclude']) > 2000)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $targeting['target']) === 1
            || ($targeting['exclude'] !== null
                && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $targeting['exclude']) === 1)) {
            throw new InvalidArgumentException('prospect_discover_target_invalid');
        }

        return $targeting;
    }

    private function discoverLimit(mixed $value): int
    {
        $limit = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]],
        );
        if ($limit === false) {
            throw new InvalidArgumentException('prospect_discover_limit_invalid');
        }

        return $limit;
    }

    private function boundedMetaInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        $value = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]],
        );

        return $value === false ? null : $value;
    }

    /** @return array<string, mixed> */
    private function buildEstimate(ProspectBatch $batch): array
    {
        if ($batch->source_type === 'discover') {
            $cursor = is_array($batch->source_cursor) ? $batch->source_cursor : [];

            return $this->discoverEstimate($this->discoverLimit($cursor['limit'] ?? self::DISCOVER_LIMIT));
        }

        $items = $batch->items()->count();
        $withoutDomain = $batch->items()->whereNull('provided_domain')->count();
        $namedPeople = $batch->items()
            ->get(['source_metadata'])
            ->filter(function (ProspectBatchItem $item): bool {
                $metadata = is_array($item->source_metadata) ? $item->source_metadata : [];
                $fullName = $this->normalizeText($metadata['full_name'] ?? '');

                return $fullName !== ''
                    && mb_strlen($fullName) <= 255
                    && ! str_contains($fullName, '@')
                    && ! str_contains($fullName, '://')
                    && count(preg_split('/\s+/u', $fullName) ?: []) >= 2;
            })
            ->count();
        $finderUnits = (float) config('prospecting.provider_units.hunter.domain_finder', 0);
        $domainSearchUnits = (float) config('prospecting.provider_units.hunter.domain_search', 1);
        $companyEnrichmentUnits = (float) config('prospecting.provider_units.hunter.company_enrichment', 0.2);
        $emailFinderUnits = (float) config('prospecting.provider_units.hunter.email_finder', 1);
        $serpSearchUnits = (float) config('prospecting.provider_units.serpapi.search', 1);
        $domainSearchMaxResults = $this->domainSearchMaxResults($batch);
        $domainSearchCallsPerItem = (int) ceil($domainSearchMaxResults / self::DOMAIN_SEARCH_PAGE_LIMIT);
        $domainSearchUnitsPerItem = (float) ceil($domainSearchMaxResults / 10) * $domainSearchUnits;

        return [
            'items' => $items,
            'free' => ['cleanup' => $items, 'deduplication' => $items],
            'calls' => [
                'hunter_domain_finder' => $withoutDomain,
                'serpapi_search_max' => $withoutDomain * 2,
                'hunter_company_enrichment' => $items,
                'hunter_domain_search_max' => $items * $domainSearchCallsPerItem,
                'hunter_email_finder' => $namedPeople,
            ],
            'reserved_units' => [
                'hunter' => round(
                    ($withoutDomain * $finderUnits)
                    + ($items * ($domainSearchUnitsPerItem + $companyEnrichmentUnits))
                    + ($namedPeople * $emailFinderUnits),
                    2,
                ),
                'serpapi' => round($withoutDomain * 2 * $serpSearchUnits, 2),
            ],
        ];
    }

    /** @param array<string, mixed> $stored */
    private function estimateMatches(array $stored, array $current): bool
    {
        foreach (['hunter', 'serpapi'] as $provider) {
            if (isset($stored['reserved_units'][$provider])) {
                $stored['reserved_units'][$provider] = (float) $stored['reserved_units'][$provider];
            }
            if (isset($current['reserved_units'][$provider])) {
                $current['reserved_units'][$provider] = (float) $current['reserved_units'][$provider];
            }
        }

        return $this->canonicalizeEstimate($stored) === $this->canonicalizeEstimate($current);
    }

    private function canonicalizeEstimate(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeEstimate($item);
        }

        return $value;
    }

    private function recomputeCounters(ProspectBatch $batch): void
    {
        $batchId = $batch->getKey();
        $items = ProspectBatchItem::query()->where('prospect_batch_id', $batchId);
        $terminal = ['review', 'ready', 'promoted', 'failed', 'skipped'];
        $batch->forceFill([
            'total_items' => (clone $items)->count(),
            'processed_items' => (clone $items)->whereIn('status', $terminal)->count(),
            'review_items' => (clone $items)->where('status', 'review')->count(),
            'failed_items' => (clone $items)->where('status', 'failed')->count(),
            'promoted_companies' => (clone $items)->where('status', 'promoted')->count(),
            'imported_contacts' => ProspectBatchContact::query()->where('prospect_batch_id', $batchId)->count(),
        ])->save();
    }

    private function canonicalDomain(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $canonical = $this->domains->canonicalize($value);

        return $canonical !== null && ! $canonical->isPlatform ? $canonical->host : null;
    }

    private function normalizeCountryCode(mixed $value): ?string
    {
        $value = strtoupper(trim((string) $value));

        if ($value === '') {
            return null;
        }

        if (strlen($value) !== 2 || ! array_key_exists($value, config('global.data.company_countries', []))) {
            throw new InvalidArgumentException('prospect_batch_country_invalid');
        }

        return $value;
    }

    private function normalizeName(string $value): string
    {
        return $this->normalizeText(Str::lower(Str::ascii($value)));
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $this->normalizeText($value);

        return $value === '' ? null : $value;
    }

    private function boundedNullable(mixed $value, int $length): ?string
    {
        $value = $this->nullableText($value);

        return $value === null ? null : mb_substr($value, 0, $length);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23505'
            || ($sqlState === '23000' && in_array($driverCode, [19, 1062, 1555, 2067], true));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Company> */
    private function companiesForRegistrableDomain(
        \App\Services\Discovery\CanonicalDomain $domain,
        bool $lock = false,
    ): \Illuminate\Database\Eloquent\Collection {
        $query = Company::withRejected()
            ->where(function ($builder) use ($domain): void {
                $builder->where('registrable_domain', $domain->registrableDomain)
                    ->orWhere('domain', $domain->registrableDomain)
                    ->orWhere('domain', 'like', '%.'.$domain->registrableDomain);
            });
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function canonicalHost(mixed $domain): ?string
    {
        return $this->domains->canonicalize((string) $domain)?->host;
    }

    private function companyNamesAgree(mixed $first, mixed $second): bool
    {
        $first = $this->companyIdentityName($first);
        $second = $this->companyIdentityName($second);
        if ($first === '' || $second === '') {
            return false;
        }

        return $first === $second
            || str_starts_with($first.' ', $second.' ')
            || str_starts_with($second.' ', $first.' ');
    }

    private function companyIdentityName(mixed $value): string
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

    /** @return array<string, mixed> */
    private function newCompanyAttributes(
        ProspectBatchItem $item,
        \App\Services\Discovery\CanonicalDomain $domain,
    ): array {
        $company = $this->safeCompanyMetadata($item);

        return [
            'criteria_id' => $item->batch->prospect_criteria_id,
            'domain' => $domain->host,
            'registrable_domain' => $domain->registrableDomain,
            'name' => $item->company_name,
            'sector' => $company['sector'] ?? null,
            'description' => $company['description'] ?? null,
            'country' => $item->country ?? ($company['country'] ?? null),
            'estimated_size' => $company['estimated_size'] ?? null,
            'phone' => $company['phone'] ?? null,
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => true,
        ];
    }

    private function fillEmptyCompanyFields(
        Company $company,
        ProspectBatchItem $item,
        \App\Services\Discovery\CanonicalDomain $domain,
    ): void {
        $metadata = $this->safeCompanyMetadata($item);
        $values = [
            'criteria_id' => $item->batch->prospect_criteria_id,
            'registrable_domain' => $domain->registrableDomain,
            'sector' => $metadata['sector'] ?? null,
            'description' => $metadata['description'] ?? null,
            'country' => $item->country ?? ($metadata['country'] ?? null),
            'estimated_size' => $metadata['estimated_size'] ?? null,
            'phone' => $metadata['phone'] ?? null,
        ];
        $changes = [];
        foreach ($values as $field => $value) {
            $current = $company->getAttribute($field);
            if (($current === null || (is_string($current) && trim($current) === ''))
                && $value !== null && (! is_string($value) || trim($value) !== '')) {
                $changes[$field] = $value;
            }
        }
        if ($changes !== []) {
            $company->forceFill($changes)->save();
        }
    }

    /** @return array<string, string> */
    private function safeCompanyMetadata(ProspectBatchItem $item): array
    {
        $metadata = is_array($item->source_metadata) ? $item->source_metadata : [];
        $company = is_array($metadata['company'] ?? null) ? $metadata['company'] : [];
        $safe = [];
        foreach ([
            'sector' => 100,
            'estimated_size' => 20,
            'phone' => 50,
        ] as $field => $limit) {
            $value = $this->boundedNullable($company[$field] ?? null, $limit);
            if ($value !== null && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
                && ! str_contains($value, '://')) {
                $safe[$field] = $value;
            }
        }
        $country = strtoupper(trim((string) ($company['country'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
            $safe['country'] = $country;
        }

        // description lives at the TOP level of source_metadata (not under
        // 'company') — it contains URLs, so it can't go through the foreach
        // above, which rejects '://'.
        $description = $this->boundedNullable($metadata['description'] ?? null, 2000);
        if ($description !== null && preg_match('/[\x00-\x1F\x7F]/', $description) !== 1) {
            $safe['description'] = $description;
        }

        return $safe;
    }
}
