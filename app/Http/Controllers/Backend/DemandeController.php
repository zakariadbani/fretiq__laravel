<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\DemandesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Contact;
use App\Models\Demande;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DemandeController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * No toggleable boolean fields on Demande.
     *
     * @var array<string>
     */
    protected $toggleableFields = [];

    public function __construct(Request $request, Demande $model, DemandesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Wire ViewConfig — MUST be inside constructor body, never as a class property.
        // The Crudable trait declares $viewConfigClass = null; re-declaring it at class level
        // with a non-null default would be a PHP fatal (conflicting default).
        $this->viewConfigClass = \App\Crud\ViewConfigs\DemandeViewConfig::class;

        $this->middleware('permission:view demandes')->only(['index', 'view']);
        $this->middleware('permission:view contacts')->only(['contactsSearch']);
        $this->middleware('permission:create demandes')->only(['create', 'store']);
        $this->middleware('permission:edit demandes')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete demandes')->only(['delete']);

        $this->listTitle = 'Demandes';
        $this->title     = 'id';

        $this->bootResource(new BackendResource(
            modelClass:       Demande::class,
            modelName:        'demandes',
            dataTableClass:   DemandesDataTable::class,
            permissionEntity: 'demandes',
            prefixName:       'admin',
            titleField:       'id',
        ));
    }

    /**
     * Override the trait's view() to eager-load source chain and inject viewConfig.
     * Prevents N+1 on contact.company, campaign, sequence relation accesses.
     */
    public function view($id)
    {
        $model = $this->currentModel
            ->with(['contact.company', 'campaign', 'sequence'])
            ->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.demandes.index'));
        }

        return $this->getView('backend.contents.demandes.crud.view')
            ->with('title', __('overview'))
            ->with('model', $model);
    }

    /**
     * Override the trait's edit() to eager-load source chain for the form header.
     * Prevents N+1 on contact.company, campaign, sequence when rendering the hero.
     */
    public function edit($id)
    {
        $model = $this->currentModel
            ->with(['contact.company', 'campaign', 'sequence'])
            ->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.demandes.index'));
        }

        return $this->getView('backend.contents.demandes.crud.form')
            ->with('title', __('edit'))
            ->with('model', $model)
            ->with('route', route('admin.demandes.update', $model->id))
            ->with($this->getViewVars());
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.demandes.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * Provide select options to the create/edit form views.
     */
    protected function getViewVars(): array
    {
        $selectedContactId = old('contact_id', $this->currentRequest->query('contact_id'));

        if ($this->currentRequest->route('id') !== null) {
            $selectedContactId = old('contact_id', optional($this->currentModel->find($this->currentRequest->route('id')))->contact_id);
        }

        $selectedContact = $this->currentRequest->user()?->can('view contacts') && $selectedContactId
            ? Contact::with('company')->find((int) $selectedContactId)
            : null;

        return [
            'statuses' => config('global.data.demande_statuses', []),
            'selectedContact' => $selectedContact,
        ];
    }

    public function contactsSearch(Request $request)
    {
        abort_unless($request->user()->can('view contacts'), 403);

        $term = Str::limit(trim((string) $request->query('q', '')), 100, '');
        $page = max((int) $request->query('page', 1), 1);
        $perPage = 20;

        $query = Contact::query()
            ->with('company')
            ->select(['id', 'company_id', 'name', 'email'])
            ->orderBy('name')
            ->orderBy('email');

        if ($term !== '') {
            $like = '%' . addcslashes($term, '\\%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('company', function ($companyQuery) use ($like) {
                        $companyQuery->where('name', 'like', $like);
                    });
            });
        }

        $contacts = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'results' => $contacts->getCollection()->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'text' => $this->contactLabel($contact),
                'company' => $contact->company?->name,
            ])->values(),
            'pagination' => [
                'more' => $contacts->hasMorePages(),
            ],
        ]);
    }

    protected function contactLabel(Contact $contact): string
    {
        return trim(collect([
            $contact->name ?: 'Contact #' . $contact->id,
            $contact->email ? '<' . $contact->email . '>' : null,
            $contact->company?->name ? '- ' . $contact->company->name : null,
        ])->filter()->implode(' '));
    }

    /**
     * Set captured_at to now if not provided (on create).
     *
     * @param int|null $id
     * @return array
     */
    protected function beforeSave($id = null): array
    {
        $attributes = $this->currentRequest->all();

        // On create (no $id), default captured_at to now
        if ($id === null && empty($attributes['captured_at'])) {
            $attributes['captured_at'] = now()->toDateTimeString();
        }

        if (array_key_exists('contact_id', $attributes) && !$this->currentRequest->user()?->can('view contacts')) {
            $attributes['contact_id'] = null;
        }

        return $attributes;
    }
}
