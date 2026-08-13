<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Contact;
use App\Services\Prospecting\ContactLifecycleService;
use Illuminate\Http\Request;

class ContactsDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title' => 'Nom',
            'orderable' => true,
            'searchable' => true,
        ],
        'email' => [
            'title' => 'Email',
            'orderable' => true,
            'searchable' => true,
        ],
        'position' => [
            'title' => 'Poste',
            'orderable' => true,
            'searchable' => true,
        ],
        'company' => [
            'title' => 'Entreprise',
            'orderable' => false,
            'searchable' => true,
            'raw' => true,
        ],
        'lifecycle_state' => [
            'title' => 'État',
            'orderable' => true,
            'searchable' => false,
            'raw' => true,
        ],
        'created_at' => [
            'title' => 'Créé le',
            'orderable' => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
        'lifecycle_state' => [
            'type' => 'select_enum',
            'filterKey' => 'lifecycle_state',
            'configKey' => 'contact_lifecycle_states',
            'title' => 'État',
        ],
        'email_kind' => [
            'type' => 'select_enum',
            'filterKey' => 'email_kind',
            'configKey' => 'contact_email_kinds',
            'title' => 'Type d\'email',
        ],
        'source' => [
            'type' => 'select_enum',
            'filterKey' => 'source',
            'configKey' => 'contact_sources',
            'title' => 'Source',
        ],
    ];

    public function __construct(Contact $model, Request $request, private readonly ContactLifecycleService $lifecycle)
    {
        parent::__construct($model, $request);
    }

    /**
     * Get query source — eager-load company relation.
     */
    public function query()
    {
        return $this->lifecycle->select($this->currentModel->newQuery()->with('company'));
    }

    /**
     * Render company as a link and the calculated lifecycle as a badge.
     */
    protected function createEditColumns(): void
    {
        $this->datatables->filterColumn('company', function ($query, $keyword) {
            $kw = '%'.mb_strtolower($keyword).'%';
            $query->whereHas('company', function ($q) use ($kw) {
                $q->whereRaw('LOWER(companies.name) LIKE ?', [$kw])
                    ->orWhereRaw('LOWER(companies.domain) LIKE ?', [$kw]);
            });
        });

        $this->datatables->editColumn('company', function (Contact $row) {
            if (empty($row->company_id) || $row->company === null) {
                return '<span class="text-muted">—</span>';
            }

            $url = route('admin.companies.view', $row->company_id);
            $name = e($row->company->name);

            return '<a href="'.$url.'" class="text-gray-900 text-hover-primary">'.$name.'</a>';
        });

        $this->datatables->filterColumn('lifecycle_state', function ($query, $state): void {
            $this->lifecycle->applyState($query, (string) $state);
        });
        $this->datatables->orderColumn('lifecycle_state', function ($query, $direction): void {
            $this->lifecycle->orderByState($query, (string) $direction);
        });
        $this->datatables->editColumn('lifecycle_state', function (Contact $row) {
            $cfg = $this->lifecycle->label((string) $row->lifecycle_state);
            $label = $cfg['label'] ?? $row->lifecycle_state;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-'.e($color).'">'.e($label).'</span>';
        });
    }

    protected function applyFilters(): void
    {
        $request = $this->currentRequest->all();
        $state = trim((string) ($request['lifecycle_state'] ?? ''));
        unset($request['lifecycle_state']);

        foreach ($this->createFilterConditions($request) as $condition) {
            $this->currentQuery->where($condition[0], $condition[1], $condition[2]);
        }

        if ($state !== '') {
            $this->lifecycle->applyState($this->currentQuery, $state);
        }
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
            'deleteSuccess' => 'Contact supprimé avec succès',
        ];
    }
}
