<?php

namespace Tests\Unit\Services\Zoho\V2\Bulk;

use App\Services\Zoho\V2\Transport\Sleeper;
use App\Services\Zoho\V2\Transport\ZohoApiThrottle;
use App\Services\Zoho\V2\Transport\ZohoBulkReadTransport;
use App\Services\Zoho\V2\Transport\ZohoBulkReadTransportException;
use App\Services\Zoho\ZohoAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ZohoBulkReadTransportTest extends TestCase
{
    public function test_it_rejects_download_urls_outside_zoho_bulk_read(): void
    {
        $auth = Mockery::mock(ZohoAuthService::class);
        $transport = new ZohoBulkReadTransport($auth);
        $this->expectException(RuntimeException::class);
        $transport->downloadToTempFile('https://example.test/records.zip', 'safe-correlation');
    }

    public function test_it_rejects_query_or_fragment_suffixes_on_an_approved_download_path(): void
    {
        $auth = Mockery::mock(ZohoAuthService::class);
        $transport = new ZohoBulkReadTransport($auth);

        foreach (['?redirect=https://example.test', '#unexpected'] as $suffix) {
            try {
                $transport->downloadToTempFile(
                    'https://www.zohoapis.com/crm/bulk/v8/read/job-1/result'.$suffix,
                    'safe-correlation',
                );
                $this->fail('A suffixed download URL must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Bulk Read download URL was not an approved Zoho endpoint.', $exception->getMessage());
            }
        }
    }

    public function test_it_resolves_the_live_verified_relative_download_path_against_the_approved_origin(): void
    {
        $this->disableThrottle();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->once()->with('crm')->andReturn('token');
        $archive = $this->zipBytes("Id\nrecord-1\n");
        Http::fake([
            'https://www.zohoapis.com/crm/bulk/v8/read/job-1/result' => Http::response($archive, 200),
        ]);

        $download = (new ZohoBulkReadTransport($auth))->downloadToTempFile(
            '/crm/bulk/v8/read/job-1/result',
            'safe-correlation',
        );

        try {
            $this->assertSame(strlen($archive), $download->bytes);
            $this->assertFileExists($download->path);
            $this->assertSame($archive, file_get_contents($download->path));
            Http::assertSent(fn ($request): bool => $request->url()
                === 'https://www.zohoapis.com/crm/bulk/v8/read/job-1/result');
        } finally {
            @unlink($download->path);
        }
    }

    public function test_it_rejects_hostile_absolute_protocol_relative_and_suffixed_download_shapes(): void
    {
        $auth = Mockery::mock(ZohoAuthService::class);
        $transport = new ZohoBulkReadTransport($auth);
        $urls = [
            '//example.test/crm/bulk/v8/read/job-1/result',
            'http://www.zohoapis.com/crm/bulk/v8/read/job-1/result',
            'https://www.zohoapis.com.evil.test/crm/bulk/v8/read/job-1/result',
            'https://www.zohoapis.com:444/crm/bulk/v8/read/job-1/result',
            '/crm/bulk/v8/read/job-1/result?redirect=https://example.test',
            '/crm/bulk/v8/read/job-1/result#unexpected',
            '/crm/bulk/v8/read/../job-1/result',
        ];

        foreach ($urls as $url) {
            try {
                $transport->downloadToTempFile($url, 'safe-correlation');
                $this->fail("Hostile download URL was accepted: {$url}");
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Bulk Read download URL was not an approved Zoho endpoint.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_bulk_downloads_use_a_dedicated_shared_rate_bucket(): void
    {
        Cache::flush();
        $this->disableThrottle();
        config()->set('zoho-v2.bulk.downloads_per_minute', 1);
        config()->set('zoho-v2.throttle.acquire_timeout_milliseconds', 0);
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->andReturn('token');
        $archive = $this->zipBytes("Id\nrecord-1\n");
        Http::fake([
            'https://www.zohoapis.com/crm/bulk/v8/read/*/result' => Http::response($archive, 200),
        ]);
        $transport = new ZohoBulkReadTransport($auth, new BulkTransportTestSleeper);
        $first = $transport->downloadToTempFile('/crm/bulk/v8/read/job-1/result', 'safe-correlation');
        @unlink($first->path);

        try {
            $transport->downloadToTempFile('/crm/bulk/v8/read/job-2/result', 'safe-correlation');
            $this->fail('The dedicated Bulk download minute budget must be shared across calls.');
        } catch (ZohoBulkReadTransportException $exception) {
            $this->assertSame(ZohoBulkReadTransportException::CAPACITY_DEFERRED, $exception->reason);
            $this->assertSame('Bulk Read request deferred.', $exception->getMessage());
            $this->assertSame(0, $exception->attempts);
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_bulk_download_does_not_follow_redirect_responses(): void
    {
        $this->disableThrottle();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->once()->with('crm')->andReturn('token');
        Http::fake([
            'https://www.zohoapis.com/crm/bulk/v8/read/job-redirect/result' => Http::response(
                '',
                302,
                ['Location' => 'https://example.test/private'],
            ),
        ]);

        try {
            (new ZohoBulkReadTransport($auth))->downloadToTempFile(
                '/crm/bulk/v8/read/job-redirect/result',
                'safe-correlation',
            );
            $this->fail('A Bulk download redirect must not be followed.');
        } catch (ZohoBulkReadTransportException $exception) {
            $this->assertSame(ZohoBulkReadTransportException::REQUEST_FAILED, $exception->reason);
            $this->assertSame('Bulk Read request failed.', $exception->getMessage());
        }

        $this->assertCount(1, Http::recorded());
    }

    public function test_empty_and_oversized_successful_downloads_keep_typed_attempt_telemetry(): void
    {
        $this->disableThrottle();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->andReturn('token');
        Http::fakeSequence()
            ->push('', 200)
            ->push('oversized-private-payload', 200, ['Content-Length' => '26']);
        $transport = new ZohoBulkReadTransport($auth, null, 0);

        foreach ([64, 8] as $maxBytes) {
            config()->set('zoho-v2.bulk.max_archive_bytes', $maxBytes);
            try {
                $transport->downloadToTempFile('/crm/bulk/v8/read/job-typed/result', 'safe-correlation');
                $this->fail('An invalid successful download must be typed.');
            } catch (ZohoBulkReadTransportException $exception) {
                $this->assertSame(ZohoBulkReadTransportException::DOWNLOAD_FAILED, $exception->reason);
                $this->assertSame(200, $exception->httpStatus);
                $this->assertSame(1, $exception->attempts);
                $this->assertSame('Bulk Read download failed.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    public function test_exhausted_download_network_retries_keep_typed_attempt_telemetry(): void
    {
        $this->disableThrottle();
        $sleeper = new BulkTransportTestSleeper;
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->andReturn('token');
        Http::fakeSequence()->whenEmpty(function (): never {
            throw new ConnectionException('private download payload');
        });

        try {
            (new ZohoBulkReadTransport($auth, $sleeper, 1))
                ->downloadToTempFile('/crm/bulk/v8/read/job-network/result', 'safe-correlation');
            $this->fail('An exhausted download retry must be typed.');
        } catch (ZohoBulkReadTransportException $exception) {
            $this->assertSame(ZohoBulkReadTransportException::DOWNLOAD_FAILED, $exception->reason);
            $this->assertSame(0, $exception->httpStatus);
            $this->assertSame(2, $exception->attempts);
            $this->assertSame('Bulk Read download failed.', $exception->getMessage());
            $this->assertStringNotContainsString('private', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertCount(1, $sleeper->milliseconds);
    }

    public function test_it_uses_only_the_verified_create_endpoint(): void
    {
        $this->disableThrottle();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->once()->andReturn('token');
        Http::fake(['https://www.zohoapis.com/crm/bulk/v8/read' => Http::response(['data' => [['details' => ['id' => 'job-1']]]], 201)]);
        $result = (new ZohoBulkReadTransport($auth))->create('Accounts', null, 'safe-correlation');

        $this->assertSame('job-1', data_get($result->payload, 'data.0.details.id'));
        $this->assertSame(1, $result->attempts);
    }

    public function test_continuation_token_uses_the_official_nested_query_shape(): void
    {
        $this->disableThrottle();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->once()->andReturn('token');
        Http::fake([
            'https://www.zohoapis.com/crm/bulk/v8/read' => Http::response([
                'data' => [['details' => ['id' => 'job-2']]],
            ], 201),
        ]);

        (new ZohoBulkReadTransport($auth))->create('Accounts', 'opaque-page-token', 'safe-correlation');

        Http::assertSent(fn ($request): bool => $request->data() === [
            'query' => ['page_token' => 'opaque-page-token'],
        ]);
    }

    public function test_long_retry_after_is_returned_as_a_typed_deferral_without_sleeping(): void
    {
        $this->disableThrottle();
        config()->set('zoho-v2.bulk.max_inline_retry_after_seconds', 30);
        $sleeper = new BulkTransportTestSleeper;
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->once()->andReturn('token');
        Http::fakeSequence()->push([], 429, ['Retry-After' => '600']);

        try {
            (new ZohoBulkReadTransport($auth, $sleeper, 3))->status('job-1', 'safe-correlation');
            $this->fail('A long server deferral must be returned to the queue state machine.');
        } catch (ZohoBulkReadTransportException $exception) {
            $this->assertSame(ZohoBulkReadTransportException::CAPACITY_DEFERRED, $exception->reason);
            $this->assertSame(429, $exception->httpStatus);
            $this->assertSame(1, $exception->attempts);
            $this->assertSame(600, $exception->retryAfterSeconds);
            $this->assertSame('Bulk Read request deferred.', $exception->getMessage());
        }

        $this->assertSame([], $sleeper->milliseconds);
        $this->assertCount(1, Http::recorded());
    }

    public function test_local_throttle_capacity_is_a_typed_deferral_without_a_remote_attempt(): void
    {
        config()->set('zoho-v2.bulk.capacity_deferral_seconds', 47);
        config()->set('zoho-v2.throttle.acquire_timeout_milliseconds', 0);
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->once()->andReturn('token');
        $bucket = 'bulk-local-test';
        Cache::put('zoho:v2:api-throttle:'.$bucket.':minute:'.intdiv(time(), 60), 1, 61);
        $throttle = new ZohoApiThrottle(null, $bucket, 1, 0);

        try {
            (new ZohoBulkReadTransport($auth, null, 3, 30, $throttle))->status('job-1', 'safe-correlation');
            $this->fail('Local admission exhaustion must be returned to the queue state machine.');
        } catch (ZohoBulkReadTransportException $exception) {
            $this->assertSame(ZohoBulkReadTransportException::CAPACITY_DEFERRED, $exception->reason);
            $this->assertSame(0, $exception->httpStatus);
            $this->assertSame(0, $exception->attempts);
            $this->assertSame(47, $exception->retryAfterSeconds);
        }

        Http::assertNothingSent();
    }

    public function test_expired_continuation_errors_are_typed_and_sanitized(): void
    {
        $this->disableThrottle();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->once()->andReturn('token');
        Http::fake([
            'https://www.zohoapis.com/crm/bulk/v8/read' => Http::response([
                'data' => [[
                    'code' => 'EXPIRED_PAGE_TOKEN',
                    'message' => 'private person@example.test',
                ]],
            ], 400),
        ]);

        try {
            (new ZohoBulkReadTransport($auth))->create('Accounts', 'expired-token', 'safe-correlation');
            $this->fail('An expired continuation token must remain distinguishable from a generic transport error.');
        } catch (ZohoBulkReadTransportException $exception) {
            $this->assertSame(ZohoBulkReadTransportException::TOKEN_EXPIRED, $exception->reason);
            $this->assertSame(400, $exception->httpStatus);
            $this->assertSame(1, $exception->attempts);
            $this->assertSame('Bulk Read continuation token expired.', $exception->getMessage());
            $this->assertStringNotContainsString('person@example.test', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_it_refreshes_once_after_401_without_exposing_the_response_body(): void
    {
        $this->disableThrottle();
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->andReturn('expired-token', 'fresh-token');
        $auth->shouldReceive('invalidate')->once()->with('crm');
        Http::fakeSequence()
            ->push(['secret' => 'do-not-expose'], 401)
            ->push(['data' => [['state' => 'COMPLETED']]], 200);

        $result = (new ZohoBulkReadTransport($auth))->status('job-1', 'safe-correlation');

        $this->assertSame(2, $result->attempts);
        $this->assertSame('COMPLETED', data_get($result->payload, 'data.0.state'));
    }

    public function test_it_honors_retry_after_for_429_and_retries_server_errors(): void
    {
        $this->disableThrottle();
        $sleeper = new BulkTransportTestSleeper;
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->times(3)->andReturn('token');
        Http::fakeSequence()
            ->push([], 429, ['Retry-After' => '2'])
            ->push([], 503)
            ->push(['data' => [['state' => 'COMPLETED']]], 200);

        $result = (new ZohoBulkReadTransport($auth, $sleeper, 3))->status('job-1', 'safe-correlation');

        $this->assertSame(3, $result->attempts);
        $this->assertSame(2_000, $sleeper->milliseconds[0]);
        $this->assertCount(2, $sleeper->milliseconds);
    }

    public function test_it_honors_full_numeric_and_http_date_retry_after_values(): void
    {
        $this->disableThrottle();
        config()->set('zoho-v2.bulk.max_inline_retry_after_seconds', 180);
        $sleeper = new BulkTransportTestSleeper;
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->times(3)->andReturn('token');
        Http::fakeSequence()
            ->push([], 429, ['Retry-After' => '120'])
            ->push([], 429, ['Retry-After' => now()->addSeconds(90)->toRfc7231String()])
            ->push(['data' => [['state' => 'COMPLETED']]], 200);

        $result = (new ZohoBulkReadTransport($auth, $sleeper, 3))->status('job-1', 'safe-correlation');

        $this->assertSame(3, $result->attempts);
        $this->assertSame(120_000, $sleeper->milliseconds[0]);
        $this->assertGreaterThanOrEqual(89_000, $sleeper->milliseconds[1]);
        $this->assertLessThanOrEqual(90_000, $sleeper->milliseconds[1]);
    }

    public function test_it_retries_connection_failures_and_throws_only_a_sanitized_error(): void
    {
        $this->disableThrottle();
        $sleeper = new BulkTransportTestSleeper;
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->times(2)->andReturn('token');
        Http::fakeSequence()
            ->whenEmpty(function (): never {
                throw new ConnectionException('private CRM URL and payload');
            });

        try {
            (new ZohoBulkReadTransport($auth, $sleeper, 1))->status('job-1', 'safe-correlation');
            $this->fail('The exhausted connection retry must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Bulk Read transport unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertCount(1, $sleeper->milliseconds);
    }

    public function test_every_real_retry_attempt_passes_through_the_global_throttle(): void
    {
        config()->set('zoho-v2.throttle.requests_per_minute', 10);
        config()->set('zoho-v2.throttle.max_concurrent_requests', 1);
        config()->set('zoho-v2.throttle.acquire_timeout_milliseconds', 0);
        $sleeper = new BulkTransportTestSleeper;
        $auth = Mockery::mock(ZohoAuthService::class);
        $auth->shouldReceive('getAccessToken')->twice()->andReturn('token');
        Http::fakeSequence()->push([], 503)->push(['data' => [['state' => 'COMPLETED']]], 200);

        $result = (new ZohoBulkReadTransport($auth, $sleeper, 1, 30, new ZohoApiThrottle($sleeper)))
            ->status('job-1', 'safe-correlation');

        $this->assertSame(2, $result->attempts);
        $this->assertCount(2, Http::recorded());
    }

    private function disableThrottle(): void
    {
        config()->set('zoho-v2.throttle.requests_per_minute', 0);
        config()->set('zoho-v2.throttle.max_concurrent_requests', 0);
    }

    private function zipBytes(string $csv): string
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension unavailable.');
        }
        $path = tempnam(sys_get_temp_dir(), 'zoho-bulk-transport-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('records.csv', $csv);
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);

        return is_string($bytes) ? $bytes : '';
    }
}

final class BulkTransportTestSleeper implements Sleeper
{
    /** @var list<int> */
    public array $milliseconds = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->milliseconds[] = $milliseconds;
    }
}
