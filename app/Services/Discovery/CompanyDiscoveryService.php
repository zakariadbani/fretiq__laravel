<?php

namespace App\Services\Discovery;

use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CompanyDiscoveryService — SerpAPI-backed company domain discovery.
 *
 * Driver selection:  config('services.serpapi.driver', 'local')
 *   'local'  → loads database/fixtures/discovery/serpapi.json — NO HTTP
 *   anything else  → calls live SerpAPI
 *
 * Returns a deduped list of ['domain', 'title', 'snippet', 'url'] capped at $max.
 */
class CompanyDiscoveryService
{
    /**
     * Discover companies matching the given criteria.
     *
     * @return list<array{domain: string, title: ?string, snippet: ?string, url: string}>
     */
    public function discover(ProspectCriteria $criteria, int $max = 20): array
    {
        if ($this->isLocal()) {
            return $this->discoverFromFixtures($max);
        }

        return $this->discoverFromSerpApi($criteria, $max);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function extractDomain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);

        return $host ?: null;
    }

    // ── Local fixture driver ──────────────────────────────────────────────────

    private function discoverFromFixtures(int $max): array
    {
        $path = base_path('database/fixtures/discovery/serpapi.json');

        if (! file_exists($path)) {
            Log::warning('[CompanyDiscoveryService] Local fixture missing', ['path' => $path]);
            return [];
        }

        $raw = json_decode(file_get_contents($path), true);

        if (! is_array($raw)) {
            Log::warning('[CompanyDiscoveryService] Local fixture is not a valid JSON array.');
            return [];
        }

        $results = [];
        $seen    = [];

        foreach ($raw as $item) {
            if (count($results) >= $max) {
                break;
            }

            $url    = $item['link'] ?? $item['url'] ?? null;
            $domain = $url ? $this->extractDomain($url) : null;

            if (! $domain || isset($seen[$domain])) {
                continue;
            }

            $seen[$domain] = true;
            $results[]     = [
                'domain'  => $domain,
                'title'   => $item['title'] ?? null,
                'snippet' => $item['snippet'] ?? null,
                'url'     => $url,
            ];
        }

        return $results;
    }

    // ── Real SerpAPI driver ───────────────────────────────────────────────────

    private function discoverFromSerpApi(ProspectCriteria $criteria, int $max): array
    {
        $apiKey = config('services.serpapi.api_key');

        if (! $apiKey) {
            Log::warning('[CompanyDiscoveryService] SerpAPI key not configured — skipping discovery.', [
                'criteria_id' => $criteria->id,
            ]);
            return [];
        }

        $queries = $this->buildQueries($criteria);
        $results = [];
        $seen    = [];

        foreach ($queries as $query) {
            if (count($results) >= $max) {
                break;
            }

            try {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->get('https://serpapi.com/search.json', [
                        'api_key' => $apiKey,
                        'engine'  => 'google',
                        'q'       => $query,
                        'num'     => 10,
                    ]);

                if ($response->failed()) {
                    Log::warning('[CompanyDiscoveryService] SerpAPI request failed', [
                        'criteria_id' => $criteria->id,
                        'query'       => $query,
                        'status'      => $response->status(),
                    ]);
                    continue;
                }

                $organicResults = $response->json('organic_results', []);

                foreach ($organicResults as $item) {
                    if (count($results) >= $max) {
                        break;
                    }

                    $url    = $item['link'] ?? null;
                    $domain = $url ? $this->extractDomain($url) : null;

                    if (! $domain || isset($seen[$domain])) {
                        continue;
                    }

                    $seen[$domain] = true;
                    $results[]     = [
                        'domain'  => $domain,
                        'title'   => $item['title'] ?? null,
                        'snippet' => $item['snippet'] ?? null,
                        'url'     => $url,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('[CompanyDiscoveryService] SerpAPI call threw an exception', [
                    'criteria_id' => $criteria->id,
                    'query'       => $query,
                    'error'       => $e->getMessage(),
                ]);
            }

            // Polite rate-limiting between queries (~600 ms)
            usleep(600_000);
        }

        return $results;
    }

    /**
     * Build SerpAPI search queries from criteria.
     * Falls back to sensible freight / TCL-relevant defaults when fields are empty.
     */
    private function buildQueries(ProspectCriteria $criteria): array
    {
        $sectors   = $criteria->sectors   ?? [];
        $countries = $criteria->countries ?? [];

        // TCL France context: freight-forwarding defaults
        $freightKeywords = [
            'transitaire',
            'freight forwarder',
            'commissionnaire de transport',
            'logistique',
            'transport maritime',
            'transport aérien',
        ];

        $queries = [];

        $countryTokens = ! empty($countries) ? $countries : ['France', 'Maroc'];

        foreach ($countryTokens as $country) {
            // Sector-specific queries
            foreach ($sectors as $sector) {
                $queries[] = "{$sector} transitaire {$country}";
                $queries[] = "{$sector} freight forwarder {$country}";
            }

            // Freight-keyword queries
            foreach ($freightKeywords as $kw) {
                $queries[] = "{$kw} {$country} entreprise";
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    private function isLocal(): bool
    {
        return config('services.serpapi.driver', 'local') === 'local';
    }
}
