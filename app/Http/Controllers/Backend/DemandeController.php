<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\DemandesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Contact;
use App\Models\Demande;
use Illuminate\Http\Request;

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

        $this->middleware('permission:view demandes')->only(['index', 'view']);
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
        return [
            'statuses' => config('global.data.demande_statuses', []),
            'contacts' => Contact::orderBy('name')->limit(500)->get(['id', 'name', 'email']),
        ];
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

        return $attributes;
    }
}
