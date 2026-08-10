<?php

namespace Tests\Unit\Services\Zoho\V2\Registry;

use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Tests\TestCase;

class ZohoModuleRegistryTest extends TestCase
{
    public function test_it_contains_all_verified_v2_modules_and_live_metadata_facts(): void
    {
        $registry = new ZohoModuleRegistry;

        $this->assertSame([
            'users', 'leads', 'accounts', 'contacts', 'deals', 'quotes', 'products', 'tasks', 'events', 'calls',
            'notes', 'deal_history', 'quoted_items', 'actions_commercials', 'transport_international',
        ], array_keys($registry->all()));
        $this->assertTrue($registry->get('users')->activationGated);
        $this->assertFalse($registry->get('quotes')->supportsModifiedTime);
        $this->assertSame('records_if_modified_since', $registry->get('quotes')->fetchStrategy);
        $this->assertTrue($registry->get('deals')->supportsModifiedTime);
        $this->assertSame('meeting', $registry->get('events')->activityType);
        $this->assertSame('quote_subform', $registry->get('quoted_items')->fetchStrategy);
        $this->assertSame('zoho_activities', $registry->get('notes')->table);
        foreach (['leads', 'accounts', 'contacts', 'deals', 'quotes', 'products', 'tasks', 'events', 'calls', 'notes', 'deal_history', 'actions_commercials', 'transport_international'] as $module) {
            $this->assertSame('records_if_modified_since', $registry->get($module)->fetchStrategy, "{$module} must use the live-verified conditional paging strategy.");
        }
        $this->assertStringContainsString('currently empty', $registry->get('transport_international')->activationNote);

        $leads = $registry->get('leads');
        $this->assertFalse($leads->bulkReadSupported, 'Live Bulk Read omitted all 1,041 converted Leads.');
        $this->assertSame(['fields' => 'id,Converted__s', 'converted' => 'both'], $leads->listQuery);
        $this->assertSame(['converted' => 'both'], $leads->recordQuery);
        $this->assertStringContainsString('converted', strtolower((string) $leads->bulkReadNote));
    }

    public function test_it_exposes_only_reviewed_business_fields_to_commercials(): void
    {
        $registry = new ZohoModuleRegistry;

        foreach (['leads', 'accounts', 'contacts', 'deals', 'quotes', 'tasks', 'events', 'calls', 'notes', 'quoted_items'] as $key) {
            $fields = $registry->get($key)->visibilityAllowlist;

            $this->assertNotEmpty($fields, "{$key} needs a reviewed field allowlist.");
            $this->assertNotContains('raw_payload', $fields);
            $this->assertNotContains('payload_hash', $fields);
            $this->assertNotContains('field_schema_hash', $fields);
            $this->assertNotContains('sync_run_id', $fields);
            $this->assertNotContains('error_code', $fields);
        }

        $this->assertContains('email', $registry->get('leads')->visibilityAllowlist);
        $this->assertContains('is_converted', $registry->get('leads')->visibilityAllowlist);
        $this->assertContains('amount', $registry->get('deals')->visibilityAllowlist);
        $this->assertContains('currency_code', $registry->get('quotes')->visibilityAllowlist);
        $this->assertContains('quantity', $registry->get('quoted_items')->visibilityAllowlist);
        $this->assertNotContains('zoho_id', $registry->get('quoted_items')->visibilityAllowlist);
    }

    public function test_every_promoted_primary_crm_field_is_exposed_to_commercials(): void
    {
        $registry = new ZohoModuleRegistry;

        foreach (['leads', 'accounts', 'contacts', 'deals', 'quotes'] as $module) {
            $definition = $registry->get($module);
            $hiddenPromotedFields = array_diff(
                array_keys($definition->promotedFieldSources),
                $definition->visibilityAllowlist,
            );

            $this->assertSame([], array_values($hiddenPromotedFields), "{$module} has promoted fields hidden from Explorer.");
        }
    }

    public function test_it_declares_verified_sources_for_every_locally_remapped_module(): void
    {
        $registry = new ZohoModuleRegistry;

        $this->assertSame(['Phone'], $registry->get('leads')->promotedFieldSources['phone']);
        $this->assertSame(['Phone'], $registry->get('contacts')->promotedFieldSources['phone']);

        $this->assertSame([
            'subject' => ['Subject'],
            'status' => ['Status'],
            'activity_at' => ['Activity_DateTime', 'Due_Date', 'Created_Time'],
            'due_at' => ['Due_Date'],
        ], $registry->get('tasks')->promotedFieldSources);
        $this->assertSame([
            'subject' => ['Event_Title', 'Subject'],
            'status' => ['Status'],
            'activity_at' => ['Start_DateTime', 'Activity_DateTime', 'Created_Time'],
            'start_at' => ['Start_DateTime'],
            'end_at' => ['End_DateTime'],
        ], $registry->get('events')->promotedFieldSources);
        $this->assertSame([
            'subject' => ['Subject'],
            'status' => ['Outgoing_Call_Status', 'Call_Status', 'Status'],
            'activity_at' => ['Call_Start_Time', 'Activity_DateTime', 'Created_Time'],
            'start_at' => ['Call_Start_Time'],
        ], $registry->get('calls')->promotedFieldSources);
        $this->assertSame([
            'subject' => ['Note_Title', 'Subject'],
            'activity_at' => ['Created_Time'],
        ], $registry->get('notes')->promotedFieldSources);
        $this->assertSame([
            'moved_to_stage' => ['Moved_To__s'],
            'stage_duration_days' => ['Stage_Duration_Calendar_Days'],
        ], $registry->get('deal_history')->promotedFieldSources);
    }
}
