<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class CriteriaContactEnrichmentService
{
    public const BATCH_SAFETY_MAX = 20;

    public function __construct(
        private readonly CompanyDiscoveryService $discovery,
        private readonly DiscoveryQuotaService $quota,
    ) {}

    public function effectiveMinScore(ProspectCriteria $criteria): int
    {
        return (int) ($criteria->min_score_enrich ?? Setting::get('decouverte.min_score_enrich', 50));
    }

    public function query(ProspectCriteria $criteria): Builder
    {
        return Company::query()
            ->where('criteria_id', $criteria->id)
            ->whereNotNull('domain')
            ->whereNotNull('ai_score')
            ->where('ai_score', '>=', $this->effectiveMinScore($criteria))
            ->where(function (Builder $status): void {
                $status->whereNull('enrichment_status')
                    ->orWhere('enrichment_status', '!=', Company::ENRICHMENT_HUNTER_EMPTY);
            })
            ->whereDoesntHave('contacts');
    }

    /**
     * Automatic backlog selection is intentionally narrower than the explicit
     * criteria batch. A legitimate Hunter empty result is final for automated and
     * batch selection; the explicit one-company action remains retryable.
     */
    public function automaticQuery(
        ProspectCriteria $criteria,
        ?int $skipClaimRunId = null,
    ): Builder {
        $query = Company::query()
            ->where('criteria_id', $criteria->id)
            ->where('is_active', true)
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->where(function (Builder $status): void {
                $status->whereNull('enrichment_status')
                    ->orWhere('enrichment_status', '!=', Company::ENRICHMENT_HUNTER_EMPTY);
            })
            ->whereDoesntHave('contacts');

        // Ignore every live claim: the same parent already spent that attempt,
        // while a foreign manual/discovery owner must be allowed to finish. A
        // terminal or stale owner is absent from this subquery and can therefore
        // be reclaimed immediately by the locked admission check.
        $activeClaimOwners = DiscoveryRun::query()
            ->select('id')
            ->whereNull('finished_at')
            ->where(function (Builder $active): void {
                $active->where(function (Builder $pending): void {
                    $pending->where('status', 'pending')
                        ->where('created_at', '>', Carbon::now()->subSeconds(
                            DiscoveryRun::PENDING_STALE_AFTER_SECONDS,
                        ));
                })->orWhere(function (Builder $running): void {
                    $cutoff = Carbon::now()->subSeconds(DiscoveryRun::RUNNING_STALE_AFTER_SECONDS);
                    $running->where('status', 'running')
                        ->where(function (Builder $heartbeat) use ($cutoff): void {
                            $heartbeat->where('updated_at', '>', $cutoff)
                                ->orWhere(function (Builder $legacy) use ($cutoff): void {
                                    $legacy->whereNull('updated_at')
                                        ->where(function (Builder $fallback) use ($cutoff): void {
                                            $fallback->where('started_at', '>', $cutoff)
                                                ->orWhere(function (Builder $created) use ($cutoff): void {
                                                    $created->whereNull('started_at')
                                                        ->where('created_at', '>', $cutoff);
                                                });
                                        });
                                });
                        });
                });
            });

        $query->where(function (Builder $claim) use ($activeClaimOwners, $skipClaimRunId): void {
            $claim->whereNull('enrichment_claim_run_id')
                ->orWhere(function (Builder $reclaimable) use ($activeClaimOwners, $skipClaimRunId): void {
                    if ($skipClaimRunId !== null) {
                        $reclaimable->where('enrichment_claim_run_id', '!=', $skipClaimRunId);
                    }
                    $reclaimable->whereNotIn('enrichment_claim_run_id', $activeClaimOwners);
                });
        });

        if (! (bool) $criteria->is_active) {
            $query->whereRaw('1 = 0');
        }

        if ((bool) Setting::get('decouverte.auto_scoring', true)) {
            $query->whereNotNull('ai_score')
                ->where('ai_score', '>=', $this->effectiveMinScore($criteria));
        }

        return $query
            ->orderByRaw(
                'CASE
                    WHEN enrichment_status IN (?, ?) THEN 0
                    WHEN enrichment_status = ? THEN 1
                    ELSE 2
                END',
                [
                    Company::ENRICHMENT_SKIPPED_BUDGET,
                    Company::ENRICHMENT_SKIPPED_PROVIDER_UNAVAILABLE,
                    Company::ENRICHMENT_HUNTER_FAILED,
                ],
            )
            ->orderBy('id');
    }

    public function isEligible(Company $company, ProspectCriteria $criteria): bool
    {
        $fresh = Company::query()
            ->whereKey($company->id)
            ->where('criteria_id', $criteria->id)
            ->whereNotNull('domain')
            ->whereNotNull('ai_score')
            ->where('ai_score', '>=', $this->effectiveMinScore($criteria))
            ->whereDoesntHave('contacts')
            ->first();

        return $fresh !== null && ! $this->discovery->isBlockedDomain($fresh->domain);
    }

    /**
     * @return array{
     *     effective_min_score:int,
     *     companies_count:int,
     *     with_contacts_count:int,
     *     without_contacts_count:int,
     *     eligible_count:int,
     *     ineligible_count:int,
     *     quota_deferred_count:int,
     *     batch_deferred_count:int,
     *     success_target:int,
     *     attempt_limit:int,
     *     callable_count:int,
     *     limiting_factors:list<string>,
     *     limit_note:string
     * }
     */
    public function snapshot(ProspectCriteria $criteria): array
    {
        $eligible = $this->eligibleIds($criteria)->count();
        $companies = $criteria->companies()->count();
        $withContacts = $criteria->companies()->whereHas('contacts')->count();
        $withoutContacts = $companies - $withContacts;
        $quotaCaps = ['eligible' => $eligible];
        $daily = $this->quota->contactRemainingTodayForDisplay();
        $monthly = $this->quota->monthlyContactRemainingForDisplay();
        if ($daily !== null) {
            $quotaCaps['daily_quota'] = $daily;
        }
        if ($monthly !== null) {
            $quotaCaps['monthly_quota'] = $monthly;
        }

        $quotaCapacity = max(0, min($quotaCaps));
        $caps = [...$quotaCaps, 'batch_safety' => self::BATCH_SAFETY_MAX];
        $attemptLimit = max(0, min($caps));
        $configuredTarget = max(1, min((int) ($criteria->contact_limit ?? self::BATCH_SAFETY_MAX), self::BATCH_SAFETY_MAX));
        $successTarget = $configuredTarget;
        $factors = [];
        foreach ($caps as $name => $value) {
            if ($name !== 'eligible' && $value === $attemptLimit && $value < $eligible) {
                $factors[] = $name;
            }
        }

        return [
            'effective_min_score' => $this->effectiveMinScore($criteria),
            'companies_count' => $companies,
            'with_contacts_count' => $withContacts,
            'without_contacts_count' => $withoutContacts,
            'eligible_count' => $eligible,
            'ineligible_count' => max(0, $withoutContacts - $eligible),
            'quota_deferred_count' => max(0, $eligible - $quotaCapacity),
            'batch_deferred_count' => max(0, $quotaCapacity - $attemptLimit),
            'success_target' => $successTarget,
            'attempt_limit' => $attemptLimit,
            'callable_count' => $attemptLimit,
            'limiting_factors' => $factors,
            'limit_note' => $this->limitNote($factors),
        ];
    }

    /** @return \Illuminate\Support\Collection<int,int> */
    public function eligibleIds(ProspectCriteria $criteria)
    {
        return $this->query($criteria)
            ->get(['id', 'domain'])
            ->reject(fn (Company $company) => $this->discovery->isBlockedDomain($company->domain))
            ->pluck('id');
    }

    /** @return \Illuminate\Support\Collection<int,int> */
    public function automaticEligibleIds(
        ProspectCriteria $criteria,
        ?int $skipClaimRunId = null,
    ) {
        return $this->automaticQuery($criteria, $skipClaimRunId)
            ->select(['id', 'domain'])
            ->cursor()
            ->filter(fn (Company $company) => $this->hasValidDomain((string) $company->domain))
            ->reject(fn (Company $company) => $this->discovery->isBlockedDomain($company->domain))
            ->map(fn (Company $company): int => (int) $company->id);
    }

    public function automaticBacklogExists(
        ProspectCriteria $criteria,
        ?int $skipClaimRunId = null,
    ): bool {
        // cursor() keeps this O(1) in PHP memory and take(1) stops as soon as a
        // valid, non-blocked row is found. This check never materializes a large
        // criteria backlog merely to decide whether the job should resume.
        return $this->automaticEligibleIds($criteria, $skipClaimRunId)
            ->take(1)
            ->isNotEmpty();
    }

    private function hasValidDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        return strlen($domain) <= 253
            && str_contains($domain, '.')
            && ! str_starts_with($domain, '.')
            && ! str_ends_with($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function limitNote(array $factors): string
    {
        if ($factors === []) {
            return 'Aucune limite supplémentaire.';
        }
        $labels = [
            'batch_safety' => 'limite de sécurité par lot',
            'daily_quota' => 'quota journalier',
            'monthly_quota' => 'quota mensuel',
        ];

        return 'Nombre limité par : '.implode(', ', array_map(fn ($factor) => $labels[$factor], $factors)).'.';
    }
}
