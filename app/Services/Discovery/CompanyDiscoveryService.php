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
     *
     * Pure criteria → string[] function; no side effects, no HTTP.
     * Public visibility enables unit testing and the future preview endpoint (D12).
     *
     * Falls back to France + Maroc (sensible TCL defaults) when countries is empty.
     * ISO-2 codes are mapped to French labels via config('global.data.company_countries').
     * Legacy free-text values (e.g. "France") and unknown codes pass through unchanged.
     *
     * Result is sliced to config('services.serpapi.max_queries_per_run', 40) — D10.
     *
     * @param  ProspectCriteria  $criteria
     * @return list<string>
     */
    public function buildQueries(ProspectCriteria $criteria): array
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

        // ISO-2 → French label mapping (D10 / D12).
        // Legacy free-text ("France") and unknown codes pass through unchanged.
        $labels = config('global.data.company_countries', []);
        $raw    = ! empty($countries) ? $countries : ['France', 'Maroc'];

        $countryTokens = array_map(function ($v) use ($labels) {
            if (isset($labels[$v])) {
                // ISO code → French label
                return $labels[$v];
            }
            // Passthrough: already a label or junk.
            // Log at debug only when $v is neither a key NOR a value in company_countries.
            if (! in_array($v, $labels, true)) {
                Log::debug('[CompanyDiscoveryService] Unrecognised country token — passing through.', [
                    'token' => $v,
                ]);
            }
            return $v;
        }, $raw);

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

        $queries = array_values(array_unique(array_filter($queries)));

        // D10: budget slice — cap query list to avoid 700+ queries from UE-27 × sectors.
        // 27 countries × (6 keywords + 2×sectors) can exceed 700 × 600 ms.
        // daily_limit caps results, not queries; the budget caps API consumption.
        $budget  = (int) config('services.serpapi.max_queries_per_run', 40);
        $total   = count($queries);
        if ($total > $budget) {
            $dropped = $total - $budget;
            Log::info('[CompanyDiscoveryService] Query budget exceeded — dropping queries.', [
                'criteria_id' => $criteria->id,
                'total'       => $total,
                'budget'      => $budget,
                'dropped'     => $dropped,
            ]);
            $queries = array_slice($queries, 0, $budget);
        }

        return $queries;
    }

    private function isLocal(): bool
    {
        return config('services.serpapi.driver', 'local') === 'local';
    }
}
