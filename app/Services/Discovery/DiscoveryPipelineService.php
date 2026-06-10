<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\Log;

/**
 * DiscoveryPipelineService — orchestrates SerpAPI discovery → Hunter enrichment
 * → Company + Contact upserts for a given ProspectCriteria.
 *
 * Constructor uses CONCRETE classes only, so app(DiscoveryPipelineService::class)
 * resolves via Laravel's auto-wiring without any manual binding.
 *
 * Idempotent: re-running the same criteria updates existing rows, never duplicates.
 * Client-relationship guard: companies already marked relationship='client' are never
 *   downgraded to 'prospect'; only criteria_id is updated.
 *
 * email_kind mapping:
 *   Hunter type = 'generic'  →  email_kind = 'role'
 *   Hunter type = 'personal' →  email_kind = 'personal'
 * Both kinds are imported here. The cold-send filter (Phase 3) will exclude
 * 'personal' at send time; discovery gathers all.
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
    ) {}

    /**
     * Run the full discovery pipeline for the given criteria.
     *
     * @param  ProspectCriteria  $criteria
     * @param  int|null          $cap      External cap from the quota service (credits_reserved − consumed).
     *                                     null = no external cap; use criteria daily_limit only.
     * @param  DiscoveryRun|null $run      Live run row for consumed metering (incremented before each Hunter call).
     * @return array{companies: int, contacts: int, skipped: int}
     */
    public function run(ProspectCriteria $criteria, ?int $cap = null, ?DiscoveryRun $run = null): array
    {
        // When an external cap is provided, honour both the criteria daily_limit and the cap.
        $max   = $cap !== null ? min($criteria->daily_limit ?: 20, $cap) : ($criteria->daily_limit ?: 20);
        $stats = ['companies' => 0, 'contacts' => 0, 'skipped' => 0];

        // Step 1: Discover domains via SerpAPI (local fixture or live)
        $candidates = $this->discovery->discover($criteria, $max);

        $processed = 0;

        foreach ($candidates as $candidate) {
            if ($processed >= $max) {
                break;
            }

            $domain = $candidate['domain'] ?? null;

            if (! $domain) {
                $stats['skipped']++;
                // No-domain skips never reach Hunter — must NOT consume a credit.
                continue;
            }

            try {
                // ── Consumed metering: conditional atomic debit ───────────────
                // Guarded UPDATE: only debits when the run is still 'running' and
                // hasn't already reached its budget (consumed < credits_reserved).
                // Zero rows affected means the run was terminalized (by the stale
                // terminalizer or a concurrent process) or its budget is exhausted —
                // both require the loop to stop immediately with partial stats.
                // Persisted before the Hunter call so a killed worker never loses
                // the tally.
                // Only no-domain skips (above) are excluded from metering.
                if ($run !== null) {
                    $debited = DiscoveryRun::whereKey($run->id)
                        ->where('status', 'running')
                        ->whereColumn('consumed', '<', 'credits_reserved')
                        ->increment('consumed');

                    if ($debited === 0) {
                        Log::info('[DiscoveryPipelineService] Debit guard blocked — run terminalized or budget exhausted; returning partial stats.', [
                            'run_id'      => $run->id,
                            'criteria_id' => $criteria->id,
                        ]);
                        return $stats;
                    }

                    // Sync the in-memory object so downstream code sees fresh consumed.
                    $run->consumed = ($run->consumed ?? 0) + 1;
                }

                // Step 2: Enrich via Hunter
                $enrichment = $this->hunter->domainSearch($domain);

                // Step 3: Upsert Company
                $company = $this->upsertCompany($criteria, $domain, $candidate, $enrichment);
                $stats['companies']++;

                // Step 4: Upsert Contacts
                if ($enrichment) {
                    $contactCount = $this->upsertContacts($company, $domain, $enrichment['emails'] ?? []);
                    $stats['contacts'] += $contactCount;
                }
            } catch (\Throwable $e) {
                Log::warning('[DiscoveryPipelineService] Domain processing failed — skipping', [
                    'domain'      => $domain,
                    'criteria_id' => $criteria->id,
                    'error'       => $e->getMessage(),
                ]);
                $stats['skipped']++;
            }

            $processed++;
        }

        return $stats;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function upsertCompany(
        ProspectCriteria $criteria,
        string $domain,
        array $candidate,
        ?array $enrichment
    ): Company {
        /** @var Company|null $existing */
        $existing = Company::where('domain', $domain)->first();

        $isClient = $existing && $existing->relationship === 'client';

        // Build attributes to set / update
        $attributes = [
            'criteria_id'     => $criteria->id,
            'name'            => $enrichment['organization']
                ?? $candidate['title']
                ?? $domain,
            'sector'          => $enrichment['industry'] ?? null,
            'country'         => $this->mapIso2($enrichment['country'] ?? null),
            'enrichment_data' => $enrichment['raw'] ?? null,
            'source'          => 'discovered',
        ];

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
     * Upsert contacts for a company. Returns the number of contacts processed.
     */
    private function upsertContacts(Company $company, string $domain, array $emails): int
    {
        $count = 0;

        foreach ($emails as $emailData) {
            $emailAddress = $emailData['value'] ?? null;

            if (! $emailAddress) {
                continue;
            }

            $firstName  = $emailData['first_name']  ?? '';
            $lastName   = $emailData['last_name']   ?? '';
            $name       = trim("{$firstName} {$lastName}");

            if ($name === '') {
                // Fall back to the local-part of the address
                $name = strstr($emailAddress, '@', true) ?: $emailAddress;
            }

            $emailKind = ($emailData['type'] ?? '') === 'generic' ? 'role' : 'personal';

            $verificationResult = data_get($emailData, 'verification.result');

            /** @var Contact|null $existing */
            $existing = Contact::where('email', $emailAddress)->first();

            if ($existing) {
                // Idempotent update — keep existing status
                $existing->fill([
                    'company_id'               => $company->id,
                    'name'                     => $name,
                    'position'                 => $emailData['position'] ?? $existing->position,
                    'source'                   => 'discovered',
                    'legal_basis'              => 'legitimate_interest',
                    'email_kind'               => $emailKind,
                    'source_url'               => $domain,
                    'source_captured_at'       => now(),
                    'email_verification_status'=> $verificationResult,
                ]);
                $existing->save();
            } else {
                Contact::create([
                    'company_id'               => $company->id,
                    'email'                    => $emailAddress,
                    'name'                     => $name,
                    'position'                 => $emailData['position'] ?? null,
                    'source'                   => 'discovered',
                    'status'                   => 'new',
                    'legal_basis'              => 'legitimate_interest',
                    'email_kind'               => $emailKind,
                    'source_url'               => $domain,
                    'source_captured_at'       => now(),
                    'email_verification_status'=> $verificationResult,
                ]);
            }

            $count++;
        }

        return $count;
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
