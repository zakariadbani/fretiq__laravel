<?php

namespace Tests\Unit;

use App\Services\Discovery\DiscoveryEngineRegistry;
use Tests\TestCase;

class DiscoveryEngineRegistryTest extends TestCase
{
    public function test_registry_exposes_only_live_verified_engines_and_defaults(): void
    {
        $registry = new DiscoveryEngineRegistry();

        $this->assertSame(['google', 'google_maps', 'google_local', 'bing'], $registry->ids());
        $this->assertSame(['google', 'google_maps'], $registry->defaults());
        $this->assertSame([
            'google' => 'Google',
            'google_maps' => 'Google Maps',
            'google_local' => 'Google Local',
            'bing' => 'Bing',
        ], $registry->options());
    }

    public function test_sanitize_intersects_unknown_and_duplicate_ids_with_allowlist(): void
    {
        $registry = new DiscoveryEngineRegistry();

        $this->assertSame(
            ['bing', 'google'],
            $registry->sanitize(['bing', 'unknown', 'bing', 12, 'google'])
        );
        $this->assertSame([], $registry->sanitize('google'));
    }

    public function test_fixture_families_include_each_selected_family_only_once(): void
    {
        $registry = new DiscoveryEngineRegistry();

        $this->assertSame(['web'], $registry->fixtureFamilies(['google', 'bing']));
        $this->assertSame(['local'], $registry->fixtureFamilies(['google_maps', 'google_local']));
        $this->assertSame(['web', 'local'], $registry->fixtureFamilies(['bing', 'google_local']));
        $this->assertSame([], $registry->fixtureFamilies(['unknown']));
    }

    public function test_google_local_adapter_uses_documented_pagination_and_links_website(): void
    {
        $adapter = (new DiscoveryEngineRegistry())->get('google_local');

        $this->assertSame([
            'engine' => 'google_local',
            'q' => 'fabricant textile Casablanca',
            'hl' => 'fr',
            'start' => 20,
        ], $adapter->params('fabricant textile Casablanca', 20));
        $this->assertSame(20, $adapter->pageSize());

        $page = $adapter->parse([
            'local_results' => [[
                'title' => 'Usine Atlas',
                'address' => 'Casablanca',
                'type' => 'Fabricant',
                'links' => ['website' => 'https://www.atlas.test/contact'],
            ]],
            'serpapi_pagination' => ['next' => 'https://serpapi.com/search.json?start=20'],
        ], 'fabricant textile Casablanca');

        $this->assertFalse($page->exhausted);
        $this->assertSame(20, $page->nextStart);
        $this->assertSame('atlas.test', $page->candidates[0]['domain']);
        $this->assertSame('https://www.atlas.test/contact', $page->candidates[0]['url']);
    }

    public function test_bing_adapter_uses_first_pagination_without_cc_and_normalises_organic_results(): void
    {
        $adapter = (new DiscoveryEngineRegistry())->get('bing');
        $params = $adapter->params('transitaire Tanger', 5);

        $this->assertSame('bing', $params['engine']);
        $this->assertSame('fr', $params['setlang']);
        $this->assertSame(6, $params['first']);
        $this->assertArrayNotHasKey('cc', $params);
        $this->assertSame(5, $adapter->pageSize());

        $page = $adapter->parse([
            'organic_results' => [[
                'title' => 'Tanger Fret',
                'link' => 'https://www.tanger-fret.test/',
                'snippet' => 'Transport international',
            ]],
            'serpapi_pagination' => ['next' => 'https://serpapi.com/search.json?engine=bing&first=20'],
        ], 'transitaire Tanger');

        $this->assertSame('tanger-fret.test', $page->candidates[0]['domain']);
        $this->assertFalse($page->exhausted);
        $this->assertSame(19, $page->nextStart);
    }

    public function test_google_and_maps_adapters_use_provider_next_start_instead_of_fixed_page_size(): void
    {
        $registry = new DiscoveryEngineRegistry();

        $google = $registry->get('google')->parse([
            'organic_results' => [['title' => 'A', 'link' => 'https://a.test']],
            'serpapi_pagination' => ['next' => 'https://serpapi.com/search.json?start=30'],
        ], 'q');
        $maps = $registry->get('google_maps')->parse([
            'local_results' => [['title' => 'B', 'website' => 'https://b.test']],
            'serpapi_pagination' => ['next' => 'https://serpapi.com/search.json?start=40&ll=%4040.7455%2C-74.0083%2C14z&api_key=must-not-persist&evil=1'],
        ], 'q');

        $this->assertSame(30, $google->nextStart);
        $this->assertSame(40, $maps->nextStart);
        $this->assertSame(['ll' => '@40.7455,-74.0083,14z'], $maps->nextParams);
        $this->assertSame([
            'engine' => 'google_maps',
            'type' => 'search',
            'q' => 'q',
            'hl' => 'fr',
            'll' => '@40.7455,-74.0083,14z',
            'start' => 40,
        ], $registry->get('google_maps')->params('q', 40, $maps->nextParams));
    }

    public function test_maps_rejects_unsafe_ll_and_never_carries_unknown_next_parameters(): void
    {
        $adapter = (new DiscoveryEngineRegistry())->get('google_maps');
        $page = $adapter->parse([
            'local_results' => [['title' => 'B', 'website' => 'https://b.test']],
            'serpapi_pagination' => [
                'next' => 'https://serpapi.com/search.json?start=20&ll=https%3A%2F%2Fevil.test&api_key=secret',
            ],
        ], 'q');

        $this->assertSame([], $page->nextParams);
        $params = $adapter->params('q', 20, ['ll' => 'invalid', 'api_key' => 'secret']);
        $this->assertArrayNotHasKey('ll', $params);
        $this->assertArrayNotHasKey('api_key', $params);
    }

    public function test_empty_items_are_exhausted_even_when_provider_supplies_next_url(): void
    {
        $page = (new DiscoveryEngineRegistry())->get('bing')->parse([
            'organic_results' => [],
            'serpapi_pagination' => ['next' => 'https://serpapi.com/search.json?first=20'],
        ], 'q');

        $this->assertTrue($page->exhausted);
        $this->assertNull($page->nextStart);
    }
}
