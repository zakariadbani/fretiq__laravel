<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CompanyDiscoveryServiceTest — pure unit tests for buildQueries().
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because buildQueries()
 * calls config('global.data.company_countries') which requires the Laravel app.
 * RefreshDatabase is required for cursor/snapshot tests that persist criteria and runs.
 */
class CompanyDiscoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompanyDiscoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CompanyDiscoveryService();
    }

    /**
     * Helper — create an unsaved (no DB) ProspectCriteria with given attributes.
     */
    private function makeCriteria(array $attributes): ProspectCriteria
    {
        $criteria = new ProspectCriteria();
        $criteria->fill($attributes);
        return $criteria;
    }

    private function makePersistedCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Pagination ' . uniqid(),
            'ai_queries'  => [['q' => 'transitaire France', 'enabled' => true]],
            'sectors'     => [],
            'countries'   => [],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    private function makeRun(ProspectCriteria $criteria): DiscoveryRun
    {
        return DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'running',
            'credits_reserved'     => 10,
            'consumed'             => 0,
        ]);
    }

    private function serpResult(string $domain): array
    {
        return [
            'title'   => ucfirst(str_replace('.', ' ', $domain)),
            'link'    => 'https://' . $domain . '/about',
            'snippet' => 'Fixture result',
        ];
    }

    private function serpResults(string $prefix, int $count): array
    {
        return collect(range(1, $count))
            ->map(fn ($i) => $this->serpResult("{$prefix}-{$i}.test"))
            ->all();
    }

    private function requestQuery($request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

        return $params;
    }

    public function test_live_discovery_call_budget_one_search_can_return_page_size_candidates(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => $this->serpResults('budget-one', 10),
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next'],
            ], 200),
        ]);

        $criteria = $this->makePersistedCriteria([
            'daily_limit' => 1,
            'ai_queries'  => [['q' => 'budget query', 'enabled' => true]],
        ]);
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 1);

        Http::assertSentCount(1);
        $this->assertCount(10, $snapshot);
        $this->assertSame(10, (int) data_get($criteria->refresh()->discovery_cursors, md5('budget query') . '.start'));
    }

    public function test_live_discovery_call_budget_two_searches_can_append_two_pages(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;
            $prefix = count($requests) === 1 ? 'budget-two-a' : 'budget-two-b';

            return Http::response([
                'organic_results' => $this->serpResults($prefix, 10),
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next'],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria([
            'daily_limit' => 2,
            'ai_queries'  => [['q' => 'budget query', 'enabled' => true]],
        ]);
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 2);

        Http::assertSentCount(2);
        $this->assertCount(20, $snapshot);
        $this->assertSame(20, (int) data_get($criteria->refresh()->discovery_cursors, md5('budget query') . '.start'));
        $this->assertSame('10', (string) $this->requestQuery($requests[1])['start']);
    }

    // ── ISO → French label mapping ─────────────────────────────────────────────

    /**
     * ISO code 'FR' should produce queries using "France", not "FR".
     */
    public function test_iso_code_fr_maps_to_france(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('France', $query, 'ISO "FR" should map to "France" in queries');
            $this->assertStringNotContainsString(' FR ', $query, 'Raw ISO code should not appear in queries');
        }
    }

    /**
     * ISO code 'DE' should produce queries using "Allemagne", not "DE".
     */
    public function test_iso_code_de_maps_to_allemagne(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['DE'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $allemagne = array_filter($queries, fn($q) => str_contains($q, 'Allemagne'));
        $this->assertNotEmpty($allemagne, 'ISO "DE" should map to "Allemagne"');
    }

    /**
     * Multiple ISO codes are all mapped.
     */
    public function test_multiple_iso_codes_all_mapped(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR', 'MA'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $hasMaroc  = (bool) array_filter($queries, fn($q) => str_contains($q, 'Maroc'));

        $this->assertTrue($hasFrance, 'FR should map to France');
        $this->assertTrue($hasMaroc,  'MA should map to Maroc');
    }

    // ── Legacy free-text passthrough ──────────────────────────────────────────

    /**
     * Legacy free-text "France" passes through unchanged (no double-mapping).
     */
    public function test_legacy_freetext_france_passes_through(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['France'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $this->assertTrue($hasFrance, 'Legacy "France" string should pass through unchanged');
    }

    /**
     * Unknown / junk country token passes through without error.
     */
    public function test_junk_country_token_passes_through(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['JUNK_COUNTRY_XYZ'],
            'sectors'   => [],
        ]);

        // Should not throw
        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasJunk = (bool) array_filter($queries, fn($q) => str_contains($q, 'JUNK_COUNTRY_XYZ'));
        $this->assertTrue($hasJunk, 'Junk token should pass through unchanged');
    }

    // ── Empty countries fallback ──────────────────────────────────────────────

    /**
     * Empty countries array falls back to France + Maroc.
     */
    public function test_empty_countries_falls_back_to_france_and_maroc(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => [],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $hasMaroc  = (bool) array_filter($queries, fn($q) => str_contains($q, 'Maroc'));

        $this->assertTrue($hasFrance, 'Empty countries should fall back to France');
        $this->assertTrue($hasMaroc,  'Empty countries should fall back to Maroc');
    }

    /**
     * Null countries falls back to France + Maroc.
     */
    public function test_null_countries_falls_back_to_france_and_maroc(): void
    {
        $criteria = $this->makeCriteria([
            'sectors' => [],
        ]);
        // countries not set → null

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $this->assertTrue($hasFrance, 'Null countries should fall back to France');
    }

    // ── Query budget (D10) ────────────────────────────────────────────────────

    /**
     * UE-27 selection × sectors does not produce more queries than the budget cap.
     */
    public function test_query_budget_caps_oversized_criteria(): void
    {
        $eu27 = config('global.data.eu_country_codes', []);
        $allSectors = config('global.data.prospect_sectors', []);

        $criteria = $this->makeCriteria([
            'countries' => $eu27,         // 27 countries
            'sectors'   => $allSectors,   // 16 sectors
        ]);

        $queries = $this->service->buildQueries($criteria);
        $budget  = (int) config('services.serpapi.max_queries_per_run', 40);

        $this->assertLessThanOrEqual(
            $budget,
            count($queries),
            "Query count must not exceed budget cap of {$budget}"
        );
    }

    /**
     * Small criteria below budget produces all queries uncapped.
     */
    public function test_small_criteria_within_budget_produces_all_queries(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => ['Transport & Logistique'],
        ]);

        $queries = $this->service->buildQueries($criteria);
        $budget  = (int) config('services.serpapi.max_queries_per_run', 40);

        // 1 country × (2 sector queries + 6 freight keywords) = 8 queries — well under budget
        $this->assertLessThanOrEqual($budget, count($queries));
        // Should have at least the freight keywords + sector queries
        $this->assertGreaterThanOrEqual(8, count($queries));
    }

    // ── Sector queries ────────────────────────────────────────────────────────

    /**
     * When sectors are provided, sector-specific queries are generated.
     */
    public function test_sectors_produce_sector_specific_queries(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => ['Agroalimentaire'],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $sectorQuery = array_filter($queries, fn($q) => str_contains($q, 'Agroalimentaire'));
        $this->assertNotEmpty($sectorQuery, 'Sector should appear in sector-specific queries');
    }

    /**
     * When no sectors provided, only freight-keyword queries are generated.
     */
    public function test_no_sectors_generates_only_freight_keyword_queries(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        // All queries should contain freight keywords
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression(
                '/transitaire|freight forwarder|commissionnaire de transport|logistique|transport maritime|transport aérien/',
                $query,
                'Without sectors, only freight-keyword queries expected'
            );
        }
    }

    public function test_live_discovery_advances_start_for_single_query_between_runs(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;
            $domain = count($requests) === 1 ? 'alpha.test' : 'bravo.test';

            return Http::response([
                'organic_results' => [$this->serpResult($domain)],
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next'],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria();
        $query = 'transitaire France';
        $key = md5($query);

        $run1 = $this->makeRun($criteria);
        $this->service->discover($criteria, $run1, 1);

        $criteria->refresh();
        $this->assertSame(10, (int) data_get($criteria->discovery_cursors, "{$key}.start"));

        $run2 = $this->makeRun($criteria);
        $this->service->discover($criteria->refresh(), $run2, 1);

        $first = $this->requestQuery($requests[0]);
        $second = $this->requestQuery($requests[1]);

        $this->assertArrayNotHasKey('start', $first);
        $this->assertSame('10', (string) $second['start']);
        $this->assertSame('0', (string) $first['filter']);
        $this->assertSame('10', (string) $first['num']);

        $criteria->refresh();
        $this->assertSame(20, (int) data_get($criteria->discovery_cursors, "{$key}.start"));
    }

    public function test_live_discovery_does_not_append_page_when_expected_start_mismatches(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $query = 'stale query';
        $key = md5($query);

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [['q' => $query, 'enabled' => true]],
        ]);

        Http::fake(function () use ($criteria, $key, $query) {
            $criteria->forceFill([
                'discovery_cursors' => [
                    $key => [
                        'q'         => $query,
                        'start'     => 10,
                        'exhausted' => false,
                    ],
                    '_rotation' => $key,
                ],
            ])->save();

            return Http::response([
                'organic_results' => [$this->serpResult('stale-page.test')],
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next'],
            ], 200);
        });

        $run = $this->makeRun($criteria);
        $snapshot = $this->service->discover($criteria, $run, 1);

        Http::assertSentCount(1);
        $this->assertSame([], $snapshot);
        $this->assertNull($run->refresh()->candidates_snapshot);
        $this->assertSame(10, (int) data_get($criteria->refresh()->discovery_cursors, "{$key}.start"));
    }

    public function test_live_discovery_rotates_to_next_query_each_run(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $queriesSeen = [];
        Http::fake(function ($request) use (&$queriesSeen) {
            $params = $this->requestQuery($request);
            $queriesSeen[] = $params['q'];

            return Http::response([
                'organic_results' => [$this->serpResult('rotation-' . count($queriesSeen) . '.test')],
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next'],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [
                ['q' => 'query A', 'enabled' => true],
                ['q' => 'query B', 'enabled' => true],
            ],
        ]);

        $this->service->discover($criteria, $this->makeRun($criteria), 1);
        $this->service->discover($criteria->refresh(), $this->makeRun($criteria), 1);

        $this->assertSame(['query A', 'query B'], $queriesSeen);
        $this->assertSame(md5('query B'), $criteria->refresh()->discovery_cursors['_rotation']);
    }

    public function test_live_discovery_marks_last_page_exhausted_and_failed_response_advances_nothing(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $failMode = false;
        Http::fake(function () use (&$failMode) {
            if ($failMode) {
                return Http::response([], 500);
            }

            return Http::response([
                'organic_results' => [$this->serpResult('last-page.test')],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria();
        $run = $this->makeRun($criteria);
        $snapshot = $this->service->discover($criteria, $run, 3);
        $cursor = $criteria->refresh()->discovery_cursors[md5('transitaire France')];

        $this->assertSame(['last-page.test'], array_column($snapshot, 'domain'));
        $this->assertTrue($cursor['exhausted']);
        $this->assertSame(10, (int) $cursor['start']);

        $failMode = true;

        $failedCriteria = $this->makePersistedCriteria([
            'name' => 'Failed SerpAPI ' . uniqid(),
            'ai_queries' => [['q' => 'failed query', 'enabled' => true]],
        ]);
        $failedRun = $this->makeRun($failedCriteria);

        $this->assertSame([], $this->service->discover($failedCriteria, $failedRun, 1));
        $this->assertNull($failedCriteria->refresh()->discovery_cursors);
        $this->assertNull($failedRun->refresh()->candidates_snapshot);
    }

    public function test_live_discovery_filters_visible_and_same_criteria_rejected_domains_only(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $criteria = $this->makePersistedCriteria();
        $otherCriteria = $this->makePersistedCriteria(['name' => 'Other ' . uniqid()]);

        Company::create([
            'criteria_id' => $otherCriteria->id,
            'name'        => 'Visible',
            'domain'      => 'visible.test',
            'source'      => 'discovered',
        ]);
        Company::create([
            'criteria_id'           => $criteria->id,
            'name'                  => 'Rejected here',
            'domain'                => 'same-rejected.test',
            'source'                => 'discovered',
            'qualification_status'  => 'rejected',
        ]);
        Company::create([
            'criteria_id'           => $otherCriteria->id,
            'name'                  => 'Rejected elsewhere',
            'domain'                => 'other-rejected.test',
            'source'                => 'discovered',
            'qualification_status'  => 'rejected',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => [
                    $this->serpResult('visible.test'),
                    $this->serpResult('same-rejected.test'),
                    $this->serpResult('other-rejected.test'),
                    $this->serpResult('fresh.test'),
                ],
            ], 200),
        ]);

        $snapshot = $this->service->discover($criteria, $this->makeRun($criteria), 10);

        $this->assertSame(['other-rejected.test', 'fresh.test'], array_column($snapshot, 'domain'));
    }

    public function test_live_discovery_filters_social_network_domains_without_false_positives(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => [
                    [
                        'title'   => 'LinkedIn profile',
                        'link'    => 'https://fr.linkedin.com/in/example',
                        'snippet' => 'Profile result',
                    ],
                    $this->serpResult('linkedin-logistics.com'),
                    $this->serpResult('valid-company.test'),
                ],
            ], 200),
        ]);

        $criteria = $this->makePersistedCriteria();
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 1);
        $expected = ['linkedin-logistics.com', 'valid-company.test'];

        $this->assertSame($expected, array_column($snapshot, 'domain'));
        $this->assertSame($expected, array_column($run->refresh()->candidates_snapshot, 'domain'));
    }

    public function test_live_discovery_stops_at_search_cap(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $count = 0;
        Http::fake(function () use (&$count) {
            $count++;

            return Http::response(['organic_results' => []], 200);
        });

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => collect(range(1, 20))
                ->map(fn ($i) => ['q' => "dry query {$i}", 'enabled' => true])
                ->all(),
        ]);

        $snapshot = $this->service->discover($criteria, $this->makeRun($criteria), 1);

        $this->assertSame([], $snapshot);
        $this->assertSame(15, $count);
    }
}
