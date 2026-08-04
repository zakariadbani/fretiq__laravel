<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DataTables\GlobalDataTable;
use PHPUnit\Framework\TestCase;

class GlobalDataTableResponsivePriorityTest extends TestCase
{
    public function test_defaults_keep_business_status_and_actions_before_technical_id(): void
    {
        $table = new ResponsivePriorityDataTable();
        $columns = collect($table->columnsForTest())->keyBy(fn ($column) => $column['data']);

        $this->assertSame(10000, $columns['id']['responsivePriority']);
        $this->assertSame(1, $columns['name']['responsivePriority']);
        $this->assertSame(2, $columns['is_active']['responsivePriority']);
        $this->assertSame(4, $columns['notes']['responsivePriority']);
        $this->assertSame(9, $columns['explicit']['responsivePriority']);
        $this->assertSame(3, $columns['action']['responsivePriority']);
    }
}

class ResponsivePriorityDataTable extends GlobalDataTable
{
    protected $columns = [
        'name' => ['title' => 'Nom'],
        'is_active' => ['title' => 'Actif'],
        'notes' => ['title' => 'Notes'],
        'explicit' => ['title' => 'Explicite', 'priority' => 9],
    ];

    public function columnsForTest(): array
    {
        return $this->getColumns();
    }
}