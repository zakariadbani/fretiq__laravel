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
        'is_active' => [
            'title'      => 'Actif',
            'orderable'  => true,
            'searchable' => false,
            'switch'     => true,
            'typetoggle' => 'status',
            'raw'        => true,
        ],
        'segment_id' => [
            'title'      => 'Segment',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'template_id' => [
            'title'      => 'Modèle',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'sender_identity_id' => [
            'title'      => 'Expéditeur',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'next_run_at' => [
            'title'      => 'Prochaine occurrence',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'runs_count' => [
            'title'      => 'Exécutions',
            'orderable'  => true,
            'searchable' => false,
        ],
        'stats_sent_total' => [
            'title'      => 'Envoyés',
            'orderable'  => true,
            'searchable' => false,
        ],
        'created_at' => [
            'title'      => 'Créé le',
            'orderable'  => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
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
        return $this->currentModel->newQuery()
            ->with(['segment', 'template', 'senderIdentity'])
            ->withCount('runs')
            ->withSum('runs as stats_sent_total', 'stats_sent');
    }

    /**
     * Render schedule_type and status as coloured badges.
     */
    protected function createEditColumns(): void
    {
        $scheduleTypes     = config('global.data.schedule_types', []);

        $this->datatables->editColumn('schedule_type', function (Campaign $row) use ($scheduleTypes) {
            if (empty($row->schedule_type)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $scheduleTypes[$row->schedule_type] ?? [];
            $label = $cfg['label'] ?? $row->schedule_type;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });

        $this->datatables->editColumn('is_active', function (Campaign $row) {
            // Sequence campaigns: pause is controlled via sequence.is_active — static badge.
            if ($row->schedule_type === 'sequence') {
                return '<span class="badge badge-light-info">Via séquence</span>';
            }

            return view('backend.components.datatable.status', [
                'model'      => $row,
                'name'       => 'is_active',
                'typetoggle' => 'status',
            ])->render();
        });

        $this->datatables->editColumn('segment_id', function (Campaign $row) {
            return e($row->segment?->name ?? '—');
        });

        $this->datatables->editColumn('template_id', function (Campaign $row) {
            return e($row->template?->name ?? '—');
        });

        $this->datatables->editColumn('sender_identity_id', function (Campaign $row) {
            return e($row->senderIdentity?->name ?? '—');
        });

        $this->datatables->editColumn('next_run_at', function (Campaign $row) {
            if ($row->next_run_at instanceof \Carbon\Carbon) {
                return $row->next_run_at->format('d/m/Y H:i');
            }
            if ($row->scheduled_at instanceof \Carbon\Carbon) {
                return $row->scheduled_at->format('d/m/Y H:i');
            }
            return '—';
        });

        $this->datatables->editColumn('runs_count', function (Campaign $row) {
            return (int) ($row->runs_count ?? 0);
        });

        $this->datatables->editColumn('stats_sent_total', function (Campaign $row) {
            return (int) ($row->stats_sent_total ?? 0);
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
