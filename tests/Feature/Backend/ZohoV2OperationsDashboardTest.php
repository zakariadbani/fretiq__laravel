<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Models\Zoho\ZohoAccount;
use App\Models\Zoho\ZohoActivity;
use App\Models\Zoho\ZohoFieldManifest;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Models\ZohoToken;
use App\Services\Zoho\V2\Operations\ZohoOperationsDashboard;
use App\Services\Zoho\V2\Reconciliation\ZohoDataQualityMetrics;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Carbon\CarbonImmutable;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZohoV2OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_flag_off_keeps_the_legacy_page_and_excludes_v2_logs(): void
    {
        config()->set('zoho-v2.features.operations_dashboard_enabled', false);
        ZohoSyncLog::create(['module' => 'Accounts', 'sync_batch_id' => 1, 'status' => 'success', 'synced_at' => now(), 'records_synced' => 123456789]);
        $this->actingAs($this->admin)->get('/admin/zoho')->assertOk()->assertDontSee('123,456,789');
    }

    public function test_flag_on_renders_safe_v2_operations_screen(): void
    {
        config()->set('zoho-v2.features.operations_dashboard_enabled', true);
        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()->assertSee('Synchronisation &amp; qualité des données', false)
            ->assertSee('non fourni par Zoho')->assertDontSee('raw_payload');
    }

    public function test_flag_off_legacy_page_survives_before_the_v2_log_extension_migration(): void
    {
        config()->set('zoho-v2.features.operations_dashboard_enabled', false);
        Schema::shouldReceive('hasColumn')->with('zoho_sync_logs', 'sync_batch_id')->once()->andReturn(false);

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()
            ->assertSee('Synchroniser maintenant');
    }

    public function test_flag_on_with_a_partial_v2_schema_safely_falls_back_to_legacy(): void
    {
        config()->set('zoho-v2.features.operations_dashboard_enabled', true);
        Schema::shouldReceive('getColumnListing')->with('zoho_sync_batches')->once()->andReturn([]);
        Schema::shouldReceive('hasColumn')->with('zoho_sync_logs', 'sync_batch_id')->once()->andReturn(false);

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()->assertSee('Synchroniser maintenant')->assertDontSee('Santé globale');
    }

    public function test_every_registry_table_has_safe_quality_queries(): void
    {
        $metrics = app(ZohoDataQualityMetrics::class);
        foreach (array_keys(app(ZohoModuleRegistry::class)->all()) as $module) {
            $this->assertIsArray($metrics->forModule($module), $module);
        }
    }

    public function test_failure_history_never_renders_stored_secret_or_exception_text(): void
    {
        config()->set('zoho-v2.features.operations_dashboard_enabled', true);
        ZohoSyncFailure::create([
            'module' => 'accounts', 'failure_kind' => 'record', 'failure_key' => hash('sha256', 'secret-test'),
            'error_summary' => 'client_secret=DO-NOT-RENDER RuntimeException payload', 'correlation_id' => 'safe-correlation',
        ]);

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()->assertSee('safe-correlation')->assertDontSee('DO-NOT-RENDER')->assertDontSee('RuntimeException');
    }

    public function test_permitted_admin_can_override_and_unmap_a_safe_identity(): void
    {
        config()->set('zoho-v2.features.operations_dashboard_enabled', true);
        ZohoAccount::create(['zoho_id' => 'account-1', 'owner_zoho_id' => 'owner-1', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'account-1')]);
        $target = User::factory()->create(['is_active' => true]);
        $target->assignRole('commercial');

        $this->actingAs($this->admin)->post('/admin/zoho/v2/mappings', ['zoho_user_id' => 'owner-1', 'fretiq_user_id' => $target->id])->assertRedirect();
        $this->assertDatabaseHas('zoho_user_mappings', ['zoho_user_id' => 'owner-1', 'fretiq_user_id' => $target->id, 'is_override' => true]);

        $this->actingAs($this->admin)->post('/admin/zoho/v2/mappings', ['zoho_user_id' => 'owner-1', 'fretiq_user_id' => null])->assertRedirect();
        $this->assertDatabaseHas('zoho_user_mappings', ['zoho_user_id' => 'owner-1', 'fretiq_user_id' => null, 'is_confirmed' => false]);

        $this->actingAs($this->admin)->post('/admin/zoho/v2/mappings', ['zoho_user_id' => 'forged-owner', 'fretiq_user_id' => $target->id])->assertSessionHasErrors('zoho_user_id');
        $this->assertDatabaseMissing('zoho_user_mappings', ['zoho_user_id' => 'forged-owner']);
        $this->actingAs($this->admin)->post('/admin/zoho/v2/mappings', ['zoho_user_id' => 'owner-1', 'fretiq_user_id' => $this->admin->id])->assertSessionHasErrors('fretiq_user_id');
    }

    public function test_dashboard_reads_v2_checkpoint_key_and_scopes_shared_activity_counts(): void
    {
        ZohoSyncCheckpoint::create(['module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'backfill', 'status' => 'running', 'cursor_zoho_id' => 'cursor-42']);
        ZohoActivity::create(['activity_type' => 'task', 'zoho_id' => 'task-1', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'task-1')]);
        ZohoActivity::create(['activity_type' => 'call', 'zoho_id' => 'call-1', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'call-1')]);

        $data = app(ZohoOperationsDashboard::class)->data();
        $this->assertSame('cursor-42', $data['modules']['accounts']['checkpoint']->cursor_zoho_id);
        $this->assertSame(1, $data['counts']['tasks']['active']);
        $this->assertSame(1, $data['counts']['calls']['active']);
        $this->assertSame(0, $data['counts']['events']['active']);
    }

    public function test_quote_with_only_tombstoned_items_is_reported_missing_active_items(): void
    {
        $quote = ZohoQuote::create(['zoho_id' => 'quote-1', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'quote-1')]);
        ZohoQuoteItem::create(['zoho_quote_id' => $quote->zoho_id, 'zoho_line_item_id' => 'line-1', 'zoho_deleted_at' => now(), 'raw_payload' => [], 'payload_hash' => hash('sha256', 'line-1')]);

        $this->assertSame(1, app(ZohoDataQualityMetrics::class)->forModule('quotes')['missing_quote_items']);
    }

    public function test_reconciliation_evidence_is_correlated_only_to_latest_reconcile_run(): void
    {
        ZohoSyncLog::create([
            'module' => 'accounts', 'submodule' => '', 'mode' => 'reconcile', 'sync_batch_id' => 10,
            'correlation_id' => 'new-run', 'status' => 'success', 'synced_at' => now(),
            'telemetry' => ['reconciliation' => [
                'status' => 'healthy', 'complete' => true, 'http_status' => 200,
                'remote_count' => 120, 'local_count' => 118, 'missing_count' => 2,
                'extra_count' => 0, 'pages' => 3, 'tombstoned' => 4,
            ]],
        ]);
        ZohoSyncFailure::create([
            'module' => 'accounts', 'failure_kind' => 'reconciliation',
            'failure_key' => hash('sha256', 'old-reconcile'), 'correlation_id' => 'old-run',
            'error_summary' => 'Old discrepancy',
            'context' => ['remote_count' => 999, 'local_count' => 1, 'missing_count' => 998],
        ]);

        $evidence = app(ZohoOperationsDashboard::class)->data()['modules']['accounts']['reconciliation'];
        $this->assertSame([
            'status' => 'healthy', 'remote' => 120, 'local' => 118, 'missing' => 2,
            'extra' => 0, 'complete' => true, 'pages' => 3, 'http_status' => 200,
            'tombstoned' => 4,
        ], $evidence);
    }

    public function test_only_an_unresolved_same_run_failure_downgrades_current_reconciliation(): void
    {
        ZohoSyncLog::create([
            'module' => 'contacts', 'submodule' => '', 'mode' => 'reconcile', 'sync_batch_id' => 11,
            'correlation_id' => 'current-run', 'status' => 'success', 'synced_at' => now(),
            'telemetry' => ['reconciliation' => [
                'status' => 'healthy', 'complete' => true, 'http_status' => 200,
                'remote_count' => 10, 'local_count' => 10, 'missing_count' => 0,
                'extra_count' => 0, 'pages' => 1, 'tombstoned' => 0,
            ]],
        ]);
        ZohoSyncFailure::create([
            'module' => 'contacts', 'failure_kind' => 'reconciliation',
            'failure_key' => hash('sha256', 'current-reconcile'), 'correlation_id' => 'current-run',
            'error_summary' => 'Current discrepancy', 'context' => ['remote_count' => 999],
        ]);

        $evidence = app(ZohoOperationsDashboard::class)->data()['modules']['contacts']['reconciliation'];
        $this->assertSame('degraded', $evidence['status']);
        $this->assertSame(10, $evidence['remote']);
        $this->assertSame(10, $evidence['local']);
    }

    public function test_latest_error_attempt_or_reconciliation_degrades_otherwise_operational_health(): void
    {
        ZohoToken::create(['service' => 'crm', 'access_token' => 'opaque-test-token', 'refresh_token' => 'opaque-refresh', 'expires_at' => now()->addHour()]);

        $batchId = 100;
        foreach (app(ZohoModuleRegistry::class)->all() as $definition) {
            if ($definition->activationGated) {
                continue;
            }

            ZohoFieldManifest::create([
                'module' => $definition->apiName,
                'submodule' => $definition->submodule ?? '',
                'schema_hash' => hash('sha256', $definition->key),
                'fields' => [],
                'is_current' => true,
                'drift_state' => 'verified',
                'verified_at' => now(),
            ]);
            ZohoSyncLog::create([
                'module' => $definition->key,
                'submodule' => $definition->submodule ?? '',
                'mode' => 'delta',
                'sync_batch_id' => $batchId++,
                'correlation_id' => 'healthy-delta-'.$definition->key,
                'status' => 'success',
                'synced_at' => now(),
            ]);
            ZohoSyncLog::create([
                'module' => $definition->key,
                'submodule' => $definition->submodule ?? '',
                'mode' => 'reconcile',
                'sync_batch_id' => $batchId++,
                'correlation_id' => 'healthy-reconcile-'.$definition->key,
                'status' => 'success',
                'synced_at' => now(),
                'telemetry' => ['reconciliation' => ['status' => 'healthy']],
            ]);
        }

        $dashboard = app(ZohoOperationsDashboard::class);
        $this->assertSame('Opérationnelle', $dashboard->data()['overall']['label']);

        ZohoSyncLog::create([
            'module' => 'accounts', 'submodule' => '', 'mode' => 'delta', 'sync_batch_id' => $batchId++,
            'correlation_id' => 'latest-delta-error', 'status' => 'error', 'synced_at' => now(),
        ]);
        $this->assertSame('Dégradée', $dashboard->data()['overall']['label']);

        ZohoSyncLog::create([
            'module' => 'accounts', 'submodule' => '', 'mode' => 'reconcile', 'sync_batch_id' => $batchId++,
            'correlation_id' => 'latest-reconciliation-error', 'status' => 'error', 'synced_at' => now(),
        ]);
        ZohoSyncLog::create([
            'module' => 'accounts', 'submodule' => '', 'mode' => 'delta', 'sync_batch_id' => $batchId,
            'correlation_id' => 'latest-delta-success', 'status' => 'success', 'synced_at' => now(),
        ]);
        $data = $dashboard->data();
        $this->assertSame('success', $data['modules']['accounts']['attempt']->status);
        $this->assertSame('error', $data['modules']['accounts']['reconciliation']['status']);
        $this->assertSame('Dégradée', $data['overall']['label']);
    }

    public function test_sync_controls_offer_reviewed_modules_and_scoped_retry_actions(): void
    {
        config()->set('zoho-v2.features.operations_dashboard_enabled', true);
        config()->set('zoho-v2.features.sync_enabled', true);
        ZohoSyncFailure::create([
            'module' => 'accounts', 'failure_kind' => 'record',
            'failure_key' => hash('sha256', 'retry-module'), 'correlation_id' => 'retry-safe',
            'error_summary' => 'Never render this stored detail.',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $response->assertSee('id="zoho-delta-module"', false)
            ->assertSee('id="zoho-backfill-module"', false)
            ->assertSee('id="zoho-reconcile-module"', false)
            ->assertSee('name="module" value="accounts"', false)
            ->assertSee('Réessayer le module Accounts')
            ->assertSee('Réessayer les anomalies du module accounts')
            ->assertDontSee('value="quoted_items"', false)
            ->assertDontSee('Never render this stored detail.');

        config()->set('zoho-v2.features.sync_enabled', false);
        $disabled = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $disabled->assertDontSee('id="zoho-delta-module"', false)
            ->assertDontSee('Réessayer le module Accounts')
            ->assertDontSee('Réessayer les anomalies du module accounts');
    }

    public function test_oauth_with_no_expiry_is_unknown_and_dashboard_query_budget_is_bounded(): void
    {
        ZohoToken::create(['service' => 'crm', 'access_token' => 'opaque-test-token', 'refresh_token' => 'opaque-refresh', 'expires_at' => null]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $data = app(ZohoOperationsDashboard::class)->data();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('unknown', $data['oauth']);
        $this->assertSame('Dégradée', $data['overall']['label']);
        $this->assertLessThanOrEqual(75, $queryCount, "Operations dashboard used {$queryCount} SQL statements.");
    }

    public function test_nightly_reconciliation_has_its_own_freshness_threshold(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');

        try {
            ZohoSyncLog::create(['module' => 'accounts', 'submodule' => '', 'mode' => 'reconcile', 'sync_batch_id' => 10, 'correlation_id' => 'nightly-run', 'status' => 'success', 'synced_at' => now()->subHours(30)]);

            $freshness = app(ZohoOperationsDashboard::class)->data()['modules']['accounts']['reconciliation_freshness'];
            $this->assertSame('warning', $freshness['color']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_v2_actions_require_their_specific_permissions_and_flag(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole('commercial');
        $this->actingAs($user)->post('/admin/zoho/v2/sync')->assertForbidden();
        $this->actingAs($user)->post('/admin/zoho/v2/backfill')->assertForbidden();
        config()->set('zoho-v2.features.sync_enabled', false);
        $this->actingAs($this->admin)->post('/admin/zoho/v2/sync')->assertNotFound();
    }
}
