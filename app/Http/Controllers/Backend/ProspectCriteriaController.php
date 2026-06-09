<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProspectCriteriaDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\ProspectCriteria;
use Illuminate\Http\Request;

class ProspectCriteriaController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * Whitelist of boolean fields that may be toggled via executeSwitch.
     *
     * @var array<string>
     */
    protected $toggleableFields = ['is_active'];

    public function __construct(Request $request, ProspectCriteria $model, ProspectCriteriaDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view prospect_criteria')->only(['index', 'view']);
        $this->middleware('permission:create prospect_criteria')->only(['create', 'store']);
        $this->middleware('permission:edit prospect_criteria')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete prospect_criteria')->only(['delete']);
        $this->middleware('permission:run discovery')->only(['discover']);

        $this->listTitle = 'Critères de découverte';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       ProspectCriteria::class,
            modelName:        'prospect_criteria',
            dataTableClass:   ProspectCriteriaDataTable::class,
            permissionEntity: 'prospect_criteria',
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
            'backend.contents.prospect_criteria.crud.index',
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
            'companySizes' => config('global.data.company_size_buckets', []),
        ];
    }

    /**
     * Normalize comma-separated string fields (sectors, countries, target_positions)
     * into arrays before validation and model persistence.
     *
     * - String  → split on comma, trim, filter empty → array
     * - Array   → passed through as-is
     * - null/'' → empty array []
     *
     * company_sizes arrives as an array from a multi-select → passed through (default []).
     *
     * @param int|null $id
     * @return array
     */
    protected function beforeSave($id = null): array
    {
        $attributes = $this->currentRequest->all();

        foreach (['sectors', 'countries', 'target_positions'] as $field) {
            if (!array_key_exists($field, $attributes)) {
                $attributes[$field] = [];
                continue;
            }

            $value = $attributes[$field];

            if (is_array($value)) {
                // Already an array (e.g. tags widget that posts multiple values)
                $attributes[$field] = array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
            } elseif (is_string($value) && trim($value) !== '') {
                // Comma-separated text input
                $attributes[$field] = array_values(array_filter(array_map('trim', explode(',', $value)), fn($v) => $v !== ''));
            } else {
                $attributes[$field] = [];
            }
        }

        // company_sizes arrives from a multi-select as an array; ensure it defaults to []
        if (!array_key_exists('company_sizes', $attributes) || !is_array($attributes['company_sizes'])) {
            $attributes['company_sizes'] = [];
        }

        return $attributes;
    }

    /**
     * Dispatch the discovery pipeline job for the given criteria.
     *
     * Requires `run discovery` permission (enforced via middleware).
     * The job runs asynchronously on the `database` queue driver — it can be slow
     * (SerpAPI + Hunter calls) and consumes external API credits.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function discover($id)
    {
        $this->authorize('run discovery');

        $criteria = ProspectCriteria::findOrFail((int) $id);

        RunDiscoveryPipelineJob::dispatch((int) $criteria->id);

        return response()->json([
            'message'  => 'success',
            'text'     => 'Découverte lancée en arrière-plan',
            'redirect' => route('admin.prospect_criteria.index'),
        ]);
    }
}
