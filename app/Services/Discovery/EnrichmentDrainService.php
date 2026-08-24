<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Exceptions\EnrichmentInFlightException;
use App\Exceptions\InvalidEnrichmentDomainException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * EnrichmentDrainService — shared enrichment/verification "drain" loop.
 *
 * Burns leftover Hunter quota against the backlog of never-enriched (or
 * previously-failed) companies before a monthly plan reset wipes it. The loop
 * is cursor-based so a queued job (Tier B) can resume from a persisted position
 * without re-charging companies it already attempted.
 *
 * ponytail: ambiguous provider failure (Hunter charged but the DB write failed)
 * is NOT deduped on the manual enrich path — a retry gets a fresh idempotency
 * key. Rare, and a watched CLI run surfaces it in the failed tally. Not worth
 * threading a stable ProviderCallContext through the shared service for a
 * throwaway drain; per-call context if this ever becomes a recurring job.
 */
class EnrichmentDrainService
{
    /** Hunter units per company enrichment: domain-search 1.0 + company-find 0.2. */
    public const COMPANY_UNIT_COST = 1.2;

    public function __construct(
        private readonly CompanyEnrichmentService $enrichment,
        private readonly ContactVerificationBatchService $verificationBatch,
    ) {}

    /**
     * Companies that still need a Hunter attempt: have a domain, own no
     * (non-trashed) contacts, and were either never enriched (NULL) or left in
     * a retryable failure state.
     *
     * The whereNull(...)->orWhereIn(...) closure is required: a plain
     * whereIn([null, 'hunter_failed']) never matches SQL NULL, silently skipping
     * the entire never-enriched backlog. Contact has SoftDeletes, so
     * whereDoesntHave('contacts') excludes only non-trashed contacts.
     */
    public function eligibleCompaniesQuery(bool $includeEmpty = false): Builder
    {
        $statuses = [Company::ENRICHMENT_HUNTER_FAILED];
        if ($includeEmpty) {
            $statuses[] = Company::ENRICHMENT_HUNTER_EMPTY;
        }

        return Company::query()
            ->whereNotNull('domain')
            ->where(fn (Builder $q) => $q->whereNull('enrichment_status')->orWhereIn('enrichment_status', $statuses))
            ->whereDoesntHave('contacts')
            ->orderBy('id');
    }

    /**
     * Enrich eligible companies one at a time until a stop condition is hit.
     *
     * The cursor is mandatory even for a single CLI run: a company that stays
     * eligible after an attempt (a persistent hunter_empty under --include-empty,
     * a hunter_failed, or a skipped blocked domain) would be re-fetched and
     * re-charged forever without advancing past its id. The cursor is advanced
     * after EVERY fetch, before the outcome is known.
     *
     * @param  callable(Company, string):void|null  $onEach  Called once per company that hit Hunter (progress bar).
     * @return array{last_id: ?int, processed: int, enriched: int, empty: int, failed: int, stopped_reason: ?string, exhausted: bool}
     */
    public function drainCompanies(
        int $maxCompanies,
        ?int $afterId = null,
        ?int $deadlineTs = null,
        ?callable $onEach = null,
        bool $includeEmpty = false,
    ): array {
        $cursor = $afterId ?? 0;
        $processed = 0;
        $enriched = 0;
        $empty = 0;
        $failed = 0;
        $stoppedReason = null;
        $exhausted = false;

        while (true) {
            if ($processed >= $maxCompanies) {
                break; // hit the --max cap: exhausted stays false, no anomaly reason.
            }

            if ($deadlineTs !== null && time() >= $deadlineTs) {
                $stoppedReason = 'deadline';
                break;
            }

            /** @var Company|null $company */
            $company = $this->eligibleCompaniesQuery($includeEmpty)
                ->where('id', '>', $cursor)
                ->limit(1)
                ->first();

            if ($company === null) {
                $exhausted = true;
                break;
            }

            // Advance past this company BEFORE attempting it: guarantees loop
            // progress on skips and no re-charge on failures.
            $cursor = (int) $company->id;

            try {
                $result = $this->enrichment->enrich($company);
                $outcome = $result['outcome'];
            } catch (InvalidEnrichmentDomainException | EnrichmentInFlightException) {
                // Nothing was charged; move on to the next company.
                continue;
            } catch (QuotaExhaustedException) {
                $stoppedReason = 'quota_exhausted';
                break;
            } catch (QuotaLockUnavailableException) {
                // Do NOT skip: the sibling EnrichCriteriaContactsJob rethrows/halts on
                // a lost serialization lock. Fail-closed for the drain too.
                $stoppedReason = 'lock_unavailable';
                break;
            } catch (\Throwable $e) {
                Log::channel('discovery')->error('[EnrichmentDrainService] Unexpected enrichment error; drain stopped.', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
                $stoppedReason = 'error';
                break;
            }

            $processed++;

            switch ($outcome) {
                case 'enriched':
                    $enriched++;
                    break;
                case 'hunter_empty':
                    $empty++;
                    break;
                case 'provider_failed':
                    $failed++;
                    $stoppedReason = 'provider_failed';
                    break;
            }

            if ($onEach !== null) {
                $onEach($company, $outcome);
            }

            if ($stoppedReason !== null) {
                break; // provider_failed = systemic, stop the whole drain.
            }
        }

        return [
            'last_id' => $cursor > 0 ? $cursor : null,
            'processed' => $processed,
            'enriched' => $enriched,
            'empty' => $empty,
            'failed' => $failed,
            'stopped_reason' => $stoppedReason,
            'exhausted' => $exhausted,
        ];
    }

    /**
     * Cost preview for a dry-run. Company cost is an estimate (1.2 units each);
     * contact cost comes from the verification batch's own estimator.
     *
     * @return array{companies_eligible: int, company_credits: float, contacts_unverified: int, contact_credits: float, total_credits: float}
     */
    public function estimate(bool $includeEmpty = false): array
    {
        $companiesEligible = $this->eligibleCompaniesQuery($includeEmpty)->count();
        $companyCredits = round($companiesEligible * self::COMPANY_UNIT_COST, 2);

        $contact = $this->verificationBatch->estimate();
        $contactsUnverified = (int) $contact['eligible'];
        $contactCredits = (float) $contact['estimated_cost'];

        return [
            'companies_eligible' => $companiesEligible,
            'company_credits' => $companyCredits,
            'contacts_unverified' => $contactsUnverified,
            'contact_credits' => $contactCredits,
            'total_credits' => round($companyCredits + $contactCredits, 2),
        ];
    }

    /**
     * Enqueue email verification for every eligible contact. Self-idempotent —
     * only contacts with all three verification columns NULL are enqueued.
     */
    public function drainContacts(): int
    {
        return $this->verificationBatch->enqueue();
    }
}
