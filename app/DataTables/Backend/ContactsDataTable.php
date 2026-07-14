<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactsDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'email' => [
            'title'      => 'Email',
            'orderable'  => true,
            'searchable' => true,
        ],
        'position' => [
            'title'      => 'Poste',
            'orderable'  => true,
            'searchable' => true,
        ],
        'company' => [
            'title'      => 'Entreprise',
            'orderable'  => false,
            'searchable' => true,
            'raw'        => true,
        ],
        'status' => [
            'title'      => 'Statut',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'legal_basis' => [
            'title'      => 'Légal',
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
            'configKey' => 'contact_statuses',
            'title'     => 'Statut',
        ],
        'email_kind' => [
            'type'      => 'select_enum',
            'filterKey' => 'email_kind',
            'configKey' => 'contact_email_kinds',
            'title'     => 'Type d\'email',
        ],
        'source' => [
            'type'      => 'select_enum',
            'filterKey' => 'source',
            'configKey' => 'contact_sources',
            'title'     => 'Source',
        ],
    ];

    public function __construct(Contact $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Get query source — eager-load company relation.
     */
    public function query()
    {
        return $this->currentModel->newQuery()->with('company');
    }

    /**
     * Render company as a link, status and legal_basis as coloured badges.
     */
    protected function createEditColumns(): void
    {
        $statuses    = config('global.data.contact_statuses', []);
        $legalBases  = config('global.data.contact_legal_bases', []);

        $this->datatables->filterColumn('company', function ($query, $keyword) {
            $kw = '%' . mb_strtolower($keyword) . '%';
            $query->whereHas('company', function ($q) use ($kw) {
                $q->whereRaw('LOWER(companies.name) LIKE ?', [$kw])
                  ->orWhereRaw('LOWER(companies.domain) LIKE ?', [$kw]);
            });
        });

        $this->datatables->editColumn('company', function (Contact $row) {
            if (empty($row->company_id) || $row->company === null) {
                return '<span class="text-muted">—</span>';
            }

            $url  = route('admin.companies.view', $row->company_id);
            $name = e($row->company->name);

            return '<a href="' . $url . '" class="text-gray-900 text-hover-primary">' . $name . '</a>';
        });

        $this->datatables->editColumn('status', function (Contact $row) use ($statuses) {
            if (empty($row->status)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $statuses[$row->status] ?? [];
            $label = $cfg['label'] ?? $row->status;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });

        $this->datatables->editColumn('legal_basis', function (Contact $row) use ($legalBases) {
            if (empty($row->legal_basis)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $legalBases[$row->legal_basis] ?? [];
            $label = $cfg['label'] ?? $row->legal_basis;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });
    }

    protected function getEntityName(): string
    {
        return 'contact';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer ce contact ?',
            'deleteSuccess'  => 'Contact supprimé avec succès',
        ];
    }
}
