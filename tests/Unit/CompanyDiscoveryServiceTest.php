<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProviderCall;
use App\Models\ProspectCriteria;
use App\Models\Setting;
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
        // Most historical tests exercise the organic adapter in isolation. Tests
        // covering global defaults/cross-products set their own engine selection.
        Setting::set('decouverte.discovery_engines', ['google']);
        $this->service = new CompanyDiscoveryService;
    }

    /**
     * Helper — create an unsaved (no DB) ProspectCriteria with given attributes.
     */
    private function makeCriteria(array $attributes): ProspectCriteria
    {
        $criteria = new ProspectCriteria;
        $criteria->fill($attributes);

        return $criteria;
    }

    private function makePersistedCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name' => 'Pagination '.uniqid(),
            'ai_queries' => [['q' => 'transitaire France', 'enabled' => true]],
            'sectors' => [],
            'countries' => [],
            'daily_limit' => 10,
            'is_active' => true,
        ], $overrides));
    }

    private function makeRun(ProspectCriteria $criteria): DiscoveryRun
    {
        return DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 10,
            'consumed' => 0,
            'searches_reserved' => 10,
            'searches_consumed' => 0,
        ]);
    }

    private function serpResult(string $domain): array
    {
        return [
            'title' => ucfirst(str_replace('.', ' ', $domain)),
            'link' => 'https://'.$domain.'/about',
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

    // ── Google Maps helpers ───────────────────────────────────────────────────

    /**
     * A SerpAPI google_maps local_results item. Pass website=null to model the ~20%
     * of live results that carry no website at all.
     */
    private function mapsResult(?string $website, array $overrides = []): array
    {
        return array_merge([
            'position' => 1,
            'title' => 'YB company',
            'type' => 'Fabrique de textile',
            'address' => '26 Rue Lahcen El Basri, Casablanca',
            'country' => 'MA',
            'phone' => '+212 522-24-18-40',
            'website' => $website,
        ], $overrides);
    }

    /** @return list<array<string, mixed>> */
    private function mapsResults(string $prefix, int $count): array
    {
        return collect(range(1, $count))
            ->map(fn ($i) => $this->mapsResult("https://{$prefix}-{$i}.test/", [
                'position' => $i,
                'title' => "{$prefix} {$i}",
            ]))
            ->all();
    }

    private function mapsQueryKey(string $query): string
    {
        return md5('google_maps:'.$query);
    }

    private function mapsCriteria(string $query = 'fabricant textile Casablanca'): ProspectCriteria
    {
        Setting::set('decouverte.discovery_engines', ['google_maps']);

        return $this->makePersistedCriteria([
            'ai_queries' => [['q' => $query, 'enabled' => true, 'engine' => 'google_maps']],
        ]);
    }

    // ── Google Maps normalisation ─────────────────────────────────────────────

    public function test_maps_results_are_normalised_to_the_candidate_contract(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            return Http::response([
                'local_results' => [
                    $this->mapsResult('http://www.ybcompany.ma/'),
                    $this->mapsResult('http://www.socomatex.com/', [
                        'title' => 'SOCOMATEX',
                        'type' => 'Filature de coton',
                        'address' => 'Zone Industrielle, Casablanca',
                        'country' => 'ma',
                        'phone' => '+212 522-60-11-22',
                    ]),
                ],
            ], 200);
        });

        $criteria = $this->mapsCriteria();
        $snapshot = $this->service->discoverForRun($criteria, $this->makeRun($criteria), 1)->candidates;

        // Request shape: engine=google_maps&type=search&hl=fr, and none of the organic params.
        $params = $this->requestQuery($requests[0]);
        $this->assertSame('google_maps', $params['engine']);
        $this->assertSame('search', $params['type']);
        $this->assertSame('fr', $params['hl']);
        $this->assertSame('fabricant textile Casablanca', $params['q']);
        $this->assertArrayNotHasKey('num', $params);
        $this->assertArrayNotHasKey('filter', $params);

        $this->assertSame(['ybcompany.ma', 'socomatex.com'], array_column($snapshot, 'domain'));

        $this->assertEquals([
            'domain' => 'ybcompany.ma',
            'title' => 'YB company',
            'snippet' => 'Fabrique de textile — 26 Rue Lahcen El Basri, Casablanca',
            'url' => 'http://www.ybcompany.ma/',
            'discovery_query' => 'fabricant textile Casablanca',
            'phone' => '+212 522-24-18-40',
            'country' => 'MA',
            'sector_hint' => 'Fabrique de textile',
        ], $snapshot[0]);

        // Lowercase ISO-2 from the provider is uppercased.
        $this->assertSame('MA', $snapshot[1]['country']);
        $this->assertSame('Filature de coton', $snapshot[1]['sector_hint']);
    }

    public function test_maps_results_without_a_website_are_skipped(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'local_results' => [
                    $this->mapsResult(null, ['title' => 'Atelier sans site']),
                    $this->mapsResult('', ['title' => 'Site vide']),
                    $this->mapsResult('https://vraie-usine.test/', ['title' => 'Vraie usine']),
                ],
            ], 200),
        ]);

        $criteria = $this->mapsCriteria();
        $snapshot = $this->service->discoverForRun($criteria, $this->makeRun($criteria), 1)->candidates;

        $this->assertSame(['vraie-usine.test'], array_column($snapshot, 'domain'));
    }

    public function test_maps_results_with_a_blocklisted_website_are_skipped(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'local_results' => [
                    // Live maps data really does return YouTube links as the "website".
                    $this->mapsResult('https://www.youtube.com/@argane-souss'),
                    $this->mapsResult('https://fr.linkedin.com/company/exemple'),
                    $this->mapsResult('https://rapport-host.test/plaquette.pdf'),
                    $this->mapsResult('https://vraie-usine.test/'),
                ],
            ], 200),
        ]);

        $criteria = $this->mapsCriteria();
        $snapshot = $this->service->discoverForRun($criteria, $this->makeRun($criteria), 1)->candidates;

        $this->assertSame(['vraie-usine.test'], array_column($snapshot, 'domain'));
    }

    public function test_maps_optional_keys_are_omitted_when_the_provider_omits_them(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'local_results' => [[
                    'title' => 'Minimal SARL',
                    'website' => 'https://minimal.test/',
                    'country' => 'Maroc', // not an ISO-2 code — must be dropped, not stored
                ]],
            ], 200),
        ]);

        $criteria = $this->mapsCriteria();
        $snapshot = $this->service->discoverForRun($criteria, $this->makeRun($criteria), 1)->candidates;

        $this->assertCount(1, $snapshot);
        $this->assertArrayNotHasKey('phone', $snapshot[0]);
        $this->assertArrayNotHasKey('country', $snapshot[0]);
        $this->assertArrayNotHasKey('sector_hint', $snapshot[0]);
        $this->assertNull($snapshot[0]['snippet']);
    }

    // ── Google Maps pagination / cursors ──────────────────────────────────────

    public function test_maps_cursor_advances_by_twenty_and_organic_by_ten(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake(function ($request) {
            $params = $this->requestQuery($request);

            if (($params['engine'] ?? null) === 'google_maps') {
                return Http::response([
                    'local_results' => $this->mapsResults('maps-page', 20),
                    'serpapi_pagination' => ['next' => 'https://serpapi.test/next?start=20'],
                ], 200);
            }

            return Http::response([
                'organic_results' => $this->serpResults('organic-page', 10),
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next?start=10'],
            ], 200);
        });

        $mapsCriteria = $this->mapsCriteria('usine agroalimentaire Agadir');
        $this->service->discoverForRun($mapsCriteria, $this->makeRun($mapsCriteria), 1);

        $this->assertSame(20, (int) data_get(
            $mapsCriteria->refresh()->discovery_cursors,
            $this->mapsQueryKey('usine agroalimentaire Agadir').'.start'
        ));

        Setting::set('decouverte.discovery_engines', ['google']);
        $organicCriteria = $this->makePersistedCriteria([
            'ai_queries' => [['q' => 'transitaire Tanger', 'enabled' => true]],
        ]);
        $this->service->discoverForRun($organicCriteria, $this->makeRun($organicCriteria), 1);

        $this->assertSame(10, (int) data_get(
            $organicCriteria->refresh()->discovery_cursors,
            md5('transitaire Tanger').'.start'
        ));
    }

    public function test_maps_second_run_resumes_at_start_twenty(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;
            $prefix = count($requests) === 1 ? 'maps-a' : 'maps-b';
            $nextStart = count($requests) === 1 ? 20 : 40;

            return Http::response([
                'local_results' => $this->mapsResults($prefix, 20),
                'serpapi_pagination' => ['next' => "https://serpapi.test/next?start={$nextStart}"],
            ], 200);
        });

        $criteria = $this->mapsCriteria();

        $this->service->discoverForRun($criteria, $this->makeRun($criteria), 1);
        $this->service->discoverForRun($criteria->refresh(), $this->makeRun($criteria), 1);

        $this->assertArrayNotHasKey('start', $this->requestQuery($requests[0]));
        $this->assertSame('20', (string) $this->requestQuery($requests[1])['start']);
        $this->assertSame(40, (int) data_get(
            $criteria->refresh()->discovery_cursors,
            $this->mapsQueryKey('fabricant textile Casablanca').'.start'
        ));
    }

    public function test_maps_empty_local_results_marks_the_query_exhausted(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'local_results' => [],
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next'],
            ], 200),
        ]);

        $criteria = $this->mapsCriteria();
        $snapshot = $this->service->discoverForRun($criteria, $this->makeRun($criteria), 3)->candidates;

        $this->assertSame([], $snapshot);

        $cursor = $criteria->refresh()->discovery_cursors[$this->mapsQueryKey('fabricant textile Casablanca')];
        $this->assertTrue($cursor['exhausted']);
        $this->assertSame('google_maps', $cursor['engine']);
        // One call only — an exhausted query is not retried within the same run.
        Http::assertSentCount(1);
    }

    // ── Mixed engines ─────────────────────────────────────────────────────────

    public function test_mixed_google_and_google_maps_queries_run_on_their_own_engines(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);
        Setting::set('decouverte.discovery_engines', ['google', 'google_maps']);

        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $params = $this->requestQuery($request);
            $seen[] = [$params['engine'], $params['q']];

            if ($params['engine'] === 'google_maps') {
                return Http::response([
                    'local_results' => [$this->mapsResult('https://maps-hit.test/')],
                ], 200);
            }

            return Http::response([
                'organic_results' => [$this->serpResult('organic-hit.test')],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [
                ['q' => 'transitaire Maroc', 'enabled' => true],                          // no engine key → google
                ['q' => 'fabricant textile Casablanca', 'enabled' => true, 'engine' => 'google_maps'],
                ['q' => 'usine conserve Agadir', 'enabled' => false, 'engine' => 'google_maps'], // disabled
            ],
        ]);

        $snapshot = $this->service->discoverForRun($criteria, $this->makeRun($criteria), 5)->candidates;

        $this->assertSame([
            ['google', 'transitaire Maroc'],
            ['google_maps', 'transitaire Maroc'],
            ['google', 'fabricant textile Casablanca'],
            ['google_maps', 'fabricant textile Casablanca'],
        ], $seen);

        $this->assertSame(['organic-hit.test', 'maps-hit.test'], array_column($snapshot, 'domain'));

        $cursors = $criteria->refresh()->discovery_cursors;
        // No provider `next` URL means each stream is terminal. The cursor keeps
        // its last requested start while the exhausted flag prevents a replay.
        $this->assertSame(0, (int) $cursors[md5('transitaire Maroc')]['start']);
        $this->assertSame(0, (int) $cursors[$this->mapsQueryKey('transitaire Maroc')]['start']);
        $this->assertSame(0, (int) $cursors[md5('fabricant textile Casablanca')]['start']);
        $this->assertSame(0, (int) $cursors[$this->mapsQueryKey('fabricant textile Casablanca')]['start']);
        $this->assertTrue($cursors[md5('transitaire Maroc')]['exhausted']);
        $this->assertTrue($cursors[$this->mapsQueryKey('transitaire Maroc')]['exhausted']);
    }

    public function test_same_query_text_on_both_engines_paginates_independently(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);
        Setting::set('decouverte.discovery_engines', ['google', 'google_maps']);

        Http::fake(function ($request) {
            $params = $this->requestQuery($request);

            return $params['engine'] === 'google_maps'
                ? Http::response([
                    'local_results' => [$this->mapsResult('https://maps-dual.test/')],
                    'serpapi_pagination' => ['next' => 'https://serpapi.test/next?start=20'],
                ], 200)
                : Http::response([
                    'organic_results' => [$this->serpResult('organic-dual.test')],
                    'serpapi_pagination' => ['next' => 'https://serpapi.test/next?start=10'],
                ], 200);
        });

        $query = 'fabricant textile Casablanca';
        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [
                ['q' => $query, 'enabled' => true, 'engine' => 'google'],
                ['q' => $query, 'enabled' => true, 'engine' => 'google_maps'],
            ],
        ]);

        $this->service->discoverForRun($criteria, $this->makeRun($criteria), 2);

        $cursors = $criteria->refresh()->discovery_cursors;
        $this->assertSame(10, (int) $cursors[md5($query)]['start']);
        $this->assertSame(20, (int) $cursors[$this->mapsQueryKey($query)]['start']);
    }

    public function test_enabled_queries_expand_across_globally_selected_engines(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);
        Setting::set('decouverte.discovery_engines', ['google_local', 'bing']);

        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $params = $this->requestQuery($request);
            $seen[] = [$params['q'], $params['engine']];

            return Http::response([
                $params['engine'] === 'google_local' ? 'local_results' : 'organic_results' => [],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [
                ['q' => 'requête A', 'enabled' => true],
                ['q' => 'requête B', 'enabled' => true],
                ['q' => 'requête ignorée', 'enabled' => false],
            ],
        ]);
        $run = $this->makeRun($criteria);

        $this->service->discoverForRun($criteria, $run, 4);

        $this->assertSame([
            ['requête A', 'google_local'],
            ['requête A', 'bing'],
            ['requête B', 'google_local'],
            ['requête B', 'bing'],
        ], $seen);
        $this->assertSame(4, $run->refresh()->searches_consumed);
    }

    public function test_failed_and_exception_attempts_are_debited_before_call_and_other_streams_continue(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'secret-must-not-be-logged',
        ]);
        Setting::set('decouverte.discovery_engines', ['google', 'bing']);

        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $params = $this->requestQuery($request);
            $seen[] = $params['engine'];

            if ($params['engine'] === 'google') {
                return Http::response(['error' => 'provider failure'], 500);
            }

            throw new \RuntimeException('network timeout');
        });

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [['q' => 'attempt accounting', 'enabled' => true]],
        ]);
        $run = $this->makeRun($criteria);
        $run->update(['searches_reserved' => 2]);

        $this->assertSame([], $this->service->discoverForRun($criteria, $run, 2)->candidates);
        $this->assertSame(['google', 'bing'], $seen);
        $this->assertSame(2, $run->refresh()->searches_consumed);
    }

    public function test_bing_persists_variable_provider_next_cursor_without_overlap(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);
        Setting::set('decouverte.discovery_engines', ['bing']);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $this->requestQuery($request);

            return Http::response([
                'organic_results' => $this->serpResults('bing-variable', 14),
                'serpapi_pagination' => [
                    'next' => 'https://serpapi.com/search.json?engine=bing&first=20',
                ],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [['q' => 'bing variable', 'enabled' => true]],
        ]);
        $this->service->discoverForRun($criteria, $this->makeRun($criteria), 1);

        $key = md5('bing:bing variable');
        $this->assertSame(19, (int) data_get($criteria->refresh()->discovery_cursors, "{$key}.start"));

        $this->service->discoverForRun($criteria->refresh(), $this->makeRun($criteria), 1);
        $this->assertSame('20', (string) $requests[1]['first']);
    }

    public function test_failed_attempt_rotates_next_run_without_advancing_failed_cursor(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);
        Setting::set('decouverte.discovery_engines', ['google', 'bing']);

        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $params = $this->requestQuery($request);
            $seen[] = $params['engine'];

            return Http::response(['error' => 'failed'], 500);
        });

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [['q' => 'fair failure', 'enabled' => true]],
        ]);

        $firstRun = $this->makeRun($criteria);
        $firstRun->update(['searches_reserved' => 1]);
        $this->service->discoverForRun($criteria, $firstRun, 1);

        $googleKey = md5('fair failure');
        $this->assertSame(0, (int) data_get($criteria->refresh()->discovery_cursors, "{$googleKey}.start"));
        $this->assertSame($googleKey, data_get($criteria->discovery_cursors, '_rotation'));

        $secondRun = $this->makeRun($criteria);
        $secondRun->update(['searches_reserved' => 1]);
        $this->service->discoverForRun($criteria->refresh(), $secondRun, 1);

        $this->assertSame(['google', 'bing'], $seen);
    }

    // ── Local fixture driver ──────────────────────────────────────────────────

    public function test_local_fixture_driver_includes_maps_shaped_entries(): void
    {
        config(['services.serpapi.driver' => 'local']);
        Setting::set('decouverte.discovery_engines', ['google', 'google_maps']);

        $criteria = $this->makePersistedCriteria();
        $candidates = $this->service->discoverForRun($criteria, $this->makeRun($criteria), 5)->candidates;

        $domains = array_column($candidates, 'domain');

        // Legacy organic fixture entries still come first and unchanged.
        $this->assertSame('bolloré transport & logistics — transitaire international', strtolower($candidates[0]['title']));
        $this->assertContains('bolloretransport.com', $domains);

        // Maps fixture entries are appended with the extra optional keys.
        $this->assertContains('somitex-maroc.ma', $domains);

        $somitex = collect($candidates)->firstWhere('domain', 'somitex-maroc.ma');
        $this->assertSame('MA', $somitex['country']);
        $this->assertSame('Fabrique de textile', $somitex['sector_hint']);
        $this->assertNotEmpty($somitex['phone']);
        // The fixture's no-website and YouTube entries must not survive normalisation.
        $this->assertNotContains('youtube.com', $domains);
    }

    public function test_local_fixture_driver_respects_selected_engine_families(): void
    {
        config(['services.serpapi.driver' => 'local']);
        $criteria = $this->makeCriteria([]);

        Setting::set('decouverte.discovery_engines', ['google', 'bing']);
        $webDomains = array_column($this->service->discover($criteria, 100), 'domain');
        $this->assertContains('bolloretransport.com', $webDomains);
        $this->assertNotContains('somitex-maroc.ma', $webDomains);

        Setting::set('decouverte.discovery_engines', ['google_maps', 'google_local']);
        $localDomains = array_column($this->service->discover($criteria, 100), 'domain');
        $this->assertNotContains('bolloretransport.com', $localDomains);
        $this->assertContains('somitex-maroc.ma', $localDomains);
    }

    public function test_live_discovery_call_budget_one_search_can_return_page_size_candidates(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => $this->serpResults('budget-one', 10),
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next?start=10'],
            ], 200),
        ]);

        $criteria = $this->makePersistedCriteria([
            'daily_limit' => 1,
            'ai_queries' => [['q' => 'budget query', 'enabled' => true]],
        ]);
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 1)->candidates;

        Http::assertSentCount(1);
        $this->assertCount(10, $snapshot);
        $this->assertSame(10, (int) data_get($criteria->refresh()->discovery_cursors, md5('budget query').'.start'));
    }

    public function test_live_run_settles_provider_audit_without_double_debiting_legacy_quota(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake(['*' => Http::response([
            'organic_results' => [$this->serpResult('ledgered.test')],
        ], 200)]);

        $criteria = $this->makePersistedCriteria([
            'ai_queries' => [['q' => 'ledger query', 'enabled' => true]],
        ]);
        $run = $this->makeRun($criteria);
        $run->update(['searches_reserved' => 1]);

        $this->service->discoverForRun($criteria, $run, 1);

        $this->assertSame(1, $run->fresh()->searches_consumed);
        $call = ProviderCall::query()->sole();
        $this->assertSame('succeeded', $call->status);
        $this->assertSame('google', $call->engine);
        $this->assertSame(1, $call->result_count);
        $this->assertSame(0.0, (float) $call->reserved_units);
        $this->assertSame(0.0, (float) $call->consumed_units);
    }

    public function test_live_discovery_call_budget_two_searches_can_append_two_pages(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;
            $prefix = count($requests) === 1 ? 'budget-two-a' : 'budget-two-b';

            $nextStart = count($requests) * 10;

            return Http::response([
                'organic_results' => $this->serpResults($prefix, 10),
                'serpapi_pagination' => ['next' => "https://serpapi.test/next?start={$nextStart}"],
            ], 200);
        });

        $criteria = $this->makePersistedCriteria([
            'daily_limit' => 2,
            'ai_queries' => [['q' => 'budget query', 'enabled' => true]],
        ]);
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 2)->candidates;

        Http::assertSentCount(2);
        $this->assertCount(20, $snapshot);
        $this->assertSame(20, (int) data_get($criteria->refresh()->discovery_cursors, md5('budget query').'.start'));
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
            'sectors' => [],
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
            'sectors' => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $allemagne = array_filter($queries, fn ($q) => str_contains($q, 'Allemagne'));
        $this->assertNotEmpty($allemagne, 'ISO "DE" should map to "Allemagne"');
    }

    /**
     * Multiple ISO codes are all mapped.
     */
    public function test_multiple_iso_codes_all_mapped(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR', 'MA'],
            'sectors' => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $hasFrance = (bool) array_filter($queries, fn ($q) => str_contains($q, 'France'));
        $hasMaroc = (bool) array_filter($queries, fn ($q) => str_contains($q, 'Maroc'));

        $this->assertTrue($hasFrance, 'FR should map to France');
        $this->assertTrue($hasMaroc, 'MA should map to Maroc');
    }

    // ── Legacy free-text passthrough ──────────────────────────────────────────

    /**
     * Legacy free-text "France" passes through unchanged (no double-mapping).
     */
    public function test_legacy_freetext_france_passes_through(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['France'],
            'sectors' => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn ($q) => str_contains($q, 'France'));
        $this->assertTrue($hasFrance, 'Legacy "France" string should pass through unchanged');
    }

    /**
     * Unknown / junk country token passes through without error.
     */
    public function test_junk_country_token_passes_through(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['JUNK_COUNTRY_XYZ'],
            'sectors' => [],
        ]);

        // Should not throw
        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasJunk = (bool) array_filter($queries, fn ($q) => str_contains($q, 'JUNK_COUNTRY_XYZ'));
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
            'sectors' => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn ($q) => str_contains($q, 'France'));
        $hasMaroc = (bool) array_filter($queries, fn ($q) => str_contains($q, 'Maroc'));

        $this->assertTrue($hasFrance, 'Empty countries should fall back to France');
        $this->assertTrue($hasMaroc, 'Empty countries should fall back to Maroc');
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
        $hasFrance = (bool) array_filter($queries, fn ($q) => str_contains($q, 'France'));
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
            'sectors' => $allSectors,   // 16 sectors
        ]);

        $queries = $this->service->buildQueries($criteria);
        $budget = (int) config('services.serpapi.max_queries_per_run', 40);

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
            'sectors' => ['Transport & Logistique'],
        ]);

        $queries = $this->service->buildQueries($criteria);
        $budget = (int) config('services.serpapi.max_queries_per_run', 40);

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
            'sectors' => ['Agroalimentaire'],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $sectorQuery = array_filter($queries, fn ($q) => str_contains($q, 'Agroalimentaire'));
        $this->assertNotEmpty($sectorQuery, 'Sector should appear in sector-specific queries');
    }

    /**
     * When no sectors provided, only freight-keyword queries are generated.
     */
    public function test_no_sectors_generates_only_freight_keyword_queries(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors' => [],
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
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;
            $domain = count($requests) === 1 ? 'alpha.test' : 'bravo.test';
            $nextStart = count($requests) * 10;

            return Http::response([
                'organic_results' => [$this->serpResult($domain)],
                'serpapi_pagination' => ['next' => "https://serpapi.test/next?start={$nextStart}"],
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
            'services.serpapi.driver' => 'serpapi',
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
                        'q' => $query,
                        'start' => 10,
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
        $call = ProviderCall::query()->sole();
        $this->assertSame('succeeded', $call->status);
        $this->assertSame('cursor_mismatch', $call->metadata['reason']);
    }

    public function test_live_discovery_rotates_to_next_query_each_run(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $queriesSeen = [];
        Http::fake(function ($request) use (&$queriesSeen) {
            $params = $this->requestQuery($request);
            $queriesSeen[] = $params['q'];

            return Http::response([
                'organic_results' => [$this->serpResult('rotation-'.count($queriesSeen).'.test')],
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
            'services.serpapi.driver' => 'serpapi',
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
        $this->assertSame(0, (int) $cursor['start']);

        $failMode = true;

        $failedCriteria = $this->makePersistedCriteria([
            'name' => 'Failed SerpAPI '.uniqid(),
            'ai_queries' => [['q' => 'failed query', 'enabled' => true]],
        ]);
        $failedRun = $this->makeRun($failedCriteria);

        $this->assertSame([], $this->service->discover($failedCriteria, $failedRun, 1));
        $failedCursors = $failedCriteria->refresh()->discovery_cursors;
        $this->assertSame(0, (int) data_get($failedCursors, md5('failed query').'.start'));
        $this->assertSame(md5('failed query'), $failedCursors['_rotation']);
        $this->assertNull($failedRun->refresh()->candidates_snapshot);
    }

    public function test_live_discovery_filters_visible_and_same_criteria_rejected_domains_only(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        $criteria = $this->makePersistedCriteria();
        $otherCriteria = $this->makePersistedCriteria(['name' => 'Other '.uniqid()]);

        Company::create([
            'criteria_id' => $otherCriteria->id,
            'name' => 'Visible',
            'domain' => 'visible.test',
            'source' => 'discovered',
        ]);
        Company::create([
            'criteria_id' => $criteria->id,
            'name' => 'Rejected here',
            'domain' => 'same-rejected.test',
            'source' => 'discovered',
            'qualification_status' => 'rejected',
        ]);
        Company::create([
            'criteria_id' => $otherCriteria->id,
            'name' => 'Rejected elsewhere',
            'domain' => 'other-rejected.test',
            'source' => 'discovered',
            'qualification_status' => 'rejected',
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
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => [
                    [
                        'title' => 'LinkedIn profile',
                        'link' => 'https://fr.linkedin.com/in/example',
                        'snippet' => 'Profile result',
                    ],
                    $this->serpResult('linkedin-logistics.com'),
                    $this->serpResult('valid-company.test'),
                ],
            ], 200),
        ]);

        $criteria = $this->makePersistedCriteria();
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 1)->candidates;
        $expected = ['linkedin-logistics.com', 'valid-company.test'];

        $this->assertSame($expected, array_column($snapshot, 'domain'));
        $this->assertSame($expected, array_column($run->refresh()->candidates_snapshot, 'domain'));
    }

    public function test_live_discovery_drops_media_directory_gov_and_document_hosts(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => [
                    $this->serpResult('lesechos.fr'),          // média
                    $this->serpResult('www.pagesjaunes.fr'),   // annuaire
                    $this->serpResult('douane.gouv.fr'),       // gouvernement (sous-domaine)
                    $this->serpResult('fr.scribd.com'),        // hébergeur de documents
                    $this->serpResult('rekrute.com'),          // emploi
                    $this->serpResult('valid-company.test'),   // vraie entreprise
                ],
            ], 200),
        ]);

        $criteria = $this->makePersistedCriteria();
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 1)->candidates;

        $this->assertSame(['valid-company.test'], array_column($snapshot, 'domain'));
    }

    public function test_live_discovery_drops_document_urls(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => [
                    [
                        'title' => 'Rapport annuel',
                        'link' => 'https://rapport-host.test/docs/rapport-2025.pdf',
                        'snippet' => 'PDF result',
                    ],
                    [
                        'title' => 'Tableau logistique',
                        'link' => 'https://tableur-host.test/data/export.xlsx?v=2',
                        'snippet' => 'Spreadsheet result',
                    ],
                    $this->serpResult('valid-company.test'),
                ],
            ], 200),
        ]);

        $criteria = $this->makePersistedCriteria();
        $run = $this->makeRun($criteria);

        $snapshot = $this->service->discoverForRun($criteria, $run, 1)->candidates;

        $this->assertSame(['valid-company.test'], array_column($snapshot, 'domain'));
    }

    public function test_blocked_domains_setting_overrides_the_built_in_list(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
        ]);

        \App\Models\Setting::set('decouverte.blocked_domains', "banni.test\n# commentaire\n");

        Http::fake([
            '*' => Http::response([
                'organic_results' => [
                    $this->serpResult('sub.banni.test'),
                    $this->serpResult('lesechos.fr'), // plus bloqué : la liste est remplacée
                    $this->serpResult('valid-company.test'),
                ],
            ], 200),
        ]);

        $criteria = $this->makePersistedCriteria();
        $run = $this->makeRun($criteria);

        $snapshot = (new CompanyDiscoveryService)->discoverForRun($criteria, $run, 1)->candidates;

        $this->assertSame(['lesechos.fr', 'valid-company.test'], array_column($snapshot, 'domain'));
    }

    public function test_live_discovery_stops_at_search_cap(): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
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
