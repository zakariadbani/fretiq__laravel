<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Sector;
use Illuminate\Http\Request;

class SectorsDataTable extends BackendDataTable
{
    protected $columns = [
        'label' => [
            'title' => 'Libellé',
            'orderable' => true,
            'searchable' => true,
        ],
        'is_active' => [
            'title' => 'Actif',
            'orderable' => true,
            'searchable' => false,
            'switch' => true,
            'typetoggle' => 'status',
            'raw' => true,
        ],
        'use_in_discovery' => [
            'title' => 'Utilisé en découverte',
            'orderable' => true,
            'searchable' => false,
            'switch' => true,
            'typetoggle' => 'status',
            'raw' => true,
        ],
        'sort_order' => [
            'title' => 'Ordre',
            'orderable' => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
        'is_active' => [
            'type' => 'int',
            'filterKey' => 'is_active',
            'title' => 'Actif',
        ],
        'use_in_discovery' => [
            'type' => 'int',
            'filterKey' => 'use_in_discovery',
            'title' => 'Utilisé en découverte',
        ],
    ];

    public function __construct(Sector $model, Request $request)
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

    protected function dataTableLanguage(): array
    {
        $emptyTable = 'Aucun secteur enregistré.';

        if (auth()->user()?->can('create sectors')) {
            $emptyTable .= ' <a href="' . e(route('admin.sectors.create')) . '">Ajouter un secteur</a>';
        }

        return array_replace(parent::dataTableLanguage(), [
            'emptyTable' => $emptyTable,
            'zeroRecords' => 'Aucun secteur ne correspond à ces filtres. Modifiez ou réinitialisez les filtres.',
        ]);
    }

    protected function getEntityName(): string
    {
        return 'secteur';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer ce secteur ?',
            'deleteSuccess' => 'Secteur supprimé avec succès',
        ];
    }
}
