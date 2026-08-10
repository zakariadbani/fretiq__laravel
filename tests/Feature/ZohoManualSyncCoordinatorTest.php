<?php

namespace Tests\Feature;

use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoManualSyncCoordinator;
use App\Services\Zoho\V2\Sync\ZohoStandardRecoveryService;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ZohoManualSyncCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_call_creates_one_batch_and_compatible_active_call_is_a_no_op(): void
    {
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->once()->with(['accounts', 'contacts'], 'delta', 'manual', 9)
            ->andReturnUsing(fn (): ZohoSyncBatch => $this->batch(['accounts', 'contacts']));
        $coordinator = $this->coordinator($orchestrator);

        $first = $coordinator->beginOrResumeAll(['contacts', 'accounts', 'contacts'], 9);
        $second = $coordinator->beginOrResumeAll(['contacts', 'accounts'], 9);

        $this->assertSame('created', $first->outcome);
        $this->assertSame(['accounts', 'contacts'], $first->modulesToDispatch);
        $this->assertSame('already_running', $second->outcome);
        $this->assertSame($first->batch->id, $second->batch->id);
        $this->assertSame([], $second->modulesToDispatch);
        $this->assertSame(1, ZohoSyncBatch::query()->count());
    }

    public function test_any_active_manual_delta_batch_suppresses_a_new_batch_or_paused_resume(): void
    {
        $active = $this->batch(['deals'], ['status' => 'running', 'requested_at' => now()->subMinute()]);
        $paused = $this->batch(['accounts', 'contacts'], ['status' => 'paused']);
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->never();

        $decision = $this->coordinator($orchestrator)->beginOrResumeAll(['contacts', 'accounts'], 12);

        $this->assertSame('already_running', $decision->outcome);
        $this->assertSame($active->id, $decision->batch->id);
        $this->assertSame([], $decision->modulesToDispatch);
        $this->assertFalse($decision->needsFinalization);
        $this->assertSame('paused', $paused->fresh()->status);
    }

    public function test_paused_batch_resumes_same_identity_and_only_incomplete_modules(): void
    {
        $batch = $this->batch(['accounts', 'contacts'], ['status' => 'paused', 'paused_at' => now(), 'pause_reason' => 'manual stop']);
        $requestedAt = $batch->requested_at->toIso8601String();
        ZohoSyncLog::query()->create($this->logAttributes($batch, 'accounts', '', 'success'));
        ZohoSyncLog::query()->create($this->logAttributes($batch, 'contacts', '', 'error'));
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->never();

        $decision = $this->coordinator($orchestrator)->beginOrResumeAll(['accounts', 'contacts'], 12);

        $batch->refresh();
        $this->assertSame('resumed', $decision->outcome);
        $this->assertSame($batch->id, $decision->batch->id);
        $this->assertSame(['contacts'], $decision->modulesToDispatch);
        $this->assertSame('running', $batch->status);
        $this->assertSame($requestedAt, $batch->requested_at->toIso8601String());
        $this->assertSame(1, (int) $batch->resume_count);
        $this->assertSame('manual stop', $batch->pause_reason);
        $this->assertNotNull($batch->resumed_at);
    }

    public function test_resume_stages_a_durable_outbox_only_for_incomplete_owned_checkpoints(): void
    {
        config()->set('zoho-v2.retry.retry_window_hours', 4);
        config()->set('zoho-v2.module.recovery_stale_seconds', 1);
        config()->set('zoho-v2.module.retrying_recovery_stale_seconds', 1);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());

        $batch = $this->batch(['accounts', 'contacts'], [
            'status' => 'paused',
            'paused_at' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        ZohoSyncLog::query()->create($this->logAttributes($batch, 'accounts', '', 'success'));
        $completed = $this->checkpoint($batch, 'accounts', '', [
            'status' => 'completed',
            'generation' => 7,
            'heartbeat_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
            'delivery_retry_deadline_at' => now()->subHour(),
            'cursor_zoho_id' => 'account-cursor',
            'counters' => ['seen' => 17],
        ]);
        $incomplete = $this->checkpoint($batch, 'contacts', '', [
            'status' => 'paused',
            'generation' => 5,
            'lease_owner' => 'stale-worker',
            'lease_expires_at' => now()->addHour(),
            'heartbeat_at' => now()->subDay(),
            'delivery_retry_deadline_at' => now()->subHour(),
            'cursor_modified_time' => now()->subDays(2),
            'cursor_zoho_id' => 'contact-cursor',
            'page_last_zoho_id' => 'contact-page-last',
            'counters' => ['seen' => 23, 'updated' => 4],
            'retry_count' => 3,
        ]);
        $completedBefore = $completed->only([
            'status', 'generation', 'heartbeat_at', 'completed_at', 'delivery_retry_deadline_at',
            'cursor_zoho_id', 'counters', 'correlation_id',
        ]);
        $resumeAt = now();

        $decision = $this->coordinator(Mockery::mock(ZohoSyncOrchestrator::class))
            ->beginOrResumeAll(['accounts', 'contacts'], 12);

        $this->assertSame('resumed', $decision->outcome);
        $this->assertSame(['contacts'], $decision->modulesToDispatch);
        $this->assertEquals($completedBefore, $completed->fresh()->only(array_keys($completedBefore)));
        $incomplete->refresh();
        $this->assertSame('queued', $incomplete->status);
        $this->assertNull($incomplete->completed_at);
        $this->assertNull($incomplete->lease_owner);
        $this->assertNull($incomplete->lease_expires_at);
        $this->assertTrue($incomplete->heartbeat_at->equalTo($resumeAt));
        $this->assertTrue($incomplete->delivery_retry_deadline_at->equalTo($resumeAt->copy()->addHours(4)));
        $this->assertSame(5, $incomplete->generation);
        $this->assertSame($batch->correlation_id, $incomplete->correlation_id);
        $this->assertSame('contact-cursor', $incomplete->cursor_zoho_id);
        $this->assertSame('contact-page-last', $incomplete->page_last_zoho_id);
        $this->assertSame(['seen' => 23, 'updated' => 4], $incomplete->counters);
        $this->assertSame(3, $incomplete->retry_count);

        // Simulate a crash after the coordinator transaction committed but
        // before the controller dispatched the returned module list.
        $this->travel(2)->seconds();
        $recovered = app(ZohoStandardRecoveryService::class)->recover();

        $this->assertSame(1, $recovered['module_jobs']);
        Queue::assertPushed(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $batch->id
            && $job->module === 'contacts'
            && $job->checkpointGeneration === 5
            && $job->retryDeadline === $incomplete->fresh()->delivery_retry_deadline_at?->toIso8601String());
    }

    public function test_resume_with_no_incomplete_modules_signals_that_batch_needs_finalization(): void
    {
        $batch = $this->batch(['accounts'], ['status' => 'paused']);
        ZohoSyncLog::query()->create($this->logAttributes($batch, 'accounts', '', 'partial'));
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->never();

        $decision = $this->coordinator($orchestrator)->beginOrResumeAll(['accounts'], 12);

        $this->assertSame('resumed', $decision->outcome);
        $this->assertSame([], $decision->modulesToDispatch);
        $this->assertTrue($decision->needsFinalization);
        $this->assertSame($batch->id, $decision->batch->id);
        $this->assertSame($batch->correlation_id, $decision->batch->correlation_id);
        $this->assertTrue($batch->requested_at->equalTo($decision->batch->requested_at));
    }

    public function test_pause_fences_incomplete_checkpoints_and_preserves_terminal_evidence(): void
    {
        $batch = $this->batch(['accounts', 'contacts'], ['status' => 'running']);
        $running = $this->checkpoint($batch, 'accounts', '', ['status' => 'running', 'generation' => 4, 'lease_owner' => 'worker-a', 'lease_expires_at' => now()->addMinutes(5)]);
        $completed = $this->checkpoint($batch, 'contacts', '', ['status' => 'completed', 'generation' => 7, 'lease_owner' => null, 'completed_at' => now()]);

        $paused = $this->coordinator(Mockery::mock(ZohoSyncOrchestrator::class))->pauseAll($batch->id, ['accounts', 'contacts'], "  requested\nstop  ");

        $this->assertSame('paused', $paused->status);
        $this->assertSame('requested stop', $paused->pause_reason);
        $this->assertNotNull($paused->paused_at);
        $running->refresh();
        $completed->refresh();
        $this->assertSame('paused', $running->status);
        $this->assertSame(5, $running->generation);
        $this->assertNull($running->lease_owner);
        $this->assertNull($running->lease_expires_at);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(8, $completed->generation);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_pause_rejects_incompatible_batches_and_finds_only_paused_owner_modules(): void
    {
        $coordinator = $this->coordinator(Mockery::mock(ZohoSyncOrchestrator::class));
        $paused = $this->batch(['accounts', 'contacts'], ['status' => 'paused']);
        $wrongTrigger = $this->batch(['accounts', 'contacts'], ['trigger' => 'schedule', 'status' => 'running']);
        $terminal = $this->batch(['accounts', 'contacts'], ['status' => 'success', 'completed_at' => now()]);

        $this->assertSame($paused->id, $coordinator->pausedBatchOwningModule('contacts')?->id);
        $this->assertNull($coordinator->pausedBatchOwningModule('deals'));
        foreach ([[$paused, ['accounts', 'contacts']], [$wrongTrigger, ['accounts', 'contacts']], [$terminal, ['accounts', 'contacts']], [$this->batch(['accounts']), ['accounts', 'contacts']]] as [$batch, $modules]) {
            try {
                $coordinator->pauseAll($batch->id, $modules, null);
                $this->fail('Expected pause eligibility rejection.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    private function coordinator(ZohoSyncOrchestrator $orchestrator): ZohoManualSyncCoordinator
    {
        return new ZohoManualSyncCoordinator($orchestrator, new ZohoModuleRegistry);
    }

    /** @param list<string> $modules @param array<string,mixed> $overrides */
    private function batch(array $modules, array $overrides = []): ZohoSyncBatch
    {
        return ZohoSyncBatch::query()->create(array_replace([
            'correlation_id' => 'manual-'.str()->uuid(), 'mode' => 'delta', 'trigger' => 'manual',
            'status' => 'queued', 'modules' => $modules, 'requested_at' => now(),
        ], $overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function checkpoint(ZohoSyncBatch $batch, string $module, string $submodule, array $overrides = []): ZohoSyncCheckpoint
    {
        return ZohoSyncCheckpoint::query()->create(array_replace([
            'module' => 'v2:'.$module, 'submodule' => $submodule, 'sync_mode' => 'delta',
            'sync_batch_id' => $batch->id, 'correlation_id' => $batch->correlation_id,
            'status' => 'queued', 'generation' => 0,
        ], $overrides));
    }

    /** @return array<string,mixed> */
    private function logAttributes(ZohoSyncBatch $batch, string $module, string $submodule, string $status): array
    {
        return [
            'module' => $module, 'submodule' => $submodule, 'mode' => 'delta', 'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id, 'status' => $status, 'synced_at' => now(),
        ];
    }
}
