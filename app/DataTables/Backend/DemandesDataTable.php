<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Demande;
use Illuminate\Http\Request;

class DemandesDataTable extends BackendDataTable
{
    protected $columns = [
        'contact' => [
            'title'      => 'Contact',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'company' => [
            'title'      => 'Entreprise',
            'orderable'  => false,
            'searchable' => false,
        ],
        'source' => [
            'title'      => 'Source',
            'orderable'  => false,
            'searchable' => false,
        ],
        'kind' => [
            'title'      => 'Type',
            'orderable'  => true,
            'searchable' => false,
        ],
        'status' => [
            'title'      => 'Statut',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'captured_at' => [
            'title'      => 'Capturée le',
            'orderable'  => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
        'status' => [
            'type'      => 'select_enum',
            'filterKey' => 'status',
            'configKey' => 'demande_statuses',
            'title'     => 'Statut',
        ],
    ];

    public function __construct(Demande $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Eager-load contact with company, campaign, sequence.
     */
    public function query()
    {
        return $this->currentModel->newQuery()
            ->with(['contact.company', 'campaign', 'sequence']);
    }

    protected function createEditColumns(): void
    {
        $demandeStatuses = config('global.data.demande_statuses', []);

        $this->datatables->editColumn('contact', function (Demande $row) {
            if (!$row->contact) {
                return '<span class="text-muted">—</span>';
            }
            $email = e($row->contact->email);
            $url   = route('admin.contacts.view', $row->contact_id);
            return '<a href="' . $url . '" class="text-primary fw-semibold">' . $email . '</a>';
        });

        $this->datatables->addColumn('company', function (Demande $row) {
            return $row->contact?->company?->name ?? '—';
        });

        $this->datatables->addColumn('source', function (Demande $row) {
            if ($row->campaign) {
                return 'Campagne : ' . e($row->campaign->name);
            }
            if ($row->sequence) {
                return 'Séquence : ' . e($row->sequence->name);
            }
            return '—';
        });

        $this->datatables->editColumn('status', function (Demande $row) use ($demandeStatuses) {
            if (empty($row->status)) {
                return '<span class="badge badge-light-secondary">—</span>';
            }
            $cfg   = $demandeStatuses[$row->status] ?? [];
            $label = $cfg['label'] ?? $row->status;
            $color = $cfg['color'] ?? 'secondary';
            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });

        $this->datatables->editColumn('captured_at', function (Demande $row) {
            return $row->captured_at ? $row->captured_at->format('d/m/Y H:i') : '—';
        });
    }

    protected function getEntityName(): string
    {
        return 'demande';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer cette demande ?',
            'deleteSuccess' => 'Demande supprimée avec succès',
        ];
    }
}
