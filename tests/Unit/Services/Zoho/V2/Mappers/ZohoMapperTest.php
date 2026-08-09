<?php

namespace Tests\Unit\Services\Zoho\V2\Mappers;

use App\Services\Zoho\V2\Mappers\AccountMapper;
use App\Services\Zoho\V2\Mappers\ActionsCommercialsMapper;
use App\Services\Zoho\V2\Mappers\ActivityMapper;
use App\Services\Zoho\V2\Mappers\ContactMapper;
use App\Services\Zoho\V2\Mappers\DealMapper;
use App\Services\Zoho\V2\Mappers\DealStageHistoryMapper;
use App\Services\Zoho\V2\Mappers\LeadMapper;
use App\Services\Zoho\V2\Mappers\ProductMapper;
use App\Services\Zoho\V2\Mappers\QuoteItemMapper;
use App\Services\Zoho\V2\Mappers\QuoteMapper;
use App\Services\Zoho\V2\Mappers\TransportInternationalMapper;
use App\Services\Zoho\V2\Mappers\UserMapper;
use App\Services\Zoho\V2\Mappers\ZohoMapperResolver;
use Tests\TestCase;

class ZohoMapperTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return require base_path('tests/Fixtures/Zoho/V2/payloads.php');
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['seen_at' => '2026-08-09T10:00:00+00:00', 'schema_hash' => 'schema-hash'];
    }

    public function test_hash_is_stable_when_object_key_order_changes(): void
    {
        $mapper = new AccountMapper;
        $this->assertSame($mapper->payloadHash(['a' => 1, 'b' => ['x' => null, 'y' => 2]]), $mapper->payloadHash(['b' => ['y' => 2, 'x' => null], 'a' => 1]));
    }

    public function test_it_maps_accounts_contacts_deals_and_products_without_currency_conversion(): void
    {
        $data = $this->fixture();
        $context = $this->context();
        $account = (new AccountMapper)->map($data['account'], $context);
        $contact = (new ContactMapper)->map($data['contact'], $context);
        $deal = (new DealMapper)->map($data['deal'], $context);
        $product = (new ProductMapper)->map(['id' => 'product-1', 'Product_Name' => 'P', 'Product_Code' => 'P-1', 'Unit_Price' => '10.1234', 'Currency' => 'MAD', 'Vendor_Name' => ['id' => 'vendor-1', 'name' => 'Verified Vendor']], $context);
        $this->assertSame('user-1', $account['owner_zoho_id']);
        $this->assertSame('Transport', $account['industry']);
        $this->assertArrayNotHasKey('email', $account);
        $this->assertNull($account['raw_payload']['Custom_Risk__c']);
        $this->assertSame('account-1', $contact['account_zoho_id']);
        $this->assertSame('marie@example.test', $contact['normalized_email']);
        $this->assertSame('1234.5678', $deal['amount']);
        $this->assertSame('370.37', $deal['weighted_amount']);
        $this->assertSame('EUR', $deal['currency_code']);
        $this->assertSame('10.1234', $product['unit_price']);
        $this->assertSame('MAD', $product['currency_code']);
        $this->assertSame('product_category', array_key_first(array_filter(['product_category' => $product['product_category']], fn () => true)));
        $this->assertSame('vendor-1', $product['vendor_zoho_id']);
        $this->assertSame('Verified Vendor', $product['vendor_name']);
    }

    public function test_leads_preserve_the_verified_converted_state_as_a_nullable_boolean(): void
    {
        $mapper = new LeadMapper;

        $converted = $mapper->map(['id' => 'lead-converted', 'Converted__s' => true], $this->context());
        $active = $mapper->map(['id' => 'lead-active', 'Converted__s' => false], $this->context());
        $unknown = $mapper->map(['id' => 'lead-unknown'], $this->context());

        $this->assertTrue($converted['is_converted']);
        $this->assertFalse($active['is_converted']);
        $this->assertNull($unknown['is_converted']);
    }

    public function test_quotes_map_aggregate_and_line_item_fallbacks_idempotently(): void
    {
        $data = $this->fixture();
        $mapper = new QuoteMapper;
        $first = $mapper->mapAggregate($data['quote'], $this->context());
        $second = $mapper->mapAggregate($data['quote'], $this->context());
        $this->assertSame('USD', $first['quote']['currency_code']);
        $this->assertSame('1', $first['quote']['exchange_rate']);
        $this->assertNull($first['quote']['zoho_modified_at']);
        $this->assertSame('Q-TEST-001', $first['quote']['quote_number']);
        $this->assertSame('Relance', $first['quote']['follow_up_status']);
        $this->assertSame('Casablanca', $first['quote']['destination']);
        $this->assertSame('line-native-1', $first['items'][0]['zoho_line_item_id']);
        $this->assertSame($first['items'][1]['zoho_line_item_id'], $second['items'][1]['zoho_line_item_id']);
        $this->assertSame(['Road', 'Express'], $first['quote']['transport_type']);
        $this->assertArrayNotHasKey('id', $first['items'][1]['raw_payload']);
        $this->assertNull($first['items'][1]['raw_payload']['Custom_Item__c']);
        $this->assertSame('Synthetic description', $first['items'][0]['description']);
        $this->assertSame('container', $first['items'][0]['unit_of_measure']);
        $this->assertSame('native', $first['items'][0]['identity_source']);
        $this->assertSame('fallback', $first['items'][1]['identity_source']);
        $this->assertTrue($first['quoted_items_authoritative']);
        $this->assertSame('40.85', $first['quote']['line_items_total']);
        $this->assertTrue($first['quote']['line_items_total_complete']);
        $this->assertSame(['zoho_quote_id', 'zoho_line_item_id', 'identity_source', 'product_zoho_id', 'product_name', 'sequence', 'quantity', 'list_price', 'unit_price', 'unit_price_raw', 'description', 'unit_of_measure', 'discount', 'tax', 'total', 'total_raw', 'currency_code', 'raw_payload', 'payload_hash', 'field_schema_hash', 'zoho_created_at', 'zoho_modified_at', 'last_seen_at', 'last_synced_at', 'zoho_deleted_at', 'zoho_deletion_type', 'sync_batch_id'], array_keys($first['items'][0]));
    }

    public function test_activities_histories_custom_modules_and_owner_lookups_are_mapped(): void
    {
        $data = $this->fixture();
        $context = $this->context();
        $meeting = (new ActivityMapper('meeting'))->map($data['activity'], $context);
        $history = (new DealStageHistoryMapper)->map($data['history'], $context);
        $owner = (new UserMapper)->mapOwnerLookup($data['owner'], $context);
        $action = (new ActionsCommercialsMapper)->map($data['actions_commercials'], $context);
        $transport = (new TransportInternationalMapper)->map($data['transport_international'], $context);
        $this->assertSame('meeting', $meeting['activity_type']);
        $this->assertSame('deal-1', $meeting['parent_zoho_id']);
        $this->assertSame('Closed Won', $history['stage']);
        $this->assertSame('75', $history['probability']);
        $this->assertSame('1500', $history['expected_revenue']);
        $this->assertSame('2026-08-03', $history['closing_date']);
        $this->assertSame('MAD', $history['currency_code']);
        $this->assertSame('Synthetic Owner', $owner['full_name']);
        $this->assertSame('Open', $action['status']);
        $this->assertSame('Northwind Test Logistics', $action['account_name']);
        $this->assertArrayNotHasKey('account_zoho_id', $action);
        $this->assertSame('Active', $transport['status']);
        $this->assertSame('EUR', $transport['currency_code']);
        $this->assertArrayNotHasKey('transport_type', $transport);
    }

    public function test_quote_item_fallback_is_stable_for_numeric_equivalent_values(): void
    {
        $mapper = new QuoteItemMapper;
        $context = array_merge($this->context(), ['quote_zoho_id' => 'quote-2', 'sequence' => 3]);
        $first = $mapper->map(['product' => ['id' => 'product-3'], 'quantity' => '2.00', 'list_price' => '9.990'], $context);
        $second = $mapper->map(['product' => ['id' => 'product-3'], 'quantity' => 2.0, 'list_price' => '9.99'], $context);
        $this->assertSame($first['zoho_line_item_id'], $second['zoho_line_item_id']);
        $this->assertArrayNotHasKey('zoho_id', $first);
        $this->assertSame($first['raw_payload'], ['product' => ['id' => 'product-3'], 'quantity' => '2.00', 'list_price' => '9.990']);
    }

    public function test_quote_aggregate_does_not_treat_an_omitted_or_unparseable_subform_as_zero(): void
    {
        $mapper = new QuoteMapper;

        $omitted = $mapper->mapAggregate(['id' => 'quote-omitted'], $this->context());
        $empty = $mapper->mapAggregate(['id' => 'quote-empty', 'Quoted_Items' => []], $this->context());
        $invalid = $mapper->mapAggregate(['id' => 'quote-invalid', 'Quoted_Items' => [['Prix_Total' => 'about 100']]], $this->context());

        $this->assertFalse($omitted['quoted_items_authoritative']);
        $this->assertNull($omitted['quote']['line_items_total']);
        $this->assertFalse($omitted['quote']['line_items_total_complete']);
        $this->assertTrue($empty['quoted_items_authoritative']);
        $this->assertSame('0.00', $empty['quote']['line_items_total']);
        $this->assertTrue($empty['quote']['line_items_total_complete']);
        $this->assertTrue($invalid['quoted_items_authoritative']);
        $this->assertNull($invalid['quote']['line_items_total']);
        $this->assertFalse($invalid['quote']['line_items_total_complete']);
    }

    public function test_resolver_covers_verified_module_names(): void
    {
        $resolver = new ZohoMapperResolver;
        foreach (['Users', 'Leads', 'Accounts', 'Contacts', 'Deals', 'Quotes', 'Quoted_Items', 'Products', 'Tasks', 'Events', 'Calls', 'Notes', 'DealHistory', 'Actions_Commercials', 'Transport_international'] as $module) {
            $this->assertNotNull($resolver->forModule($module));
        }
    }

    public function test_resolver_accepts_canonical_keys_and_api_names(): void
    {
        $resolver = new ZohoMapperResolver;

        foreach ([
            'users', 'Users', 'leads', 'Leads', 'accounts', 'Accounts', 'contacts', 'Contacts',
            'deals', 'Deals', 'quotes', 'Quotes', 'products', 'Products', 'quoted_items', 'Quoted_Items', 'quoteditems',
            'tasks', 'Tasks', 'activities', 'Activities', 'events', 'Events', 'calls', 'Calls', 'notes', 'Notes',
            'deal_history', 'DealHistory', 'actions_commercials', 'Actions_Commercials', 'actionscommercials',
            'transport_international', 'Transport_international',
        ] as $module) {
            $this->assertNotNull($resolver->forModule($module), $module);
        }
    }

    public function test_decimal_columns_are_normalized_or_null_without_currency_conversion(): void
    {
        $context = $this->context();
        $deal = (new DealMapper)->map(['id' => 'd-decimal', 'Amount' => '1 234,50', 'Probability' => '30.0000'], $context);
        $product = (new ProductMapper)->map(['id' => 'p-decimal', 'Unit_Price' => 'EUR 12.3400'], $context);
        $history = (new DealStageHistoryMapper)->map(['id' => 'h-decimal', 'Potential_Name' => ['id' => 'd-decimal'], 'Amount' => 'bad', 'Probability' => '12,50', 'Expected_Revenue' => '1,500.25', 'Closing_Date' => '2026-09-30'], $context);
        $transport = (new TransportInternationalMapper)->map(['id' => 't-decimal', 'Exchange_Rate' => '1.250000'], $context);
        $quote = (new QuoteMapper)->map(['id' => 'q-decimal', 'Grand_Total' => 'USD 10.5000', 'Exchange_Rate' => 'not a number'], $context);
        $item = (new QuoteItemMapper)->map(['id' => 'i-decimal', 'quantity' => '2.5000', 'list_price' => '3.2500', 'discount' => '0.50', 'tax' => 'invalid', 'total' => '8.00'], array_merge($context, ['quote_zoho_id' => 'q-decimal']));

        $this->assertSame('1234.5', $deal['amount']);
        $this->assertSame('30', $deal['probability']);
        $this->assertSame('370.35', $deal['weighted_amount']);
        $this->assertSame('12.34', $product['unit_price']);
        $this->assertNull($history['amount']);
        $this->assertSame('12.5', $history['probability']);
        $this->assertSame('1500.25', $history['expected_revenue']);
        $this->assertSame('2026-09-30', $history['closing_date']);
        $this->assertSame('1.25', $transport['exchange_rate']);
        $this->assertSame('10.5', $quote['grand_total']);
        $this->assertNull($quote['exchange_rate']);
        $this->assertSame('2.5', $item['quantity']);
        $this->assertSame('3.25', $item['list_price']);
        $this->assertSame('3.25', $item['unit_price']);
        $this->assertSame('0.5', $item['discount']);
        $this->assertNull($item['tax']);
        $this->assertSame('8', $item['total']);
    }

    public function test_mapper_output_keys_match_the_frozen_schema_contract(): void
    {
        $data = $this->fixture();
        $context = $this->context();
        $common = ['zoho_id', 'owner_zoho_id', 'parent_zoho_id', 'zoho_created_at', 'zoho_modified_at', 'last_seen_at', 'last_synced_at', 'zoho_deleted_at', 'zoho_deletion_type', 'payload_hash', 'field_schema_hash', 'raw_payload', 'sync_batch_id'];
        $matrix = [
            [(new AccountMapper)->map($data['account'], $context), [...$common, 'name', 'phone', 'country', 'industry', 'website', 'account_type', 'parent_account_zoho_id']],
            [(new ContactMapper)->map($data['contact'], $context), [...$common, 'first_name', 'last_name', 'full_name', 'email', 'normalized_email', 'phone', 'country', 'title', 'account_zoho_id']],
            [(new LeadMapper)->map(['id' => 'l-contract'], $context), [...$common, 'first_name', 'last_name', 'full_name', 'company_name', 'email', 'normalized_email', 'phone', 'country', 'industry', 'lead_source', 'status', 'is_converted', 'account_zoho_id', 'contact_zoho_id']],
            [(new DealMapper)->map($data['deal'], $context), [...$common, 'name', 'stage', 'amount', 'currency_code', 'probability', 'weighted_amount', 'closing_date', 'lead_source', 'account_zoho_id', 'contact_zoho_id']],
            [(new ProductMapper)->map(['id' => 'p-contract'], $context), [...$common, 'name', 'product_code', 'unit_price', 'currency_code', 'product_category', 'vendor_name', 'vendor_zoho_id']],
            [(new DealStageHistoryMapper)->map($data['history'], $context), [...$common, 'deal_zoho_id', 'stage', 'previous_stage', 'occurred_at', 'amount', 'probability', 'expected_revenue', 'currency_code', 'closing_date']],
            [(new ActionsCommercialsMapper)->map($data['actions_commercials'], $context), [...$common, 'name', 'status', 'priority', 'action_at', 'due_at', 'comment', 'contact_name', 'account_name', 'prospect_name', 'phone', 'mobile']],
            [(new TransportInternationalMapper)->map($data['transport_international'], $context), [...$common, 'name', 'status', 'email', 'secondary_email', 'currency_code', 'exchange_rate']],
            [(new UserMapper)->mapOwnerLookup($data['owner'], $context), [...$common, 'full_name', 'first_name', 'last_name', 'email', 'normalized_email', 'status']],
            [(new ActivityMapper('meeting'))->map($data['activity'], $context), [...$common, 'activity_type', 'subject', 'status', 'activity_at', 'due_at', 'start_at', 'end_at', 'contact_zoho_id']],
            [(new QuoteMapper)->map($data['quote'], $context), [...$common, 'subject', 'quote_number', 'status', 'follow_up_status', 'valid_till', 'grand_total', 'sub_total', 'discount', 'tax', 'currency_code', 'exchange_rate', 'deal_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'origin', 'destination', 'transport_type', 'quote_date', 'country']],
        ];

        foreach ($matrix as [$mapped, $expected]) {
            $this->assertSame($expected, array_keys($mapped));
        }
    }
}
