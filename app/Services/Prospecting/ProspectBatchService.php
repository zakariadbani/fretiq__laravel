<?php

namespace App\Services\Prospecting;

use App\Jobs\FinalizeProspectBatchJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectContactCandidate;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Discovery\DomainCanonicalizer;
use App\Services\Discovery\HunterDiscoverService;
use App\Services\Providers\Hunter\HunterClient;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderExecution;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

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

    /** @var list<string> */
    private const VERIFICATION_STATUSES = [
        'valid', 'accept_all', 'pending', 'unknown', 'missing',
        'webmail', 'invalid', 'disposable', 'manual',
    ];

    /** @var list<string> */
    private const VERIFICATION_SOURCES = ['hunter', 'recovery', 'manual', 'zoho'];

    /** @var list<string> */
    private const CANDIDATE_METADATA_KEYS = [
        'origin', 'provider', 'operation', 'department', 'seniority',
        'confidence', 'decision_maker', 'page', 'offset', 'source_hash',
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

    public function stageContactCandidates(
        ProspectBatch $batch,
        ProspectBatchItem $item,
        iterable $rows,
    ): int {
        if ((int) $item->prospect_batch_id !== (int) $batch->getKey()) {
            throw new InvalidArgumentException('prospect_candidate_item_batch_mismatch');
        }

        return DB::transaction(function () use ($batch, $item, $rows): int {
            $accepted = [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $email = strtolower(trim((string) ($row['email'] ?? '')));

                if (! $this->isValidCandidateEmail($email) || isset($accepted[$email])) {
                    continue;
                }

                $source = strtolower(trim((string) ($row['source'] ?? '')));

                if ($source === '' || strlen($source) > 32 || preg_match('/^[a-z0-9_]+$/', $source) !== 1) {
                    continue;
                }

                $existing = ProspectContactCandidate::query()
                    ->where('prospect_batch_id', $batch->getKey())
                    ->where('normalized_email', $email)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && (int) $existing->prospect_batch_item_id !== (int) $item->getKey()) {
                    continue;
                }

                $verificationStatus = strtolower(trim((string) ($row['verification_status'] ?? '')));
                if (! in_array($verificationStatus, self::VERIFICATION_STATUSES, true)) {
                    $verificationStatus = null;
                }
                $verificationSource = strtolower(trim((string) ($row['verification_source'] ?? '')));
                if ($verificationStatus === null || ! in_array($verificationSource, self::VERIFICATION_SOURCES, true)) {
                    $verificationSource = null;
                }
                $verificationCheckedAt = $verificationStatus === null || $verificationSource === null
                    ? null
                    : $this->evidenceDate($row['verification_checked_at'] ?? null);

                $attributes = [
                    'company_id' => $item->company_id,
                    'email' => $email,
                    'normalized_email' => $email,
                    'name' => $this->boundedNullable($row['name'] ?? null, 255),
                    'position' => $this->boundedNullable($row['position'] ?? null, 120),
                    'phone' => $this->boundedNullable($row['phone'] ?? null, 50),
                    'source' => $source,
                    'email_kind' => in_array(($row['email_kind'] ?? 'role'), ['role', 'personal'], true)
                        ? $row['email_kind'] ?? 'role'
                        : 'role',
                    'verification_status' => $verificationStatus,
                    'verification_checked_at' => $verificationCheckedAt,
                    'verification_source' => $verificationSource,
                    'decision_reason' => $this->safeReason($row['decision_reason'] ?? null),
                    'metadata' => $this->candidateMetadata($row['metadata'] ?? null),
                ];

                if ($existing === null) {
                    try {
                        ProspectContactCandidate::query()->create($attributes + [
                            'prospect_batch_id' => $batch->getKey(),
                            'prospect_batch_item_id' => $item->getKey(),
                            'decision' => 'pending',
                        ]);
                    } catch (QueryException $exception) {
                        if (! $this->isUniqueConstraintViolation($exception)) {
                            throw $exception;
                        }

                        $winner = ProspectContactCandidate::query()
                            ->where('prospect_batch_id', $batch->getKey())
                            ->where('normalized_email', $email)
                            ->first();

                        if ($winner === null) {
                            throw $exception;
                        }

                        if ((int) $winner->prospect_batch_item_id !== (int) $item->getKey()) {
                            continue;
                        }
                    }
                } else {
                    foreach (['company_id', 'name', 'position', 'phone', 'metadata'] as $nullableField) {
                        if ($attributes[$nullableField] === null) {
                            unset($attributes[$nullableField]);
                        }
                    }
                    if ($verificationStatus === null) {
                        unset(
                            $attributes['verification_status'],
                            $attributes['verification_checked_at'],
                            $attributes['verification_source'],
                        );
                    }
                    if ($attributes['decision_reason'] === null) {
                        unset($attributes['decision_reason']);
                    }
                    $existing->forceFill($attributes)->save();
                }

                $accepted[$email] = true;
            }

            $this->recomputeCounters($batch);

            return count($accepted);
        });
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
                ProspectContactCandidate::query()
                    ->where('prospect_batch_item_id', $locked->getKey())
                    ->whereNull('company_id')
                    ->update(['company_id' => $company->getKey(), 'updated_at' => now()]);

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

    public function promoteContactCandidate(
        ProspectContactCandidate $candidate,
        ?User $actor = null,
    ): Contact {
        $result = DB::transaction(function () use ($candidate): array {
            $locked = ProspectContactCandidate::query()->lockForUpdate()->findOrFail($candidate->getKey());
            if ($locked->decision === 'promoted' && $locked->contact_id !== null) {
                return ['contact' => Contact::withTrashed()->findOrFail($locked->contact_id), 'conflict' => null];
            }
            if ($locked->decision !== 'approved') {
                throw new LogicException('prospect_contact_not_approved');
            }

            $companyId = $locked->company_id
                ?? ProspectBatchItem::query()->whereKey($locked->prospect_batch_item_id)->value('company_id');
            if ($companyId === null) {
                throw new LogicException('prospect_contact_company_required');
            }
            $email = strtolower(trim((string) $locked->normalized_email));
            if (! $this->isValidCandidateEmail($email)) {
                throw new LogicException('prospect_contact_email_invalid');
            }

            $contact = Contact::withNormalizedEmail($email)->lockForUpdate()->first();
            if ($contact !== null && (int) $contact->company_id !== (int) $companyId) {
                $locked->forceFill(['decision_reason' => 'email_owned_by_another_company'])->save();

                return ['contact' => null, 'conflict' => 'prospect_contact_email_owned_by_another_company'];
            }

            if ($contact === null) {
                try {
                    $contact = Contact::query()->create([
                        'company_id' => $companyId,
                        'email' => $email,
                        'name' => $this->contactName($locked, $email),
                        'position' => $locked->position,
                        'phone' => $locked->phone,
                        'source' => 'discovered',
                        'status' => 'new',
                        'legal_basis' => 'legitimate_interest',
                        'email_kind' => in_array($locked->email_kind, ['role', 'personal'], true)
                            ? $locked->email_kind
                            : 'role',
                        'source_captured_at' => $locked->created_at ?? now(),
                        ...$this->candidateVerificationAttributes($locked),
                    ]);
                } catch (QueryException $exception) {
                    if (! $this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }
                    $contact = Contact::withNormalizedEmail($email)->lockForUpdate()->first();
                    if ($contact === null) {
                        throw $exception;
                    }
                    if ((int) $contact->company_id !== (int) $companyId) {
                        $locked->forceFill(['decision_reason' => 'email_owned_by_another_company'])->save();

                        return ['contact' => null, 'conflict' => 'prospect_contact_email_owned_by_another_company'];
                    }
                }
            }

            $verification = $this->candidateVerificationAttributes($locked);
            if ($this->shouldReplaceContactVerification($contact, $verification)) {
                $contact->forceFill($verification)->save();
            }
            $locked->forceFill([
                'company_id' => $companyId,
                'contact_id' => $contact->getKey(),
                'decision' => 'promoted',
                'decision_reason' => null,
            ])->save();

            return ['contact' => $contact->fresh(), 'conflict' => null];
        });

        if ($result['contact'] === null) {
            throw new LogicException((string) $result['conflict']);
        }

        return $result['contact'];
    }

    public function refreshCounters(ProspectBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $persisted = ProspectBatch::query()->lockForUpdate()->findOrFail($batch->getKey());
            $this->recomputeCounters($persisted);
        });
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
        return [
            'items' => $limit,
            'free' => ['prompt' => 1, 'staging_max' => $limit],
            'calls' => ['hunter_discover' => 1],
            'reserved_units' => ['hunter' => 0.0, 'serpapi' => 0.0],
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
        }

        return $stored === $current;
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
            'candidate_contacts' => ProspectContactCandidate::query()
                ->where('prospect_batch_id', $batchId)
                ->count(),
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

    private function evidenceDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, bool|int|string>|null */
    private function candidateMetadata(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $safe = [];
        foreach (self::CANDIDATE_METADATA_KEYS as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            $candidate = $value[$key];
            if ($key === 'source_hash') {
                if (is_string($candidate) && preg_match('/^[a-f0-9]{64}$/', $candidate) === 1) {
                    $safe[$key] = $candidate;
                }

                continue;
            }
            if ($key === 'confidence') {
                $candidate = filter_var($candidate, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 100],
                ]);
                if ($candidate !== false) {
                    $safe[$key] = $candidate;
                }

                continue;
            }
            if (in_array($key, ['page', 'offset'], true)) {
                $candidate = filter_var($candidate, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 10_000],
                ]);
                if ($candidate !== false) {
                    $safe[$key] = $candidate;
                }

                continue;
            }
            if ($key === 'decision_maker' && is_bool($candidate)) {
                $safe[$key] = $candidate;

                continue;
            }
            if (! is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate !== '' && mb_strlen($candidate) <= 64
                && preg_match('/[\x00-\x1F\x7F]/', $candidate) !== 1
                && ! str_contains($candidate, '://')
                && ! str_contains($candidate, '@')) {
                $safe[$key] = $candidate;
            }
        }

        return $safe === [] ? null : $safe;
    }

    private function safeReason(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1 ? $value : null;
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

        return $safe;
    }

    private function contactName(ProspectContactCandidate $candidate, string $email): string
    {
        $name = $this->boundedNullable($candidate->name, 255);
        if ($name !== null) {
            return $name;
        }

        $local = strstr($email, '@', true);

        return $local === false || $local === '' ? $email : $local;
    }

    /** @return array<string, mixed> */
    private function candidateVerificationAttributes(ProspectContactCandidate $candidate): array
    {
        $status = strtolower(trim((string) $candidate->verification_status));
        $source = strtolower(trim((string) $candidate->verification_source));
        if (! in_array($status, self::VERIFICATION_STATUSES, true)
            || ! in_array($source, self::VERIFICATION_SOURCES, true)) {
            return [];
        }

        return [
            'email_verification_status' => $status,
            'email_verification_checked_at' => $candidate->verification_checked_at,
            'email_verification_source' => $source,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function shouldReplaceContactVerification(Contact $contact, array $candidate): bool
    {
        if ($candidate === []) {
            return false;
        }

        $hasExistingEvidence = $contact->email_verification_status !== null
            || $contact->email_verification_checked_at !== null
            || $contact->email_verification_source !== null;
        if (! $hasExistingEvidence) {
            return true;
        }

        $candidateSource = (string) ($candidate['email_verification_source'] ?? '');
        $contactSource = strtolower(trim((string) $contact->email_verification_source));
        if ($contactSource === 'manual' && $candidateSource !== 'manual') {
            return false;
        }

        $candidateCheckedAt = $candidate['email_verification_checked_at'] ?? null;
        $contactCheckedAt = $contact->email_verification_checked_at;
        if (! $candidateCheckedAt instanceof DateTimeInterface || ! $contactCheckedAt instanceof DateTimeInterface) {
            return false;
        }

        return $candidateCheckedAt->getTimestamp() > $contactCheckedAt->getTimestamp();
    }

    private function isValidCandidateEmail(string $email): bool
    {
        if ($email === '' || strlen($email) > 191 || preg_match('/[^\x21-\x7E]/', $email) === 1) {
            return false;
        }

        return ! Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:191']])->fails();
    }
}
