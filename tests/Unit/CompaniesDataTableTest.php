<?php

namespace Tests\Unit;

use App\DataTables\Backend\CompaniesDataTable;
use PHPUnit\Framework\TestCase;

class CompaniesDataTableTest extends TestCase
{
    private function defaults(): array
    {
        return (new \ReflectionClass(CompaniesDataTable::class))->getDefaultProperties();
    }

    public function test_enrichment_status_column_is_registered_as_a_raw_badge_column(): void
    {
        $column = $this->defaults()['columns']['enrichment_status'] ?? null;

        $this->assertNotNull($column, 'Enrichissement column must be registered.');
        $this->assertSame('Enrichissement', $column['title']);
        $this->assertTrue(
            $column['raw'] ?? false,
            'The column renders a badge, so it must be raw — otherwise the HTML is escaped.'
        );
        $this->assertFalse(
            $column['searchable'] ?? true,
            'Enum badge columns are filtered, not free-text searched (matches qualification_status).'
        );
    }

    public function test_enrichment_status_column_sits_next_to_qualification_status(): void
    {
        $keys = array_keys($this->defaults()['columns']);

        $this->assertContains('enrichment_status', $keys);
        $this->assertGreaterThan(
            array_search('qualification_status', $keys, true),
            array_search('enrichment_status', $keys, true),
            'Enrichissement must follow the qualification badge, before the contacts count.'
        );
    }

    public function test_enrichment_status_filter_uses_the_shared_enum_config(): void
    {
        $filter = $this->defaults()['table_filters']['enrichment_status'] ?? null;

        $this->assertNotNull($filter, 'Enrichissement filter must be registered.');
        $this->assertSame('select_enum', $filter['type']);
        $this->assertSame('enrichment_status', $filter['filterKey']);
        $this->assertSame(
            'company_enrichment_statuses',
            $filter['configKey'],
            'Labels/colours must come from config/global/data.php, not be duplicated here.'
        );
    }
}
