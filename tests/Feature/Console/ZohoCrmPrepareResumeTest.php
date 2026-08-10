<?php

namespace Tests\Feature\Console;

use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Models\Zoho\ZohoActivity;
use App\Models\Zoho\ZohoContact;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoStandardSyncRun;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoStandardWorklist;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZohoCrmPrepareResumeTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVE_MODULES = [
        'leads', 'accounts', 'contacts', 'deals', 'quotes', 'products', 'tasks',
        'events', 'calls', 'notes', 'deal_history', 'actions_commercials',
        'transport_international',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prepare_resume_command_is_registered(): void
    {
        $this->assertArrayHasKey('zoho:crm:prepare-resume', Artisan::all());
    }

    public function test_quote_slice_dry_run_is_write_free_and_reports_local_and_unresolved_counts(): void
    {
        $fixture = $this->quoteInterruptedBatch();
        $before = $this->snapshotTables();
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $exit = $this->artisan('zoho:crm:prepare-resume', ['batch' => $fixture['batch']->id])
            ->expectsOutputToContain('Completed modules (12)')
            ->expectsOutputToContain('Incomplete modules (1): quotes')
            ->expectsOutputToContain('quotes: mirror seeds 1, unresolved record failures 1, resulting completed 1, queued 1')
            ->expectsOutputToContain('Resulting work items: 1 completed, 1 queued')
            ->expectsOutputToContain('Dry run only')
            ->assertSuccessful()
            ->execute();

        $this->assertSame(0, $exit);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->snapshotTables());
    }

    public function test_quote_slice_apply_reopens_same_batch_preserves_evidence_and_is_idempotent(): void
    {
        $fixture = $this->quoteInterruptedBatch();
        $batch = $fixture['batch'];
        $requestedAt = $batch->requested_at->copy();
        $correlationId = $batch->correlation_id;
        $resumeCount = $batch->resume_count;
        $definition = app(ZohoModuleRegistry::class)->get('quotes');
        $existingRun = app(ZohoStandardWorklist::class)->getOrCreateRun(
            $batch,
            'quotes',
            '',
            [
                'correlation_id' => $batch->correlation_id,
                'mode' => 'delta',
                'query_fingerprint' => $definition->queryFingerprint('delta'),
                'query_params' => $definition->enumerationQuery(),
                'watermark_at' => $batch->requested_at,
                'since_at' => $fixture['checkpoints']['quotes']->cursor_at->copy()->subMinutes(15),
                'status' => 'completed',
                'counters' => ['seen' => 999],
                'page_token' => 'stale-run-token',
                'page_token_expires_at' => Carbon::now()->addHour(),
                'enumerated_at' => Carbon::now()->subHour(),
                'completed_at' => Carbon::now()->subMinutes(30),
            ],
        );
        app(ZohoStandardWorklist::class)->stage($existingRun, ['quote-missing']);
        $existingRun->workItems()->where('zoho_id', 'quote-missing')->update([
            'status' => 'completed',
            'outcome' => 'persisted',
            'processed_at' => Carbon::now()->subMinutes(30),
        ]);
        $evidenceBefore = $this->snapshotTables(['zoho_sync_logs', 'zoho_sync_failures', 'zoho_quotes']);
        $completedCheckpointsBefore = collect($fixture['checkpoints'])
            ->except('quotes')
            ->mapWithKeys(fn (ZohoSyncCheckpoint $checkpoint, string $module): array => [
                $module => $this->row('zoho_sync_checkpoints', $checkpoint->id),
            ])->all();
        $quoteCheckpointBefore = $this->row('zoho_sync_checkpoints', $fixture['checkpoints']['quotes']->id);

        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id, '--apply' => true])
            ->expectsOutputToContain("Batch {$batch->id} prepared and left paused")
            ->assertSuccessful()
            ->execute();

        $prepared = $batch->fresh();
        $this->assertSame($batch->id, $prepared->id);
        $this->assertSame($correlationId, $prepared->correlation_id);
        $this->assertTrue($prepared->requested_at->equalTo($requestedAt));
        $this->assertSame(self::ACTIVE_MODULES, $prepared->modules);
        $this->assertSame('paused', $prepared->status);
        $this->assertNull($prepared->completed_at);
        $this->assertNotNull($prepared->paused_at);
        $this->assertStringContainsString('durable resume', (string) $prepared->pause_reason);
        $this->assertSame($resumeCount, $prepared->resume_count);
        $this->assertNull($prepared->error_summary);
        $this->assertSame('zoho:crm:prepare-resume', $prepared->resume_metadata['prepared_by']);
        $this->assertSame('error', $prepared->resume_metadata['prior']['status']);
        $this->assertSame('Interrupted manually after terminalization.', $prepared->resume_metadata['prior']['error_summary']);
        $this->assertTrue(Carbon::parse($prepared->resume_metadata['prior']['completed_at'])->equalTo('2026-08-09 12:30:00'));

        foreach ($completedCheckpointsBefore as $module => $before) {
            $this->assertSame($before, $this->row('zoho_sync_checkpoints', $fixture['checkpoints'][$module]->id), "Completed checkpoint {$module} changed.");
        }

        $quoteCheckpoint = $fixture['checkpoints']['quotes']->fresh();
        $this->assertSame('paused', $quoteCheckpoint->status);
        $this->assertNull($quoteCheckpoint->lease_owner);
        $this->assertNull($quoteCheckpoint->lease_expires_at);
        $this->assertSame((int) $quoteCheckpointBefore['generation'] + 1, $quoteCheckpoint->generation);
        foreach ([
            'cursor_modified_time', 'cursor_zoho_id', 'page_last_zoho_id', 'cursor_at',
            'cursor_page_token', 'page_token_expires_at', 'retry_count', 'completed_at',
            'counters', 'correlation_id', 'sync_batch_id', 'delivery_retry_deadline_at',
        ] as $field) {
            $this->assertSame($quoteCheckpointBefore[$field], $this->row('zoho_sync_checkpoints', $quoteCheckpoint->id)[$field], "Quote checkpoint {$field} changed.");
        }

        $run = ZohoStandardSyncRun::query()->where('sync_batch_id', $batch->id)->where('module', 'quotes')->sole();
        $this->assertSame($existingRun->id, $run->id);
        $expectedQuery = $definition->enumerationQuery();
        ksort($expectedQuery);
        $this->assertSame($correlationId, $run->correlation_id);
        $this->assertSame('delta', $run->mode);
        $this->assertSame('enumerating', $run->status);
        $this->assertSame($definition->queryFingerprint('delta'), $run->query_fingerprint);
        $this->assertEquals($expectedQuery, $run->query_params);
        $this->assertTrue($run->watermark_at->equalTo($requestedAt));
        $this->assertTrue($run->since_at->equalTo($fixture['checkpoints']['quotes']->cursor_at->copy()->subMinutes(15)));
        $this->assertSame($fixture['checkpoints']['quotes']->counters, $run->counters);
        $this->assertNull($run->page_token);
        $this->assertNull($run->page_token_expires_at);
        $this->assertNull($run->enumerated_at);
        $this->assertNull($run->completed_at);
        $this->assertSame(
            ['quote-missing' => 'queued', 'quote-owned' => 'completed'],
            $run->workItems()->orderBy('zoho_id')->pluck('status', 'zoho_id')->all(),
        );
        $this->assertSame('seeded', $run->workItems()->where('zoho_id', 'quote-owned')->sole()->outcome);
        $this->assertNull($run->workItems()->where('zoho_id', 'quote-missing')->sole()->outcome);
        $this->assertSame($evidenceBefore, $this->snapshotTables(['zoho_sync_logs', 'zoho_sync_failures', 'zoho_quotes']));
        $this->assertSame(0, DB::table('jobs')->count());

        $firstApply = $this->snapshotTables();
        Carbon::setTestNow(Carbon::now()->addHour());
        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id, '--apply' => true])
            ->expectsOutputToContain('already prepared')
            ->assertSuccessful()
            ->execute();
        $this->assertSame($firstApply, $this->snapshotTables());
    }

    public function test_activity_slice_seeds_tasks_and_notes_by_exact_activity_type_and_submodule(): void
    {
        $fixture = $this->activityInterruptedBatch();
        $batch = $fixture['batch'];
        $eventsBefore = $this->row('zoho_sync_checkpoints', $fixture['checkpoints']['events']->id);
        $callsBefore = $this->row('zoho_sync_checkpoints', $fixture['checkpoints']['calls']->id);
        $evidenceBefore = $this->snapshotTables(['zoho_sync_logs', 'zoho_sync_failures', 'zoho_activities']);

        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id, '--apply' => true])
            ->assertSuccessful()
            ->execute();

        $this->assertSame(
            ['task-missing' => 'queued', 'task-owned' => 'completed'],
            $this->workItemStates($batch->id, 'tasks', 'Tasks'),
        );
        $this->assertSame(
            ['note-owned' => 'completed'],
            $this->workItemStates($batch->id, 'notes', 'Notes'),
        );
        $this->assertFalse(DB::table('zoho_standard_sync_work_items')->whereIn('zoho_id', [
            'event-owned', 'call-owned', 'task-other-batch', 'task-wrong-submodule',
        ])->exists());
        $this->assertSame($eventsBefore, $this->row('zoho_sync_checkpoints', $fixture['checkpoints']['events']->id));
        $this->assertSame($callsBefore, $this->row('zoho_sync_checkpoints', $fixture['checkpoints']['calls']->id));
        $this->assertSame($evidenceBefore, $this->snapshotTables(['zoho_sync_logs', 'zoho_sync_failures', 'zoho_activities']));
    }

    public function test_contacts_slice_reports_and_applies_the_four_incomplete_module_aggregate(): void
    {
        $fixture = $this->fullInterruptedBatch();
        $batch = $fixture['batch'];
        $beforeDryRun = $this->snapshotTables([
            'zoho_sync_batches', 'zoho_sync_checkpoints', 'zoho_sync_logs', 'zoho_sync_failures',
            'zoho_standard_sync_runs', 'zoho_standard_sync_work_items', 'zoho_contacts',
            'zoho_quotes', 'zoho_activities', 'jobs',
        ]);

        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id])
            ->expectsOutputToContain('Completed modules (9): leads, accounts, deals, products, events, calls, deal_history, actions_commercials, transport_international')
            ->expectsOutputToContain('Incomplete modules (4): contacts, quotes, tasks, notes')
            ->expectsOutputToContain('contacts: mirror seeds 1, unresolved record failures 0, resulting completed 1, queued 0')
            ->expectsOutputToContain('Resulting work items: 4 completed, 2 queued')
            ->assertSuccessful()
            ->execute();
        $this->assertSame($beforeDryRun, $this->snapshotTables(array_keys($beforeDryRun)));

        $contactEvidence = $this->snapshotTables(['zoho_contacts']);
        $incompleteGenerations = collect(['contacts', 'quotes', 'tasks', 'notes'])->mapWithKeys(
            fn (string $module): array => [$module => $fixture['checkpoints'][$module]->generation],
        )->all();
        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id, '--apply' => true])
            ->assertSuccessful()
            ->execute();

        $this->assertSame(['contact-owned' => 'completed'], $this->workItemStates($batch->id, 'contacts', ''));
        $this->assertSame(4, ZohoStandardSyncRun::query()->count());
        $this->assertSame(4, DB::table('zoho_standard_sync_work_items')->where('status', 'completed')->count());
        $this->assertSame(2, DB::table('zoho_standard_sync_work_items')->where('status', 'queued')->count());
        $this->assertSame($contactEvidence, $this->snapshotTables(['zoho_contacts']));
        foreach ($incompleteGenerations as $module => $generation) {
            $checkpoint = $fixture['checkpoints'][$module]->fresh();
            $this->assertSame('paused', $checkpoint->status);
            $this->assertNull($checkpoint->lease_owner);
            $this->assertNull($checkpoint->lease_expires_at);
            $this->assertSame($generation + 1, $checkpoint->generation);
        }
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_validation_refuses_a_batch_without_the_exact_active_sync_tout_set(): void
    {
        $fixture = $this->fullInterruptedBatch();
        $batch = $fixture['batch'];
        $batch->update(['modules' => array_slice(self::ACTIVE_MODULES, 0, -1)]);
        $before = $this->snapshotTables();

        $this->assertRejectedForDryRunAndApply($batch->id, 'exact active Sync Tout module set');
        $this->assertSame($before, $this->snapshotTables());
    }

    public function test_validation_refuses_healthy_and_unmarked_paused_batches(): void
    {
        $healthy = $this->fullInterruptedBatch();
        $healthy['batch']->update(['status' => 'success']);
        $healthyBefore = $this->snapshotTables();
        $this->assertRejectedForDryRunAndApply($healthy['batch']->id, 'not an explicitly recoverable interrupted batch');
        $this->assertSame($healthyBefore, $this->snapshotTables());

        $healthy['batch']->update(['status' => 'paused', 'completed_at' => null]);
        $pausedBefore = $this->snapshotTables();
        $this->assertRejectedForDryRunAndApply($healthy['batch']->id, 'was not prepared by this command');
        $this->assertSame($pausedBefore, $this->snapshotTables());
    }

    public function test_validation_refuses_associated_waiting_and_reserved_zoho_jobs(): void
    {
        $fixture = $this->fullInterruptedBatch();
        $batch = $fixture['batch'];
        RunZohoModuleSyncJob::dispatch($batch->id, 'contacts', 'delta', $batch->correlation_id);
        $this->assertSame(1, DB::table('jobs')->count());
        $waitingBefore = $this->snapshotTables();

        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id])
            ->expectsOutputToContain('associated queued or reserved Zoho job')
            ->assertFailed()
            ->execute();
        $this->assertSame($waitingBefore, $this->snapshotTables());

        DB::table('jobs')->update(['reserved_at' => Carbon::now()->timestamp]);
        $reservedBefore = $this->snapshotTables();
        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id, '--apply' => true])
            ->expectsOutputToContain('associated queued or reserved Zoho job')
            ->assertFailed()
            ->execute();
        $this->assertSame($reservedBefore, $this->snapshotTables());
    }

    public function test_validation_refuses_an_unexpired_checkpoint_lease_in_dry_run_and_apply(): void
    {
        $fixture = $this->fullInterruptedBatch();
        $batch = $fixture['batch'];
        $fixture['checkpoints']['contacts']->update([
            'lease_owner' => 'still-running',
            'lease_expires_at' => Carbon::now()->addMinutes(5),
        ]);
        $before = $this->snapshotTables();

        $this->assertRejectedForDryRunAndApply($batch->id, 'unexpired checkpoint lease');
        $this->assertSame($before, $this->snapshotTables());
    }

    public function test_apply_rolls_back_every_change_when_an_existing_run_is_incompatible(): void
    {
        $fixture = $this->fullInterruptedBatch();
        $batch = $fixture['batch'];
        $definition = app(ZohoModuleRegistry::class)->get('quotes');
        ZohoStandardSyncRun::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => 'quotes',
            'submodule' => '',
            'correlation_id' => $batch->correlation_id,
            'mode' => 'delta',
            'status' => 'enumerating',
            'query_fingerprint' => str_repeat('f', 64),
            'query_params' => $definition->enumerationQuery(),
            'watermark_at' => $batch->requested_at,
            'since_at' => $fixture['checkpoints']['quotes']->cursor_at->copy()->subMinutes(15),
            'counters' => $fixture['checkpoints']['quotes']->counters,
        ]);
        $before = $this->snapshotTables();

        $this->artisan('zoho:crm:prepare-resume', ['batch' => $batch->id, '--apply' => true])
            ->expectsOutputToContain('different query fingerprint')
            ->assertFailed()
            ->execute();

        $this->assertSame($before, $this->snapshotTables());
    }

    /** @return array{batch: ZohoSyncBatch, checkpoints: array<string, ZohoSyncCheckpoint>} */
    private function quoteInterruptedBatch(): array
    {
        Carbon::setTestNow('2026-08-09 14:00:00');
        $batch = ZohoSyncBatch::query()->create([
            'correlation_id' => 'batch-5-interrupted-correlation',
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'error',
            'modules' => self::ACTIVE_MODULES,
            'counters' => ['seen' => 99],
            'error_summary' => 'Interrupted manually after terminalization.',
            'requested_at' => '2026-08-09 10:00:00',
            'started_at' => '2026-08-09 10:01:00',
            'completed_at' => '2026-08-09 12:30:00',
            'resume_count' => 2,
        ]);

        $registry = app(ZohoModuleRegistry::class);
        $checkpoints = [];
        foreach (self::ACTIVE_MODULES as $index => $module) {
            $definition = $registry->get($module);
            $complete = $module !== 'quotes';
            $checkpoints[$module] = ZohoSyncCheckpoint::query()->create([
                'module' => 'v2:'.$module,
                'submodule' => $definition->submodule ?? '',
                'sync_mode' => 'delta',
                'page_query_fingerprint' => $definition->queryFingerprint('delta'),
                'cursor_modified_time' => Carbon::parse('2026-08-09 09:00:00')->addMinutes($index),
                'cursor_zoho_id' => 'cursor-'.$module,
                'page_last_zoho_id' => 'page-'.$module,
                'cursor_at' => Carbon::parse('2026-08-09 09:30:00')->addMinutes($index),
                'cursor_page_token' => 'legacy-token-'.$module,
                'page_token_expires_at' => Carbon::parse('2026-08-10 09:30:00'),
                'status' => $complete ? ($module === 'deals' ? 'partial' : 'completed') : 'failed',
                'lease_owner' => $complete ? null : 'expired-quotes',
                'lease_expires_at' => $complete ? null : Carbon::now()->subMinute(),
                'heartbeat_at' => Carbon::parse('2026-08-09 12:00:00'),
                'retry_count' => $index + 1,
                'completed_at' => Carbon::parse('2026-08-09 12:20:00')->addSeconds($index),
                'counters' => ['seen' => $index + 10, 'created' => $index + 1, 'api_requests' => $index + 20],
                'correlation_id' => $batch->correlation_id,
                'sync_batch_id' => $batch->id,
                'generation' => 7 + $index,
                'delivery_retry_deadline_at' => Carbon::parse('2026-08-09 20:00:00'),
            ]);

            $this->log($batch, $module, $complete ? ($module === 'deals' ? 'partial' : 'success') : 'error', Carbon::parse('2026-08-09 11:00:00')->addMinutes($index));
        }

        ZohoQuote::query()->create([
            'zoho_id' => 'quote-owned',
            'raw_payload' => ['id' => 'quote-owned', 'secret' => 'QUOTE-RAW'],
            'payload_hash' => hash('sha256', 'quote-owned-payload'),
            'field_schema_hash' => hash('sha256', 'quote-owned-schema'),
            'sync_batch_id' => $batch->id,
        ]);
        ZohoQuote::query()->create([
            'zoho_id' => 'quote-other-batch',
            'raw_payload' => ['id' => 'quote-other-batch', 'secret' => 'OTHER-RAW'],
            'payload_hash' => hash('sha256', 'quote-other-payload'),
            'field_schema_hash' => hash('sha256', 'quote-other-schema'),
            'sync_batch_id' => $batch->id + 100,
        ]);
        $this->failure($batch, 'quote-missing', 'record', null, '2026-08-10 08:00:00');
        $this->failure($batch, 'quote-resolved', 'record', '2026-08-09 13:00:00', null);
        $this->failure($batch, 'quote-wrong-kind', 'transport', null, '2026-08-10 08:10:00');

        return ['batch' => $batch->fresh(), 'checkpoints' => $checkpoints];
    }

    /** @return array{batch: ZohoSyncBatch, checkpoints: array<string, ZohoSyncCheckpoint>} */
    private function activityInterruptedBatch(): array
    {
        $fixture = $this->quoteInterruptedBatch();
        $batch = $fixture['batch'];
        foreach (['tasks', 'notes'] as $index => $module) {
            $fixture['checkpoints'][$module]->update([
                'status' => 'failed',
                'lease_owner' => 'expired-'.$module,
                'lease_expires_at' => Carbon::now()->subMinute(),
            ]);
            $this->log($batch, $module, 'error', Carbon::parse('2026-08-09 13:00:00')->addMinute($index));
        }

        ZohoActivity::query()->create($this->activity('task-owned', 'task', $batch->id, 'TASK-RAW'));
        ZohoActivity::query()->create($this->activity('note-owned', 'note', $batch->id, 'NOTE-RAW'));
        ZohoActivity::query()->create($this->activity('event-owned', 'meeting', $batch->id, 'EVENT-RAW'));
        ZohoActivity::query()->create($this->activity('call-owned', 'call', $batch->id, 'CALL-RAW'));
        ZohoActivity::query()->create($this->activity('task-other-batch', 'task', $batch->id + 100, 'OTHER-TASK'));
        $this->moduleFailure($batch, 'tasks', 'Tasks', 'task-missing', 'record', null, '2026-08-10 08:20:00');
        $this->moduleFailure($batch, 'tasks', 'Events', 'task-wrong-submodule', 'record', null, '2026-08-10 08:25:00');

        return $fixture;
    }

    /** @return array{batch: ZohoSyncBatch, checkpoints: array<string, ZohoSyncCheckpoint>} */
    private function fullInterruptedBatch(): array
    {
        $fixture = $this->activityInterruptedBatch();
        $batch = $fixture['batch'];
        $fixture['checkpoints']['contacts']->update([
            'status' => 'failed',
            'lease_owner' => 'expired-contacts',
            'lease_expires_at' => Carbon::now()->subMinute(),
        ]);
        $this->log($batch, 'contacts', 'error', '2026-08-09 13:02:00');
        ZohoContact::query()->create([
            'zoho_id' => 'contact-owned',
            'raw_payload' => ['id' => 'contact-owned', 'secret' => 'CONTACT-RAW'],
            'payload_hash' => hash('sha256', 'contact-owned-payload'),
            'field_schema_hash' => hash('sha256', 'contact-owned-schema'),
            'sync_batch_id' => $batch->id,
        ]);
        ZohoContact::query()->create([
            'zoho_id' => 'contact-other-batch',
            'raw_payload' => ['id' => 'contact-other-batch', 'secret' => 'OTHER-CONTACT'],
            'payload_hash' => hash('sha256', 'contact-other-payload'),
            'field_schema_hash' => hash('sha256', 'contact-other-schema'),
            'sync_batch_id' => $batch->id + 100,
        ]);

        return $fixture;
    }

    /** @return array<string, mixed> */
    private function activity(string $zohoId, string $type, int $batchId, string $secret): array
    {
        return [
            'activity_type' => $type,
            'zoho_id' => $zohoId,
            'raw_payload' => ['id' => $zohoId, 'secret' => $secret],
            'payload_hash' => hash('sha256', $zohoId.'-payload'),
            'field_schema_hash' => hash('sha256', $zohoId.'-schema'),
            'sync_batch_id' => $batchId,
        ];
    }

    private function log(ZohoSyncBatch $batch, string $module, string $status, mixed $syncedAt): void
    {
        $definition = app(ZohoModuleRegistry::class)->get($module);
        ZohoSyncLog::query()->create([
            'module' => $module,
            'submodule' => $definition->submodule ?? '',
            'mode' => 'delta',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'synced_at' => $syncedAt,
            'records_synced' => 3,
            'records_seen' => 4,
            'records_created' => 1,
            'records_updated' => 1,
            'records_unchanged' => 1,
            'records_quarantined' => $status === 'error' ? 1 : 0,
            'status' => $status,
            'error' => $status === 'error' ? 'Preserved module error.' : null,
            'api_requests' => 2,
            'telemetry' => ['evidence' => $module],
        ]);
    }

    private function failure(ZohoSyncBatch $batch, string $zohoId, string $kind, mixed $resolvedAt, mixed $retryAfter): void
    {
        $this->moduleFailure($batch, 'quotes', '', $zohoId, $kind, $resolvedAt, $retryAfter);
    }

    private function moduleFailure(ZohoSyncBatch $batch, string $module, string $submodule, string $zohoId, string $kind, mixed $resolvedAt, mixed $retryAfter): void
    {
        ZohoSyncFailure::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => $module,
            'submodule' => $submodule,
            'zoho_id' => $zohoId,
            'failure_kind' => $kind,
            'failure_key' => hash('sha256', implode('|', [$module, $submodule, $zohoId, $kind])),
            'correlation_id' => $batch->correlation_id,
            'error_summary' => 'Preserved record failure.',
            'context' => ['secret' => 'FAILURE-EVIDENCE', 'zoho_id' => $zohoId],
            'attempts' => 3,
            'retry_after' => $retryAfter,
            'resolved_at' => $resolvedAt,
        ]);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function snapshotTables(?array $tables = null): array
    {
        $tables ??= [
            'zoho_sync_batches', 'zoho_sync_checkpoints', 'zoho_sync_logs', 'zoho_sync_failures',
            'zoho_standard_sync_runs', 'zoho_standard_sync_work_items', 'zoho_quotes', 'jobs',
        ];

        return collect($tables)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ])->all();
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->sole();
    }

    /** @return array<string, string> */
    private function workItemStates(int $batchId, string $module, string $submodule): array
    {
        $run = ZohoStandardSyncRun::query()
            ->where('sync_batch_id', $batchId)
            ->where('module', $module)
            ->where('submodule', $submodule)
            ->sole();

        return $run->workItems()->orderBy('zoho_id')->pluck('status', 'zoho_id')->all();
    }

    private function assertRejectedForDryRunAndApply(int $batchId, string $message): void
    {
        foreach ([false, true] as $apply) {
            $arguments = ['batch' => $batchId];
            if ($apply) {
                $arguments['--apply'] = true;
            }
            $this->artisan('zoho:crm:prepare-resume', $arguments)
                ->expectsOutputToContain($message)
                ->assertFailed()
                ->execute();
        }
    }
}
