<?php

namespace Tests\Unit;

use App\Services\Discovery\HomepageSnapshotService;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HomepageSnapshotServiceTest — the homepage fetch/clean/cache contract.
 *
 * No database: settings are injected by priming the SettingService cache key
 * ('app_settings'), which is exactly what SettingService::loadMap() reads.
 * This keeps the test fast and avoids a migrated settings table.
 */
class HomepageSnapshotServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $this->settings([]);
    }

    /**
     * Inject the settings map without touching the database.
     *
     * @param  array<string, mixed>  $map
     */
    private function settings(array $map): void
    {
        // clearCache() first: it wipes the in-process memo AND the cache key, so the
        // put() below is what the next loadMap() call sees.
        app(\App\Services\Settings\SettingService::class)->clearCache();
        Cache::put('app_settings', $map, 3600);
    }

    private function service(): HomepageSnapshotService
    {
        return new HomepageSnapshotService();
    }

    // ── Cleaning ──────────────────────────────────────────────────────────────

    public function test_strips_script_style_and_noscript_including_their_contents(): void
    {
        Http::fake(['*' => Http::response(
            '<html><head>'
            . '<style>body{color:red}</style>'
            . '<script>var secret = "SCRIPTLEAK";</script>'
            . '</head><body>'
            . '<h1>Textile Maroc</h1>'
            . '<noscript>NOSCRIPTLEAK</noscript>'
            . '<p>' . str_repeat('Nous fabriquons du textile. ', 6) . '</p>'
            . '</body></html>',
            200
        )]);

        $excerpt = $this->service()->excerpt('exemple.ma');

        $this->assertNotNull($excerpt);
        $this->assertStringNotContainsString('SCRIPTLEAK', $excerpt);
        $this->assertStringNotContainsString('color:red', $excerpt);
        $this->assertStringNotContainsString('NOSCRIPTLEAK', $excerpt);
        $this->assertStringContainsString('Textile Maroc', $excerpt);
    }

    public function test_collapses_whitespace_runs_to_single_spaces(): void
    {
        Http::fake(['*' => Http::response(
            "<html><body><h1>Textile   \n\n\t Maroc</h1><p>"
            . str_repeat('Nous exportons vers la France. ', 4)
            . '</p></body></html>',
            200
        )]);

        $excerpt = $this->service()->excerpt('espaces.ma');

        $this->assertNotNull($excerpt);
        $this->assertStringContainsString('Textile Maroc', $excerpt);
        $this->assertStringNotContainsString('  ', $excerpt);
        $this->assertSame(trim($excerpt), $excerpt);
    }

    public function test_decodes_html_entities(): void
    {
        Http::fake(['*' => Http::response(
            '<html><body><p>Soci&eacute;t&eacute; de transport &amp; logistique. '
            . str_repeat('Fret international. ', 4)
            . '</p></body></html>',
            200
        )]);

        $excerpt = $this->service()->excerpt('entites.ma');

        $this->assertNotNull($excerpt);
        $this->assertStringContainsString('Société de transport & logistique', $excerpt);
    }

    public function test_truncates_to_the_configured_char_budget(): void
    {
        $this->settings(['decouverte.homepage_excerpt_chars' => 600]);

        Http::fake(['*' => Http::response(
            '<html><body>' . str_repeat('mot ', 3000) . '</body></html>',
            200
        )]);

        $excerpt = $this->service()->excerpt('long.ma');

        $this->assertNotNull($excerpt);
        $this->assertSame(600, mb_strlen($excerpt));
    }

    // ── Null paths ────────────────────────────────────────────────────────────

    public function test_returns_null_and_makes_no_request_when_fetch_homepage_is_off(): void
    {
        $this->settings(['decouverte.fetch_homepage' => false]);

        Http::fake(['*' => Http::response('<html><body>' . str_repeat('a', 500) . '</body></html>', 200)]);

        $this->assertNull($this->service()->excerpt('desactive.ma'));
        Http::assertNothingSent();
    }

    public function test_returns_null_on_http_failure(): void
    {
        Http::fake(['*' => Http::response('Not found', 404)]);

        $this->assertNull($this->service()->excerpt('absent.ma'));

        // A 4xx/5xx is a real answer, not a transport failure — no http:// retry.
        Http::assertSentCount(1);
    }

    public function test_returns_null_on_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->assertNull($this->service()->excerpt('injoignable.ma'));
    }

    public function test_returns_null_when_cleaned_text_is_under_fifty_chars(): void
    {
        Http::fake(['*' => Http::response('<html><body>Bonjour</body></html>', 200)]);

        $this->assertNull($this->service()->excerpt('court.ma'));
    }

    // ── Fallback + caching ────────────────────────────────────────────────────

    public function test_does_not_retry_over_http_when_the_fallback_setting_is_off(): void
    {
        $schemes = [];

        Http::fake(function ($request) use (&$schemes) {
            $schemes[] = parse_url($request->url(), PHP_URL_SCHEME);

            throw new ConnectionException('SSL connect error');
        });

        $this->assertNull($this->service()->excerpt('sansssl.ma'));

        // Default is off: one attempt only, so a dead domain costs ONE timeout.
        // (assertSentCount is useless here — Laravel does not record a request
        // whose fake handler threw.)
        $this->assertSame(['https'], $schemes);
    }

    public function test_retries_once_over_http_when_the_fallback_setting_is_on(): void
    {
        $this->settings(['decouverte.homepage_http_fallback' => true]);

        $schemes = [];

        Http::fake(function ($request) use (&$schemes) {
            $schemes[] = parse_url($request->url(), PHP_URL_SCHEME);

            if (str_starts_with($request->url(), 'https://')) {
                throw new ConnectionException('SSL connect error');
            }

            return Http::response(
                '<html><body><p>' . str_repeat('Nous exportons du textile. ', 4) . '</p></body></html>',
                200
            );
        });

        $excerpt = $this->service()->excerpt('sansssl.ma');

        $this->assertNotNull($excerpt);
        $this->assertSame(['https', 'http'], $schemes);
    }

    public function test_default_timeout_is_three_seconds(): void
    {
        $timeouts = [];

        Http::fake(function ($request, $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response(
                '<html><body><p>' . str_repeat('Nous exportons du textile. ', 4) . '</p></body></html>',
                200
            );
        });

        $this->assertNotNull($this->service()->excerpt('delai.ma'));
        $this->assertSame([3], $timeouts);
    }

    public function test_second_call_is_served_from_cache_without_a_second_request(): void
    {
        Http::fake(['*' => Http::response(
            '<html><body><p>' . str_repeat('Nous fabriquons du textile. ', 6) . '</p></body></html>',
            200
        )]);

        $service = $this->service();

        $first  = $service->excerpt('cache.ma');
        $second = $service->excerpt('cache.ma');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_negative_results_are_cached_so_a_dead_domain_is_not_refetched(): void
    {
        Http::fake(['*' => Http::response('<html><body>Bonjour</body></html>', 200)]);

        $service = $this->service();

        $this->assertNull($service->excerpt('mort.ma'));
        $this->assertNull($service->excerpt('mort.ma'));

        Http::assertSentCount(1);
    }

    // ── Pooled prefetch ───────────────────────────────────────────────────────

    /** Body long enough to clear the 50-char minimum, tagged with the domain. */
    private function page(string $domain): string
    {
        return '<html><body><p>Site ' . $domain . '. '
            . str_repeat('Nous exportons du textile vers la France. ', 3)
            . '</p></body></html>';
    }

    public function test_prefetch_warms_the_cache_so_excerpt_issues_no_further_http(): void
    {
        Http::fake(fn ($request) => Http::response(
            $this->page(parse_url($request->url(), PHP_URL_HOST)),
            200
        ));

        $service = $this->service();
        $service->prefetch(['un.ma', 'deux.ma', 'trois.ma']);

        Http::assertSentCount(3);

        $this->assertStringContainsString('Site un.ma', (string) $service->excerpt('un.ma'));
        $this->assertStringContainsString('Site deux.ma', (string) $service->excerpt('deux.ma'));
        $this->assertStringContainsString('Site trois.ma', (string) $service->excerpt('trois.ma'));

        // Every excerpt() above was a pure cache hit.
        Http::assertSentCount(3);
    }

    public function test_prefetch_normalises_domains_and_skips_blanks_and_duplicates(): void
    {
        Http::fake(fn ($request) => Http::response(
            $this->page(parse_url($request->url(), PHP_URL_HOST)),
            200
        ));

        $service = $this->service();
        $service->prefetch(['  Unique.MA  ', 'unique.ma', '', '   ', 'UNIQUE.ma']);

        Http::assertSentCount(1);

        $this->assertNotNull($service->excerpt('  Unique.MA  '));
        Http::assertSentCount(1);
    }

    public function test_prefetch_does_not_refetch_already_cached_domains(): void
    {
        Http::fake(fn ($request) => Http::response(
            $this->page(parse_url($request->url(), PHP_URL_HOST)),
            200
        ));

        $service = $this->service();

        $this->assertNotNull($service->excerpt('deja.ma'));
        Http::assertSentCount(1);

        $service->prefetch(['deja.ma', 'nouveau.ma']);

        // Only nouveau.ma was fetched.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'nouveau.ma'));
    }

    public function test_prefetch_isolates_a_failing_domain_from_the_rest_of_the_pool(): void
    {
        // Counted in the handler, not via assertSentCount: Laravel does not record
        // a request whose fake handler threw.
        $hits = [];

        Http::fake(function ($request) use (&$hits) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $hits[] = $host;

            if ($host === 'casse.ma') {
                // Guzzle's ConnectException — the real transport failure. Laravel folds
                // it into the pool results array as a ConnectionException OBJECT rather
                // than throwing, which is the branch under test.
                throw new ConnectException('cURL error 28: Operation timed out', $request->toPsrRequest());
            }

            if ($host === 'quatrecentquatre.ma') {
                return Http::response('Not found', 404);
            }

            return Http::response($this->page($host), 200);
        });

        $service = $this->service();
        $service->prefetch(['bon.ma', 'casse.ma', 'quatrecentquatre.ma', 'autre.ma']);

        $this->assertCount(4, $hits);

        // Healthy siblings still cached...
        $this->assertStringContainsString('Site bon.ma', (string) $service->excerpt('bon.ma'));
        $this->assertStringContainsString('Site autre.ma', (string) $service->excerpt('autre.ma'));

        // ...and both failure modes are cached as negatives, not retried.
        $this->assertNull($service->excerpt('casse.ma'));
        $this->assertNull($service->excerpt('quatrecentquatre.ma'));

        // No excerpt() call above issued a request: all four are cached.
        $this->assertCount(4, $hits);
    }

    public function test_prefetch_caches_a_negative_for_a_too_short_page(): void
    {
        Http::fake(['*' => Http::response('<html><body>Bonjour</body></html>', 200)]);

        $service = $this->service();
        $service->prefetch(['court.ma']);

        Http::assertSentCount(1);
        $this->assertNull($service->excerpt('court.ma'));
        Http::assertSentCount(1);
    }

    public function test_prefetch_chunks_more_than_ten_domains(): void
    {
        // 23 domains at 10 per wave = 3 pool waves. The wave boundary is not
        // observable through Http::fake, so pin the chunk size directly and assert
        // the observable contract: every domain fetched exactly once, then cached.
        $chunk = (new \ReflectionClass(HomepageSnapshotService::class))
            ->getConstant('POOL_CHUNK');

        $this->assertSame(10, $chunk);

        Http::fake(fn ($request) => Http::response(
            $this->page(parse_url($request->url(), PHP_URL_HOST)),
            200
        ));

        $domains = array_map(fn (int $i) => "site{$i}.ma", range(1, 23));

        $service = $this->service();
        $service->prefetch($domains);

        Http::assertSentCount(23);

        foreach ($domains as $domain) {
            $this->assertNotNull($service->excerpt($domain));
        }

        // Still 23: every domain came back from the cache, across all three waves.
        Http::assertSentCount(23);
    }

    public function test_prefetch_makes_no_request_when_fetch_homepage_is_off(): void
    {
        $this->settings(['decouverte.fetch_homepage' => false]);

        Http::fake(['*' => Http::response('<html><body>' . str_repeat('a', 500) . '</body></html>', 200)]);

        $this->service()->prefetch(['desactive.ma', 'aussi.ma']);

        Http::assertNothingSent();
    }
}
