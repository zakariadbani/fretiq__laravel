<?php

namespace App\Services\Discovery;

use App\Exceptions\InvalidEnrichmentDomainException;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompanyEnrichmentService
{
    public function __construct(
        private readonly CompanyDiscoveryService $discovery,
        private readonly DiscoveryQuotaService $quota,
        private readonly HunterEnrichmentService $hunter,
        private readonly DiscoveredContactImportService $contacts,
    ) {}

    /**
     * @return array{outcome: 'enriched'|'hunter_empty'|'provider_failed', contacts_count: int, successful_enrichments: int}
     */
    public function enrich(Company $company): array
    {
        if (empty($company->domain)) {
            throw new InvalidEnrichmentDomainException(InvalidEnrichmentDomainException::MISSING);
        }

        if ($this->discovery->isBlockedDomain($company->domain)) {
            throw new InvalidEnrichmentDomainException(InvalidEnrichmentDomainException::BLOCKED);
        }

        $run = $this->quota->reserveManualEnrichment($company);

        return $this->enrichClaimed($company, $run);
    }

    /** @return array{outcome: 'enriched'|'hunter_empty'|'provider_failed', contacts_count: int, successful_enrichments: int} */
    public function enrichForCriteria(
        int $companyId,
        ProspectCriteria $criteria,
        ?string $batchId = null,
        int $approvedAttempts = 1,
        int $successTarget = 1,
    ): array {
        [$company, $run] = $this->quota->reserveCriteriaEnrichment(
            $companyId,
            $criteria,
            $batchId,
            $approvedAttempts,
            $successTarget,
        );

        return $this->enrichClaimed($company, $run);
    }

    /**
     * Execute the provider/upsert operation for a company whose Hunter attempt was
     * already admitted and debited. The caller must never reserve another run here.
     *
     * Automatic discovery keeps its parent run active; type=manual wrappers above
     * complete their one-company child row after the owned outcome is persisted.
     *
     * @return array{outcome: 'enriched'|'hunter_empty'|'provider_failed', contacts_count: int, successful_enrichments: int}
     */
    public function enrichClaimed(
        Company $company,
        DiscoveryRun $run,
        ?int $timeoutSeconds = null,
        ?string $fallbackCountry = null,
    ): array {
        try {
            $hunterResult = $this->hunter->domainSearchResult(
                (string) $company->domain,
                10,
                $timeoutSeconds,
            );
            $enrichment = $hunterResult['data'];

            if ($hunterResult['status'] === 'provider_failed') {
                $this->persistOwnedOutcome(
                    $company,
                    $run,
                    Company::ENRICHMENT_HUNTER_FAILED,
                    null,
                );
                $this->completeManualRun($run, 0);

                return ['outcome' => 'provider_failed', 'contacts_count' => 0, 'successful_enrichments' => 0];
            }

            if ($enrichment === null) {
                $enrichment = ['organization' => null, 'industry' => null, 'country' => null, 'emails' => [], 'raw' => []];
            }

            $usableEmails = collect($enrichment['emails'] ?? [])
                ->filter(fn ($email) => ! empty($email['value'] ?? null))
                ->count();
            $requestedStatus = $usableEmails > 0
                ? Company::ENRICHMENT_ENRICHED
                : Company::ENRICHMENT_HUNTER_EMPTY;

            $persisted = $this->persistOwnedOutcome($company, $run, $requestedStatus, $enrichment, $fallbackCountry);
            $this->completeManualRun($run);

            return [
                'outcome' => $persisted['successful_enrichments'] === 1 ? 'enriched' : 'hunter_empty',
                'contacts_count' => $persisted['contacts_count'],
                'successful_enrichments' => $persisted['successful_enrichments'],
            ];
        } catch (\Throwable $e) {
            Log::error('[CompanyEnrichmentService] Enrichment failed', [
                'company_id' => $company->id,
                'domain' => $company->domain,
                'error' => $e->getMessage(),
            ]);
            $this->markOwnedUnexpectedFailure($company, $run);
            $this->failManualRun($run, $e);

            throw $e;
        } finally {
            // Owner check is repeated inside the quota service. A late response
            // from a stale run cannot clear a newer run's claim.
            $this->quota->clearEnrichmentClaim((int) $company->id, $run);
        }
    }

    private function persistOwnedOutcome(
        Company $company,
        DiscoveryRun $run,
        string $status,
        ?array $enrichment,
        ?string $fallbackCountry = null,
    ): array {
        return DB::transaction(function () use ($company, $run, $status, $enrichment, $fallbackCountry): array {
            // Criteria-owned claims serialize on criterion -> run -> company.
            // Standalone manual claims have no criterion row, so their stable
            // order is company -> run. Mirroring both admission paths prevents
            // a late provider response from deadlocking with a second claimant.
            $ownedCompany = null;
            if ($run->prospect_criteria_id !== null) {
                ProspectCriteria::whereKey($run->prospect_criteria_id)
                    ->lockForUpdate()
                    ->first();
            } else {
                /** @var Company|null $ownedCompany */
                $ownedCompany = Company::withRejected()
                    ->whereKey($company->id)
                    ->lockForUpdate()
                    ->first();
            }

            /** @var DiscoveryRun|null $ownedRun */
            $ownedRun = DiscoveryRun::whereKey($run->id)->lockForUpdate()->first();
            if ($ownedCompany === null) {
                /** @var Company|null $ownedCompany */
                $ownedCompany = Company::withRejected()
                    ->whereKey($company->id)
                    ->lockForUpdate()
                    ->first();
            }

            $runActive = $ownedRun !== null && $ownedRun->status === 'running';
            $stillOwned = $ownedCompany !== null
                && (int) $ownedCompany->enrichment_claim_run_id === (int) $run->id;

            if (! $runActive || ! $stillOwned) {
                throw new \RuntimeException('La revendication d’enrichissement n’appartient plus à cette exécution.');
            }

            $attributes = ['enrichment_status' => $status];

            if ($enrichment !== null) {
                $attributes['enrichment_data'] = $enrichment['raw'] ?? null;

                if (! empty($enrichment['industry'])) {
                    $attributes['sector'] = $enrichment['industry'];
                }

                $resolvedCountry = $this->mapIso2($enrichment['country'] ?? null) ?? $this->mapIso2($fallbackCountry);
                if ($resolvedCountry !== null && empty($ownedCompany->country)) {
                    $attributes['country'] = $resolvedCountry;
                }
            }

            $ownedCompany->forceFill($attributes)->save();

            $import = $enrichment === null
                ? new ContactImportResult
                : $this->contacts->import($ownedCompany, array_map(
                    static fn (array $row): array => $row + ['source_url' => (string) $ownedCompany->domain, 'source' => 'hunter_company_enrichment'],
                    $enrichment['emails'] ?? [],
                ));
            $created = $import->created;

            // Hunter can return an address already owned by another company (or
            // a soft-deleted globally unique address). Treat that as no usable
            // contact for this company so automatic discovery does not requeue
            // the same row repeatedly within the parent run.
            $successful = $status === Company::ENRICHMENT_ENRICHED
                && $ownedCompany->contacts()->exists();

            if ($status === Company::ENRICHMENT_ENRICHED && ! $successful) {
                $ownedCompany->forceFill([
                    'enrichment_status' => Company::ENRICHMENT_HUNTER_EMPTY,
                ])->save();
            }

            $ownedRun->contacts_count = (int) $ownedRun->contacts_count + $created;
            if ($successful) {
                $ownedRun->successful_enrichments = (int) $ownedRun->successful_enrichments + 1;
            }
            if ($ownedRun->type === 'discovery') {
                if ($status === Company::ENRICHMENT_HUNTER_FAILED) {
                    // Persist the circuit on the owning parent in the same
                    // transaction as the provider-failure outcome. A worker
                    // crash cannot make the retry call Hunter again, and an
                    // unrelated manual failure cannot contaminate this run.
                    $ownedRun->hunter_circuit_open = true;
                }
            }
            $ownedRun->save();

            return [
                'contacts_count' => $created,
                'successful_enrichments' => $successful ? 1 : 0,
            ];
        });
    }

    private function completeManualRun(DiscoveryRun $run, int $contacts = 0): void
    {
        if ($run->type !== 'manual') {
            return;
        }

        DiscoveryRun::whereKey($run->id)->update([
            'status' => 'completed',
            'companies_count' => 0,
            'finished_at' => now(),
        ]);
    }

    private function failManualRun(DiscoveryRun $run, \Throwable $error): void
    {
        if ($run->type !== 'manual') {
            return;
        }

        DiscoveryRun::whereKey($run->id)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'error' => Str::limit($error->getMessage(), 1000),
                'finished_at' => now(),
            ]);
    }

    /**
     * Do not leave an orphaned "enriching" badge when provider/upsert code throws.
     * The claim predicate makes this safe against a newer run reclaiming the row.
     *
     * Writes enrichment_status to a value it may already hold (retry landing on
     * the same status). Its affected-row count is therefore never a valid
     * ownership signal — this write is fire-and-forget, unread by any caller.
     */
    private function markOwnedUnexpectedFailure(Company $company, DiscoveryRun $run): void
    {
        Company::withRejected()
            ->whereKey($company->id)
            ->where('enrichment_claim_run_id', $run->id)
            ->update([
                'enrichment_status' => Company::ENRICHMENT_HUNTER_FAILED,
                'updated_at' => now(),
            ]);
    }

    private function mapIso2(?string $country): ?string
    {
        if ($country === null || $country === '') {
            return null;
        }
        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        return [
            'france' => 'FR', 'maroc' => 'MA', 'morocco' => 'MA', 'espagne' => 'ES', 'spain' => 'ES',
            'belgique' => 'BE', 'belgium' => 'BE', 'allemagne' => 'DE', 'germany' => 'DE',
            'italie' => 'IT', 'italy' => 'IT', 'portugal' => 'PT', 'pays-bas' => 'NL',
            'netherlands' => 'NL', 'suisse' => 'CH', 'switzerland' => 'CH', 'sénégal' => 'SN',
            'senegal' => 'SN', "côte d'ivoire" => 'CI', 'ivory coast' => 'CI', 'tunisie' => 'TN',
            'tunisia' => 'TN', 'algérie' => 'DZ', 'algeria' => 'DZ', 'chine' => 'CN', 'china' => 'CN',
            'états-unis' => 'US', 'united states' => 'US', 'usa' => 'US', 'royaume-uni' => 'GB',
            'united kingdom' => 'GB', 'uk' => 'GB', 'turquie' => 'TR', 'turkey' => 'TR',
            'pologne' => 'PL', 'poland' => 'PL', 'roumanie' => 'RO', 'romania' => 'RO',
        ][strtolower(trim($country))] ?? null;
    }
}
