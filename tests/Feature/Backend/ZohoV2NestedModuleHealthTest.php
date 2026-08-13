<?php

namespace Tests\Feature\Backend;

use App\Models\Zoho\ZohoFieldManifest;
use App\Models\ZohoSyncLog;
use App\Models\ZohoToken;
use App\Services\Zoho\V2\Operations\ZohoOperationsDashboard;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoV2NestedModuleHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_quote_items_without_own_logs_do_not_degrade_healthy_top_level_modules(): void
    {
        $this->seed(RolesSeeder::class);

        ZohoToken::create([
            'service' => 'crm',
            'access_token' => 'opaque-test-token',
            'refresh_token' => 'opaque-refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $batchId = 1;
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

            if ($definition->key === 'quoted_items') {
                continue;
            }

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

        $data = app(ZohoOperationsDashboard::class)->data();

        $this->assertNull($data['modules']['quoted_items']['success']);
        $this->assertSame('Opérationnelle', $data['overall']['label']);
    }
}
