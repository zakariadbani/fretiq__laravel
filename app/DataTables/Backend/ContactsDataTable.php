<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Contact;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\ContactLifecycleService;
use App\Support\OriginResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
        'origine' => [
            'title' => 'Origine',
            'orderable' => false,
            'searchable' => false,
            'raw' => true,
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
        $query = $this->currentModel->newQuery()
            ->addSelect([
                // Own batch pivot — correlated subquery, no per-row query.
                'own_batch_id' => ProspectBatchContact::query()
                    ->select('prospect_batch_id')
                    ->whereColumn('contact_id', 'contacts.id')
                    ->latest('id')
                    ->limit(1),
            ])
            ->with(['company' => function ($relation) {
                $relation->addSelect([
                    'origin_batch_id' => ProspectBatchItem::query()
                        ->select('prospect_batch_id')
                        ->whereColumn('company_id', 'companies.id')
                        ->latest('id')
                        ->limit(1),
                ]);
            }]);

        return $this->lifecycle->select($query);
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

        // ── Origine badge — own batch pivot wins, else falls back to the company's ──
        $this->datatables->addColumn('origine', function (Contact $row) {
            $company = $row->company;
            $origin = OriginResolver::forContact(
                $row->source,
                $row->own_batch_id ?? null,
                $company?->source,
                $company?->criteria_id,
                $company?->origin_batch_id ?? null,
            );
            $label = e($origin['label']);

            if ($origin['route'] !== null && Route::has($origin['route'])) {
                $href = e(route($origin['route'], $origin['id']));

                return '<a href="'.$href.'" class="badge badge-light-info text-hover-primary">'.$label.'</a>';
            }

            return '<span class="badge badge-light-secondary">'.$label.'</span>';
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
