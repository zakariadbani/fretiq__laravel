<?php

namespace Tests\Feature\Backend;

use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoV2SyncControlModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkpoint_persists_v2_control_state_and_legacy_fields(): void
    {
        $checkpoint = ZohoSyncCheckpoint::create([
            'module' => 'Quotes',
            'submodule' => 'line_items',
            'sync_mode' => 'reconcile',
            'page_query_fingerprint' => str_repeat('a', 64),
            'cursor_modified_time' => '2026-08-09 10:00:00',
            'cursor_zoho_id' => '5309063000000100001',
            'cursor_at' => '2026-08-09 10:05:00',
            'reconcile_cursor_zoho_id' => '5309063000000100000',
            'reconcile_correlation_id' => 'reconcile-run-123',
            'reconcile_started_at' => '2026-08-09 09:55:00',
            'cursor_page_token' => 'legacy-page-token',
            'page_token_expires_at' => '2026-08-09 11:00:00',
            'status' => 'running',
            'lease_owner' => 'zoho-worker-1',
            'lease_expires_at' => '2026-08-09 10:10:00',
            'heartbeat_at' => '2026-08-09 10:06:00',
            'retry_count' => 2,
            'completed_at' => '2026-08-09 10:07:00',
            'counters' => ['seen' => 12, 'updated' => 3],
            'correlation_id' => 'run-123',
        ]);

        $checkpoint->refresh();

        $this->assertSame('Quotes', $checkpoint->module);
        $this->assertSame('line_items', $checkpoint->submodule);
        $this->assertSame('reconcile', $checkpoint->sync_mode);
        $this->assertSame(str_repeat('a', 64), $checkpoint->page_query_fingerprint);
        $this->assertSame('5309063000000100001', $checkpoint->cursor_zoho_id);
        $this->assertSame('5309063000000100000', $checkpoint->reconcile_cursor_zoho_id);
        $this->assertSame('reconcile-run-123', $checkpoint->reconcile_correlation_id);
        $this->assertSame('legacy-page-token', $checkpoint->cursor_page_token);
        $this->assertSame('running', $checkpoint->status);
        $this->assertSame('zoho-worker-1', $checkpoint->lease_owner);
        $this->assertSame(2, $checkpoint->retry_count);
        $this->assertSame(['seen' => 12, 'updated' => 3], $checkpoint->counters);
        $this->assertSame('run-123', $checkpoint->correlation_id);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $checkpoint->cursor_modified_time);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $checkpoint->cursor_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $checkpoint->reconcile_started_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $checkpoint->page_token_expires_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $checkpoint->lease_expires_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $checkpoint->heartbeat_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $checkpoint->completed_at);
    }

    public function test_log_persists_v2_telemetry_and_legacy_fields(): void
    {
        $log = ZohoSyncLog::create([
            'module' => 'Deals',
            'submodule' => 'history',
            'mode' => 'delta',
            'sync_batch_id' => 42,
            'correlation_id' => 'run-456',
            'synced_at' => '2026-08-09 10:00:00',
            'records_synced' => 10,
            'records_seen' => 15,
            'records_created' => 2,
            'records_updated' => 3,
            'records_unchanged' => 4,
            'records_deleted' => 1,
            'records_quarantined' => 5,
            'status' => 'degraded',
            'error' => 'redacted failure',
            'duration_ms' => 1200,
            'cursor_zoho_id' => '5309063000000100002',
            'cursor_at' => '2026-08-09 10:01:00',
            'api_requests' => 3,
            'api_credits' => 7,
            'telemetry' => ['retries' => 1],
        ]);

        $log->refresh();

        $this->assertSame('Deals', $log->module);
        $this->assertSame('history', $log->submodule);
        $this->assertSame('delta', $log->mode);
        $this->assertSame(42, $log->sync_batch_id);
        $this->assertSame('run-456', $log->correlation_id);
        $this->assertSame(10, $log->records_synced);
        $this->assertSame(15, $log->records_seen);
        $this->assertSame(2, $log->records_created);
        $this->assertSame(3, $log->records_updated);
        $this->assertSame(4, $log->records_unchanged);
        $this->assertSame(1, $log->records_deleted);
        $this->assertSame(5, $log->records_quarantined);
        $this->assertSame('degraded', $log->status);
        $this->assertSame('redacted failure', $log->error);
        $this->assertSame(1200, $log->duration_ms);
        $this->assertSame('5309063000000100002', $log->cursor_zoho_id);
        $this->assertSame(3, $log->api_requests);
        $this->assertSame(7, $log->api_credits);
        $this->assertSame(['retries' => 1], $log->telemetry);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $log->synced_at);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $log->cursor_at);
    }
}
