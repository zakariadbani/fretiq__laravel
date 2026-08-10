<?php

namespace Tests\Feature\Backend;

use App\Jobs\Zoho\RecoverZohoBulkTerminalizationsJob;
use App\Jobs\Zoho\RunZohoBulkBackfillJob;
use App\Models\Zoho\ZohoBulkReadJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\Zoho\ZohoSyncWorkItem;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Bulk\BulkBackfillStep;
use App\Services\Zoho\V2\Bulk\BulkCsvIdParser;
use App\Services\Zoho\V2\Bulk\BulkDownload;
use App\Services\Zoho\V2\Bulk\BulkTransportResponse;
use App\Services\Zoho\V2\Bulk\ZohoBulkBackfillService;
use App\Services\Zoho\V2\Bulk\ZohoBulkDeliveryHandoff;
use App\Services\Zoho\V2\Bulk\ZohoBulkRunTerminator;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\TransportAttempt;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoRecordIngestor;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use App\Services\Zoho\V2\Transport\ZohoBulkReadTransport;
use App\Services\Zoho\V2\Transport\ZohoBulkReadTransportException;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ZohoV2BulkBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-09 10:00:00');
        config()->set('zoho-v2.bulk.verified_modules', ['accounts', 'deals', 'tasks', 'events', 'calls']);
        config()->set('zoho-v2.bulk.poll_delay_seconds', 1);
        config()->set('zoho-v2.bulk.lock_retry_seconds', 1);
        config()->set('zoho-v2.bulk.failure_retry_seconds', 1);
        config()->set('zoho-v2.bulk.work_batch_size', 100);
        config()->set('zoho-v2.bulk.staging_batch_size', 50);
        config()->set('zoho-v2.retry.max_attempts', 2);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_root_page_has_a_non_null_sentinel_and_a_real_unique_boundary(): void
    {
        $batch = $this->batch();
        $attributes = [
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'page_key' => 'root',
            'page_token' => null,
            'correlation_id' => $batch->correlation_id,
            'status' => 'queued',
        ];
        ZohoBulkReadJob::query()->create($attributes);

        $this->expectException(QueryException::class);
        ZohoBulkReadJob::query()->create($attributes);
    }

    public function test_bulk_backfill_cannot_steal_a_lease_free_queued_standard_outbox(): void
    {
        $older = ZohoSyncBatch::query()->create([
            'correlation_id' => 'older-standard-outbox', 'mode' => 'delta', 'trigger' => 'schedule',
            'status' => 'running', 'modules' => ['accounts'], 'requested_at' => now()->subMinute(),
        ]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'queued',
            'sync_batch_id' => $older->id, 'correlation_id' => $older->correlation_id,
            'generation' => 9, 'heartbeat_at' => now()->subMinute(),
        ]);
        $newer = $this->batch();
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);

        $result = $this->service($bulk, new BulkRecordTransport)->step($newer->id, 'accounts', $newer->correlation_id);

        $this->assertSame(BulkBackfillStep::FAILED, $result->action);
        $this->assertSame($older->id, $checkpoint->fresh()->sync_batch_id);
        $this->assertSame($older->correlation_id, $checkpoint->fresh()->correlation_id);
        $this->assertSame(9, $checkpoint->fresh()->generation);
        $this->assertSame('error', $newer->fresh()->status);
        $this->assertNotNull($newer->fresh()->completed_at);
        $this->assertDatabaseHas('zoho_sync_logs', [
            'sync_batch_id' => $newer->id, 'module' => 'accounts', 'mode' => 'backfill', 'status' => 'error',
        ]);
        $this->assertDatabaseHas('zoho_bulk_read_jobs', ['sync_batch_id' => $newer->id, 'page_key' => 'root', 'status' => 'superseded']);
    }

    public function test_zero_record_export_terminalizes_page_checkpoint_log_and_batch_once(): void
    {
        $batch = $this->batch();
        $archive = $this->zip("Id\n");
        $bulk = $this->completedBulkTransport('remote-zero', '/crm/bulk/v8/read/remote-zero/result', $archive, 0);
        $service = $this->service($bulk, new BulkRecordTransport);

        $result = $this->drive($service, $batch);
        $again = $service->step($batch->id, 'accounts', $batch->correlation_id, $result->bulkJobId);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame(BulkBackfillStep::COMPLETE, $again->action);
        $this->assertDatabaseHas('zoho_bulk_read_jobs', ['sync_batch_id' => $batch->id, 'page_key' => 'root', 'status' => 'complete']);
        $this->assertDatabaseCount('zoho_sync_work_items', 0);
        $this->assertDatabaseHas('zoho_sync_checkpoints', ['module' => 'v2:accounts', 'status' => 'completed', 'lease_owner' => null]);
        $this->assertDatabaseHas('zoho_sync_logs', ['sync_batch_id' => $batch->id, 'module' => 'accounts', 'status' => 'success', 'records_seen' => 0]);
        $this->assertSame(1, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->where('module', 'accounts')->count());
        $this->assertSame('success', $batch->fresh()->status);
        $this->assertFileDoesNotExist($archive);
    }

    public function test_work_row_reads_are_bounded_and_finalization_uses_sql_exists_count_and_sum(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = strtolower(preg_replace('/\s+/', ' ', $event->sql) ?? $event->sql);
        });
        $batch = $this->batch();
        $archive = $this->zip("Id\n");

        $this->drive($this->service($this->completedBulkTransport(
            'bounded-finalizer',
            '/crm/bulk/v8/read/bounded-finalizer/result',
            $archive,
            0,
        ), new BulkRecordTransport), $batch);

        $workSelects = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from `zoho_sync_work_items`'),
        ));
        $materializingSelects = array_values(array_filter(
            $workSelects,
            static fn (string $sql): bool => str_starts_with($sql, 'select *'),
        ));

        $this->assertNotEmpty($materializingSelects);
        foreach ($materializingSelects as $sql) {
            $this->assertStringContainsString('limit', $sql);
        }
        $this->assertTrue(collect($workSelects)->contains(
            static fn (string $sql): bool => str_contains($sql, 'count(*) as seen')
                && str_contains($sql, 'sum(records_created)'),
        ));
        $this->assertTrue(collect($workSelects)->contains(
            static fn (string $sql): bool => str_contains($sql, 'exists'),
        ));
    }

    public function test_successful_authorized_backfill_supersedes_a_prior_bulk_export_failure(): void
    {
        $prior = ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'prior-accounts-bulk-export'),
            'module' => 'accounts',
            'submodule' => '',
            'failure_kind' => 'bulk_export',
            'error_summary' => 'Bulk Read export failed; see correlation ID.',
            'attempts' => 2,
        ]);
        $batch = $this->batch();
        $archive = $this->zip("Id\n");

        $result = $this->drive($this->service($this->completedBulkTransport(
            'authorized-restart',
            '/crm/bulk/v8/read/authorized-restart/result',
            $archive,
            0,
        ), new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertNotNull($prior->fresh()->resolved_at);
        $this->assertSame(2, $prior->fresh()->attempts);
    }

    public function test_all_bulk_modules_finalize_once_regardless_of_completion_order(): void
    {
        $batch = $this->batch(['accounts', 'deals']);
        $accountsArchive = $this->zip("Id\n");
        $accounts = $this->drive($this->service($this->completedBulkTransport(
            'all-bulk-accounts',
            '/crm/bulk/v8/read/all-bulk-accounts/result',
            $accountsArchive,
            0,
        ), new BulkRecordTransport), $batch, null, 'accounts');

        $this->assertSame(BulkBackfillStep::COMPLETE, $accounts->action);
        $this->assertSame('running', $batch->fresh()->status);

        $dealsArchive = $this->zip("Id\n");
        $deals = $this->drive($this->service($this->completedBulkTransport(
            'all-bulk-deals',
            '/crm/bulk/v8/read/all-bulk-deals/result',
            $dealsArchive,
            0,
        ), new BulkRecordTransport), $batch, null, 'deals');

        $this->assertSame(BulkBackfillStep::COMPLETE, $deals->action);
        $this->assertSame('success', $batch->fresh()->status);
        $this->assertSame(2, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->count());

        $lateTransport = Mockery::mock(ZohoBulkReadTransport::class);
        $lateTransport->shouldNotReceive('create');
        $lateTransport->shouldNotReceive('status');
        $late = $this->service($lateTransport, new BulkRecordTransport)->step(
            $batch->id,
            'accounts',
            $batch->correlation_id,
            $accounts->bulkJobId,
        );
        $this->assertSame(BulkBackfillStep::COMPLETE, $late->action);
        $this->assertSame(2, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->count());
    }

    public function test_bulk_first_mixed_batch_waits_for_the_standard_module_log_then_finalizes(): void
    {
        $batch = $this->batch(['accounts', 'notes']);
        $archive = $this->zip("Id\n");
        $result = $this->drive($this->service($this->completedBulkTransport(
            'mixed-accounts',
            '/crm/bulk/v8/read/mixed-accounts/result',
            $archive,
            0,
        ), new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame('running', $batch->fresh()->status);
        ZohoSyncLog::query()->create([
            'module' => 'notes',
            'submodule' => 'Notes',
            'mode' => 'backfill',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'synced_at' => now(),
            'status' => 'success',
        ]);

        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertSame('success', $batch->fresh()->status);
        $this->assertSame(2, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->count());
    }

    public function test_101_records_drain_across_multiple_deliveries_with_true_ingestion_counters(): void
    {
        $batch = $this->batch();
        $ids = array_map(static fn (int $index): string => sprintf('record-%03d', $index), range(1, 101));
        $archive = $this->zip("Id\n".implode("\n", $ids)."\n");
        $bulk = $this->completedBulkTransport('remote-101', '/crm/bulk/v8/read/remote-101/result', $archive, 101);
        $records = new BulkRecordTransport;
        $service = $this->service($bulk, $records);

        $result = $this->drive($service, $batch);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame(101, $records->calls);
        $this->assertSame(101, ZohoSyncWorkItem::query()->where('status', 'completed')->count());
        $log = ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(101, $log->records_seen);
        $this->assertSame(101, $log->records_created);
        $this->assertSame(0, $log->records_updated);
        $this->assertSame(0, $log->records_unchanged);
        $this->assertSame(104, $log->api_requests);
    }

    public function test_max_export_plus_continuation_semantics_converge_without_a_200001_row_fixture(): void
    {
        $this->assertSame(200_000, config('zoho-v2.bulk.max_records_per_export'));
        // Scaling the cap to two exercises the exact token-driven 200,000 + 1 transition.
        config()->set('zoho-v2.bulk.max_records_per_export', 2);
        $batch = $this->batch();
        $first = $this->zip("Id\nfirst-1\nfirst-2\n");
        $second = $this->zip("Id\nlast-1\n");
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->with('Accounts', null, $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'remote-first']]]], 201));
        $bulk->shouldReceive('status')->once()->with('remote-first', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => 'https://www.zohoapis.com/crm/bulk/v8/read/remote-first/result',
                'next_page_token' => 'opaque-next-token',
                'count' => 2,
                'more_records' => true,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()->andReturn(new BulkDownload($first, 1, filesize($first)));
        $bulk->shouldReceive('create')->once()->with('Accounts', 'opaque-next-token', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'remote-second']]]], 201));
        $bulk->shouldReceive('status')->once()->with('remote-second', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => 'https://www.zohoapis.com/crm/bulk/v8/read/remote-second/result',
                'count' => 1,
                'more_records' => false,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()->andReturn(new BulkDownload($second, 1, filesize($second)));

        $result = $this->drive($this->service($bulk, new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame(2, ZohoBulkReadJob::query()->where('sync_batch_id', $batch->id)->count());
        $this->assertDatabaseHas('zoho_bulk_read_jobs', ['page_key' => 'root', 'status' => 'complete']);
        $this->assertDatabaseHas('zoho_bulk_read_jobs', ['page_key' => hash('sha256', 'opaque-next-token'), 'status' => 'complete']);
        $this->assertSame(3, ZohoSyncWorkItem::query()->where('status', 'completed')->count());
    }

    public function test_self_or_previous_continuation_token_cycle_is_rejected_without_creating_another_page(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 1);
        $batch = $this->batch();
        $rootArchive = $this->zip("Id\n");
        $continuationArchive = $this->zip("Id\n");
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->with('Accounts', null, $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'cycle-root']]]], 201));
        $bulk->shouldReceive('status')->once()->with('cycle-root', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => '/crm/bulk/v8/read/cycle-root/result',
                'next_page_token' => 'cycle-token',
                'count' => 0,
                'more_records' => true,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->andReturn(new BulkDownload($rootArchive, 1, filesize($rootArchive)));
        $bulk->shouldReceive('create')->once()->with('Accounts', 'cycle-token', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'cycle-continuation']]]], 201));
        $bulk->shouldReceive('status')->once()->with('cycle-continuation', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => '/crm/bulk/v8/read/cycle-continuation/result',
                'next_page_token' => 'cycle-token',
                'count' => 0,
                'more_records' => true,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->andReturn(new BulkDownload($continuationArchive, 1, filesize($continuationArchive)));

        $result = $this->drive($this->service($bulk, new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::FAILED, $result->action);
        $this->assertSame(2, ZohoBulkReadJob::query()->where('sync_batch_id', $batch->id)->count());
        $this->assertDatabaseHas('zoho_sync_failures', [
            'sync_batch_id' => $batch->id,
            'failure_kind' => 'bulk_export',
            'resolved_at' => null,
        ]);
    }

    public function test_crash_after_partial_staging_is_idempotent_and_resolves_the_export_failure(): void
    {
        config()->set('zoho-v2.bulk.staging_batch_size', 2);
        $batch = $this->batch();
        $invalid = $this->zip("Id\nresume-1\nresume-2\ninvalid id\n");
        $valid = $this->zip("Id\nresume-1\nresume-2\nresume-3\n");
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'remote-resume']]]], 201));
        $completed = $this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
            'download_url' => 'https://www.zohoapis.com/crm/bulk/v8/read/remote-resume/result',
            'count' => 3,
            'more_records' => false,
        ]]]], 200);
        $bulk->shouldReceive('status')->twice()->andReturn($completed);
        $bulk->shouldReceive('downloadToTempFile')->once()->andReturn(new BulkDownload($invalid, 1, filesize($invalid)));
        $bulk->shouldReceive('downloadToTempFile')->once()->andReturn(new BulkDownload($valid, 1, filesize($valid)));
        $service = $this->service($bulk, new BulkRecordTransport);

        $first = $service->step($batch->id, 'accounts', $batch->correlation_id);
        $this->advance($first->delaySeconds);
        $failedStage = $service->step($batch->id, 'accounts', $batch->correlation_id, $first->bulkJobId);

        $this->assertSame(BulkBackfillStep::REDISPATCH, $failedStage->action);
        $this->assertSame(2, ZohoSyncWorkItem::query()->count());
        $failure = ZohoSyncFailure::query()->where('failure_kind', 'bulk_export')->firstOrFail();
        $this->assertNull($failure->resolved_at);
        $this->advance($failedStage->delaySeconds);

        $result = $this->drive($service, $batch, $failedStage->bulkJobId);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame(3, ZohoSyncWorkItem::query()->count());
        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertDatabaseHas('zoho_bulk_read_jobs', ['id' => $failedStage->bulkJobId, 'status' => 'complete']);
    }

    public function test_record_quarantine_retries_complete_the_work_row_and_resolve_the_stable_failure(): void
    {
        $batch = $this->batch();
        $archive = $this->zip("Id\nretry-1\n");
        $bulk = $this->completedBulkTransport('remote-retry', '/crm/bulk/v8/read/remote-retry/result', $archive, 1);
        $records = new BulkRecordTransport(['retry-1' => 1]);
        $service = $this->service($bulk, $records);

        $result = $this->drive($service, $batch);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $item = ZohoSyncWorkItem::query()->firstOrFail();
        $this->assertSame('completed', $item->status);
        $this->assertSame(2, $item->attempts);
        $failure = ZohoSyncFailure::query()->where('failure_kind', 'record')->firstOrFail();
        $this->assertSame($item->id, data_get($failure->context, 'work_item_id'));
        $this->assertNotNull($failure->resolved_at);
        $log = ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertSame(5, $log->api_requests);
    }

    public function test_exhausted_export_failure_terminalizes_as_error_and_never_as_success(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 1);
        $batch = $this->batch();
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'remote-failed']]]], 201));
        $bulk->shouldReceive('status')->once()->andReturn($this->bulkResponse(['data' => [['state' => 'FAILED']]], 200));
        $service = $this->service($bulk, new BulkRecordTransport);

        $result = $this->drive($service, $batch);

        $this->assertSame(BulkBackfillStep::FAILED, $result->action);
        $this->assertDatabaseHas('zoho_bulk_read_jobs', ['sync_batch_id' => $batch->id, 'status' => 'partial']);
        $this->assertDatabaseHas('zoho_sync_logs', ['sync_batch_id' => $batch->id, 'status' => 'error']);
        $this->assertSame('error', $batch->fresh()->status);
        $this->assertDatabaseHas('zoho_sync_failures', ['sync_batch_id' => $batch->id, 'failure_kind' => 'bulk_export', 'resolved_at' => null]);
    }

    public function test_typed_download_failure_attempts_are_added_to_terminal_bulk_telemetry(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 1);
        $batch = $this->batch();
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'download-fails']]]], 201));
        $bulk->shouldReceive('status')->once()
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => '/crm/bulk/v8/read/download-fails/result',
                'count' => 1,
                'more_records' => false,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->andThrow(ZohoBulkReadTransportException::downloadFailed(3, 200));

        $result = $this->drive($this->service($bulk, new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::FAILED, $result->action);
        $this->assertSame(
            5,
            ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('api_requests'),
        );
        $this->assertDatabaseHas('zoho_sync_failures', [
            'sync_batch_id' => $batch->id,
            'failure_kind' => 'bulk_export',
            'attempts' => 1,
            'resolved_at' => null,
        ]);
    }

    public function test_active_module_and_page_leases_prevent_duplicate_remote_work_then_recover_when_stale(): void
    {
        $batch = $this->batch();
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'running',
            'lease_owner' => 'standard-worker', 'lease_expires_at' => now()->addMinute(),
        ]);
        $blockedTransport = Mockery::mock(ZohoBulkReadTransport::class);
        $blockedTransport->shouldReceive('create')->never();
        $blocked = $this->service($blockedTransport, new BulkRecordTransport)
            ->step($batch->id, 'accounts', $batch->correlation_id);

        $this->assertSame(BulkBackfillStep::REDISPATCH, $blocked->action);
        $this->assertSame('standard-worker', ZohoSyncCheckpoint::query()->value('lease_owner'));
        $root = ZohoBulkReadJob::query()->firstOrFail();
        $this->assertNotEmpty($root->module_lease_owner);

        ZohoSyncCheckpoint::query()->update(['lease_expires_at' => now()->subSecond()]);
        $transport = Mockery::mock(ZohoBulkReadTransport::class);
        $transport->shouldReceive('create')->once()->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'remote-lock']]]], 201));
        $service = $this->service($transport, new BulkRecordTransport);
        $started = $service->step($batch->id, 'accounts', $batch->correlation_id, $root->id);

        $this->assertSame(BulkBackfillStep::REDISPATCH, $started->action);
        $this->assertSame($root->fresh()->module_lease_owner, ZohoSyncCheckpoint::query()->value('lease_owner'));

        $root->refresh()->update(['lease_owner' => 'duplicate-delivery', 'lease_expires_at' => now()->addMinute()]);
        $duplicateTransport = Mockery::mock(ZohoBulkReadTransport::class);
        $duplicateTransport->shouldReceive('status')->never();
        $duplicate = $this->service($duplicateTransport, new BulkRecordTransport)
            ->step($batch->id, 'accounts', $batch->correlation_id, $root->id);
        $this->assertSame(BulkBackfillStep::REDISPATCH, $duplicate->action);
    }

    public function test_activity_bulk_modules_use_the_registry_submodule_for_checkpoint_log_and_batch_finalization(): void
    {
        foreach ([
            ['tasks', 'Tasks', 'Tasks'],
            ['events', 'Events', 'Events'],
            ['calls', 'Calls', 'Calls'],
        ] as [$module, $apiName, $submodule]) {
            $batch = $this->batch([$module]);
            $archive = $this->zip("Id\n");
            $url = "/crm/bulk/v8/read/{$module}-job/result";
            $bulk = Mockery::mock(ZohoBulkReadTransport::class);
            $bulk->shouldReceive('create')->once()->with($apiName, null, $batch->correlation_id)
                ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => $module.'-job']]]], 201));
            $bulk->shouldReceive('status')->once()->andReturn($this->bulkResponse(['data' => [[
                'state' => 'COMPLETED',
                'result' => ['download_url' => $url, 'count' => 0, 'more_records' => false],
            ]]], 200));
            $bulk->shouldReceive('downloadToTempFile')->once()
                ->andReturn(new BulkDownload($archive, 1, filesize($archive)));

            $result = $this->drive(
                $this->service($bulk, new BulkRecordTransport),
                $batch,
                null,
                $module,
            );

            $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
            $this->assertDatabaseHas('zoho_sync_checkpoints', [
                'module' => 'v2:'.$module,
                'submodule' => $submodule,
                'status' => 'completed',
            ]);
            $this->assertDatabaseHas('zoho_sync_logs', [
                'sync_batch_id' => $batch->id,
                'module' => $module,
                'submodule' => $submodule,
                'status' => 'success',
            ]);
            $this->assertDatabaseHas('zoho_bulk_read_jobs', [
                'sync_batch_id' => $batch->id,
                'module' => $module,
                'submodule' => $submodule,
                'status' => 'complete',
            ]);
            $this->assertDatabaseMissing('zoho_sync_checkpoints', [
                'module' => 'v2:'.$module,
                'submodule' => '',
            ]);
            $this->assertSame('success', $batch->fresh()->status);
        }
    }

    public function test_expired_processing_claim_at_the_attempt_ceiling_is_quarantined_instead_of_looping_forever(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 2);
        $batch = $this->batch();
        $root = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'staged',
            'module_lease_owner' => (string) str()->uuid(),
            'started_at' => now(),
            'watermark_at' => now(),
            'counters' => ['staged' => 1, 'reported' => 1, 'parsed' => 1, 'api_requests' => 3, 'export_failed' => 0, 'token_restarts' => 0],
        ]);
        $work = ZohoSyncWorkItem::query()->create([
            'zoho_bulk_read_job_id' => $root->id,
            'module' => 'accounts',
            'zoho_id' => 'crashed-at-ceiling',
            'status' => 'processing',
            'attempts' => 2,
            'lease_owner' => 'dead-worker',
            'lease_expires_at' => now()->subSecond(),
            'api_requests' => 1,
            'correlation_id' => $batch->correlation_id,
        ]);
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldNotReceive('create');
        $bulk->shouldNotReceive('status');
        $bulk->shouldNotReceive('downloadToTempFile');

        $result = $this->drive($this->service($bulk, new BulkRecordTransport), $batch, $root->id);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame('quarantined', $work->fresh()->status);
        $this->assertNull($work->fresh()->lease_owner);
        $this->assertDatabaseHas('zoho_sync_failures', [
            'module' => 'accounts',
            'submodule' => '',
            'zoho_id' => 'crashed-at-ceiling',
            'failure_kind' => 'record',
            'resolved_at' => null,
        ]);
        $this->assertDatabaseHas('zoho_sync_logs', [
            'sync_batch_id' => $batch->id,
            'status' => 'partial',
            'records_quarantined' => 1,
        ]);
    }

    public function test_added_is_a_nonterminal_remote_state_and_is_polled_again(): void
    {
        $batch = $this->batch();
        $archive = $this->zip("Id\n");
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->andReturn($this->bulkResponse([
            'data' => [['details' => ['id' => 'added-job']]],
        ], 201));
        $bulk->shouldReceive('status')->twice()->andReturn(
            $this->bulkResponse(['data' => [['state' => 'ADDED']]], 200),
            $this->bulkResponse(['data' => [[
                'state' => 'COMPLETED',
                'result' => [
                    'download_url' => '/crm/bulk/v8/read/added-job/result',
                    'count' => 0,
                    'more_records' => false,
                ],
            ]]], 200),
        );
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->andReturn(new BulkDownload($archive, 1, filesize($archive)));

        $result = $this->drive($this->service($bulk, new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame(4, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('api_requests'));
    }

    public function test_completed_result_requires_more_records_and_token_consistency(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 1);
        $batch = $this->batch();
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->andReturn($this->bulkResponse([
            'data' => [['details' => ['id' => 'inconsistent-job']]],
        ], 201));
        $bulk->shouldReceive('status')->once()->andReturn($this->bulkResponse(['data' => [[
            'state' => 'COMPLETED',
            'result' => [
                'download_url' => '/crm/bulk/v8/read/inconsistent-job/result',
                'count' => 0,
                'more_records' => true,
            ],
        ]]], 200));
        $bulk->shouldNotReceive('downloadToTempFile');

        $result = $this->drive($this->service($bulk, new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::FAILED, $result->action);
        $this->assertDatabaseHas('zoho_sync_logs', ['sync_batch_id' => $batch->id, 'status' => 'error']);
    }

    public function test_capacity_deferral_releases_the_page_without_consuming_attempts_or_quarantining(): void
    {
        $batch = $this->batch();
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->andThrow(
            ZohoBulkReadTransportException::deferred(600),
        );

        $step = $this->service($bulk, new BulkRecordTransport)->step(
            $batch->id,
            'accounts',
            $batch->correlation_id,
        );

        $root = ZohoBulkReadJob::query()->where('sync_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(BulkBackfillStep::REDISPATCH, $step->action);
        $this->assertSame(600, $step->delaySeconds);
        $this->assertSame(0, $root->attempts);
        $this->assertSame('queued', $root->status);
        $this->assertNull($root->lease_owner);
        $this->assertTrue($root->retry_after->greaterThanOrEqualTo(now()->addSeconds(600)));
        $this->assertDatabaseCount('zoho_sync_failures', 0);
    }

    public function test_reported_result_count_must_match_the_streamed_csv_rows(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 1);
        $batch = $this->batch();
        $archive = $this->zip("Id\nonly-one\n");
        $bulk = $this->completedBulkTransport(
            'count-mismatch-job',
            '/crm/bulk/v8/read/count-mismatch-job/result',
            $archive,
            2,
        );

        $result = $this->drive($this->service($bulk, new BulkRecordTransport), $batch);

        $this->assertSame(BulkBackfillStep::FAILED, $result->action);
        $this->assertDatabaseHas('zoho_sync_logs', ['sync_batch_id' => $batch->id, 'status' => 'error']);
    }

    public function test_all_continuation_exports_are_staged_before_any_record_hydration(): void
    {
        $batch = $this->batch();
        $archive = $this->zip("Id\nfirst-1\nfirst-2\n");
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->with('Accounts', null, $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'stage-first']]]], 201));
        $bulk->shouldReceive('status')->once()->andReturn($this->bulkResponse(['data' => [[
            'state' => 'COMPLETED',
            'result' => [
                'download_url' => '/crm/bulk/v8/read/stage-first/result',
                'count' => 2,
                'more_records' => true,
                'next_page_token' => 'stage-next-token',
            ],
        ]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->andReturn(new BulkDownload($archive, 1, filesize($archive)));
        $bulk->shouldReceive('create')->once()->with('Accounts', 'stage-next-token', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'stage-second']]]], 201));
        $records = new BulkRecordTransport;
        $service = $this->service($bulk, $records);

        $created = $service->step($batch->id, 'accounts', $batch->correlation_id);
        $this->advance($created->delaySeconds);
        $staged = $service->step($batch->id, 'accounts', $batch->correlation_id, $created->bulkJobId);
        $nextExport = $service->step($batch->id, 'accounts', $batch->correlation_id, $staged->bulkJobId);

        $this->assertSame(BulkBackfillStep::REDISPATCH, $nextExport->action);
        $this->assertSame(0, $records->calls);
        $this->assertDatabaseHas('zoho_bulk_read_jobs', [
            'page_key' => hash('sha256', 'stage-next-token'),
            'status' => 'polling',
        ]);
    }

    public function test_typed_continuation_expiry_restarts_from_root_without_hydrating_partial_exports(): void
    {
        $batch = $this->batch();
        $archive = $this->zip("Id\nfirst-1\n");
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->with('Accounts', null, $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'restart-first']]]], 201));
        $bulk->shouldReceive('status')->once()->andReturn($this->bulkResponse(['data' => [[
            'state' => 'COMPLETED',
            'result' => [
                'download_url' => '/crm/bulk/v8/read/restart-first/result',
                'count' => 1,
                'more_records' => true,
                'next_page_token' => 'expiring-next-token',
            ],
        ]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->andReturn(new BulkDownload($archive, 1, filesize($archive)));
        $bulk->shouldReceive('create')->once()->with('Accounts', 'expiring-next-token', $batch->correlation_id)
            ->andThrow(new ZohoBulkReadTransportException(
                ZohoBulkReadTransportException::TOKEN_EXPIRED,
                400,
                1,
                'Bulk Read continuation token expired.',
            ));
        $records = new BulkRecordTransport;
        $service = $this->service($bulk, $records);

        $created = $service->step($batch->id, 'accounts', $batch->correlation_id);
        $this->advance($created->delaySeconds);
        $staged = $service->step($batch->id, 'accounts', $batch->correlation_id, $created->bulkJobId);
        $restarted = $service->step($batch->id, 'accounts', $batch->correlation_id, $staged->bulkJobId);

        $root = ZohoBulkReadJob::query()->where('page_key', 'root')->firstOrFail();
        $this->assertSame(BulkBackfillStep::REDISPATCH, $restarted->action);
        $this->assertSame($root->id, $restarted->bulkJobId);
        $this->assertSame('queued', $root->fresh()->status);
        $this->assertSame(1, data_get($root->fresh()->counters, 'token_restarts'));
        $this->assertSame(0, ZohoSyncWorkItem::query()->count());
        $this->assertSame(0, $records->calls);
        $this->assertDatabaseHas('zoho_bulk_read_jobs', [
            'page_key' => hash('sha256', 'expiring-next-token'),
            'status' => 'superseded',
        ]);
    }

    public function test_expired_chain_can_revive_the_same_superseded_token_and_hydrates_each_record_once(): void
    {
        $batch = $this->batch();
        $firstArchive = $this->zip("Id\nfirst-1\n");
        $restartedArchive = $this->zip("Id\nfirst-1\n");
        $continuationArchive = $this->zip("Id\nsecond-1\n");
        $token = 'reusable-after-expiry';
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->with('Accounts', null, $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'expired-root']]]], 201));
        $bulk->shouldReceive('status')->once()->with('expired-root', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => '/crm/bulk/v8/read/expired-root/result',
                'count' => 1,
                'more_records' => true,
                'next_page_token' => $token,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->with('/crm/bulk/v8/read/expired-root/result', $batch->correlation_id)
            ->andReturn(new BulkDownload($firstArchive, 1, filesize($firstArchive)));
        $bulk->shouldReceive('create')->once()->with('Accounts', $token, $batch->correlation_id)
            ->andThrow(new ZohoBulkReadTransportException(
                ZohoBulkReadTransportException::TOKEN_EXPIRED,
                400,
                1,
                'Bulk Read continuation token expired.',
            ));
        $bulk->shouldReceive('create')->once()->with('Accounts', null, $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'restarted-root']]]], 201));
        $bulk->shouldReceive('status')->once()->with('restarted-root', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => '/crm/bulk/v8/read/restarted-root/result',
                'count' => 1,
                'more_records' => true,
                'next_page_token' => $token,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->with('/crm/bulk/v8/read/restarted-root/result', $batch->correlation_id)
            ->andReturn(new BulkDownload($restartedArchive, 1, filesize($restartedArchive)));
        $bulk->shouldReceive('create')->once()->with('Accounts', $token, $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['details' => ['id' => 'restarted-continuation']]]], 201));
        $bulk->shouldReceive('status')->once()->with('restarted-continuation', $batch->correlation_id)
            ->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
                'download_url' => '/crm/bulk/v8/read/restarted-continuation/result',
                'count' => 1,
                'more_records' => false,
            ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()
            ->with('/crm/bulk/v8/read/restarted-continuation/result', $batch->correlation_id)
            ->andReturn(new BulkDownload($continuationArchive, 1, filesize($continuationArchive)));
        $records = new BulkRecordTransport;
        $service = $this->service($bulk, $records);

        $result = $this->drive($service, $batch);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame(2, $records->calls);
        $this->assertSame(2, ZohoSyncWorkItem::query()->count());
        $this->assertSame(2, ZohoSyncWorkItem::query()->where('status', 'completed')->count());
        $this->assertSame(2, ZohoBulkReadJob::query()->where('sync_batch_id', $batch->id)->count());
        $this->assertDatabaseHas('zoho_bulk_read_jobs', [
            'page_key' => hash('sha256', $token),
            'status' => 'complete',
        ]);
        $this->assertNotNull(
            ZohoSyncFailure::query()->where('failure_kind', 'bulk_export')->firstOrFail()->resolved_at
        );
        $this->assertFileDoesNotExist($firstArchive);
        $this->assertFileDoesNotExist($restartedArchive);
        $this->assertFileDoesNotExist($continuationArchive);
    }

    public function test_delayed_finalization_uses_the_persisted_export_start_watermark_for_the_next_delta(): void
    {
        $batch = $this->batch();
        $archive = $this->zip("Id\n");
        $bulk = $this->completedBulkTransport(
            'delayed-job',
            '/crm/bulk/v8/read/delayed-job/result',
            $archive,
            0,
        );
        $service = $this->service($bulk, new BulkRecordTransport);

        $created = $service->step($batch->id, 'accounts', $batch->correlation_id);
        Carbon::setTestNow('2026-08-09 14:00:00');
        $result = $this->drive($service, $batch, $created->bulkJobId);

        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $root = ZohoBulkReadJob::query()->where('page_key', 'root')->firstOrFail();
        $this->assertSame('2026-08-09 10:00:00', $root->watermark_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-08-09 10:00:00',
            ZohoSyncCheckpoint::query()->where('module', 'v2:accounts')->firstOrFail()->cursor_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-09 10:00:00',
            ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->firstOrFail()->cursor_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_completed_old_delivery_returns_without_reacquiring_or_overwriting_a_newer_checkpoint(): void
    {
        $batch = $this->batch();
        $archive = $this->zip("Id\n");
        $completed = $this->drive($this->service($this->completedBulkTransport(
            'old-job',
            '/crm/bulk/v8/read/old-job/result',
            $archive,
            0,
        ), new BulkRecordTransport), $batch);
        $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:accounts')->firstOrFail();
        $checkpoint->update([
            'status' => 'running',
            'sync_mode' => 'delta',
            'correlation_id' => 'newer-delta-correlation',
            'cursor_at' => now()->addHour(),
            'cursor_modified_time' => now()->addHour(),
            'lease_owner' => 'newer-delta-owner',
            'lease_expires_at' => now()->addHour(),
        ]);
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldNotReceive('create');
        $bulk->shouldNotReceive('status');
        $bulk->shouldNotReceive('downloadToTempFile');

        $duplicate = $this->service($bulk, new BulkRecordTransport)
            ->step($batch->id, 'accounts', $batch->correlation_id, $completed->bulkJobId);

        $this->assertSame(BulkBackfillStep::COMPLETE, $duplicate->action);
        $checkpoint->refresh();
        $this->assertSame('newer-delta-correlation', $checkpoint->correlation_id);
        $this->assertSame('newer-delta-owner', $checkpoint->lease_owner);
        $this->assertSame('delta', $checkpoint->sync_mode);
    }

    public function test_bulk_job_atomically_reserves_and_queues_its_descendant_before_the_parent_returns(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 5);
        $batch = $this->batch();
        $service = Mockery::mock(ZohoBulkBackfillService::class);
        $service->shouldReceive('step')->once()->with(
            $batch->id,
            'accounts',
            $batch->correlation_id,
            null,
            Mockery::type('string'),
            Mockery::type('string'),
            0,
        )
            ->andReturn(BulkBackfillStep::redispatch(17, 30));
        $this->app->instance(ZohoBulkBackfillService::class, $service);
        $job = new RunZohoBulkBackfillJob($batch->id, 'accounts', $batch->correlation_id);
        $root = ZohoBulkReadJob::query()->create([
            'id' => 17,
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'polling',
            'module_lease_owner' => $job->runOwner,
            'delivery_generation' => 0,
            'started_at' => now(),
            'watermark_at' => now(),
        ]);
        $deadline = $job->retryDeadline;

        $job->handle();

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame($batch->id.':accounts', $job->uniqueId());
        $this->assertNotSame($job->uniqueId(), (new RunZohoBulkBackfillJob($batch->id + 1, 'accounts', 'next-batch'))->uniqueId());
        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 1800], $job->backoff());
        $this->assertSame(900, $job->timeout);
        $this->assertSame(1, $root->fresh()->delivery_generation);
        $queued = DB::table('jobs')->where('queue', 'zoho')->first();
        $this->assertNotNull($queued);
        $payload = json_decode((string) $queued->payload, true, flags: JSON_THROW_ON_ERROR);
        $next = unserialize($payload['data']['command']);
        $this->assertInstanceOf(RunZohoBulkBackfillJob::class, $next);
        $this->assertSame(17, $next->bulkJobId);
        $this->assertSame($deadline, $next->retryDeadline);
        $this->assertSame($job->runOwner, $next->runOwner);
        $this->assertNotSame($job->deliveryOwner, $next->deliveryOwner);
        $this->assertSame(1, $next->deliveryGeneration);

        // The child is durably queued but has not handled yet. A delayed
        // failed() callback from its ancestor must observe the reservation.
        $job->failed(new RuntimeException('ancestor exhausted after handoff'));
        $this->assertSame('polling', $root->fresh()->status);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'zoho')->count());
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
    }

    public function test_bulk_child_dispatch_failure_rolls_back_the_generation_reservation_without_an_orphan(): void
    {
        $batch = $this->batch();
        $parent = new RunZohoBulkBackfillJob($batch->id, 'accounts', $batch->correlation_id);
        $root = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'polling',
            'module_lease_owner' => $parent->runOwner,
            'delivery_generation' => 0,
            'started_at' => now(),
            'watermark_at' => now(),
        ]);
        $queueThatFailsAfterInsert = new class
        {
            public function later(mixed $delay, mixed $job, ?string $queue = null): never
            {
                DB::table('jobs')->insert([
                    'queue' => $queue ?? 'zoho',
                    'payload' => '{}',
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                    'created_at' => now()->timestamp,
                ]);

                throw new RuntimeException('database queue unavailable after insert');
            }
        };
        Queue::shouldReceive('connection')->once()->with('zoho')->andReturn($queueThatFailsAfterInsert);

        try {
            app(ZohoBulkDeliveryHandoff::class)->reserveAndDispatch(
                $parent,
                BulkBackfillStep::redispatch($root->id, 30),
            );
            $this->fail('The queue insertion should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database queue unavailable after insert', $exception->getMessage());
        }

        $this->assertSame(0, $root->fresh()->delivery_generation);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_bulk_job_failed_callback_quarantines_owned_work_idempotently_without_pii(): void
    {
        $batch = $this->batch();
        $job = new RunZohoBulkBackfillJob(
            $batch->id,
            'accounts',
            $batch->correlation_id,
        );
        $page = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'staged',
            'module_lease_owner' => $job->runOwner,
            'lease_owner' => $job->deliveryOwner,
            'delivery_generation' => $job->deliveryGeneration,
            'lease_expires_at' => now()->addMinute(),
            'started_at' => now(),
            'watermark_at' => now(),
            'counters' => ['api_requests' => 3],
        ]);
        $continuation = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => hash('sha256', 'continuation-token'),
            'page_token' => 'continuation-token',
            'correlation_id' => $batch->correlation_id,
            'status' => 'staged',
            'module_lease_owner' => $job->runOwner,
            'delivery_generation' => $job->deliveryGeneration,
            'started_at' => now(),
            'watermark_at' => now(),
            'counters' => ['api_requests' => 2],
        ]);
        $work = ZohoSyncWorkItem::query()->create([
            'zoho_bulk_read_job_id' => $continuation->id,
            'module' => 'accounts',
            'zoho_id' => 'owned-record',
            'status' => 'processing',
            'lease_owner' => $job->deliveryOwner,
            'lease_expires_at' => now()->addMinute(),
            'attempts' => 1,
            'api_requests' => 1,
            'correlation_id' => $batch->correlation_id,
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'backfill',
            'status' => 'running',
            'lease_owner' => $job->runOwner,
            'lease_expires_at' => now()->addMinute(),
            'correlation_id' => $batch->correlation_id,
        ]);

        $job->failed(new RuntimeException('person@example.test'));
        $job->failed(new RuntimeException('person@example.test'));

        $this->assertSame('quarantined', $work->fresh()->status);
        $this->assertSame('partial', $page->fresh()->status);
        $this->assertSame('error', $batch->fresh()->status);
        $this->assertSame(1, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->count());
        $failure = ZohoSyncFailure::query()->where('failure_kind', 'record')->firstOrFail();
        $this->assertSame($continuation->id, data_get($failure->context, 'bulk_job_id'));
        $this->assertSame($work->id, data_get($failure->context, 'work_item_id'));
        $this->assertStringNotContainsString('person@example.test', $failure->error_summary);
        $this->assertStringNotContainsString('person@example.test', json_encode($failure->context));
    }

    public function test_terminalizer_commits_bounded_chunks_and_recovers_once_after_a_mid_run_crash(): void
    {
        config()->set('zoho-v2.bulk.termination_chunk_size', 1);
        $batch = $this->batch();
        $job = new RunZohoBulkBackfillJob($batch->id, 'accounts', $batch->correlation_id);
        $root = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'staged',
            'module_lease_owner' => $job->runOwner,
            'lease_owner' => $job->deliveryOwner,
            'delivery_generation' => $job->deliveryGeneration,
            'lease_expires_at' => now()->addMinute(),
            'started_at' => now(),
            'watermark_at' => now(),
            'counters' => ['api_requests' => 2],
        ]);
        foreach (['terminal-1', 'terminal-2', 'terminal-3'] as $zohoId) {
            ZohoSyncWorkItem::query()->create([
                'zoho_bulk_read_job_id' => $root->id,
                'module' => 'accounts',
                'zoho_id' => $zohoId,
                'status' => 'queued',
                'attempts' => 0,
                'api_requests' => 0,
                'correlation_id' => $batch->correlation_id,
            ]);
        }
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('finalizeBatch')->once()->with($batch->id);
        $crashing = new ZohoBulkRunTerminator(
            app(ZohoModuleRegistry::class),
            $orchestrator,
            static function (int $processed): void {
                throw new RuntimeException('injected terminator crash after '.$processed.' row');
            },
        );
        $this->app->instance(ZohoBulkRunTerminator::class, $crashing);

        try {
            $job->failed(new RuntimeException('queue delivery exhausted for private@example.test'));
            $this->fail('The injected terminalizer crash should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Zoho V2 Bulk terminalization failed; recovery remains pending.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('terminalizing', $root->fresh()->status);
        $this->assertTrue($root->fresh()->retry_after->isFuture());
        $this->assertSame('delivery_exhausted', $root->fresh()->terminalization_reason);
        $this->assertEquals([
            'version' => 1,
            'source' => 'framework_failed_job',
            'delivery_generation' => 0,
        ], $root->fresh()->terminalization_context);
        $this->assertStringNotContainsString(
            'private@example.test',
            json_encode($root->fresh()->terminalization_context, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString('private@example.test', (string) $root->fresh()->error_summary);
        $this->assertSame(1, ZohoSyncWorkItem::query()->where('status', 'quarantined')->count());
        $this->assertSame(2, ZohoSyncWorkItem::query()->whereIn('status', ['queued', 'processing', 'retry_wait'])->count());
        $this->assertSame(1, ZohoSyncFailure::query()->where('failure_kind', 'record')->count());
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);

        $root->refresh();
        $dueAt = $root->retry_after;
        if ($root->lease_expires_at?->greaterThan($dueAt)) {
            $dueAt = $root->lease_expires_at;
        }
        Carbon::setTestNow($dueAt->addSecond());
        $recovery = new ZohoBulkRunTerminator(app(ZohoModuleRegistry::class), $orchestrator);
        $sweeper = new RecoverZohoBulkTerminalizationsJob;
        $sweeper->handle($recovery);
        $sweeper->handle($recovery);

        $this->assertSame('partial', $root->fresh()->status);
        $this->assertSame(0, ZohoSyncWorkItem::query()->whereIn('status', ['queued', 'processing', 'retry_wait'])->count());
        $this->assertSame(3, ZohoSyncWorkItem::query()->where('status', 'quarantined')->count());
        $this->assertSame(3, ZohoSyncFailure::query()->where('failure_kind', 'record')->count());
        $this->assertSame(1, ZohoSyncFailure::query()->where('failure_kind', 'bulk_export')->count());
        $this->assertSame(4, (int) ZohoSyncFailure::query()->sum('attempts'));
        $this->assertSame(1, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->count());
    }

    public function test_bulk_terminalization_recovery_sanitizes_remote_exception_details(): void
    {
        $batch = $this->batch();
        $root = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'terminalizing',
            'module_lease_owner' => 'sanitized-run-owner',
            'delivery_generation' => 1,
            'retry_after' => now()->subMinute(),
            'terminalization_reason' => 'delivery_exhausted',
            'terminalization_context' => [
                'version' => 1,
                'source' => 'framework_failed_job',
                'delivery_generation' => 1,
            ],
            'started_at' => now()->subHour(),
            'watermark_at' => now()->subHour(),
        ]);
        $terminator = Mockery::mock(ZohoBulkRunTerminator::class);
        $terminator->shouldReceive('terminate')->once()
            ->andThrow(new RuntimeException('private@example.test must not persist'));

        try {
            (new RecoverZohoBulkTerminalizationsJob)->handle($terminator);
            $this->fail('The recovery exception should propagate in sanitized form.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Zoho V2 Bulk recovery failed; see correlation ID.', $exception->getMessage());
        }

        $root->refresh();
        $this->assertSame('terminalizing', $root->status);
        $this->assertNull($root->lease_owner);
        $this->assertTrue($root->retry_after->isFuture());
        $this->assertStringNotContainsString('private@example.test', (string) $root->error_summary);
        $this->assertStringNotContainsString(
            'private@example.test',
            json_encode($root->terminalization_context, JSON_THROW_ON_ERROR),
        );
    }

    public function test_bulk_failed_callback_cannot_terminate_a_foreign_active_delivery(): void
    {
        $batch = $this->batch();
        $page = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'polling',
            'module_lease_owner' => 'module-owner',
            'lease_owner' => 'newer-delivery',
            'lease_expires_at' => now()->addMinute(),
            'started_at' => now(),
            'watermark_at' => now(),
        ]);

        (new RunZohoBulkBackfillJob(
            $batch->id,
            'accounts',
            $batch->correlation_id,
            $page->id,
            null,
            'module-owner',
            'older-delivery',
            0,
        ))->failed(new RuntimeException('deadline'));

        $this->assertSame('polling', $page->fresh()->status);
        $this->assertSame('newer-delivery', $page->fresh()->lease_owner);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
    }

    public function test_delayed_ancestor_failure_cannot_terminate_a_live_descendant_generation(): void
    {
        $batch = $this->batch();
        $ancestor = new RunZohoBulkBackfillJob(
            $batch->id,
            'accounts',
            $batch->correlation_id,
            null,
            null,
            'stable-run-owner',
            'ancestor-delivery',
            1,
        );
        $page = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'polling',
            'module_lease_owner' => $ancestor->runOwner,
            'delivery_generation' => 2,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'started_at' => now(),
            'watermark_at' => now(),
        ]);

        $ancestor->failed(new RuntimeException('late ancestor callback'));

        $this->assertSame('polling', $page->fresh()->status);
        $this->assertSame(2, $page->fresh()->delivery_generation);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
    }

    public function test_interrupted_old_backfill_cannot_regress_a_newer_checkpoint_cursor_or_correlation(): void
    {
        $batch = $this->batch();
        $batch->update(['started_at' => now()->subHours(2)]);
        $root = ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $batch->correlation_id,
            'status' => 'staged',
            'module_lease_owner' => 'old-run-owner',
            'delivery_generation' => 1,
            'started_at' => now()->subHours(2),
            'watermark_at' => now()->subHours(2),
            'counters' => ['staged' => 0, 'reported' => 0, 'parsed' => 0, 'api_requests' => 1, 'export_failed' => 0, 'token_restarts' => 0],
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'delta',
            'status' => 'completed',
            'cursor_at' => now()->subHour(),
            'cursor_modified_time' => now()->subHour(),
            'correlation_id' => 'newer-delta-correlation',
        ]);

        $result = $this->service(Mockery::mock(ZohoBulkReadTransport::class), new BulkRecordTransport)->step(
            $batch->id,
            'accounts',
            $batch->correlation_id,
            $root->id,
            'old-delivery',
            'old-run-owner',
            1,
        );

        $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:accounts')->firstOrFail();
        $this->assertSame(BulkBackfillStep::COMPLETE, $result->action);
        $this->assertSame('newer-delta-correlation', $checkpoint->correlation_id);
        $this->assertSame(now()->subHour()->toDateTimeString(), $checkpoint->cursor_at->toDateTimeString());
        $this->assertSame('delta', $checkpoint->sync_mode);
        $this->assertSame('completed', $checkpoint->status);
        $this->assertNull($checkpoint->lease_owner);
    }

    private function batch(array $modules = ['accounts']): ZohoSyncBatch
    {
        return ZohoSyncBatch::query()->create([
            'correlation_id' => (string) str()->uuid(),
            'mode' => 'backfill',
            'trigger' => 'manual',
            'status' => 'queued',
            'modules' => $modules,
            'requested_at' => now(),
        ]);
    }

    private function service(ZohoBulkReadTransport $bulk, ZohoTransport $records): ZohoBulkBackfillService
    {
        return new ZohoBulkBackfillService(
            $bulk,
            app(ZohoModuleRegistry::class),
            new BulkCsvIdParser,
            $records,
            app(ZohoRecordIngestor::class),
            app(ZohoSyncOrchestrator::class),
        );
    }

    private function completedBulkTransport(
        string $remoteId,
        string $downloadUrl,
        string $archive,
        int $count,
    ): ZohoBulkReadTransport {
        $bulk = Mockery::mock(ZohoBulkReadTransport::class);
        $bulk->shouldReceive('create')->once()->andReturn($this->bulkResponse(['data' => [['details' => ['id' => $remoteId]]]], 201));
        $bulk->shouldReceive('status')->once()->andReturn($this->bulkResponse(['data' => [['state' => 'COMPLETED', 'result' => [
            'download_url' => $downloadUrl,
            'count' => $count,
            'more_records' => false,
        ]]]], 200));
        $bulk->shouldReceive('downloadToTempFile')->once()->with($downloadUrl, Mockery::type('string'))
            ->andReturn(new BulkDownload($archive, 1, filesize($archive)));

        return $bulk;
    }

    private function bulkResponse(array $payload, int $status): BulkTransportResponse
    {
        return new BulkTransportResponse($payload, $status, 1);
    }

    private function drive(
        ZohoBulkBackfillService $service,
        ZohoSyncBatch $batch,
        ?int $bulkJobId = null,
        string $module = 'accounts',
    ): BulkBackfillStep {
        $history = [];
        for ($delivery = 0; $delivery < 50; $delivery++) {
            $step = $service->step($batch->id, $module, $batch->correlation_id, $bulkJobId);
            $history[] = [$step->action, $step->bulkJobId, $step->delaySeconds];
            $bulkJobId = $step->bulkJobId;
            if ($step->action !== BulkBackfillStep::REDISPATCH) {
                return $step;
            }
            $this->advance($step->delaySeconds);
        }

        throw new RuntimeException('Bulk state machine did not converge: '.json_encode([
            'pages' => ZohoBulkReadJob::query()->get(['id', 'status', 'attempts', 'retry_after', 'error_summary', 'module_lease_owner', 'lease_owner', 'lease_expires_at'])->toArray(),
            'work' => ZohoSyncWorkItem::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all(),
            'failures' => ZohoSyncFailure::query()->get(['failure_kind', 'attempts', 'resolved_at'])->toArray(),
            'checkpoint' => ZohoSyncCheckpoint::query()->get(['lease_owner', 'lease_expires_at'])->toArray(),
            'history' => $history,
        ], JSON_THROW_ON_ERROR));
    }

    private function advance(int $seconds): void
    {
        Carbon::setTestNow(now()->addSeconds(max(1, $seconds)));
    }

    private function zip(string $csv): string
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension unavailable.');
        }
        $path = tempnam(sys_get_temp_dir(), 'zoho-bulk-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('records.csv', $csv);
        $zip->close();

        return $path;
    }
}

final class BulkRecordTransport implements ZohoTransport
{
    public int $calls = 0;

    /** @param array<string,int> $failuresRemaining */
    public function __construct(private array $failuresRemaining = []) {}

    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        $this->calls++;
        $id = rawurldecode((string) basename($path));
        $attempt = [new TransportAttempt(1, 200, null)];
        if (($this->failuresRemaining[$id] ?? 0) > 0) {
            $this->failuresRemaining[$id]--;

            return new TransportResult(503, [], [], [], $correlationId ?? 'bulk-record', $attempt, 'http_503');
        }
        $record = ['id' => $id, 'Account_Name' => 'Account '.$id, 'Created_Time' => '2026-08-01T10:00:00+00:00'];

        return new TransportResult(200, [$record], [], [], $correlationId ?? 'bulk-record', $attempt, null, ['data' => [$record]]);
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        return $this->get($path, $query, $correlationId);
    }
}
