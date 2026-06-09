<?php

declare(strict_types=1);

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Sequence;
use Illuminate\Http\Request;

class SequencesDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'steps_count' => [
            'title'      => 'Étapes',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'is_active' => [
            'title'      => 'Actif',
            'orderable'  => false,
            'searchable' => false,
            'switch'     => true,
            'typetoggle' => 'status',
            'raw'        => true,
        ],
        'stop_on_reply' => [
            'title'      => 'Stop sur réponse',
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

    public function __construct(Sequence $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Eager-load steps count.
     */
    public function query()
    {
        return $this->currentModel->newQuery()->withCount('steps');
    }

    protected function createEditColumns(): void
    {
        $this->datatables->editColumn('steps_count', function (Sequence $row) {
            return '<span class="badge badge-light-primary">' . (int) $row->steps_count . '</span>';
        });

        $this->datatables->editColumn('stop_on_reply', function (Sequence $row) {
            if ($row->stop_on_reply) {
                return '<span class="badge badge-light-success">Oui</span>';
            }
            return '<span class="badge badge-light-secondary">Non</span>';
        });
    }

    protected function getEntityName(): string
    {
        return 'séquence';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer cette séquence ?',
            'deleteSuccess' => 'Séquence supprimée avec succès',
        ];
    }
}
