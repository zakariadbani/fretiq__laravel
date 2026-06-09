<?php

declare(strict_types=1);

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Campaign;
use Illuminate\Http\Request;

class CampaignsDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'schedule_type' => [
            'title'      => 'Type',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'status' => [
            'title'      => 'Statut',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'scheduled_at' => [
            'title'      => 'Planifié le',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'created_at' => [
            'title'      => 'Créé le',
            'orderable'  => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
        'status' => [
            'type'      => 'select_enum',
            'filterKey' => 'status',
            'configKey' => 'campaign_statuses',
            'title'     => 'Statut',
        ],
        'schedule_type' => [
            'type'      => 'select_enum',
            'filterKey' => 'schedule_type',
            'configKey' => 'schedule_types',
            'title'     => 'Type de planification',
        ],
    ];

    public function __construct(Campaign $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Get query source for DataTable — eager-load segment and template.
     */
    public function query()
    {
        return $this->currentModel->newQuery()->with(['segment', 'template']);
    }

    /**
     * Render schedule_type and status as coloured badges.
     */
    protected function createEditColumns(): void
    {
        $scheduleTypes     = config('global.data.schedule_types', []);
        $campaignStatuses  = config('global.data.campaign_statuses', []);

        $this->datatables->editColumn('schedule_type', function (Campaign $row) use ($scheduleTypes) {
            if (empty($row->schedule_type)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $scheduleTypes[$row->schedule_type] ?? [];
            $label = $cfg['label'] ?? $row->schedule_type;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });

        $this->datatables->editColumn('status', function (Campaign $row) use ($campaignStatuses) {
            if (empty($row->status)) {
                return '<span class="badge badge-light-secondary">Brouillon</span>';
            }
            $cfg   = $campaignStatuses[$row->status] ?? [];
            $label = $cfg['label'] ?? $row->status;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });

        $this->datatables->editColumn('scheduled_at', function (Campaign $row) {
            return $row->scheduled_at instanceof \Carbon\Carbon
                ? $row->scheduled_at->format('d/m/Y H:i')
                : e($row->scheduled_at ?? '—');
        });
    }

    protected function getEntityName(): string
    {
        return 'campagne';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer cette campagne ?',
            'deleteSuccess' => 'Campagne supprimée avec succès',
        ];
    }
}
