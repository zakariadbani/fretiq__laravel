<?php

namespace App\Services\Zoho\V2\Inventory;

use App\Models\Zoho\ZohoFieldManifest;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Support\Facades\DB;

final class ZohoInventoryService
{
    public function __construct(private ZohoTransport $transport, private ZohoModuleRegistry $registry) {}

    public function inventory(?array $moduleKeys = null): InventoryReport
    {
        $modulesResponse = $this->transport->get('/settings/modules');
        $keys = $moduleKeys ?? array_keys($this->registry->all());
        $remoteModules = $modulesResponse->root('modules');
        $validModules = is_array($remoteModules) && array_is_list($remoteModules) && $remoteModules !== []
            && collect($remoteModules)->every(fn (mixed $module): bool => is_array($module) && isset($module['api_name']) && is_string($module['api_name']) && $module['api_name'] !== '');
        if (! $modulesResponse->successful() || $modulesResponse->status !== 200 || ! $validModules) {
            $status = $modulesResponse->status;
            $failedResults = [];
            foreach ($keys as $key) {
                $this->registry->get($key);
                $failedResults[$key] = ['status' => $status, 'state' => 'failed', 'schema_hash' => null, 'fields' => 0, 'layouts' => 0, 'related_lists' => 0, 'currencies' => [], 'mapping_gaps' => []];
            }

            return new InventoryReport($failedResults, count($keys), count($keys), false, 'degraded');
        }

        $remote = collect($remoteModules)->keyBy('api_name');
        $results = [];
        $failed = 0;

        foreach ($keys as $key) {
            $definition = $this->registry->get($key);
            if ($definition->activationGated) {
                $results[$key] = ['status' => 403, 'state' => 'gated', 'schema_hash' => null, 'fields' => 0, 'layouts' => 0, 'related_lists' => 0, 'currencies' => [], 'mapping_gaps' => []];

                continue;
            }
            if (! $remote->has($definition->apiName)) {
                $failed++;
                $results[$key] = ['status' => 404, 'state' => 'missing', 'schema_hash' => null, 'fields' => 0, 'layouts' => 0, 'related_lists' => 0, 'currencies' => [], 'mapping_gaps' => []];

                continue;
            }
            $fields = $this->transport->get('/settings/fields', ['module' => $definition->apiName]);
            $layouts = $this->transport->get('/settings/layouts', ['module' => $definition->apiName]);
            $related = $this->transport->get('/settings/related_lists', ['module' => $definition->apiName]);
            $fieldRows = $this->metadataRows($fields, 'fields', true);
            $layoutRows = $this->metadataRows($layouts, 'layouts');
            $relatedRows = $this->metadataRows($related, 'related_lists', allowNoContent: true);
            if ($fieldRows === null || $layoutRows === null || $relatedRows === null) {
                $failed++;
                $transportFailed = ! $fields->successful() || ! $layouts->successful() || ! $related->successful();
                $results[$key] = ['status' => $transportFailed ? max($fields->status, $layouts->status, $related->status) : 422, 'state' => $transportFailed ? 'failed' : 'malformed', 'schema_hash' => null, 'fields' => 0, 'layouts' => 0, 'related_lists' => 0, 'currencies' => [], 'mapping_gaps' => []];

                continue;
            }
            $safeFields = $this->safeFields($fieldRows);
            $safeLayouts = $this->safeLayouts($layoutRows);
            $safeRelated = $this->safeRelated($relatedRows);
            $picklists = $this->picklists($safeFields);
            $document = ['fields' => $safeFields, 'layouts' => $safeLayouts, 'related_lists' => $safeRelated, 'picklists' => $picklists];
            $hash = hash('sha256', json_encode($this->canonical($document), JSON_THROW_ON_ERROR));
            $gaps = $this->mappingGaps($definition->promotedFieldSources, $safeFields);
            $this->persist($key, $definition->submodule ?? '', $hash, $safeFields, $safeLayouts, $picklists, $safeRelated, $gaps);
            $results[$key] = ['status' => 200, 'state' => $gaps === [] ? 'verified' : 'mapping_gap', 'schema_hash' => $hash, 'fields' => count($safeFields), 'layouts' => count($safeLayouts), 'related_lists' => count($safeRelated), 'currencies' => $this->currencies($safeFields), 'mapping_gaps' => $gaps];
        }

        $hasGaps = collect($results)->contains(fn (array $result): bool => $result['state'] === 'mapping_gap');

        return new InventoryReport($results, count($keys), $failed, $failed === 0, $failed === 0 && ! $hasGaps ? 'healthy' : 'degraded');
    }

    public function markReviewed(string $module, string $schemaHash, string $submodule = ''): bool
    {
        return DB::transaction(function () use ($module, $schemaHash, $submodule): bool {
            $manifest = ZohoFieldManifest::query()
                ->where(compact('module', 'submodule'))
                ->where('schema_hash', $schemaHash)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if ($manifest === null || (array) ($manifest->mapping_gaps ?? []) !== []) {
                return false;
            }

            $manifest->update([
                'drift_state' => 'verified',
                'verified_at' => now(),
                'last_seen_at' => now(),
            ]);

            return true;
        });
    }

    private function persist(string $module, string $submodule, string $hash, array $fields, array $layouts, array $picklists, array $relatedLists, array $mappingGaps): void
    {
        DB::transaction(function () use ($module, $submodule, $hash, $fields, $layouts, $picklists, $relatedLists, $mappingGaps): void {
            $current = ZohoFieldManifest::where(compact('module', 'submodule'))->where('is_current', true)->lockForUpdate()->first();
            if ($current && $current->schema_hash === $hash) {
                $updates = ['last_seen_at' => now(), 'related_lists' => $relatedLists, 'mapping_gaps' => $mappingGaps];
                if ($current->drift_state === 'verified' && $mappingGaps === []) {
                    $updates['verified_at'] = now();
                } elseif ($mappingGaps !== []) {
                    $updates['drift_state'] = 'drifted';
                    $updates['verified_at'] = null;
                }
                $current->update($updates);

                return;
            }
            ZohoFieldManifest::where(compact('module', 'submodule'))->where('is_current', true)->update(['is_current' => false]);
            ZohoFieldManifest::updateOrCreate(
                ['module' => $module, 'submodule' => $submodule, 'schema_hash' => $hash],
                [
                    'fields' => $fields,
                    'layouts' => $layouts,
                    'picklists' => $picklists,
                    'related_lists' => $relatedLists,
                    'is_current' => true,
                    'mapping_gaps' => $mappingGaps,
                    'drift_state' => $current || $mappingGaps !== [] ? 'drifted' : 'verified',
                    'verified_at' => $current || $mappingGaps !== [] ? null : now(),
                    'last_seen_at' => now(),
                ],
            );
        });
    }

    /** @param array<string,list<string>> $requirements @param list<array<string,mixed>> $fields */
    private function mappingGaps(array $requirements, array $fields): array
    {
        $available = collect($fields)->pluck('api_name')->filter()->flip();

        return collect($requirements)->filter(fn (array $candidates): bool => collect($candidates)->every(fn (string $source): bool => ! $available->has($source)))->all();
    }

    private function safeFields(array $fields): array
    {
        return array_values(array_map(fn ($f) => array_filter(['api_name' => $f['api_name'] ?? null, 'data_type' => $f['data_type'] ?? null, 'field_label' => $f['field_label'] ?? null, 'required' => $f['required'] ?? null, 'read_only' => $f['read_only'] ?? null, 'pick_list_values' => array_map(fn ($v) => array_filter(['display_value' => $v['display_value'] ?? null, 'actual_value' => $v['actual_value'] ?? null]), (array) ($f['pick_list_values'] ?? []))], fn ($v) => $v !== null), $fields));
    }

    private function safeLayouts(array $layouts): array
    {
        return array_values(array_map(fn ($l) => array_filter(['id' => $l['id'] ?? null, 'name' => $l['name'] ?? null, 'api_name' => $l['api_name'] ?? null]), $layouts));
    }

    private function safeRelated(array $related): array
    {
        return array_values(array_map(fn ($r) => array_filter(['api_name' => $r['api_name'] ?? null, 'display_label' => $r['display_label'] ?? null, 'module' => $r['module'] ?? null]), $related));
    }

    private function picklists(array $fields): array
    {
        return array_values(array_filter(array_map(fn ($f) => empty($f['pick_list_values']) ? null : ['field' => $f['api_name'] ?? '', 'values' => $f['pick_list_values']], $fields)));
    }

    private function currencies(array $fields): array
    {
        $currency = collect($fields)->firstWhere('api_name', 'Currency');
        $values = collect($currency['pick_list_values'] ?? [])->pluck('actual_value');

        return $values->filter(fn ($value) => is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1)->unique()->sort()->values()->all();
    }

    /** @return list<array<string,mixed>>|null */
    private function metadataRows(mixed $response, string $root, bool $requireApiName = false, bool $allowNoContent = false): ?array
    {
        if ($allowNoContent && $response->successful() && $response->status === 204) {
            return [];
        }

        $rows = $response->root($root);
        if (! $response->successful() || $response->status !== 200 || ! is_array($rows) || ! array_is_list($rows)) {
            return null;
        } foreach ($rows as $row) {
            if (! is_array($row) || ($requireApiName && (! isset($row['api_name']) || ! is_string($row['api_name']) || $row['api_name'] === ''))) {
                return null;
            }
        }

        return $rows;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        } if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        } ksort($value);
        foreach ($value as $k => $v) {
            $value[$k] = $this->canonical($v);
        }

        return $value;
    }
}
