<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\ProspectBatch;
use Illuminate\Http\Request;

class ProspectBatchesDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => ['title' => 'Lot', 'orderable' => true, 'searchable' => true, 'raw' => true],
        'source_type' => ['title' => 'Source', 'orderable' => true, 'searchable' => false, 'raw' => true],
        'status' => ['title' => 'Statut', 'orderable' => true, 'searchable' => false, 'raw' => true],
        'total_items' => ['title' => 'Entreprises', 'orderable' => true, 'searchable' => false],
        'processed_items' => ['title' => 'Progression', 'orderable' => true, 'searchable' => false, 'raw' => true],
        'candidate_contacts' => ['title' => 'Contacts', 'orderable' => true, 'searchable' => false],
        'created_at' => ['title' => 'Créé le', 'orderable' => true, 'searchable' => false],
    ];

    protected $table_filters = [
        'source_type' => [
            'type' => 'select_enum',
            'filterKey' => 'source_type',
            'configKey' => 'prospect_batch_sources',
            'title' => 'Source',
        ],
        'status' => [
            'type' => 'select_enum',
            'filterKey' => 'status',
            'configKey' => 'prospect_batch_statuses',
            'title' => 'Statut',
        ],
    ];

    public function __construct(ProspectBatch $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    public function query()
    {
        $query = $this->currentModel->newQuery()->select([
            'id',
            'name',
            'created_by',
            'source_type',
            'status',
            'total_items',
            'processed_items',
            'candidate_contacts',
            'created_at',
        ]);
        $user = request()->user();
        if ($user !== null && ! $user->hasRole(['admin', 'superadmin'])) {
            $query->where('created_by', $user->getKey());
        }

        return $query;
    }

    protected function createEditColumns(): void
    {
        $statuses = self::statuses();
        $sources = self::sources();

        $this->datatables->editColumn('name', static function (ProspectBatch $row): string {
            return '<a class="text-gray-900 text-hover-primary fw-semibold" href="'.e(route('admin.prospect_batches.view', $row)).'">'.e($row->name).'</a>';
        });
        $this->datatables->editColumn('source_type', static function (ProspectBatch $row) use ($sources): string {
            $cfg = $sources[$row->source_type] ?? ['label' => $row->source_type, 'color' => 'secondary'];

            return '<span class="badge badge-light-'.e($cfg['color']).'">'.e($cfg['label']).'</span>';
        });
        $this->datatables->editColumn('status', static function (ProspectBatch $row) use ($statuses): string {
            $cfg = $statuses[$row->status] ?? ['label' => $row->status, 'color' => 'secondary'];

            return '<span class="badge badge-light-'.e($cfg['color']).'">'.e($cfg['label']).'</span>';
        });
        $this->datatables->editColumn('processed_items', static function (ProspectBatch $row): string {
            $total = max(0, (int) $row->total_items);
            $processed = max(0, min($total, (int) $row->processed_items));
            $percent = $total > 0 ? (int) floor(($processed / $total) * 100) : 0;

            return '<div class="min-w-125px"><div class="d-flex justify-content-between fs-8 mb-1"><span>'.e("{$processed}/{$total}").'</span><span>'.e("{$percent}%").'</span></div><div class="progress h-4px"><div class="progress-bar bg-primary" style="width: '.e((string) $percent).'%"></div></div></div>';
        });
    }

    protected function getEntityName(): string
    {
        return 'prospect_batch';
    }

    protected function getMessages(): array
    {
        return [
            'deleteConfirm' => 'Supprimer ce lot brouillon ?',
            'deleteSuccess' => 'Lot supprimé.',
        ];
    }

    /** @return array<string, array{label:string,color:string}> */
    private static function statuses(): array
    {
        return config('global.data.prospect_batch_statuses', [
            'draft' => ['label' => 'Brouillon', 'color' => 'secondary'],
            'queued' => ['label' => 'En attente', 'color' => 'info'],
            'running' => ['label' => 'En cours', 'color' => 'primary'],
            'review' => ['label' => 'À revoir', 'color' => 'warning'],
            'completed' => ['label' => 'Terminé', 'color' => 'success'],
            'failed' => ['label' => 'Échec', 'color' => 'danger'],
            'cancelled' => ['label' => 'Annulé', 'color' => 'secondary'],
        ]);
    }

    /** @return array<string, array{label:string,color:string}> */
    private static function sources(): array
    {
        return config('global.data.prospect_batch_sources', [
            'company_list' => ['label' => 'Liste', 'color' => 'primary'],
            'discover' => ['label' => 'Discover IA', 'color' => 'info'],
            'recovery' => ['label' => 'Récupération', 'color' => 'warning'],
        ]);
    }
}
