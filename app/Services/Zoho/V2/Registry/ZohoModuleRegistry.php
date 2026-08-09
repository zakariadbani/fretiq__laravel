<?php

namespace App\Services\Zoho\V2\Registry;

use InvalidArgumentException;

/** Verified module facts captured during the 2026-08-09 live V2 inventory. */
final class ZohoModuleRegistry
{
    /** @var array<string, ModuleDefinition>|null */
    private ?array $definitions = null;

    /** @return array<string, ModuleDefinition> */
    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function get(string $key): ModuleDefinition
    {
        $definition = $this->all()[$key] ?? null;

        if (! $definition) {
            throw new InvalidArgumentException("Unknown Zoho CRM module [{$key}].");
        }

        return $definition;
    }

    /** @return array<string, ModuleDefinition> */
    private function build(): array
    {
        $crm = 'App\\Models\\Zoho\\';
        // Commercial-facing fields must be both reviewed business fields and canonical persisted columns.
        // Do not add raw payloads, linkage IDs, tombstone/sync state, or framework timestamps here.
        $leadFields = ['zoho_id', 'full_name', 'company_name', 'email', 'phone', 'owner_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'status', 'is_converted', 'lead_source', 'country', 'industry', 'zoho_created_at', 'zoho_modified_at'];
        $accountFields = ['zoho_id', 'name', 'phone', 'website', 'owner_zoho_id', 'parent_account_zoho_id', 'account_type', 'industry', 'country', 'zoho_created_at', 'zoho_modified_at'];
        $contactFields = ['zoho_id', 'full_name', 'first_name', 'last_name', 'email', 'phone', 'owner_zoho_id', 'account_zoho_id', 'title', 'country', 'zoho_created_at', 'zoho_modified_at'];
        $dealFields = ['zoho_id', 'name', 'owner_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'stage', 'lead_source', 'amount', 'probability', 'weighted_amount', 'currency_code', 'closing_date', 'zoho_created_at', 'zoho_modified_at'];
        $quoteFields = ['zoho_id', 'subject', 'quote_number', 'owner_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'deal_zoho_id', 'status', 'discount', 'tax', 'line_items_total', 'line_items_total_complete', 'currency_code', 'exchange_rate', 'valid_till', 'quote_date', 'follow_up_status', 'origin', 'destination', 'transport_type', 'country', 'zoho_created_at', 'zoho_modified_at'];
        $productFields = ['zoho_id', 'name', 'product_code', 'unit_price', 'currency_code', 'product_category', 'vendor_name', 'vendor_zoho_id', 'owner_zoho_id', 'zoho_created_at', 'zoho_modified_at'];
        $activityFields = ['zoho_id', 'activity_type', 'subject', 'owner_zoho_id', 'parent_zoho_id', 'contact_zoho_id', 'status', 'activity_at', 'due_at', 'start_at', 'end_at', 'zoho_created_at', 'zoho_modified_at'];
        $quoteItemFields = ['zoho_quote_id', 'zoho_line_item_id', 'product_zoho_id', 'sequence', 'product_name', 'description', 'unit_of_measure', 'quantity', 'list_price', 'unit_price', 'discount', 'tax', 'total', 'currency_code'];
        $historyFields = ['zoho_id', 'owner_zoho_id', 'parent_zoho_id', 'stage', 'previous_stage', 'occurred_at', 'amount', 'probability', 'expected_revenue', 'currency_code', 'closing_date', 'zoho_created_at', 'zoho_modified_at'];
        $quoteStatusHistoryFields = ['zoho_id', 'owner_zoho_id', 'parent_zoho_id', 'status', 'previous_status', 'occurred_at', 'zoho_created_at', 'zoho_modified_at'];
        $actionsCommercialFields = ['zoho_id', 'name', 'owner_zoho_id', 'parent_zoho_id', 'status', 'priority', 'action_at', 'due_at', 'contact_name', 'account_name', 'prospect_name', 'phone', 'mobile', 'zoho_created_at', 'zoho_modified_at'];
        $transportInternationalFields = ['zoho_id', 'name', 'owner_zoho_id', 'status', 'email', 'secondary_email', 'currency_code', 'exchange_rate', 'zoho_created_at', 'zoho_modified_at'];
        $definitions = [
            new ModuleDefinition('users', 'Users', $crm.'ZohoUser', 'zoho_users', [], 'users', true, null, null, [], true, 'OAuth scope ZohoCRM.users.READ is not granted.'),
            new ModuleDefinition(
                key: 'leads',
                apiName: 'Leads',
                modelClass: $crm.'ZohoLead',
                table: 'zoho_leads',
                dependencies: ['users'],
                fetchStrategy: 'records_if_modified_since',
                visibilityAllowlist: $leadFields,
                bulkReadSupported: false,
                listQuery: ['fields' => 'id,Converted__s', 'converted' => 'both'],
                recordQuery: ['converted' => 'both'],
                bulkReadNote: 'Live 2026-08-09 export returned 3,810 active Leads and omitted all 1,041 converted Leads; Records API converted=both is required.',
            ),
            new ModuleDefinition('accounts', 'Accounts', $crm.'ZohoAccount', 'zoho_accounts', ['users'], 'records_if_modified_since', false, null, null, $accountFields, false, null, true),
            new ModuleDefinition('contacts', 'Contacts', $crm.'ZohoContact', 'zoho_contacts', ['accounts', 'users'], 'records_if_modified_since', false, null, null, $contactFields, false, null, true),
            new ModuleDefinition('deals', 'Deals', $crm.'ZohoDeal', 'zoho_deals', ['accounts', 'contacts', 'users'], 'records_if_modified_since', true, null, null, $dealFields, false, null, true),
            new ModuleDefinition('quotes', 'Quotes', $crm.'ZohoQuote', 'zoho_quotes', ['accounts', 'contacts', 'deals', 'users'], 'records_if_modified_since', false, null, null, $quoteFields, false, null, true),
            new ModuleDefinition('products', 'Products', $crm.'ZohoProduct', 'zoho_products', ['users'], 'records_if_modified_since', true, null, null, $productFields, false, null, true),
            new ModuleDefinition('tasks', 'Tasks', $crm.'ZohoActivity', 'zoho_activities', ['users'], 'records_if_modified_since', true, 'task', 'Tasks', $activityFields, false, null, true),
            new ModuleDefinition('events', 'Events', $crm.'ZohoActivity', 'zoho_activities', ['users'], 'records_if_modified_since', true, 'meeting', 'Events', $activityFields, false, null, true),
            new ModuleDefinition('calls', 'Calls', $crm.'ZohoActivity', 'zoho_activities', ['users'], 'records_if_modified_since', true, 'call', 'Calls', $activityFields, false, null, true),
            new ModuleDefinition('notes', 'Notes', $crm.'ZohoActivity', 'zoho_activities', ['users'], 'records_if_modified_since', true, 'note', 'Notes', $activityFields),
            new ModuleDefinition('deal_history', 'DealHistory', $crm.'ZohoDealStageHistory', 'zoho_deal_stage_history', ['deals'], 'records_if_modified_since', true, null, null, $historyFields),
            new ModuleDefinition('quoted_items', 'Quoted_Items', $crm.'ZohoQuoteItem', 'zoho_quote_items', ['quotes', 'products'], 'quote_subform', true, null, null, $quoteItemFields),
            new ModuleDefinition('actions_commercials', 'Actions_Commercials', $crm.'ZohoActionsCommercial', 'zoho_actions_commercials', ['accounts', 'contacts', 'deals', 'users'], 'records_if_modified_since', true, null, null, $actionsCommercialFields, false, null, true),
            new ModuleDefinition('transport_international', 'Transport_international', $crm.'ZohoTransportInternational', 'zoho_transport_international', ['accounts', 'contacts', 'users'], 'records_if_modified_since', false, null, null, $transportInternationalFields, false, 'Verified readable but currently empty (204 list response).', true),
        ];

        return collect($definitions)->keyBy(fn (ModuleDefinition $definition) => $definition->key)->all();
    }
}
