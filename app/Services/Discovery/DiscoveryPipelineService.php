<?php

namespace App\Services\Discovery;

use App\Exceptions\CriteriaCompanyNoLongerEligibleException;
use App\Exceptions\EnrichmentInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Quota\DiscoveryQuotaService;
use App\Services\Scoring\LeadScoringService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DiscoveryPipelineService — orchestrates SerpAPI discovery → optional scoring
 * gate → optional Hunter enrichment → Company + Contact upserts.
 *
 * Constructor uses CONCRETE classes only, so app(DiscoveryPipelineService::class)
 * resolves via Laravel's auto-wiring without any manual binding.
 *
 * Idempotent: re-running the same criteria updates existing rows, never duplicates.
 * Client-relationship guard: companies already marked relationship='client' are never
 *   downgraded to 'prospect'; only criteria_id is updated.
 *
 * Credit model: 1 crédit = 1 entreprise traitée (candidate that enters the loop).
 * Resume cursor = run.consumed. Every processed candidate position advances consumed
 * by exactly 1 via CAS UPDATE AFTER upserts (atomically ensures no double-counting
 * on retries and detects concurrent ownership changes).
 *
 * Scoring gate (when auto_scoring=true):
 *   score(candidate, criteria) is called for every candidate.
 *   Enrichment (Hunter call) only runs when score >= min_score_enrich.
 *   Candidates below the threshold are still counted (companies_count++) so that
 *   consumed stays exact; they increment low_score_count instead of contacts_count.
 *
 * Exclusion gate (AI target/exclude descriptions):
 *   When the scorer flags exclude=true (candidate matches the criteria's "à exclure"
 *   description — a competitor), the company is upserted with qualification_status=
 *   'rejected' and Hunter enrichment is skipped entirely. Rejected companies are
 *   free: they do NOT count against the kept budget (companies_count/completedThisAttempt),
 *   only against excluded_count, so a competitor-heavy intent doesn't starve the run of
 *   real prospects. They still advance the `consumed` cursor (+1) — every scanned
 *   candidate consumes exactly one credit regardless of outcome. To bound the extra
 *   scanning cost, discover() over-fetches (OVERSCAN_FACTOR) up to a hard SCAN_CEILING.
 *   A domain already rejected for this criteria is detected BEFORE scoring runs
 *   (Company::withRejected() lookup) and skips re-scoring + re-enrichment entirely.
 *
 * email_kind mapping:
 *   Hunter type = 'generic'  →  email_kind = 'role'
 *   Hunter type = 'personal' →  email_kind = 'personal'
 *
 * the compliance spec (CNIL B2B cold-discovery basis).
 */
class DiscoveryPipelineService
{
    private const SCORE_CHECKPOINT_KEY = '_discovery_score_checkpoint';

    /**
     * Over-fetch multiplier applied to the kept budget when discovering candidates,
     * so a competitor-heavy intent (many rejects) still has enough scanned candidates
     * to reach the kept target. Bounded by SCAN_EXTRA_CEILING below.
     */
    private const OVERSCAN_FACTOR = 3;

    /**
     * Hard extra-scan ceiling added on top of $offset + $budget, bounding SerpAPI +
     * Gemini cost when OVERSCAN_FACTOR alone would let a 100%-competitor intent scan
     * the entire discovery pool.
     */
    private const SCAN_EXTRA_CEILING = 100;

    /**
     * Wall-clock seconds one attempt may spend inside the candidate loop before it
     * stops cleanly. Must stay BELOW RunDiscoveryPipelineJob::$timeout (300) so the
     * attempt ends on its own terms — cursor persisted, stats returned — instead of
     * being killed mid-candidate by the queue worker.
     *
     * Overridable via the `decouverte.run_time_budget` setting; see runTimeBudget().
     */
    private const DEFAULT_RUN_TIME_BUDGET = 240;

    /**
     * ISO-2 country code map. Reused from ZohoCrmSyncService; inlined for isolation.
     */
    private const COUNTRY_MAP = [
        'france' => 'FR',
        'maroc' => 'MA',
        'morocco' => 'MA',
        'espagne' => 'ES',
        'spain' => 'ES',
        'belgique' => 'BE',
        'belgium' => 'BE',
        'allemagne' => 'DE',
        'germany' => 'DE',
        'italie' => 'IT',
        'italy' => 'IT',
        'portugal' => 'PT',
        'pays-bas' => 'NL',
        'netherlands' => 'NL',
        'suisse' => 'CH',
        'switzerland' => 'CH',
        'sénégal' => 'SN',
        'senegal' => 'SN',
        "côte d'ivoire" => 'CI',
        'ivory coast' => 'CI',
        'tunisie' => 'TN',
        'tunisia' => 'TN',
        'algérie' => 'DZ',
        'algeria' => 'DZ',
        'chine' => 'CN',
        'china' => 'CN',
        'états-unis' => 'US',
        'united states' => 'US',
        'usa' => 'US',
        'royaume-uni' => 'GB',
        'united kingdom' => 'GB',
        'uk' => 'GB',
        'turquie' => 'TR',
        'turkey' => 'TR',
        'pologne' => 'PL',
        'poland' => 'PL',
        'roumanie' => 'RO',
        'romania' => 'RO',
    ];

    public function __construct(
        private readonly CompanyDiscoveryService $discovery,
        private readonly HunterEnrichmentService $hunter,
        private readonly LeadScoringService $scoring,
        private readonly DiscoveredContactImportService $contacts,
        private readonly HomepageSnapshotService $homepage,
        private readonly ?DiscoveryQuotaService $quota = null,
        private readonly ?CompanyEnrichmentService $companyEnrichment = null,
    ) {}

    /**
     * Run the full discovery pipeline for the given criteria.
     *
     * Credit semantics: 1 crédit = 1 entreprise traitée. consumed is the resume cursor.
     * Counts are persisted incrementally via CAS UPDATE — job completion no longer
     * writes counts.
     *
     * @param  int|null  $cap  External company cap (credits_reserved − consumed) from RunDiscoveryPipelineJob.
     *                         null = no external cap; use criteria daily_limit only.
     * @param  DiscoveryRun|null  $run  Live run row; consumed is the resume cursor.
     * @param  int|null  $contactCap  External contact-enrichment cap. null = unlimited (skip Hunter gate).
     *                                When set, Hunter is only called while $contactSpent < $contactCap.
     *                                Invariant: for discovery rows contact_consumed <= consumed; manual rows differ.
     * @param  float|null  $attemptStartedAt  Absolute attempt start used by direct/legacy callers.
     * @param  DiscoveryExecutionDeadline|null  $attemptDeadline  Exact deadline already shared with
     *                                                            the queue job's backlog phase.
     */
    public function run(
        ProspectCriteria $criteria,
        ?int $cap = null,
        ?DiscoveryRun $run = null,
        ?int $contactCap = null,
        ?float $attemptStartedAt = null,
        bool $providerUnavailable = false,
        ?DiscoveryExecutionDeadline $attemptDeadline = null,
    ): DiscoveryPipelineResult {
        // Queued runs receive the exact immutable deadline already used by their
        // backlog phase. Direct callers still get a locally configured deadline.
        $deadline = $attemptDeadline ?? new DiscoveryExecutionDeadline(
            $attemptStartedAt ?? microtime(true),
            $this->runTimeBudget(),
        );

        // $cap is a SerpAPI search-call budget, not a kept-company budget.
        // One search returns up to a full page of candidates, and page size is
        // engine-dependent (google organic = 10, google_maps = 20).
        $searchBudget = $cap !== null
            ? max(0, $cap)
            : ($criteria->daily_limit ?: 20);
        // Sized engine-agnostically off MAX_PAGE_SIZE: using the organic PAGE_SIZE
        // would cap a 20-result Maps page at 10 and silently discard half of it.
        // Only the manual path (discover(), below) consumes $candidateLimit — queued
        // discovery runs go through discoverForRun() and never see it. Raising it
        // cannot raise API spend: the provider-call budget is $searchBudget, enforced
        // separately.
        $candidateLimit = $searchBudget * CompanyDiscoveryService::MAX_PAGE_SIZE;

        // Contact budget: how many Hunter calls are allowed in this attempt.
        // PHP_INT_MAX means unlimited (no contactCap set).
        $contactBudget = $contactCap ?? PHP_INT_MAX;
        $contactSpent = 0;
        $providerCircuitOpen = $providerUnavailable;

        $stats = ['companies' => 0, 'contacts' => 0, 'skipped' => 0, 'low_score' => 0, 'new' => 0, 'contacts_consumed' => 0, 'excluded' => 0];

        // Read scoring/enrichment settings once per run (avoids repeated DB/cache reads).
        $autoScoring = (bool) Setting::get('decouverte.auto_scoring', true);
        $enrichmentRules = AutomaticEnrichmentDecision::resolveRules($criteria);
        // Resume cursor: $offset = number of candidates already processed (scanned, not just kept).
        $offset = ($run !== null) ? (int) $run->consumed : 0;

        // Only queued discovery runs use shared SerpAPI cursor/snapshot pagination.
        $isDiscoveryRun = $run !== null && ($run->type ?? 'discovery') === 'discovery';

        $searchProviderDown = false;

        if ($isDiscoveryRun) {
            $collection = $this->discovery->discoverForRun(
                $criteria,
                $run,
                $searchBudget,
                $deadline->workDeadlineAt(),
            );
            $allCandidates = $collection->candidates;
            $collectionComplete = $collection->terminal;
            $searchProviderDown = $collection->searchProviderDown;
        } else {
            $allCandidates = $this->discovery->discover($criteria, $candidateLimit);
            $collectionComplete = true;
        }

        $candidates = array_slice($allCandidates, $offset);

        $completedThisAttempt = 0; // kept rows only — stats/logging only; not a budget gate anymore
        $scannedThisAttempt = 0; // every processed candidate (kept + rejected)
        $scanTarget = count($candidates);

        foreach ($candidates as $candidate) {
            if ($scannedThisAttempt >= $scanTarget) {
                break;
            }

            // Checked before starting a candidate. If scoring itself consumes the
            // remaining work window, its result is checkpointed in the run snapshot
            // below before this attempt pauses, so continuation never pays for the
            // same score twice.
            if ($deadline->isExhausted()) {
                Log::channel('discovery')->info('[DiscoveryPipelineService] Time budget exhausted — stopping this attempt; the run resumes from the same cursor on the next attempt.', [
                    'run_id' => $run?->id,
                    'criteria_id' => $criteria->id,
                    'consumed' => $offset + $scannedThisAttempt,
                    'scanned' => $scannedThisAttempt,
                    'remaining_seconds' => round($deadline->remaining(), 1),
                    'unscanned' => $scanTarget - $scannedThisAttempt,
                ]);

                break;
            }

            // Warm only the next resumable slice. A large all-at-once prefetch used
            // to consume the whole queue timeout before candidate #1. If a wave
            // cannot start within the shared deadline, nothing is cached for the
            // untouched domains and the same cursor resumes on the next attempt.
            if ($autoScoring && $scannedThisAttempt % 10 === 0) {
                $prefetchDomains = [];

                foreach (array_slice($candidates, $scannedThisAttempt, 10) as $prefetchCandidate) {
                    $prefetchDomain = $prefetchCandidate['domain'] ?? null;

                    if (is_string($prefetchDomain) && $prefetchDomain !== '') {
                        $prefetchDomains[] = $prefetchDomain;
                    }
                }

                if (! $this->homepage->prefetch($prefetchDomains, $deadline->workDeadlineAt())) {
                    break;
                }

                if (! $this->touchHeartbeat($run)) {
                    Log::channel('discovery')->info('[DiscoveryPipelineService] Run no longer running — stopping this attempt.', [
                        'run_id' => $run?->id,
                        'criteria_id' => $criteria->id,
                    ]);

                    return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                }
            }

            $domain = $candidate['domain'] ?? null;

            // Defensive branch for a corrupted legacy snapshot. Advancing the cursor
            // prevents one invalid row from forcing endless continuations.
            if (! $domain) {
                if ($run !== null) {
                    $advanced = DiscoveryRun::where('id', $run->id)
                        ->where('status', 'running')
                        ->where('consumed', $offset + $scannedThisAttempt)
                        ->update([
                            'consumed' => DB::raw('consumed + 1'),
                            'skipped_count' => DB::raw('skipped_count + 1'),
                            'updated_at' => now(),
                        ]);

                    if ($advanced === 0) {
                        return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                    }
                }

                $stats['skipped']++;
                $scannedThisAttempt++;

                continue;
            }

            // CAS expected position: consumed must equal this value for our UPDATE to land.
            $expected = $offset + $scannedThisAttempt;
            $hunterCalled = false; // declared before try so catch can read it

            try {
                // ── Step 0: Skip re-scoring known rejects (cost guard) ───────
                // A domain already rejected for THIS criteria is detected before any
                // scoring/Hunter call — re-discovering it must not re-pay Gemini every run.
                /** @var Company|null $existingForDomain */
                $existingForDomain = Company::withRejected()->where('domain', $domain)->first();

                $alreadyRejectedHere = $existingForDomain
                    && $existingForDomain->qualification_status === 'rejected'
                    && (int) $existingForDomain->criteria_id === (int) $criteria->id;

                if ($alreadyRejectedHere) {
                    if ($run !== null) {
                        $advanced = DiscoveryRun::where('id', $run->id)
                            ->where('status', 'running')
                            ->where('consumed', $expected)
                            ->update([
                                'consumed' => DB::raw('consumed + 1'),
                                'excluded_count' => DB::raw('excluded_count + 1'),
                                'updated_at' => now(),
                            ]);

                        if ($advanced === 0) {
                            Log::channel('discovery')->info('[DiscoveryPipelineService] CAS debit blocked — run terminalized or cursor mismatch; returning partial stats.', [
                                'run_id' => $run->id,
                                'criteria_id' => $criteria->id,
                                'expected' => $expected,
                            ]);

                            return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                        }
                    }

                    $stats['excluded']++;
                    $scannedThisAttempt++;

                    continue;
                }

                // ── Step 1: Scoring gate ─────────────────────────────────────
                $score = null;
                $explanation = null;
                $excludeFlag = false;
                $checkpointedCompanyWasNew = false;

                if ($autoScoring) {
                    $scoreResult = $isDiscoveryRun
                        ? $this->scoringCheckpoint($candidate)
                        : null;

                    if ($scoreResult !== null) {
                        $checkpointedCompanyWasNew = (bool) ($scoreResult['company_was_new'] ?? false);
                    }

                    if ($scoreResult === null) {
                        // Feed the real homepage text to the scorer, not just the SERP
                        // snippet — an article ABOUT freight reads like a shipper otherwise.
                        // Enrich a LOCAL copy only: $candidate stays untouched for upsertCompany().
                        $scoringCandidate = $candidate;
                        // prefetch() is the only network-capable homepage operation in
                        // this pipeline and is bounded by the shared deadline. Reading
                        // here must stay cache-only so eviction cannot trigger a late,
                        // unbounded serial request.
                        $excerpt = $this->homepage->cachedExcerpt($domain);

                        if ($excerpt !== null) {
                            $scoringCandidate['homepage_excerpt'] = $excerpt;
                        }

                        $scoringTimeout = $deadline->timeout(20);

                        if ($scoringTimeout === null) {
                            break;
                        }

                        $scoreResult = $this->scoring->score($scoringCandidate, $criteria, $scoringTimeout);
                    }

                    $score = $scoreResult['score'];
                    $explanation = $scoreResult['explanation'];
                    $excludeFlag = $scoreResult['exclude'] ?? false;
                }

                $decision = AutomaticEnrichmentDecision::fromResolvedRules(
                    $enrichmentRules,
                    $autoScoring,
                    $score,
                    $excludeFlag,
                    $existingForDomain?->enrichment_status,
                );
                $excluded = $decision->excluded;

                // ── Step 2: Enrichment decision ──────────────────────────────
                // Enrich when: not excluded, auto_enrich is on, AND either scoring is off OR
                // score passes the gate, AND the per-attempt contact budget has not been exhausted.
                // Once contactBudget is spent, companies continue being discovered but Hunter is skipped.
                $budgetExhausted = $contactSpent >= $contactBudget;
                $passesEnrichmentGate = $decision->allowsEnrichment();
                $shouldEnrich = $passesEnrichmentGate
                    && ! $budgetExhausted
                    && ! $providerCircuitOpen;

                $enrichmentStatus = $this->resolveEnrichmentStatus(
                    $decision,
                    $budgetExhausted,
                    false,
                    null,
                    $providerCircuitOpen,
                );

                // Persist the candidate before attempting to claim it. Automatic
                // eligibility and the contact debit are then checked against this
                // durable row under the quota service's admission lock.
                $deferCountry = $shouldEnrich || ($run !== null && $passesEnrichmentGate);
                $company = $this->upsertCompany(
                    $criteria, $domain, $candidate, null,
                    $score, $explanation, $autoScoring, $excluded, $enrichmentStatus, $deferCountry
                );

                // A paid score may have been checkpointed after this run inserted
                // the company but before its candidate cursor could advance. Keep
                // that run-scoped fact so the continuation reports the new row once.
                $isNew = $company->wasRecentlyCreated || $checkpointedCompanyWasNew;
                $contactCount = 0;
                $enrichment = null;

                // ── Step 3: Hunter enrichment (if gate passed) ───────────────
                $hunterTimeout = $shouldEnrich ? $deadline->timeout(20) : null;

                if ($shouldEnrich && $hunterTimeout === null) {
                    if ($isDiscoveryRun
                        && $autoScoring
                        && $this->scoringCheckpoint($candidate) === null
                        && ! $this->persistScoringCheckpoint($run, $expected, $domain, $scoreResult, $isNew)
                    ) {
                        return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                    }

                    break;
                }

                if ($shouldEnrich && $run !== null) {
                    $claimed = null;
                    try {
                        $claimed = $this->quotaService()->claimAutomaticEnrichment($company->id, $run);
                    } catch (QuotaExhaustedException) {
                        $this->markUnclaimedEnrichmentStatus(
                            $company,
                            Company::ENRICHMENT_SKIPPED_BUDGET,
                        );
                    } catch (QuotaLockUnavailableException) {
                        // Admission was never decided, so consuming this cursor
                        // would permanently strand a candidate without a Hunter
                        // debit or call. Preserve any paid score and let the parent
                        // job release this exact candidate for a later attempt.
                        if ($isDiscoveryRun
                            && $autoScoring
                            && $this->scoringCheckpoint($candidate) === null
                            && ! $this->persistScoringCheckpoint($run, $expected, $domain, $scoreResult, $isNew)
                        ) {
                            return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                        }

                        Log::channel('discovery')->warning('[DiscoveryPipelineService] Quota admission lock unavailable — candidate retained for retry.', [
                            'domain' => $domain,
                            'criteria_id' => $criteria->id,
                            'run_id' => $run->id,
                        ]);

                        return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                    } catch (CriteriaCompanyNoLongerEligibleException|EnrichmentInFlightException) {
                        // A concurrent/manual owner or a fresh eligibility change won.
                        // It owns the status and provider decision; discovery still
                        // advances its candidate cursor without another Hunter call.
                    }

                    if ($claimed !== null) {
                        $hunterCalled = true;
                        $contactSpent++;

                        $outcome = $this->enrichmentService()->enrichClaimed(
                            $claimed,
                            $run,
                            $hunterTimeout,
                            $this->countryFallback($criteria, $candidate),
                        );
                        $contactCount = $outcome['contacts_count'];

                        if ($outcome['outcome'] === 'provider_failed') {
                            $providerCircuitOpen = true;
                        }
                    }
                } elseif ($shouldEnrich) {
                    // Compatibility path for direct service callers that do not own
                    // a durable discovery run. Queued production runs always use the
                    // claimed branch above.
                    $hunterCalled = true;
                    $contactSpent++;
                    $hunterResult = $this->hunter->domainSearchResult($domain, 10, $hunterTimeout);

                    if ($hunterResult['status'] === 'provider_failed') {
                        $providerCircuitOpen = true;
                        $directStatus = Company::ENRICHMENT_HUNTER_FAILED;
                    } else {
                        $enrichment = $hunterResult['data'] ?? [
                            'organization' => null,
                            'industry' => null,
                            'country' => null,
                            'emails' => [],
                            'raw' => [],
                        ];
                        $directStatus = $this->resolveEnrichmentStatus(
                            $decision,
                            false,
                            true,
                            $enrichment,
                        );
                    }

                    $company = $this->upsertCompany(
                        $criteria,
                        $domain,
                        $candidate,
                        $enrichment,
                        $score,
                        $explanation,
                        $autoScoring,
                        $excluded,
                        $directStatus,
                        $enrichment === null,
                    );

                    $contactCount = $enrichment !== null
                        ? $this->contacts->import($company, array_map(
                            static fn (array $row): array => $row + ['source_url' => $domain, 'source' => 'hunter_company_enrichment'],
                            $enrichment['emails'] ?? [],
                        ))->created
                        : 0;
                }

                // ── Step 5: Determine low-score flag ────────────────────────
                // A candidate is "low score" when not excluded, auto_enrich is on, scoring is on,
                // and the score is below the gate.
                $isLowScore = $decision->isLowScore();

                // ── Step 6: CAS debit + incremental stats (AFTER upserts) ───
                // Rejects do NOT increment companies_count/new_companies_count (they'd
                // inflate "success" stats while the visible list stays near-empty) —
                // they get their own excluded_count bucket instead.
                if ($run !== null) {
                    $advanced = DiscoveryRun::where('id', $run->id)
                        ->where('status', 'running')
                        ->where('consumed', $expected)
                        ->update($excluded ? [
                            'consumed' => DB::raw('consumed + 1'),
                            'excluded_count' => DB::raw('excluded_count + 1'),
                            'updated_at' => now(),
                        ] : [
                            'consumed' => DB::raw('consumed + 1'),
                            'companies_count' => DB::raw('companies_count + 1'),
                            'new_companies_count' => DB::raw('COALESCE(new_companies_count, 0) + '.($isNew ? 1 : 0)),
                            'low_score_count' => DB::raw('low_score_count + '.($isLowScore ? 1 : 0)),
                            'updated_at' => now(),
                        ]);

                    if ($advanced === 0) {
                        // Run was terminalized or a concurrent process owns the cursor.
                        Log::channel('discovery')->info('[DiscoveryPipelineService] CAS debit blocked — run terminalized or cursor mismatch; returning partial stats.', [
                            'run_id' => $run->id,
                            'criteria_id' => $criteria->id,
                            'expected' => $expected,
                        ]);

                        return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                    }
                }

                // Local stats for return value and logging.
                if ($excluded) {
                    $stats['excluded']++;
                } else {
                    $stats['companies']++;
                    if ($isNew) {
                        $stats['new']++;
                    }
                    $stats['contacts'] += $contactCount;
                    if ($isLowScore) {
                        $stats['low_score']++;
                    }
                    if ($hunterCalled) {
                        $stats['contacts_consumed']++;
                    }
                }
            } catch (\Throwable $e) {
                if ($e instanceof QueryException && $this->isDuplicateCompanyDomainKey($e)) {
                    Log::channel('discovery')->info('[DiscoveryPipelineService] Duplicate company domain race - advancing cursor only', [
                        'domain' => $domain,
                        'criteria_id' => $criteria->id,
                        'run_id' => $run?->id,
                    ]);

                    if ($run !== null) {
                        $update = [
                            'consumed' => DB::raw('consumed + 1'),
                            'updated_at' => now(),
                        ];

                        $advanced = DiscoveryRun::where('id', $run->id)
                            ->where('status', 'running')
                            ->where('consumed', $expected)
                            ->update($update);

                        if ($advanced === 0) {
                            return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                        }
                    }

                    if ($hunterCalled) {
                        $stats['contacts_consumed']++;
                    }

                    $scannedThisAttempt++;

                    continue;
                }

                Log::channel('discovery')->warning('[DiscoveryPipelineService] Domain processing failed — skipping', [
                    'domain' => $domain,
                    'criteria_id' => $criteria->id,
                    'error' => $e->getMessage(),
                ]);

                // CAS advance with skipped: consumed still +1 (failing candidate
                // consumes its credit, keeping the cursor exact). No company/contact
                // increments — the candidate was not processed successfully.
                if ($run !== null) {
                    $advanced = DiscoveryRun::where('id', $run->id)
                        ->where('status', 'running')
                        ->where('consumed', $expected)
                        ->update([
                            'consumed' => DB::raw('consumed + 1'),
                            'skipped_count' => DB::raw('skipped_count + 1'),
                            'updated_at' => now(),
                        ]);

                    if ($advanced === 0) {
                        return new DiscoveryPipelineResult($stats, $collectionComplete, false);
                    }
                }

                $stats['skipped']++;
                $scannedThisAttempt++;

                continue;
            }

            $scannedThisAttempt++;
            if (! ($excluded ?? false)) {
                $completedThisAttempt++;
            }
        }

        return new DiscoveryPipelineResult(
            $stats,
            $collectionComplete,
            ($offset + $scannedThisAttempt) >= count($allCandidates),
            $searchProviderDown,
        );
    }

    /**
     * Keep active-run updated_at as the observable heartbeat. Returning false means
     * another actor terminalized the run, so this attempt must stop immediately.
     */
    private function touchHeartbeat(?DiscoveryRun $run): bool
    {
        if ($run === null) {
            return true;
        }

        return DiscoveryRun::touchHeartbeat(DiscoveryRun::whereKey($run->id));
    }

    /**
     * Read a scorer result previously paid for by this run. Provider candidates
     * cannot bypass scoring because checkpoints are only consumed on durable runs
     * and discovery normalisation never emits this private key.
     *
     * @param  array<string, mixed>  $candidate
     * @return array{score: int, explanation: string, exclude: bool, company_was_new: bool}|null
     */
    private function scoringCheckpoint(array $candidate): ?array
    {
        $checkpoint = $candidate[self::SCORE_CHECKPOINT_KEY] ?? null;

        if (! is_array($checkpoint)
            || ! is_numeric($checkpoint['score'] ?? null)
            || ! is_string($checkpoint['explanation'] ?? null)
        ) {
            return null;
        }

        return [
            'score' => (int) $checkpoint['score'],
            'explanation' => $checkpoint['explanation'],
            'exclude' => (bool) ($checkpoint['exclude'] ?? false),
            'company_was_new' => (bool) ($checkpoint['company_was_new'] ?? false),
        ];
    }

    /**
     * Persist the paid scoring result when its attempt cannot safely start Hunter.
     * The cursor guard ties the checkpoint to the exact unconsumed candidate and
     * the row lock prevents collection append/terminalisation from overwriting it.
     *
     * @param  array{score: int, explanation: string, exclude?: bool}  $scoreResult
     */
    private function persistScoringCheckpoint(
        DiscoveryRun $run,
        int $expected,
        string $domain,
        array $scoreResult,
        bool $companyWasNew,
    ): bool {
        return DB::transaction(function () use ($run, $expected, $domain, $scoreResult, $companyWasNew): bool {
            /** @var DiscoveryRun|null $lockedRun */
            $lockedRun = DiscoveryRun::whereKey($run->id)->lockForUpdate()->first();

            if ($lockedRun === null
                || $lockedRun->status !== 'running'
                || (int) $lockedRun->consumed !== $expected
            ) {
                return false;
            }

            $snapshot = $lockedRun->candidates_snapshot;
            $candidate = is_array($snapshot) ? ($snapshot[$expected] ?? null) : null;

            if (! is_array($candidate) || ($candidate['domain'] ?? null) !== $domain) {
                return false;
            }

            $snapshot[$expected][self::SCORE_CHECKPOINT_KEY] = [
                'score' => (int) $scoreResult['score'],
                'explanation' => (string) $scoreResult['explanation'],
                'exclude' => (bool) ($scoreResult['exclude'] ?? false),
                'company_was_new' => $companyWasNew,
            ];

            $lockedRun->forceFill(['candidates_snapshot' => array_values($snapshot)])->save();

            return true;
        });
    }

    private function quotaService(): DiscoveryQuotaService
    {
        return $this->quota ?? app(DiscoveryQuotaService::class);
    }

    private function enrichmentService(): CompanyEnrichmentService
    {
        return $this->companyEnrichment ?? app(CompanyEnrichmentService::class);
    }

    /**
     * Record a deferral only while no run owns the company. This prevents a
     * losing discovery worker from overwriting an active manual/automatic claim.
     *
     * Writes enrichment_status to a value it may already hold (retry landing on
     * the same status). Its affected-row count is therefore never a valid
     * ownership signal — this write is fire-and-forget, unread by any caller.
     */
    private function markUnclaimedEnrichmentStatus(Company $company, string $status): void
    {
        Company::withRejected()
            ->whereKey($company->id)
            ->whereNull('enrichment_claim_run_id')
            ->update([
                'enrichment_status' => $status,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidateSnapshot(DiscoveryRun $run): array
    {
        if (! is_array($run->candidates_snapshot)) {
            return [];
        }

        return array_values(array_filter(
            $run->candidates_snapshot,
            fn ($candidate) => is_array($candidate) && ! empty($candidate['domain'])
        ));
    }

    private function isDuplicateCompanyDomainKey(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'companies.domain')
            || str_contains($message, 'companies_domain_unique');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Wall-clock budget, in seconds, for one attempt's candidate loop.
     *
     * Defensive read (same idiom as HomepageSnapshotService::positiveSetting()): a
     * missing, non-numeric or non-positive value falls back to the default. Honouring
     * a stored 0 would mean "stop before the first candidate", silently disabling all
     * discovery — the setting must never be able to express that.
     */
    private function runTimeBudget(): int
    {
        $value = Setting::get('decouverte.run_time_budget', self::DEFAULT_RUN_TIME_BUDGET);

        if (! is_numeric($value) || (int) $value <= 0) {
            return self::DEFAULT_RUN_TIME_BUDGET;
        }

        return max(30, min(240, (int) $value));
    }

    /**
     * Whether this attempt has spent its wall-clock budget.
     *
     * $now is injectable so the decision can be unit-tested without simulating a
     * multi-minute run.
     *
     * @param  float  $startedAt  Monotonic-ish start from microtime(true).
     * @param  int  $budget  Budget in seconds (already defaulted).
     * @param  float|null  $now  Defaults to the current microtime(true).
     */
    private function timeBudgetExceeded(float $startedAt, int $budget, ?float $now = null): bool
    {
        return (($now ?? microtime(true)) - $startedAt) >= $budget;
    }

    /**
     * Resolve the per-company enrichment audit status.
     *
     * Answers "why does this company have no contacts?" — the admin UI cannot
     * otherwise distinguish "never attempted" (NULL) from "attempted, found nothing".
     *
     * Precedence is deliberate and evaluated top-down: the FIRST reason that
     * prevented enrichment wins, so a candidate that is both excluded and
     * low-score reports 'skipped_excluded' (the scorer's verdict, not the gate's).
     *
     * Branches 1–4 fully cover every case where Hunter was not called (they are the
     * exact negation of $shouldEnrich), so branches 5–7 only ever run when
     * $hunterCalled is true.
     *
     * @param  AutomaticEnrichmentDecision  $decision  Shared criterion/global gate result.
     * @param  bool  $budgetExhausted  Per-run contact budget was already spent.
     * @param  bool  $hunterCalled  Whether Hunter was actually invoked.
     * @param  array|null  $enrichment  Hunter payload (null = provider failure).
     * @param  bool  $providerUnavailable  Hunter circuit was already open.
     * @return string|null One of the Company::ENRICHMENT_* constants, or null
     *                     while an eligible candidate is awaiting its claim.
     */
    private function resolveEnrichmentStatus(
        AutomaticEnrichmentDecision $decision,
        bool $budgetExhausted,
        bool $hunterCalled,
        ?array $enrichment,
        bool $providerUnavailable = false,
    ): ?string {
        if ($decision->skipStatus !== null) {
            return $decision->skipStatus;
        }

        if ($providerUnavailable) {
            return Company::ENRICHMENT_SKIPPED_PROVIDER_UNAVAILABLE;
        }

        if ($budgetExhausted) {
            return Company::ENRICHMENT_SKIPPED_BUDGET;
        }

        if (! $hunterCalled) {
            return null;
        }

        // Hunter ran but the provider gave us nothing at all (no API key, or both
        // provider calls failed) — distinct from "ran fine, found no addresses".
        if ($hunterCalled && $enrichment === null) {
            return Company::ENRICHMENT_HUNTER_FAILED;
        }

        return $this->countUsableEmails($enrichment) === 0
            ? Company::ENRICHMENT_HUNTER_EMPTY
            : Company::ENRICHMENT_ENRICHED;
    }

    /**
     * Count Hunter e-mail entries that the shared discovered-contact importer can store as
     * a contact row (it skips any entry without a non-empty `value`).
     */
    private function countUsableEmails(?array $enrichment): int
    {
        $emails = $enrichment['emails'] ?? [];

        if (! is_array($emails)) {
            return 0;
        }

        $count = 0;
        foreach ($emails as $email) {
            if (! empty($email['value'] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Upsert a company row from discovery data.
     *
     * Credit semantics: enrichment_data is only written when $enrichment !== null,
     * preventing null-wipe of previously enriched data when the scoring gate skips
     * Hunter for this candidate. ai_score + ai_explanation are only written when
     * $scored=true to avoid clobbering existing scores when scoring is disabled.
     * Existing sector/country values are preserved when a partial enrichment has no
     * replacement metadata; new companies still receive nullable values.
     *
     * Candidate metadata fallbacks: a discovery source may carry optional `phone`,
     * `country` and `sector_hint` keys (Google Maps). They are FALLBACKS ONLY — used
     * when Hunter supplied nothing for that field AND the existing row is empty for
     * it. They never overwrite data already held.
     *
     * Client-downgrade guard: companies with relationship='client' are never
     * downgraded to 'prospect'; only criteria_id is updated.
     *
     * Global-scope sharp edge: the domain lookup MUST use withRejected() — Company
     * has a global scope hiding qualification_status='rejected' rows. Without the
     * opt-out, a re-discovered rejected domain would not be found here, Company::create()
     * would fire, and the unique `domain` constraint would throw.
     *
     * Rejection: when $excluded is true, qualification_status is forced to 'rejected'
     * and enrichment/scoring fields are still written (so the explanation stays visible
     * on the audit view) but enrichment_data is never written (Hunter is never called
     * for excluded candidates — see run()).
     *
     * discovery_query is only set on first insert — a domain deduped across multiple
     * queries keeps the query that first found it (enables per-request results grouping).
     *
     * @param  array  $candidate  SerpAPI-normalised candidate.
     * @param  array|null  $enrichment  Hunter enrichment data (null when gate skipped).
     * @param  int|null  $score  AI/heuristic score (null when scoring disabled).
     * @param  string|null  $explanation  Score explanation (null when scoring disabled).
     * @param  bool  $scored  Whether scoring ran for this candidate.
     * @param  bool  $excluded  Whether the scorer flagged this candidate for rejection.
     * @param  string|null  $enrichmentStatus  Enrichment audit status (null = do not touch the column).
     * @param  bool  $deferCountry  Leave fallback empty until an eligible Hunter attempt resolves.
     */
    private function upsertCompany(
        ProspectCriteria $criteria,
        string $domain,
        array $candidate,
        ?array $enrichment,
        ?int $score,
        ?string $explanation,
        bool $scored,
        bool $excluded = false,
        ?string $enrichmentStatus = null,
        bool $deferCountry = false,
    ): Company {
        /** @var Company|null $existing */
        $existing = Company::withRejected()->where('domain', $domain)->first();

        $isClient = $existing && $existing->relationship === 'client';

        $enrichedSector = $enrichment['industry'] ?? null;
        $hunterCountry = $this->mapIso2($enrichment['country'] ?? null);

        // Forward-compatible candidate metadata (Google Maps discovery source).
        // FALLBACKS ONLY — used when Hunter supplied nothing for the field AND the
        // existing row is empty for it. Never overwrites data we already hold.
        $candidateSector = $candidate['sector_hint'] ?? null;
        $fallbackCountry = $this->countryFallback($criteria, $candidate);
        $candidatePhone = $candidate['phone'] ?? null;

        if ($enrichedSector === null && $candidateSector !== null && empty($existing?->sector)) {
            $enrichedSector = $candidateSector;
        }

        $resolvedCountry = $hunterCountry ?? $fallbackCountry;

        // Build attributes to set / update
        $attributes = [
            'criteria_id' => $criteria->id,
            'name' => $enrichment['organization']
                ?? $candidate['title']
                ?? $domain,
            'source' => 'discovered',
        ];

        // phone has no Hunter equivalent — only ever filled when the row is empty.
        if (! empty($candidatePhone) && empty($existing?->phone)) {
            $attributes['phone'] = $candidatePhone;
        }

        // Enrichment audit status — written in the same save as everything else so
        // no candidate costs a second UPDATE. null means "leave the column alone".
        if ($enrichmentStatus !== null) {
            $attributes['enrichment_status'] = $enrichmentStatus;
        }

        if (! $existing || $enrichedSector !== null) {
            $attributes['sector'] = $enrichedSector;
        }

        if (! $deferCountry && empty($existing?->country) && $resolvedCountry !== null) {
            $attributes['country'] = $resolvedCountry;
        }

        // Only write enrichment_data when enrichment ran — do not null-wipe
        // previously enriched data when the scoring gate skips Hunter this pass.
        if ($enrichment !== null) {
            $attributes['enrichment_data'] = $enrichment['raw'] ?? null;
        }

        // Only write scoring fields when scoring actually ran — do not wipe
        // existing scores when auto_scoring is disabled.
        if ($scored) {
            $attributes['ai_score'] = $score;
            $attributes['ai_explanation'] = $explanation;
        }

        if ($excluded) {
            $attributes['qualification_status'] = 'rejected';
        } elseif ($existing && ! $isClient && $existing->qualification_status === 'rejected') {
            // A prior criteria rejected this domain, but the current (new/edited) criteria
            // just kept it — un-hide it rather than leaving it stuck behind the
            // notRejected global scope forever.
            $attributes['qualification_status'] = 'pending';
        }

        if (! $isClient) {
            // Safe to set / overwrite relationship for non-clients
            $attributes['relationship'] = 'prospect';
        }
        // For clients: criteria_id is still updated (already in $attributes) but
        // relationship stays 'client' — the merge below handles this correctly.

        if ($existing) {
            // Preserve existing relationship when client; merge other fields
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        // New company — discovery_query is only ever set here (first insert).
        return Company::create(array_merge($attributes, [
            'domain' => $domain,
            'discovery_query' => $candidate['discovery_query'] ?? null,
        ]));
    }

    /**
     * Candidate country first, then the criterion only when it names one country.
     * Used by queued backlog processing after the first upsert deliberately defers it.
     */
    public function countryFallback(ProspectCriteria $criteria, array $candidate): ?string
    {
        $countries = $criteria->countries;
        $criteriaCountry = is_array($countries) && count($countries) === 1
            ? $this->mapIso2(array_values($countries)[0])
            : null;

        return $this->mapIso2($candidate['country'] ?? null) ?? $criteriaCountry;
    }

    /**
     * Map a country name or code to an ISO-3166-1 alpha-2 code.
     * Bare 2-char inputs that are already a code pass through uppercased.
     */
    private function mapIso2(?string $country): ?string
    {
        if ($country === null || $country === '') {
            return null;
        }

        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        $key = strtolower(trim($country));

        return self::COUNTRY_MAP[$key] ?? null;
    }
}
