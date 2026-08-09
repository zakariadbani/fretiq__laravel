<?php

namespace App\Services\Zoho\V2\Reconciliation;

use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Support\Facades\Schema;

final class ZohoDataQualityMetrics
{
    /** @var array<string,list<string>> */
    private array $columns = [];

    public function __construct(private readonly ZohoModuleRegistry $registry) {}

    /** @return array<string,int> */
    public function forModule(string $moduleKey, int $staleMinutes = 180): array
    {
        $definition = $this->registry->get($moduleKey);
        if (! $this->hasColumn($definition->table, 'zoho_deleted_at')) {
            return [];
        }

        $query = $definition->modelClass::current();
        if ($definition->activityType && $this->hasColumn($definition->table, 'activity_type')) {
            $query->where('activity_type', $definition->activityType);
        }
        $base = clone $query;
        $selects = [];

        if ($this->hasColumn($definition->table, 'owner_zoho_id')) {
            $selects['missing_owner'] = 'owner_zoho_id IS NULL';
        }
        if ($this->hasColumn($definition->table, 'last_synced_at')) {
            $selects['stale'] = 'last_synced_at IS NULL OR last_synced_at < ?';
        }
        foreach (['email', 'account_zoho_id', 'stage', 'currency_code'] as $field) {
            if ($this->hasColumn($definition->table, $field)) {
                $selects['missing_'.$field] = $field.' IS NULL';
            }
        }
        $orphanAliases = [];
        foreach ($this->relations($moduleKey) as $lookup => $target) {
            if (! $this->hasColumn($definition->table, $lookup) || ! $this->hasColumn($target, 'zoho_id')) {
                continue;
            }
            $alias = 'orphan_'.count($orphanAliases);
            $activeTarget = $this->hasColumn($target, 'zoho_deleted_at') ? ' AND '.$target.'.zoho_deleted_at IS NULL' : '';
            $selects[$alias] = $definition->table.'.'.$lookup.' IS NOT NULL AND NOT EXISTS (SELECT 1 FROM '.$target.' WHERE '.$target.'.zoho_id = '.$definition->table.'.'.$lookup.$activeTarget.')';
            $orphanAliases[] = $alias;
        }
        if ($moduleKey === 'quotes') {
            $selects['missing_quote_items'] = 'NOT EXISTS (SELECT 1 FROM zoho_quote_items WHERE zoho_quote_items.zoho_quote_id = zoho_quotes.zoho_id AND zoho_quote_items.zoho_deleted_at IS NULL)';
        }
        $metrics = [];
        if ($selects !== []) {
            $sql = [];
            $bindings = [];
            foreach ($selects as $alias => $condition) {
                $sql[] = 'SUM(CASE WHEN ('.$condition.') THEN 1 ELSE 0 END) AS '.$alias;
                if (str_contains($condition, '?')) {
                    $bindings[] = now()->subMinutes($staleMinutes);
                }
            }
            $row = (clone $base)->selectRaw(implode(', ', $sql), $bindings)->first();
            foreach (array_keys($selects) as $alias) {
                $metrics[$alias] = (int) ($row?->{$alias} ?? 0);
            }
        }
        $metrics['orphans'] = array_sum(array_map(fn (string $alias): int => $metrics[$alias] ?? 0, $orphanAliases));
        foreach ($orphanAliases as $alias) {
            unset($metrics[$alias]);
        }

        return $metrics;
    }

    /** @return array<string,string> */
    private function relations(string $moduleKey): array
    {
        return match ($moduleKey) {
            'leads' => ['account_zoho_id' => 'zoho_accounts', 'contact_zoho_id' => 'zoho_contacts'],
            'contacts' => ['account_zoho_id' => 'zoho_accounts'],
            'deals' => ['account_zoho_id' => 'zoho_accounts', 'contact_zoho_id' => 'zoho_contacts'],
            'quotes' => ['account_zoho_id' => 'zoho_accounts', 'contact_zoho_id' => 'zoho_contacts', 'deal_zoho_id' => 'zoho_deals'],
            'quoted_items' => ['zoho_quote_id' => 'zoho_quotes', 'product_zoho_id' => 'zoho_products'],
            'tasks', 'events', 'calls', 'notes' => ['contact_zoho_id' => 'zoho_contacts'],
            default => [],
        };
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (! array_key_exists($table, $this->columns)) {
            $this->columns[$table] = Schema::getColumnListing($table);
        }

        return in_array($column, $this->columns[$table], true);
    }
}
