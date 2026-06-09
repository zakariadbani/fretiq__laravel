<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\SuppressionsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Suppression;
use Illuminate\Http\Request;

class SuppressionController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, Suppression $model, SuppressionsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view suppressions')->only(['index', 'view']);
        $this->middleware('permission:create suppressions')->only(['create', 'store']);
        $this->middleware('permission:edit suppressions')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete suppressions')->only(['delete']);

        $this->listTitle = 'Suppressions';
        $this->title     = 'email';

        $this->bootResource(new BackendResource(
            modelClass:       Suppression::class,
            modelName:        'suppressions',
            dataTableClass:   SuppressionsDataTable::class,
            permissionEntity: 'suppressions',
            prefixName:       'admin',
            titleField:       'email',
        ));
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.suppressions.crud.index',
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
            'suppressionReasons' => config('global.data.suppression_reasons', []),
            'suppressionSources' => config('global.data.suppression_sources', []),
        ];
    }
}
