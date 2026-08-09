<?php

namespace Tests\Unit\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoBulkReadJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\Zoho\ZohoSyncWorkItem;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Reconciliation\FailedRecordHydrationResult;
use App\Services\Zoho\V2\Reconciliation\FailedRecordHydrator;
use App\Services\Zoho\V2\Reconciliation\ZohoFailureRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZohoFailureRetryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_successful_retries_and_exponentially_defers_failures_without_pii(): void
    {
        $success = $this->failure('lead-success');
        $failure = $this->failure('lead-retry');
        $hydrator = new class implements FailedRecordHydrator
        {
            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                return $zohoId === 'lead-success'
                    ? FailedRecordHydrationResult::persisted(['created' => 1, 'updated' => 0, 'unchanged' => 0], 1)
                    : FailedRecordHydrationResult::failed(1);
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                return $fetched;
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 2, 'resolved' => 1, 'deferred' => 1], $result);
        $this->assertNotNull($success->fresh()->resolved_at);
        $this->assertNull($success->fresh()->retry_after);
        $this->assertSame(1, $failure->fresh()->attempts);
        $this->assertTrue($failure->fresh()->retry_after->greaterThan(now()->addMinute()));
        $this->assertStringNotContainsString('@', $failure->fresh()->error_summary);
        $this->assertStringNotContainsString('@', json_encode($failure->fresh()->context));
    }

    public function test_it_isolates_exceptions_and_does_not_select_exhausted_failures(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 2);
        $throws = $this->failure('lead-throws');
        $later = $this->failure('lead-later');
        $exhausted = $this->failure('lead-exhausted');
        $exhausted->update(['attempts' => 2]);
        $hydrator = new class implements FailedRecordHydrator
        {
            public array $seen = [];

            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                $this->seen[] = $zohoId;
                if ($zohoId === 'lead-throws') {
                    throw new \RuntimeException('PII person@example.test');
                }

                return FailedRecordHydrationResult::persisted(['created' => 1, 'updated' => 0, 'unchanged' => 0], 1);
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                return $fetched;
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 2, 'resolved' => 1, 'deferred' => 1], $result);
        $this->assertSame(1, $throws->fresh()->attempts);
        $this->assertNotNull($later->fresh()->resolved_at);
        $this->assertSame(2, $exhausted->fresh()->attempts);
        $this->assertNotContains('lead-exhausted', $hydrator->seen);
        $this->assertStringNotContainsString('person@example.test', $throws->fresh()->error_summary);
        $this->assertStringNotContainsString('person@example.test', json_encode($throws->fresh()->context));
    }

    public function test_successful_manual_retry_transactionally_heals_linked_bulk_health_without_resetting_attempt_history(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 2);
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'bulk-retry-correlation',
            'mode' => 'backfill',
            'trigger' => 'manual',
            'status' => 'partial',
            'modules' => ['leads'],
            'requested_at' => now(),
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        $page = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'leads',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'partial',
            'attempts' => 2,
            'counters' => ['staged' => 1, 'api_requests' => 3, 'export_failed' => 0],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        $work = ZohoSyncWorkItem::query()->create([
            'zoho_bulk_read_job_id' => $page->id,
            'module' => 'leads',
            'zoho_id' => 'lead-quarantined',
            'status' => 'quarantined',
            'attempts' => 2,
            'api_requests' => 2,
            'correlation_id' => $batch->correlation_id,
            'processed_at' => now(),
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads',
            'submodule' => '',
            'sync_mode' => 'backfill',
            'status' => 'partial',
            'correlation_id' => $batch->correlation_id,
            'counters' => [
                'seen' => 1,
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'quarantined' => 1,
                'api_requests' => 5,
            ],
        ]);
        ZohoSyncLog::query()->create([
            'module' => 'leads',
            'submodule' => '',
            'mode' => 'backfill',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'synced_at' => now(),
            'records_seen' => 1,
            'records_synced' => 0,
            'records_quarantined' => 1,
            'status' => 'partial',
            'error' => 'Bulk backfill degraded; see correlation ID.',
            'api_requests' => 5,
            'telemetry' => ['bulk' => ['pages' => 1, 'export_failures' => 0]],
        ]);
        $failure = ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'leads||lead-quarantined|record'),
            'sync_batch_id' => $batch->id,
            'module' => 'leads',
            'submodule' => '',
            'zoho_id' => 'lead-quarantined',
            'failure_kind' => 'record',
            'correlation_id' => $batch->correlation_id,
            'error_summary' => 'Record synchronization failed; see correlation ID.',
            'context' => ['bulk_job_id' => $page->id, 'work_item_id' => $work->id],
            // Internal Bulk delivery attempts are exhausted; the explicit
            // failure-retry lifecycle still has its own bounded retry window.
            'attempts' => 2,
        ]);
        $hydrator = new class implements FailedRecordHydrator
        {
            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                return FailedRecordHydrationResult::persisted(
                    ['created' => 0, 'updated' => 1, 'unchanged' => 0],
                    3,
                );
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                return $fetched;
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 1, 'resolved' => 1, 'deferred' => 0], $result);
        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertSame(3, $failure->fresh()->attempts);
        $this->assertSame('completed', $work->fresh()->status);
        $this->assertSame(3, $work->fresh()->attempts);
        $this->assertSame(5, $work->fresh()->api_requests);
        $this->assertSame(1, $work->fresh()->records_updated);
        $this->assertSame('complete', $page->fresh()->status);

        $log = ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->where('module', 'leads')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertNull($log->error);
        $this->assertSame(0, $log->records_quarantined);
        $this->assertSame(8, $log->api_requests);
        $this->assertSame(1, $log->records_updated);
        $this->assertSame('completed', ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->value('status'));
        $this->assertSame('success', $batch->fresh()->status);
        $this->assertSame(8, data_get($batch->fresh()->counters, 'api_requests'));
    }

    public function test_untrusted_bulk_context_cannot_mutate_a_work_item_outside_a_validated_link(): void
    {
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'bulk-context-validation',
            'mode' => 'backfill',
            'trigger' => 'manual',
            'status' => 'partial',
            'modules' => ['leads'],
            'requested_at' => now(),
        ]);
        $page = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'leads',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'partial',
        ]);
        $work = ZohoSyncWorkItem::query()->create([
            'zoho_bulk_read_job_id' => $page->id,
            'module' => 'leads',
            'zoho_id' => 'lead-protected',
            'status' => 'quarantined',
            'attempts' => 1,
            'api_requests' => 1,
            'correlation_id' => $batch->correlation_id,
        ]);
        $failure = ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'untrusted-bulk-context'),
            'sync_batch_id' => $batch->id,
            'module' => 'leads',
            'zoho_id' => 'lead-protected',
            'failure_kind' => 'record',
            'error_summary' => 'Record synchronization failed; see correlation ID.',
            // JSON context is untrusted. Numeric strings and unrelated IDs do
            // not qualify as internal primary-key references.
            'context' => ['bulk_job_id' => (string) $page->id, 'work_item_id' => $work->id],
        ]);
        $hydrator = new class implements FailedRecordHydrator
        {
            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                return FailedRecordHydrationResult::persisted(['created' => 0, 'updated' => 0, 'unchanged' => 1], 2);
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                return $fetched;
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 1, 'resolved' => 1, 'deferred' => 0], $result);
        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertSame('quarantined', $work->fresh()->status);
        $this->assertSame(1, $work->fresh()->attempts);
        $this->assertSame(1, $work->fresh()->api_requests);
        $this->assertSame('partial', $page->fresh()->status);
    }

    public function test_persistence_crash_rolls_back_mirror_failure_work_counters_and_health_together(): void
    {
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'retry-crash-boundary',
            'mode' => 'backfill',
            'trigger' => 'manual',
            'status' => 'partial',
            'modules' => ['leads'],
            'requested_at' => now(),
        ]);
        $page = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'leads',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'partial',
            'counters' => ['api_requests' => 2, 'export_failed' => 0],
        ]);
        $work = ZohoSyncWorkItem::query()->create([
            'zoho_bulk_read_job_id' => $page->id,
            'module' => 'leads',
            'zoho_id' => 'lead-crash',
            'status' => 'quarantined',
            'attempts' => 2,
            'api_requests' => 4,
            'correlation_id' => $batch->correlation_id,
        ]);
        $failure = ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'leads||lead-crash|record'),
            'sync_batch_id' => $batch->id,
            'module' => 'leads',
            'submodule' => '',
            'zoho_id' => 'lead-crash',
            'failure_kind' => 'record',
            'correlation_id' => $batch->correlation_id,
            'error_summary' => 'Record synchronization failed; see correlation ID.',
            'context' => ['bulk_job_id' => $page->id, 'work_item_id' => $work->id],
        ]);
        $hydrator = new class implements FailedRecordHydrator
        {
            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                return FailedRecordHydrationResult::fetched(['id' => $zohoId], 3);
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                DB::table('zoho_leads')->insert([
                    'zoho_id' => (string) data_get($fetched->record, 'id'),
                    'raw_payload' => '{}',
                    'payload_hash' => hash('sha256', '{}'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                throw new \RuntimeException('simulated persistence crash');
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 1, 'resolved' => 0, 'deferred' => 1], $result);
        $this->assertDatabaseMissing('zoho_leads', ['zoho_id' => 'lead-crash']);
        $this->assertNull($failure->fresh()->resolved_at);
        $this->assertTrue($failure->fresh()->retry_after->isFuture());
        $this->assertSame('quarantined', $work->fresh()->status);
        $this->assertSame(3, $work->fresh()->attempts);
        $this->assertSame(7, $work->fresh()->api_requests);
        $this->assertSame('partial', $page->fresh()->status);
        $this->assertSame('partial', $batch->fresh()->status);
    }

    public function test_reclaimed_module_fence_prevents_a_stale_retry_from_persisting(): void
    {
        $failure = $this->failure('lead-stale-retry');
        $hydrator = new class implements FailedRecordHydrator
        {
            public int $persistCalls = 0;

            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                ZohoSyncCheckpoint::query()
                    ->where('module', 'v2:leads')
                    ->update([
                        'generation' => 99,
                        'lease_owner' => 'newer-sync-generation',
                        'lease_expires_at' => now()->addHour(),
                    ]);

                return FailedRecordHydrationResult::fetched(['id' => $zohoId], 1);
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                $this->persistCalls++;
                DB::table('zoho_leads')->insert([
                    'zoho_id' => (string) data_get($fetched->record, 'id'),
                    'raw_payload' => '{}',
                    'payload_hash' => hash('sha256', '{}'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return FailedRecordHydrationResult::persisted(
                    ['created' => 1, 'updated' => 0, 'unchanged' => 0],
                    $fetched->apiRequests,
                );
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 1, 'resolved' => 0, 'deferred' => 1], $result);
        $this->assertSame(0, $hydrator->persistCalls);
        $this->assertNull($failure->fresh()->resolved_at);
        $this->assertDatabaseMissing('zoho_leads', ['zoho_id' => 'lead-stale-retry']);
        $this->assertDatabaseHas('zoho_sync_checkpoints', [
            'module' => 'v2:leads',
            'generation' => 99,
            'lease_owner' => 'newer-sync-generation',
        ]);
    }

    public function test_manual_failure_retry_defers_without_stealing_a_lease_free_unfinished_standard_outbox(): void
    {
        $older = ZohoSyncBatch::query()->create([
            'correlation_id' => 'queued-standard-owner', 'mode' => 'delta', 'trigger' => 'schedule',
            'status' => 'running', 'modules' => ['leads'], 'requested_at' => now(),
        ]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'queued',
            'sync_batch_id' => $older->id, 'correlation_id' => $older->correlation_id,
            'generation' => 13, 'heartbeat_at' => now(),
        ]);
        $failure = $this->failure('lead-guard-deferred');
        $hydrator = new class implements FailedRecordHydrator
        {
            public int $fetchCalls = 0;

            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                $this->fetchCalls++;

                return FailedRecordHydrationResult::failed();
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                throw new \LogicException('A deferred mutation guard must not persist.');
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 0, 'resolved' => 0, 'deferred' => 1], $result);
        $this->assertSame(0, $hydrator->fetchCalls);
        $this->assertSame(0, $failure->fresh()->attempts);
        $this->assertNull($failure->fresh()->retry_after);
        $this->assertSame($older->id, $checkpoint->fresh()->sync_batch_id);
        $this->assertSame($older->correlation_id, $checkpoint->fresh()->correlation_id);
        $this->assertSame(13, $checkpoint->fresh()->generation);
        $this->assertNull($checkpoint->fresh()->lease_owner);
    }

    public function test_reclaimed_module_fence_prevents_failed_fetch_from_mutating_retry_and_bulk_health(): void
    {
        config()->set('zoho-v2.retry.backoff_seconds', [1800]);
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'failed-fetch-fence', 'mode' => 'backfill', 'trigger' => 'manual',
            'status' => 'partial', 'modules' => ['leads'], 'requested_at' => now(), 'completed_at' => now(),
            'counters' => ['records_quarantined' => 1],
        ]);
        $page = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id, 'module' => 'leads', 'submodule' => '', 'page_key' => 'root',
            'correlation_id' => $batch->correlation_id, 'status' => 'partial', 'counters' => ['api_requests' => 2],
        ]);
        $work = ZohoSyncWorkItem::query()->create([
            'zoho_bulk_read_job_id' => $page->id, 'module' => 'leads', 'zoho_id' => 'lead-failed-fence',
            'status' => 'quarantined', 'attempts' => 2, 'api_requests' => 4, 'correlation_id' => $batch->correlation_id,
        ]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads', 'submodule' => '', 'sync_mode' => 'backfill', 'status' => 'partial',
            'correlation_id' => $batch->correlation_id, 'sync_batch_id' => $batch->id,
            'counters' => ['seen' => 1, 'quarantined' => 1],
        ]);
        $log = ZohoSyncLog::query()->create([
            'module' => 'leads', 'submodule' => '', 'mode' => 'backfill', 'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id, 'synced_at' => now(), 'records_seen' => 1,
            'records_quarantined' => 1, 'status' => 'partial', 'api_requests' => 6, 'telemetry' => [],
        ]);
        $failure = ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'failed-fetch-fence'), 'sync_batch_id' => $batch->id,
            'module' => 'leads', 'submodule' => '', 'zoho_id' => 'lead-failed-fence', 'failure_kind' => 'record',
            'correlation_id' => $batch->correlation_id, 'error_summary' => 'Record synchronization failed.',
            'context' => ['bulk_job_id' => $page->id, 'work_item_id' => $work->id],
        ]);
        $hydrator = new class($failure->id) implements FailedRecordHydrator
        {
            public ?string $claimedRetryAfter = null;

            public function __construct(private int $failureId) {}

            public function fetch(string $moduleKey, string $zohoId, int $batchId, string $correlationId): FailedRecordHydrationResult
            {
                $this->claimedRetryAfter = ZohoSyncFailure::findOrFail($this->failureId)->retry_after?->toIso8601String();
                ZohoSyncCheckpoint::where('module', 'v2:leads')->update([
                    'generation' => 99, 'lease_owner' => 'newer-generation', 'lease_expires_at' => now()->addHour(),
                ]);

                return FailedRecordHydrationResult::failed(7);
            }

            public function persist(string $moduleKey, int $batchId, FailedRecordHydrationResult $fetched): FailedRecordHydrationResult
            {
                throw new \LogicException('Failed fetch must not persist.');
            }
        };

        $result = (new ZohoFailureRetryService($hydrator))->retry('leads');

        $this->assertSame(['attempted' => 1, 'resolved' => 0, 'deferred' => 1], $result);
        $this->assertSame($hydrator->claimedRetryAfter, $failure->fresh()->retry_after?->toIso8601String());
        $this->assertSame(1, $failure->fresh()->attempts);
        $this->assertSame(['quarantined', 2, 4], [$work->fresh()->status, $work->fresh()->attempts, $work->fresh()->api_requests]);
        $this->assertSame('partial', $page->fresh()->status);
        $this->assertSame(6, $log->fresh()->api_requests);
        $this->assertSame('partial', $batch->fresh()->status);
        $this->assertSame(99, $checkpoint->fresh()->generation);
        $this->assertSame('newer-generation', $checkpoint->fresh()->lease_owner);
    }

    private function failure(string $id): ZohoSyncFailure
    {
        return ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'record:'.$id),
            'module' => 'leads',
            'zoho_id' => $id,
            'failure_kind' => 'record',
            'error_summary' => 'Hydration failed; see correlation ID only.',
            'context' => ['status' => 503],
        ]);
    }
}
