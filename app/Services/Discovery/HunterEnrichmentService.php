<?php

namespace App\Services\Discovery;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HunterEnrichmentService — Hunter.io domain-search enrichment.
 *
 * Driver selection:  config('services.hunter.driver', 'local')
 *   'local'  → loads database/fixtures/discovery/hunter.json — NO HTTP
 *   anything else  → calls live Hunter API
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
    /**
     * Fetch domain-level data including contact emails.
     *
     * @return array{organization: ?string, industry: ?string, country: ?string, emails: list<mixed>, raw: array}|null
     */
    public function domainSearch(string $domain, int $limit = 10): ?array
    {
        if ($this->isLocal()) {
            return $this->domainSearchFromFixtures($domain);
        }

        return $this->domainSearchFromHunter($domain, $limit);
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

        // Exact match first; fall back to __default__ so any domain returns data
        $data = $fixtures[$domain] ?? $fixtures['__default__'] ?? null;

        if (! $data) {
            return null;
        }

        return $this->normalizeHunterData($data);
    }

    // ── Real Hunter driver ────────────────────────────────────────────────────

    private function domainSearchFromHunter(string $domain, int $limit): ?array
    {
        $apiKey = config('services.hunter.api_key');

        if (! $apiKey) {
            Log::warning('[HunterEnrichmentService] Hunter API key not configured — skipping enrichment.', [
                'domain' => $domain,
            ]);
            return null;
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->get('https://api.hunter.io/v2/domain-search', [
                    'domain'  => $domain,
                    'api_key' => $apiKey,
                    'limit'   => $limit,
                ]);

            if ($response->failed()) {
                Log::warning('[HunterEnrichmentService] Hunter domain-search failed', [
                    'domain' => $domain,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json('data', []);

            return $this->normalizeHunterData($data);
        } catch (\Throwable $e) {
            Log::error('[HunterEnrichmentService] Hunter domain-search threw an exception', [
                'domain' => $domain,
                'error'  => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ── Shared normalizer ─────────────────────────────────────────────────────

    private function normalizeHunterData(array $data): array
    {
        return [
            'organization' => $data['organization'] ?? null,
            'industry'     => $data['industry']     ?? null,
            'country'      => $data['country']       ?? null,
            'emails'       => $data['emails']        ?? [],
            'raw'          => $data,
        ];
    }

    private function isLocal(): bool
    {
        return config('services.hunter.driver', 'local') === 'local';
    }
}
