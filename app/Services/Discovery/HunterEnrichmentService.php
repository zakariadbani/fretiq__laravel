<?php

namespace App\Services\Discovery;

use App\Services\Providers\Hunter\HunterClient;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderExecution;
use App\Services\Providers\ProviderRequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Compatibility facade for the discovery pipeline.
 *
 * Hunter transport, authentication and provider accounting live exclusively in
 * HunterClient. This service only combines normalized business results and
 * preserves the pipeline's partial-success behavior.
 */
class HunterEnrichmentService
{
    private bool $lastSearchSystemicFailure = false;

    private readonly HunterClient $client;

    public function __construct(?HunterClient $client = null)
    {
        $this->client = $client ?? app(HunterClient::class);
    }

    /**
     * @return array{organization: ?string, industry: ?string, country: ?string, emails: list<mixed>, raw: array}|null
     */
    public function domainSearch(string $domain, int $limit = 10, ?int $timeoutSeconds = null, ?ProviderCallContext $context = null): ?array
    {
        if ($this->isLocal()) {
            return $this->domainSearchFromClientFixture($domain, $limit, $context);
        }

        return $this->domainSearchFromHunter($domain, $limit, $timeoutSeconds, $context);
    }

    /** @return array{status:'ok'|'empty'|'provider_failed',data:?array} */
    public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null, ?ProviderCallContext $context = null): array
    {
        $this->lastSearchSystemicFailure = false;
        $data = $this->domainSearch($domain, $limit, $timeoutSeconds, $context);

        if ($this->lastSearchSystemicFailure) {
            return ['status' => 'provider_failed', 'data' => $data];
        }
        if ($data !== null) {
            return ['status' => 'ok', 'data' => $data];
        }

        return ['status' => 'empty', 'data' => null];
    }

    /**
     * Fetch the free Hunter usage endpoint and retain the legacy quota-view shape.
     *
     * @return array{searches_used: ?int, searches_available: ?int, verifications_used: ?int, verifications_available: ?int, plan_name: ?string, reset_date: ?string}|null
     */
    public function accountUsage(): ?array
    {
        if ($this->isLocal()) {
            try {
                $execution = $this->client->accountUsage($this->legacyContext('account_usage', 0));
                $this->settle($execution, 1, 0);
            } catch (Throwable) {
                // The local quota page remains unavailable by design.
            }

            return null;
        }

        $fetch = function (): ?array {
            try {
                $execution = $this->client->accountUsage($this->legacyContext('account_usage', 0));
                $data = $execution->response?->data ?? [];
                $this->settle($execution, $data === [] ? 0 : 1, 0);

                // ponytail: falls back to credits when searches is absent — verified live
                // 2026-08-18 that Hunter reports credits and searches identically on this
                // plan (same used/available/remaining), so this isn't a guessed stand-in.
                // Add a distinct credits meter only if the two ever diverge.
                $credits = data_get($data, 'requests.credits');
                $searches = data_get($data, 'requests.searches', is_array($credits) ? $credits : []);
                $verifications = data_get($data, 'requests.verifications', []);

                return [
                    'searches_used' => $this->nullableInt(data_get($searches, 'used')),
                    'searches_available' => $this->nullableInt(data_get($searches, 'remaining', data_get($searches, 'available'))),
                    'verifications_used' => $this->nullableInt(data_get($verifications, 'used')),
                    'verifications_available' => $this->nullableInt(data_get($verifications, 'remaining', data_get($verifications, 'available'))),
                    'plan_name' => is_string($data['plan_name'] ?? null) ? $data['plan_name'] : null,
                    'reset_date' => isset($data['reset_date']) ? (string) $data['reset_date'] : null,
                ];
            } catch (ProviderRequestException $exception) {
                Log::channel('discovery')->warning('[HunterEnrichmentService] Hunter usage request failed', [
                    'status' => $exception->httpStatus,
                    'error_code' => $exception->safeCode,
                ]);

                return null;
            } catch (Throwable $exception) {
                Log::channel('discovery')->warning('[HunterEnrichmentService] Hunter usage request failed', [
                    'exception' => $exception::class,
                ]);

                return null;
            }
        };

        // ponytail: the cached value is always an array so a failed fetch actually caches —
        // Cache::remember treats a stored null as a miss and would re-hit the vendor on every
        // page load. Key is versioned because the cached SHAPE changed; a pre-existing entry
        // under the old key would be read as a malformed payload after deploy.
        $cached = Cache::remember('provider.hunter.account.v2', now()->addMinutes(10), fn (): array => [
            'payload' => $fetch(),
            'fetched_at' => now()->toIso8601String(),
        ]);

        return $cached['payload'] === null
            ? null
            : $cached['payload'] + ['fetched_at' => $cached['fetched_at']];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<mixed>, meta: array<string, mixed>}|null
     */
    public function usageHistory(array $filters = []): ?array
    {
        try {
            $execution = $this->client->usageHistory($this->legacyContext('usage_history', 0), $filters);
            $data = $execution->response?->data ?? [];
            $meta = $execution->response?->meta ?? [];
            $this->settle($execution, count($data), 0);

            return ['data' => array_values($data), 'meta' => $meta];
        } catch (ProviderRequestException $exception) {
            Log::channel('discovery')->warning('[HunterEnrichmentService] Hunter usage history request failed', [
                'status' => $exception->httpStatus,
                'error_code' => $exception->safeCode,
            ]);
        } catch (Throwable $exception) {
            Log::channel('discovery')->warning('[HunterEnrichmentService] Hunter usage history request failed', [
                'exception' => $exception::class,
            ]);
        }

        return null;
    }

    private function domainSearchFromClientFixture(string $domain, int $limit, ?ProviderCallContext $context = null): ?array
    {
        try {
            $execution = $this->client->domainSearch(
                $context ?? $this->legacyContext('domain_search', 1),
                $domain,
                limit: $limit,
            );
            $data = $execution->response?->data ?? [];
            $this->settle($execution, count((array) ($data['emails'] ?? [])), 0);

            if (($data['domain'] ?? null) === null && ($data['emails'] ?? []) === []) {
                return null;
            }

            // The old local facade exposed the fixture verbatim as raw data.
            unset($data['domain']);

            return $this->normalizeHunterData($data);
        } catch (ProviderRequestException $exception) {
            $this->lastSearchSystemicFailure = true;
            Log::channel('discovery')->error('[HunterEnrichmentService] Local Hunter fixture failed', [
                'error_code' => $exception->safeCode,
            ]);
        } catch (Throwable $exception) {
            $this->lastSearchSystemicFailure = true;
            Log::channel('discovery')->error('[HunterEnrichmentService] Local Hunter fixture failed', [
                'exception' => $exception::class,
            ]);
        }

        return null;
    }

    private function domainSearchFromHunter(string $domain, int $limit, ?int $timeoutSeconds = null, ?ProviderCallContext $context = null): ?array
    {
        $domainSearchData = $this->attemptDomainSearch($domain, $limit, $timeoutSeconds, $context);
        $companyData = $this->attemptCompanyEnrichment($domain, $timeoutSeconds, $context === null ? null : new ProviderCallContext(hash('sha256', $context->idempotencyKey.'|company_enrichment'), (float) config('prospecting.provider_units.hunter.company_enrichment', 0.2), batchId: $context->batchId, itemId: $context->itemId, engine: 'company_enrichment'));

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

    /** @return array<string, mixed>|null */
    private function attemptDomainSearch(string $domain, int $limit, ?int $timeoutSeconds = null, ?ProviderCallContext $context = null): ?array
    {
        try {
            $execution = $this->client->domainSearch(
                $context ?? $this->legacyContext('domain_search', 1),
                $domain,
                limit: $limit,
                timeoutSeconds: $timeoutSeconds,
            );
            $data = $execution->response?->data ?? [];
            $emails = (array) ($data['emails'] ?? []);
            $this->settle($execution, count($emails), $emails === [] ? 0 : 1);

            return $data === [] || (($data['domain'] ?? null) === null && $emails === []) ? null : $data;
        } catch (ProviderRequestException $exception) {
            $this->recordFailure('domain-search', $exception);
        } catch (Throwable $exception) {
            $this->lastSearchSystemicFailure = true;
            Log::channel('discovery')->error('[HunterEnrichmentService] Hunter domain-search failed', [
                'exception' => $exception::class,
            ]);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function attemptCompanyEnrichment(string $domain, ?int $timeoutSeconds = null, ?ProviderCallContext $context = null): ?array
    {
        try {
            $execution = $this->client->companyEnrichment(
                $context ?? $this->legacyContext('company_enrichment', 1),
                $domain,
                $timeoutSeconds,
            );
            $data = $execution->response?->data ?? [];
            $this->settle($execution, $data === [] ? 0 : 1, $data === [] ? 0 : 1);

            return $data === [] ? null : $data;
        } catch (ProviderRequestException $exception) {
            $this->recordFailure('company-enrichment', $exception);
        } catch (Throwable $exception) {
            $this->lastSearchSystemicFailure = true;
            Log::channel('discovery')->error('[HunterEnrichmentService] Hunter company enrichment failed', [
                'exception' => $exception::class,
            ]);
        }

        return null;
    }

    private function recordFailure(string $operation, ProviderRequestException $exception): void
    {
        if ($this->isSystemicStatus($exception->httpStatus) || $exception->safeCode === 'hunter_not_configured') {
            $this->lastSearchSystemicFailure = true;
        }
        Log::channel('discovery')->error('[HunterEnrichmentService] Hunter '.$operation.' failed', [
            'status' => $exception->httpStatus,
            'error_code' => $exception->safeCode,
        ]);
    }

    private function settle(ProviderExecution $execution, int $resultCount, float $consumedUnits): void
    {
        DB::transaction(fn () => app(ProviderCallLedger::class)->settle(
            $execution,
            max(0, $resultCount),
            $this->isLocal() ? 0 : $consumedUnits,
        ));
    }

    private function legacyContext(string $operation, float $reservedUnits): ProviderCallContext
    {
        return new ProviderCallContext(
            hash('sha256', 'hunter-legacy:'.$operation.':'.Str::uuid()),
            $this->isLocal() ? 0 : $reservedUnits,
            engine: $operation,
        );
    }

    /** @param array<string, mixed> $data */
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

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function isLocal(): bool
    {
        return config('services.hunter.driver', 'local') === 'local';
    }

    private function isSystemicStatus(?int $status): bool
    {
        return $status !== null && (in_array($status, [401, 403, 408, 425, 429], true) || $status >= 500);
    }
}
