<?php

namespace Tests\Feature\Backend;

use App\Models\Zoho\ZohoAccount;
use App\Models\Zoho\ZohoActivity;
use App\Models\Zoho\ZohoContact;
use App\Models\Zoho\ZohoDeal;
use App\Models\Zoho\ZohoDealStageHistory;
use App\Models\Zoho\ZohoFieldManifest;
use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZohoV2SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_v2_zoho_mirror_tables_are_available(): void
    {
        $tables = [
            'zoho_users', 'zoho_leads', 'zoho_accounts', 'zoho_contacts', 'zoho_deals', 'zoho_quotes',
            'zoho_quote_items', 'zoho_products', 'zoho_activities', 'zoho_deal_stage_history',
            'zoho_quote_status_history', 'zoho_actions_commercials', 'zoho_transport_international',
            'zoho_user_mappings', 'zoho_marketing_links', 'zoho_sync_batches', 'zoho_sync_failures',
            'zoho_field_manifests',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumns('zoho_accounts', [
            'zoho_id', 'raw_payload', 'payload_hash', 'field_schema_hash', 'owner_zoho_id',
            'last_seen_at', 'last_synced_at', 'zoho_deleted_at', 'sync_batch_id', 'fretiq_company_id',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_sync_checkpoints', ['submodule', 'sync_mode', 'page_query_fingerprint', 'lease_owner', 'heartbeat_at', 'counters', 'correlation_id']));
        $this->assertTrue(Schema::hasColumns('zoho_sync_logs', ['submodule', 'mode', 'sync_batch_id', 'records_quarantined', 'api_requests', 'telemetry']));
    }

    public function test_mapper_promoted_fields_are_persistable(): void
    {
        $this->assertTrue(Schema::hasColumns('zoho_leads', [
            'phone', 'mobile', 'secondary_phone', 'industry', 'account_zoho_id', 'contact_zoho_id',
            'converted_deal_zoho_id', 'converted_at', 'is_converted', 'title', 'client_type', 'transport_type',
            'language', 'address', 'city', 'website', 'incoterm', 'destination', 'volume', 'competitor',
            'origin_destination', 'observation', 'email_opt_out', 'unsubscribed_mode', 'unsubscribed_at',
            'last_activity_at', 'tags',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_accounts', [
            'parent_account_zoho_id', 'industry', 'account_type', 'active_status', 'account_status', 'address',
            'city', 'language', 'commercial_name', 'assignment_type', 'accounting_number', 'ice', 'tax_id',
            'trade_register', 'cnss', 'business_tax_number', 'trade_register_center', 'payment_mode',
            'transport_type', 'volume', 'competitor', 'origin_destination', 'observation',
            'logistics_manager_zoho_id', 'last_activity_at', 'tags',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_contacts', [
            'phone', 'mobile', 'title', 'company_name', 'address', 'city', 'postal_code', 'language',
            'lead_source', 'email_opt_out', 'email_opened', 'link_clicked', 'unsubscribed_mode',
            'unsubscribed_at', 'last_activity_at', 'description', 'tags', 'salutation',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_deals', [
            'exchange_rate', 'pipeline', 'stackability', 'last_activity_at', 'stage_modified_at', 'tags',
            'origin', 'destination', 'incoterm', 'cargo_description', 'gross_weight', 'volume', 'quantity',
            'dimensions', 'departure_frequency', 'package_type', 'quote_type', 'transport_type',
            'transit_time', 'expires_on', 'description',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_quotes', [
            'quote_number', 'discount', 'tax', 'exchange_rate', 'origin', 'destination',
            'transport_type', 'quote_date', 'follow_up_status', 'country', 'line_items_total', 'line_items_total_complete',
            'incoterms', 'gross_weight', 'volume', 'quantity_text', 'package_type', 'transit_time_days',
            'equipment_type', 'free_time', 'stackability', 'dangerous_goods_status', 'un_number',
            'dangerous_goods_class', 'dimensions', 'loading_meters', 'last_activity_at', 'tags',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_quote_items', [
            'product_name', 'description', 'unit_of_measure', 'list_price', 'unit_price', 'unit_price_raw',
            'discount', 'tax', 'total', 'total_raw', 'identity_source', 'zoho_created_at', 'zoho_modified_at',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_activities', ['start_at', 'end_at', 'contact_zoho_id']));
        $this->assertFalse(Schema::hasColumn('zoho_activities', 'related_zoho_id'));
        $this->assertTrue(Schema::hasColumns('zoho_deal_stage_history', [
            'parent_zoho_id', 'amount', 'probability', 'expected_revenue', 'closing_date', 'currency_code',
            'zoho_deletion_type', 'moved_to_stage', 'stage_duration_days',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_quote_status_history', ['parent_zoho_id', 'zoho_deletion_type']));
        $this->assertTrue(Schema::hasColumns('zoho_actions_commercials', [
            'comment', 'priority', 'action_at', 'due_at', 'contact_name', 'account_name', 'prospect_name', 'phone', 'mobile',
        ]));
        $this->assertFalse(Schema::hasColumn('zoho_actions_commercials', 'account_zoho_id'));
        $this->assertTrue(Schema::hasColumns('zoho_transport_international', ['email', 'secondary_email', 'currency_code', 'exchange_rate', 'status']));
        $this->assertTrue(Schema::hasColumns('zoho_products', ['vendor_name', 'vendor_zoho_id']));
        $this->assertFalse(Schema::hasColumn('zoho_transport_international', 'origin_country'));
        $this->assertTrue(Schema::hasColumn('zoho_field_manifests', 'mapping_gaps'));

        $this->assertTrue((new ZohoLead)->hasCast('tags', 'array'));
        $this->assertTrue((new ZohoLead)->hasCast('email_opt_out', 'boolean'));
        $this->assertTrue((new ZohoContact)->hasCast('email_opened', 'boolean'));
        $this->assertTrue((new ZohoDeal)->hasCast('quantity', 'integer'));
        $this->assertTrue((new ZohoQuote)->hasCast('incoterms', 'array'));
        $this->assertTrue((new ZohoDealStageHistory)->hasCast('stage_duration_days', 'integer'));
        $this->assertTrue((new ZohoFieldManifest)->hasCast('mapping_gaps', 'array'));
    }

    public function test_sync_control_tables_expose_durable_idempotency_and_drift_state(): void
    {
        $this->assertTrue(Schema::hasColumns('zoho_sync_batches', [
            'correlation_id', 'mode', 'trigger', 'requested_at', 'scheduled_for', 'error_summary',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_sync_failures', [
            'failure_key', 'module', 'submodule', 'zoho_id', 'failure_kind', 'retry_after', 'resolved_at',
        ]));
        $this->assertTrue(Schema::hasColumns('zoho_field_manifests', ['is_current', 'drift_state', 'schema_hash', 'related_lists']));

        $this->assertTrue((new ZohoSyncBatch)->hasCast('requested_at', 'datetime'));
        $this->assertTrue((new ZohoFieldManifest)->hasCast('is_current', 'boolean'));
    }

    public function test_mirror_models_keep_tombstones_visible_and_cast_payloads(): void
    {
        $account = ZohoAccount::query()->create([
            'zoho_id' => 'account-current',
            'raw_payload' => ['Account_Name' => 'Current'],
            'payload_hash' => hash('sha256', 'current'),
        ]);
        ZohoAccount::query()->create([
            'zoho_id' => 'account-deleted',
            'raw_payload' => ['Account_Name' => 'Deleted'],
            'payload_hash' => hash('sha256', 'deleted'),
            'zoho_deleted_at' => now(),
        ]);

        $this->assertSame(['Account_Name' => 'Current'], $account->raw_payload);
        $this->assertArrayNotHasKey('raw_payload', $account->toArray());
        $this->assertSame(['Account_Name' => 'Current'], $account->makeVisible('raw_payload')->toArray()['raw_payload']);
        $this->assertSame(1, ZohoAccount::query()->current()->count());
        $this->assertSame(1, ZohoAccount::query()->tombstoned()->count());

        $item = ZohoQuoteItem::query()->create([
            'zoho_quote_id' => 'quote-raw-hidden',
            'zoho_line_item_id' => 'item-raw-hidden',
            'raw_payload' => ['Prix_Total' => '100.00'],
            'payload_hash' => hash('sha256', 'item-raw-hidden'),
        ]);
        $this->assertArrayNotHasKey('raw_payload', $item->toArray());
        $this->assertSame(['Prix_Total' => '100.00'], $item->makeVisible('raw_payload')->toArray()['raw_payload']);
    }

    public function test_quote_item_identity_is_unique_per_quote_and_line_item(): void
    {
        $quote = ZohoQuote::query()->create([
            'zoho_id' => 'quote-1',
            'raw_payload' => ['Subject' => 'Test'],
            'payload_hash' => hash('sha256', 'quote-1'),
        ]);

        ZohoQuoteItem::query()->create([
            'zoho_quote_id' => $quote->zoho_id,
            'zoho_line_item_id' => 'line-1',
            'raw_payload' => ['id' => 'line-1'],
            'payload_hash' => hash('sha256', 'line-1'),
        ]);

        $this->expectException(QueryException::class);

        ZohoQuoteItem::query()->create([
            'zoho_quote_id' => $quote->zoho_id,
            'zoho_line_item_id' => 'line-1',
            'raw_payload' => ['id' => 'line-1'],
            'payload_hash' => hash('sha256', 'line-1-again'),
        ]);
    }

    public function test_failure_key_is_a_durable_idempotency_boundary(): void
    {
        $failure = [
            'module' => 'Quotes',
            'submodule' => '',
            'failure_kind' => 'record',
            'failure_key' => hash('sha256', 'Quotes||quote-1|record'),
            'zoho_id' => 'quote-1',
            'error_summary' => 'Redacted failure',
        ];

        ZohoSyncFailure::query()->create($failure);

        $this->expectException(QueryException::class);
        ZohoSyncFailure::query()->create($failure);
    }

    public function test_lookup_relationships_use_zoho_string_keys_without_database_foreign_keys(): void
    {
        $account = ZohoAccount::query()->create([
            'zoho_id' => 'account-relationship',
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'account-relationship'),
        ]);
        $deal = ZohoDeal::query()->create([
            'zoho_id' => 'deal-relationship',
            'account_zoho_id' => $account->zoho_id,
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'deal-relationship'),
        ]);
        $history = ZohoDealStageHistory::query()->create([
            'zoho_id' => 'history-relationship',
            'deal_zoho_id' => $deal->zoho_id,
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'history-relationship'),
        ]);
        $activity = new ZohoActivity(['contact_zoho_id' => 'contact-relationship']);

        $this->assertTrue($deal->account->is($account));
        $this->assertTrue($history->deal->is($deal));
        $this->assertSame('contact_zoho_id', $activity->contact()->getForeignKeyName());
        $this->assertSame('zoho_id', $activity->contact()->getOwnerKeyName());
    }
}
