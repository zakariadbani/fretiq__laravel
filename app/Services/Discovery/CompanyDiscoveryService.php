<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CompanyDiscoveryService — SerpAPI-backed company domain discovery.
 *
 * Driver selection:  config('services.serpapi.driver', 'local')
 *   'local'  → loads database/fixtures/discovery/serpapi.json — NO HTTP
 *   anything else  → calls live SerpAPI
 *
 * Returns a deduped list of ['domain', 'title', 'snippet', 'url', 'discovery_query'] capped at $max.
 * The 'discovery_query' key is only set by discoverFromSerpApi() (the query that
 * surfaced the candidate) — discoverFromFixtures() (local dev driver) omits it.
 *
 * Query source (discoverFromSerpApi only): uses criteria.ai_queries (cached AI-generated
 * queries, enabled ones only) when present, else falls back to buildQueries(). buildQueries()
 * itself stays a pure criteria→string[] function (no HTTP) — the live IntentQueryService call
 * only happens from the controller's generateQueries() action, never from here or from preview.
 */
class CompanyDiscoveryService
{
    public const PAGE_SIZE = 10;
    private const MAX_SEARCHES_PER_RUN = 15;

    public function __construct(
        private readonly IntentQueryService $intentQuery = new IntentQueryService(),
    ) {}

    /**
     * Discover companies matching the given criteria.
     *
     * @return list<array{domain: string, title: ?string, snippet: ?string, url: string}>
     */
    public function discover(ProspectCriteria $criteria, int|DiscoveryRun $maxOrRun = 20, ?int $need = null): array
    {
        $run = $maxOrRun instanceof DiscoveryRun ? $maxOrRun : null;
        $max = $run ? (int) $need : (int) $maxOrRun;

        if ($max <= 0) {
            return [];
        }

        if ($this->isLocal()) {
            return $this->discoverFromFixtures($max);
        }

        return $this->discoverFromSerpApi($criteria, $run, $max);
    }

    /**
     * Discover candidates for a queued discovery run using a SerpAPI search-call
     * budget. Unlike discover(..., $run, $need), $searchBudget is the number of
     * provider calls allowed, not the number of companies desired.
     *
     * @return list<array{domain: string, title: ?string, snippet: ?string, url: string}>
     */
    public function discoverForRun(ProspectCriteria $criteria, DiscoveryRun $run, int $searchBudget): array
    {
        $snapshot = $this->snapshot($run);

        if ($searchBudget <= 0 || count($snapshot) > (int) $run->consumed) {
            return $snapshot;
        }

        if ($this->isLocal()) {
            return $this->discoverFromFixtures($searchBudget * self::PAGE_SIZE);
        }

        return $this->discoverFromSerpApiBySearchBudget($criteria, $run, $searchBudget);
    }

    /**
     * Fetch live SerpAPI account balance/usage for the superadmin quota page.
     *
     * Verified live 2026-07-04 (STATUS 200): SerpAPI /account returns plan_searches_left, total_searches_left, this_month_usage, searches_per_month, plan_name, account_email.
     *
     * @return array{plan_searches_left: ?int, total_searches_left: ?int, this_month_usage: ?int, searches_per_month: ?int, plan_name: ?string, account_email: ?string}|null
     */
    public function accountUsage(): ?array
    {
        if ($this->isLocal()) {
            return null;
        }

        $apiKey = config('services.serpapi.api_key');

        if (! $apiKey) {
            return null;
        }

        // ponytail: cached null-on-failure for 10 min is acceptable here — this is a
        // low-traffic admin page, not a hot path; a stuck failure self-heals in 10 min.
        return Cache::remember('provider.serpapi.account', now()->addMinutes(10), function () use ($apiKey) {
            try {
                $response = Http::timeout(15)->acceptJson()->get('https://serpapi.com/account', [
                    'api_key' => $apiKey,
                ]);

                if ($response->failed()) {
                    Log::warning('[CompanyDiscoveryService] SerpAPI account request failed', [
                        'status' => $response->status(),
                    ]);
                    return null;
                }

                $json = $response->json();

                return [
                    'plan_searches_left'  => $json['plan_searches_left']  ?? null,
                    'total_searches_left' => $json['total_searches_left'] ?? null,
                    'this_month_usage'    => $json['this_month_usage']    ?? null,
                    'searches_per_month'  => $json['searches_per_month']  ?? null,
                    'plan_name'           => $json['plan_name']           ?? null,
                    'account_email'       => $json['account_email']       ?? null,
                ];
            } catch (\Throwable $e) {
                Log::warning('[CompanyDiscoveryService] SerpAPI account call threw an exception', [
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
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

    private function discoverFromSerpApi(ProspectCriteria $criteria, ?DiscoveryRun $run, int $need): array
    {
        $apiKey = config('services.serpapi.api_key');

        if (! $apiKey) {
            Log::warning('[CompanyDiscoveryService] SerpAPI key not configured — skipping discovery.', [
                'criteria_id' => $criteria->id,
            ]);
            return [];
        }

        $queries = $this->enabledQueries($criteria);

        if ($queries === []) {
            return $run ? $this->snapshot($run) : [];
        }

        if ($run === null) {
            return $this->discoverFromSerpApiWithoutCursor($criteria, $apiKey, $queries, $need);
        }

        $snapshot = $this->snapshot($run);
        if (count($snapshot) >= $need) {
            return $snapshot;
        }

        $cursors = $this->normaliseCursors($criteria->discovery_cursors ?? [], $queries);
        $order   = $this->rotatedQueryOrder($queries, $cursors);
        $searches = 0;

        foreach ($order as $query) {
            if (count($snapshot) >= $need || $searches >= self::MAX_SEARCHES_PER_RUN) {
                break;
            }

            $key = $this->queryKey($query);

            while (count($snapshot) < $need && $searches < self::MAX_SEARCHES_PER_RUN) {
                $cursors = $this->normaliseCursors($criteria->fresh()?->discovery_cursors ?? [], $queries);
                $cursor  = $cursors[$key] ?? ['q' => $query, 'start' => 0, 'exhausted' => false];

                if ((bool) ($cursor['exhausted'] ?? false)) {
                    break;
                }

                $start = max(0, (int) ($cursor['start'] ?? 0));
                $response = null;

                try {
                    $params = [
                        'api_key' => $apiKey,
                        'engine'  => 'google',
                        'q'       => $query,
                        'num'     => self::PAGE_SIZE,
                        'filter'  => 0,
                    ];

                    if ($start > 0) {
                        $params['start'] = $start;
                    }

                    $response = Http::timeout(20)
                        ->acceptJson()
                        ->get('https://serpapi.com/search.json', $params);
                } catch (\Throwable $e) {
                    Log::warning('[CompanyDiscoveryService] SerpAPI call threw an exception', [
                        'criteria_id' => $criteria->id,
                        'query'       => $query,
                        'start'       => $start,
                        'error'       => $e->getMessage(),
                    ]);
                    return $snapshot;
                }

                $searches++;

                if ($response->failed()) {
                    Log::warning('[CompanyDiscoveryService] SerpAPI request failed', [
                        'criteria_id' => $criteria->id,
                        'query'       => $query,
                        'start'       => $start,
                        'status'      => $response->status(),
                    ]);
                    return $snapshot;
                }

                $organicResults = $response->json('organic_results', []);
                $exhausted = empty($organicResults) || ! (bool) $response->json('serpapi_pagination.next');
                $pageCandidates = $this->normalisePage($criteria, $query, $organicResults, $snapshot);

                $committed = $this->appendPage($criteria, $run, $queries, $query, $start, $pageCandidates, $exhausted);
                if (! $committed) {
                    return $this->snapshot($run->fresh());
                }

                $snapshot = $this->snapshot($run->fresh());

                if ($pageCandidates === [] || $exhausted) {
                    break;
                }

                if (! app()->runningUnitTests()) {
                    usleep(600_000);
                }
            }
        }

        return $snapshot;
    }

    /**
     * Live SerpAPI run mode where the budget is provider search calls. Each call
     * appends up to PAGE_SIZE new candidates to the durable run snapshot.
     */
    private function discoverFromSerpApiBySearchBudget(ProspectCriteria $criteria, DiscoveryRun $run, int $searchBudget): array
    {
        $apiKey = config('services.serpapi.api_key');

        if (! $apiKey) {
            Log::warning('[CompanyDiscoveryService] SerpAPI key not configured — skipping discovery.', [
                'criteria_id' => $criteria->id,
            ]);
            return $this->snapshot($run);
        }

        $queries = $this->enabledQueries($criteria);

        if ($queries === []) {
            return $this->snapshot($run);
        }

        $snapshot = $this->snapshot($run);
        $cursors  = $this->normaliseCursors($criteria->discovery_cursors ?? [], $queries);
        $order    = $this->rotatedQueryOrder($queries, $cursors);
        $searches = 0;
        $maxSearches = min($searchBudget, self::MAX_SEARCHES_PER_RUN);

        foreach ($order as $query) {
            if ($searches >= $maxSearches) {
                break;
            }

            $key = $this->queryKey($query);

            while ($searches < $maxSearches) {
                $cursors = $this->normaliseCursors($criteria->fresh()?->discovery_cursors ?? [], $queries);
                $cursor  = $cursors[$key] ?? ['q' => $query, 'start' => 0, 'exhausted' => false];

                if ((bool) ($cursor['exhausted'] ?? false)) {
                    break;
                }

                $start = max(0, (int) ($cursor['start'] ?? 0));
                $response = null;

                try {
                    $params = [
                        'api_key' => $apiKey,
                        'engine'  => 'google',
                        'q'       => $query,
                        'num'     => self::PAGE_SIZE,
                        'filter'  => 0,
                    ];

                    if ($start > 0) {
                        $params['start'] = $start;
                    }

                    $response = Http::timeout(20)
                        ->acceptJson()
                        ->get('https://serpapi.com/search.json', $params);
                } catch (\Throwable $e) {
                    Log::warning('[CompanyDiscoveryService] SerpAPI call threw an exception', [
                        'criteria_id' => $criteria->id,
                        'query'       => $query,
                        'start'       => $start,
                        'error'       => $e->getMessage(),
                    ]);
                    return $snapshot;
                }

                if ($response->failed()) {
                    Log::warning('[CompanyDiscoveryService] SerpAPI request failed', [
                        'criteria_id' => $criteria->id,
                        'query'       => $query,
                        'start'       => $start,
                        'status'      => $response->status(),
                    ]);
                    return $snapshot;
                }

                $searches++;

                $organicResults = $response->json('organic_results', []);
                $exhausted = empty($organicResults) || ! (bool) $response->json('serpapi_pagination.next');
                $pageCandidates = $this->normalisePage($criteria, $query, $organicResults, $snapshot);

                $committed = $this->appendPage($criteria, $run, $queries, $query, $start, $pageCandidates, $exhausted);
                if (! $committed) {
                    return $this->snapshot($run->fresh());
                }

                $this->recordSerpApiSearch($run);
                $snapshot = $this->snapshot($run->fresh());

                if ($pageCandidates === [] || $exhausted) {
                    break;
                }

                if (! app()->runningUnitTests()) {
                    usleep(600_000);
                }
            }
        }

        return $snapshot;
    }

    private function discoverFromSerpApiWithoutCursor(ProspectCriteria $criteria, string $apiKey, array $queries, int $max): array
    {
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
                        'num'     => self::PAGE_SIZE,
                        'filter'  => 0,
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
                        'domain'          => $domain,
                        'title'           => $item['title'] ?? null,
                        'snippet'         => $item['snippet'] ?? null,
                        'url'             => $url,
                        'discovery_query' => $query,
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
     * @return list<string>
     */
    private function enabledQueries(ProspectCriteria $criteria): array
    {
        $queries = ! empty($criteria->ai_queries)
            ? collect($criteria->ai_queries)
                ->filter(fn ($r) => is_array($r) && ($r['enabled'] ?? true) === true)
                ->pluck('q')
                ->all()
            : $this->buildQueries($criteria);

        return collect($queries)
            ->filter(fn ($q) => is_string($q) && trim($q) !== '')
            ->map(fn ($q) => trim($q))
            ->unique()
            ->values()
            ->all();
    }

    private function queryKey(string $query): string
    {
        return md5($query);
    }

    /**
     * @param list<string> $queries
     * @return array<string, mixed>
     */
    private function normaliseCursors(?array $stored, array $queries): array
    {
        $stored = is_array($stored) ? $stored : [];
        $validKeys = [];
        $cursors = [];

        foreach ($queries as $query) {
            $key = $this->queryKey($query);
            $validKeys[$key] = true;
            $existing = is_array($stored[$key] ?? null) ? $stored[$key] : [];

            $cursors[$key] = [
                'q'         => $query,
                'start'     => max(0, (int) ($existing['start'] ?? 0)),
                'exhausted' => (bool) ($existing['exhausted'] ?? false),
            ];
        }

        foreach ($stored as $storedKey => $storedCursor) {
            if ($storedKey === '_rotation' || isset($cursors[$storedKey]) || ! is_array($storedCursor)) {
                continue;
            }

            $cursors[$storedKey] = [
                'q'         => is_string($storedCursor['q'] ?? null) ? $storedCursor['q'] : '',
                'start'     => max(0, (int) ($storedCursor['start'] ?? 0)),
                'exhausted' => (bool) ($storedCursor['exhausted'] ?? false),
            ];
        }

        $rotation = $stored['_rotation'] ?? null;
        if (is_string($rotation) && isset($validKeys[$rotation])) {
            $cursors['_rotation'] = $rotation;
        }

        return $cursors;
    }

    /**
     * @param list<string> $queries
     * @param array<string, mixed> $cursors
     * @return list<string>
     */
    private function rotatedQueryOrder(array $queries, array $cursors): array
    {
        if ($queries === []) {
            return [];
        }

        $keys = array_map(fn (string $query) => $this->queryKey($query), $queries);
        $last = $cursors['_rotation'] ?? null;
        $index = is_string($last) ? array_search($last, $keys, true) : false;
        $start = $index === false ? 0 : ($index + 1) % count($queries);

        return array_values(array_merge(
            array_slice($queries, $start),
            array_slice($queries, 0, $start)
        ));
    }

    /**
     * @return list<array{domain: string, title: ?string, snippet: ?string, url: string, discovery_query: string}>
     */
    private function normalisePage(ProspectCriteria $criteria, string $query, array $organicResults, array $snapshot): array
    {
        $page = [];
        $seen = [];

        foreach ($snapshot as $candidate) {
            if (is_array($candidate) && ! empty($candidate['domain'])) {
                $seen[$candidate['domain']] = true;
            }
        }

        foreach ($organicResults as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url    = $item['link'] ?? null;
            $domain = is_string($url) ? $this->extractDomain($url) : null;

            if (! $domain || isset($seen[$domain])) {
                continue;
            }

            $seen[$domain] = true;
            $page[] = [
                'domain'          => $domain,
                'title'           => $item['title'] ?? null,
                'snippet'         => $item['snippet'] ?? null,
                'url'             => $url,
                'discovery_query' => $query,
            ];
        }

        if ($page === []) {
            return [];
        }

        $pageDomains = array_values(array_unique(array_column($page, 'domain')));

        $visibleDomains = Company::whereIn('domain', $pageDomains)
            ->pluck('domain')
            ->all();

        $rejectedHere = Company::withRejected()
            ->whereIn('domain', $pageDomains)
            ->where('criteria_id', $criteria->id)
            ->where('qualification_status', 'rejected')
            ->pluck('domain')
            ->all();

        $blocked = array_fill_keys(array_merge($visibleDomains, $rejectedHere), true);

        return array_values(array_filter(
            $page,
            fn (array $candidate) => ! isset($blocked[$candidate['domain']])
        ));
    }

    /**
     * @param list<string> $queries
     * @param list<array<string, mixed>> $pageCandidates
     */
    private function appendPage(
        ProspectCriteria $criteria,
        DiscoveryRun $run,
        array $queries,
        string $query,
        int $expectedStart,
        array $pageCandidates,
        bool $exhausted
    ): bool {
        return DB::transaction(function () use ($criteria, $run, $queries, $query, $expectedStart, $pageCandidates, $exhausted) {
            /** @var ProspectCriteria|null $lockedCriteria */
            $lockedCriteria = ProspectCriteria::whereKey($criteria->id)->lockForUpdate()->first();
            /** @var DiscoveryRun|null $lockedRun */
            $lockedRun = DiscoveryRun::whereKey($run->id)->lockForUpdate()->first();

            if (! $lockedCriteria || ! $lockedRun) {
                return false;
            }

            $cursors = $this->normaliseCursors($lockedCriteria->discovery_cursors ?? [], $queries);
            $key = $this->queryKey($query);
            $storedStart = (int) ($cursors[$key]['start'] ?? 0);

            if ($storedStart !== $expectedStart) {
                return false;
            }

            $snapshot = $this->snapshot($lockedRun);
            $seen = [];
            foreach ($snapshot as $candidate) {
                if (is_array($candidate) && ! empty($candidate['domain'])) {
                    $seen[$candidate['domain']] = true;
                }
            }

            foreach ($pageCandidates as $candidate) {
                $domain = $candidate['domain'] ?? null;
                if (! $domain || isset($seen[$domain])) {
                    continue;
                }

                $seen[$domain] = true;
                $snapshot[] = $candidate;
            }

            $cursors[$key] = [
                'q'         => $query,
                'start'     => $expectedStart + self::PAGE_SIZE,
                'exhausted' => $exhausted,
            ];
            $cursors['_rotation'] = $key;

            $lockedRun->forceFill(['candidates_snapshot' => array_values($snapshot)])->save();
            $lockedCriteria->forceFill(['discovery_cursors' => $cursors])->save();

            return true;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshot(?DiscoveryRun $run): array
    {
        if ($run === null || ! is_array($run->candidates_snapshot)) {
            return [];
        }

        return array_values(array_filter(
            $run->candidates_snapshot,
            fn ($candidate) => is_array($candidate) && ! empty($candidate['domain'])
        ));
    }

    private function recordSerpApiSearch(DiscoveryRun $run): void
    {
        DiscoveryRun::whereKey($run->id)->increment('searches_consumed');
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
