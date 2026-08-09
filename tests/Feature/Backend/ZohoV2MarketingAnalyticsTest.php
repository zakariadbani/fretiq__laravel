<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Services\Zoho\V2\Marketing\MarketingAnalyticsQuery;
use App\Services\Zoho\V2\Marketing\MarketingPeriod;
use App\Services\Zoho\V2\Marketing\ZohoMarketingAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ZohoV2MarketingAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Role::findOrCreate('admin');
        Role::findOrCreate('commercial');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->commercial = User::factory()->create();
        $this->commercial->assignRole('commercial');
        DB::table('zoho_user_mappings')->insert(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_portfolio_scope_forged_commercial_filter_and_currency_buckets_are_safe(): void
    {
        $this->mirror('zoho_deals', 'deal-a', 'owner-a', ['stage' => 'Qualification', 'amount' => 10, 'weighted_amount' => 5, 'currency_code' => 'EUR']);
        $this->mirror('zoho_deals', 'deal-b', 'owner-b', ['stage' => 'Qualification', 'amount' => 20, 'weighted_amount' => 10, 'currency_code' => 'MAD']);
        $this->mirror('zoho_quotes', 'quote-eur', 'owner-a', ['line_items_total' => 50, 'line_items_total_complete' => true, 'currency_code' => 'EUR', 'follow_up_status' => 'Affaire gagnée']);
        $this->mirror('zoho_quotes', 'quote-mad', 'owner-a', ['line_items_total' => 100, 'line_items_total_complete' => true, 'currency_code' => 'MAD']);
        $this->mirror('zoho_quotes', 'quote-usd', 'owner-a', ['line_items_total' => 20, 'line_items_total_complete' => true, 'currency_code' => 'USD']);
        $this->mirror('zoho_quotes', 'quote-unknown', 'owner-a', ['line_items_total' => null, 'line_items_total_complete' => false, 'currency_code' => null]);

        $result = $this->analyse($this->commercial, ['commercial' => 'owner-b']);
        $this->assertSame(1, $result['kpis']['pipeline']['count']);
        $this->assertSame(['EUR' => 10.0], $result['kpis']['pipeline']['amount_by_currency']);
        $this->assertSame(0, $result['kpis']['pipeline']['amount_unknown_count']);
        $this->assertSame(['EUR' => 50.0, 'MAD' => 100.0, 'USD' => 20.0], $result['kpis']['quotes']['value_by_currency']);
        $this->assertSame(['EUR' => 50.0], $result['kpis']['quotes']['won_value_by_currency']);
        $this->assertSame(1, $result['kpis']['quotes']['unknown_value_count']);
        $this->assertArrayNotHasKey('total', $result['kpis']['quotes']);
    }

    public function test_missing_value_and_missing_currency_coverage_are_independent(): void
    {
        $this->mirror('zoho_deals', 'deal-complete', 'owner-a', ['stage' => 'Qualification', 'amount' => 25, 'weighted_amount' => 10, 'currency_code' => 'EUR']);
        $this->mirror('zoho_deals', 'deal-no-value', 'owner-a', ['stage' => 'Qualification', 'amount' => null, 'weighted_amount' => null, 'currency_code' => 'EUR']);
        $this->mirror('zoho_deals', 'deal-no-currency', 'owner-a', ['stage' => 'Qualification', 'amount' => 100, 'weighted_amount' => 50, 'currency_code' => '   ']);
        $this->mirror('zoho_deals', 'deal-neither', 'owner-a', ['stage' => 'Qualification', 'amount' => null, 'weighted_amount' => null, 'currency_code' => null]);
        $this->mirror('zoho_deals', 'deal-won-no-value', 'owner-a', ['stage' => 'Closed Won', 'amount' => null, 'currency_code' => 'EUR']);
        $this->mirror('zoho_deals', 'deal-won-no-currency', 'owner-a', ['stage' => 'Closed Won', 'amount' => 200, 'currency_code' => '   ']);
        $this->mirror('zoho_quotes', 'quote-no-value', 'owner-a', ['line_items_total' => null, 'line_items_total_complete' => false, 'currency_code' => 'EUR']);
        $this->mirror('zoho_quotes', 'quote-no-currency', 'owner-a', ['line_items_total' => 100, 'line_items_total_complete' => true, 'currency_code' => '   ']);
        $this->mirror('zoho_quotes', 'quote-won-no-value', 'owner-a', ['follow_up_status' => 'Affaire gagnée', 'line_items_total' => null, 'line_items_total_complete' => false, 'currency_code' => 'EUR']);
        $this->mirror('zoho_quotes', 'quote-won-no-currency', 'owner-a', ['follow_up_status' => 'Affaire gagnée', 'line_items_total' => 200, 'line_items_total_complete' => true, 'currency_code' => null]);

        $result = $this->analyse($this->commercial);
        $pipeline = $result['kpis']['pipeline'];
        $this->assertSame(4, $pipeline['count']);
        $this->assertSame(1, $pipeline['amount_known_count']);
        $this->assertSame(3, $pipeline['amount_unknown_count']);
        $this->assertSame(2, $pipeline['amount_missing_value_count']);
        $this->assertSame(2, $pipeline['amount_missing_currency_count']);
        $this->assertSame(1, $pipeline['weighted_known_count']);
        $this->assertSame(3, $pipeline['weighted_unknown_count']);
        $this->assertSame(2, $pipeline['weighted_missing_value_count']);
        $this->assertSame(2, $pipeline['weighted_missing_currency_count']);
        $this->assertSame(['EUR' => 25.0], $pipeline['amount_by_currency']);
        $this->assertSame(['EUR' => 10.0], $pipeline['weighted_by_currency']);
        $this->assertSame(1, $result['kpis']['deal_outcomes']['won_missing_value_count']);
        $this->assertSame(1, $result['kpis']['deal_outcomes']['won_missing_currency_count']);
        $this->assertSame(0, $result['kpis']['deal_outcomes']['won_known_value_count']);
        $this->assertSame(2, $result['kpis']['deal_outcomes']['won_unknown_value_count']);

        $quotes = $result['kpis']['quotes'];
        $this->assertSame(2, $quotes['missing_value_count']);
        $this->assertSame(2, $quotes['missing_currency_count']);
        $this->assertSame(0, $quotes['known_value_count']);
        $this->assertSame(4, $quotes['unknown_value_count']);
        $this->assertSame(1, $quotes['won_missing_value_count']);
        $this->assertSame(1, $quotes['won_missing_currency_count']);
        $this->assertSame(0, $quotes['won_known_value_count']);
        $this->assertSame(2, $quotes['won_unknown_value_count']);
        $this->assertSame([], $quotes['value_by_currency']);
        $this->assertSame([], $quotes['won_value_by_currency']);
    }

    public function test_quote_decision_time_uses_status_history_not_missing_modified_time(): void
    {
        $this->mirror('zoho_quotes', 'quote-history', 'owner-a', ['quote_date' => '2026-08-01', 'follow_up_status' => 'Affaire gagnée']);
        DB::table('zoho_quote_status_history')->insert(['zoho_id' => 'history-1', 'quote_zoho_id' => 'quote-history', 'status' => 'Affaire gagnée', 'previous_status' => 'Devis confirmé', 'payload_hash' => str_repeat('a', 64), 'raw_payload' => '{}', 'occurred_at' => '2026-08-06 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $quotes = $this->analyse($this->commercial)['kpis']['quotes'];
        $this->assertSame(5.0, $quotes['median_decision_days']);
        $this->assertSame(1, $quotes['terminal_decision_known_count']);
        $this->assertSame(0, $quotes['terminal_decision_unknown_count']);
    }

    public function test_initial_terminal_quote_snapshot_is_not_a_decision_transition(): void
    {
        $this->mirror('zoho_quotes', 'quote-snapshot', 'owner-a', ['quote_date' => '2026-08-01', 'follow_up_status' => 'Affaire gagnée']);
        $this->quoteHistory('snapshot-terminal', 'quote-snapshot', 'Affaire gagnée', '2026-08-01 00:00:00');

        $result = $this->analyse($this->commercial);

        $this->assertNull($result['kpis']['quotes']['median_decision_days']);
        $this->assertSame(0, $result['kpis']['quotes']['terminal_decision_known_count']);
        $this->assertSame(1, $result['kpis']['quotes']['terminal_decision_unknown_count']);
        $this->assertSame([], $result['funnels']['zoho']['transitions']['quotes']);
    }

    public function test_cache_isolated_per_user_and_identity_coverage_does_not_expose_raw_zoho_ids_or_credit_revenue(): void
    {
        $this->mirror('zoho_leads', 'lead-a', 'owner-a');
        $this->mirror('zoho_leads', 'lead-b', 'owner-b');
        [, $contactA] = $this->fretiqContact($this->commercial);
        [, $contactB] = $this->fretiqContact($this->admin);
        DB::table('zoho_marketing_links')->insert(['zoho_module' => 'Leads', 'zoho_record_id' => 'lead-a', 'fretiq_entity_type' => 'contact', 'fretiq_entity_id' => $contactA, 'match_type' => 'exact_email', 'match_key_hash' => str_repeat('b', 64), 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('zoho_marketing_links')->insert(['zoho_module' => 'Leads', 'zoho_record_id' => 'lead-b', 'fretiq_entity_type' => 'contact', 'fretiq_entity_id' => $contactB, 'match_type' => 'exact_email', 'match_key_hash' => str_repeat('c', 64), 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();
        $commercial = $this->analyse($this->commercial);
        $admin = $this->analyse($this->admin);
        $this->assertTrue($commercial['meta']['causal_attribution'] === false);
        $this->assertSame(1, $commercial['identity_coverage']['count']);
        $this->assertSame(2, $admin['identity_coverage']['count']);
        $this->assertStringNotContainsString('owner-a', json_encode($commercial, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('lead-a', json_encode($commercial, JSON_THROW_ON_ERROR));
    }

    public function test_dst_database_bounds_are_utc_instants(): void
    {
        $period = MarketingPeriod::fromInput(['preset' => 'custom', 'from' => '2026-03-29', 'to' => '2026-03-29']);
        [$from, $to] = $period->databaseBounds();
        $this->assertSame('2026-03-28 23:00:00', $from->toDateTimeString());
        $this->assertSame('2026-03-29 21:59:59', $to->toDateTimeString());
    }

    public function test_every_requested_filter_declares_where_it_applies_and_unmapped_commercial_is_empty(): void
    {
        $unmapped = User::factory()->create();
        $unmapped->assignRole('commercial');
        $result = $this->analyse($unmapped, ['source' => 'zoho', 'campaign' => '99', 'country' => 'FR', 'sector' => 'transport', 'transport' => 'Air', 'client_type' => 'client', 'lead_source' => 'Web', 'currency' => 'EUR']);
        $this->assertTrue($result['scope']['mapping_required']);
        $this->assertFalse($result['scope']['crm_visible']);
        $this->assertSame(['crm_quotes'], $result['filter_applicability']['transport']['applies_to']);
        $this->assertSame(['campaign', 'demandes', 'matched_touches'], $result['filter_applicability']['campaign']['applies_to']);
        $this->assertSame(['campaign', 'demandes', 'discovery', 'crm_accounts', 'matched_touches'], $result['filter_applicability']['client_type']['applies_to']);
        $this->assertSame(['crm_deals', 'crm_quotes'], $result['filter_applicability']['currency']['applies_to']);
        foreach (['source', 'sector', 'lead_source'] as $filter) {
            $partial = $result['filter_applicability'][$filter]['partially_applies_to']['identity_coverage'];
            $this->assertSame(['crm_leads'], $partial['applies_to_modules']);
            $this->assertSame(['crm_contacts'], $partial['not_applicable_to_modules']);
        }
        $this->assertContains('matched_touches', $result['filter_applicability']['lead_source']['ignored_by']);
        $this->assertContains('identity_coverage', $result['filter_applicability']['country']['applies_to']);
    }

    public function test_demandes_use_captured_at_and_apply_real_campaign_and_prospect_joins(): void
    {
        [, $contactId] = $this->fretiqContact($this->commercial, [
            'company_source' => 'zoho', 'contact_source' => 'zoho', 'country' => 'FR',
            'sector' => 'Transport', 'relationship' => 'client',
        ]);
        $campaignId = $this->campaign('Campagne ciblée');
        $this->demande($contactId, $campaignId, '2026-08-05 09:00:00', '2026-06-01 09:00:00');
        $this->demande($contactId, $campaignId, '2026-06-01 09:00:00', '2026-08-05 09:00:00');

        $filters = ['campaign' => (string) $campaignId, 'source' => 'zoho', 'country' => 'FR', 'sector' => 'Transport', 'client_type' => 'client'];
        $result = $this->analyse($this->commercial, $filters);

        $this->assertSame(1, $result['campaign']['counts']['demandes']);
        $this->assertSame(1, $result['trends']['demandes']['2026-08-05']);
        $this->assertFalse($result['meta']['causal_attribution']);
        $this->assertStringContainsString('sans attribution', $result['meta']['demande_label']);
        $this->assertSame(0, $this->analyse($this->commercial, [...$filters, 'country' => 'MA'])['campaign']['counts']['demandes']);
    }

    public function test_campaign_funnel_counts_only_canonical_sent_lifecycle_rows(): void
    {
        $campaignId = $this->campaign('Cycle de vie');
        $runId = DB::table('campaign_runs')->insertGetId([
            'campaign_id' => $campaignId, 'occurrence_key' => 'lifecycle-run', 'run_at' => '2026-08-05 10:00:00',
            'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $states = [
            ['sent', null, null, null, null, null],
            ['queued', null, null, null, null, null],
            ['skipped', null, null, null, null, null],
            ['opened', null, '2026-08-05 10:05:00', null, null, null],
            ['bounced', null, null, null, null, '2026-08-05 10:06:00'],
            ['replied', null, null, null, '2026-08-05 10:07:00', null],
            ['queued', '2026-08-05 10:01:00', null, null, null, null],
        ];
        foreach ($states as $index => [$status, $sentAt, $openedAt, $clickedAt, $repliedAt, $bouncedAt]) {
            [, $contactId] = $this->fretiqContact($this->commercial);
            DB::table('campaign_recipients')->insert([
                'campaign_run_id' => $runId, 'contact_id' => $contactId, 'status' => $status,
                'sent_at' => $sentAt, 'opened_at' => $openedAt, 'clicked_at' => $clickedAt,
                'replied_at' => $repliedAt, 'bounced_at' => $bouncedAt,
                'provider_message_id' => 'message-'.$index, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $result = $this->analyse($this->commercial);
        $counts = $result['campaign']['counts'];
        $this->assertSame(5, $counts['sent']);
        $this->assertSame(2, $counts['delivered']);
        $this->assertSame(1, $counts['opened']);
        $this->assertSame(1, $counts['replied']);
        $this->assertSame(0, $result['matched_touches']['count']);
        $this->assertSame([], $result['matched_touches']['items']);
        $this->assertStringNotContainsString('provider_message_id', json_encode($result['matched_touches'], JSON_THROW_ON_ERROR));
    }

    public function test_all_persisted_boundaries_use_utc_across_the_dst_transition(): void
    {
        $period = MarketingPeriod::fromInput(['preset' => 'custom', 'from' => '2026-03-29', 'to' => '2026-03-29']);
        $this->mirror('zoho_accounts', 'account-inside', 'owner-a', ['zoho_created_at' => '2026-03-28 23:00:00']);
        $this->mirror('zoho_accounts', 'account-before', 'owner-a', ['zoho_created_at' => '2026-03-28 22:59:59']);
        $this->mirror('zoho_contacts', 'contact-inside', 'owner-a', ['zoho_created_at' => '2026-03-29 21:59:59', 'country' => 'FR']);
        $this->mirror('zoho_contacts', 'contact-after', 'owner-a', ['zoho_created_at' => '2026-03-29 22:00:00', 'country' => 'FR']);
        $this->mirror('zoho_leads', 'lead-inside', 'owner-a', ['zoho_created_at' => '2026-03-29 21:30:00']);
        $this->mirror('zoho_leads', 'lead-after', 'owner-a', ['zoho_created_at' => '2026-03-29 22:00:00']);
        [, $matchedContact] = $this->fretiqContact($this->admin);
        DB::table('zoho_marketing_links')->insert([
            'zoho_module' => 'Leads', 'zoho_record_id' => 'lead-inside', 'fretiq_entity_type' => 'contact',
            'fretiq_entity_id' => $matchedContact, 'match_type' => 'exact_email', 'match_key_hash' => str_repeat('d', 64),
            'matched_at' => '2026-03-29 21:59:59', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('zoho_marketing_links')->insert([
            'zoho_module' => 'Leads', 'zoho_record_id' => 'lead-after', 'fretiq_entity_type' => 'contact',
            'fretiq_entity_id' => $matchedContact, 'match_type' => 'exact_email', 'match_key_hash' => str_repeat('e', 64),
            'matched_at' => '2026-03-29 22:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->analyseForPeriod($this->admin, $period);
        $this->assertSame(1, $result['kpis']['new_accounts']);
        $this->assertSame(1, $result['kpis']['new_contacts']);
        $this->assertSame(['2026-03-29' => 1], $result['trends']['leads']);
        $this->assertSame(2, $result['identity_coverage']['count']);
    }

    public function test_commercial_filter_is_a_fretiq_user_id_and_never_widens_scope(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('commercial');
        DB::table('zoho_user_mappings')->insert(['zoho_user_id' => 'owner-b', 'fretiq_user_id' => $other->id, 'is_confirmed' => true, 'created_at' => now(), 'updated_at' => now()]);
        $unmapped = User::factory()->create(['is_active' => true]);
        $unmapped->assignRole('commercial');
        $nonCommercial = User::factory()->create(['is_active' => true]);
        $this->mirror('zoho_leads', 'lead-a', 'owner-a');
        $this->mirror('zoho_leads', 'lead-b', 'owner-b');
        $this->mirror('zoho_leads', 'lead-c', 'owner-c');
        $this->fretiqContact($nonCommercial);

        $selected = $this->analyse($this->admin, ['commercial' => (string) $other->id]);
        $this->assertSame(1, $selected['kpis']['new_leads']);
        $this->assertSame($other->id, $selected['scope']['commercial_user_id']);

        $forcedSelf = $this->analyse($this->commercial, ['commercial' => (string) $other->id]);
        $this->assertSame((string) $this->commercial->id, $forcedSelf['filters']['commercial']);
        $this->assertSame(1, $forcedSelf['kpis']['new_leads']);

        $missing = $this->analyse($this->admin, ['commercial' => (string) $unmapped->id]);
        $this->assertTrue($missing['scope']['mapping_required']);
        $this->assertSame(0, $missing['kpis']['new_leads']);

        $forged = $this->analyse($this->admin, ['commercial' => (string) $nonCommercial->id]);
        $this->assertTrue($forged['scope']['selection_invalid']);
        $this->assertSame(0, $forged['kpis']['new_leads']);
        $this->assertSame(0, $forged['kpis']['discovered_contacts']);
    }

    public function test_multiple_confirmed_owner_mappings_fail_closed_for_commercial_and_admin_selection(): void
    {
        DB::table('zoho_user_mappings')->insert(['zoho_user_id' => 'owner-extra', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->mirror('zoho_leads', 'lead-a', 'owner-a');
        $this->mirror('zoho_leads', 'lead-extra', 'owner-extra');

        $commercial = $this->analyse($this->commercial);
        $adminSelection = $this->analyse($this->admin, ['commercial' => (string) $this->commercial->id]);

        foreach ([$commercial, $adminSelection] as $result) {
            $this->assertTrue($result['scope']['mapping_required']);
            $this->assertFalse($result['scope']['crm_visible']);
            $this->assertSame(0, $result['kpis']['new_leads']);
        }
    }

    public function test_matched_touches_require_live_links_and_deduplicate_each_event(): void
    {
        $campaignId = $this->campaign('Liens déterministes');
        [$companyId, $contactId] = $this->fretiqContact($this->commercial);
        $runId = DB::table('campaign_runs')->insertGetId(['campaign_id' => $campaignId, 'occurrence_key' => 'links-run', 'run_at' => '2026-08-05 10:00:00', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('campaign_recipients')->insert(['campaign_run_id' => $runId, 'contact_id' => $contactId, 'status' => 'opened', 'sent_at' => '2026-08-05 10:00:00', 'opened_at' => '2026-08-05 10:01:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, $this->analyse($this->commercial)['matched_touches']['count']);

        foreach (['lead-a', 'contact-a'] as $index => $recordId) {
            DB::table('zoho_marketing_links')->insert(['zoho_module' => $index === 0 ? 'Leads' : 'Contacts', 'zoho_record_id' => $recordId, 'fretiq_entity_type' => 'contact', 'fretiq_entity_id' => $contactId, 'match_type' => 'exact_email', 'match_key_hash' => str_repeat((string) ($index + 1), 64), 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->mirror('zoho_leads', 'lead-a', 'owner-a');
        $this->mirror('zoho_contacts', 'contact-a', 'owner-a');
        Cache::flush();
        $touches = $this->analyse($this->commercial)['matched_touches'];
        $this->assertSame(2, $touches['count']);
        $this->assertCount(2, $touches['items']);
        $this->assertStringNotContainsString('lead-a', json_encode($touches, JSON_THROW_ON_ERROR));
    }

    public function test_decision_time_uses_final_current_terminal_transition_only(): void
    {
        $this->mirror('zoho_quotes', 'won-to-lost', 'owner-a', ['quote_date' => '2026-08-01', 'follow_up_status' => 'Affaire perdue']);
        $this->quoteHistory('h-won', 'won-to-lost', 'Affaire gagnée', '2026-08-02 00:00:00', 'En cours');
        $this->quoteHistory('h-lost', 'won-to-lost', 'Affaire perdue', '2026-08-05 00:00:00', 'Affaire gagnée');
        $this->mirror('zoho_quotes', 'reopened', 'owner-a', ['quote_date' => '2026-08-01', 'follow_up_status' => 'En cours']);
        $this->quoteHistory('h-reopened', 'reopened', 'Affaire gagnée', '2026-08-03 00:00:00', 'En cours');

        $metrics = $this->analyse($this->commercial)['kpis']['quotes'];
        $this->assertSame(4.0, $metrics['median_decision_days']);
        $this->assertSame(1, $metrics['terminal_decision_known_count']);
    }

    public function test_physical_missing_crm_tables_preserve_fretiq_analytics_without_querying_them(): void
    {
        $connection = 'marketing_without_crm';
        $originalDefault = config('database.default');
        $this->commercial->load('roles');

        config()->set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge($connection);

        try {
            config()->set('database.default', $connection);
            $this->createFretiqOnlySchema($connection);
            DB::connection($connection)->table('users')->insert([
                'id' => $this->commercial->id,
                'name' => $this->commercial->name,
                'is_active' => true,
            ]);
            DB::connection($connection)->table('zoho_user_mappings')->insert([
                'fretiq_user_id' => $this->commercial->id,
                'zoho_user_id' => 'owner-a',
                'is_confirmed' => true,
                'updated_at' => now(),
            ]);
            $companyId = DB::connection($connection)->table('companies')->insertGetId([
                'source' => 'manual',
                'country' => 'FR',
                'sector' => 'Transport',
                'relationship' => 'prospect',
                'qualification_status' => 'pending',
                'created_at' => '2026-08-05 10:00:00',
                'updated_at' => now(),
            ]);
            DB::connection($connection)->table('contacts')->insert([
                'company_id' => $companyId,
                'assigned_to' => $this->commercial->id,
                'source' => 'manual',
                'created_at' => '2026-08-05 10:00:00',
                'updated_at' => now(),
            ]);
            Cache::flush();

            $result = (new ZohoMarketingAnalytics)->analyse(new MarketingAnalyticsQuery(
                $this->commercial,
                MarketingPeriod::fromInput([], CarbonImmutable::parse('2026-08-09', 'Europe/Paris')),
            ))->toArray();

            $this->assertFalse(Schema::connection($connection)->hasTable('zoho_sync_logs'));
            $this->assertFalse(Schema::connection($connection)->hasTable('zoho_marketing_links'));
            $this->assertTrue($result['meta']['stale_or_unavailable']);
            $this->assertSame([], $result['filter_options']['currencies']);
            $this->assertSame(1, $result['kpis']['discovered_companies']);
            $this->assertSame(1, $result['kpis']['discovered_contacts']);
        } finally {
            config()->set('database.default', $originalDefault);
            DB::purge($connection);
            config()->offsetUnset("database.connections.{$connection}");
            Cache::flush();
        }
    }

    public function test_programming_query_errors_are_not_hidden_as_schema_degradation(): void
    {
        $service = new ZohoMarketingAnalytics;
        Cache::partialMock()->shouldReceive('remember')->once()->andThrow(
            new QueryException('testing', 'select max(synced_at)', [], new RuntimeException('programming defect')),
        );
        $this->expectException(QueryException::class);
        $service->analyse(new MarketingAnalyticsQuery($this->admin, MarketingPeriod::fromInput([], CarbonImmutable::parse('2026-08-09', 'Europe/Paris'))));
    }

    public function test_missing_mapping_schema_fails_closed_without_hiding_fretiq_scope(): void
    {
        $this->fretiqContact($this->commercial);
        $service = new ZohoMarketingAnalytics;
        $columns = new ReflectionProperty($service, 'columns');
        $columns->setValue($service, ['zoho_user_mappings' => [], 'zoho_leads' => []]);

        $result = $service->analyse(new MarketingAnalyticsQuery(
            $this->commercial,
            MarketingPeriod::fromInput([], CarbonImmutable::parse('2026-08-09', 'Europe/Paris')),
        ))->toArray();

        $this->assertTrue($result['scope']['mapping_required']);
        $this->assertFalse($result['scope']['crm_visible']);
        $this->assertSame(1, $result['kpis']['discovered_contacts']);
        $this->assertSame(0, $result['kpis']['new_leads']);
    }

    public function test_identity_coverage_uses_the_same_owner_and_filter_scope(): void
    {
        $this->mirror('zoho_leads', 'lead-fr', 'owner-a', ['country' => 'FR', 'lead_source' => 'Web', 'industry' => 'Transport']);
        $this->mirror('zoho_leads', 'lead-de', 'owner-a', ['country' => 'DE', 'lead_source' => 'Salon', 'industry' => 'Industrie']);
        $this->mirror('zoho_contacts', 'contact-fr', 'owner-a', ['country' => 'FR']);
        $this->mirror('zoho_contacts', 'contact-b', 'owner-b', ['country' => 'FR']);
        [, $matchedContact] = $this->fretiqContact($this->commercial);
        foreach ([['Leads', 'lead-fr', 'f'], ['Contacts', 'contact-fr', '1'], ['Contacts', 'contact-b', '2']] as [$module, $id, $hash]) {
            DB::table('zoho_marketing_links')->insert([
                'zoho_module' => $module, 'zoho_record_id' => $id, 'fretiq_entity_type' => 'contact',
                'fretiq_entity_id' => $matchedContact, 'match_type' => 'exact_email', 'match_key_hash' => str_repeat($hash, 64),
                'matched_at' => '2026-08-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $country = $this->analyse($this->commercial, ['country' => 'FR']);
        $this->assertSame(['matched' => 2, 'eligible' => 2, 'unknown' => false, 'rate' => 100.0], $country['identity_coverage']['coverage']);
        $source = $this->analyse($this->commercial, ['lead_source' => 'Web']);
        $this->assertSame(['matched' => 1, 'eligible' => 1, 'unknown' => false, 'rate' => 100.0], $source['identity_coverage']['coverage']);
        $this->assertStringNotContainsString('lead-fr', json_encode($source['identity_coverage'], JSON_THROW_ON_ERROR));
    }

    public function test_identity_coverage_is_current_and_distinct_while_touch_timeline_uses_real_events_only(): void
    {
        $this->mirror('zoho_leads', 'lead-identity', 'owner-a');
        [, $contactId] = $this->fretiqContact($this->commercial);
        foreach ([['exact_email', 'a'], ['manual_confirmed', 'b']] as [$matchType, $hash]) {
            DB::table('zoho_marketing_links')->insert([
                'zoho_module' => 'Leads', 'zoho_record_id' => 'lead-identity',
                'fretiq_entity_type' => 'contact', 'fretiq_entity_id' => $contactId,
                'match_type' => $matchType, 'match_key_hash' => str_repeat($hash, 64),
                'matched_at' => '2026-06-01 10:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $result = $this->analyse($this->commercial);
        $this->assertSame('identity_coverage', $result['identity_coverage']['kind']);
        $this->assertSame(['matched' => 1, 'eligible' => 1, 'unknown' => false, 'rate' => 100.0], $result['identity_coverage']['coverage']);
        $this->assertSame(0, $result['matched_touches']['count']);
        $this->assertSame('fretiq_lifecycle_timeline', $result['matched_touches']['kind']);
        $this->assertStringNotContainsString('lead-identity', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_only_active_exact_email_links_count_as_identity_and_touch_evidence(): void
    {
        $this->mirror('zoho_leads', 'lead-evidence', 'owner-a');
        [, $contactId] = $this->fretiqContact($this->commercial);
        $campaignId = $this->campaign('Preuve déterministe');
        $runId = DB::table('campaign_runs')->insertGetId([
            'campaign_id' => $campaignId, 'occurrence_key' => 'identity-evidence-run',
            'run_at' => '2026-08-05 10:00:00', 'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('campaign_recipients')->insert([
            'campaign_run_id' => $runId, 'contact_id' => $contactId, 'status' => 'sent',
            'sent_at' => '2026-08-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('zoho_marketing_links')->insert([
            'zoho_module' => 'Leads', 'zoho_record_id' => 'lead-evidence',
            'fretiq_entity_type' => 'contact', 'fretiq_entity_id' => $contactId,
            'match_type' => 'manual_confirmed', 'match_key_hash' => str_repeat('m', 64),
            'is_active' => true, 'matched_at' => now(), 'validated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manualOnly = $this->analyse($this->commercial);
        $this->assertSame(0, $manualOnly['identity_coverage']['count']);
        $this->assertSame(0, $manualOnly['matched_touches']['count']);

        DB::table('zoho_marketing_links')->insert([
            'zoho_module' => 'Leads', 'zoho_record_id' => 'lead-evidence',
            'fretiq_entity_type' => 'contact', 'fretiq_entity_id' => $contactId,
            'match_type' => 'exact_email', 'match_key_hash' => str_repeat('e', 64),
            'is_active' => false, 'matched_at' => now(), 'validated_at' => now(), 'invalidated_at' => now(),
            'invalidation_reason' => 'exact_email_changed', 'invalidation_count' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::flush();
        $inactiveExact = $this->analyse($this->commercial);
        $this->assertSame(0, $inactiveExact['identity_coverage']['count']);
        $this->assertSame(0, $inactiveExact['matched_touches']['count']);

        DB::table('zoho_marketing_links')->where('match_type', 'exact_email')->update(['is_active' => true, 'updated_at' => now()]);
        Cache::flush();
        $activeExact = $this->analyse($this->commercial);
        $this->assertSame(1, $activeExact['identity_coverage']['count']);
        $this->assertSame(1, $activeExact['matched_touches']['count']);
    }

    public function test_funnel_trends_and_comparison_include_current_outcomes_and_history_transitions(): void
    {
        $this->mirror('zoho_leads', 'lead-qualified', 'owner-a', ['status' => 'Qualified']);
        $this->mirror('zoho_leads', 'lead-new', 'owner-a', ['status' => 'New']);
        $this->mirror('zoho_deals', 'deal-won', 'owner-a', ['stage' => 'Closed Won', 'amount' => 200, 'weighted_amount' => 200, 'currency_code' => 'EUR']);
        $this->mirror('zoho_deals', 'deal-lost', 'owner-a', ['stage' => 'Closed Lost']);
        $this->mirror('zoho_deals', 'deal-open', 'owner-a', ['stage' => 'Qualification', 'amount' => 100, 'weighted_amount' => 40, 'currency_code' => 'MAD']);
        $this->mirror('zoho_deals', 'deal-old', 'owner-a', ['stage' => 'Closed Won', 'zoho_created_at' => '2026-05-01 10:00:00']);
        $this->mirror('zoho_deals', 'deal-previous', 'owner-a', ['stage' => 'Proposal', 'amount' => 300, 'weighted_amount' => 120, 'currency_code' => 'USD', 'zoho_created_at' => '2026-06-20 10:00:00']);
        $this->mirror('zoho_quotes', 'quote-won', 'owner-a', ['follow_up_status' => 'Affaire gagnée', 'line_items_total' => 500, 'line_items_total_complete' => true, 'currency_code' => 'EUR']);
        $this->mirror('zoho_quotes', 'quote-lost', 'owner-a', ['follow_up_status' => 'Affaire perdue']);
        $this->mirror('zoho_quotes', 'quote-open', 'owner-a', ['follow_up_status' => 'En cours']);
        $this->mirror('zoho_quotes', 'quote-previous', 'owner-a', ['follow_up_status' => 'Affaire gagnée', 'line_items_total' => 700, 'line_items_total_complete' => true, 'currency_code' => 'USD', 'zoho_created_at' => '2026-06-20 10:00:00']);
        $this->dealHistory('history-old-win', 'deal-old', 'Closed Won', '2026-08-05 10:00:00');
        $this->quoteHistory('history-quote', 'quote-won', 'Affaire gagnée', '2026-08-06 10:00:00', 'Devis confirmé');

        $result = $this->analyse($this->commercial);
        $this->assertSame(['Qualified' => 1, 'New' => 1], $result['funnels']['zoho']['lead_qualification']);
        $this->assertSame(['won' => 1, 'lost' => 1, 'open' => 1], $result['funnels']['zoho']['deal_outcomes']);
        $this->assertSame(['won' => 1, 'lost' => 1, 'open' => 1], $result['funnels']['zoho']['quote_outcomes']);
        $this->assertSame(['EUR' => 200.0], $result['kpis']['deal_outcomes']['won_value_by_currency']);
        $this->assertSame(['EUR' => 500.0], $result['kpis']['quotes']['won_value_by_currency']);
        $this->assertSame(1, $result['funnels']['zoho']['transitions']['deals']['Closed Won']);
        $this->assertSame(1, $result['funnels']['zoho']['transitions']['quotes']['Affaire gagnée']);
        $this->assertSame(1, $result['trends']['wins']['2026-08-05']);
        $this->assertSame(2, $result['kpis']['pipeline']['count']);
        $this->assertSame(['MAD' => 100.0], $result['kpis']['created_cohort_pipeline']['amount_by_currency']);
        $this->assertSame(['USD' => 300.0], $result['comparison']['created_cohort_pipeline']['amount_by_currency']);
        $this->assertArrayNotHasKey('pipeline', $result['comparison']);
        $this->assertFalse($result['meta']['pipeline_comparable']);
        $this->assertSame(['USD' => 700.0], $result['comparison']['quote_outcomes']['value_by_currency']);
    }

    public function test_stale_module_keys_and_hourly_nightly_thresholds_are_explicit(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00 UTC');
        try {
            $this->syncLog('leads', 'delta', '2026-08-09 10:30:00');
            $this->syncLog('leads', 'reconcile', '2026-08-08 06:00:00');
            $this->syncLog('accounts', 'delta', '2026-08-09 10:29:00');
            $this->syncLog('accounts', 'reconcile', '2026-08-08 07:00:00');
            $this->syncLog('deals', 'delta', '2026-08-09 08:59:00');
            $this->syncLog('deals', 'reconcile', '2026-08-07 11:00:00');
            $this->syncLog('quotes', 'delta', '2026-08-09 11:50:00');
            $this->syncLog('quotes', 'reconcile', '2026-08-09 02:00:00');

            $stale = $this->analyse($this->admin)['attention']['stale_modules'];
            $this->assertSame('green', $stale['leads']['hourly']);
            $this->assertSame('warning', $stale['leads']['nightly']);
            $this->assertSame('warning', $stale['accounts']['hourly']);
            $this->assertSame(['hourly' => 'critical_no_success', 'nightly' => 'critical_no_success'], $stale['contacts']);
            $this->assertSame(['hourly' => 'critical', 'nightly' => 'critical'], $stale['deals']);
            $this->assertArrayNotHasKey('quotes', $stale);
            $this->assertSame(['leads', 'accounts', 'contacts', 'deals'], array_keys($stale));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_attention_is_null_inclusive_open_only_and_accepts_activity_from_any_owner(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00 UTC');
        try {
            $old = '2026-07-01 10:00:00';
            $this->mirror('zoho_leads', 'lead-with-activity', 'owner-a', ['status' => null, 'zoho_created_at' => $old]);
            $this->mirror('zoho_leads', 'lead-without-activity', 'owner-a', ['status' => null, 'zoho_created_at' => $old]);
            $this->mirror('zoho_deals', 'deal-with-activity', 'owner-a', ['stage' => null, 'zoho_created_at' => $old]);
            $this->mirror('zoho_deals', 'deal-without-activity', 'owner-a', ['stage' => null, 'zoho_created_at' => $old]);
            foreach ([['activity-lead', 'lead-with-activity'], ['activity-deal', 'deal-with-activity']] as [$id, $parent]) {
                DB::table('zoho_activities')->insert([
                    'activity_type' => 'task', 'zoho_id' => $id, 'owner_zoho_id' => 'owner-b',
                    'parent_zoho_id' => $parent, 'activity_at' => '2026-08-08 10:00:00',
                    'raw_payload' => '{}', 'payload_hash' => str_repeat('a', 64),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->mirror('zoho_quotes', 'quote-open-expiring', 'owner-a', ['follow_up_status' => null, 'valid_till' => '2026-08-10']);
            $this->mirror('zoho_quotes', 'quote-won-expiring', 'owner-a', ['follow_up_status' => 'Affaire gagnée', 'valid_till' => '2026-08-10']);
            $this->mirror('zoho_quotes', 'quote-lost-expiring', 'owner-a', ['follow_up_status' => 'Affaire perdue', 'valid_till' => '2026-08-10']);

            $attention = $this->analyse($this->commercial)['attention'];
            $this->assertSame(1, $attention['open_leads_without_activity_14_days']);
            $this->assertSame(1, $attention['open_deals_without_activity_14_days']);
            $this->assertSame(1, $attention['quotes_expiring_7_days']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_filter_options_are_stable_and_never_leak_another_commercial_portfolio(): void
    {
        $other = User::factory()->create(['name' => 'Bravo', 'is_active' => true]);
        $other->assignRole('commercial');
        DB::table('zoho_user_mappings')->insert(['zoho_user_id' => 'owner-b', 'fretiq_user_id' => $other->id, 'is_confirmed' => true, 'created_at' => now(), 'updated_at' => now()]);
        [, $contactA] = $this->fretiqContact($this->commercial, ['company_source' => 'manual-a', 'contact_source' => 'manual-a', 'country' => 'FR', 'sector' => 'Alpha']);
        [, $contactB] = $this->fretiqContact($other, ['company_source' => 'manual-b', 'contact_source' => 'manual-b', 'country' => 'MA', 'sector' => 'Bravo']);
        $campaignA = $this->campaign('Alpha Campaign');
        $this->demande($contactA, $campaignA, '2026-08-05 10:00:00');
        $campaignB = $this->campaign('Bravo Campaign');
        $this->demande($contactB, $campaignB, '2026-08-05 10:00:00');
        $this->mirror('zoho_leads', 'lead-a', 'owner-a', ['lead_source' => 'Web A', 'country' => 'FR']);
        $this->mirror('zoho_leads', 'lead-b', 'owner-b', ['lead_source' => 'Web B', 'country' => 'MA']);
        $this->mirror('zoho_deals', 'deal-a', 'owner-a', ['currency_code' => 'EUR']);
        $this->mirror('zoho_deals', 'deal-b', 'owner-b', ['currency_code' => 'MAD']);

        $options = $this->analyse($this->commercial)['filter_options'];
        $this->assertSame([(string) $this->commercial->id], array_column($options['commercials'], 'value'));
        $this->assertContains(['value' => 'manual-a', 'label' => 'manual-a'], $options['sources']);
        $this->assertNotContains(['value' => 'manual-b', 'label' => 'manual-b'], $options['sources']);
        $this->assertSame([['value' => (string) $campaignA, 'label' => 'Alpha Campaign']], $options['campaigns']);
        $this->assertSame([['value' => 'EUR', 'label' => 'EUR']], $options['currencies']);
        $this->assertNotContains(['value' => 'MA', 'label' => 'MA'], $options['countries']);

        $adminOptions = $this->analyse($this->admin)['filter_options'];
        $this->assertContains(['value' => (string) $campaignB, 'label' => 'Bravo Campaign'], $adminOptions['campaigns']);
        $this->assertContains(['value' => 'MAD', 'label' => 'MAD'], $adminOptions['currencies']);
    }

    public function test_query_count_and_lifecycle_payload_stay_bounded_as_event_volume_grows(): void
    {
        $campaignId = $this->campaign('Charge bornée');
        $runId = DB::table('campaign_runs')->insertGetId([
            'campaign_id' => $campaignId, 'occurrence_key' => 'bounded-run', 'run_at' => '2026-08-05 10:00:00',
            'status' => 'sent', 'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($index = 0; $index < 25; $index++) {
            [, $contactId] = $this->fretiqContact($this->commercial);
            DB::table('campaign_recipients')->insert([
                'campaign_run_id' => $runId, 'contact_id' => $contactId, 'status' => 'replied',
                'sent_at' => '2026-08-05 10:00:00', 'opened_at' => '2026-08-05 10:01:00',
                'clicked_at' => '2026-08-05 10:02:00', 'replied_at' => '2026-08-05 10:03:00',
                'bounced_at' => '2026-08-05 10:04:00', 'provider_message_id' => 'bounded-'.$index,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $leadId = 'bounded-lead-'.$index;
            $this->mirror('zoho_leads', $leadId, 'owner-a');
            DB::table('zoho_marketing_links')->insert([
                'zoho_module' => 'Leads', 'zoho_record_id' => $leadId, 'fretiq_entity_type' => 'contact',
                'fretiq_entity_id' => $contactId, 'match_type' => 'exact_email', 'match_key_hash' => hash('sha256', $leadId),
                'matched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $this->analyse($this->commercial);
            $coldQueries = count(DB::getQueryLog());
            $this->assertSame(125, $result['matched_touches']['count']);
            $this->assertCount(100, $result['matched_touches']['items']);
            $this->assertTrue($result['matched_touches']['items_truncated']);
            $this->assertLessThanOrEqual(75, $coldQueries, "Cold analytics issued {$coldQueries} queries.");

            DB::flushQueryLog();
            $this->analyse($this->commercial);
            $warmQueries = count(DB::getQueryLog());
            $this->assertLessThanOrEqual(8, $warmQueries, "Warm analytics issued {$warmQueries} queries.");
        } finally {
            DB::disableQueryLog();
        }
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function analyse(User $user, array $filters = []): array
    {
        return $this->analyseForPeriod($user, MarketingPeriod::fromInput([], CarbonImmutable::parse('2026-08-09', 'Europe/Paris')), $filters);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function analyseForPeriod(User $user, MarketingPeriod $period, array $filters = []): array
    {
        return app(ZohoMarketingAnalytics::class)->analyse(new MarketingAnalyticsQuery($user, $period, $filters))->toArray();
    }

    /** @param array<string,mixed> $extra */
    private function mirror(string $table, string $id, string $owner, array $extra = []): void
    {
        DB::table($table)->insert(array_merge(['zoho_id' => $id, 'owner_zoho_id' => $owner, 'raw_payload' => '{}', 'payload_hash' => str_repeat('a', 64), 'zoho_created_at' => '2026-08-05 10:00:00', 'last_seen_at' => now(), 'last_synced_at' => now(), 'created_at' => now(), 'updated_at' => now()], $extra));
    }

    /** @param array<string,string> $extra @return array{int,int} */
    private function fretiqContact(User $owner, array $extra = []): array
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Company '.uniqid(), 'source' => $extra['company_source'] ?? 'manual',
            'country' => $extra['country'] ?? 'FR', 'sector' => $extra['sector'] ?? 'Transport',
            'relationship' => $extra['relationship'] ?? 'prospect', 'qualification_status' => 'pending',
            'created_at' => $extra['created_at'] ?? '2026-08-05 10:00:00', 'updated_at' => now(),
        ]);
        $contactId = DB::table('contacts')->insertGetId([
            'company_id' => $companyId, 'assigned_to' => $owner->id, 'email' => uniqid().'@example.test',
            'name' => 'Contact '.uniqid(), 'source' => $extra['contact_source'] ?? 'manual', 'status' => 'new',
            'legal_basis' => 'legitimate_interest', 'email_kind' => 'role',
            'created_at' => $extra['created_at'] ?? '2026-08-05 10:00:00', 'updated_at' => now(),
        ]);

        return [$companyId, $contactId];
    }

    private function campaign(string $name): int
    {
        $suffix = uniqid();
        $segment = DB::table('segments')->insertGetId(['name' => 'Segment '.$suffix, 'scope' => 'client', 'created_at' => now(), 'updated_at' => now()]);
        $template = DB::table('campaign_templates')->insertGetId(['name' => 'Template '.$suffix, 'subject' => 'Subject', 'html_content' => '<p>Body</p>', 'created_at' => now(), 'updated_at' => now()]);
        $sender = DB::table('sender_identities')->insertGetId(['name' => 'Sender '.$suffix, 'email' => $suffix.'@example.test', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('campaigns')->insertGetId(['segment_id' => $segment, 'template_id' => $template, 'sender_identity_id' => $sender, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function demande(int $contactId, int $campaignId, string $capturedAt, string $createdAt = '2026-08-05 10:00:00'): void
    {
        DB::table('demandes')->insert(['contact_id' => $contactId, 'campaign_id' => $campaignId, 'captured_at' => $capturedAt, 'status' => 'pending', 'created_at' => $createdAt, 'updated_at' => now()]);
    }

    private function dealHistory(string $id, string $dealId, string $stage, string $occurredAt): void
    {
        DB::table('zoho_deal_stage_history')->insert(['zoho_id' => $id, 'deal_zoho_id' => $dealId, 'stage' => $stage, 'raw_payload' => '{}', 'payload_hash' => str_repeat('a', 64), 'occurred_at' => $occurredAt, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function quoteHistory(string $id, string $quoteId, string $status, string $occurredAt, ?string $previousStatus = null): void
    {
        DB::table('zoho_quote_status_history')->insert(['zoho_id' => $id, 'quote_zoho_id' => $quoteId, 'status' => $status, 'previous_status' => $previousStatus, 'raw_payload' => '{}', 'payload_hash' => str_repeat('a', 64), 'occurred_at' => $occurredAt, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function syncLog(string $module, string $mode, string $syncedAt): void
    {
        DB::table('zoho_sync_logs')->insert(['module' => $module, 'mode' => $mode, 'sync_batch_id' => 1, 'synced_at' => $syncedAt, 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function createFretiqOnlySchema(string $connection): void
    {
        $schema = Schema::connection($connection);
        $schema->create('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->boolean('is_active');
        });
        $schema->create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->nullable();
            $table->string('country')->nullable();
            $table->string('sector')->nullable();
            $table->string('relationship')->nullable();
            $table->string('qualification_status')->nullable();
            $table->timestamps();
        });
        $schema->create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('source')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $schema->create('campaign_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->string('status');
            $table->dateTime('run_at')->nullable();
            $table->timestamps();
        });
        $schema->create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('campaign_run_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('status');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->dateTime('bounced_at')->nullable();
            $table->timestamps();
        });
        $schema->create('demandes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->dateTime('captured_at');
            $table->timestamps();
        });
        $schema->create('zoho_user_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('zoho_user_id');
            $table->unsignedBigInteger('fretiq_user_id');
            $table->boolean('is_confirmed');
            $table->dateTime('updated_at')->nullable();
        });
    }
}
