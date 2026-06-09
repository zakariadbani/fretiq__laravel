<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\CampaignTemplate;
use Illuminate\Http\Request;

class CampaignTemplatesDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'subject' => [
            'title'      => 'Sujet',
            'orderable'  => true,
            'searchable' => true,
        ],
        'preview_text' => [
            'title'      => 'Texte de prévisualisation',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'created_at' => [
            'title'      => 'Créé le',
            'orderable'  => true,
            'searchable' => false,
        ],
    ];

    public function __construct(CampaignTemplate $model, Request $request)
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

    protected function createEditColumns(): void
    {
        // 'raw' => true on the column definition already adds preview_text to rawColumns in the base class.
        $this->datatables->editColumn('preview_text', function (CampaignTemplate $row) {
            if (empty($row->preview_text)) {
                return '<span class="text-muted">—</span>';
            }
            return '<span class="text-truncate d-inline-block" style="max-width:200px;" title="' . e($row->preview_text) . '">'
                . e(\Str::limit($row->preview_text, 60))
                . '</span>';
        });
    }

    protected function getEntityName(): string
    {
        return 'modèle';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer ce modèle ?',
            'deleteSuccess' => 'Modèle supprimé avec succès',
        ];
    }
}
