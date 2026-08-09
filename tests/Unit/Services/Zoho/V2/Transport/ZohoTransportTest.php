<?php

namespace Tests\Unit\Services\Zoho\V2\Transport;

use App\Services\Zoho\V2\Transport\Sleeper;
use App\Services\Zoho\V2\Transport\ZohoApiThrottle;
use App\Services\Zoho\V2\Transport\ZohoHttpTransport;
use App\Services\Zoho\ZohoAuthService;
use DateTime;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ZohoTransportTest extends TestCase
{
    public function test_it_normalizes_the_configured_crm_base_url_to_v8(): void
    {
        config()->set('services.zoho.crm.api_url', 'https://www.zohoapis.eu/crm/v2/');

        $transport = app(ZohoHttpTransport::class);

        $this->assertSame('https://www.zohoapis.eu/crm/v8', $transport->baseUrl());
    }

    public function test_it_only_allows_relative_paths_without_a_query_string(): void
    {
        $transport = $this->transport();

        $this->expectExceptionMessage('relative read-only paths');

        $transport->get('https://malicious.example/Leads');
    }

    public function test_it_returns_a_structured_sanitized_success_response(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['id' => '123']], 'info' => ['more_records' => false]], 200, [
                'X-RateLimit-Remaining' => '42', 'X-Request-Id' => 'zoho-request',
            ]),
        ]);

        $result = $this->transport()->get('/Leads', ['per_page' => 1], 'correlation-123');

        $this->assertTrue($result->successful());
        $this->assertSame([['id' => '123']], $result->data);
        $this->assertSame(['more_records' => false], $result->info);
        $this->assertSame('42', $result->headers['x-ratelimit-remaining']);
        $this->assertSame('zoho-request', $result->headers['x-request-id']);
        $this->assertSame('correlation-123', $result->correlationId);
        $this->assertSame([['id' => '123']], $result->root('data'));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://www.zohoapis.com/crm/v8/Leads?per_page=1'
            && $request->header('If-Modified-Since') === []);
    }

    public function test_it_preserves_successful_metadata_roots_without_retaining_error_bodies(): void
    {
        Http::fakeSequence()
            ->push(['fields' => [['api_name' => 'Email']], 'layouts' => [['id' => 'layout-1']]], 200, ['X-CRM-API-Info' => 'credits=1'])
            ->push(['code' => 'INVALID_DATA', 'message' => 'private@example.test'], 400);

        $metadata = $this->transport()->get('/settings/fields', [], 'metadata-correlation');
        $failure = $this->transport()->get('/settings/fields', [], 'failure-correlation');

        $this->assertSame([['api_name' => 'Email']], $metadata->root('fields'));
        $this->assertSame([['id' => 'layout-1']], $metadata->root('layouts'));
        $this->assertSame('credits=1', $metadata->headers['x-crm-api-info']);
        $this->assertSame([], $failure->payload);
        $this->assertNull($failure->root('message'));
        $this->assertStringNotContainsString('private@example.test', serialize($failure));
    }

    public function test_it_uses_zoho_oauth_token_authorization_scheme(): void
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $this->transport()->get('/Leads');

        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Zoho-oauthtoken test-token');
    }

    public function test_it_invalidates_once_and_retries_after_unauthorized(): void
    {
        Http::fakeSequence()->push([], 401)->push(['data' => [['id' => '123']]], 200);
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->with('crm')->andReturn('old-token', 'new-token');
        $auth->shouldReceive('invalidate')->once()->with('crm');

        $result = (new ZohoHttpTransport($auth, $this->sleeper()))->get('/Leads');

        $this->assertTrue($result->successful());
        $this->assertCount(2, $result->attempts);
        $this->assertSame('unauthorized', $result->attempts[0]->reason);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Zoho-oauthtoken old-token');
        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Zoho-oauthtoken new-token');
    }

    public function test_it_treats_a_conditional_not_modified_response_as_successful(): void
    {
        Http::fake(['*' => Http::response([], 304)]);

        $result = $this->transport()->get('/Quotes');

        $this->assertTrue($result->successful());
        $this->assertTrue($result->notModified());
        $this->assertNull($result->errorCode);
        $this->assertCount(1, $result->attempts);
        $this->assertSame([], $result->payload);
    }

    public function test_empty_success_and_not_modified_responses_have_no_payload_roots(): void
    {
        Http::fakeSequence()->push([], 204)->push([], 304);

        $empty = $this->transport()->get('/Transport_international');
        $notModified = $this->transport()->get('/Quotes');

        $this->assertTrue($empty->successful());
        $this->assertSame([], $empty->payload);
        $this->assertNull($empty->root('data'));
        $this->assertTrue($notModified->notModified());
        $this->assertSame([], $notModified->payload);
        $this->assertNull($notModified->root('fields'));
    }

    public function test_a_malformed_json_200_is_a_sanitized_failure_not_an_empty_success(): void
    {
        Http::fake(['*' => Http::response('<html>proxy failure private@example.test</html>', 200, ['Content-Type' => 'text/html'])]);

        $result = $this->transport()->get('/Leads');

        $this->assertFalse($result->successful());
        $this->assertSame('malformed_success_response', $result->errorCode);
        $this->assertSame([], $result->payload);
        $this->assertStringNotContainsString('private@example.test', serialize($result));
    }

    public function test_it_sends_if_modified_since_as_an_rfc_7231_gmt_header(): void
    {
        Http::fake(['*' => Http::response([], 304)]);
        $since = new DateTimeImmutable('2026-08-09 14:25:00+02:00');

        $result = $this->transport()->getIfModifiedSince('/Quotes', $since);

        $this->assertTrue($result->notModified());
        Http::assertSent(fn (Request $request) => $request->header('If-Modified-Since')[0] === 'Sun, 09 Aug 2026 12:25:00 GMT');
    }

    public function test_conditional_header_and_transport_owned_headers_survive_retries_without_mutating_input(): void
    {
        Http::fakeSequence()->push([], 503)->push([], 304);
        $since = new DateTime('2026-08-09 14:25:00+02:00');
        $original = [$since->format('c'), $since->getTimezone()->getName()];
        $sleeper = $this->sleeper();

        $result = $this->transport($sleeper)->getIfModifiedSince(
            '/Quotes',
            $since,
            ['Authorization' => 'cannot-override-header', 'Host' => 'malicious.example', 'X-Correlation-ID' => 'cannot-override'],
            'fixed-correlation',
        );

        $this->assertTrue($result->notModified());
        $this->assertSame($original, [$since->format('c'), $since->getTimezone()->getName()]);
        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        foreach ($recorded as [$request]) {
            $this->assertSame(['Zoho-oauthtoken test-token'], $request->header('Authorization'));
            $this->assertSame(['Sun, 09 Aug 2026 12:25:00 GMT'], $request->header('If-Modified-Since'));
            $this->assertSame(['fixed-correlation'], $request->header('X-Correlation-ID'));
            $this->assertSame(['www.zohoapis.com'], $request->header('Host'));
        }
    }

    public function test_it_retries_throttling_with_a_bounded_retry_after(): void
    {
        Http::fakeSequence()->push([], 429, ['Retry-After' => '999'])->push(['data' => []], 200);
        $sleeper = $this->sleeper();

        $result = $this->transport($sleeper)->get('/Leads');

        $this->assertTrue($result->successful());
        $this->assertSame(30, $result->attempts[0]->retryAfterSeconds);
        $this->assertSame([30_000], $sleeper->delays);
    }

    public function test_it_retries_a_network_failure_without_exposing_the_exception(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if ($calls++ === 0) {
                throw new ConnectionException('connection contains prospect@example.test');
            }

            return Http::response(['data' => []], 200);
        });
        $sleeper = $this->sleeper();

        $result = $this->transport($sleeper)->get('/Leads');

        $this->assertTrue($result->successful());
        $this->assertSame('network', $result->attempts[0]->reason);
        $this->assertCount(1, $sleeper->delays);
        $this->assertStringNotContainsString('prospect@example.test', serialize($result));
    }

    public function test_it_retries_server_errors_but_not_permanent_client_errors(): void
    {
        Http::fakeSequence()->push([], 503)->push(['data' => []], 200)
            ->push(['data' => [['Email' => 'private@example.test']], 'message' => 'private@example.test'], 400);
        $sleeper = $this->sleeper();

        $this->assertTrue($this->transport($sleeper)->get('/Leads')->successful());
        $this->assertCount(1, $sleeper->delays);

        $failure = $this->transport()->get('/Leads');

        $this->assertFalse($failure->successful());
        $this->assertSame('http_400', $failure->errorCode);
        $this->assertSame([], $failure->data);
        $this->assertSame([], $failure->info);
        $this->assertSame(1, count($failure->attempts));
        $this->assertStringNotContainsString('private@example.test', serialize($failure));
    }

    public function test_every_retry_is_admitted_through_the_global_gate(): void
    {
        Cache::flush();
        config()->set('zoho-v2.throttle.requests_per_minute', 2);
        config()->set('zoho-v2.throttle.max_concurrent_requests', 1);
        config()->set('zoho-v2.throttle.acquire_timeout_milliseconds', 0);
        Http::fakeSequence()->push([], 503)->push(['data' => []], 200);
        $sleeper = $this->sleeper();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->with('crm')->andReturn('test-token');

        $result = (new ZohoHttpTransport($auth, $sleeper, 3, 20, new ZohoApiThrottle($sleeper)))->get('/Leads');

        $this->assertTrue($result->successful());
        $this->assertCount(2, $result->attempts);
        $this->assertSame(2, Cache::get('zoho:v2:api-throttle:global:minute:'.intdiv(time(), 60)));
    }

    public function test_a_busy_or_exhausted_gate_returns_a_sanitized_transport_failure(): void
    {
        Cache::flush();
        config()->set('zoho-v2.throttle.requests_per_minute', 1);
        config()->set('zoho-v2.throttle.max_concurrent_requests', 1);
        config()->set('zoho-v2.throttle.acquire_timeout_milliseconds', 0);
        Http::fakeSequence()->push([], 503)->push(['data' => [['Email' => 'must-not-be-requested@example.test']]], 200);
        $sleeper = $this->sleeper();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->with('crm')->andReturn('test-token');

        $failure = (new ZohoHttpTransport($auth, $sleeper, 3, 20, new ZohoApiThrottle($sleeper)))->get('/Leads');

        $this->assertSame('throttle_unavailable', $failure->errorCode);
        $this->assertCount(1, $failure->attempts);
        $this->assertCount(1, Http::recorded());
        $this->assertStringNotContainsString('must-not-be-requested@example.test', serialize($failure));
    }

    public function test_it_preserves_only_a_safe_machine_readable_zoho_error_code(): void
    {
        Http::fakeSequence()
            ->push(['code' => 'TOKEN_BOUND_DATA_MISMATCH', 'message' => 'token=secret&email=private@example.test'], 400)
            ->push(['code' => 'invalid code with private@example.test', 'message' => 'private@example.test'], 400);

        $pageTokenFailure = $this->transport()->get('/Leads');
        $unsafeFailure = $this->transport()->get('/Leads');

        $this->assertSame('TOKEN_BOUND_DATA_MISMATCH', $pageTokenFailure->errorCode);
        $this->assertSame('http_400', $unsafeFailure->errorCode);
        $this->assertStringNotContainsString('private@example.test', serialize([$pageTokenFailure, $unsafeFailure]));
    }

    private function transport(?Sleeper $sleeper = null): ZohoHttpTransport
    {
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->zeroOrMoreTimes()->with('crm')->andReturn('test-token');

        return new ZohoHttpTransport($auth, $sleeper);
    }

    private function sleeper(): Sleeper
    {
        return new class implements Sleeper
        {
            /** @var list<int> */
            public array $delays = [];

            public function sleepMilliseconds(int $milliseconds): void
            {
                $this->delays[] = $milliseconds;
            }
        };
    }
}
