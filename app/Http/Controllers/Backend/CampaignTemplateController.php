<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\CampaignTemplatesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\CampaignTemplate;
use App\Services\Zoho\ZohoCrmTemplatesService;
use Illuminate\Http\Request;

class CampaignTemplateController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, CampaignTemplate $model, CampaignTemplatesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view campaign_templates')->only(['index', 'view']);
        $this->middleware('permission:create campaign_templates')->only(['create', 'store']);
        $this->middleware('permission:edit campaign_templates')->only(['edit', 'update', 'executeSwitch']);
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

        // Wire ViewConfig — MUST be inside constructor body, never as a class property.
        // The Crudable trait declares $viewConfigClass = null; re-declaring it at class level
        // with a non-null default would be a PHP fatal (conflicting default).
        $this->viewConfigClass = \App\Crud\ViewConfigs\CampaignTemplateViewConfig::class;
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

    /**
     * Import email templates from Zoho CRM into campaign_templates.
     *
     * Controller-side permission enforcement (belt + suspenders — Blade @can is
     * presentational only; the gate must be enforced here).
     */
    public function importFromZoho(Request $request)
    {
        abort_unless($request->user()->can('create campaign_templates'), 403);

        try {
            $result = app(ZohoCrmTemplatesService::class)->import();

            session()->flash(
                'success',
                "Modèles importés : {$result['imported']} créés, {$result['updated']} mis à jour, {$result['skipped']} ignorés."
            );
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 200);
            session()->flash('error', "Erreur lors de l'import des modèles : {$message}");
        }

        return redirect()->route('admin.campaign_templates.index');
    }
}
