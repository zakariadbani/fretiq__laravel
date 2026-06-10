<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProspectCriteriaDataTable;
use App\Exceptions\CriteriaInactiveException;
use App\Exceptions\DiscoveryRunInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Http\Request;
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
     * Override index() to inject the dynamic filter config for JavaScript
     * and the daily quota badge vars.
     */
    public function index(DiscoveryQuotaService $quotaService)
    {
        [$quotaRemaining, $quotaPackage] = $this->resolveQuotaVars($quotaService);

        return $this->currentDataTable->render(
            'backend.contents.prospect_criteria.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
                'quotaRemaining'  => $quotaRemaining,
                'quotaPackage'    => $quotaPackage,
            ]
        );
    }

    /**
     * Override view() to inject quota badge vars alongside the standard view vars.
     *
     * @param  int                   $id
     * @param  DiscoveryQuotaService $quotaService
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function view($id, DiscoveryQuotaService $quotaService)
    {
        // Resolve the model via parent Crudable logic.
        $model = $this->currentModel->find((int) $id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.prospect_criteria.index'));
        }

        $view = $this->getView('backend.contents.prospect_criteria.crud.view');
        $view->with('title', __('overview'))
             ->with('model', $model);

        $viewConfig = $this->buildViewConfig($model);
        if ($viewConfig !== null) {
            $view->with('viewConfig', $viewConfig);
        }

        [$quotaRemaining, $quotaPackage] = $this->resolveQuotaVars($quotaService);
        $view->with('quotaRemaining', $quotaRemaining)
             ->with('quotaPackage', $quotaPackage);

        return $view;
    }

    /**
     * Safely read today's quota remaining and active package.
     *
     * Wraps in a try/catch for QueryException so pages render correctly even when
     * the quota tables do not yet exist on the dev DB (pre-migration).
     *
     * @return array{0: ?int, 1: ?\App\Models\Package}
     */
    private function resolveQuotaVars(DiscoveryQuotaService $quotaService): array
    {
        try {
            return [
                $quotaService->remainingTodayForDisplay(),
                $quotaService->activePackage(),
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            // Quota tables not yet migrated — treat as unlimited.
            return [null, null];
        }
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
     * with the existing run_id. Otherwise calls DiscoveryQuotaService::reserveRun()
     * to create the DiscoveryRun row (with quota reservation), then dispatches the
     * job AFTER the transaction commits so the worker cannot pick it up before the
     * row is visible.
     *
     * Quota check: at 0 remaining → 422 JSON "Solde du jour épuisé".
     * Partial batch: when limited and credits_reserved < daily_limit, the success
     * message includes the partial count.
     *
     * @param  int                    $id
     * @param  DiscoveryQuotaService  $quotaService
     * @return \Illuminate\Http\JsonResponse
     */
    public function discover($id, DiscoveryQuotaService $quotaService)
    {
        $this->authorize('run discovery');

        // reserveRun() owns the full lock+transaction boundary.
        // All guards (inactive, in-flight, quota) are checked inside the lock.
        // Dispatch happens AFTER reserveRun() returns — always after the committed row.
        $criteria = ProspectCriteria::findOrFail((int) $id);

        try {
            $run = $quotaService->reserveRun($criteria);
        } catch (CriteriaInactiveException $e) {
            return response()->json([
                'message' => 'error',
                'text'    => $e->getMessage(),
            ], 422);
        } catch (DiscoveryRunInFlightException $e) {
            return response()->json([
                'message' => 'error',
                'text'    => $e->getMessage(),
                'run_id'  => $e->existingRun->id,
                'status'  => $e->existingRun->status,
            ], 409);
        } catch (QuotaExhaustedException $e) {
            return response()->json([
                'message' => 'error',
                'text'    => $e->getMessage(),
            ], 422);
        } catch (QuotaLockUnavailableException $e) {
            return response()->json([
                'message' => 'error',
                'text'    => 'Réservation temporairement indisponible — réessayez dans un instant.',
            ], 409);
        }

        // Dispatch AFTER reserveRun() returns (i.e. after the transaction commits)
        // so the worker always finds the committed run row.
        // If dispatch itself throws, mark the row failed immediately so no orphan
        // pending row is left without a backing job.
        try {
            RunDiscoveryPipelineJob::dispatch($criteria->id, $run->id);
        } catch (\Throwable $e) {
            DiscoveryRun::where('id', $run->id)->update([
                'status'      => 'failed',
                'error'       => Str::limit('Échec de mise en file : ' . $e->getMessage(), 1000),
                'finished_at' => now(),
            ]);

            return response()->json([
                'message' => 'error',
                'text'    => 'Impossible de lancer la découverte. Réessayez.',
            ], 500);
        }

        // Partial-batch message: when limited and we got fewer credits than wanted
        $wantedBatch = $criteria->daily_limit ?: 20;
        $isPartial   = (! $quotaService->isUnlimited()) && ($run->credits_reserved < $wantedBatch);

        $successText = $isPartial
            ? "Découverte lancée — {$run->credits_reserved} entreprises possibles aujourd'hui"
            : 'Découverte lancée en arrière-plan';

        return response()->json([
            'message'    => 'success',
            'text'       => $successText,
            'run_id'     => $run->id,
            'status'     => 'pending',
            'status_url' => route('admin.prospect_criteria.discovery_status', $criteria->id),
        ]);
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
