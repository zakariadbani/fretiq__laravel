<?php

namespace Tests\Feature\Backend;

use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZohoV2RegistrySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_commercial_visibility_allowlist_maps_only_to_persisted_business_columns(): void
    {
        $forbidden = [
            'raw_payload', 'payload_hash', 'field_schema_hash', 'normalized_email',
            'fretiq_company_id', 'fretiq_contact_id', 'zoho_deleted_at', 'zoho_deletion_type',
            'last_seen_at', 'last_synced_at', 'sync_batch_id', 'created_at', 'updated_at',
        ];

        foreach ((new ZohoModuleRegistry)->all() as $definition) {
            if (! Schema::hasTable($definition->table)) {
                continue;
            }

            foreach ($definition->visibilityAllowlist as $field) {
                $this->assertTrue(
                    Schema::hasColumn($definition->table, $field),
                    "{$definition->key} allowlist field [{$field}] is not persisted on {$definition->table}."
                );
                $this->assertNotContains($field, $forbidden, "{$definition->key} exposes internal field [{$field}].");
            }
        }
    }
}
