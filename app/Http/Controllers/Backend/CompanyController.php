<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\CompaniesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Company;
use Illuminate\Http\Request;

class CompanyController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, Company $model, CompaniesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view companies')->only(['index', 'view']);
        $this->middleware('permission:create companies')->only(['create', 'store']);
        $this->middleware('permission:edit companies')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete companies')->only(['delete']);

        $this->listTitle = 'Entreprises';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:      Company::class,
            modelName:       'companies',
            dataTableClass:  CompaniesDataTable::class,
            permissionEntity: 'companies',
            prefixName:      'admin',
            titleField:      'name',
        ));
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.companies.crud.index',
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
            'relationships'          => config('global.data.company_relationships', []),
            'sources'                => config('global.data.company_sources', []),
            'qualificationStatuses'  => config('global.data.company_qualification_statuses', []),
            'sizeBuckets'            => config('global.data.company_size_buckets', []),
            'countries'              => config('global.data.company_countries', []),
        ];
    }
}
