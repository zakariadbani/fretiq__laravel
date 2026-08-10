<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Models\Zoho\ZohoAccount;
use App\Models\Zoho\ZohoActivity;
use App\Models\Zoho\ZohoFieldManifest;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoStandardSyncRun;
use App\Models\Zoho\ZohoStandardSyncWorkItem;
use App\Models\Zoho\ZohoSyncBatch;
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
use DOMDocument;
use DOMElement;
use DOMXPath;
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

    public function test_schema_ready_dashboard_excludes_legacy_logs(): void
    {
        ZohoSyncLog::create(['module' => 'Accounts', 'sync_batch_id' => 1, 'status' => 'success', 'synced_at' => now(), 'records_synced' => 123456789]);
        $this->actingAs($this->admin)->get('/admin/zoho')->assertOk()->assertDontSee('123,456,789');
    }

    public function test_schema_ready_dashboard_renders_safe_v2_operations_screen(): void
    {
        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()->assertSee('Synchronisation &amp; qualité des données', false)
            ->assertSee('non fourni par Zoho')->assertDontSee('raw_payload');
    }

    public function test_legacy_page_survives_before_the_v2_log_extension_migration(): void
    {
        Schema::shouldReceive('hasColumn')->with('zoho_sync_logs', 'sync_batch_id')->once()->andReturn(false);
        Schema::shouldReceive('getColumnListing')->with('zoho_sync_batches')->once()->andReturn([]);

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()
            ->assertSee('Synchroniser maintenant');
    }

    public function test_partial_v2_schema_safely_falls_back_to_legacy(): void
    {
        Schema::shouldReceive('getColumnListing')->with('zoho_sync_batches')->once()->andReturn([]);
        Schema::shouldReceive('hasColumn')->with('zoho_sync_logs', 'sync_batch_id')->once()->andReturn(false);

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()->assertSee('Synchroniser maintenant')->assertDontSee('Santé globale');
    }

    public function test_resume_controls_stay_unavailable_before_the_additive_resume_schema_exists(): void
    {
        Schema::shouldReceive('getColumnListing')->with('zoho_sync_batches')->once()->andReturn([
            'status', 'requested_at', 'mode', 'correlation_id',
        ]);

        $this->assertFalse(app(ZohoOperationsDashboard::class)->available());
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
        ZohoSyncFailure::create([
            'module' => 'accounts', 'failure_kind' => 'record', 'failure_key' => hash('sha256', 'secret-test'),
            'error_summary' => 'client_secret=DO-NOT-RENDER RuntimeException payload', 'correlation_id' => 'safe-correlation',
        ]);

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()->assertSee('safe-correlation')->assertDontSee('DO-NOT-RENDER')->assertDontSee('RuntimeException');
    }

    public function test_dashboard_renders_mapping_gaps_without_manifest_payload_details(): void
    {
        ZohoFieldManifest::query()->create([
            'module' => 'Leads',
            'submodule' => '',
            'schema_hash' => hash('sha256', 'leads-gapped'),
            'fields' => [['api_name' => 'Private_Field', 'field_label' => 'DO-NOT-RENDER']],
            'mapping_gaps' => ['industry' => ['Secteur_Activit']],
            'is_current' => true,
            'drift_state' => 'drifted',
        ]);

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()
            ->assertSee('Champs source manquants')
            ->assertSee('industry')
            ->assertSee('Secteur_Activit')
            ->assertDontSee('DO-NOT-RENDER')
            ->assertDontSee('Private_Field');
    }

    public function test_permitted_admin_can_override_and_unmap_a_safe_identity(): void
    {
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

    public function test_permitted_admin_sees_sync_all_and_each_reviewed_module_delta_form(): void
    {

        $response = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $allForm = $xpath->query('//form[@data-zoho-sync-all-form]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $allForm);
        $this->assertSame('POST', strtoupper($allForm->getAttribute('method')));
        $this->assertSame(route('admin.zoho.v2.sync'), $allForm->getAttribute('action'));
        $this->assertCount(1, $xpath->query('.//input[@name="_token"]', $allForm));
        $this->assertCount(0, $xpath->query('.//input[@name="module"]', $allForm));

        $actionModules = collect(app(ZohoModuleRegistry::class)->all())
            ->reject(fn ($definition) => $definition->activationGated || $definition->key === 'quoted_items');

        $this->assertCount($actionModules->count(), $xpath->query('//*[@data-zoho-module-card]'));
        foreach ($actionModules as $key => $definition) {
            $form = $xpath->query("//form[@data-zoho-sync-module='{$key}']")->item(0);
            $this->assertInstanceOf(DOMElement::class, $form, "Missing delta form for {$key}.");
            $this->assertSame('POST', strtoupper($form->getAttribute('method')));
            $this->assertSame(route('admin.zoho.v2.sync'), $form->getAttribute('action'));
            $this->assertCount(1, $xpath->query('.//input[@name="_token"]', $form));
            $this->assertSame(
                $key,
                $xpath->query('.//input[@name="module"]', $form)->item(0)?->getAttribute('value'),
            );
            $this->assertSame($definition->apiName, trim($xpath->query("//*[@data-zoho-module-label='{$key}']")->item(0)?->textContent ?? ''));
            $this->assertCount(1, $xpath->query("//*[@data-zoho-module-last-success='{$key}']"));
            $this->assertCount(1, $xpath->query("//*[@data-zoho-module-counts='{$key}']"));
        }

        $this->assertCount(0, $xpath->query('//*[@data-zoho-module-card="users"]'));
        $this->assertCount(0, $xpath->query('//*[@data-zoho-module-card="quoted_items"]'));
    }

    public function test_busy_module_sync_buttons_are_disabled(): void
    {
        ZohoSyncCheckpoint::create(['module' => 'v2:accounts', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'queued']);
        ZohoSyncCheckpoint::create(['module' => 'v2:leads', 'submodule' => '', 'sync_mode' => 'delta', 'status' => 'running']);
        ZohoSyncCheckpoint::create(['module' => 'v2:tasks', 'submodule' => 'Tasks', 'sync_mode' => 'delta', 'status' => 'retrying']);

        $response = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $xpath = $this->htmlXPath($response->getContent());

        foreach (['accounts', 'leads', 'tasks'] as $key) {
            $button = $xpath->query("//button[@data-zoho-sync-button='{$key}']")->item(0);
            $this->assertInstanceOf(DOMElement::class, $button);
            $this->assertSame('busy', $button->getAttribute('data-sync-state'));
            $this->assertTrue($button->hasAttribute('disabled'));
            $this->assertSame('true', $button->getAttribute('aria-disabled'));
            $this->assertStringContainsString('En cours…', trim($button->textContent));
        }

        $availableButton = $xpath->query("//button[@data-zoho-sync-button='contacts']")->item(0);
        $this->assertInstanceOf(DOMElement::class, $availableButton);
        $this->assertSame('ready', $availableButton->getAttribute('data-sync-state'));
        $this->assertFalse($availableButton->hasAttribute('disabled'));
        $this->assertStringContainsString('Synchroniser', trim($availableButton->textContent));
    }

    public function test_running_sync_all_batch_disables_restart_and_exposes_pause_without_changing_maintenance_routes(): void
    {
        $batch = $this->syncAllBatch('running');

        $response = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $syncButton = $xpath->query('//button[@data-zoho-sync-all-button]')->item(0);
        $pauseForm = $xpath->query('//form[@data-zoho-pause-all-form]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $syncButton);
        $this->assertSame('active', $syncButton->getAttribute('data-sync-state'));
        $this->assertTrue($syncButton->hasAttribute('disabled'));
        $this->assertInstanceOf(DOMElement::class, $pauseForm);
        $this->assertSame(route('admin.zoho.v2.pause'), $pauseForm->getAttribute('action'));
        $this->assertSame((string) $batch->id, $xpath->query('.//input[@name="batch_id"]', $pauseForm)->item(0)?->getAttribute('value'));
        $this->assertSame(route('admin.zoho.v2.backfill'), $xpath->query("//form[@data-zoho-maintenance='backfill']")->item(0)?->getAttribute('action'));
    }

    public function test_paused_sync_all_batch_renders_resume_and_disables_its_per_module_controls(): void
    {
        $batch = $this->syncAllBatch('paused');

        $data = app(ZohoOperationsDashboard::class)->data();
        $this->assertSame($batch->id, $data['batch']?->id);
        $this->assertSame($batch->id, $data['syncAllBatch']?->id);
        $this->assertTrue($data['modules']['accounts']['paused_owner']);

        $response = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $syncButton = $xpath->query('//button[@data-zoho-sync-all-button]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $syncButton);
        $this->assertSame('paused', $syncButton->getAttribute('data-sync-state'));
        $this->assertFalse($syncButton->hasAttribute('disabled'));
        $this->assertStringContainsString('Reprendre', trim($syncButton->textContent));
        $this->assertCount(0, $xpath->query('//form[@data-zoho-pause-all-form]'));

        foreach ((array) $batch->modules as $key) {
            $button = $xpath->query("//button[@data-zoho-sync-button='{$key}']")->item(0);
            $this->assertInstanceOf(DOMElement::class, $button, "Missing paused control for {$key}.");
            $this->assertSame('paused', $button->getAttribute('data-sync-state'));
            $this->assertTrue($button->hasAttribute('disabled'));
        }
    }

    public function test_advanced_maintenance_controls_keep_their_existing_routes(): void
    {

        $response = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $xpath = $this->htmlXPath($response->getContent());

        $this->assertCount(1, $xpath->query('//*[@data-zoho-maintenance-controls]'));
        $this->assertSame(route('admin.zoho.v2.retry'), $xpath->query("//form[@data-zoho-maintenance='retry-all']")->item(0)?->getAttribute('action'));
        $this->assertSame(route('admin.zoho.v2.backfill'), $xpath->query("//form[@data-zoho-maintenance='backfill']")->item(0)?->getAttribute('action'));
        $this->assertSame(route('admin.zoho.v2.backfill'), $xpath->query("//form[@data-zoho-maintenance='reconcile']")->item(0)?->getAttribute('action'));
        $response->assertSee('id="zoho-backfill-module"', false)
            ->assertSee('id="zoho-reconcile-module"', false);
    }

    public function test_sync_center_is_hidden_without_the_permission(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole('commercial');
        $user->givePermissionTo('view zoho');

        $unauthorized = $this->actingAs($user)->get('/admin/zoho')->assertOk();
        $unauthorized->assertDontSee('data-zoho-sync-center', false)
            ->assertDontSee('data-zoho-maintenance-controls', false);
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

    public function test_v2_actions_require_their_specific_permissions_and_schema_readiness(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole('commercial');
        $this->actingAs($user)->post('/admin/zoho/v2/sync')->assertForbidden();
        $this->actingAs($user)->post('/admin/zoho/v2/backfill')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/zoho/v2/sync')->assertRedirect();
    }

    public function test_sync_progress_aggregates_exact_batch_worklist_without_exposing_record_details(): void
    {
        $batch = $this->syncAllBatch('running');
        $this->completedCheckpoints($batch, ['accounts', 'contacts']);
        $quotes = $this->syncRun($batch, 'quotes', 'enumerating');
        $notes = $this->syncRun($batch, 'notes', 'hydrating', now());
        $this->workItems($quotes, ['queued', 'queued']);
        $this->workItems($notes, ['completed', 'completed', 'quarantined', 'processing', 'queued']);

        $progress = app(ZohoOperationsDashboard::class)->syncProgress($batch);

        $this->assertNotNull($progress);
        $this->assertSame($batch->id, $progress['batch']['id']);
        $this->assertSame(7, $progress['summary']['discovered']);
        $this->assertSame(3, $progress['summary']['processed']);
        $this->assertSame(2, $progress['summary']['completed']);
        $this->assertSame(1, $progress['summary']['quarantined']);
        $this->assertSame(3, $progress['summary']['queued']);
        $this->assertSame(1, $progress['summary']['processing']);
        $this->assertFalse($progress['summary']['determinate']);
        $this->assertNull($progress['summary']['percent']);
        $this->assertSame('enumerating', $progress['modules']['quotes']['phase']);
        $this->assertSame('hydrating', $progress['modules']['notes']['phase']);
        $this->assertSame(60, $progress['modules']['notes']['percent']);

        $encoded = json_encode($progress, JSON_THROW_ON_ERROR);
        foreach (['zoho_id', 'raw_payload', 'page_token', 'lease_owner', 'correlation_id', 'error_summary'] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, $encoded);
        }
    }

    public function test_sync_progress_is_indeterminate_during_enumeration_and_determinate_during_hydration(): void
    {
        $batch = $this->syncAllBatch('running');
        $run = $this->syncRun($batch, 'notes', 'enumerating');
        $this->workItems($run, ['completed', 'quarantined', 'queued', 'queued']);

        $this->assertNull(app(ZohoOperationsDashboard::class)->syncProgress($batch)['modules']['notes']['percent']);

        $run->update(['status' => 'hydrating', 'enumerated_at' => now()]);

        $this->assertSame(50, app(ZohoOperationsDashboard::class)->syncProgress($batch)['modules']['notes']['percent']);
    }

    public function test_checkpoint_phase_overrides_a_stale_hydrating_run_during_retry_and_terminalization(): void
    {
        $batch = $this->syncAllBatch('running');
        $this->syncRun($batch, 'notes', 'hydrating', now());
        $checkpoint = ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:notes',
            'submodule' => 'Notes',
            'sync_mode' => 'delta',
            'status' => 'retrying',
            'sync_batch_id' => $batch->id,
            'heartbeat_at' => now(),
        ]);

        $this->assertSame('retrying', app(ZohoOperationsDashboard::class)->syncProgress($batch)['modules']['notes']['phase']);

        $checkpoint->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertSame('completed', app(ZohoOperationsDashboard::class)->syncProgress($batch)['modules']['notes']['phase']);

        $checkpoint->update(['status' => 'partial']);
        $this->assertSame('error', app(ZohoOperationsDashboard::class)->syncProgress($batch)['modules']['notes']['phase']);

        $checkpoint->update(['status' => 'failed']);
        $this->assertSame('error', app(ZohoOperationsDashboard::class)->syncProgress($batch)['modules']['notes']['phase']);
    }

    public function test_sync_progress_marks_stalled_only_after_the_configured_delivery_window(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 15:54:30');

        try {
            config()->set('zoho-v2.module.delivery_timeout_seconds', 1200);
            config()->set('queue.connections.zoho.retry_after', 1260);
            $batch = $this->syncAllBatch('running');
            $run = $this->syncRun($batch, 'notes', 'hydrating', now());
            $this->workItems($run, ['queued']);

            $this->assertFalse(app(ZohoOperationsDashboard::class)->syncProgress($batch)['stalled']);

            $stale = now()->subSeconds(1261);
            $batch->update(['updated_at' => $stale]);
            $run->update(['updated_at' => $stale]);
            ZohoStandardSyncWorkItem::query()->where('zoho_standard_sync_run_id', $run->id)->update(['updated_at' => $stale]);
            $this->assertTrue(app(ZohoOperationsDashboard::class)->syncProgress($batch->fresh())['stalled']);

            $batch->update(['status' => 'paused', 'paused_at' => now()]);
            $pausedProgress = app(ZohoOperationsDashboard::class)->syncProgress($batch->fresh());
            $this->assertFalse($pausedProgress['stalled']);
            $this->assertSame('paused', $pausedProgress['modules']['notes']['phase']);
            $batch->update(['status' => 'completed', 'completed_at' => now()]);
            $this->assertFalse(app(ZohoOperationsDashboard::class)->syncProgress($batch->fresh())['stalled']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_view_zoho_user_can_read_exact_batch_progress_without_cache_or_sensitive_data(): void
    {
        $batch = $this->syncAllBatch('running');
        $run = $this->syncRun($batch, 'notes', 'hydrating', now());
        $this->workItems($run, ['completed', 'completed', 'quarantined']);

        $response = $this->actingAs($this->admin)->get('/admin/zoho/v2/batches/'.$batch->id.'/progress');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('batch.id', $batch->id)
            ->assertJsonPath('summary.processed', 3)
            ->assertJsonMissingPath('modules.notes.zoho_ids');
        foreach (['zoho_id', 'raw_payload', 'page_token', 'lease_owner', 'correlation_id', 'error_summary'] as $sensitiveKey) {
            $this->assertStringNotContainsString($sensitiveKey, $response->getContent());
        }
    }

    public function test_progress_endpoint_requires_view_zoho_permission(): void
    {
        $batch = $this->syncAllBatch('running');
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole('commercial');

        $this->get('/admin/zoho/v2/batches/'.$batch->id.'/progress')->assertRedirect('/login');
        $this->actingAs($user)->get('/admin/zoho/v2/batches/'.$batch->id.'/progress')->assertForbidden();
    }

    public function test_progress_endpoint_rejects_non_sync_all_batches(): void
    {
        $singleModule = ZohoSyncBatch::query()->create([
            'correlation_id' => 'single-module-'.str()->uuid(), 'mode' => 'delta', 'trigger' => 'manual',
            'status' => 'running', 'modules' => ['accounts'], 'requested_at' => now(),
        ]);
        $reconciliation = $this->syncAllBatch('running');
        $reconciliation->update(['mode' => 'reconcile']);

        $this->actingAs($this->admin)->get('/admin/zoho/v2/batches/'.$singleModule->id.'/progress')->assertNotFound();
        $this->actingAs($this->admin)->get('/admin/zoho/v2/batches/'.$reconciliation->id.'/progress')->assertNotFound();
    }

    public function test_progress_endpoint_query_count_is_bounded_independent_of_work_item_count(): void
    {
        $batch = $this->syncAllBatch('running');
        $runs = [
            $this->syncRun($batch, 'quotes', 'hydrating', now()),
            $this->syncRun($batch, 'notes', 'hydrating', now()),
        ];
        $now = now();
        foreach ($runs as $runIndex => $run) {
            $items = [];
            for ($index = 0; $index < 250; $index++) {
                $items[] = [
                    'zoho_standard_sync_run_id' => $run->id,
                    'zoho_id' => 'query-budget-'.$runIndex.'-'.$index,
                    'status' => $index < 125 ? 'completed' : 'queued',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('zoho_standard_sync_work_items')->insert($items);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->admin)->get(route('admin.zoho.v2.progress', $batch));
        $queries = DB::getQueryLog();
        $queryCount = count($queries);
        DB::disableQueryLog();

        $response->assertOk()->assertJsonPath('summary.discovered', 500);
        $querySummary = collect($queries)->pluck('query')->implode("\n");
        $this->assertLessThanOrEqual(8, $queryCount, "Progress endpoint used {$queryCount} SQL statements:\n{$querySummary}");
    }

    public function test_running_sync_all_renders_live_worklist_progress_with_accessible_hooks(): void
    {
        $batch = $this->syncAllBatch('running');
        $quotes = $this->syncRun($batch, 'quotes', 'enumerating');
        $notes = $this->syncRun($batch, 'notes', 'hydrating', now());
        $this->workItems($quotes, ['queued']);
        $this->workItems($notes, ['completed', 'queued']);

        $response = $this->actingAs($this->admin)->get('/admin/zoho')->assertOk();
        $xpath = $this->htmlXPath($response->getContent());
        $root = $xpath->query('//*[@data-zoho-live-progress]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $root);
        $this->assertSame((string) $batch->id, $root->getAttribute('data-batch-id'));
        $this->assertSame(route('admin.zoho.v2.progress', $batch), $root->getAttribute('data-status-url'));
        $this->assertSame('polite', $xpath->query('//*[@data-zoho-progress-message]')->item(0)?->getAttribute('aria-live'));
        $this->assertStringContainsString('Énumération', $response->getContent());
        $this->assertStringContainsString('Hydratation', $response->getContent());
        $this->assertStringContainsString('Traitées', $response->getContent());
        $quotesBar = $xpath->query("//*[@data-zoho-progress-module='quotes']//*[@data-zoho-progress-bar]")->item(0);
        $notesBar = $xpath->query("//*[@data-zoho-progress-module='notes']//*[@data-zoho-progress-bar]")->item(0);
        $this->assertFalse($quotesBar?->hasAttribute('aria-valuenow') ?? true);
        $this->assertSame('50', $notesBar?->getAttribute('aria-valuenow'));
        $this->assertCount(0, $xpath->query('ancestor::*[@aria-hidden="true"]', $quotesBar));
        $this->assertCount(0, $xpath->query('ancestor::*[@aria-hidden="true"]', $notesBar));
    }

    public function test_dashboard_distinguishes_local_stock_from_live_worklist_progress(): void
    {
        $batch = $this->syncAllBatch('running');
        $this->syncRun($batch, 'notes', 'hydrating', now());

        $this->actingAs($this->admin)->get('/admin/zoho')
            ->assertOk()
            ->assertSee('Stock local')
            ->assertSee('Progression en direct du lot');
    }

    private function htmlXPath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function syncAllBatch(string $status): ZohoSyncBatch
    {
        $modules = array_keys(array_filter(
            app(ZohoModuleRegistry::class)->all(),
            fn ($definition): bool => ! $definition->activationGated && $definition->key !== 'quoted_items',
        ));

        return ZohoSyncBatch::query()->create([
            'correlation_id' => 'dashboard-'.str()->uuid(),
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => $status,
            'modules' => $modules,
            'requested_at' => now(),
            'paused_at' => $status === 'paused' ? now() : null,
        ]);
    }

    /** @param list<string> $modules */
    private function completedCheckpoints(ZohoSyncBatch $batch, array $modules): void
    {
        foreach ($modules as $module) {
            $definition = app(ZohoModuleRegistry::class)->get($module);
            ZohoSyncCheckpoint::query()->create([
                'module' => 'v2:'.$module,
                'submodule' => $definition->submodule ?? '',
                'sync_mode' => 'delta',
                'status' => 'completed',
                'sync_batch_id' => $batch->id,
                'completed_at' => now(),
                'heartbeat_at' => now(),
            ]);
        }
    }

    private function syncRun(ZohoSyncBatch $batch, string $module, string $status, $enumeratedAt = null): ZohoStandardSyncRun
    {
        $definition = app(ZohoModuleRegistry::class)->get($module);

        return ZohoStandardSyncRun::query()->create([
            'sync_batch_id' => $batch->id,
            'module' => $module,
            'submodule' => $definition->submodule ?? '',
            'correlation_id' => 'test-'.$module.'-'.$batch->id,
            'mode' => 'delta',
            'status' => $status,
            'query_fingerprint' => hash('sha256', $module),
            'query_params' => [],
            'watermark_at' => now(),
            'enumerated_at' => $enumeratedAt,
        ]);
    }

    /** @param list<string> $statuses */
    private function workItems(ZohoStandardSyncRun $run, array $statuses): void
    {
        foreach ($statuses as $index => $status) {
            ZohoStandardSyncWorkItem::query()->create([
                'zoho_standard_sync_run_id' => $run->id,
                'zoho_id' => 'private-'.$run->id.'-'.$index,
                'status' => $status,
            ]);
        }
    }
}
