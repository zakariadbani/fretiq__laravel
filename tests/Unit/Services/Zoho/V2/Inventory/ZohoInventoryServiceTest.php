<?php

namespace Tests\Unit\Services\Zoho\V2\Inventory;

use App\Models\Zoho\ZohoFieldManifest;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\V2\Inventory\ZohoInventoryService;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_deterministic_manifest_revisions_and_marks_schema_drift(): void
    {
        $transport = new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Leads']]],
            '/settings/fields' => ['fields' => $this->completeLeadFields([[
                'api_name' => 'Currency', 'data_type' => 'picklist', 'field_label' => 'Currency',
                'pick_list_values' => [['display_value' => 'Euro', 'actual_value' => 'EUR']],
            ]])],
            '/settings/layouts' => ['layouts' => [['id' => 'layout-1', 'name' => 'Standard', 'api_name' => 'Standard']]],
            '/settings/related_lists' => ['related_lists' => []],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        $service = app(ZohoInventoryService::class);
        $first = $service->inventory(['leads']);
        $repeat = $service->inventory(['leads']);

        $this->assertSame($first->modules['leads']['schema_hash'], $repeat->modules['leads']['schema_hash']);
        $this->assertSame(['EUR'], $first->modules['leads']['currencies']);
        $this->assertSame(1, ZohoFieldManifest::query()->where('module', 'leads')->count());

        $transport->responses['/settings/fields'] = ['fields' => [...$this->completeLeadFields([[
            'api_name' => 'Currency', 'data_type' => 'picklist', 'field_label' => 'Currency',
            'pick_list_values' => [['display_value' => 'Euro', 'actual_value' => 'EUR']],
        ]]), ['api_name' => 'Industry', 'data_type' => 'text', 'field_label' => 'Industry']]];
        $changed = $service->inventory(['leads']);

        $this->assertNotSame($first->modules['leads']['schema_hash'], $changed->modules['leads']['schema_hash']);
        $this->assertSame(2, ZohoFieldManifest::query()->where('module', 'leads')->count());
        $this->assertSame(1, ZohoFieldManifest::query()->where('module', 'leads')->where('is_current', true)->count());
        $this->assertSame('drifted', ZohoFieldManifest::query()->where('module', 'leads')->where('is_current', true)->value('drift_state'));
        $this->assertContains('/settings/modules', $transport->paths);
        $this->assertContains('/settings/fields', $transport->paths);
        $this->assertContains('/settings/layouts', $transport->paths);
        $this->assertContains('/settings/related_lists', $transport->paths);
        $this->assertNotContains('/settings/currencies', $transport->paths);

        $repeatAfterDrift = $service->inventory(['leads']);
        $this->assertSame($changed->modules['leads']['schema_hash'], $repeatAfterDrift->modules['leads']['schema_hash']);
        $this->assertSame('drifted', ZohoFieldManifest::query()->where('module', 'leads')->where('is_current', true)->value('drift_state'));

        $service->markReviewed('leads', $changed->modules['leads']['schema_hash']);
        $this->assertSame('verified', ZohoFieldManifest::query()->where('module', 'leads')->where('is_current', true)->value('drift_state'));
    }

    public function test_missing_promoted_sources_are_persisted_as_mapping_gaps_and_block_review(): void
    {
        $fields = array_values(array_filter(
            $this->completeLeadFields(),
            fn (array $field): bool => $field['api_name'] !== 'Secteur_Activit',
        ));
        $transport = new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Leads']]],
            '/settings/fields' => ['fields' => $fields],
            '/settings/layouts' => ['layouts' => []],
            '/settings/related_lists' => ['related_lists' => []],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);
        $service = app(ZohoInventoryService::class);

        $gapped = $service->inventory(['leads']);

        $this->assertTrue($gapped->complete);
        $this->assertSame(0, $gapped->failedModules);
        $this->assertSame('degraded', $gapped->status);
        $this->assertSame('mapping_gap', $gapped->modules['leads']['state']);
        $this->assertSame(['industry' => ['Secteur_Activit']], $gapped->modules['leads']['mapping_gaps']);
        $this->assertFalse($service->markReviewed('leads', $gapped->modules['leads']['schema_hash']));
        $this->assertSame(['industry' => ['Secteur_Activit']], ZohoFieldManifest::where('module', 'leads')->sole()->mapping_gaps);

        $transport->responses['/settings/fields'] = ['fields' => $this->completeLeadFields()];
        $resolved = $service->inventory(['leads']);

        $this->assertSame([], $resolved->modules['leads']['mapping_gaps']);
        $this->assertTrue($service->markReviewed('leads', $resolved->modules['leads']['schema_hash']));
        $this->assertSame('verified', ZohoFieldManifest::where('module', 'leads')->where('is_current', true)->sole()->drift_state);
    }

    public function test_root_module_inventory_failure_degrades_every_requested_module_without_fake_204s(): void
    {
        $transport = new InventoryTransport(['/settings/modules' => ['status' => 503]]);
        $this->app->instance(ZohoTransport::class, $transport);

        $report = app(ZohoInventoryService::class)->inventory(['leads', 'accounts']);

        $this->assertSame(2, $report->failedModules);
        $this->assertSame(503, $report->modules['leads']['status']);
        $this->assertSame(503, $report->modules['accounts']['status']);
        $this->assertSame([], $report->modules['leads']['mapping_gaps']);
        $this->assertFalse($report->complete);
        $this->assertSame('degraded', $report->status);

        $this->app->instance(ZohoTransport::class, new InventoryTransport(['/settings/modules' => ['status' => 204]]));
        $emptyRoot = app(ZohoInventoryService::class)->inventory(['leads']);
        $this->assertSame(1, $emptyRoot->failedModules);
        $this->assertFalse($emptyRoot->complete);
        $this->assertSame(204, $emptyRoot->modules['leads']['status']);
    }

    public function test_related_list_metadata_is_persisted_in_the_manifest(): void
    {
        $transport = new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Leads']]],
            '/settings/fields' => ['fields' => [['api_name' => 'Last_Name', 'data_type' => 'text']]],
            '/settings/layouts' => ['layouts' => []],
            '/settings/related_lists' => ['related_lists' => [['api_name' => 'Notes', 'display_label' => 'Notes', 'module' => 'Notes', 'href' => 'secret-path']]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        app(ZohoInventoryService::class)->inventory(['leads']);

        $related = ZohoFieldManifest::where('module', 'leads')->sole()->related_lists;
        $this->assertEquals([['api_name' => 'Notes', 'display_label' => 'Notes', 'module' => 'Notes']], $related);
    }

    public function test_notes_inventory_accepts_related_lists_no_content_and_persists_empty_metadata(): void
    {
        $this->app->instance(ZohoTransport::class, new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Notes']]],
            '/settings/fields' => ['fields' => [
                ['api_name' => 'Note_Title', 'data_type' => 'text'],
                ['api_name' => 'Created_Time', 'data_type' => 'datetime'],
            ]],
            '/settings/layouts' => ['layouts' => []],
            '/settings/related_lists' => ['status' => 204],
        ]));

        $report = app(ZohoInventoryService::class)->inventory(['notes']);

        $this->assertTrue($report->complete);
        $this->assertSame(0, $report->failedModules);
        $this->assertSame('healthy', $report->status);
        $this->assertSame('verified', $report->modules['notes']['state']);
        $this->assertSame(0, $report->modules['notes']['related_lists']);
        $this->assertSame([], ZohoFieldManifest::where('module', 'notes')->sole()->related_lists);
    }

    public function test_missing_expected_modules_and_malformed_metadata_degrade_while_activation_gates_are_explicit(): void
    {
        $this->app->instance(ZohoTransport::class, new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Accounts']]],
        ]));
        $missing = app(ZohoInventoryService::class)->inventory(['leads']);
        $this->assertFalse($missing->complete);
        $this->assertSame(1, $missing->failedModules);
        $this->assertSame(404, $missing->modules['leads']['status']);
        $this->assertSame('missing', $missing->modules['leads']['state']);

        $this->app->instance(ZohoTransport::class, new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Users']]],
        ]));
        $gated = app(ZohoInventoryService::class)->inventory(['users']);
        $this->assertTrue($gated->complete);
        $this->assertSame(0, $gated->failedModules);
        $this->assertSame('gated', $gated->modules['users']['state']);
        $this->assertSame([], $gated->modules['users']['mapping_gaps']);

        $this->app->instance(ZohoTransport::class, new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Leads']]],
            '/settings/fields' => ['unexpected' => []],
            '/settings/layouts' => ['layouts' => []],
            '/settings/related_lists' => ['related_lists' => []],
        ]));
        $malformed = app(ZohoInventoryService::class)->inventory(['leads']);
        $this->assertFalse($malformed->complete);
        $this->assertSame('malformed', $malformed->modules['leads']['state']);
    }

    public function test_currencies_are_derived_only_from_the_live_currency_field_without_a_hardcoded_allowlist(): void
    {
        $this->app->instance(ZohoTransport::class, new InventoryTransport([
            '/settings/modules' => ['modules' => [['api_name' => 'Leads']]],
            '/settings/fields' => ['fields' => [
                ['api_name' => 'Currency', 'data_type' => 'picklist', 'pick_list_values' => [
                    ['display_value' => 'Pound sterling', 'actual_value' => 'GBP'],
                    ['display_value' => 'invalid', 'actual_value' => 'NOT-CURRENCY'],
                ]],
                ['api_name' => 'Lead_Status', 'data_type' => 'picklist', 'pick_list_values' => [
                    ['display_value' => 'USD label', 'actual_value' => 'USD'],
                ]],
            ]],
            '/settings/layouts' => ['layouts' => []],
            '/settings/related_lists' => ['related_lists' => []],
        ]));

        $report = app(ZohoInventoryService::class)->inventory(['leads']);

        $this->assertSame(['GBP'], $report->modules['leads']['currencies']);
    }

    /** @param list<array<string,mixed>> $extra */
    private function completeLeadFields(array $extra = []): array
    {
        $apiNames = [
            'Phone', 'Secteur_Activit', 'Converted_Account', 'Converted_Contact', 'Converted_Deal',
            'Converted_Date_Time', 'Intitul_de_Poste', 'Type_de_client', 'Type_de_transport_utilis',
            'Langue', 'Adresse', 'City', 'Website', 'Incoterm', 'Destination', 'Volume',
            'Prestataire_Concurrent', 'Provenance_Destination', 'Observation', 'Email_Opt_Out',
            'Unsubscribed_Mode', 'Unsubscribed_Time', 'Last_Activity_Time', 'Tag', 'Mobile', 'T_l_phone_2',
        ];

        return [...$extra, ...array_map(
            fn (string $apiName): array => ['api_name' => $apiName, 'data_type' => 'text'],
            $apiNames,
        )];
    }
}

final class InventoryTransport implements ZohoTransport
{
    /** @var list<string> */
    public array $paths = [];

    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(public array $responses) {}

    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        $this->paths[] = $path;
        $payload = $this->responses[$path] ?? [];
        $status = (int) ($payload['status'] ?? 200);

        return new TransportResult($status, $status < 300 ? (array) ($payload['data'] ?? []) : [], $status < 300 ? (array) ($payload['info'] ?? []) : [], [], $correlationId ?? 'inventory-test', [], $status < 300 ? null : 'INVENTORY_FAILED', $status < 300 ? $payload : []);
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        return $this->get($path, $query, $correlationId);
    }
}
