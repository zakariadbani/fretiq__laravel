<?php

namespace App\Services\Prospecting;

use App\Data\Prospecting\RecoveryPlan;
use App\Data\Prospecting\RecoveryResult;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectContactCandidate;
use App\Services\Discovery\DomainCanonicalizer;
use App\Support\EmailKind;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class ExistingProspectingDataRecoveryService
{
    private const VERSION = 1;

    public function __construct(
        private readonly HunterVerificationStatusNormalizer $verificationStatuses,
        private readonly HunterCompanySizeNormalizer $companySizes,
        private readonly DomainCanonicalizer $domains,
        private readonly ProspectBatchService $batches,
    ) {}

    public function preview(): RecoveryPlan
    {
        $companies = Company::withRejected()->orderBy('id')->get();
        $contacts = Contact::withTrashed()->orderBy('id')->get();
        $runs = DiscoveryRun::query()
            ->whereNotNull('candidates_snapshot')
            ->orderBy('id')
            ->get();

        $globalContactEmails = [];
        $contactsByCompanyEmail = [];

        foreach ($contacts as $contact) {
            $email = $this->normalizeEmail($contact->email);

            if ($email === null) {
                continue;
            }

            $globalContactEmails[$email] = true;
            $contactsByCompanyEmail[(int) $contact->company_id][$email] = $contact;
        }

        $companiesByHost = [];
        $companiesByRegistrable = [];

        foreach ($companies as $company) {
            $canonical = is_string($company->domain)
                ? $this->domains->canonicalize($company->domain)
                : null;

            if ($canonical === null) {
                continue;
            }

            $companiesByHost[$canonical->host][] = $company;
            $companiesByRegistrable[$canonical->registrableDomain][] = [
                'id' => (int) $company->getKey(),
                'host' => $canonical->host,
            ];
        }

        $verificationChangesByContact = [];
        $companyChangesByKey = [];
        $contactCandidatesByEmail = [];
        $sourceEvidenceHashes = [];
        $counts = $this->emptyCounts();

        foreach ($companies as $company) {
            $payload = $company->enrichment_data;

            if (! is_array($payload)) {
                continue;
            }

            $payloadHash = $this->payloadHash($payload);
            $sourceEvidenceHashes[] = $this->evidenceHash(
                'company',
                (int) $company->getKey(),
                $payloadHash,
            );

            $domainEmails = data_get($payload, 'domain_search.emails', []);

            if (is_array($domainEmails)) {
                foreach ($domainEmails as $emailData) {
                    if (! is_array($emailData)) {
                        continue;
                    }

                    $email = $this->normalizeEmail($emailData['value'] ?? null);
                    $contact = $email === null
                        ? null
                        : ($contactsByCompanyEmail[(int) $company->getKey()][$email] ?? null);

                    if ($contact === null
                        || ! $this->isBlank($contact->email_verification_status)
                        || isset($verificationChangesByContact[(int) $contact->getKey()])) {
                        continue;
                    }

                    $status = $this->verificationStatuses->normalize($emailData);

                    if ($status === null) {
                        continue;
                    }

                    $checkedAt = $this->verificationStatuses->checkedAt($emailData)
                        ?? $this->immutableDate($company->enrichment_attempted_at)
                        ?? $this->immutableDate($company->updated_at);

                    $verificationChangesByContact[(int) $contact->getKey()] = [
                        'entity_type' => 'contact',
                        'entity_id' => (int) $contact->getKey(),
                        'contact_id' => (int) $contact->getKey(),
                        'company_id' => (int) $company->getKey(),
                        'field' => 'email_verification_status',
                        'old' => $contact->email_verification_status,
                        'new' => $status,
                        'checked_at' => $checkedAt?->toISOString(),
                        'verification_source' => 'hunter',
                        'source_hash' => $payloadHash,
                    ];
                }
            }

            $siteEmails = data_get($payload, 'company.site.emailAddresses', []);

            if (is_array($siteEmails)) {
                foreach ($siteEmails as $rawEmail) {
                    $emailValue = is_array($rawEmail)
                        ? ($rawEmail['value'] ?? $rawEmail['email'] ?? null)
                        : $rawEmail;
                    $email = $this->normalizeEmail($emailValue);

                    if ($email === null
                        || isset($globalContactEmails[$email])
                        || isset($contactCandidatesByEmail[$email])) {
                        continue;
                    }

                    $contactCandidatesByEmail[$email] = [
                        'company_id' => (int) $company->getKey(),
                        'email' => $email,
                        'normalized_email' => $email,
                        'source' => 'hunter_company_enrichment',
                        'email_kind' => EmailKind::classify($email),
                        'source_hash' => $payloadHash,
                    ];
                    $counts['company_enrichment_emails']++;
                }
            }

            if ($this->isBlank($company->phone)) {
                $phone = $this->boundedString(data_get($payload, 'company.phone'), 50);

                if ($phone !== null) {
                    $this->planCompanyChange(
                        $companyChangesByKey,
                        $company,
                        'phone',
                        $phone,
                        $payloadHash,
                        'hunter_company_enrichment',
                    );
                    $counts['hunter_phones']++;
                }
            }

            if ($this->isBlank($company->estimated_size)) {
                $size = $this->companySizes->normalize(
                    data_get($payload, 'company.metrics.employeesRange'),
                ) ?? $this->companySizes->normalize(
                    data_get($payload, 'company.metrics.employees'),
                );

                if ($size !== null) {
                    $this->planCompanyChange(
                        $companyChangesByKey,
                        $company,
                        'estimated_size',
                        $size,
                        $payloadHash,
                        'hunter_company_enrichment',
                    );
                    $counts['hunter_sizes']++;
                }
            }
        }

        $snapshotItemsByHost = [];

        foreach ($runs as $run) {
            $snapshot = $run->candidates_snapshot;

            if (! is_array($snapshot)) {
                continue;
            }

            $snapshotHash = $this->payloadHash($snapshot);
            $sourceEvidenceHashes[] = $this->evidenceHash(
                'discovery_run',
                (int) $run->getKey(),
                $snapshotHash,
            );

            foreach (array_values($snapshot) as $snapshotIndex => $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $canonical = is_string($candidate['domain'] ?? null)
                    ? $this->domains->canonicalize($candidate['domain'])
                    : null;

                if ($canonical !== null) {
                    foreach ($companiesByHost[$canonical->host] ?? [] as $company) {
                        $companyId = (int) $company->getKey();

                        if ($this->isBlank($company->phone)
                            && ! isset($companyChangesByKey[$companyId.'|phone'])) {
                            $phone = $this->boundedString($candidate['phone'] ?? null, 50);

                            if ($phone !== null) {
                                $this->planCompanyChange(
                                    $companyChangesByKey,
                                    $company,
                                    'phone',
                                    $phone,
                                    $snapshotHash,
                                    'failed_snapshot',
                                );
                                $counts['snapshot_phones']++;
                            }
                        }

                        if ($this->isBlank($company->sector)
                            && ! isset($companyChangesByKey[$companyId.'|sector'])) {
                            $sector = $this->boundedString($candidate['sector_hint'] ?? null, 100);

                            if ($sector !== null) {
                                $this->planCompanyChange(
                                    $companyChangesByKey,
                                    $company,
                                    'sector',
                                    $sector,
                                    $snapshotHash,
                                    'failed_snapshot',
                                );
                                $counts['snapshot_sectors']++;
                            }
                        }

                        if ($this->isGenericCompanyName($company)
                            && ! isset($companyChangesByKey[$companyId.'|name'])) {
                            $title = $this->boundedString($candidate['title'] ?? null, 255);

                            if ($title !== null) {
                                $this->planCompanyChange(
                                    $companyChangesByKey,
                                    $company,
                                    'name',
                                    $title,
                                    $snapshotHash,
                                    'failed_snapshot',
                                );
                                $counts['snapshot_names']++;
                            }
                        }
                    }
                }

                if ($run->status !== 'failed'
                    || $snapshotIndex < max(0, (int) $run->consumed)
                    || $canonical === null
                    || $canonical->isPlatform
                    || isset($companiesByHost[$canonical->host])) {
                    continue;
                }

                $sourceReference = [
                    'run_id' => (int) $run->getKey(),
                    'snapshot_index' => $snapshotIndex,
                ];

                if (isset($snapshotItemsByHost[$canonical->host])) {
                    $snapshotItemsByHost[$canonical->host]['sources'][] = $sourceReference;

                    continue;
                }

                $conflicts = array_values(array_filter(
                    $companiesByRegistrable[$canonical->registrableDomain] ?? [],
                    static fn (array $existing): bool => $existing['host'] !== $canonical->host,
                ));
                usort($conflicts, static fn (array $left, array $right): int => [
                    $left['host'], $left['id'],
                ] <=> [
                    $right['host'], $right['id'],
                ]);

                $snapshotItemsByHost[$canonical->host] = [
                    'host' => $canonical->host,
                    'registrable_domain' => $canonical->registrableDomain,
                    'title' => $this->boundedString($candidate['title'] ?? null, 255),
                    'domain_reason' => $conflicts === []
                        ? 'recovered_failed_snapshot'
                        : 'recovered_registrable_collision',
                    'sources' => [$sourceReference],
                    'registrable_collision' => $conflicts === [] ? null : [
                        'company_ids' => array_values(array_map(
                            static fn (array $conflict): int => $conflict['id'],
                            $conflicts,
                        )),
                        'hosts' => array_values(array_unique(array_map(
                            static fn (array $conflict): string => $conflict['host'],
                            $conflicts,
                        ))),
                    ],
                ];
            }
        }

        $companyChanges = array_values($companyChangesByKey);
        $verificationChanges = array_values($verificationChangesByContact);
        $contactCandidates = array_values($contactCandidatesByEmail);
        $snapshotItems = array_values($snapshotItemsByHost);

        foreach ($verificationChanges as $change) {
            $counts['verification_statuses']++;
            $countKey = 'verification_'.$change['new'];

            if (array_key_exists($countKey, $counts)) {
                $counts[$countKey]++;
            }
        }

        usort($verificationChanges, static fn (array $left, array $right): int => [
            $left['entity_type'], $left['entity_id'], $left['field'],
        ] <=> [
            $right['entity_type'], $right['entity_id'], $right['field'],
        ]);
        usort($companyChanges, static fn (array $left, array $right): int => [
            $left['entity_type'], $left['entity_id'], $left['field'],
        ] <=> [
            $right['entity_type'], $right['entity_id'], $right['field'],
        ]);
        usort($contactCandidates, static fn (array $left, array $right): int => [
            $left['company_id'], $left['normalized_email'],
        ] <=> [
            $right['company_id'], $right['normalized_email'],
        ]);
        usort($snapshotItems, static fn (array $left, array $right): int => $left['host'] <=> $right['host']);

        foreach ($snapshotItems as &$snapshotItem) {
            usort($snapshotItem['sources'], static fn (array $left, array $right): int => [
                $left['run_id'], $left['snapshot_index'],
            ] <=> [
                $right['run_id'], $right['snapshot_index'],
            ]);
        }
        unset($snapshotItem);

        sort($sourceEvidenceHashes, SORT_STRING);
        $counts['company_changes'] = count($companyChanges);
        $counts['failed_snapshot_domains'] = count($snapshotItems);

        $fingerprint = hash('sha256', $this->canonicalJson([
            'recovery_version' => self::VERSION,
            'source_evidence_hashes' => $sourceEvidenceHashes,
        ]));

        return new RecoveryPlan(
            fingerprint: $fingerprint,
            verificationChanges: $verificationChanges,
            companyChanges: $companyChanges,
            contactCandidates: $contactCandidates,
            snapshotItems: $snapshotItems,
            counts: $counts,
            sourceEvidenceHashes: $sourceEvidenceHashes,
        );
    }

    public function apply(RecoveryPlan $plan): RecoveryResult
    {
        $evidenceHashes = array_values($plan->sourceEvidenceHashes);
        sort($evidenceHashes, SORT_STRING);
        $expectedFingerprint = hash('sha256', $this->canonicalJson([
            'recovery_version' => self::VERSION,
            'source_evidence_hashes' => $evidenceHashes,
        ]));

        if (preg_match('/^[a-f0-9]{64}$/', $plan->fingerprint) !== 1
            || ! hash_equals($expectedFingerprint, $plan->fingerprint)) {
            throw new LogicException('recovery_plan_fingerprint_invalid');
        }

        $counts = $this->sanitizedCounts($plan->counts);

        return DB::transaction(function () use ($plan, $counts): RecoveryResult {
            $now = now();

            ProspectBatch::query()->insertOrIgnore([[
                'source_fingerprint' => $plan->fingerprint,
                'source_type' => 'recovery',
                'name' => 'Récupération des données existantes',
                'created_by' => null,
                'status' => 'running',
                'quality_preset' => 'balanced',
                'quality_settings' => json_encode([], JSON_THROW_ON_ERROR),
                'source_options' => json_encode([
                    'recovery_version' => self::VERSION,
                    'fingerprint' => $plan->fingerprint,
                    'counts' => $counts,
                ], JSON_THROW_ON_ERROR),
                'estimate' => json_encode([
                    'provider_calls' => 0,
                    'provider_units' => 0,
                ], JSON_THROW_ON_ERROR),
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);

            $batch = ProspectBatch::query()
                ->where('source_fingerprint', $plan->fingerprint)
                ->lockForUpdate()
                ->firstOrFail();
            $created = $batch->recovery_audit === null && $batch->items()->doesntExist();

            if (! $created) {
                return new RecoveryResult($batch, $counts, true);
            }

            $this->stagePlan($batch, $plan);

            $audit = [];
            $this->applyCompanyChanges($plan, $audit);
            $this->applyVerificationChanges($plan, $audit);

            $batch->forceFill([
                'recovery_audit' => [
                    'version' => self::VERSION,
                    'changes' => $audit,
                ],
            ])->save();
            $this->recomputeCounters($batch);

            return new RecoveryResult($batch->fresh(), $counts, false);
        }, attempts: 3);
    }

    private function stagePlan(ProspectBatch $batch, RecoveryPlan $plan): void
    {
        $stagedCandidatesByCompany = [];
        $currentContactEmails = [];

        foreach (Contact::withTrashed()->pluck('email') as $contactEmail) {
            $normalized = $this->normalizeEmail($contactEmail);

            if ($normalized !== null) {
                $currentContactEmails[$normalized] = true;
            }
        }

        foreach ($plan->contactCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $companyId = filter_var(
                $candidate['company_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $email = $this->normalizeEmail($candidate['normalized_email'] ?? $candidate['email'] ?? null);
            $sourceHash = $this->validatedSourceHash($candidate['source_hash'] ?? null);

            if ($companyId === false || $email === null || $sourceHash === null) {
                continue;
            }

            if (isset($currentContactEmails[$email])) {
                continue;
            }

            $stagedCandidatesByCompany[(int) $companyId][$email] = [
                'email' => $email,
                'source' => 'hunter_company_enrichment',
                'email_kind' => EmailKind::classify($email),
                'decision_reason' => 'recovered_company_enrichment',
                'metadata' => ['source_hash' => $sourceHash],
            ];
        }

        $affectedCompanyIds = [];
        $sourceHashesByCompany = [];

        foreach ([$plan->verificationChanges, $plan->companyChanges] as $changes) {
            foreach ($changes as $change) {
                if (! is_array($change)) {
                    continue;
                }

                $companyId = filter_var(
                    $change['company_id'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]],
                );

                if ($companyId === false) {
                    continue;
                }

                $affectedCompanyIds[(int) $companyId] = true;
                $sourceHash = $this->validatedSourceHash($change['source_hash'] ?? null);

                if ($sourceHash !== null) {
                    $sourceHashesByCompany[(int) $companyId][$sourceHash] = true;
                }
            }
        }

        foreach ($stagedCandidatesByCompany as $companyId => $candidates) {
            $affectedCompanyIds[$companyId] = true;

            foreach ($candidates as $candidate) {
                $sourceHashesByCompany[$companyId][$candidate['metadata']['source_hash']] = true;
            }
        }

        $companyIds = array_keys($affectedCompanyIds);
        sort($companyIds, SORT_NUMERIC);
        $rows = [];
        $rowDetails = [];
        $companyItems = [];
        $rowNumber = 0;

        foreach ($companyIds as $companyId) {
            $company = Company::withRejected()->find($companyId);

            if ($company === null) {
                continue;
            }

            $canonical = is_string($company->domain)
                ? $this->domains->canonicalize($company->domain)
                : null;
            $companyName = $this->boundedString($company->name, 255)
                ?? $canonical?->host
                ?? 'Entreprise #'.$companyId;
            $sourceHashes = array_keys($sourceHashesByCompany[$companyId] ?? []);
            sort($sourceHashes, SORT_STRING);
            $candidateCount = count($stagedCandidatesByCompany[$companyId] ?? []);
            $rowNumber++;
            $rows[] = [
                'row_number' => $rowNumber,
                'original_input' => $canonical?->host ?? $companyName,
                'company_name' => $companyName,
                'country' => $this->countryCode($company->country),
                'provided_domain' => $canonical?->host,
                'source_metadata' => [
                    'source' => 'local_recovery',
                    'source_hashes' => $sourceHashes,
                    'candidate_count' => $candidateCount,
                    'high_volume' => $candidateCount >= 50,
                ],
            ];
            $rowDetails[$rowNumber] = [
                'selected_domain' => $canonical?->host,
                'registrable_domain' => $canonical?->registrableDomain,
                'domain_confidence' => $canonical === null ? null : 100,
                'domain_reason' => 'recovered_local_payload',
                'status' => $candidateCount > 0 ? 'review' : 'ready',
                'company_id' => $companyId,
            ];
            $companyItems[$companyId] = $rowNumber;
        }

        [$companiesByHost, $companiesByRegistrable] = $this->currentDomainIndexes();

        foreach ($plan->snapshotItems as $snapshotItem) {
            if (! is_array($snapshotItem)) {
                continue;
            }

            $canonical = is_string($snapshotItem['host'] ?? null)
                ? $this->domains->canonicalize($snapshotItem['host'])
                : null;

            if ($canonical === null || $canonical->isPlatform || isset($companiesByHost[$canonical->host])) {
                continue;
            }

            $sources = $this->validatedSourceReferences($snapshotItem['sources'] ?? null);

            if ($sources === []) {
                continue;
            }

            $conflicts = array_values(array_filter(
                $companiesByRegistrable[$canonical->registrableDomain] ?? [],
                static fn (array $existing): bool => $existing['host'] !== $canonical->host,
            ));
            usort($conflicts, static fn (array $left, array $right): int => [
                $left['host'], $left['id'],
            ] <=> [
                $right['host'], $right['id'],
            ]);
            $collision = $conflicts === [] ? null : [
                'company_ids' => array_values(array_map(
                    static fn (array $conflict): int => $conflict['id'],
                    $conflicts,
                )),
                'hosts' => array_values(array_unique(array_map(
                    static fn (array $conflict): string => $conflict['host'],
                    $conflicts,
                ))),
            ];
            $title = $this->boundedString($snapshotItem['title'] ?? null, 255) ?? $canonical->host;
            $rowNumber++;
            $rows[] = [
                'row_number' => $rowNumber,
                'original_input' => $title,
                'company_name' => $title,
                'provided_domain' => $canonical->host,
                'source_metadata' => [
                    'sources' => $sources,
                    'registrable_collision' => $collision,
                ],
            ];
            $rowDetails[$rowNumber] = [
                'selected_domain' => $canonical->host,
                'registrable_domain' => $canonical->registrableDomain,
                'domain_confidence' => 0,
                'domain_reason' => $collision === null
                    ? 'recovered_failed_snapshot'
                    : 'recovered_registrable_collision',
                'status' => 'review',
                'company_id' => null,
            ];
        }

        if ($rows !== []) {
            $this->batches->stageItems($batch, $rows);
        }

        foreach ($rowDetails as $number => $details) {
            ProspectBatchItem::query()
                ->where('prospect_batch_id', $batch->getKey())
                ->where('row_number', $number)
                ->update($details + ['processed_at' => now(), 'updated_at' => now()]);
        }

        foreach ($stagedCandidatesByCompany as $companyId => $candidates) {
            $itemRow = $companyItems[$companyId] ?? null;

            if ($itemRow === null) {
                continue;
            }

            $item = ProspectBatchItem::query()
                ->where('prospect_batch_id', $batch->getKey())
                ->where('row_number', $itemRow)
                ->first();

            if ($item === null) {
                continue;
            }

            $this->batches->stageContactCandidates($batch, $item, array_values($candidates));
        }
    }

    /** @param array<int, array<string, mixed>> $audit */
    private function applyCompanyChanges(RecoveryPlan $plan, array &$audit): void
    {
        foreach ($plan->companyChanges as $change) {
            if (! is_array($change)) {
                continue;
            }

            $companyId = filter_var(
                $change['company_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $field = is_string($change['field'] ?? null) ? $change['field'] : '';
            $sourceHash = $this->validatedSourceHash($change['source_hash'] ?? null);

            if ($companyId === false
                || ! in_array($field, ['phone', 'estimated_size', 'sector', 'name'], true)
                || $sourceHash === null) {
                continue;
            }

            $company = Company::withRejected()->lockForUpdate()->find($companyId);

            if ($company === null) {
                continue;
            }

            $oldValue = $company->getAttribute($field);
            $eligible = $field === 'name'
                ? $this->isGenericCompanyName($company)
                : $this->isBlank($oldValue);

            if (! $eligible) {
                continue;
            }

            $newValue = $field === 'estimated_size'
                ? $this->allowedCompanySize($change['new'] ?? null)
                : $this->boundedString(
                    $change['new'] ?? null,
                    match ($field) {
                        'phone' => 50,
                        'sector' => 100,
                        default => 255,
                    },
                );

            if ($newValue === null) {
                continue;
            }

            $company->forceFill([$field => $newValue])->save();
            $audit[] = $this->auditEntry(
                'company',
                (int) $company->getKey(),
                $field,
                $oldValue,
                $newValue,
                $sourceHash,
            );
        }
    }

    /** @param array<int, array<string, mixed>> $audit */
    private function applyVerificationChanges(RecoveryPlan $plan, array &$audit): void
    {
        $allowedStatuses = ['valid', 'accept_all', 'invalid', 'unknown', 'disposable', 'webmail'];

        foreach ($plan->verificationChanges as $change) {
            if (! is_array($change)) {
                continue;
            }

            $contactId = filter_var(
                $change['contact_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $companyId = filter_var(
                $change['company_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $status = is_string($change['new'] ?? null)
                ? strtolower(trim($change['new']))
                : '';
            $sourceHash = $this->validatedSourceHash($change['source_hash'] ?? null);

            if ($contactId === false
                || $companyId === false
                || ! in_array($status, $allowedStatuses, true)
                || $sourceHash === null) {
                continue;
            }

            $contact = Contact::withTrashed()->lockForUpdate()->find($contactId);

            if ($contact === null
                || (int) $contact->company_id !== (int) $companyId
                || ! $this->isBlank($contact->email_verification_status)) {
                continue;
            }

            $updates = ['email_verification_status' => $status];
            $audit[] = $this->auditEntry(
                'contact',
                (int) $contact->getKey(),
                'email_verification_status',
                $contact->email_verification_status,
                $status,
                $sourceHash,
            );

            if ($this->isBlank($contact->email_verification_checked_at)) {
                $checkedAt = $this->parseEvidenceDate($change['checked_at'] ?? null);

                if ($checkedAt !== null) {
                    $updates['email_verification_checked_at'] = $checkedAt;
                    $audit[] = $this->auditEntry(
                        'contact',
                        (int) $contact->getKey(),
                        'email_verification_checked_at',
                        null,
                        $checkedAt,
                        $sourceHash,
                    );
                }
            }

            if ($this->isBlank($contact->email_verification_source)) {
                $source = $change['verification_source'] ?? null;

                if ($source === 'hunter') {
                    $updates['email_verification_source'] = $source;
                    $audit[] = $this->auditEntry(
                        'contact',
                        (int) $contact->getKey(),
                        'email_verification_source',
                        null,
                        $source,
                        $sourceHash,
                    );
                }
            }

            $contact->forceFill($updates)->save();
        }
    }

    /** @return array<string, mixed> */
    private function auditEntry(
        string $entityType,
        int $entityId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        string $sourceHash,
    ): array {
        return [
            'key' => hash('sha256', implode('|', [
                'recovery:v'.self::VERSION,
                $entityType,
                $entityId,
                $field,
                $sourceHash,
            ])),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'old' => $this->auditValue($oldValue),
            'new' => $this->auditValue($newValue),
            'source_hash' => $sourceHash,
            'applied_at' => now()->toISOString(),
        ];
    }

    private function recomputeCounters(ProspectBatch $batch): void
    {
        $items = ProspectBatchItem::query()->where('prospect_batch_id', $batch->getKey());
        $terminal = ['review', 'ready', 'promoted', 'failed', 'skipped'];

        $batch->forceFill([
            'total_items' => (clone $items)->count(),
            'processed_items' => (clone $items)->whereIn('status', $terminal)->count(),
            'review_items' => (clone $items)->where('status', 'review')->count(),
            'failed_items' => (clone $items)->where('status', 'failed')->count(),
            'promoted_companies' => (clone $items)->where('status', 'promoted')->count(),
            'candidate_contacts' => ProspectContactCandidate::query()
                ->where('prospect_batch_id', $batch->getKey())
                ->count(),
        ])->save();
    }

    /**
     * @return array{
     *     0: array<string, array<int, array{id:int,host:string}>>,
     *     1: array<string, array<int, array{id:int,host:string}>>
     * }
     */
    private function currentDomainIndexes(): array
    {
        $byHost = [];
        $byRegistrable = [];

        foreach (Company::withRejected()->orderBy('id')->get(['id', 'domain']) as $company) {
            $canonical = is_string($company->domain)
                ? $this->domains->canonicalize($company->domain)
                : null;

            if ($canonical === null) {
                continue;
            }

            $entry = ['id' => (int) $company->getKey(), 'host' => $canonical->host];
            $byHost[$canonical->host][] = $entry;
            $byRegistrable[$canonical->registrableDomain][] = $entry;
        }

        return [$byHost, $byRegistrable];
    }

    private function countryCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    private function validatedSourceHash(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1
            ? $value
            : null;
    }

    /** @return array<int, array{run_id:int,snapshot_index:int}> */
    private function validatedSourceReferences(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $references = [];

        foreach ($value as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $runId = filter_var(
                $reference['run_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            $snapshotIndex = filter_var(
                $reference['snapshot_index'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]],
            );

            if ($runId === false || $snapshotIndex === false) {
                continue;
            }

            $references[$runId.'|'.$snapshotIndex] = [
                'run_id' => (int) $runId,
                'snapshot_index' => (int) $snapshotIndex,
            ];
        }

        $references = array_values($references);
        usort($references, static fn (array $left, array $right): int => [
            $left['run_id'], $left['snapshot_index'],
        ] <=> [
            $right['run_id'], $right['snapshot_index'],
        ]);

        return $references;
    }

    private function allowedCompanySize(mixed $value): ?string
    {
        return is_string($value) && in_array($value, [
            '1-10', '11-50', '51-200', '201-500', '500+',
        ], true) ? $value : null;
    }

    private function parseEvidenceDate(mixed $value): ?CarbonImmutable
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value);
            }

            return is_string($value) && trim($value) !== ''
                ? CarbonImmutable::parse($value)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function auditValue(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->toISOString()
            : $value;
    }

    /** @param array<string, mixed> $counts
     * @return array<string, int>
     */
    private function sanitizedCounts(array $counts): array
    {
        $safe = $this->emptyCounts();

        foreach ($safe as $key => $default) {
            $value = filter_var(
                $counts[$key] ?? $default,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]],
            );
            $safe[$key] = $value === false ? $default : (int) $value;
        }

        return $safe;
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [
            'verification_statuses' => 0,
            'verification_valid' => 0,
            'verification_accept_all' => 0,
            'verification_invalid' => 0,
            'verification_unknown' => 0,
            'verification_disposable' => 0,
            'verification_webmail' => 0,
            'company_enrichment_emails' => 0,
            'failed_snapshot_domains' => 0,
            'hunter_phones' => 0,
            'hunter_sizes' => 0,
            'snapshot_phones' => 0,
            'snapshot_sectors' => 0,
            'snapshot_names' => 0,
            'company_changes' => 0,
            'provider_calls' => 0,
        ];
    }

    /** @param array<string, array<string, mixed>> $changes */
    private function planCompanyChange(
        array &$changes,
        Company $company,
        string $field,
        string $newValue,
        string $sourceHash,
        string $source,
    ): void {
        $changes[(int) $company->getKey().'|'.$field] = [
            'entity_type' => 'company',
            'entity_id' => (int) $company->getKey(),
            'company_id' => (int) $company->getKey(),
            'field' => $field,
            'old' => $company->getAttribute($field),
            'new' => $newValue,
            'source_hash' => $sourceHash,
            'source' => $source,
        ];
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $email = strtolower(trim($value));

        if ($email === ''
            || strlen($email) > 191
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function boundedString(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === ''
            || mb_strlen($value) > $maxLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function isGenericCompanyName(Company $company): bool
    {
        if (! is_string($company->domain) || $this->isBlank($company->domain)) {
            return false;
        }

        return Str::lower(trim((string) $company->name)) === Str::lower(trim($company->domain));
    }

    private function immutableDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::instance($value);
    }

    /** @param array<mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function evidenceHash(string $type, int $id, string $payloadHash): string
    {
        return hash('sha256', $type.'|'.$id.'|'.$payloadHash);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalizeValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeValue($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeValue($item);
        }

        return $value;
    }
}
