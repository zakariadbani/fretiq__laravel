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
        $leadFields = ['zoho_id', 'full_name', 'company_name', 'email', 'phone', 'mobile', 'secondary_phone', 'title', 'client_type', 'transport_type', 'language', 'address', 'city', 'website', 'incoterm', 'destination', 'volume', 'competitor', 'origin_destination', 'observation', 'email_opt_out', 'unsubscribed_mode', 'unsubscribed_at', 'last_activity_at', 'tags', 'owner_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'converted_deal_zoho_id', 'converted_at', 'status', 'is_converted', 'country', 'industry', 'zoho_created_at', 'zoho_modified_at'];
        $accountFields = ['zoho_id', 'name', 'phone', 'website', 'owner_zoho_id', 'parent_account_zoho_id', 'account_type', 'active_status', 'account_status', 'industry', 'country', 'address', 'city', 'language', 'commercial_name', 'assignment_type', 'accounting_number', 'ice', 'tax_id', 'trade_register', 'cnss', 'business_tax_number', 'trade_register_center', 'payment_mode', 'transport_type', 'volume', 'competitor', 'origin_destination', 'observation', 'logistics_manager_zoho_id', 'last_activity_at', 'tags', 'zoho_created_at', 'zoho_modified_at'];
        $contactFields = ['zoho_id', 'full_name', 'first_name', 'last_name', 'email', 'phone', 'mobile', 'owner_zoho_id', 'account_zoho_id', 'title', 'company_name', 'country', 'address', 'city', 'postal_code', 'language', 'lead_source', 'email_opt_out', 'email_opened', 'link_clicked', 'unsubscribed_mode', 'unsubscribed_at', 'last_activity_at', 'description', 'tags', 'salutation', 'zoho_created_at', 'zoho_modified_at'];
        $dealFields = ['zoho_id', 'name', 'owner_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'stage', 'amount', 'currency_code', 'closing_date', 'exchange_rate', 'pipeline', 'stackability', 'last_activity_at', 'stage_modified_at', 'tags', 'origin', 'destination', 'incoterm', 'cargo_description', 'gross_weight', 'volume', 'quantity', 'dimensions', 'departure_frequency', 'package_type', 'quote_type', 'transport_type', 'transit_time', 'expires_on', 'description', 'zoho_created_at', 'zoho_modified_at'];
        $quoteFields = ['zoho_id', 'subject', 'quote_number', 'owner_zoho_id', 'account_zoho_id', 'contact_zoho_id', 'deal_zoho_id', 'status', 'line_items_total', 'line_items_total_complete', 'currency_code', 'exchange_rate', 'valid_till', 'quote_date', 'follow_up_status', 'origin', 'destination', 'transport_type', 'country', 'incoterms', 'gross_weight', 'volume', 'quantity_text', 'package_type', 'transit_time_days', 'equipment_type', 'free_time', 'stackability', 'dangerous_goods_status', 'un_number', 'dangerous_goods_class', 'dimensions', 'loading_meters', 'last_activity_at', 'tags', 'zoho_created_at', 'zoho_modified_at'];
        $productFields = ['zoho_id', 'name', 'product_code', 'unit_price', 'currency_code', 'product_category', 'vendor_name', 'vendor_zoho_id', 'owner_zoho_id', 'zoho_created_at', 'zoho_modified_at'];
        $activityFields = ['zoho_id', 'activity_type', 'subject', 'owner_zoho_id', 'parent_zoho_id', 'contact_zoho_id', 'status', 'activity_at', 'due_at', 'start_at', 'end_at', 'zoho_created_at', 'zoho_modified_at'];
        $quoteItemFields = ['zoho_quote_id', 'zoho_line_item_id', 'product_zoho_id', 'sequence', 'product_name', 'description', 'unit_of_measure', 'quantity', 'list_price', 'unit_price', 'discount', 'tax', 'total', 'currency_code'];
        $historyFields = ['zoho_id', 'owner_zoho_id', 'parent_zoho_id', 'stage', 'previous_stage', 'moved_to_stage', 'stage_duration_days', 'occurred_at', 'amount', 'probability', 'expected_revenue', 'currency_code', 'closing_date', 'zoho_created_at', 'zoho_modified_at'];
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
                promotedFieldSources: ['phone' => ['Phone'], 'industry' => ['Secteur_Activit'], 'account_zoho_id' => ['Converted_Account'], 'contact_zoho_id' => ['Converted_Contact'], 'converted_deal_zoho_id' => ['Converted_Deal'], 'converted_at' => ['Converted_Date_Time'], 'title' => ['Intitul_de_Poste'], 'client_type' => ['Type_de_client'], 'transport_type' => ['Type_de_transport_utilis'], 'language' => ['Langue'], 'address' => ['Adresse'], 'city' => ['City'], 'website' => ['Website'], 'incoterm' => ['Incoterm'], 'destination' => ['Destination'], 'volume' => ['Volume'], 'competitor' => ['Prestataire_Concurrent'], 'origin_destination' => ['Provenance_Destination'], 'observation' => ['Observation'], 'email_opt_out' => ['Email_Opt_Out'], 'unsubscribed_mode' => ['Unsubscribed_Mode'], 'unsubscribed_at' => ['Unsubscribed_Time'], 'last_activity_at' => ['Last_Activity_Time'], 'tags' => ['Tag'], 'mobile' => ['Mobile'], 'secondary_phone' => ['T_l_phone_2']],
                multiValueFields: ['tags'],
            ),
            new ModuleDefinition('accounts', 'Accounts', $crm.'ZohoAccount', 'zoho_accounts', ['users'], 'records_if_modified_since', false, null, null, $accountFields, false, null, true, [], [], null, ['industry' => ['Secteur_Activit'], 'account_type' => ['Type_de_client'], 'active_status' => ['Actif'], 'account_status' => ['Statut_du_Compte'], 'address' => ['Adresse'], 'city' => ['Billing_City'], 'language' => ['Langue'], 'commercial_name' => ['Commercial'], 'assignment_type' => ['Type_Affectation_Commercial'], 'accounting_number' => ['Num_ro_Comptable'], 'ice' => ['ICE'], 'tax_id' => ['I_F'], 'trade_register' => ['R_C'], 'cnss' => ['CNSS'], 'business_tax_number' => ['Patente'], 'trade_register_center' => ['Centre_R_C'], 'payment_mode' => ['Mode_Paiement'], 'transport_type' => ['Type_de_transport_utilis'], 'volume' => ['Volume'], 'competitor' => ['Prestataire_Concurrent'], 'origin_destination' => ['Provenance_Destination'], 'observation' => ['Observation'], 'logistics_manager_zoho_id' => ['Nom_de_Responsable_Logistique'], 'last_activity_at' => ['Last_Activity_Time'], 'tags' => ['Tag']], ['tags']),
            new ModuleDefinition('contacts', 'Contacts', $crm.'ZohoContact', 'zoho_contacts', ['accounts', 'users'], 'records_if_modified_since', false, null, null, $contactFields, false, null, true, [], [], null, ['phone' => ['Phone'], 'title' => ['Intitul_de_Poste'], 'company_name' => ['Nom_du_Soci_t'], 'address' => ['Adresse'], 'city' => ['Mailing_City'], 'postal_code' => ['Mailing_Zip'], 'language' => ['Langue'], 'lead_source' => ['Lead_Source'], 'email_opt_out' => ['Email_Opt_Out'], 'email_opened' => ['Email_Ouvert'], 'link_clicked' => ['Lien_Cliqu'], 'unsubscribed_mode' => ['Unsubscribed_Mode'], 'unsubscribed_at' => ['Unsubscribed_Time'], 'last_activity_at' => ['Last_Activity_Time'], 'description' => ['Description'], 'tags' => ['Tag'], 'mobile' => ['Mobile'], 'salutation' => ['Salutation']], ['tags']),
            new ModuleDefinition('deals', 'Deals', $crm.'ZohoDeal', 'zoho_deals', ['accounts', 'contacts', 'users'], 'records_if_modified_since', true, null, null, $dealFields, false, null, true, [], [], null, ['exchange_rate' => ['Exchange_Rate'], 'pipeline' => ['Pipeline'], 'stackability' => ['G_rbable'], 'last_activity_at' => ['Last_Activity_Time'], 'stage_modified_at' => ['Stage_Modified_Time'], 'tags' => ['Tag'], 'origin' => ['Origine'], 'destination' => ['Destination'], 'incoterm' => ['Incoterm'], 'cargo_description' => ['Marchandise'], 'gross_weight' => ['P_Brut'], 'volume' => ['Volume'], 'quantity' => ['Quantit'], 'dimensions' => ['Dimensions_CM'], 'departure_frequency' => ['Fr_quence_D_part'], 'package_type' => ['Type_de_Colis'], 'quote_type' => ['Type_de_Cotation'], 'transport_type' => ['Type_de_Transpot'], 'transit_time' => ['Transite_Time_J'], 'expires_on' => ['Date_d_expiration'], 'description' => ['Description']], ['tags']),
            new ModuleDefinition('quotes', 'Quotes', $crm.'ZohoQuote', 'zoho_quotes', ['accounts', 'contacts', 'deals', 'users'], 'records_if_modified_since', false, null, null, $quoteFields, false, null, true, [], [], null, ['transport_type' => ['Type_de_Transport'], 'incoterms' => ['Incoterm1'], 'gross_weight' => ['P_Brut'], 'volume' => ['Volume'], 'quantity_text' => ['Quantit'], 'package_type' => ['Type_de_Colis'], 'transit_time_days' => ['Transit_Time_J'], 'equipment_type' => ['Type_d_quipement'], 'free_time' => ['Franchise'], 'stackability' => ['G_rbable'], 'dangerous_goods_status' => ['Marchandise_dangereuse'], 'un_number' => ['UN'], 'dangerous_goods_class' => ['La_classe'], 'dimensions' => ['Dimensions_CM'], 'loading_meters' => ['Metre_de_Planche'], 'last_activity_at' => ['Last_Activity_Time'], 'tags' => ['Tag']], ['transport_type', 'incoterms', 'stackability', 'tags']),
            new ModuleDefinition('products', 'Products', $crm.'ZohoProduct', 'zoho_products', ['users'], 'records_if_modified_since', true, null, null, $productFields, false, null, true),
            new ModuleDefinition(
                key: 'tasks', apiName: 'Tasks', modelClass: $crm.'ZohoActivity', table: 'zoho_activities',
                dependencies: ['users'], fetchStrategy: 'records_if_modified_since', supportsModifiedTime: true,
                activityType: 'task', submodule: 'Tasks', visibilityAllowlist: $activityFields, bulkReadSupported: true,
                promotedFieldSources: ['subject' => ['Subject'], 'status' => ['Status'], 'activity_at' => ['Activity_DateTime', 'Due_Date', 'Created_Time'], 'due_at' => ['Due_Date']],
            ),
            new ModuleDefinition(
                key: 'events', apiName: 'Events', modelClass: $crm.'ZohoActivity', table: 'zoho_activities',
                dependencies: ['users'], fetchStrategy: 'records_if_modified_since', supportsModifiedTime: true,
                activityType: 'meeting', submodule: 'Events', visibilityAllowlist: $activityFields, bulkReadSupported: true,
                promotedFieldSources: ['subject' => ['Event_Title', 'Subject'], 'status' => ['Status', 'Check_In_Status', 'Record_Status__s'], 'activity_at' => ['Start_DateTime', 'Activity_DateTime', 'Created_Time'], 'start_at' => ['Start_DateTime'], 'end_at' => ['End_DateTime']],
            ),
            new ModuleDefinition(
                key: 'calls', apiName: 'Calls', modelClass: $crm.'ZohoActivity', table: 'zoho_activities',
                dependencies: ['users'], fetchStrategy: 'records_if_modified_since', supportsModifiedTime: true,
                activityType: 'call', submodule: 'Calls', visibilityAllowlist: $activityFields, bulkReadSupported: true,
                promotedFieldSources: ['subject' => ['Subject'], 'status' => ['Outgoing_Call_Status', 'Call_Status', 'Status'], 'activity_at' => ['Call_Start_Time', 'Activity_DateTime', 'Created_Time'], 'start_at' => ['Call_Start_Time']],
            ),
            new ModuleDefinition(
                key: 'notes', apiName: 'Notes', modelClass: $crm.'ZohoActivity', table: 'zoho_activities',
                dependencies: ['users'], fetchStrategy: 'records_if_modified_since', supportsModifiedTime: true,
                activityType: 'note', submodule: 'Notes', visibilityAllowlist: $activityFields,
                promotedFieldSources: ['subject' => ['Note_Title', 'Subject'], 'activity_at' => ['Created_Time']],
            ),
            new ModuleDefinition(
                key: 'deal_history', apiName: 'DealHistory', modelClass: $crm.'ZohoDealStageHistory', table: 'zoho_deal_stage_history',
                dependencies: ['deals'], fetchStrategy: 'records_if_modified_since', supportsModifiedTime: true,
                visibilityAllowlist: $historyFields,
                promotedFieldSources: ['moved_to_stage' => ['Moved_To__s'], 'stage_duration_days' => ['Stage_Duration_Calendar_Days']],
            ),
            new ModuleDefinition('quoted_items', 'Quoted_Items', $crm.'ZohoQuoteItem', 'zoho_quote_items', ['quotes', 'products'], 'quote_subform', true, null, null, $quoteItemFields),
            new ModuleDefinition('actions_commercials', 'Actions_Commercials', $crm.'ZohoActionsCommercial', 'zoho_actions_commercials', ['accounts', 'contacts', 'deals', 'users'], 'records_if_modified_since', true, null, null, $actionsCommercialFields, false, null, true),
            new ModuleDefinition('transport_international', 'Transport_international', $crm.'ZohoTransportInternational', 'zoho_transport_international', ['accounts', 'contacts', 'users'], 'records_if_modified_since', false, null, null, $transportInternationalFields, false, 'Verified readable but currently empty (204 list response).', true),
        ];

        return collect($definitions)->keyBy(fn (ModuleDefinition $definition) => $definition->key)->all();
    }
}
