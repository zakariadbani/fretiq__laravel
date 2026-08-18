<?php

namespace Tests\Feature\Backend;

use App\Models\ProviderCall;
use App\Services\Providers\Hunter\HunterClient;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class HunterClientTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'hunter-client-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hunter.driver' => 'hunter',
            'services.hunter.api_key' => self::API_KEY,
            'services.hunter.base_url' => 'https://api.hunter.io/v2',
            'services.hunter.timeout' => 20,
            'services.hunter.discover_timeout' => 20,
        ]);
    }

    public function test_discover_returns_filters_without_logging_authorization(): void
    {
        Log::spy();
        Http::fake(['api.hunter.io/v2/discover*' => Http::response([
            'data' => [['domain' => 'example.com', 'organization' => 'Example']],
            'meta' => [
                'filters' => ['headcount' => ['11-50']],
                'offset' => 0,
                'limit' => 100,
            ],
        ], 200, ['X-Request-ID' => 'hunter-request-1'])]);

        $execution = $this->client()->discover(
            $this->context('discover'),
            ['query' => 'Exportateurs français'],
        );

        $this->assertSame(['headcount' => ['11-50']], $execution->response?->meta['filters']);
        $this->assertSame('hunter-request-1', $execution->response?->requestId);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.hunter.io/v2/discover'
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && ! str_contains($request->url(), self::API_KEY)
                && $request->data() === ['query' => 'Exportateurs français'];
        });
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_discover_rejects_undocumented_payload_keys_before_transport(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hunter_discover_payload_invalid');

        try {
            $this->client()->discover($this->context('bad-discover'), [
                'query' => 'Freight companies',
                'api_key' => 'must-never-be-accepted',
            ]);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('provider_calls', 0);
        }
    }

    public function test_anonymous_403_is_a_terminal_permission_failure(): void
    {
        Http::fake(['api.hunter.io/v2/domain-finder*' => Http::response([], 403)]);

        try {
            $this->client()->domainFinder($this->context('anonymous-403'), 'Acme Logistics');
            $this->fail('Expected Hunter permission failure.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame('permission_denied', $exception->safeCode);
            $this->assertFalse($exception->retryable);
            $this->assertSame(403, $exception->httpStatus);
        }

        $call = ProviderCall::query()->sole();
        $this->assertSame('failed', $call->status);
        $this->assertSame('permission_denied', $call->metadata['error_code']);
        $this->assertFalse($call->metadata['retryable']);
    }

    public function test_429_too_many_requests_is_normalized_as_a_terminal_usage_limit(): void
    {
        Http::fake(['api.hunter.io/v2/companies/find*' => Http::response([
            'errors' => [['id' => 'too_many_requests', 'code' => 429]],
        ], 429)]);

        try {
            $this->client()->companyEnrichment($this->context('usage-limit'), 'acme.test');
            $this->fail('Expected a terminal usage-limit failure.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame('usage_limit', $exception->safeCode);
            $this->assertFalse($exception->retryable);
            $this->assertSame(429, $exception->httpStatus);
        }

        $call = ProviderCall::query()->sole();
        $this->assertSame('failed', $call->status);
        $this->assertSame('usage_limit', $call->metadata['error_code']);
        $this->assertFalse($call->metadata['retryable']);
    }

    public function test_domain_search_sends_only_documented_filters_and_pagination(): void
    {
        Http::fake(['api.hunter.io/v2/domain-search*' => Http::response([
            'data' => ['domain' => 'acme.test', 'emails' => []],
            'meta' => ['results' => 0],
        ])]);

        $this->client()->domainSearch(
            $this->context('domain-search'),
            'acme.test',
            [
                'department' => ['sales', 'executive'],
                'seniority' => ['senior', 'executive'],
                'decision_maker' => true,
                'verification_status' => ['valid', 'accept_all'],
            ],
            37,
            12,
        );

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.hunter.io/v2/domain-search?')
                && $request->hasHeader('Authorization', 'Bearer '.self::API_KEY)
                && ! array_key_exists('api_key', $query)
                && $query === [
                    'domain' => 'acme.test',
                    'limit' => '37',
                    'offset' => '12',
                    'department' => 'sales,executive',
                    'seniority' => 'senior,executive',
                    'decision_maker' => '1',
                    'verification_status' => 'valid,accept_all',
                ];
        });
    }

    public function test_local_driver_ledgers_all_eight_operations_without_http_or_consumption(): void
    {
        config(['services.hunter.driver' => 'local']);
        Http::fake();

        $client = $this->client();
        $executions = [
            $client->discover($this->context('local-discover'), ['query' => 'transport']),
            $client->domainFinder($this->context('local-domain-finder'), 'GEODIS'),
            $client->domainSearch($this->context('local-domain-search'), 'geodis.com'),
            $client->companyEnrichment($this->context('local-company-enrichment'), 'geodis.com'),
            $client->emailFinder($this->context('local-email-finder'), 'geodis.com', 'Jean-Pierre Dupont'),
            $client->emailVerifier($this->context('local-email-verifier'), 'fret@geodis.com'),
            $client->accountUsage($this->context('local-account-usage')),
            $client->usageHistory($this->context('local-usage-history')),
        ];

        foreach ($executions as $execution) {
            DB::transaction(fn () => app(ProviderCallLedger::class)->settle(
                $execution,
                count($execution->response?->data ?? []),
                0,
            ));
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 8);
        $this->assertSame(8, ProviderCall::query()
            ->where('status', 'succeeded')
            ->where('reserved_units', 0)
            ->where('consumed_units', 0)
            ->count());
    }

    public function test_local_domain_finder_marks_each_exact_match_explicitly(): void
    {
        config(['services.hunter.driver' => 'local']);
        Http::fake();

        $execution = $this->client()->domainFinder(
            $this->context('local-perfect-domain-finder'),
            'GEODIS',
            perfectMatch: true,
        );

        $this->assertNotEmpty($execution->response?->data);
        $this->assertTrue($execution->response?->data[0]['perfect_match']);
        Http::assertNothingSent();
    }

    public function test_domain_rejects_empty_labels_before_transport(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hunter_domain_invalid');

        try {
            $this->client()->domainSearch($this->context('invalid-domain'), 'acme..test');
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('provider_calls', 0);
        }
    }

    public function test_email_verifier_preserves_pending_and_terminal_statuses(): void
    {
        Http::fakeSequence()
            ->push(['data' => []], 202)
            ->push(['data' => ['status' => 'accept_all', 'score' => 72]], 200);

        $pending = $this->client()->emailVerifier(
            $this->context('verifier-pending'),
            'pending@example.test',
        );
        $terminal = $this->client()->emailVerifier(
            $this->context('verifier-terminal'),
            'terminal@example.test',
        );

        $this->assertSame(202, $pending->response?->httpStatus);
        $this->assertSame('accept_all', $terminal->response?->data['status']);
    }

    public function test_email_verifier_normalizes_special_http_statuses_as_settleable_logical_results(): void
    {
        Http::fakeSequence()
            ->push(['errors' => [['code' => 222]]], 222)
            ->push(['errors' => [['id' => 'claimed_email', 'code' => 451]]], 451);

        $smtp = $this->client()->emailVerifier(
            $this->context('verifier-http-222'),
            'smtp@example.test',
        );
        $claimed = $this->client()->emailVerifier(
            $this->context('verifier-http-451'),
            'claimed@example.test',
        );

        $this->assertSame(200, $smtp->response?->httpStatus);
        $this->assertSame('unknown', $smtp->response?->data['status']);
        $this->assertSame('unexpected_smtp_response', $smtp->response?->meta['error_code']);
        $this->assertSame('222', $smtp->response?->meta['provider_status']);
        $this->assertSame(200, $claimed->response?->httpStatus);
        $this->assertSame('unknown', $claimed->response?->data['status']);
        $this->assertSame('claimed_email', $claimed->response?->meta['error_code']);
        $this->assertSame('451', $claimed->response?->meta['provider_status']);

        DB::transaction(function () use ($smtp, $claimed): void {
            app(ProviderCallLedger::class)->settle($smtp, 1, 1);
            app(ProviderCallLedger::class)->settle($claimed, 1, 1);
        });
        $this->assertSame(2, ProviderCall::query()->where('status', 'succeeded')->count());
    }

    public function test_email_verifier_normalizes_structured_special_codes_without_losing_terminal_status(): void
    {
        Http::fakeSequence()
            ->push([
                'data' => ['status' => 'unknown'],
                'errors' => [['code' => 222, 'id' => 'smtp_error']],
            ])
            ->push([
                'data' => ['status' => 'invalid'],
                'errors' => [['code' => 451, 'id' => 'claimed_email']],
            ]);

        $smtp = $this->client()->emailVerifier(
            $this->context('verifier-body-222'),
            'smtp@example.test',
        );
        $claimed = $this->client()->emailVerifier(
            $this->context('verifier-body-451'),
            'claimed@example.test',
        );

        $this->assertSame('unknown', $smtp->response?->data['status']);
        $this->assertSame('unexpected_smtp_response', $smtp->response?->meta['error_code']);
        $this->assertSame('invalid', $claimed->response?->data['status']);
        $this->assertSame('claimed_email', $claimed->response?->meta['error_code']);
    }

    public function test_account_usage_and_history_use_bearer_urls_and_do_not_persist_sensitive_request_details(): void
    {
        Log::spy();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/usage/history')) {
                return Http::response([
                    'data' => [[
                        'id' => 9,
                        'type' => 'search',
                        'product' => 'domain_search',
                        'credits' => 1,
                        'unknown_raw' => ['token' => self::API_KEY],
                        'made_by' => [
                            'id' => 7,
                            'authorization' => 'Bearer '.self::API_KEY,
                        ],
                        'request' => [
                            'url' => 'https://api.hunter.io/v2/domain-search?api_key='.self::API_KEY,
                            'ip' => '203.0.113.42',
                            'user_agent' => 'secret-agent',
                        ],
                    ]],
                    'meta' => ['total' => 1, 'limit' => 20, 'offset' => 0],
                ]);
            }

            return Http::response([
                'data' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'email' => 'ops@example.test',
                    'plan_name' => 'Growth',
                    'plan_level' => 2,
                    'reset_date' => '2026-08-18',
                    'team_id' => 4242,
                    'requests' => ['credits' => ['used' => 12, 'available' => 100, 'remaining' => 88]],
                    'api_key' => self::API_KEY,
                    'unknown_raw' => ['authorization' => 'Bearer '.self::API_KEY],
                ],
                'meta' => ['url' => 'https://api.hunter.io/private', 'token' => self::API_KEY],
            ]);
        });

        $usage = $this->client()->accountUsage($this->context('usage'));
        $history = $this->client()->usageHistory($this->context('history'), [
            'offset' => 0,
            'limit' => 20,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-10',
            'user_id' => 42,
        ]);

        $this->assertSame([
            'reset_date' => '2026-08-18',
            'plan_name' => 'Growth',
            'requests' => [
                'credits' => ['used' => 12, 'available' => 100, 'remaining' => 88],
            ],
        ], $usage->response?->data);
        // Regression guard: /account (unlike /usage) returns account PII —
        // confirm sanitizeUsageData() drops it before it reaches the caller.
        foreach (['first_name', 'last_name', 'email', 'team_id', 'plan_level'] as $piiKey) {
            $this->assertArrayNotHasKey($piiKey, $usage->response?->data ?? []);
        }
        $this->assertSame([], $usage->response?->meta);
        $this->assertSame([
            'id' => 9,
            'type' => 'search',
            'product' => 'domain_search',
            'credits' => 1,
            'made_by' => ['id' => 7],
        ], $history->response?->data[0]);
        $this->assertSame(['total' => 1, 'limit' => 20, 'offset' => 0], $history->response?->meta);

        DB::transaction(function () use ($usage, $history): void {
            app(ProviderCallLedger::class)->settle($usage, 1, 0);
            app(ProviderCallLedger::class)->settle($history, 1, 0);
        });

        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v2/account');

        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.self::API_KEY));
            $this->assertStringNotContainsString('api_key', $request->url());
            $this->assertStringNotContainsString(self::API_KEY, $request->url());
        }
        $this->assertStringNotContainsString(self::API_KEY, ProviderCall::query()->get()->toJson());
        $this->assertStringNotContainsString('203.0.113.42', ProviderCall::query()->get()->toJson());
        $this->assertStringNotContainsString('secret-agent', ProviderCall::query()->get()->toJson());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_domain_finder_company_enrichment_and_email_finder_use_documented_endpoints(): void
    {
        Http::fake(['api.hunter.io/v2/*' => Http::response(['data' => []])]);

        $client = $this->client();
        $client->domainFinder($this->context('finder'), 'Acme Logistics', 3, true);
        $client->companyEnrichment($this->context('company'), 'acme.test');
        $client->emailFinder($this->context('email'), 'acme.test', 'Ada Lovelace');

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/v2/domain-finder?')
                && $query === ['company' => 'Acme Logistics', 'limit' => '3', 'perfect_match' => '1'];
        });
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v2/companies/find?domain=acme.test'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v2/email-finder?domain=acme.test&full_name=Ada%20Lovelace'));
    }

    private function client(): HunterClient
    {
        return app(HunterClient::class);
    }

    private function context(string $key, float $units = 1): ProviderCallContext
    {
        return new ProviderCallContext(hash('sha256', $key), $units, engine: 'hunter');
    }
}
