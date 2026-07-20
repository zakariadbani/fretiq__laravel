<?php

namespace App\Services\Discovery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HunterEnrichmentService — Hunter.io domain and company enrichment.
 *
 * Driver selection:  config('services.hunter.driver', 'local')
 *   'local'  → loads database/fixtures/discovery/hunter.json — NO HTTP
 *   anything else  → calls live Hunter API
 *
 * A live enrichment bundle makes two provider requests (and can therefore use
 * two Hunter API units): Domain Search for emails and Company Enrichment for
 * authoritative organization metadata.
 *
 * Returns ['organization', 'industry', 'country', 'emails' => [...], 'raw' => data]
 * or null when the domain cannot be enriched.
 *
 * email_kind mapping (applied upstream in DiscoveryPipelineService):
 *   type = 'generic'  →  email_kind = 'role'
 *   type = 'personal' →  email_kind = 'personal'
 */
class HunterEnrichmentService
{
    private bool $lastSearchSystemicFailure = false;

    /**
     * Fetch domain-level data including contact emails.
     *
     * @return array{organization: ?string, industry: ?string, country: ?string, emails: list<mixed>, raw: array}|null
     */
    public function domainSearch(string $domain, int $limit = 10, ?int $timeoutSeconds = null): ?array
    {
        if ($this->isLocal()) {
            return $this->domainSearchFromFixtures($domain);
        }

        return $this->domainSearchFromHunter($domain, $limit, $timeoutSeconds);
    }

    /** @return array{status:'ok'|'empty'|'provider_failed',data:?array} */
    public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null): array
    {
        $this->lastSearchSystemicFailure = false;

        if ($this->isLocal()) {
            $path = base_path('database/fixtures/discovery/hunter.json');
            if (! file_exists($path) || ! is_array(json_decode(file_get_contents($path), true))) {
                return ['status' => 'provider_failed', 'data' => null];
            }
        } elseif (! config('services.hunter.api_key')) {
            return ['status' => 'provider_failed', 'data' => null];
        }

        $data = $this->domainSearch($domain, $limit, $timeoutSeconds);

        // A systemic failure on either half of the bundle must trip the caller's
        // circuit breaker even if the other endpoint returned partial metadata.
        if ($this->lastSearchSystemicFailure) {
            return ['status' => 'provider_failed', 'data' => $data];
        }

        if ($data !== null) {
            return ['status' => 'ok', 'data' => $data];
        }

        return [
            'status' => 'empty',
            'data' => null,
        ];
    }

    /**
     * Fetch live Hunter.io account balance/usage for the superadmin quota page.
     *
     * Verified live 2026-07-04 (STATUS 200): Hunter /account data.* returns requests.searches.{used,available}, requests.verifications.{used,available}, plan_name, reset_date.
     *
     * @return array{searches_used: ?int, searches_available: ?int, verifications_used: ?int, verifications_available: ?int, plan_name: ?string, reset_date: ?string}|null
     */
    public function accountUsage(): ?array
    {
        if ($this->isLocal()) {
            return null;
        }

        $apiKey = config('services.hunter.api_key');

        if (! $apiKey) {
            return null;
        }

        // ponytail: cached null-on-failure for 10 min is acceptable here — same rationale as
        // CompanyDiscoveryService::accountUsage().
        return Cache::remember('provider.hunter.account', now()->addMinutes(10), function () use ($apiKey) {
            try {
                $response = Http::timeout(15)->acceptJson()->get('https://api.hunter.io/v2/account', [
                    'api_key' => $apiKey,
                ]);

                if ($response->failed()) {
                    Log::warning('[HunterEnrichmentService] Hunter account request failed', [
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                $data = $response->json('data', []);

                return [
                    'searches_used' => data_get($data, 'requests.searches.used'),
                    'searches_available' => data_get($data, 'requests.searches.available'),
                    'verifications_used' => data_get($data, 'requests.verifications.used'),
                    'verifications_available' => data_get($data, 'requests.verifications.available'),
                    'plan_name' => $data['plan_name'] ?? null,
                    'reset_date' => $data['reset_date'] ?? null,
                ];
            } catch (\Throwable $e) {
                Log::warning('[HunterEnrichmentService] Hunter account call threw an exception', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }
    // ── Local fixture driver ──────────────────────────────────────────────────

    private function domainSearchFromFixtures(string $domain): ?array
    {
        $path = base_path('database/fixtures/discovery/hunter.json');

        if (! file_exists($path)) {
            Log::warning('[HunterEnrichmentService] Local fixture missing', ['path' => $path]);

            return null;
        }

        $fixtures = json_decode(file_get_contents($path), true);

        if (! is_array($fixtures)) {
            Log::warning('[HunterEnrichmentService] Local fixture is not a valid JSON object.');

            return null;
        }

        // Exact match first; fall back to a domain-scoped generic fixture. The
        // fallback addresses must be unique to the requested company or local
        // multi-company runs would manufacture cross-company email collisions.
        $exact = array_key_exists($domain, $fixtures);
        $data = $exact ? $fixtures[$domain] : ($fixtures['__default__'] ?? null);

        if (! $data) {
            return null;
        }

        if (! $exact && isset($data['emails']) && is_array($data['emails'])) {
            $data['emails'] = array_map(function (mixed $email) use ($domain): mixed {
                if (! is_array($email) || ! is_string($email['value'] ?? null)) {
                    return $email;
                }

                $localPart = strstr($email['value'], '@', true);
                if ($localPart !== false && $localPart !== '') {
                    $email['value'] = $localPart.'@'.$domain;
                }

                return $email;
            }, $data['emails']);
        }

        return $this->normalizeHunterData($data);
    }

    // ── Real Hunter driver ────────────────────────────────────────────────────

    private function domainSearchFromHunter(string $domain, int $limit, ?int $timeoutSeconds = null): ?array
    {
        $apiKey = config('services.hunter.api_key');

        if (! $apiKey) {
            $this->lastSearchSystemicFailure = true;
            Log::warning('[HunterEnrichmentService] Hunter API key not configured — skipping enrichment.', [
                'domain' => $domain,
            ]);

            return null;
        }

        $domainSearchData = null;
        $companyData = null;
        $domainPromise = null;
        $companyPromise = null;

        $timeout = max(1, $timeoutSeconds ?? 20);

        try {
            $domainPromise = Http::timeout($timeout)
                ->acceptJson()
                ->async()
                ->get('https://api.hunter.io/v2/domain-search', [
                    'domain' => $domain,
                    'api_key' => $apiKey,
                    'limit' => $limit,
                ]);
        } catch (\Throwable $e) {
            $this->lastSearchSystemicFailure = true;
            Log::error('[HunterEnrichmentService] Hunter domain-search threw an exception', [
                'domain' => $domain,
                'exception' => $e::class,
            ]);
        }

        try {
            $companyPromise = Http::timeout($timeout)
                ->acceptJson()
                ->async()
                ->get('https://api.hunter.io/v2/companies/find', [
                    'domain' => $domain,
                    'api_key' => $apiKey,
                ]);
        } catch (\Throwable $e) {
            $this->lastSearchSystemicFailure = true;
            Log::error('[HunterEnrichmentService] Hunter company enrichment threw an exception', [
                'domain' => $domain,
                'exception' => $e::class,
            ]);
        }

        if ($domainPromise !== null) {
            try {
                $domainResponse = $domainPromise->wait();

                if ($domainResponse->failed()) {
                    if ($this->isSystemicStatus($domainResponse->status())) {
                        $this->lastSearchSystemicFailure = true;
                    }
                    Log::warning('[HunterEnrichmentService] Hunter domain-search failed', [
                        'domain' => $domain,
                        'status' => $domainResponse->status(),
                    ]);
                } else {
                    $domainSearchData = $domainResponse->json('data', []);
                }
            } catch (\Throwable $e) {
                $this->lastSearchSystemicFailure = true;
                Log::error('[HunterEnrichmentService] Hunter domain-search threw an exception', [
                    'domain' => $domain,
                    'exception' => $e::class,
                ]);
            }
        }

        if ($companyPromise !== null) {
            try {
                $companyResponse = $companyPromise->wait();

                if ($companyResponse->failed()) {
                    if ($this->isSystemicStatus($companyResponse->status())) {
                        $this->lastSearchSystemicFailure = true;
                    }
                    Log::warning('[HunterEnrichmentService] Hunter company enrichment failed', [
                        'domain' => $domain,
                        'status' => $companyResponse->status(),
                    ]);
                } else {
                    $companyData = $companyResponse->json('data', []);
                }
            } catch (\Throwable $e) {
                $this->lastSearchSystemicFailure = true;
                Log::error('[HunterEnrichmentService] Hunter company enrichment threw an exception', [
                    'domain' => $domain,
                    'exception' => $e::class,
                ]);
            }
        }

        if ($domainSearchData === null && $companyData === null) {
            return null;
        }

        return [
            'organization' => data_get($companyData, 'name')
                ?? data_get($domainSearchData, 'organization'),
            'industry' => data_get($companyData, 'category.industry'),
            'country' => data_get($companyData, 'geo.countryCode'),
            'emails' => data_get($domainSearchData, 'emails', []),
            'raw' => [
                'company' => $companyData,
                'domain_search' => $domainSearchData,
            ],
        ];
    }

    // ── Shared normalizer ─────────────────────────────────────────────────────

    private function normalizeHunterData(array $data): array
    {
        return [
            'organization' => $data['organization'] ?? null,
            'industry' => $data['industry'] ?? null,
            'country' => $data['country'] ?? null,
            'emails' => $data['emails'] ?? [],
            'raw' => $data,
        ];
    }

    private function isLocal(): bool
    {
        return config('services.hunter.driver', 'local') === 'local';
    }

    private function isSystemicStatus(int $status): bool
    {
        return in_array($status, [401, 403, 408, 425, 429], true) || $status >= 500;
    }
}
