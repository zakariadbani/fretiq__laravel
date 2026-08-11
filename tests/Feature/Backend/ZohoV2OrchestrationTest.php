<?php

namespace Tests\Feature\Backend;

use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Jobs\Zoho\RunZohoPostReconciliationJob;
use App\Jobs\Zoho\ZohoModuleRunOutcome;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoMarketingLink;
use App\Models\Zoho\ZohoStandardSyncRun;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Bulk\ZohoModuleDispatcher;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\SyncResult;
use App\Services\Zoho\V2\Identity\ZohoIdentityLinker;
use App\Services\Zoho\V2\PostReconciliation\ZohoPostReconciliationProcessor;
use App\Services\Zoho\V2\Reconciliation\FailedRecordHydrator;
use App\Services\Zoho\V2\Reconciliation\MapperFailedRecordHydrator;
use App\Services\Zoho\V2\Sync\ModuleDeliveryPreparation;
use App\Services\Zoho\V2\Sync\ZohoStandardRecoveryService;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ZohoV2OrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v2_automation_is_disabled_by_default_and_jobs_use_the_dedicated_queue(): void
    {
        $this->assertFalse(Setting::get('zoho.auto_sync_enabled', false));
        $this->assertFalse(Setting::get('zoho.nightly_reconciliation_enabled', false));
        $this->assertSame('hourly', Setting::get('zoho.sync_frequency', 'hourly'));
        $this->assertSame('zoho', config('zoho-v2.queue'));

        $job = new RunZohoModuleSyncJob(42, 'accounts', 'delta', 'test-correlation');

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame('zoho', $job->queue);
        $this->assertSame('zoho', $job->connection);
        $this->assertSame('42:accounts:g0', $job->uniqueId());
        $this->assertSame($job->uniqueId(), (new RunZohoModuleSyncJob(42, 'accounts', 'reconcile', 'duplicate'))->uniqueId());
        $this->assertNotSame(
            (new RunZohoModuleSyncJob(42, 'accounts', 'delta', 'test-correlation', checkpointGeneration: 3))->uniqueId(),
            (new RunZohoModuleSyncJob(42, 'accounts', 'delta', 'test-correlation', checkpointGeneration: 4))->uniqueId(),
        );
        $this->assertNotSame($job->uniqueId(), (new RunZohoModuleSyncJob(43, 'accounts', 'delta', 'next-batch'))->uniqueId());
        $this->assertSame(5, $job->tries);
        $this->assertSame(1200, $job->timeout);
        $this->assertSame([60, 300, 900, 1800], $job->backoff());
        $this->assertInstanceOf(ZohoTransport::class, app(ZohoTransport::class));
    }

    public function test_v2_permissions_are_limited_for_commercials_and_organization_wide_for_admins(): void
    {
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $expected = [
            'view marketing dashboard', 'view zoho records', 'view zoho', 'sync zoho',
            'backfill zoho', 'manage zoho mappings', 'view zoho raw payload', 'export zoho records',
        ];
        $this->assertEqualsCanonicalizing($expected, Permission::query()->whereIn('name', $expected)->pluck('name')->all());

        $commercial = Role::findByName('commercial');
        $this->assertTrue($commercial->hasPermissionTo('view marketing dashboard'));
        $this->assertTrue($commercial->hasPermissionTo('view zoho records'));
        foreach (['view zoho', 'sync zoho', 'backfill zoho', 'manage zoho mappings', 'view zoho raw payload', 'export zoho records'] as $permission) {
            $this->assertFalse($commercial->hasPermissionTo($permission));
        }

        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('backfill zoho'));
        $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo('view zoho raw payload'));
    }

    public function test_retry_hydrator_contract_resolves_to_the_mapper_implementation(): void
    {
        $this->assertInstanceOf(MapperFailedRecordHydrator::class, app(FailedRecordHydrator::class));
    }

    public function test_lease_conflict_returns_for_delayed_retry_without_fabricating_a_module_log(): void
    {
        $batch = $this->syncBatch('lease-conflict');
        $batch->update(['mode' => 'delta']);
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('runModule')->once()->andReturn(new SyncResult(leaseConflict: true));
        $orchestrator->shouldNotReceive('finalizeBatch');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        (new RunZohoModuleSyncJob($batch->id, 'accounts', 'delta', $batch->correlation_id))->handle();

        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
        $this->assertSame('running', $batch->fresh()->status);
    }

    public function test_reconciliation_continuation_dispatches_the_same_durable_batch_without_finalizing_it(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('reconcile-continuation');
        $job = new RunZohoModuleSyncJob(
            $batch->id,
            'accounts',
            'reconcile',
            $batch->correlation_id,
        );
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('runModule')->once()->andReturn(new SyncResult(
            continuationRequired: true,
            reconciliation: [
                'status' => 'running',
                'complete' => true,
                'continuation_required' => true,
            ],
        ));
        $orchestrator->shouldReceive('prepareModuleDelivery')->once()->with(
            $batch->id,
            'accounts',
            'reconcile',
            $batch->correlation_id,
            $job->retryDeadline,
        )->andReturn(ModuleDeliveryPreparation::prepared(7));
        $orchestrator->shouldNotReceive('finalizeBatch');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $job->handle();

        Queue::assertPushed(RunZohoModuleSyncJob::class, function (RunZohoModuleSyncJob $next) use ($batch, $job): bool {
            return $next->batchId === $batch->id
                && $next->module === 'accounts'
                && $next->mode === 'reconcile'
                && $next->correlationId === $batch->correlation_id
                && $next->retryDeadline === $job->retryDeadline
                && $next->checkpointGeneration === 7;
        });
        $this->assertSame('running', $batch->fresh()->status);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
    }

    public function test_reconciliation_completion_dispatches_post_processing_once_and_only_after_terminal_logs_exist(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('reconcile-post-processing');
        $this->syncLog($batch, 'success');

        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        Queue::assertPushed(RunZohoPostReconciliationJob::class, 1);
        $this->assertSame('success', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);

        $tracker = (object) ['batch_id' => null];
        $processor = new class($tracker) implements ZohoPostReconciliationProcessor
        {
            public function __construct(private readonly object $tracker) {}

            public function process(int $batchId): array
            {
                $this->tracker->batch_id = $batchId;

                return ['batch_id' => $batchId];
            }
        };
        (new RunZohoPostReconciliationJob($batch->id))->handle($processor);

        $this->assertSame($batch->id, $tracker->batch_id);
        $this->assertSame('completed', $batch->fresh()->post_reconciliation_status);
    }

    public function test_post_reconciliation_enqueue_failure_rolls_back_batch_completion_atomically(): void
    {
        config()->set('zoho-v2.queue_connection', 'missing-post-test-connection');
        $batch = $this->syncBatch('post-enqueue-rollback');
        $this->syncLog($batch, 'success');

        try {
            app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);
            $this->fail('The invalid queue connection must reject post-processing enqueue.');
        } catch (\InvalidArgumentException) {
            $this->assertNull($batch->fresh()->completed_at);
            $this->assertSame('running', $batch->fresh()->status);
            $this->assertNull($batch->fresh()->post_reconciliation_status);
        }
    }

    public function test_delta_completion_revalidates_exact_email_links_without_waiting_for_nightly_reconciliation(): void
    {
        Queue::fake();
        $contact = Contact::factory()->create(['email' => 'delta-link@example.test']);
        $lead = ZohoLead::query()->create([
            'zoho_id' => 'delta-linked-lead',
            'email' => 'delta-link@example.test',
            'normalized_email' => 'delta-link@example.test',
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'delta-linked-lead-v1'),
        ]);
        app(ZohoIdentityLinker::class)->linkMarketingContacts();
        $this->assertTrue(ZohoMarketingLink::query()->sole()->is_active);

        $lead->update([
            'email' => 'changed-by-delta@example.test',
            'normalized_email' => 'changed-by-delta@example.test',
            'payload_hash' => hash('sha256', 'delta-linked-lead-v2'),
        ]);
        $batch = $this->syncBatch('delta-identity-refresh');
        $batch->update(['mode' => 'delta', 'modules' => ['leads']]);
        ZohoSyncLog::query()->create([
            'module' => 'leads',
            'mode' => 'delta',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'synced_at' => now(),
            'status' => 'success',
            'telemetry' => [],
        ]);

        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertSame('pending', $batch->fresh()->post_reconciliation_status);
        Queue::assertPushed(RunZohoPostReconciliationJob::class, fn (RunZohoPostReconciliationJob $job): bool => $job->batchId === $batch->id);

        (new RunZohoPostReconciliationJob($batch->id))->handle(app(ZohoPostReconciliationProcessor::class));

        $this->assertFalse(ZohoMarketingLink::query()->sole()->is_active);
        $this->assertSame('completed', $batch->fresh()->post_reconciliation_status);
        $this->assertSame($contact->id, ZohoMarketingLink::query()->sole()->fretiq_entity_id);
    }

    public function test_post_reconciliation_failure_is_sanitized_reclaimable_and_a_late_failure_cannot_regress_completion(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('post-reconciliation-retry');
        $this->syncLog($batch, 'success');
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $failing = new class implements ZohoPostReconciliationProcessor
        {
            public function process(int $batchId): array
            {
                throw new RuntimeException('private@example.test');
            }
        };
        $firstJob = new RunZohoPostReconciliationJob($batch->id);
        try {
            $firstJob->handle($failing);
            $this->fail('The post-reconciliation failure must be retried.');
        } catch (RuntimeException $exception) {
            $this->assertSame('retrying', $batch->fresh()->post_reconciliation_status);
            $this->assertSame(1, $batch->fresh()->post_reconciliation_attempts);
            $this->assertNotNull($batch->fresh()->post_reconciliation_retry_not_before);
            $this->assertStringNotContainsString('private@example.test', (string) $batch->fresh()->post_reconciliation_error);
            $this->assertSame('Zoho V2 post-reconciliation is retryable; see correlation ID.', $exception->getMessage());
            $this->assertStringNotContainsString('private@example.test', $exception->getMessage());
        }

        $successful = new class implements ZohoPostReconciliationProcessor
        {
            public function process(int $batchId): array
            {
                return ['batch_id' => $batchId];
            }
        };
        (new RunZohoPostReconciliationJob($batch->id))->handle($successful);
        $this->assertSame('completed', $batch->fresh()->post_reconciliation_status);
        $this->assertSame(2, $batch->fresh()->post_reconciliation_attempts);

        $firstJob->failed(new RuntimeException('late ancestor failure'));
        $this->assertSame('completed', $batch->fresh()->post_reconciliation_status);
        $this->assertNull($batch->fresh()->post_reconciliation_error);
    }

    public function test_finalize_batch_waits_for_terminal_latest_module_logs(): void
    {
        $batch = $this->syncBatch('nonterminal-log');
        $this->syncLog($batch, 'retrying');

        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertNull($batch->fresh()->completed_at);
        $this->assertSame('running', $batch->fresh()->status);
    }

    public function test_finalize_batch_ignores_preserved_error_logs_while_current_standard_work_is_unfinished(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'recovered-current-work-fence',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'running',
            'modules' => ['contacts', 'quotes'],
            'requested_at' => now(),
        ]);
        foreach ([['contacts', 'success'], ['quotes', 'error']] as [$module, $status]) {
            ZohoSyncLog::query()->create([
                'module' => $module,
                'submodule' => '',
                'mode' => 'delta',
                'sync_batch_id' => $batch->id,
                'correlation_id' => $batch->correlation_id,
                'synced_at' => now(),
                'status' => $status,
                'telemetry' => [],
            ]);
        }
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:contacts',
            'submodule' => '',
            'sync_mode' => 'delta',
            'status' => 'completed',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'completed_at' => now(),
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:quotes',
            'submodule' => '',
            'sync_mode' => 'delta',
            'status' => 'queued',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
        ]);
        ZohoStandardSyncRun::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'quotes',
            'submodule' => '',
            'correlation_id' => $batch->correlation_id,
            'mode' => 'delta',
            'query_fingerprint' => hash('sha256', 'quotes-resume'),
            'query_params' => ['fields' => 'id'],
            'watermark_at' => $batch->requested_at,
            'status' => 'enumerating',
            'counters' => [],
        ]);

        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertSame('running', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->completed_at);
        Queue::assertNotPushed(RunZohoPostReconciliationJob::class);
    }

    public function test_finalize_batch_ignores_a_wrong_mode_checkpoint_as_stale_ownership(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'wrong-mode-checkpoint',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'running',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);
        ZohoSyncLog::query()->create([
            'module' => 'accounts',
            'submodule' => '',
            'mode' => 'delta',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'synced_at' => now(),
            'status' => 'success',
            'telemetry' => [],
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'reconcile',
            'status' => 'queued',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
        ]);

        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertSame('success', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
        Queue::assertPushed(RunZohoPostReconciliationJob::class, 1);
    }

    public function test_sibling_success_cannot_seal_a_batch_while_reconciliation_has_no_terminal_log(): void
    {
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'reconciliation-race', 'mode' => 'reconcile', 'trigger' => 'manual',
            'status' => 'running', 'modules' => ['accounts', 'contacts'], 'requested_at' => now(),
        ]);
        ZohoSyncLog::query()->create([
            'module' => 'contacts', 'mode' => 'reconcile', 'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id, 'synced_at' => now(), 'status' => 'success', 'telemetry' => [],
        ]);

        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertNull($batch->fresh()->completed_at);
        $this->assertSame('running', $batch->fresh()->status);
    }

    public function test_exhausted_sync_job_terminalizes_without_persisting_exception_details(): void
    {
        $batch = $this->syncBatch('exhausted-job');
        $batch->update(['mode' => 'delta']);
        $job = new RunZohoModuleSyncJob($batch->id, 'accounts', 'delta', $batch->correlation_id);

        $job->failed(new RuntimeException('private@example.test'));

        $log = ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->sole();
        $this->assertSame('error', $log->status);
        $this->assertSame('Queue job exhausted before completion.', $log->error);
        $this->assertStringNotContainsString('private@example.test', serialize($log->toArray()));
        $this->assertSame('error', $batch->fresh()->status);
    }

    public function test_retryable_sync_result_is_rethrown_without_finalizing_the_batch(): void
    {
        $batch = $this->syncBatch('fatal-correlation');
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('runModule')->once()->andReturn(new SyncResult(
            counters: ['quarantined' => 0],
            failures: ['safe failure marker'],
            retryableFailure: true,
        ));
        $orchestrator->shouldNotReceive('finalizeBatch');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);
        $this->app->bind(
            \App\Services\Zoho\V2\Reconciliation\ZohoReconciliationService::class,
            fn () => throw new RuntimeException('reconciliation must not resolve'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('see correlation ID');

        (new RunZohoModuleSyncJob($batch->id, 'accounts', 'reconcile', 'fatal-correlation'))->handle();
    }

    public function test_reconciliation_discrepancy_downgrades_the_module_log_and_batch_to_partial(): void
    {
        $batch = $this->syncBatch('reconcile-partial');
        $this->syncLog($batch, 'success');

        $status = ZohoModuleRunOutcome::recordReconciliation($batch->id, 'accounts', [
            'status' => 'degraded',
            'complete' => true,
            'http_status' => 200,
            'remote_count' => 12,
            'local_count' => 10,
            'missing_count' => 2,
            'extra_count' => 0,
            'pages' => 1,
            'api_requests' => 4,
            'deleted' => ['status' => 200, 'tombstoned' => 1],
        ]);
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertSame('partial', $status);
        $this->assertSame('partial', ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('status'));
        $this->assertSame(1, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('records_deleted'));
        $this->assertSame(4, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('api_requests'));
        $this->assertSame('partial', $batch->fresh()->status);
        $storedLog = ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->firstOrFail();
        $this->assertEquals([
            'status' => 'degraded',
            'complete' => true,
            'http_status' => 200,
            'remote_count' => 12,
            'local_count' => 10,
            'missing_count' => 2,
            'extra_count' => 0,
            'repaired_count' => 0,
            'swept_count' => 0,
            'pages' => 1,
            'api_requests' => 4,
            'hydration' => [
                'attempted' => 0,
                'failed' => 0,
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'quarantined' => 0,
                'api_requests' => 0,
            ],
            'deleted_status' => 200,
            'tombstoned' => 1,
        ], $storedLog->telemetry['reconciliation']);
    }

    public function test_reconciliation_transport_failure_downgrades_health_without_logging_record_ids(): void
    {
        $batch = $this->syncBatch('reconcile-error');
        $log = $this->syncLog($batch, 'success');

        $status = ZohoModuleRunOutcome::recordReconciliation($batch->id, 'accounts', [
            'status' => 'degraded',
            'complete' => false,
            'http_status' => 503,
            'remote_count' => null,
            'local_count' => null,
            'missing_count' => null,
            'extra_count' => null,
            'pages' => 0,
            'deleted' => ['status' => 503, 'tombstoned' => 0],
            'remote_ids' => ['private-record-id'],
        ]);
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertSame('error', $status);
        $this->assertSame('error', $log->fresh()->status);
        $this->assertSame('error', $batch->fresh()->status);
        $this->assertStringNotContainsString('private-record-id', (string) $log->fresh()->error);
        $this->assertStringNotContainsString('private-record-id', json_encode($log->fresh()->telemetry));
    }

    public function test_now_command_returns_nonzero_and_skips_reconciliation_after_fatal_sync(): void
    {
        $batch = $this->syncBatch('fatal-now');
        $this->syncLog($batch, 'error');
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->once()->andReturn($batch);
        $orchestrator->shouldReceive('runModule')->once()->andReturn(new SyncResult(failures: ['safe failure marker']));
        $orchestrator->shouldReceive('finalizeBatch')->once()->with($batch->id);
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);
        $this->app->bind(
            \App\Services\Zoho\V2\Reconciliation\ZohoReconciliationService::class,
            fn () => throw new RuntimeException('reconciliation must not resolve'),
        );

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts', '--mode' => 'reconcile', '--now' => true,
        ])->expectsOutputToContain('failed')
            ->assertExitCode(1);
    }

    public function test_now_command_reports_partial_sync_without_treating_it_as_fatal(): void
    {
        $batch = $this->syncBatch('partial-now');
        $this->syncLog($batch, 'partial');
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->once()->andReturn($batch);
        $orchestrator->shouldReceive('runModule')->once()->andReturn(new SyncResult(
            counters: ['quarantined' => 1],
            failures: ['One or more records were quarantined.'],
        ));
        $orchestrator->shouldReceive('finalizeBatch')->once()->with($batch->id);
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts', '--mode' => 'delta', '--now' => true,
        ])->expectsOutputToContain('partial')
            ->assertExitCode(0);
    }

    public function test_scheduled_delta_skips_while_a_nightly_reconciliation_batch_is_unfinished(): void
    {

        $this->syncBatch('unfinished-nightly-reconciliation');

        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->never();
        $orchestrator->shouldReceive('runModule')->never();
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts',
            '--mode' => 'delta',
            '--trigger' => 'scheduled',
        ])->expectsOutputToContain('reconciliation is still active')
            ->assertExitCode(0);

        $this->assertDatabaseCount('zoho_sync_batches', 1);
    }

    public function test_scheduled_sync_skips_an_unfinished_batch_of_the_same_mode(): void
    {
        $batch = $this->syncBatch('unfinished-scheduled-delta');
        $batch->update(['mode' => 'delta']);

        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->never();
        $orchestrator->shouldReceive('runModule')->never();
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts',
            '--mode' => 'delta',
            '--trigger' => 'scheduled',
        ])->expectsOutputToContain('equivalent scheduled batch is still active')
            ->assertExitCode(0);

        $this->assertDatabaseCount('zoho_sync_batches', 1);
    }

    public function test_scheduled_delta_leaves_a_manually_paused_delta_batch_untouched(): void
    {
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'manually-paused-scheduled-fence',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'paused',
            'modules' => ['accounts'],
            'requested_at' => now()->subHour(),
        ]);

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts',
            '--mode' => 'delta',
            '--trigger' => 'scheduled',
        ])->expectsOutputToContain('manually paused')
            ->assertExitCode(0);

        $this->assertDatabaseCount('zoho_sync_batches', 1);
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->completed_at);
    }

    public function test_scheduled_reconcile_leaves_a_manually_paused_delta_batch_untouched(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'manually-paused-reconcile-fence',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'paused',
            'modules' => ['accounts'],
            'requested_at' => now()->subHour(),
        ]);

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts',
            '--mode' => 'reconcile',
            '--trigger' => 'scheduled',
        ])->expectsOutputToContain('manually paused')
            ->assertExitCode(0);

        $this->assertDatabaseCount('zoho_sync_batches', 1);
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->completed_at);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_failure_terminalizes_the_created_batch_without_orphaning_it(): void
    {
        config()->set('zoho-v2.queue_connection', 'missing-zoho-test-connection');

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts',
            '--mode' => 'delta',
        ])->expectsOutputToContain('could not dispatch every module')
            ->assertExitCode(1);

        $batch = ZohoSyncBatch::query()->sole();
        $this->assertSame('error', $batch->status);
        $this->assertNotNull($batch->completed_at);
        $this->assertDatabaseHas('zoho_sync_logs', [
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'status' => 'error',
            'error' => 'Synchronization dispatch failed before execution.',
        ]);
    }

    public function test_paused_batch_without_a_checkpoint_is_ignored_by_dispatch_without_a_log(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'paused-before-first-delivery',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'paused',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);

        app(ZohoModuleDispatcher::class)->dispatch(
            $batch->id,
            'accounts',
            'delta',
            $batch->correlation_id,
        );

        Queue::assertNotPushed(RunZohoModuleSyncJob::class);
        $this->assertDatabaseMissing('zoho_sync_checkpoints', [
            'module' => 'v2:accounts',
            'sync_batch_id' => $batch->id,
        ]);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->completed_at);
    }

    public function test_resume_after_an_ignored_preparation_cannot_turn_it_into_terminalization(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'ignored-then-resumed-preparation',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'paused',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('prepareModuleDelivery')->once()->andReturnUsing(
            function () use ($batch): ModuleDeliveryPreparation {
                $batch->update(['status' => 'running', 'resumed_at' => now()]);

                return ModuleDeliveryPreparation::ignored();
            },
        );
        $orchestrator->shouldNotReceive('terminalizeModule');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        app(ZohoModuleDispatcher::class)->dispatch(
            $batch->id,
            'accounts',
            'delta',
            $batch->correlation_id,
        );

        Queue::assertNotPushed(RunZohoModuleSyncJob::class);
        $this->assertSame('running', $batch->fresh()->status);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
    }

    public function test_paused_module_job_handle_and_late_failed_callback_are_harmless(): void
    {
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'paused-queued-delivery',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'paused',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'delta',
            'status' => 'paused',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'generation' => 4,
        ]);
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldNotReceive('runModule');
        $orchestrator->shouldNotReceive('terminalizeModule');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);
        $job = new RunZohoModuleSyncJob(
            $batch->id,
            'accounts',
            'delta',
            $batch->correlation_id,
            null,
            'module:paused-ancestor',
            3,
        );

        $job->handle();
        $job->failed(new RuntimeException('late paused delivery'));

        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
    }

    public function test_stale_queued_generation_handle_and_late_failed_callback_are_harmless(): void
    {
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'stale-queued-generation',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'running',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'delta',
            'status' => 'queued',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'generation' => 6,
        ]);
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldNotReceive('runModule');
        $orchestrator->shouldNotReceive('terminalizeModule');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);
        $job = new RunZohoModuleSyncJob(
            $batch->id,
            'accounts',
            'delta',
            $batch->correlation_id,
            null,
            'module:stale-queued-generation',
            5,
        );

        $job->handle();
        $job->failed(new RuntimeException('late stale delivery'));

        $this->assertSame('running', $batch->fresh()->status);
        $this->assertSame(6, $checkpoint->fresh()->generation);
        $this->assertSame('queued', $checkpoint->fresh()->status);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
    }

    public function test_paused_batch_is_not_finalized_or_terminalized(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'paused-terminal-fence',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'paused',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);
        $this->syncLog($batch, 'success')->update(['mode' => 'delta']);

        app(ZohoSyncOrchestrator::class)->terminalizeModule(
            $batch->id,
            'accounts',
            'delta',
            'job_exhausted',
        );
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);

        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->completed_at);
        Queue::assertNotPushed(RunZohoPostReconciliationJob::class);
    }

    public function test_forged_or_late_module_delivery_is_ignored_without_mutating_the_batch(): void
    {
        $batch = $this->syncBatch('expected-correlation');
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldNotReceive('runModule');
        $orchestrator->shouldNotReceive('terminalizeModule');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $forged = new RunZohoModuleSyncJob($batch->id, 'accounts', 'reconcile', 'forged-correlation');
        $forged->handle();
        $forged->failed(new RuntimeException('forged ancestor failure'));
        $batch->update(['status' => 'success', 'completed_at' => now()]);
        (new RunZohoModuleSyncJob($batch->id, 'accounts', 'reconcile', $batch->correlation_id))->handle();

        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
        $this->assertSame('success', $batch->fresh()->status);
    }

    public function test_missing_reconciliation_result_is_retried_without_sealing_the_batch(): void
    {
        $batch = $this->syncBatch('reconcile-exception');
        $this->syncLog($batch, 'success');
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('runModule')->once()->andReturn(new SyncResult);
        $orchestrator->shouldNotReceive('terminalizeModule');
        $orchestrator->shouldNotReceive('finalizeBatch');
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        try {
            (new RunZohoModuleSyncJob($batch->id, 'accounts', 'reconcile', $batch->correlation_id))->handle();
            $this->fail('The failed reconciliation should be retried by the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Zoho V2 reconciliation failed; see correlation ID.', $exception->getMessage());
        }
    }

    public function test_late_terminalization_does_not_overwrite_a_same_correlation_successful_module(): void
    {
        Queue::fake();

        $batch = $this->syncBatch('terminalized-reconciliation');
        $log = $this->syncLog($batch, 'success');

        app(ZohoSyncOrchestrator::class)->terminalizeModule(
            $batch->id,
            'accounts',
            'reconcile',
            'reconciliation_missing',
        );

        $this->assertSame('success', $log->fresh()->status);
        $this->assertSame('success', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
        $this->assertNull($log->fresh()->error);
        Queue::assertPushed(RunZohoPostReconciliationJob::class, 1);
    }

    public function test_quarantined_partial_sync_finalizes_without_triggering_a_job_retry(): void
    {
        $batch = $this->syncBatch('partial-job');
        $batch->update(['mode' => 'delta']);
        $log = $this->syncLog($batch, 'partial');
        $log->update(['mode' => 'delta']);
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('runModule')->once()->andReturn(new SyncResult(
            counters: ['quarantined' => 1],
            failures: ['One or more records were quarantined.'],
        ));
        $orchestrator->shouldReceive('finalizeBatch')->once()->with($batch->id);
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        (new RunZohoModuleSyncJob($batch->id, 'accounts', 'delta', $batch->correlation_id))->handle();

        $this->assertSame('partial', $log->fresh()->status);
    }

    public function test_standard_recovery_requeues_a_crash_window_continuation_only_for_its_exact_unfinished_batch(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('continuation-outbox');
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'reconcile',
            'status' => 'running',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'generation' => 7,
            'heartbeat_at' => now()->subHour(),
        ]);
        $finished = $this->syncBatch('finished-continuation');
        $finished->update(['status' => 'success', 'completed_at' => now()]);
        $finishedCheckpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:contacts', 'submodule' => '', 'sync_mode' => 'reconcile', 'status' => 'retrying',
            'sync_batch_id' => $finished->id, 'correlation_id' => $finished->correlation_id,
        ]);
        $ancestor = new RunZohoModuleSyncJob(
            $batch->id,
            'accounts',
            'reconcile',
            $batch->correlation_id,
            null,
            'module:stale-running-ancestor',
            7,
        );

        $recovery = app(ZohoStandardRecoveryService::class);
        $claim = new \ReflectionMethod($recovery, 'claimModuleContinuation');
        $claim->setAccessible(true);
        $delivery = $claim->invoke($recovery, $checkpoint->id);

        $this->assertIsArray($delivery);
        $this->assertSame(8, $delivery[5]);
        $this->assertSame('queued', $checkpoint->fresh()->status);
        $this->assertSame(8, (int) $checkpoint->fresh()->generation);

        // Exact crash boundary: claim transaction committed, replacement has
        // not been enqueued yet. The ancestor must already be fenced.
        $ancestor->failed(new RuntimeException('late stale-running callback'));
        $this->assertSame('queued', $checkpoint->fresh()->status);
        $this->assertSame(8, (int) $checkpoint->fresh()->generation);
        $this->assertNull($batch->fresh()->error_summary);
        $this->assertDatabaseMissing('zoho_sync_logs', [
            'sync_batch_id' => $batch->id, 'module' => 'accounts', 'status' => 'error',
        ]);

        app(ZohoModuleDispatcher::class)->dispatchPrepared(...$delivery);
        Queue::assertPushed(RunZohoModuleSyncJob::class, function (RunZohoModuleSyncJob $job) use ($batch): bool {
            return $job->batchId === $batch->id
                && $job->module === 'accounts'
                && $job->mode === 'reconcile'
                && $job->correlationId === $batch->correlation_id
                && $job->checkpointGeneration === 8;
        });
        $this->assertSame('queued', $checkpoint->fresh()->status);
        $this->assertSame(8, (int) $checkpoint->fresh()->generation);
        $this->assertNull($checkpoint->fresh()->lease_owner);
        $this->assertNull($claim->invoke($recovery, $finishedCheckpoint->id));
    }

    public function test_standard_recovery_leaves_a_paused_batch_and_queued_checkpoint_untouched(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'paused-recovery-fence',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'paused',
            'modules' => ['accounts'],
            'requested_at' => now()->subHour(),
        ]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'delta',
            'status' => 'queued',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'generation' => 9,
            'heartbeat_at' => now()->subHour(),
        ]);

        $result = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(['module_jobs' => 0, 'post_jobs' => 0], $result);
        Queue::assertNothingPushed();
        $this->assertSame('paused', $batch->fresh()->status);
        $this->assertSame('queued', $checkpoint->fresh()->status);
        $this->assertSame(9, $checkpoint->fresh()->generation);
        $this->assertNull($checkpoint->fresh()->lease_owner);
    }

    public function test_standard_recovery_requeues_pending_failed_and_expired_post_reconciliation_work(): void
    {
        Queue::fake();
        $pending = $this->syncBatch('post-pending');
        $pending->update(['status' => 'success', 'completed_at' => now(), 'post_reconciliation_status' => 'pending']);
        $failed = $this->syncBatch('post-failed');
        $failed->update(['status' => 'partial', 'completed_at' => now(), 'post_reconciliation_status' => 'failed']);
        $expired = $this->syncBatch('post-expired');
        $expired->update([
            'status' => 'success', 'completed_at' => now(), 'post_reconciliation_status' => 'running',
            'post_reconciliation_lease_owner' => 'stale', 'post_reconciliation_lease_expires_at' => now()->subMinute(),
        ]);

        $result = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(3, $result['post_jobs']);
        Queue::assertPushed(RunZohoPostReconciliationJob::class, 3);
        foreach ([$pending, $failed, $expired] as $batch) {
            $this->assertSame('pending', $batch->fresh()->post_reconciliation_status);
            $this->assertNull($batch->fresh()->post_reconciliation_lease_owner);
        }
    }

    public function test_standard_recovery_never_steals_bulk_work_and_stops_exhausted_post_retries(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 2);
        Queue::fake();

        $bulkBatch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'bulk-owned-checkpoint',
            'mode' => 'backfill',
            'trigger' => 'manual',
            'status' => 'running',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'backfill',
            'status' => 'running',
            'sync_batch_id' => $bulkBatch->id,
            'correlation_id' => $bulkBatch->correlation_id,
        ]);
        \App\Models\Zoho\ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $bulkBatch->id,
            'module' => 'accounts',
            'submodule' => '',
            'page_key' => 'root',
            'correlation_id' => $bulkBatch->correlation_id,
            'status' => 'running',
        ]);

        $exhaustedPost = $this->syncBatch('exhausted-post');
        $exhaustedPost->update([
            'status' => 'partial',
            'completed_at' => now(),
            'post_reconciliation_status' => 'failed',
            'post_reconciliation_attempts' => 2,
        ]);

        $result = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(['module_jobs' => 0, 'post_jobs' => 0], $result);
        Queue::assertNotPushed(RunZohoModuleSyncJob::class);
        Queue::assertNotPushed(RunZohoPostReconciliationJob::class);
        $this->assertSame('failed', $exhaustedPost->fresh()->post_reconciliation_status);
    }

    public function test_standard_recovery_terminalizes_a_hard_killed_final_post_attempt_without_requeueing(): void
    {
        config()->set('zoho-v2.retry.max_attempts', 2);
        Queue::fake();
        $batch = $this->syncBatch('post-hard-killed-final-attempt');
        $batch->update([
            'status' => 'success',
            'completed_at' => now(),
            'post_reconciliation_status' => 'running',
            'post_reconciliation_attempts' => 2,
            'post_reconciliation_lease_owner' => 'killed-worker',
            'post_reconciliation_lease_expires_at' => now()->subSecond(),
            'post_reconciliation_retry_deadline_at' => now()->addHour(),
            'post_reconciliation_retry_not_before' => now()->addHour(),
        ]);

        $first = app(ZohoStandardRecoveryService::class)->recover();
        $second = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(0, $first['post_jobs']);
        $this->assertSame(0, $second['post_jobs']);
        $this->assertSame('failed', $batch->fresh()->post_reconciliation_status);
        $this->assertSame(2, $batch->fresh()->post_reconciliation_attempts);
        $this->assertNull($batch->fresh()->post_reconciliation_lease_owner);
        $this->assertNull($batch->fresh()->post_reconciliation_lease_expires_at);
        $this->assertNull($batch->fresh()->post_reconciliation_retry_not_before);
        $this->assertSame('Post-reconciliation processing exhausted before completion.', $batch->fresh()->post_reconciliation_error);
        Queue::assertNotPushed(RunZohoPostReconciliationJob::class);
    }

    public function test_standard_recovery_recreates_initial_delivery_despite_historical_checkpoint(): void
    {
        Queue::fake();
        $historical = $this->syncBatch('historical-completed-checkpoint');
        $historical->update(['status' => 'success', 'completed_at' => now()]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts',
            'submodule' => '',
            'sync_mode' => 'reconcile',
            'status' => 'completed',
            'sync_batch_id' => $historical->id,
            'correlation_id' => $historical->correlation_id,
            'cursor_at' => now()->subDay(),
            'cursor_modified_time' => now()->subDay(),
            'completed_at' => now()->subHour(),
            'counters' => ['seen' => 9],
            'delivery_retry_deadline_at' => now()->subMinute(),
        ]);
        $orphan = $this->syncBatch('orphan-after-historical-checkpoint');
        $orphan->update(['created_at' => now()->subMinutes(2)]);

        $result = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(1, $result['module_jobs']);
        Queue::assertPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $orphan->id
            && $job->module === 'accounts'
            && $job->retryDeadline === $checkpoint->fresh()->delivery_retry_deadline_at?->toIso8601String());
        $checkpoint->refresh();
        $this->assertSame($orphan->id, $checkpoint->sync_batch_id);
        $this->assertSame('queued', $checkpoint->status);
        $this->assertTrue($checkpoint->delivery_retry_deadline_at->isFuture());
        $this->assertNotNull($checkpoint->cursor_at);
        $this->assertSame(['seen' => 9], $checkpoint->counters);
    }

    public function test_standard_recovery_ignores_live_marker_and_recovers_stale_marker_once(): void
    {
        config()->set('zoho-v2.module.recovery_stale_seconds', 60);
        config()->set('zoho-v2.module.retrying_recovery_stale_seconds', 60);
        Queue::fake();
        $live = $this->syncBatch('recovery-live-marker');
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'reconcile',
            'status' => 'retrying', 'sync_batch_id' => $live->id,
            'correlation_id' => $live->correlation_id, 'heartbeat_at' => now(), 'counters' => [],
        ]);
        $stale = $this->syncBatch('recovery-stale-marker');
        ZohoSyncCheckpoint::query()->where('module', 'v2:accounts')->update([
            'sync_batch_id' => $stale->id, 'correlation_id' => $stale->correlation_id,
            'heartbeat_at' => now()->subMinutes(2),
        ]);

        $first = app(ZohoStandardRecoveryService::class)->recover();
        $second = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(1, $first['module_jobs']);
        $this->assertSame(0, $second['module_jobs']);
        Queue::assertPushed(RunZohoModuleSyncJob::class, 1);
    }

    public function test_standard_recovery_does_not_invalidate_a_queued_delivery_when_its_unique_lock_suppresses_reenqueue(): void
    {
        config()->set('zoho-v2.module.recovery_stale_seconds', 60);
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'queued-unique-lock-owner', 'mode' => 'delta', 'trigger' => 'schedule',
            'status' => 'queued', 'modules' => ['accounts'], 'requested_at' => now(),
        ]);

        app(ZohoModuleDispatcher::class)->dispatch($batch->id, 'accounts', 'delta', $batch->correlation_id);

        $this->assertSame(1, DB::table('jobs')->where('queue', 'zoho')->count());
        $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:accounts')->sole();
        $generation = (int) $checkpoint->generation;
        $checkpoint->update(['status' => 'queued', 'heartbeat_at' => now()->subMinutes(2)]);
        $queued = DB::table('jobs')->where('queue', 'zoho')->sole();
        $payload = json_decode((string) $queued->payload, true, flags: JSON_THROW_ON_ERROR);
        $original = unserialize($payload['data']['command']);
        $this->assertInstanceOf(RunZohoModuleSyncJob::class, $original);
        $this->assertSame($generation, $original->checkpointGeneration);

        $result = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(1, $result['module_jobs']);
        // Laravel's still-live ShouldBeUniqueUntilProcessing lock suppresses
        // the duplicate. The original payload remains valid because recovery
        // retained the same durable generation.
        $this->assertSame(1, DB::table('jobs')->where('queue', 'zoho')->count());
        $checkpoint->refresh();
        $this->assertSame($generation, $checkpoint->generation);
        $this->assertSame('queued', $checkpoint->status);
        $this->assertSame($batch->id, $checkpoint->sync_batch_id);
        $this->assertSame($batch->correlation_id, $checkpoint->correlation_id);
        $this->assertSame($checkpoint->generation, $original->checkpointGeneration);
    }

    public function test_standard_recovery_recovers_a_stale_queued_initial_delivery_and_uses_the_activity_submodule(): void
    {
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'queued-activity-orphan', 'mode' => 'delta', 'trigger' => 'manual',
            'status' => 'queued', 'modules' => ['tasks'], 'requested_at' => now(),
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);

        $result = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(1, $result['module_jobs']);
        Queue::assertPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $batch->id && $job->module === 'tasks');
        $this->assertDatabaseHas('zoho_sync_checkpoints', [
            'module' => 'v2:tasks', 'submodule' => 'Tasks', 'sync_batch_id' => $batch->id,
        ]);
    }

    public function test_standard_recovery_uses_bulk_policy_for_a_stale_initial_backfill(): void
    {
        config()->set('zoho-v2.bulk.verified_modules', ['accounts']);
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'queued-bulk-orphan', 'mode' => 'backfill', 'trigger' => 'manual',
            'status' => 'queued', 'modules' => ['accounts'], 'requested_at' => now(),
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);

        app(ZohoStandardRecoveryService::class)->recover();

        Queue::assertPushed(\App\Jobs\Zoho\RunZohoBulkBackfillJob::class, fn ($job): bool => $job->batchId === $batch->id && $job->module === 'accounts');
        Queue::assertNotPushed(RunZohoModuleSyncJob::class);
        $this->assertDatabaseMissing('zoho_sync_checkpoints', ['module' => 'v2:accounts', 'sync_batch_id' => $batch->id]);
    }

    public function test_standard_recovery_stops_re_dispatching_an_initial_bulk_batch_after_its_root_exists(): void
    {
        config()->set('zoho-v2.bulk.verified_modules', ['accounts']);
        Queue::fake();
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'bulk-root-handoff', 'mode' => 'backfill', 'trigger' => 'manual',
            'status' => 'queued', 'modules' => ['accounts'], 'requested_at' => now(),
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(1, app(ZohoStandardRecoveryService::class)->recover()['module_jobs']);
        Queue::assertPushed(\App\Jobs\Zoho\RunZohoBulkBackfillJob::class, 1);
        \App\Models\Zoho\ZohoBulkReadJob::query()->create([
            'sync_batch_id' => $batch->id, 'module' => 'accounts', 'submodule' => '',
            'page_key' => 'root', 'correlation_id' => $batch->correlation_id, 'status' => 'running',
        ]);
        Queue::fake();

        $this->assertSame(0, app(ZohoStandardRecoveryService::class)->recover()['module_jobs']);
        Queue::assertNothingPushed();
    }

    public function test_standard_recovery_claims_an_idle_historical_checkpoint_but_never_steals_a_live_or_newer_owner(): void
    {
        Queue::fake();
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'completed',
            'cursor_at' => now()->subDay(), 'counters' => ['seen' => 7], 'sync_batch_id' => null,
        ]);
        $orphan = ZohoSyncBatch::query()->create([
            'correlation_id' => 'idle-history-orphan', 'mode' => 'delta', 'trigger' => 'manual',
            'status' => 'queued', 'modules' => ['accounts'], 'requested_at' => now(),
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(1, app(ZohoStandardRecoveryService::class)->recover()['module_jobs']);
        $checkpoint->refresh();
        $this->assertSame($orphan->id, $checkpoint->sync_batch_id);
        $this->assertNotNull($checkpoint->cursor_at);
        $this->assertSame(['seen' => 7], $checkpoint->counters);

        $newer = ZohoSyncBatch::query()->create([
            'correlation_id' => 'newer-live-owner', 'mode' => 'delta', 'trigger' => 'manual',
            'status' => 'running', 'modules' => ['accounts'], 'requested_at' => now(),
        ]);
        $checkpoint->update([
            'sync_batch_id' => $newer->id, 'correlation_id' => $newer->correlation_id, 'status' => 'running',
            'lease_owner' => 'module:successor', 'lease_expires_at' => now()->addHour(), 'heartbeat_at' => now(),
        ]);
        $blocked = ZohoSyncBatch::query()->create([
            'correlation_id' => 'blocked-by-live-owner', 'mode' => 'delta', 'trigger' => 'manual',
            'status' => 'queued', 'modules' => ['accounts'], 'requested_at' => now(),
            'created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(0, app(ZohoStandardRecoveryService::class)->recover()['module_jobs']);
        $this->assertSame($newer->id, $checkpoint->fresh()->sync_batch_id);
        $this->assertSame('module:successor', $checkpoint->fresh()->lease_owner);
        Queue::assertNotPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $blocked->id);
    }

    public function test_standard_recovery_respects_framework_retry_backoff_and_a_fresh_heartbeat(): void
    {
        config()->set('zoho-v2.module.recovery_stale_seconds', 60);
        config()->set('zoho-v2.module.retrying_recovery_stale_seconds', 1860);
        Queue::fake();
        $batch = $this->syncBatch('retry-backoff-fence');
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'reconcile', 'status' => 'retrying',
            'sync_batch_id' => $batch->id, 'correlation_id' => $batch->correlation_id,
            'heartbeat_at' => now()->subMinutes(2),
        ]);

        $this->assertSame(0, app(ZohoStandardRecoveryService::class)->recover()['module_jobs']);
        $checkpoint->update(['heartbeat_at' => now()->subSeconds(1900), 'lease_expires_at' => now()->subSecond()]);
        $this->assertSame(1, app(ZohoStandardRecoveryService::class)->recover()['module_jobs']);
        $checkpoint->update(['status' => 'running', 'heartbeat_at' => now(), 'lease_expires_at' => now()->subSecond()]);
        $this->assertSame(0, app(ZohoStandardRecoveryService::class)->recover()['module_jobs']);
    }

    public function test_post_retry_not_before_prevents_recovery_from_cloning_the_framework_backoff_chain(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('post-retry-not-before');
        $this->syncLog($batch, 'success');
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);
        $job = new RunZohoPostReconciliationJob($batch->id);
        try {
            $job->handle(new class implements ZohoPostReconciliationProcessor
            {
                public function process(int $batchId): array
                {
                    throw new RuntimeException('retry me');
                }
            });
        } catch (RuntimeException) {
            // Laravel owns the immutable delivery's regular retry chain.
        }

        $this->assertSame('retrying', $batch->fresh()->post_reconciliation_status);
        $this->assertNotNull($batch->fresh()->post_reconciliation_retry_not_before);
        $this->assertSame(0, app(ZohoStandardRecoveryService::class)->recover()['post_jobs']);
        Queue::assertPushed(RunZohoPostReconciliationJob::class, 1);
    }

    public function test_late_module_callback_is_fenced_by_generation_during_a_lease_free_continuation_gap(): void
    {
        $batch = $this->syncBatch('lease-free-generation-gap');
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'reconcile', 'status' => 'retrying',
            'sync_batch_id' => $batch->id, 'correlation_id' => $batch->correlation_id,
            'generation' => 3, 'lease_owner' => null, 'lease_expires_at' => null, 'heartbeat_at' => now(),
        ]);
        (new RunZohoModuleSyncJob($batch->id, 'accounts', 'reconcile', $batch->correlation_id, null, 'module:ancestor', 2))
            ->failed(new RuntimeException('late ancestor callback'));

        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id, 'module' => 'accounts', 'status' => 'error']);
        $this->assertSame('retrying', $checkpoint->fresh()->status);
        $this->assertSame(3, $checkpoint->fresh()->generation);
    }

    public function test_recovery_finalizes_a_complete_terminal_module_log_set_exactly_once(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('finalize-orphaned-terminal-logs');
        $this->syncLog($batch, 'success');

        app(ZohoStandardRecoveryService::class)->recover();
        app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame('success', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
        Queue::assertPushed(RunZohoPostReconciliationJob::class, 1);
    }

    public function test_recovered_module_delivery_uses_its_persisted_retry_deadline(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('recovered-retry-deadline');
        $deadline = now()->addHours(3)->toIso8601String();
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'reconcile', 'status' => 'retrying',
            'sync_batch_id' => $batch->id, 'correlation_id' => $batch->correlation_id,
            'heartbeat_at' => now()->subSeconds(1900), 'delivery_retry_deadline_at' => $deadline,
        ]);

        app(ZohoStandardRecoveryService::class)->recover();

        Queue::assertPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $batch->id
            && $job->retryDeadline === $deadline);
    }

    public function test_new_batch_cannot_steal_a_lease_free_unfinished_module_outbox(): void
    {
        config()->set('zoho-v2.module.recovery_stale_seconds', 60);
        Queue::fake();
        $older = ZohoSyncBatch::query()->create([
            'correlation_id' => 'older-lease-free-owner', 'mode' => 'delta', 'trigger' => 'schedule',
            'status' => 'running', 'modules' => ['accounts'], 'requested_at' => now()->subMinutes(2),
        ]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'queued',
            'sync_batch_id' => $older->id, 'correlation_id' => $older->correlation_id, 'generation' => 7,
            'heartbeat_at' => now()->subMinutes(2), 'delivery_retry_deadline_at' => now()->addHours(6),
        ]);
        $newer = ZohoSyncBatch::query()->create([
            'correlation_id' => 'newer-manual-request', 'mode' => 'reconcile', 'trigger' => 'manual',
            'status' => 'queued', 'modules' => ['accounts'], 'requested_at' => now(),
        ]);

        app(ZohoModuleDispatcher::class)->dispatch($newer->id, 'accounts', 'reconcile', $newer->correlation_id);

        Queue::assertNotPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $newer->id);
        $this->assertSame($older->id, $checkpoint->fresh()->sync_batch_id);
        $this->assertSame(7, $checkpoint->fresh()->generation);
        $this->assertSame('error', $newer->fresh()->status);
        $this->assertNotNull($newer->fresh()->completed_at);
        $this->assertDatabaseHas('zoho_sync_logs', ['sync_batch_id' => $newer->id, 'module' => 'accounts', 'status' => 'error']);

        $result = app(ZohoStandardRecoveryService::class)->recover();
        $this->assertSame(1, $result['module_jobs']);
        Queue::assertPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $older->id);
    }

    public function test_inline_module_run_cannot_steal_a_lease_free_unfinished_module_outbox(): void
    {
        config()->set('zoho-v2.module.recovery_stale_seconds', 60);
        Queue::fake();
        $older = ZohoSyncBatch::query()->create([
            'correlation_id' => 'older-inline-owner', 'mode' => 'delta', 'trigger' => 'schedule',
            'status' => 'running', 'modules' => ['accounts'], 'requested_at' => now()->subMinutes(2),
        ]);
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'queued',
            'sync_batch_id' => $older->id, 'correlation_id' => $older->correlation_id, 'generation' => 11,
            'heartbeat_at' => now()->subMinutes(2), 'delivery_retry_deadline_at' => now()->addHours(6),
        ]);
        $newer = ZohoSyncBatch::query()->create([
            'correlation_id' => 'newer-inline-request', 'mode' => 'reconcile', 'trigger' => 'manual',
            'status' => 'running', 'modules' => ['accounts'], 'requested_at' => now(),
        ]);

        $result = app(ZohoSyncOrchestrator::class)->runModule($newer->id, 'accounts', 'reconcile', 'inline-now-worker');
        $this->assertTrue($result->leaseConflict);
        app(ZohoSyncOrchestrator::class)->terminalizeModule($newer->id, 'accounts', 'reconcile', 'inline_failed');

        $this->assertSame($older->id, $checkpoint->fresh()->sync_batch_id);
        $this->assertSame($older->correlation_id, $checkpoint->fresh()->correlation_id);
        $this->assertSame(11, $checkpoint->fresh()->generation);
        $this->assertSame('error', $newer->fresh()->status);
        $this->assertNotNull($newer->fresh()->completed_at);
        $this->assertDatabaseHas('zoho_sync_logs', ['sync_batch_id' => $newer->id, 'module' => 'accounts', 'status' => 'error']);

        $recovered = app(ZohoStandardRecoveryService::class)->recover();
        $this->assertSame(1, $recovered['module_jobs']);
        Queue::assertPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $older->id);
    }

    public function test_post_recovery_terminalizes_an_expired_durable_retry_window_without_enqueuing(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('expired-post-retry-window');
        $batch->update([
            'status' => 'success', 'completed_at' => now(), 'post_reconciliation_status' => 'failed',
            'post_reconciliation_retry_deadline_at' => now()->subSecond(),
        ]);

        $this->assertSame(0, app(ZohoStandardRecoveryService::class)->recover()['post_jobs']);
        $this->assertSame('failed', $batch->fresh()->post_reconciliation_status);
        $this->assertSame((int) config('zoho-v2.retry.max_attempts'), $batch->fresh()->post_reconciliation_attempts);
        $this->assertSame('Post-reconciliation retry window expired.', $batch->fresh()->post_reconciliation_error);
        Queue::assertNotPushed(RunZohoPostReconciliationJob::class);
    }

    public function test_late_module_failed_callback_cannot_regress_a_newer_successful_generation(): void
    {
        $batch = $this->syncBatch('late-module-generation');
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'reconcile',
            'status' => 'completed', 'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id, 'completed_at' => now(),
        ]);
        $success = $this->syncLog($batch, 'success');
        $job = new RunZohoModuleSyncJob($batch->id, 'accounts', 'reconcile', $batch->correlation_id);

        $job->failed(new RuntimeException('late module ancestor'));

        $this->assertSame('success', $success->fresh()->status);
        $this->assertSame('completed', $checkpoint->fresh()->status);
        $this->assertNull($batch->fresh()->error_summary);
    }

    public function test_late_module_failed_callback_cannot_regress_a_newer_live_generation(): void
    {
        $batch = $this->syncBatch('late-module-live-generation');
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'reconcile', 'status' => 'running',
            'sync_batch_id' => $batch->id, 'correlation_id' => $batch->correlation_id,
            'generation' => 2, 'lease_owner' => 'module:successor', 'lease_expires_at' => now()->addHour(),
        ]);
        $job = new RunZohoModuleSyncJob($batch->id, 'accounts', 'reconcile', $batch->correlation_id, null, 'module:ancestor');

        $job->failed(new RuntimeException('late module ancestor'));

        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id, 'module' => 'accounts', 'status' => 'error']);
        $this->assertSame('running', $checkpoint->fresh()->status);
        $this->assertSame('module:successor', $checkpoint->fresh()->lease_owner);
        $this->assertNull($batch->fresh()->error_summary);
    }

    public function test_late_post_failed_callback_cannot_regress_a_newer_live_attempt(): void
    {
        Queue::fake();
        $batch = $this->syncBatch('late-post-live-generation');
        $this->syncLog($batch, 'success');
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);
        $ancestor = new RunZohoPostReconciliationJob($batch->id);
        $failing = new class implements ZohoPostReconciliationProcessor
        {
            public function process(int $batchId): array
            {
                throw new RuntimeException('ancestor failed');
            }
        };
        try {
            $ancestor->handle($failing);
        } catch (RuntimeException) {
            // The later live worker has legitimately reclaimed this work.
        }
        $batch->update([
            'post_reconciliation_status' => 'running',
            'post_reconciliation_attempts' => 2,
            'post_reconciliation_lease_owner' => 'new-live-owner',
            'post_reconciliation_lease_expires_at' => now()->addHour(),
        ]);

        $ancestor->failed(new RuntimeException('late ancestor callback'));

        $this->assertSame('running', $batch->fresh()->post_reconciliation_status);
        $this->assertSame('new-live-owner', $batch->fresh()->post_reconciliation_lease_owner);
        $this->assertSame(2, $batch->fresh()->post_reconciliation_attempts);
    }

    public function test_terminalization_writes_the_checkpoint_and_module_log_as_one_terminal_outcome(): void
    {
        $batch = $this->syncBatch('terminal-checkpoint-log');
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'reconcile',
            'status' => 'running', 'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
        ]);

        app(ZohoSyncOrchestrator::class)->terminalizeModule($batch->id, 'accounts', 'reconcile', 'dispatch_failed');

        $this->assertSame('failed', $checkpoint->fresh()->status);
        $this->assertNotNull($checkpoint->fresh()->completed_at);
        $log = ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->where('module', 'accounts')->sole();
        $this->assertSame('error', $log->status);
        $this->assertSame($batch->correlation_id, $log->correlation_id);
    }

    private function syncBatch(string $correlationId): ZohoSyncBatch
    {
        return ZohoSyncBatch::query()->create([
            'correlation_id' => $correlationId,
            'mode' => 'reconcile',
            'trigger' => 'manual',
            'status' => 'running',
            'modules' => ['accounts'],
            'requested_at' => now(),
        ]);
    }

    private function syncLog(ZohoSyncBatch $batch, string $status): ZohoSyncLog
    {
        return ZohoSyncLog::query()->create([
            'module' => 'accounts',
            'mode' => 'reconcile',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'synced_at' => now(),
            'status' => $status,
            'telemetry' => [],
        ]);
    }
}
