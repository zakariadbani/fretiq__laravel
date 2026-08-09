<?php

namespace Tests\Unit\Services\Zoho\V2\Bulk;

use App\Jobs\Zoho\RetryZohoFailuresJob;
use App\Jobs\Zoho\RunZohoBulkBackfillJob;
use App\Services\Zoho\V2\Bulk\ZohoBulkRunTerminator;
use Carbon\CarbonImmutable;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class RunZohoBulkBackfillJobTest extends TestCase
{
    public function test_dedicated_connection_retry_timeout_and_bulk_lease_are_safely_ordered(): void
    {
        $job = new RunZohoBulkBackfillJob(42, 'accounts', 'queue-timing');
        $retryAfter = (int) config('queue.connections.zoho.retry_after');

        $this->assertSame('zoho', $job->connection);
        $this->assertSame('zoho', $job->queue);
        $this->assertSame(900, $job->timeout);
        $this->assertGreaterThan($job->timeout, $retryAfter);
        $this->assertGreaterThan($retryAfter, $job->uniqueFor());
        $this->assertGreaterThan(60, (int) config('zoho-v2.throttle.lock_ttl_seconds'));
    }

    public function test_retry_job_and_bulk_job_fit_the_dedicated_queue_envelope(): void
    {
        $bulk = new RunZohoBulkBackfillJob(42, 'accounts', 'queue-envelope');
        $retry = new RetryZohoFailuresJob('accounts', 25);
        $retryAfter = (int) config('queue.connections.zoho.retry_after');

        $this->assertSame('zoho', $retry->connection);
        $this->assertSame('zoho', $retry->queue);
        $this->assertLessThan($retryAfter, $retry->timeout);
        $this->assertLessThan($retryAfter, $bulk->timeout);
        $this->assertGreaterThan($retryAfter, $bulk->uniqueFor());
        $this->assertGreaterThanOrEqual($bulk->uniqueFor(), $retry->uniqueFor());
    }

    public function test_run_deadline_covers_a_200001_record_rate_limited_backfill(): void
    {
        config()->set('zoho-v2.bulk.max_records_per_export', 200_000);
        config()->set('zoho-v2.throttle.requests_per_minute', 90);
        config()->set('zoho-v2.bulk.run_horizon_hours', 72);
        $startedAt = CarbonImmutable::parse('2026-08-09T10:00:00+00:00');
        CarbonImmutable::setTestNow($startedAt);

        try {
            $job = new RunZohoBulkBackfillJob(42, 'accounts', 'long-backfill');
            $minimumRateHours = (int) ceil(200_001 / 90 / 60);
            $deadline = CarbonImmutable::parse($job->retryDeadline);

            $this->assertGreaterThanOrEqual(
                $minimumRateHours + 12,
                (int) ceil($startedAt->floatDiffInHours($deadline)),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_redispatched_delivery_keeps_run_owner_but_rotates_delivery_owner_and_generation(): void
    {
        $first = new RunZohoBulkBackfillJob(42, 'accounts', 'owner-chain');
        $next = $first->continuation(99);

        $this->assertSame($first->runOwner, $next->runOwner);
        $this->assertNotSame($first->deliveryOwner, $next->deliveryOwner);
        $this->assertSame($first->deliveryGeneration + 1, $next->deliveryGeneration);
        $this->assertSame($first->retryDeadline, $next->retryDeadline);
    }

    public function test_terminalizer_exception_is_sanitized_before_framework_failed_job_evidence_can_render_it(): void
    {
        $raw = 'private@example.test SQLSTATE[42000]: secret provider detail';
        $terminator = Mockery::mock(ZohoBulkRunTerminator::class);
        $terminator->shouldReceive('terminate')->once()->andThrow(new RuntimeException($raw));
        $this->app->instance(ZohoBulkRunTerminator::class, $terminator);

        try {
            (new RunZohoBulkBackfillJob(42, 'accounts', 'bulk-security'))->failed(new RuntimeException('framework failure'));
            $this->fail('Terminalizer failure should keep framework failed-job evidence alive.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Zoho V2 Bulk terminalization failed; recovery remains pending.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('private@example.test', $exception->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', (string) $exception);
            $this->assertStringNotContainsString('private@example.test', (string) $exception);
        }
    }
}
