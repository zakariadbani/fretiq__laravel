<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\ProspectCriteria;
use Illuminate\Http\Request;

class ProspectCriteriaDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'sectors' => [
            'title'      => 'Secteurs',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'countries' => [
            'title'      => 'Pays',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'daily_limit' => [
            'title'      => 'Limite/jour',
            'orderable'  => true,
            'searchable' => false,
        ],
        'is_active' => [
            'title'      => 'Actif',
            'orderable'  => true,
            'searchable' => false,
            'switch'     => true,
            'typetoggle' => 'status',
            'raw'        => true,
        ],
        'created_at' => [
            'title'      => 'Créé le',
            'orderable'  => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
        'is_active' => [
            'type'      => 'int',
            'filterKey' => 'is_active',
            'title'     => 'Actif',
        ],
    ];

    public function __construct(ProspectCriteria $model, Request $request)
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
     * Render sectors as badge spans (escaped) and countries as escaped comma list.
     * Appends a "Lancer la découverte" button to the standard action column.
     * The is_active switch column is handled automatically by the base class.
     */
    protected function createEditColumns(): void
    {
        $this->datatables->editColumn('sectors', function (ProspectCriteria $row) {
            $sectors = is_array($row->sectors) ? $row->sectors : [];
            if (empty($sectors)) {
                return '<span class="text-muted">—</span>';
            }
            $badges = array_map(fn($s) => '<span class="badge badge-light-primary me-1">' . e(trim($s)) . '</span>', $sectors);
            return implode('', $badges);
        });

        $this->datatables->editColumn('countries', function (ProspectCriteria $row) {
            $countries = is_array($row->countries) ? $row->countries : [];
            if (empty($countries)) {
                return '<span class="text-muted">—</span>';
            }
            return e(implode(', ', array_map('trim', $countries)));
        });

        // Extend the action column with a "Lancer la découverte" button (permission-gated).
        $this->datatables->editColumn('action', function (ProspectCriteria $row) {
            $user = auth()->user();
            $id   = (int) $row->id;
            $csrf = e(csrf_token());
            $html = '<div class="d-flex justify-content-end flex-shrink-0">';

            // Discover button (run discovery permission)
            if ($user?->can('run discovery')) {
                $html .= '<button type="button"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-success btn-sm me-1"'
                    . ' onclick="launchDiscovery(' . $id . ', \'' . $csrf . '\')"'
                    . ' data-bs-toggle="tooltip"'
                    . ' title="Lancer la d&#233;couverte">'
                    . '<i class="bi bi-play-fill fs-4"></i>'
                    . '</button>';
            }

            // Edit button
            if ($user?->can('edit prospect_criteria')) {
                $html .= '<a href="' . route('admin.prospect_criteria.edit', $id) . '"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"'
                    . ' data-bs-toggle="tooltip" title="Modifier">'
                    . '<i class="bi bi-pencil fs-4"></i>'
                    . '</a>';
            }

            // View button
            if ($user?->can('view prospect_criteria')) {
                $html .= '<a href="' . route('admin.prospect_criteria.view', $id) . '"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"'
                    . ' data-bs-toggle="tooltip" title="Voir">'
                    . '<i class="bi bi-eye fs-4"></i>'
                    . '</a>';
            }

            // Delete button
            if ($user?->can('delete prospect_criteria')) {
                $html .= '<a href="javascript:void(0);"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm delete-btn"'
                    . ' data-id="' . $id . '"'
                    . ' data-url="' . route('admin.prospect_criteria.delete', $id) . '"'
                    . ' data-bs-toggle="tooltip" title="Supprimer">'
                    . '<i class="bi bi-trash fs-4"></i>'
                    . '</a>';
            }

            $html .= '</div>';
            return $html;
        });
    }

    /**
     * Return the JS tableId that matches the HTML table id set by html().
     * html() calls setTableId(getName() . '-table') → 'prospect_criteria-table'.
     * getDataTable() appends '-table', so we return 'prospect_criteria' here.
     * The base class default (strtolower(class_basename(model))) gives
     * 'prospectcriteria' which does NOT match — hence this override.
     */
    protected function getTableId(): string
    {
        return 'prospect_criteria';
    }

    protected function getEntityName(): string
    {
        return 'critère';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer ce critère ?',
            'deleteSuccess'  => 'Critère supprimé avec succès',
        ];
    }
}
