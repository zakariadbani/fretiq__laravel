<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Scoring\LeadScoringService;
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
 * email_kind mapping:
 *   Hunter type = 'generic'  →  email_kind = 'role'
 *   Hunter type = 'personal' →  email_kind = 'personal'
 *
 * legal_basis is set to 'legitimate_interest' on every discovered contact per
 * the compliance spec (CNIL B2B cold-discovery basis).
 */
class DiscoveryPipelineService
{
    /**
     * ISO-2 country code map. Reused from ZohoCrmSyncService; inlined for isolation.
     */
    private const COUNTRY_MAP = [
        'france'          => 'FR',
        'maroc'           => 'MA',
        'morocco'         => 'MA',
        'espagne'         => 'ES',
        'spain'           => 'ES',
        'belgique'        => 'BE',
        'belgium'         => 'BE',
        'allemagne'       => 'DE',
        'germany'         => 'DE',
        'italie'          => 'IT',
        'italy'           => 'IT',
        'portugal'        => 'PT',
        'pays-bas'        => 'NL',
        'netherlands'     => 'NL',
        'suisse'          => 'CH',
        'switzerland'     => 'CH',
        'sénégal'         => 'SN',
        'senegal'         => 'SN',
        "côte d'ivoire"   => 'CI',
        'ivory coast'     => 'CI',
        'tunisie'         => 'TN',
        'tunisia'         => 'TN',
        'algérie'         => 'DZ',
        'algeria'         => 'DZ',
        'chine'           => 'CN',
        'china'           => 'CN',
        'états-unis'      => 'US',
        'united states'   => 'US',
        'usa'             => 'US',
        'royaume-uni'     => 'GB',
        'united kingdom'  => 'GB',
        'uk'              => 'GB',
        'turquie'         => 'TR',
        'turkey'          => 'TR',
        'pologne'         => 'PL',
        'poland'          => 'PL',
        'roumanie'        => 'RO',
        'romania'         => 'RO',
    ];

    public function __construct(
        private readonly CompanyDiscoveryService  $discovery,
        private readonly HunterEnrichmentService  $hunter,
        private readonly LeadScoringService       $scoring,
        private readonly ContactUpsertService     $contactUpsert,
    ) {}

    /**
     * Run the full discovery pipeline for the given criteria.
     *
     * Credit semantics: 1 crédit = 1 entreprise traitée. consumed is the resume cursor.
     * Counts are persisted incrementally via CAS UPDATE — job completion no longer
     * writes counts.
     *
     * @param  ProspectCriteria  $criteria
     * @param  int|null          $cap        External company cap (credits_reserved − consumed) from RunDiscoveryPipelineJob.
     *                                       null = no external cap; use criteria daily_limit only.
     * @param  DiscoveryRun|null $run        Live run row; consumed is the resume cursor.
     * @param  int|null          $contactCap External contact-enrichment cap. null = unlimited (skip Hunter gate).
     *                                       When set, Hunter is only called while $contactSpent < $contactCap.
     *                                       Invariant: for discovery rows contact_consumed <= consumed; manual rows differ.
     * @return array{companies: int, contacts: int, skipped: int, low_score: int, contacts_consumed: int}
     */
    public function run(ProspectCriteria $criteria, ?int $cap = null, ?DiscoveryRun $run = null, ?int $contactCap = null): array
    {
        // When an external cap is provided, honour both the criteria daily_limit and the cap.
        $budget = $cap !== null
            ? min($criteria->daily_limit ?: 20, $cap)
            : ($criteria->daily_limit ?: 20);

        // Contact budget: how many Hunter calls are allowed in this attempt.
        // PHP_INT_MAX means unlimited (no contactCap set).
        $contactBudget = $contactCap ?? PHP_INT_MAX;
        $contactSpent  = 0;

        $stats = ['companies' => 0, 'contacts' => 0, 'skipped' => 0, 'low_score' => 0, 'new' => 0, 'contacts_consumed' => 0];

        // Read scoring/enrichment settings once per run (avoids repeated DB/cache reads).
        $autoScoring = (bool) Setting::get('decouverte.auto_scoring', true);
        $autoEnrich  = (bool) Setting::get('decouverte.auto_enrich', true);
        $minScore    = (int) Setting::get('decouverte.min_score_enrich', 50);

        // Resume cursor: $offset = number of candidates already processed.
        // Fetch offset+budget candidates so we can slice from the correct position.
        $offset = ($run !== null) ? (int) $run->consumed : 0;

        $allCandidates = $this->discovery->discover($criteria, $offset + $budget);
        $candidates    = array_slice($allCandidates, $offset);

        $completedThisAttempt = 0;

        foreach ($candidates as $candidate) {
            if ($completedThisAttempt >= $budget) {
                break;
            }

            $domain = $candidate['domain'] ?? null;

            // Dead branch: CompanyDiscoveryService filters no-domain results in both
            // drivers; this guard is kept for safety only.
            if (! $domain) {
                continue;
            }

            // CAS expected position: consumed must equal this value for our UPDATE to land.
            $expected    = $offset + $completedThisAttempt;
            $hunterCalled = false; // declared before try so catch can read it

            try {
                // ── Step 1: Scoring gate ─────────────────────────────────────
                $score       = null;
                $explanation = null;

                if ($autoScoring) {
                    ['score' => $score, 'explanation' => $explanation] =
                        $this->scoring->score($candidate, $criteria);
                }

                // ── Step 2: Enrichment decision ──────────────────────────────
                // Enrich when: auto_enrich is on, AND either scoring is off OR score passes the gate,
                // AND the per-attempt contact budget has not been exhausted.
                // Once contactBudget is spent, companies continue being discovered but Hunter is skipped.
                $shouldEnrich = $autoEnrich && (! $autoScoring || $score >= $minScore) && ($contactSpent < $contactBudget);

                // ── Step 3: Hunter enrichment (if gate passed) ───────────────
                // $hunterCalled tracks whether Hunter was actually invoked for this candidate.
                // We debit the contact budget immediately after the call so the gate reflects
                // calls already made even if a subsequent upsert throws.
                $hunterCalled = $shouldEnrich;
                $enrichment   = $shouldEnrich ? $this->hunter->domainSearch($domain) : null;
                if ($hunterCalled) {
                    $contactSpent++;
                }

                // ── Step 4: Upsert Company + Contacts ────────────────────────
                $company = $this->upsertCompany(
                    $criteria, $domain, $candidate, $enrichment,
                    $score, $explanation, $autoScoring
                );

                $isNew = $company->wasRecentlyCreated;

                $contactCount = ($enrichment !== null)
                    ? $this->contactUpsert->upsertFromHunter($company, $domain, $enrichment['emails'] ?? [])
                    : 0;

                // ── Step 5: Determine low-score flag ────────────────────────
                // A candidate is "low score" when auto_enrich is on, scoring is on,
                // and the score is below the gate.
                $isLowScore = $autoEnrich && $autoScoring && ($score < $minScore);

                // ── Step 6: CAS debit + incremental stats (AFTER upserts) ───
                if ($run !== null) {
                    $advanced = DiscoveryRun::where('id', $run->id)
                        ->where('status', 'running')
                        ->where('consumed', $expected)
                        ->update([
                            'consumed'            => DB::raw('consumed + 1'),
                            'companies_count'     => DB::raw('companies_count + 1'),
                            'new_companies_count' => DB::raw('COALESCE(new_companies_count, 0) + ' . ($isNew ? 1 : 0)),
                            'contacts_count'      => DB::raw('contacts_count + ' . (int) $contactCount),
                            'low_score_count'     => DB::raw('low_score_count + ' . ($isLowScore ? 1 : 0)),
                            'contact_consumed'    => DB::raw('contact_consumed + ' . ($hunterCalled ? 1 : 0)),
                        ]);

                    if ($advanced === 0) {
                        // Run was terminalized or a concurrent process owns the cursor.
                        Log::info('[DiscoveryPipelineService] CAS debit blocked — run terminalized or cursor mismatch; returning partial stats.', [
                            'run_id'      => $run->id,
                            'criteria_id' => $criteria->id,
                            'expected'    => $expected,
                        ]);
                        return $stats;
                    }
                }

                // Local stats for return value and logging.
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
            } catch (\Throwable $e) {
                Log::warning('[DiscoveryPipelineService] Domain processing failed — skipping', [
                    'domain'      => $domain,
                    'criteria_id' => $criteria->id,
                    'error'       => $e->getMessage(),
                ]);

                // CAS advance with skipped: consumed still +1 (failing candidate
                // consumes its credit, keeping the cursor exact). No company/contact
                // increments — the candidate was not processed successfully.
                if ($run !== null) {
                    $advanced = DiscoveryRun::where('id', $run->id)
                        ->where('status', 'running')
                        ->where('consumed', $expected)
                        ->update([
                            'consumed'         => DB::raw('consumed + 1'),
                            'skipped_count'    => DB::raw('skipped_count + 1'),
                            'contact_consumed' => DB::raw('contact_consumed + ' . ($hunterCalled ? 1 : 0)),
                        ]);

                    if ($advanced === 0) {
                        return $stats;
                    }
                }

                $stats['skipped']++;
            }

            $completedThisAttempt++;
        }

        return $stats;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Upsert a company row from discovery data.
     *
     * Credit semantics: enrichment_data is only written when $enrichment !== null,
     * preventing null-wipe of previously enriched data when the scoring gate skips
     * Hunter for this candidate. ai_score + ai_explanation are only written when
     * $scored=true to avoid clobbering existing scores when scoring is disabled.
     *
     * Client-downgrade guard: companies with relationship='client' are never
     * downgraded to 'prospect'; only criteria_id is updated.
     *
     * @param  ProspectCriteria $criteria
     * @param  string           $domain
     * @param  array            $candidate      SerpAPI-normalised candidate.
     * @param  array|null       $enrichment     Hunter enrichment data (null when gate skipped).
     * @param  int|null         $score          AI/heuristic score (null when scoring disabled).
     * @param  string|null      $explanation    Score explanation (null when scoring disabled).
     * @param  bool             $scored         Whether scoring ran for this candidate.
     * @return Company
     */
    private function upsertCompany(
        ProspectCriteria $criteria,
        string $domain,
        array $candidate,
        ?array $enrichment,
        ?int $score,
        ?string $explanation,
        bool $scored
    ): Company {
        /** @var Company|null $existing */
        $existing = Company::where('domain', $domain)->first();

        $isClient = $existing && $existing->relationship === 'client';

        // Build attributes to set / update
        $attributes = [
            'criteria_id' => $criteria->id,
            'name'        => $enrichment['organization']
                ?? $candidate['title']
                ?? $domain,
            'sector'      => $enrichment['industry'] ?? null,
            'country'     => $this->mapIso2($enrichment['country'] ?? null),
            'source'      => 'discovered',
        ];

        // Only write enrichment_data when enrichment ran — do not null-wipe
        // previously enriched data when the scoring gate skips Hunter this pass.
        if ($enrichment !== null) {
            $attributes['enrichment_data'] = $enrichment['raw'] ?? null;
        }

        // Only write scoring fields when scoring actually ran — do not wipe
        // existing scores when auto_scoring is disabled.
        if ($scored) {
            $attributes['ai_score']       = $score;
            $attributes['ai_explanation'] = $explanation;
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

        // New company
        return Company::create(array_merge($attributes, ['domain' => $domain]));
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
