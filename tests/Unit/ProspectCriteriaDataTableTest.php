<?php

namespace Tests\Unit;

use App\DataTables\Backend\ProspectCriteriaDataTable;
use PHPUnit\Framework\TestCase;

class ProspectCriteriaDataTableTest extends TestCase
{
    public function test_companies_count_column_is_visible_with_desktop_priority(): void
    {
        $defaults = (new \ReflectionClass(ProspectCriteriaDataTable::class))->getDefaultProperties();
        $column = $defaults['columns']['companies_count'] ?? null;

        $this->assertNotNull($column, 'Entreprises column must be registered.');
        $this->assertNotFalse($column['visible'] ?? true, 'Entreprises column must be visible.');
        $this->assertLessThanOrEqual(
            4,
            $column['priority'],
            'Entreprises column must remain visible before lower-priority columns collapse.'
        );
    }
}
