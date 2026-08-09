<?php

namespace Tests\Unit\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoQuoteStatusHistory;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\Zoho\ZohoUser;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\TransportAttempt;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ZohoSyncOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private FakeZohoTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeZohoTransport;
        $this->app->instance(ZohoTransport::class, $this->transport);
    }

    public function test_it_rejects_an_unknown_sync_mode_before_creating_a_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'write');
    }

    public function test_activation_gated_users_never_calls_the_transport(): void
    {
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['users'], 'delta');

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'users', 'delta', 'worker-1');

        $this->assertSame(0, $result->counters['api_requests']);
        $this->assertNotEmpty($result->warnings);
    }

    public function test_first_delta_is_a_full_enumeration_then_later_delta_uses_the_overlap_header(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        config(['zoho-v2.overlap_minutes' => 17]);
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [],
            'info' => ['more_records' => false],
        ]));

        $first = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'delta');
        app(ZohoSyncOrchestrator::class)->runModule($first->id, 'leads', 'delta', 'worker-1');
        $first->update(['status' => 'success', 'completed_at' => now()]);

        $this->assertSame('get', $this->transport->calls[0]['method']);

        CarbonImmutable::setTestNow('2026-08-09 13:00:00');
        $this->transport->queue('conditional', '/Leads', $this->notModified());
        $second = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'delta');
        app(ZohoSyncOrchestrator::class)->runModule($second->id, 'leads', 'delta', 'worker-2');

        $call = $this->transport->calls[1];
        $this->assertSame('conditional', $call['method']);
        $this->assertSame('2026-08-09 11:43:00', $call['since']->format('Y-m-d H:i:s'));
    }

    public function test_final_cursor_uses_batch_start_not_continuation_time(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $definition = app(ZohoModuleRegistry::class)->get('leads');
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads', 'submodule' => '', 'sync_mode' => 'delta',
            'page_query_fingerprint' => $definition->queryFingerprint('delta'), 'status' => 'completed',
            'cursor_at' => CarbonImmutable::parse('2026-08-09 10:00:00'),
        ]);
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'delta');
        CarbonImmutable::setTestNow('2026-08-09 13:00:00');
        $this->transport->queue('conditional', '/Leads', $this->ok(['data' => [], 'info' => ['more_records' => false]]));

        app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'delta', 'cursor-worker');

        $this->assertSame('2026-08-09 12:00:00', ZohoSyncCheckpoint::query()->value('cursor_at')->format('Y-m-d H:i:s'));
    }

    public function test_reconcile_uses_overlap_delta_repairs_missing_ids_and_holds_the_module_lease_through_the_scan(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $definition = app(ZohoModuleRegistry::class)->get('leads');
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads',
            'submodule' => '',
            'sync_mode' => 'reconcile',
            'page_query_fingerprint' => $definition->queryFingerprint('reconcile'),
            'status' => 'completed',
            'cursor_at' => CarbonImmutable::parse('2026-08-09 10:00:00'),
        ]);
        ZohoLead::query()->create([
            'zoho_id' => 'lead-current',
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'lead-current'),
            'last_seen_at' => now()->subDay(),
        ]);
        $this->transport->queue('conditional', '/Leads', $this->notModified());
        $this->transport->queue('get', '/Leads/deleted', $this->ok([
            'data' => [],
            'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'lead-current'], ['id' => 'lead-repaired']],
            'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Leads/lead-repaired', $this->ok([
            'data' => [['id' => 'lead-repaired', 'Full_Name' => 'Repaired']],
        ]));
        $leaseObservedDuringReconciliation = false;
        $this->transport->observer = function (string $method, string $path) use (&$leaseObservedDuringReconciliation): void {
            if ($method !== 'get' || $path !== '/Leads/deleted') {
                return;
            }

            $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->firstOrFail();
            $leaseObservedDuringReconciliation = $checkpoint->lease_owner !== null
                && $checkpoint->lease_expires_at?->isFuture() === true;
        };

        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'reconcile');
        $result = app(ZohoSyncOrchestrator::class)->runModule(
            $batch->id,
            'leads',
            'reconcile',
            'reconcile-worker',
        );

        $this->assertTrue($leaseObservedDuringReconciliation);
        $this->assertSame('conditional', $this->transport->calls[0]['method']);
        $this->assertSame(
            'healthy',
            $result->reconciliation['status'],
            json_encode($result->reconciliation, JSON_THROW_ON_ERROR),
        );
        $this->assertSame(1, $result->reconciliation['repaired_count']);
        $this->assertDatabaseHas('zoho_leads', ['zoho_id' => 'lead-repaired']);
        $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->firstOrFail();
        $this->assertNull($checkpoint->lease_owner);
        $log = ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->where('module', 'leads')->sole();
        $this->assertSame('healthy', data_get($log->telemetry, 'reconciliation.status'));
        $this->assertTrue(data_get($log->telemetry, 'reconciliation.complete'));
        $this->assertSame(200, data_get($log->telemetry, 'reconciliation.http_status'));
        $this->assertArrayHasKey('remote_count', data_get($log->telemetry, 'reconciliation'));
        $this->assertArrayHasKey('local_count', data_get($log->telemetry, 'reconciliation'));
        $this->assertArrayHasKey('missing_count', data_get($log->telemetry, 'reconciliation'));
        $this->assertArrayHasKey('extra_count', data_get($log->telemetry, 'reconciliation'));
        $this->assertArrayHasKey('pages', data_get($log->telemetry, 'reconciliation'));
        $this->assertArrayHasKey('tombstoned', data_get($log->telemetry, 'reconciliation'));
        $this->assertSame($result->counters['api_requests'], $checkpoint->fresh()->counters['api_requests']);
        $this->assertSame((int) data_get($result->reconciliation, 'deleted.tombstoned', 0), $log->records_deleted);
        $this->assertSame(
            $result->counters['api_requests'] + $result->reconciliation['api_requests'],
            $log->api_requests,
        );
        \App\Jobs\Zoho\ZohoModuleRunOutcome::recordReconciliation($batch->id, 'leads', $result->reconciliation);
        $this->assertSame($result->counters['api_requests'] + $result->reconciliation['api_requests'], $log->fresh()->api_requests);
        app(ZohoSyncOrchestrator::class)->finalizeBatch($batch->id);
        $this->assertSame('success', $batch->fresh()->status);
        $this->assertSame('completed', $checkpoint->status);
    }

    public function test_incomplete_reconciliation_is_retryable_and_never_persists_a_terminal_module_log(): void
    {
        $this->transport->queue('get', '/Accounts', $this->ok(['data' => [], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Accounts/deleted', $this->error(503, 'service_unavailable'));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['accounts'], 'reconcile');

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'accounts', 'reconcile', 'incomplete-reconcile');

        $this->assertTrue($result->retryableFailure);
        $this->assertNotEmpty($result->failures);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id, 'module' => 'accounts']);
        $this->assertSame('retrying', ZohoSyncCheckpoint::query()->where('module', 'v2:accounts')->value('status'));
    }

    public function test_quote_reconciliation_resumes_across_bounded_deliveries_and_aggregates_the_sweep(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        config()->set('zoho-v2.reconciliation.quote_chunk_size', 1);
        $definition = app(ZohoModuleRegistry::class)->get('quotes');
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:quotes',
            'submodule' => '',
            'sync_mode' => 'reconcile',
            'page_query_fingerprint' => $definition->queryFingerprint('reconcile'),
            'status' => 'completed',
            'cursor_at' => CarbonImmutable::parse('2026-08-09 10:00:00'),
        ]);
        foreach (['quote-resume-1', 'quote-resume-2'] as $id) {
            ZohoQuote::query()->create([
                'zoho_id' => $id,
                'subject' => $id,
                'raw_payload' => ['id' => $id, 'Subject' => $id],
                'payload_hash' => hash('sha256', json_encode(['id' => $id, 'Subject' => $id])),
            ]);
        }
        $this->queueQuoteReconciliationDelivery('quote-resume-1', includeDelta: true);

        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['quotes'], 'reconcile');
        $first = app(ZohoSyncOrchestrator::class)->runModule(
            $batch->id,
            'quotes',
            'reconcile',
            'quote-worker-1',
        );

        $this->assertTrue($first->continuationRequired);
        $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
        $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:quotes')->firstOrFail();
        $this->assertSame('quote-resume-1', $checkpoint->reconcile_cursor_zoho_id);
        $this->assertSame($batch->correlation_id, $checkpoint->reconcile_correlation_id);
        $this->assertNull($checkpoint->lease_owner);
        $this->assertSame('2026-08-09 10:00:00', $checkpoint->cursor_at->format('Y-m-d H:i:s'));

        $this->queueQuoteReconciliationDelivery('quote-resume-2', includeDelta: false);
        $second = app(ZohoSyncOrchestrator::class)->runModule(
            $batch->id,
            'quotes',
            'reconcile',
            'quote-worker-2',
        );

        $this->assertFalse($second->continuationRequired);
        $this->assertSame(2, $second->reconciliation['swept_count']);
        $this->assertSame(2, $second->reconciliation['hydration']['attempted']);
        $this->assertSame(5, $second->reconciliation['api_requests']);
        $this->assertSame(
            6,
            $second->counters['api_requests'] + $second->reconciliation['api_requests'],
        );
        $checkpoint->refresh();
        $this->assertNull($checkpoint->reconcile_cursor_zoho_id);
        $this->assertNull($checkpoint->reconcile_correlation_id);
        $this->assertSame('completed', $checkpoint->status);
        $this->assertDatabaseHas('zoho_sync_logs', [
            'sync_batch_id' => $batch->id,
            'module' => 'quotes',
            'status' => 'success',
        ]);
        $this->assertCount(1, array_filter(
            $this->transport->calls,
            static fn (array $call): bool => $call['method'] === 'conditional' && $call['path'] === '/Quotes',
        ));
        $this->assertCount(1, array_filter(
            $this->transport->calls,
            static fn (array $call): bool => $call['method'] === 'get' && $call['path'] === '/Quotes/deleted',
        ));
    }

    public function test_leads_keep_the_verified_converted_query_on_every_page_and_specific_hydration(): void
    {
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'converted-lead']],
            'info' => ['more_records' => true, 'next_page_token' => 'opaque-page-2'],
        ]));
        $this->transport->queue('get', '/Leads/converted-lead', $this->ok([
            'data' => [['id' => 'converted-lead', 'Converted__s' => true]],
        ]));
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [],
            'info' => ['more_records' => false],
        ]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $first = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker');
        $this->assertTrue($first->continuationRequired);
        app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker-next-page');

        $this->assertSame([
            'fields' => 'id,Converted__s',
            'per_page' => 200,
            'converted' => 'both',
        ], $this->transport->calls[0]['query']);
        $this->assertSame(['converted' => 'both'], $this->transport->calls[1]['query']);
        $this->assertSame([
            'fields' => 'id,Converted__s',
            'per_page' => 200,
            'converted' => 'both',
            'page_token' => 'opaque-page-2',
        ], $this->transport->calls[2]['query']);
        $this->assertTrue(ZohoLead::query()->where('zoho_id', 'converted-lead')->value('is_converted'));
    }

    public function test_successful_record_is_idempotent_and_mirrors_owner_without_erasing_fuller_user_data(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $payload = ['id' => 'L1', 'Full_Name' => 'Prospect', 'Company' => 'TCL', 'Currency' => 'EUR', 'Owner' => ['id' => 'U1', 'name' => 'Owner Name']];
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'L1']],
            'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Leads/L1', $this->ok(['data' => [$payload]]));

        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');
        $first = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker-1');
        $batch->update(['status' => 'success', 'completed_at' => now()]);

        $this->assertSame(1, $first->counters['created']);
        $this->assertDatabaseHas('zoho_users', ['zoho_id' => 'U1', 'full_name' => 'Owner Name', 'email' => null]);

        ZohoUser::query()->where('zoho_id', 'U1')->update(['email' => 'owner@example.test', 'normalized_email' => 'owner@example.test']);
        \App\Models\Zoho\ZohoFieldManifest::query()->create([
            'module' => 'leads',
            'submodule' => '',
            'schema_hash' => str_repeat('b', 64),
            'fields' => [],
            'is_current' => true,
        ]);
        ZohoLead::query()->where('zoho_id', 'L1')->update(['full_name' => 'corrupted derived value', 'field_schema_hash' => null]);
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'L1']],
            'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Leads/L1', $this->ok(['data' => [$payload]]));
        $again = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');
        $second = app(ZohoSyncOrchestrator::class)->runModule($again->id, 'leads', 'backfill', 'worker-2');

        $this->assertSame(1, $second->counters['unchanged']);
        $this->assertSame(1, ZohoLead::query()->count());
        $this->assertSame('Prospect', ZohoLead::query()->where('zoho_id', 'L1')->value('full_name'));
        $this->assertSame(str_repeat('b', 64), ZohoLead::query()->where('zoho_id', 'L1')->value('field_schema_hash'));
        $this->assertSame('owner@example.test', ZohoUser::query()->where('zoho_id', 'U1')->value('email'));
    }

    public function test_record_failure_is_redacted_repeat_safe_and_marks_module_partial(): void
    {
        $this->transport->queue('get', '/Leads', $this->ok(['data' => [['id' => 'L-secret']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Leads/L-secret', $this->error(500, 'PII-owner@example.test'));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker-1');

        $failure = ZohoSyncFailure::query()->firstOrFail();
        $this->assertSame(1, $failure->attempts);
        $this->assertStringNotContainsString('owner@example.test', $failure->error_summary);
        $this->assertSame('partial', ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('status'));
        $this->assertNotEmpty($result->failures);
        $batch->update(['status' => 'partial', 'completed_at' => now()]);

        $this->transport->queue('get', '/Leads', $this->ok(['data' => [['id' => 'L-secret']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Leads/L-secret', $this->error(500, 'different-private-body'));
        $again = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');
        app(ZohoSyncOrchestrator::class)->runModule($again->id, 'leads', 'backfill', 'worker-2');

        $this->assertSame(1, ZohoSyncFailure::query()->count());
        $this->assertSame(2, ZohoSyncFailure::query()->value('attempts'));
        $again->update(['status' => 'partial', 'completed_at' => now()]);

        $this->transport->queue('get', '/Leads', $this->ok(['data' => [['id' => 'L-secret']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Leads/L-secret', $this->ok(['data' => [[
            'id' => 'L-secret',
            'Full_Name' => 'Recovered',
        ]]]));
        $recovered = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');
        app(ZohoSyncOrchestrator::class)->runModule($recovered->id, 'leads', 'backfill', 'worker-3');

        $this->assertNotNull(ZohoSyncFailure::query()->value('resolved_at'));
        $this->assertSame('success', ZohoSyncLog::query()->where('sync_batch_id', $recovered->id)->value('status'));
    }

    public function test_specific_record_identity_mismatch_is_quarantined_without_persisting_the_wrong_record(): void
    {
        $this->transport->queue('get', '/Leads', $this->ok(['data' => [['id' => 'expected-lead']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Leads/expected-lead', $this->ok(['data' => [[
            'id' => 'different-lead',
            'Full_Name' => 'Wrong record',
        ]]]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $result = app(ZohoSyncOrchestrator::class)->runModule(
            $batch->id,
            'leads',
            'backfill',
            'identity-worker',
        );

        $this->assertSame(1, $result->counters['quarantined']);
        $this->assertDatabaseMissing('zoho_leads', ['zoho_id' => 'different-lead']);
        $this->assertDatabaseHas('zoho_sync_failures', [
            'module' => 'leads',
            'zoho_id' => 'expected-lead',
            'failure_kind' => 'record',
            'resolved_at' => null,
        ]);
    }

    public function test_local_throttle_capacity_exhaustion_releases_the_page_without_quarantining_a_record(): void
    {
        $this->transport->queue('get', '/Leads', $this->ok(['data' => [['id' => 'L-capacity']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Leads/L-capacity', $this->error(0, 'throttle_unavailable'));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'delta');

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'delta', 'worker-capacity');

        $this->assertSame(0, $result->counters['quarantined']);
        $this->assertTrue($result->continuationRequired);
        $this->assertSame([], $result->failures);
        $this->assertDatabaseCount('zoho_sync_failures', 0);
        $this->assertNull(ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('status'));
        $this->assertSame('retrying', ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->value('status'));
        $this->assertNull(ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->value('cursor_at'));
    }

    public function test_api_request_telemetry_counts_every_transport_retry_attempt(): void
    {
        $listPayload = ['data' => [['id' => 'A1']], 'info' => ['more_records' => false]];
        $recordPayload = ['data' => [['id' => 'A1', 'Account_Name' => 'Account']]];
        $this->transport->queue('get', '/Accounts', new TransportResult(
            200, $listPayload['data'], $listPayload['info'], [], 'attempt-list', [
                new TransportAttempt(1, 503, null, 'server_error'),
                new TransportAttempt(2, 200, null),
            ], payload: $listPayload,
        ));
        $this->transport->queue('get', '/Accounts/A1', new TransportResult(
            200, $recordPayload['data'], [], [], 'attempt-record', [
                new TransportAttempt(1, 429, 1, 'throttled'),
                new TransportAttempt(2, 503, null, 'server_error'),
                new TransportAttempt(3, 200, null),
            ], payload: $recordPayload,
        ));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['accounts'], 'backfill');

        app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'accounts', 'backfill', 'attempt-worker');

        $this->assertSame(5, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('api_requests'));
    }

    public function test_live_lease_is_refused_and_stale_lease_is_reclaimed_using_seconds_config(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        config(['zoho-v2.module.lease_seconds' => 90]);
        ZohoSyncCheckpoint::create(['module' => 'v2:leads', 'submodule' => '', 'status' => 'running', 'lease_owner' => 'other', 'lease_expires_at' => now()->addSecond()]);
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $refused = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker-1');
        $this->assertNotEmpty($refused->warnings);
        $this->assertTrue($refused->leaseConflict);
        $this->assertCount(0, $this->transport->calls);

        CarbonImmutable::setTestNow('2026-08-09 12:00:02');
        $orchestrator = app(ZohoSyncOrchestrator::class);
        $method = new \ReflectionMethod($orchestrator, 'claimCheckpoint');
        $claimed = $method->invoke($orchestrator, app(ZohoModuleRegistry::class)->get('leads'), 'backfill', 'worker-1', 'corr');
        $this->assertSame('2026-08-09 12:01:32', $claimed->lease_expires_at->format('Y-m-d H:i:s'));
    }

    public function test_live_lease_conflict_returns_an_expiry_aware_delay_instead_of_burning_attempts(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        config()->set('zoho-v2.module.lease_seconds', 1500);
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads',
            'submodule' => '',
            'status' => 'running',
            'lease_owner' => 'other-generation',
            'lease_expires_at' => now()->addSeconds(1500),
        ]);
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $result = app(ZohoSyncOrchestrator::class)->runModule(
            $batch->id,
            'leads',
            'backfill',
            'waiting-delivery',
        );

        $this->assertTrue($result->leaseConflict);
        $this->assertSame(1501, $result->retryAfterSeconds);
    }

    public function test_page_progress_resumes_after_durable_id_without_duplicate_hydration(): void
    {
        $page = ['data' => [['id' => 'L1'], ['id' => 'L2']], 'info' => ['more_records' => false]];
        $this->transport->queue('get', '/Leads', $this->ok($page));
        $this->transport->queue('get', '/Leads/L1', $this->ok(['data' => [['id' => 'L1', 'Full_Name' => 'One']]]));
        $this->transport->queue('get', '/Leads/L2', $this->error(0, 'throttle_unavailable'));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $interrupted = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker-1');

        $this->assertTrue($interrupted->continuationRequired);
        $this->assertSame(1, $interrupted->counters['seen']);
        $this->assertSame(1, $interrupted->counters['created']);
        $this->assertSame('L1', ZohoSyncCheckpoint::query()->value('page_last_zoho_id'));
        $this->assertSame(1, ZohoLead::query()->count());

        $this->transport->queue('get', '/Leads', $this->ok($page));
        $this->transport->queue('get', '/Leads/L2', $this->ok(['data' => [['id' => 'L2', 'Full_Name' => 'Two']]]));
        $again = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker-2');

        $this->assertFalse($again->continuationRequired);
        $this->assertSame(2, $again->counters['seen']);
        $this->assertSame(2, $again->counters['created']);
        $this->assertSame(2, ZohoLead::query()->count());
        $this->assertSame(1, count(array_filter($this->transport->calls, fn (array $call): bool => $call['path'] === '/Leads/L1')));
        $this->assertDatabaseHas('zoho_sync_logs', [
            'sync_batch_id' => $batch->id,
            'records_seen' => 2,
            'records_created' => 2,
        ]);
    }

    public function test_missing_durable_page_marker_restarts_the_page_without_an_exhaustion_loop(): void
    {
        $definition = app(ZohoModuleRegistry::class)->get('leads');
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');
        ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:leads', 'submodule' => '', 'sync_mode' => 'backfill',
            'page_query_fingerprint' => $definition->queryFingerprint('backfill'), 'status' => 'idle',
            'sync_batch_id' => $batch->id, 'correlation_id' => $batch->correlation_id,
            'page_last_zoho_id' => 'removed-by-reorder',
        ]);
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'L2'], ['id' => 'L1']], 'info' => ['more_records' => false],
        ]));

        $restart = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'marker-worker-1');

        $this->assertTrue($restart->continuationRequired);
        $this->assertNotEmpty($restart->warnings);
        $this->assertNull(ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->value('page_last_zoho_id'));

        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'L2'], ['id' => 'L1']], 'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Leads/L2', $this->ok(['data' => [['id' => 'L2', 'Full_Name' => 'Two']]]));
        $this->transport->queue('get', '/Leads/L1', $this->ok(['data' => [['id' => 'L1', 'Full_Name' => 'One']]]));

        $completed = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'marker-worker-2');

        $this->assertFalse($completed->retryableFailure);
        $this->assertSame(2, ZohoLead::query()->count());
        $this->assertSame(2, count(array_filter($this->transport->calls, fn (array $call): bool => $call['path'] === '/Leads')));
    }

    public function test_expired_page_token_restarts_once_from_durable_overlap_cursor(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $definition = app(ZohoModuleRegistry::class)->get('leads');
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'delta');
        ZohoSyncCheckpoint::create([
            'module' => 'v2:leads',
            'submodule' => '',
            'sync_mode' => 'delta',
            'page_query_fingerprint' => $definition->queryFingerprint('delta'),
            'status' => 'idle',
            'cursor_at' => now()->subHour(),
            'cursor_page_token' => 'expired',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
        ]);
        $this->transport->queue('conditional', '/Leads', $this->error(400, 'expired_page_token'));
        $this->transport->queue('conditional', '/Leads', $this->notModified());

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'delta', 'worker-1');

        $this->assertTrue($result->continuationRequired);
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('expired', $this->transport->calls[0]['query']['page_token']);
        $this->assertNotEmpty($result->warnings);

        app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'delta', 'worker-2');

        $this->assertCount(2, $this->transport->calls);
        $this->assertArrayNotHasKey('page_token', $this->transport->calls[1]['query']);
    }

    public function test_page_tokens_resume_only_for_the_same_query_and_token_bound_mismatch_restarts_once(): void
    {
        $definition = app(ZohoModuleRegistry::class)->get('leads');
        ZohoSyncCheckpoint::create([
            'module' => 'v2:leads',
            'submodule' => '',
            'sync_mode' => 'delta',
            'page_query_fingerprint' => $definition->queryFingerprint('delta'),
            'status' => 'idle',
            'cursor_at' => now()->subHour(),
            'cursor_page_token' => 'delta-token',
        ]);
        $this->transport->queue('get', '/Leads', $this->ok(['data' => [], 'info' => ['more_records' => false]]));
        $backfill = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        app(ZohoSyncOrchestrator::class)->runModule($backfill->id, 'leads', 'backfill', 'backfill-worker');

        $this->assertArrayNotHasKey('page_token', $this->transport->calls[0]['query']);
        $this->assertSame($definition->queryFingerprint('backfill'), ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->value('page_query_fingerprint'));

        $delta = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'delta');
        ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->update([
            'sync_mode' => 'delta',
            'page_query_fingerprint' => $definition->queryFingerprint('delta'),
            'cursor_at' => now()->subHour(),
            'cursor_page_token' => 'bound-token',
            'sync_batch_id' => $delta->id,
            'correlation_id' => $delta->correlation_id,
        ]);
        $this->transport->queue('conditional', '/Leads', $this->error(400, 'TOKEN_BOUND_DATA_MISMATCH'));
        $this->transport->queue('conditional', '/Leads', $this->notModified());

        $restart = app(ZohoSyncOrchestrator::class)->runModule($delta->id, 'leads', 'delta', 'delta-worker');
        $this->assertTrue($restart->continuationRequired);
        app(ZohoSyncOrchestrator::class)->runModule($delta->id, 'leads', 'delta', 'delta-worker-retry');

        $conditional = collect($this->transport->calls)->where('method', 'conditional')->values();
        $this->assertSame('bound-token', $conditional[0]['query']['page_token']);
        $this->assertArrayNotHasKey('page_token', $conditional[1]['query']);
    }

    public function test_list_page_requires_boolean_more_records_and_accepts_1024_token(): void
    {
        $token = str_repeat('t', 1024);
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'wide-token-lead']],
            'info' => ['more_records' => true, 'next_page_token' => $token],
        ]));
        $this->transport->queue('get', '/Leads/wide-token-lead', $this->ok(['data' => [['id' => 'wide-token-lead']]]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'wide-token-worker');

        $this->assertTrue($result->continuationRequired);
        $this->assertSame($token, ZohoSyncCheckpoint::query()->value('cursor_page_token'));
    }

    public function test_quote_items_are_tombstoned_and_status_observations_are_replay_safe(): void
    {
        $first = [
            'id' => 'Q1', 'Subject' => 'Quote', 'Quote_Stage' => 'Draft', 'Created_Time' => '2026-08-01T08:00:00+00:00', 'Currency' => 'EUR',
            'Quoted_Items' => [
                ['id' => 'I1', 'product' => ['id' => 'P1', 'name' => 'Freight'], 'quantity' => 1, 'list_price' => 10],
                ['id' => 'I2', 'product' => ['id' => 'P2', 'name' => 'Fees'], 'quantity' => 1, 'list_price' => 2],
            ],
        ];
        $this->runQuote($first, 'worker-1');
        $this->runQuote($first, 'worker-2');

        $this->assertSame(1, ZohoQuoteStatusHistory::query()->count());
        $this->assertSame(2, ZohoQuoteItem::query()->whereNull('zoho_deleted_at')->count());

        $changed = $first;
        $changed['Quote_Stage'] = 'Accepted';
        $changed['Modified_Time'] = '2026-08-02T09:00:00+00:00';
        $changed['Quoted_Items'] = [$first['Quoted_Items'][0]];
        $this->runQuote($changed, 'worker-3');
        $this->runQuote($changed, 'worker-4');

        $this->assertSame(2, ZohoQuoteStatusHistory::query()->count());
        $this->assertDatabaseHas('zoho_quote_items', ['zoho_quote_id' => 'Q1', 'zoho_line_item_id' => 'I2', 'zoho_deletion_type' => 'missing_from_quote']);
        $this->assertSame('EUR', ZohoQuoteItem::query()->where('zoho_line_item_id', 'I1')->value('currency_code'));
    }

    public function test_v2_checkpoint_namespace_does_not_collide_with_legacy_module_name(): void
    {
        ZohoSyncCheckpoint::create(['module' => 'Accounts', 'submodule' => '', 'status' => 'idle']);
        $this->transport->queue('get', '/Accounts', $this->ok(['data' => [], 'info' => ['more_records' => false]]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['accounts'], 'backfill');

        app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'accounts', 'backfill', 'worker-1');

        $this->assertDatabaseHas('zoho_sync_checkpoints', ['module' => 'Accounts']);
        $this->assertDatabaseHas('zoho_sync_checkpoints', ['module' => 'v2:accounts']);
        $this->assertSame(2, ZohoSyncCheckpoint::query()->count());
    }

    public function test_quoted_items_are_skipped_without_api_calls_because_quotes_own_them(): void
    {
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['quoted_items'], 'backfill');

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'quoted_items', 'backfill', 'worker-1');

        $this->assertSame(0, $result->counters['api_requests']);
        $this->assertNotEmpty($result->warnings);
        $this->assertSame('success', ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->value('status'));
    }

    public function test_reclaimed_stale_worker_cannot_advance_or_clear_the_new_lease(): void
    {
        $checkpoint = ZohoSyncCheckpoint::create(['module' => 'v2:leads', 'submodule' => '', 'status' => 'running', 'lease_owner' => 'new-lease', 'lease_expires_at' => now()->addHour()]);
        $orchestrator = app(ZohoSyncOrchestrator::class);
        $save = new \ReflectionMethod($orchestrator, 'savePageCheckpoint');

        $saved = $save->invoke($orchestrator, $checkpoint, 'stale-lease', 'stale-page', CarbonImmutable::now(), ['seen' => 1]);

        $this->assertFalse($saved);
        $checkpoint->refresh();
        $this->assertSame('new-lease', $checkpoint->lease_owner);
        $this->assertNull($checkpoint->cursor_page_token);
    }

    public function test_record_failure_resolution_refuses_a_reclaimed_owner_without_resolving_the_quarantine(): void
    {
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'running',
            'sync_batch_id' => 91, 'generation' => 2, 'lease_owner' => 'successor',
            'lease_expires_at' => now()->addHour(),
        ]);
        $failure = ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'accounts||race-id|record'), 'module' => 'accounts', 'submodule' => '',
            'zoho_id' => 'race-id', 'failure_kind' => 'record', 'error_summary' => 'Record synchronization failed.',
            'attempts' => 1,
        ]);
        $method = new \ReflectionMethod(ZohoSyncOrchestrator::class, 'resolveRecordFailure');
        $method->setAccessible(true);

        try {
            $method->invoke(app(ZohoSyncOrchestrator::class), app(ZohoModuleRegistry::class)->get('accounts'), 'race-id', $checkpoint, 'ancestor', 91);
            $this->fail('A reclaimed worker must not resolve the successor quarantine.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->assertNull($failure->fresh()->resolved_at);
        }
    }

    public function test_reclaimed_generation_cannot_persist_a_stale_record(): void
    {
        $this->transport->queue('get', '/Leads', $this->ok([
            'data' => [['id' => 'stale-lead']],
            'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Leads/stale-lead', $this->ok([
            'data' => [['id' => 'stale-lead', 'Full_Name' => 'Must not persist']],
        ]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');
        $this->transport->observer = function (string $method, string $path): void {
            if ($method !== 'get' || $path !== '/Leads/stale-lead') {
                return;
            }

            ZohoSyncCheckpoint::query()->where('module', 'v2:leads')->update([
                'generation' => 2,
                'lease_owner' => 'new-owner',
                'lease_expires_at' => now()->addHour(),
            ]);
        };

        $result = app(ZohoSyncOrchestrator::class)->runModule(
            $batch->id,
            'leads',
            'backfill',
            'stale-owner',
        );

        $this->assertNotEmpty($result->failures);
        $this->assertDatabaseMissing('zoho_leads', ['zoho_id' => 'stale-lead']);
        $this->assertDatabaseMissing('zoho_sync_failures', ['zoho_id' => 'stale-lead']);
        $this->assertDatabaseHas('zoho_sync_checkpoints', [
            'module' => 'v2:leads',
            'generation' => 2,
            'lease_owner' => 'new-owner',
        ]);
    }

    public function test_non_array_specific_record_is_quarantined_instead_of_silently_counted_as_seen(): void
    {
        $this->transport->queue('get', '/Leads', $this->ok(['data' => [['id' => 'L1']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Leads/L1', $this->ok(['data' => ['not-a-list']]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');

        $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker');

        $this->assertSame(1, $result->counters['quarantined']);
        $this->assertDatabaseHas('zoho_sync_failures', ['module' => 'leads', 'zoho_id' => 'L1']);
    }

    public function test_malformed_list_success_and_missing_continuation_never_advance_the_checkpoint(): void
    {
        foreach ([
            $this->ok([]),
            $this->ok(['data' => [], 'info' => ['more_records' => true]]),
        ] as $index => $response) {
            $this->transport->queue('get', '/Accounts', $response);
            $batch = app(ZohoSyncOrchestrator::class)->createBatch(['accounts'], 'backfill');

            $result = app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'accounts', 'backfill', 'worker-'.$index);

            $this->assertTrue($result->retryableFailure);
            $this->assertDatabaseMissing('zoho_sync_logs', ['sync_batch_id' => $batch->id]);
            $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:accounts')->sole();
            $this->assertSame('retrying', $checkpoint->status);
            $this->assertNull($checkpoint->cursor_at);
            $this->assertNull($checkpoint->cursor_page_token);
            $batch->update(['status' => 'error', 'completed_at' => now()]);
        }
    }

    public function test_quote_omitting_items_does_not_tombstone_existing_lines_and_observed_status_cycles_are_distinct(): void
    {
        $first = ['id' => 'Q1', 'Suivie_d_Affaire' => 'A', 'Created_Time' => '2026-08-01T08:00:00+00:00', 'Quoted_Items' => [['id' => 'I1', 'product' => ['id' => 'P1'], 'quantity' => 1, 'list_price' => 10, 'total' => 10]]];
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $this->runQuote($first, 'one');
        CarbonImmutable::setTestNow('2026-08-09 13:00:00');
        $withoutItems = ['id' => 'Q1', 'Suivie_d_Affaire' => 'B', 'Created_Time' => '2026-08-01T08:00:00+00:00'];
        $this->runQuote($withoutItems, 'two');
        $this->assertSame('10.00', \App\Models\Zoho\ZohoQuote::query()->where('zoho_id', 'Q1')->value('line_items_total'));
        $this->assertTrue((bool) \App\Models\Zoho\ZohoQuote::query()->where('zoho_id', 'Q1')->value('line_items_total_complete'));
        CarbonImmutable::setTestNow('2026-08-09 14:00:00');
        $this->runQuote($first, 'three');

        $this->assertNull(ZohoQuoteItem::query()->where('zoho_line_item_id', 'I1')->value('zoho_deleted_at'));
        $this->assertSame(3, ZohoQuoteStatusHistory::query()->count());
    }

    public function test_quote_explicitly_returning_no_items_tombstones_existing_lines(): void
    {
        $first = [
            'id' => 'Q1',
            'Suivie_d_Affaire' => 'A',
            'Created_Time' => '2026-08-01T08:00:00+00:00',
            'Quoted_Items' => [['id' => 'I1', 'product' => ['id' => 'P1'], 'quantity' => 1, 'list_price' => 10]],
        ];
        $this->runQuote($first, 'one');

        $withoutLines = $first;
        $withoutLines['Quoted_Items'] = [];
        $this->runQuote($withoutLines, 'two');

        $this->assertDatabaseHas('zoho_quote_items', [
            'zoho_quote_id' => 'Q1',
            'zoho_line_item_id' => 'I1',
            'zoho_deletion_type' => 'missing_from_quote',
        ]);
    }

    public function test_current_manifest_hash_is_persisted_on_mapped_rows(): void
    {
        \App\Models\Zoho\ZohoFieldManifest::create(['module' => 'leads', 'submodule' => '', 'schema_hash' => str_repeat('a', 64), 'fields' => [], 'is_current' => true]);
        $this->transport->queue('get', '/Leads', $this->ok(['data' => [['id' => 'L1']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Leads/L1', $this->ok(['data' => [['id' => 'L1', 'Full_Name' => 'One']]]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['leads'], 'backfill');
        app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'leads', 'backfill', 'worker');

        $this->assertSame(str_repeat('a', 64), ZohoLead::query()->value('field_schema_hash'));
    }

    private function runQuote(array $payload, string $worker): void
    {
        $this->transport->queue('get', '/Quotes', $this->ok(['data' => [['id' => 'Q1']], 'info' => ['more_records' => false]]));
        $this->transport->queue('get', '/Quotes/Q1', $this->ok(['data' => [$payload]]));
        $batch = app(ZohoSyncOrchestrator::class)->createBatch(['quotes'], 'backfill');
        app(ZohoSyncOrchestrator::class)->runModule($batch->id, 'quotes', 'backfill', $worker);
        $batch->update(['status' => 'success', 'completed_at' => now()]);
    }

    private function queueQuoteReconciliationDelivery(string $hydratedId, bool $includeDelta): void
    {
        if ($includeDelta) {
            $this->transport->queue('conditional', '/Quotes', $this->notModified());
        }
        $this->transport->queue('get', '/Quotes/deleted', $this->ok([
            'data' => [],
            'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Quotes', $this->ok([
            'data' => [['id' => 'quote-resume-2'], ['id' => 'quote-resume-1']],
            'info' => ['more_records' => false],
        ]));
        $this->transport->queue('get', '/Quotes/'.$hydratedId, $this->ok(['data' => [[
            'id' => $hydratedId,
            'Subject' => $hydratedId,
        ]]]));
    }

    private function ok(array $payload): TransportResult
    {
        return new TransportResult(200, (array) ($payload['data'] ?? []), (array) ($payload['info'] ?? []), [], 'corr', [], payload: $payload);
    }

    private function notModified(): TransportResult
    {
        return new TransportResult(304, [], [], [], 'corr', []);
    }

    private function error(int $status, string $code): TransportResult
    {
        return new TransportResult($status, [], [], [], 'corr', [], errorCode: $code);
    }
}

class FakeZohoTransport implements ZohoTransport
{
    /** @var array<string,list<TransportResult>> */
    public array $responses = [];

    /** @var list<array<string,mixed>> */
    public array $calls = [];

    /** @var (callable(string,string,array<string,mixed>): void)|null */
    public $observer = null;

    public function queue(string $method, string $path, TransportResult $result): void
    {
        $this->responses[$method.' '.$path][] = $result;
    }

    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        $this->calls[] = compact('path', 'query') + ['method' => 'get'];
        if (is_callable($this->observer)) {
            ($this->observer)('get', $path, $query);
        }

        return array_shift($this->responses['get '.$path]) ?? throw new \LogicException('Unexpected GET '.$path);
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        $this->calls[] = compact('path', 'query', 'since') + ['method' => 'conditional'];
        if (is_callable($this->observer)) {
            ($this->observer)('conditional', $path, $query);
        }

        return array_shift($this->responses['conditional '.$path]) ?? throw new \LogicException('Unexpected conditional GET '.$path);
    }
}
