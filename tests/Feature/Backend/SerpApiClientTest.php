<?php

namespace Tests\Feature\Backend;

use App\Models\ProviderCall;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\SerpApi\SerpApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class SerpApiClientTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'serpapi-client-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => self::API_KEY,
        ]);
    }

    public function test_search_keeps_cache_enabled_and_never_returns_or_persists_the_api_key(): void
    {
        Log::spy();
        Http::fake(['serpapi.com/search.json*' => Http::response([
            'search_metadata' => [
                'id' => 'search-safe-1',
                'status' => 'Success',
                'json_endpoint' => 'https://serpapi.com/searches/search-safe-1.json?api_key='.self::API_KEY,
            ],
            'search_parameters' => ['engine' => 'google', 'api_key' => self::API_KEY],
            'organic_results' => [[
                'title' => 'Acme Transport',
                'link' => 'https://acme.test/',
            ]],
            'serpapi_pagination' => [
                'next' => 'https://serpapi.com/search.json?start=10&api_key='.self::API_KEY,
            ],
        ], 200, ['X-Request-ID' => 'request-safe-1'])]);

        $execution = $this->client()->search(
            $this->context('safe-search', 'google'),
            'google',
            'transitaire France',
            parameters: [
                'hl' => 'fr',
                'gl' => 'fr',
                'num' => 10,
                'filter' => 0,
                'no_cache' => false,
            ],
        );

        $serialized = json_encode($execution->response?->data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::API_KEY, $serialized);
        $this->assertSame([
            'id' => 'search-safe-1',
            'status' => 'Success',
        ], $execution->response?->data['search_metadata']);
        $this->assertArrayNotHasKey('search_parameters', $execution->response?->data ?? []);
        $this->assertSame('request-safe-1', $execution->response?->requestId);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && ($query['api_key'] ?? null) === self::API_KEY
                && ($query['engine'] ?? null) === 'google'
                && ($query['q'] ?? null) === 'transitaire France'
                && ($query['num'] ?? null) === '10'
                && ($query['filter'] ?? null) === '0'
                && ! array_key_exists('no_cache', $query)
                && ! array_key_exists('async', $query);
        });

        DB::transaction(fn () => app(ProviderCallLedger::class)->settle(
            $execution,
            1,
            1,
        ));

        $this->assertStringNotContainsString(self::API_KEY, ProviderCall::query()->sole()->toJson());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_search_rejects_credentials_async_cache_bypass_full_urls_and_cross_engine_parameters(): void
    {
        Http::fake();

        $invalid = [
            ['google', ['api_key' => 'secret']],
            ['google', ['authorization' => 'Bearer secret']],
            ['google', ['async' => false]],
            ['google', ['no_cache' => true]],
            ['google', ['next' => 'https://serpapi.com/search.json?start=10']],
            ['google', ['ll' => '@33.5,-7.6,14z']],
            ['google_maps', ['num' => 10]],
            ['bing', ['filter' => 0]],
        ];

        foreach ($invalid as $index => [$engine, $parameters]) {
            try {
                $this->client()->search(
                    $this->context('invalid-'.$index, $engine),
                    $engine,
                    'safe query',
                    parameters: $parameters,
                );
                $this->fail('Expected invalid SerpAPI parameters to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('serpapi_search_parameters_invalid', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 0);
    }

    public function test_local_search_is_ledgered_without_reserved_or_consumed_units(): void
    {
        config(['services.serpapi.driver' => 'local']);
        Http::fake();

        $execution = $this->client()->search(
            $this->context('local-search', 'google', 7),
            'google',
            'transport local',
        );
        DB::transaction(fn () => app(ProviderCallLedger::class)->settle($execution, 0, 0));

        Http::assertNothingSent();
        $call = ProviderCall::query()->sole();
        $this->assertSame('0.00', $call->reserved_units);
        $this->assertSame('0.00', $call->consumed_units);
    }

    public function test_search_preserves_all_four_engine_pagination_shapes(): void
    {
        Http::fake(['serpapi.com/search.json*' => Http::response([])]);

        $executions = [
            $this->client()->search(
                $this->context('google-page', 'google'),
                'google',
                'google query',
                10,
                ['num' => 10, 'filter' => 0],
            ),
            $this->client()->search(
                $this->context('maps-page', 'google_maps'),
                'google_maps',
                'maps query',
                20,
                ['type' => 'search', 'hl' => 'fr', 'll' => '@33.5731,-7.5898,14z'],
            ),
            $this->client()->search(
                $this->context('local-page', 'google_local'),
                'google_local',
                'local query',
                20,
                ['hl' => 'fr'],
            ),
            $this->client()->search(
                $this->context('bing-page', 'bing'),
                'bing',
                'bing query',
                19,
                ['setlang' => 'fr', 'mkt' => 'fr-FR', 'cc' => 'fr'],
            ),
        ];

        $queries = Http::recorded()->map(function (array $recorded): array {
            parse_str((string) parse_url($recorded[0]->url(), PHP_URL_QUERY), $query);

            return $query;
        })->all();

        $this->assertSame(['google', 'google_maps', 'google_local', 'bing'], array_column($queries, 'engine'));
        $this->assertSame('10', $queries[0]['start']);
        $this->assertSame('20', $queries[1]['start']);
        $this->assertSame('20', $queries[2]['start']);
        $this->assertSame('20', $queries[3]['first']);
        $this->assertSame('@33.5731,-7.5898,14z', $queries[1]['ll']);
        $this->assertSame('fr-FR', $queries[3]['mkt']);
        $this->assertSame('fr', $queries[3]['cc']);

        DB::transaction(function () use ($executions): void {
            foreach ($executions as $execution) {
                app(ProviderCallLedger::class)->settle($execution, 0, 1);
            }
        });
    }

    public function test_reserved_search_honours_the_callers_deadline_timeout(): void
    {
        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response([]);
        });

        $execution = $this->client()->searchWithReservation(
            $this->context('deadline-timeout', 'google'),
            'google',
            'deadline query',
            0,
            ['hl' => 'fr'],
            fn (): bool => true,
            timeoutSeconds: 7,
        );

        $this->assertSame([7], $timeouts);

        DB::transaction(fn () => app(ProviderCallLedger::class)->settle($execution, 0, 1));
    }

    public function test_account_uses_an_explicit_allowlist_and_drops_identity_and_raw_urls(): void
    {
        Http::fake(['serpapi.com/account*' => Http::response([
            'plan_searches_left' => 700,
            'total_searches_left' => 743,
            'this_month_usage' => 257,
            'searches_per_month' => 1000,
            'plan_name' => 'Starter',
            'account_id' => 'account-secret-id',
            'account_email' => 'ops@example.test',
            'api_key' => self::API_KEY,
            'searches_url' => 'https://serpapi.com/searches?api_key='.self::API_KEY,
            'unknown' => ['email' => 'hidden@example.test'],
        ], 200, ['Request-ID' => 'account-request-1'])]);

        $execution = $this->client()->account($this->context('account', 'account', 0));

        $this->assertSame([
            'plan_searches_left' => 700,
            'total_searches_left' => 743,
            'this_month_usage' => 257,
            'searches_per_month' => 1000,
            'plan_name' => 'Starter',
        ], $execution->response?->data);
        $this->assertSame('account-request-1', $execution->response?->requestId);

        DB::transaction(fn () => app(ProviderCallLedger::class)->settle($execution, 1, 0));

        $stored = ProviderCall::query()->sole()->toJson();
        $this->assertStringNotContainsString(self::API_KEY, $stored);
        $this->assertStringNotContainsString('ops@example.test', $stored);
        $this->assertStringNotContainsString('account-secret-id', $stored);
    }

    private function client(): SerpApiClient
    {
        return app(SerpApiClient::class);
    }

    private function context(string $key, string $engine, float $units = 1): ProviderCallContext
    {
        return new ProviderCallContext(hash('sha256', $key), $units, engine: $engine);
    }
}
