<?php

declare(strict_types=1);

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Suppression;
use Illuminate\Http\Request;

class SuppressionsDataTable extends BackendDataTable
{
    protected $columns = [
        'email' => [
            'title'      => 'Email',
            'orderable'  => true,
            'searchable' => true,
        ],
        'reason' => [
            'title'      => 'Motif',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'source' => [
            'title'      => 'Source',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'created_at' => [
            'title'      => 'Supprimé le',
            'orderable'  => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
        'reason' => [
            'type'      => 'select_enum',
            'filterKey' => 'reason',
            'configKey' => 'suppression_reasons',
            'title'     => 'Motif',
        ],
        'source' => [
            'type'      => 'select_enum',
            'filterKey' => 'source',
            'configKey' => 'suppression_sources',
            'title'     => 'Source',
        ],
    ];

    public function __construct(Suppression $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Get query source for DataTable.
     */
    public function query()
    {
        return $this->currentModel->newQuery();
    }

    /**
     * Render reason and source as coloured badges.
     */
    protected function createEditColumns(): void
    {
        $reasons = config('global.data.suppression_reasons', []);
        $sources = config('global.data.suppression_sources', []);

        $this->datatables->editColumn('reason', function (Suppression $row) use ($reasons) {
            if (empty($row->reason)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $reasons[$row->reason] ?? [];
            $label = $cfg['label'] ?? $row->reason;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });

        $this->datatables->editColumn('source', function (Suppression $row) use ($sources) {
            if (empty($row->source)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $sources[$row->source] ?? [];
            $label = $cfg['label'] ?? $row->source;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });
    }

    protected function dataTableLanguage(): array
    {
        $emptyTable = 'Aucune suppression enregistrée.';

        if (auth()->user()?->can('create suppressions')) {
            $emptyTable .= ' <a href="' . e(route('admin.suppressions.create')) . '">Ajouter une suppression</a>';
        }

        return array_replace(parent::dataTableLanguage(), [
            'emptyTable' => $emptyTable,
            'zeroRecords' => 'Aucune suppression ne correspond à ces filtres. Modifiez ou réinitialisez les filtres.',
        ]);
    }

    protected function getEntityName(): string
    {
        return 'suppression';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer cette suppression ?',
            'deleteSuccess' => 'Suppression retirée avec succès',
        ];
    }
}
