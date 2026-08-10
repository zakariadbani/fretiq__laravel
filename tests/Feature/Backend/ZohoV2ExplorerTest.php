<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Models\Zoho\ZohoAccount;
use App\Models\Zoho\ZohoActivity;
use App\Models\Zoho\ZohoDeal;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoUserMapping;
use App\Services\Zoho\V2\Explorer\ZohoExplorer;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ZohoV2ExplorerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        View::share('errors', new ViewErrorBag);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
        $this->commercial = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->commercial->assignRole('commercial');
    }

    public function test_routes_are_loaded_normally_and_expose_get_only_surface(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'admin/zoho/records'));

        $this->assertSame([
            'admin.zoho_records.data',
            'admin.zoho_records.export',
            'admin.zoho_records.index',
            'admin.zoho_records.show',
        ], $routes->pluck('action.as')->sort()->values()->all());
        $routes->each(function ($route): void {
            $this->assertSame([], array_values(array_diff($route->methods(), ['GET', 'HEAD'])));
        });
    }

    public function test_specific_permission_protects_the_explorer(): void
    {
        $denied = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $denied->givePermissionTo('backend.access');

        $this->actingAs($denied)->get('/admin/zoho/records/accounts')->assertForbidden();
    }

    public function test_commercial_cannot_escape_confirmed_owner_scope_or_view_another_detail(): void
    {
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);
        $mine = $this->account('a', 'owner-a');
        $other = $this->account('b', 'owner-b');

        $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10&owner_zoho_id=owner-b')
            ->assertOk()->assertJsonPath('recordsFiltered', 0);
        $response = $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10');
        $response
            ->assertOk()->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.detail_url', url('/admin/zoho/records/accounts/'.$mine->zoho_id));
        $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts/'.$other->zoho_id)->assertNotFound();
    }

    public function test_data_rows_contain_only_reviewed_listing_fields_and_detail_url(): void
    {
        $account = ZohoAccount::query()->create([
            'zoho_id' => 'account-safe-select',
            'owner_zoho_id' => 'owner-a',
            'name' => 'Reviewed',
            'raw_payload' => ['secret' => 'RAW-SECRET'],
            'payload_hash' => 'PAYLOAD-HASH-SECRET',
            'field_schema_hash' => 'SCHEMA-HASH-SECRET',
            'sync_batch_id' => 999,
        ]);
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);

        $response = $this->actingAs($this->commercial)
            ->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10')
            ->assertOk()->assertJsonPath('recordsFiltered', 1);

        $fields = app(ZohoExplorer::class)->listingFields('accounts');
        $this->assertSame(array_values(array_merge($fields, ['detail_url'])), array_keys($response->json('data.0')));
        $serialized = json_encode($response->json('data.0'));
        foreach (['raw_payload', 'fretiq_company_id', 'payload_hash', 'field_schema_hash', 'sync_batch_id', 'zoho_deleted_at', 'normalized_email', (string) $account->id] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_listing_rejects_unbounded_requests_and_caps_oversized_pages(): void
    {
        foreach (range(1, 105) as $index) {
            $this->account('page-'.$index, 'owner-a');
        }

        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=-1')
            ->assertUnprocessable();

        $response = $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=2&start=-500&length=1000000')
            ->assertOk();

        $this->assertCount(100, $response->json('data'));
        $response->assertJsonPath('recordsFiltered', 105);
    }

    public function test_explorer_availability_fails_closed_when_a_mirror_table_is_missing(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('zoho_accounts')->andReturn(false);

        $this->assertFalse(app(ZohoExplorer::class)->available('accounts'));
    }

    public function test_explorer_availability_fails_closed_when_scope_dependencies_are_missing(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('zoho_accounts')->andReturn(true);
        Schema::shouldReceive('hasColumns')->once()->with('zoho_accounts', \Mockery::type('array'))->andReturn(true);
        Schema::shouldReceive('hasTable')->once()->with('zoho_user_mappings')->andReturn(false);

        $this->assertFalse(app(ZohoExplorer::class)->available('accounts'));
    }

    public function test_unmapped_commercial_gets_mapping_notice_and_no_data(): void
    {
        $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts')
            ->assertOk()->assertSee('portefeuille Zoho doit être confirmé');
        $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10')
            ->assertOk()->assertJsonPath('recordsFiltered', 0);
    }

    public function test_raw_payload_is_never_rendered_without_its_specific_permission(): void
    {
        $account = $this->account('raw', 'owner-a', '<script>alert(1)</script>');
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);
        $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts/'.$account->zoho_id)
            ->assertOk()->assertDontSee('Payload brut')->assertDontSee('<script>alert(1)</script>', false);
        $this->commercial->givePermissionTo('view zoho raw payload');
        $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts/'.$account->zoho_id)
            ->assertOk()->assertSee('Payload brut')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_detail_and_nested_queries_select_only_reviewed_attributes(): void
    {
        $quote = ZohoQuote::query()->create([
            'zoho_id' => 'quote-safe-select', 'owner_zoho_id' => 'owner-a', 'subject' => 'Quote',
            'raw_payload' => ['secret' => 'RAW-QUOTE'], 'payload_hash' => 'HASH-QUOTE', 'sync_batch_id' => 123,
        ]);
        ZohoActivity::query()->create([
            'activity_type' => 'task', 'zoho_id' => 'activity-safe', 'owner_zoho_id' => 'owner-a',
            'parent_zoho_id' => $quote->zoho_id, 'subject' => 'Visible activity',
            'raw_payload' => ['secret' => 'RAW-ACTIVITY'], 'payload_hash' => 'HASH-ACTIVITY',
        ]);
        ZohoQuoteItem::query()->create([
            'zoho_quote_id' => $quote->zoho_id, 'zoho_line_item_id' => 'line-safe', 'product_name' => 'Visible product',
            'raw_payload' => ['secret' => 'RAW-LINE'], 'payload_hash' => 'HASH-LINE',
        ]);
        $request = Request::create('/admin/zoho/records/quotes/'.$quote->zoho_id, 'GET');
        $request->setUserResolver(fn (): User => $this->admin);
        $explorer = app(ZohoExplorer::class);

        $record = $explorer->detailQuery('quotes', $request, false)->query->where('zoho_id', $quote->zoho_id)->firstOrFail();
        $this->assertEqualsCanonicalizing(array_merge(['id'], $explorer->visibleFields('quotes')), array_keys($record->getAttributes()));
        $this->assertArrayNotHasKey('raw_payload', $record->getAttributes());

        $rawRecord = $explorer->detailQuery('quotes', $request, true)->query->where('zoho_id', $quote->zoho_id)->firstOrFail();
        $this->assertEqualsCanonicalizing(array_merge(['id'], $explorer->visibleFields('quotes'), ['raw_payload']), array_keys($rawRecord->getAttributes()));

        $activities = $explorer->nestedActivities($record, $request);
        $items = $explorer->quoteItems($record);
        $this->assertEqualsCanonicalizing(array_merge(['id'], $explorer->activityDetailFields()), array_keys($activities->first()->getAttributes()));
        $this->assertEqualsCanonicalizing(array_merge(['id'], $explorer->quoteItemDetailFields()), array_keys($items->first()->getAttributes()));
        $this->assertStringNotContainsString('RAW-', json_encode([$activities->first()->getAttributes(), $items->first()->getAttributes()]));
    }

    public function test_export_requires_its_own_permission_and_neutralizes_formula_values(): void
    {
        $this->account('csv', 'owner-a', " \t+SUM(1,1)\rnext");
        $this->actingAs($this->commercial)->get('/admin/zoho/records/accounts/export')->assertForbidden();
        $this->admin->givePermissionTo('export zoho records');
        $response = $this->actingAs($this->admin)->get('/admin/zoho/records/accounts/export')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = preg_split('/\r\n|\n|\r/', trim(substr($csv, 3)));
        $headers = str_getcsv($lines[0], ';');
        $row = str_getcsv($lines[1], ';');
        $this->assertSame("'  +SUM(1,1) next", $row[array_search('name', $headers, true)]);
        $this->assertStringNotContainsString('raw_payload', $csv);
        $this->assertStringNotContainsString("\t", $csv);
    }

    public function test_export_remains_portfolio_scoped_after_permission_is_granted(): void
    {
        $this->account('mine-export', 'owner-a', 'Mine export');
        $this->account('other-export', 'owner-b', 'Other export secret');
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);
        $this->commercial->givePermissionTo('export zoho records');

        $csv = $this->actingAs($this->commercial)
            ->get('/admin/zoho/records/accounts/export')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Mine export', $csv);
        $this->assertStringNotContainsString('Other export secret', $csv);
    }

    public function test_marketing_drilldown_names_are_translated_without_raw_payload_searches(): void
    {
        $this->account('matching', 'owner-a')->update(['industry' => 'Logistique', 'account_type' => 'Client']);
        $this->account('other', 'owner-b')->update(['industry' => 'Industrie', 'account_type' => 'Prospect']);

        $this->actingAs($this->admin)->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10&sector=Logistique&client_type=Client')
            ->assertOk()->assertJsonPath('recordsFiltered', 1);
        $this->actingAs($this->admin)->get('/admin/zoho/records/accounts?campaign=campaign-123')
            ->assertOk()->assertSee('filtre campagne ne s’applique pas', false);
    }

    public function test_shared_filter_names_resolve_to_each_modules_typed_columns(): void
    {
        $this->account('typed-client', 'owner-a')->update([
            'account_type' => 'Client',
            'account_status' => 'Actif',
            'transport_type' => 'Air',
        ]);
        $this->account('typed-prospect', 'owner-a')->update([
            'account_type' => 'Prospect',
            'account_status' => 'Inactif',
            'transport_type' => 'Road',
        ]);
        foreach ([['quote-air', ['Air', 'Sea']], ['quote-road', ['Road']]] as [$zohoId, $transport]) {
            ZohoQuote::query()->create([
                'zoho_id' => $zohoId,
                'owner_zoho_id' => 'owner-a',
                'subject' => $zohoId,
                'transport_type' => $transport,
                'raw_payload' => [],
                'payload_hash' => hash('sha256', $zohoId),
            ]);
        }

        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10&client_type=Client')
            ->assertOk()->assertJsonPath('recordsFiltered', 1);
        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=2&start=0&length=10&status=Actif')
            ->assertOk()->assertJsonPath('recordsFiltered', 1);
        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=3&start=0&length=10&transport=Air')
            ->assertOk()->assertJsonPath('recordsFiltered', 1);
        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/quotes/data?draw=4&start=0&length=10&transport=Air')
            ->assertOk()->assertJsonPath('recordsFiltered', 1);
    }

    public function test_absent_deal_lead_source_is_not_exposed_or_used_as_a_filter(): void
    {
        foreach ([['deal-referral', 'Referral'], ['deal-partner', 'Partner']] as [$zohoId, $leadSource]) {
            ZohoDeal::query()->create([
                'zoho_id' => $zohoId,
                'owner_zoho_id' => 'owner-a',
                'name' => $zohoId,
                'lead_source' => $leadSource,
                'raw_payload' => [],
                'payload_hash' => hash('sha256', $zohoId),
            ]);
        }

        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/deals/data?draw=1&start=0&length=10&source=Referral')
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 2);

        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/deals/data?draw=2&start=0&length=10&lead_source=Referral')
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 2);

        $this->assertNotContains('lead_source', app(ZohoExplorer::class)->visibleFields('deals'));
    }

    public function test_admin_commercial_filter_uses_confirmed_owner_mapping_and_fails_closed(): void
    {
        $this->account('commercial-mine', 'owner-a');
        $this->account('commercial-other', 'owner-b');
        ZohoUserMapping::query()->create([
            'zoho_user_id' => 'owner-a',
            'fretiq_user_id' => $this->commercial->id,
            'is_confirmed' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10&commercial='.$this->commercial->id)
            ->assertOk()->assertJsonPath('recordsFiltered', 1);

        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=2&start=0&length=10&commercial=not-a-user')
            ->assertOk()->assertJsonPath('recordsFiltered', 0);
    }

    public function test_commercial_cannot_widen_scope_with_another_commercial_filter(): void
    {
        $otherCommercial = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $otherCommercial->assignRole('commercial');
        $this->account('mine-commercial-filter', 'owner-a');
        $this->account('other-commercial-filter', 'owner-b');
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-b', 'fretiq_user_id' => $otherCommercial->id, 'is_confirmed' => true]);

        $this->actingAs($this->commercial)
            ->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10&commercial='.$otherCommercial->id)
            ->assertOk()->assertJsonPath('recordsFiltered', 0);
    }

    public function test_period_and_reviewed_filters_are_applied_and_preserved_in_detail_links(): void
    {
        $included = $this->account('period-included', 'owner-a');
        $included->update(['industry' => 'Logistique', 'zoho_created_at' => '2026-08-05 10:00:00']);
        $this->account('period-excluded', 'owner-a')->update([
            'industry' => 'Logistique',
            'zoho_created_at' => '2026-07-01 10:00:00',
        ]);

        $response = $this->actingAs($this->admin)->get(
            '/admin/zoho/records/accounts/data?draw=1&start=0&length=10&period=custom&from=2026-08-01&to=2026-08-09&sector=Logistique'
        )->assertOk()->assertJsonPath('recordsFiltered', 1);

        $detailUrl = (string) $response->json('data.0.detail_url');
        $this->assertStringStartsWith(url('/admin/zoho/records/accounts/'.$included->zoho_id).'?', $detailUrl);
        $this->assertStringContainsString('period=custom', $detailUrl);
        $this->assertStringContainsString('from=2026-08-01', $detailUrl);
        $this->assertStringContainsString('to=2026-08-09', $detailUrl);
        $this->assertStringContainsString('sector=Logistique', $detailUrl);
        $this->assertStringNotContainsString('&amp;', $detailUrl);

        parse_str((string) parse_url($detailUrl, PHP_URL_QUERY), $roundTripFilters);
        $this->assertSame([
            'period' => 'custom',
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'sector' => 'Logistique',
        ], $roundTripFilters);
    }

    public function test_invalid_or_malformed_periods_fail_closed_instead_of_becoming_all_time(): void
    {
        $this->account('invalid-period', 'owner-a');

        foreach ([
            'period=unknown',
            'period=custom&from=2026-08-09&to=2026-08-01',
            'period%5B0%5D=30d',
        ] as $query) {
            $this->actingAs($this->admin)
                ->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10&'.$query)
                ->assertUnprocessable();
        }
    }

    public function test_malformed_nested_search_input_is_safely_ignored(): void
    {
        $this->account('malformed-search', 'owner-a');

        $this->actingAs($this->admin)
            ->get('/admin/zoho/records/accounts/data?draw=1&start=0&length=10&search%5Bvalue%5D%5B0%5D=Account')
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1);
    }

    public function test_quote_detail_nests_current_line_items_only(): void
    {
        $quote = ZohoQuote::query()->create(['zoho_id' => 'quote-1', 'owner_zoho_id' => 'owner-a', 'subject' => 'Quote', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'quote')]);
        ZohoQuoteItem::query()->create(['zoho_quote_id' => $quote->zoho_id, 'zoho_line_item_id' => 'line-1', 'product_name' => 'Visible line', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'line')]);
        ZohoQuoteItem::query()->create(['zoho_quote_id' => $quote->zoho_id, 'zoho_line_item_id' => 'line-2', 'product_name' => 'Deleted line', 'zoho_deleted_at' => now(), 'raw_payload' => [], 'payload_hash' => hash('sha256', 'line2')]);

        $this->actingAs($this->admin)->get('/admin/zoho/records/quotes/'.$quote->zoho_id)
            ->assertOk()->assertSee('Visible line')->assertDontSee('Deleted line');
    }

    public function test_nested_activities_cannot_cross_the_commercial_owner_scope(): void
    {
        $quote = ZohoQuote::query()->create(['zoho_id' => 'quote-owned', 'owner_zoho_id' => 'owner-a', 'subject' => 'Owned quote', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'owned')]);
        foreach ([['owner-a', 'Visible owned activity'], ['owner-b', 'Foreign activity secret']] as [$owner, $subject]) {
            ZohoActivity::query()->create([
                'activity_type' => 'task', 'zoho_id' => hash('sha256', $subject), 'owner_zoho_id' => $owner,
                'parent_zoho_id' => $quote->zoho_id, 'subject' => $subject,
                'raw_payload' => [], 'payload_hash' => hash('sha256', 'payload-'.$subject),
            ]);
        }
        ZohoUserMapping::query()->create(['zoho_user_id' => 'owner-a', 'fretiq_user_id' => $this->commercial->id, 'is_confirmed' => true]);

        $this->actingAs($this->commercial)->get('/admin/zoho/records/quotes/'.$quote->zoho_id)
            ->assertOk()->assertSee('Visible owned activity')->assertDontSee('Foreign activity secret');
    }

    private function account(string $suffix, string $owner, ?string $name = null): ZohoAccount
    {
        return ZohoAccount::query()->create([
            'zoho_id' => 'account-'.$suffix, 'owner_zoho_id' => $owner,
            'name' => $name ?? 'Account '.$suffix,
            'raw_payload' => ['Name' => $name ?? 'Account '.$suffix], 'payload_hash' => hash('sha256', $suffix),
        ]);
    }
}
