<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProspectCriteriaDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyDiscoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $this->middleware('permission:view prospect_criteria')->only(['index', 'view', 'discoveryStatus']);
        $this->middleware('permission:create prospect_criteria')->only(['create', 'store']);
        $this->middleware('permission:edit prospect_criteria')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete prospect_criteria')->only(['delete']);
        $this->middleware('permission:run discovery')->only(['discover']);
        $this->middleware('permission:create prospect_criteria')->only(['duplicate']);
        $this->middleware('permission:view prospect_criteria')->only(['previewQueries']);

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

        $this->viewConfigClass = \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::class;
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
            'companySizes'        => config('global.data.company_size_buckets', []),
            'countries'           => config('global.data.company_countries', []),
            'sectorsList'         => config('global.data.prospect_sectors', []),
            'positionGroups'      => config('global.data.prospect_positions', []),
            'euCodes'             => config('global.data.eu_country_codes', []),
            'recommendedPositions' => collect(config('global.data.prospect_positions', []))
                ->only(config('global.data.prospect_positions_recommended_groups', []))
                ->flatten()->values()->all(),
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
                // Already an array (e.g. tags widget that posts multiple values).
                // E2: guard against nested-array items — trim(array) throws TypeError before
                // validation runs (Crudable calls beforeSave first). Non-strings are silently
                // dropped; F1 rules then validate what remains.
                $attributes[$field] = array_values(array_filter(array_map(fn($v) => is_string($v) ? trim($v) : '', $value), fn($v) => $v !== ''));
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
     * Duplicate an existing criteria record.
     *
     * Requires `create prospect_criteria` permission (enforced via middleware).
     * Sets the clone's name to 'Copie de {original name}' and is_active to false.
     * Redirects to the clone's edit page with a standard success flash.
     *
     * @param  ProspectCriteria  $prospectCriteria
     * @return \Illuminate\Http\RedirectResponse
     */
    public function duplicate(ProspectCriteria $prospectCriteria)
    {
        // prospect_criteria.name is varchar(100); keep the 'Copie de ' prefix intact
        // and trim the original-name tail so the prefixed clone name fits the column.
        $prefix = 'Copie de ';

        $clone = $prospectCriteria->replicate();
        $clone->name      = $prefix . Str::limit($prospectCriteria->name, 100 - mb_strlen($prefix), '');
        $clone->is_active = false;
        $clone->save();

        session()->flash('success', trans('app.creation_completed'));

        return redirect()->route('admin.prospect_criteria.edit', $clone->id);
    }

    /**
     * Return the SerpAPI query strings that would be fired for the given criteria.
     *
     * Requires `view prospect_criteria` permission (enforced via middleware).
     * Read-only — does not call SerpAPI; only builds the query list.
     *
     * @param  ProspectCriteria        $prospectCriteria
     * @param  CompanyDiscoveryService $discoveryService
     * @return \Illuminate\Http\JsonResponse
     */
    public function previewQueries(ProspectCriteria $prospectCriteria, CompanyDiscoveryService $discoveryService)
    {
        return response()->json([
            'queries' => $discoveryService->buildQueries($prospectCriteria),
        ]);
    }

    /**
     * Dispatch the discovery pipeline job for the given criteria.
     *
     * Requires `run discovery` permission (enforced via middleware).
     * The job runs asynchronously on the `database` queue driver — it can be slow
     * (SerpAPI + Hunter calls) and consumes external API credits.
     *
     * Double-submit guard: a DB transaction with row-lock checks for an already
     * in-flight run (pending|running and not stale). If one is found, returns 409
     * with the existing run_id. Otherwise creates a DiscoveryRun row (pending) and
     * dispatches the job AFTER the transaction commits so the worker cannot pick it
     * up before the row is visible.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function discover($id)
    {
        $this->authorize('run discovery');

        $result = DB::transaction(function () use ($id) {
            $criteria = ProspectCriteria::lockForUpdate()->findOrFail((int) $id);

            if (! $criteria->is_active) {
                return [
                    'code' => 422,
                    'body' => [
                        'message' => 'error',
                        'text'    => 'Critère inactif — activez-le avant de lancer la découverte.',
                    ],
                ];
            }

            $latest = $criteria->discoveryRuns()->latest('id')->first();

            if ($latest && in_array($latest->status, ['pending', 'running'], true) && ! $latest->isStale()) {
                return [
                    'code' => 409,
                    'body' => [
                        'message' => 'error',
                        'text'    => 'Une découverte est déjà en cours.',
                        'run_id'  => $latest->id,
                        'status'  => $latest->status,
                    ],
                ];
            }

            $run = DiscoveryRun::create([
                'prospect_criteria_id' => $criteria->id,
                'status'               => 'pending',
            ]);

            return [
                'code'        => 200,
                'criteria_id' => $criteria->id,
                'run_id'      => $run->id,
            ];
        });

        if ($result['code'] === 200) {
            // Dispatch AFTER the transaction so the worker finds the committed row.
            // If dispatch itself throws, mark the row failed immediately so no orphan
            // pending row is left without a backing job.
            try {
                RunDiscoveryPipelineJob::dispatch($result['criteria_id'], $result['run_id']);
            } catch (\Throwable $e) {
                DiscoveryRun::where('id', $result['run_id'])->update([
                    'status'      => 'failed',
                    'error'       => Str::limit('Échec de mise en file : ' . $e->getMessage(), 1000),
                    'finished_at' => now(),
                ]);

                return response()->json([
                    'message' => 'error',
                    'text'    => 'Impossible de lancer la découverte. Réessayez.',
                ], 500);
            }

            return response()->json([
                'message'    => 'success',
                'text'       => 'Découverte lancée en arrière-plan',
                'run_id'     => $result['run_id'],
                'status'     => 'pending',
                'status_url' => route('admin.prospect_criteria.discovery_status', $result['criteria_id']),
            ]);
        }

        return response()->json($result['body'], $result['code']);
    }

    /**
     * Return the current discovery status for the given criteria (latest run).
     *
     * Polled by the JS panel on the criteria detail page every ~3 s while in-flight.
     * Returns Cache-Control: no-store to prevent stale responses.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function discoveryStatus($id)
    {
        $criteria = ProspectCriteria::findOrFail((int) $id);
        $run      = $criteria->latestDiscoveryRun;

        return response()->json([
            'status'          => $run?->status,
            'companies_count' => $run?->companies_count ?? 0,
            'contacts_count'  => $run?->contacts_count ?? 0,
            'skipped_count'   => $run?->skipped_count ?? 0,
            'finished_at'     => optional($run?->finished_at)->toIso8601String(),
            'companies_total' => $criteria->companies()->count(),
            'stale'           => $run ? $run->isStale() : false,
            'error'           => $run?->error,
        ], 200)->header('Cache-Control', 'no-store');
    }
}
