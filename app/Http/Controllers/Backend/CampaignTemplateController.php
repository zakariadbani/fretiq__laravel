<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\CampaignTemplatesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\CampaignTemplate;
use Illuminate\Http\Request;

class CampaignTemplateController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, CampaignTemplate $model, CampaignTemplatesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view campaign_templates')->only(['index', 'view']);
        $this->middleware('permission:create campaign_templates')->only(['create', 'store']);
        $this->middleware('permission:edit campaign_templates')->only(['edit', 'update']);
        $this->middleware('permission:delete campaign_templates')->only(['delete']);

        $this->listTitle = "Modèles d'email";
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       CampaignTemplate::class,
            modelName:        'campaign_templates',
            dataTableClass:   CampaignTemplatesDataTable::class,
            permissionEntity: 'campaign_templates',
            prefixName:       'admin',
            titleField:       'name',
        ));
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.campaign_templates.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }
}
