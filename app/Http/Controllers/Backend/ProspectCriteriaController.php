<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProspectCriteriaDataTable;
use App\Exceptions\CriteriaInactiveException;
use App\Exceptions\DiscoveryRunInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\EnrichCriteriaContactsJob;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\CriteriaContactEnrichmentService;
use App\Services\Discovery\DiscoveryEngineRegistry;
use App\Services\Discovery\DiscoveryProgressPresenter;
use App\Services\Discovery\IntentQueryService;
use App\Services\Discovery\HunterDiscoverService;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $this->middleware('permission:edit prospect_criteria')->only(['edit', 'update', 'executeSwitch', 'generateQueries']);
        $this->middleware('permission:delete prospect_criteria')->only(['delete']);
        $this->middleware('permission:run discovery')->only(['discover', 'hunterDiscoverPreview', 'hunterDiscoverImport']);
        $this->middleware('permission:create prospect_criteria')->only(['duplicate']);
        $this->middleware('permission:view prospect_criteria')->only(['previewQueries']);
        $this->middleware('permission:enrich companies')->only(['contactEnrichmentPreview', 'dispatchContactEnrichment']);

        $this->listTitle = 'Critères de découverte';
        $this->title = 'name';

        $this->bootResource(new BackendResource(
            modelClass: ProspectCriteria::class,
            modelName: 'prospect_criteria',
            dataTableClass: ProspectCriteriaDataTable::class,
            permissionEntity: 'prospect_criteria',
            prefixName: 'admin',
            titleField: 'name',
        ));

        $this->viewConfigClass = \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::class;
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript
     * and the daily + monthly quota badge vars.
     */
    public function index(DiscoveryQuotaService $quotaService)
    {
        [$quotaRemaining, $quotaPackage, $contactRemaining, $monthlyRemaining, $monthlyContactRemaining, $activeDailyLimitSum, $dailyQuotaSummary, $monthlyQuotaSummary, $quotaMeters, $providerSearchesLeft] = $this->resolveQuotaVars($quotaService);

        return $this->currentDataTable->render(
            'backend.contents.prospect_criteria.crud.index',
            [
                'listTitle' => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
                'quotaRemaining' => $quotaRemaining,
                'quotaPackage' => $quotaPackage,
                'contactRemaining' => $contactRemaining,
                'monthlyRemaining' => $monthlyRemaining,
                'monthlyContactRemaining' => $monthlyContactRemaining,
                'activeDailyLimitSum' => $activeDailyLimitSum,
                'dailyQuotaSummary' => $dailyQuotaSummary,
                'monthlyQuotaSummary' => $monthlyQuotaSummary,
                'quotaMeters' => $quotaMeters,
                'providerSearchesLeft' => $providerSearchesLeft,
            ]
        );
    }

    /**
     * Override view() to inject quota badge vars alongside the standard view vars.
     *
     * Supports an `?audit=1` query param on the Résultats tab: reveals rejected
     * (AI-excluded competitor) companies via Company::withRejected() so the user
     * can inspect and un-reject the AI's calls. Default (no param) keeps the
     * global notRejected scope in effect.
     *
     * @param  int  $id
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    public function view(
        $id,
        DiscoveryQuotaService $quotaService,
        CriteriaContactEnrichmentService $contactEnrichmentService,
    )
    {
        // Resolve the model via parent Crudable logic.
        $model = $this->currentModel->find((int) $id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));

            return redirect(route('admin.prospect_criteria.index'));
        }

        $auditMode = $this->currentRequest->boolean('audit');

        $allowedResultSorts = [
            'score' => 'companies.ai_score',
            'created_at' => 'companies.created_at',
            'recent' => 'companies.id',
            'name' => 'companies.name',
            'sector' => 'companies.sector',
            'country' => 'companies.country',
            'size' => 'companies.estimated_size',
            'contacts' => 'contacts_count',
        ];
        $resultsSort = (string) $this->currentRequest->query('results_sort', 'score');
        if (! array_key_exists($resultsSort, $allowedResultSorts)) {
            $resultsSort = 'score';
        }
        $resultsDir = strtolower((string) $this->currentRequest->query('results_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Paginate discovered companies first so we can reuse ->total() in the
        // ViewConfig stat card — avoids a second COUNT query.
        $companiesQuery = $auditMode ? $model->companies()->withRejected() : $model->companies();
        $sortColumn = $allowedResultSorts[$resultsSort];
        $resultCompanies = $companiesQuery
            ->with('contacts')
            ->withCount('contacts')
            ->orderBy($sortColumn, $resultsDir)
            ->when($resultsSort !== 'recent', fn ($query) => $query->orderByDesc('companies.id'))
            ->paginate(25, ['*'], 'results_page');

        // Results grouped by the query that found them (§6) — trouvées/gardées/exclues per query.
        $queryGroups = $this->buildQueryResultGroups($model);

        $view = $this->getView('backend.contents.prospect_criteria.crud.view');
        $view->with('title', __('overview'))
            ->with('model', $model);

        [$quotaRemaining, $quotaPackage, $contactRemaining, $monthlyRemaining, $monthlyContactRemaining, $activeDailyLimitSum, $dailyQuotaSummary, $monthlyQuotaSummary, $quotaMeters] = $this->resolveQuotaVars($quotaService);
        $contactCoverage = $contactEnrichmentService->snapshot($model);

        $viewConfig = \App\Crud\ViewConfigs\ProspectCriteriaViewConfig::make(
            $model,
            [
                'discovered_total' => $resultCompanies->total(),
                'quota_tz' => $quotaService->quotaTz(),
                'quota_exhausted' => $quotaRemaining === 0 || $monthlyRemaining === 0,
                'contact_coverage' => $contactCoverage,
            ]
        );
        $view->with('viewConfig', $viewConfig);

        $view->with('quotaRemaining', $quotaRemaining)
            ->with('quotaPackage', $quotaPackage)
            ->with('contactRemaining', $contactRemaining)
            ->with('monthlyRemaining', $monthlyRemaining)
            ->with('monthlyContactRemaining', $monthlyContactRemaining)
            ->with('activeDailyLimitSum', $activeDailyLimitSum)
            ->with('dailyQuotaSummary', $dailyQuotaSummary)
            ->with('monthlyQuotaSummary', $monthlyQuotaSummary)
            ->with('quotaMeters', $quotaMeters)
            ->with('resultCompanies', $resultCompanies)
            ->with('resultsSort', $resultsSort)
            ->with('resultsDir', $resultsDir)
            ->with('queryGroups', $queryGroups)
            ->with('auditMode', $auditMode);

        return $view;
    }

    /**
     * Group this criteria's discovered companies by `discovery_query` for the
     * Résultats tab (§6): each query → trouvées / gardées / exclues counts,
     * expandable to the company rows.
     *
     * "Gardées" = visible under the default notRejected scope; "exclues" only
     * surface via withRejected(). Companies with a null discovery_query (pre-AI
     * or manually created) are bucketed under a single "(sans requête)" group.
     *
     * @return list<array{query: ?string, found: int, kept: int, excluded: int, companies: \Illuminate\Support\Collection}>
     */
    private function buildQueryResultGroups(ProspectCriteria $model): array
    {
        $all = Company::withRejected()
            ->where('criteria_id', $model->id)
            ->with('contacts')
            ->latest('id')
            ->get()
            ->groupBy(fn (Company $company) => $company->discovery_query ?: '');

        return $all->map(function ($companies, $query) {
            $excluded = $companies->where('qualification_status', 'rejected');
            $kept = $companies->reject(fn (Company $c) => $c->qualification_status === 'rejected');

            return [
                'query' => $query !== '' ? $query : null,
                'found' => $companies->count(),
                'kept' => $kept->count(),
                'excluded' => $excluded->count(),
                'companies' => $companies,
            ];
        })->values()->all();
    }

    /**
     * Safely read today's + monthly quota remaining, active package, contact
     * remaining, and the sum of daily_limit across active criteria (overbook check).
     *
     * Wraps in a try/catch for QueryException so pages render correctly even when
     * the quota tables do not yet exist on the dev DB (pre-migration).
     *
     * The 9th value (displayMeters()) is the render-ready meter set consumed by
     * the index quota strip and the view hero badge; the first 8 are kept as-is
     * because other partials still read them individually. The 10th value is the
     * live provider account balance (CompanyDiscoveryService::accountUsage(),
     * already cached 10 min there) — null on a `local` driver, which the strip
     * partial must render as "hidden", never a fake "0 crédits restants".
     *
     * @return array{0: ?int, 1: ?\App\Models\Package, 2: ?int, 3: ?int, 4: ?int, 5: ?int, 6: array, 7: array, 8: array, 9: ?int}
     */
    private function resolveQuotaVars(DiscoveryQuotaService $quotaService): array
    {
        try {
            $dailyQuotaSummary = $quotaService->dailyDisplaySummary();
            $monthlyQuotaSummary = $quotaService->monthlyDisplaySummary();
            $quotaMeters = $quotaService->displayMeters();
            $providerSearchesLeft = auth()->user()?->can('view provider quota')
                ? (app(CompanyDiscoveryService::class)->accountUsage()['total_searches_left'] ?? null)
                : null;

            return [
                $quotaService->remainingTodayForDisplay(),
                $quotaService->activePackage(),
                $quotaService->contactRemainingTodayForDisplay(),
                $quotaService->monthlyRemainingForDisplay(),
                $quotaService->monthlyContactRemainingForDisplay(),
                (int) ProspectCriteria::where('is_active', true)
                    ->selectRaw('COALESCE(SUM(COALESCE(daily_limit, 20)), 0) AS s')
                    ->value('s'),
                $dailyQuotaSummary,
                $monthlyQuotaSummary,
                $quotaMeters,
                $providerSearchesLeft,
            ];
        } catch (\Illuminate\Database\QueryException $e) {
            // Quota tables not yet migrated — treat as unlimited.
            return [null, null, null, null, null, null, [], [], [], null];
        }
    }

    /**
     * Provide select options + automation hints to the create/edit form views.
     *
     * quotaPackage/activeDailyLimitSum reuse the same try/catch-QueryException
     * safety as resolveQuotaVars() (form must render even pre-migration).
     *
     * activeDailyLimitSum here means "OTHER active criteria" on the edit form —
     * the criteria being edited is excluded via the {id} route param so its own
     * stored daily_limit does not double-count against itself in the overbook
     * hint. resolveQuotaVars() (index badge) intentionally counts ALL active
     * criteria and is untouched.
     *
     * quotaRemaining/contactRemaining/monthlyRemaining are resolved here so the edit
     * page's hero action row can compute the "Lancer la découverte" disabled state
     * identically to the view page (which gets them from resolveQuotaVars()).
     */
    protected function getViewVars(): array
    {
        $quotaService = app(DiscoveryQuotaService::class);
        $editingId = $this->currentRequest->route('id');

        try {
            $quotaPackage = $quotaService->activePackage();
            $sumQuery = ProspectCriteria::where('is_active', true);
            if ($editingId !== null) {
                $sumQuery->where('id', '!=', $editingId);
            }
            $activeDailyLimitSum = (int) $sumQuery
                ->selectRaw('COALESCE(SUM(COALESCE(daily_limit, 20)), 0) AS s')
                ->value('s');

            $quotaRemaining = $quotaService->remainingTodayForDisplay();
            $contactRemaining = $quotaService->contactRemainingTodayForDisplay();
            $monthlyRemaining = $quotaService->monthlyRemainingForDisplay();
        } catch (\Illuminate\Database\QueryException $e) {
            $quotaPackage = null;
            $activeDailyLimitSum = null;
            $quotaRemaining = null;
            $contactRemaining = null;
            $monthlyRemaining = null;
        }

        return [
            'companySizes' => config('global.data.company_size_buckets', []),
            'countries' => config('global.data.company_countries', []),
            'sectorsList' => config('global.data.prospect_sectors', []),
            'positionGroups' => config('global.data.prospect_positions', []),
            'euCodes' => config('global.data.eu_country_codes', []),
            'recommendedPositions' => collect(config('global.data.prospect_positions', []))
                ->only(config('global.data.prospect_positions_recommended_groups', []))
                ->flatten()->values()->all(),
            'quotaPackage' => $quotaPackage,
            'activeDailyLimitSum' => $activeDailyLimitSum,
            'quotaRemaining' => $quotaRemaining,
            'contactRemaining' => $contactRemaining,
            'monthlyRemaining' => $monthlyRemaining,
            'globalMinScore' => (int) \App\Models\Setting::get('decouverte.min_score_enrich', 50),
            // Fallback false: enrichment is opted into per criteria; a missing settings
            // row must not make « Hérité » read as "activé" in the form.
            'globalAutoEnrich' => (bool) \App\Models\Setting::get('decouverte.auto_enrich', false),
            'quotaTz' => $quotaService->quotaTz(),
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
     * @param  int|null  $id
     */
    protected function beforeSave($id = null): array
    {
        $attributes = $this->currentRequest->all();

        foreach (['sectors', 'countries', 'target_positions'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                $attributes[$field] = [];

                continue;
            }

            $value = $attributes[$field];

            if (is_array($value)) {
                // Already an array (e.g. tags widget that posts multiple values).
                // E2: guard against nested-array items — trim(array) throws TypeError before
                // validation runs (Crudable calls beforeSave first). Non-strings are silently
                // dropped; F1 rules then validate what remains.
                $attributes[$field] = array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $value), fn ($v) => $v !== ''));
            } elseif (is_string($value) && trim($value) !== '') {
                // Comma-separated text input
                $attributes[$field] = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
            } else {
                $attributes[$field] = [];
            }
        }

        // company_sizes arrives from a multi-select as an array; ensure it defaults to []
        if (! array_key_exists('company_sizes', $attributes) || ! is_array($attributes['company_sizes'])) {
            $attributes['company_sizes'] = [];
        }

        // ai_queries: posted as ai_queries[i][q]/[enabled]. Engine selection is global.
        // (form.blade.php). Only normalize when present in the request — absence (e.g. a
        // non-form caller) must leave the existing stored value untouched, not null it out.
        if (array_key_exists('ai_queries', $attributes) && is_array($attributes['ai_queries'])) {
            $attributes['ai_queries'] = $this->normalizeAiQueries($attributes['ai_queries']);
        }

        return $attributes;
    }

    /**
     * Collapse legacy per-engine duplicates into one engine-neutral query row.
     * A query remains enabled when any legacy duplicate was enabled.
     *
     * @return list<array{q: string, enabled: bool}>
     */
    private function normalizeAiQueries(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $q = is_array($row) ? trim((string) ($row['q'] ?? '')) : '';
            if ($q === '') {
                continue;
            }

            if (! isset($normalized[$q])) {
                $normalized[$q] = ['q' => $q, 'enabled' => false];
            }
            $normalized[$q]['enabled'] = $normalized[$q]['enabled'] || (bool) ($row['enabled'] ?? true);
        }

        return array_values($normalized);
    }

    /**
     * Duplicate an existing criteria record.
     *
     * Requires `create prospect_criteria` permission (enforced via middleware).
     * Sets the clone's name to 'Copie de {original name}' and is_active to false.
     * Redirects to the clone's edit page with a standard success flash.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function duplicate(ProspectCriteria $prospectCriteria)
    {
        // prospect_criteria.name is varchar(100); keep the 'Copie de ' prefix intact
        // and trim the original-name tail so the prefixed clone name fits the column.
        $prefix = 'Copie de ';

        $clone = $prospectCriteria->replicate();
        $clone->name = $prefix.Str::limit($prospectCriteria->name, 100 - mb_strlen($prefix), '');
        $clone->is_active = false;
        $clone->auto_run = false;
        $clone->save();

        session()->flash('success', trans('app.creation_completed'));

        return redirect()->route('admin.prospect_criteria.edit', $clone->id);
    }

    /**
     * Return the SerpAPI query strings that would be fired for the given criteria.
     *
     * Requires `view prospect_criteria` permission (enforced via middleware).
     * Read-only — does not call SerpAPI, and does NOT call the AI query generator
     * (that only fires from generateQueries(), keeping this a plain-page-load-safe
     * preview). Returns the cached ai_queries (with per-query enabled flags) when
     * present, else falls back to the structured buildQueries() list wrapped in
     * the same {q, enabled} shape.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function previewQueries(
        ProspectCriteria $prospectCriteria,
        CompanyDiscoveryService $discoveryService,
        DiscoveryQuotaService $quotaService,
        DiscoveryEngineRegistry $engineRegistry,
    ) {
        $queries = ! empty($prospectCriteria->ai_queries)
            ? $this->normalizeAiQueries($prospectCriteria->ai_queries)
            : array_map(
                fn (string $q) => [
                    'q' => $q,
                    'enabled' => true,
                ],
                $discoveryService->buildQueries($prospectCriteria)
            );

        return response()->json([
            'queries' => $queries,
            'execution' => $this->queryPreviewExecutionMeta(
                $prospectCriteria,
                $quotaService,
                count($engineRegistry->selected()),
                count(array_filter($queries, fn (array $query) => ($query['enabled'] ?? true) === true)),
            ),
        ]);
    }

    /**
     * French query-preview UX metadata: the preview can show how many SerpAPI
     * searches the next launch may execute without actually reserving or spending
     * any credits. Null package caps are treated as unlimited; missing quota tables
     * fall back to the criteria's daily SerpAPI search limit so the form still loads.
     *
     * @return array{daily_limit: int, search_budget: int, engine_count: int, prepared_attempts: int}
     */
    private function queryPreviewExecutionMeta(
        ProspectCriteria $criteria,
        DiscoveryQuotaService $quotaService,
        int $engineCount,
        int $enabledQueryCount,
    ): array {
        $dailyLimit = (int) ($criteria->daily_limit ?: 20);
        $caps = [$dailyLimit];

        try {
            $remainingToday = $quotaService->remainingTodayForDisplay();
            if ($remainingToday !== null) {
                $caps[] = $remainingToday;
            }

            $remainingMonth = $quotaService->monthlyRemainingForDisplay();
            if ($remainingMonth !== null) {
                $caps[] = $remainingMonth;
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Quota tables not migrated yet — the preview remains read-only and
            // should not block form rendering. Keep daily_limit as the fallback.
        }

        return [
            'daily_limit' => $dailyLimit,
            'search_budget' => max(0, min($caps)),
            'engine_count' => max(0, $engineCount),
            'prepared_attempts' => max(0, $engineCount) * max(0, $enabledQueryCount),
        ];
    }

    /**
     * Stateless preview: expand the POSTed (not-yet-saved) ai_target/ai_exclude
     * descriptions into SerpAPI query strings, without persisting anything.
     *
     * Requires `edit prospect_criteria` permission (middleware + inline authorize()).
     * The user's typed textareas — not the stored description — drive the expansion,
     * so "Générer avec l'IA" reflects unsaved edits. Enabled flags are carried over
     * from the POSTed current query list (not the DB) so a re-generate does not
     * silently re-enable queries the user turned off in this same editing session.
     * Persisting ai_queries happens only via Enregistrer → beforeSave().
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateQueries(Request $request, $id, IntentQueryService $intentQueryService)
    {
        $this->authorize('edit prospect_criteria');

        $criteria = ProspectCriteria::findOrFail((int) $id);

        // In-memory only — mirrors the unsaved form state, never save()d.
        $criteria->ai_target = $request->input('ai_target');
        $criteria->ai_exclude = $request->input('ai_exclude');

        // Index enabled flags from the POSTed current list (not the DB) so unchanged
        // queries keep their current on/off state across a re-generate.
        $previousEnabled = collect($request->input('queries', []))
            ->mapWithKeys(fn (array $row) => [($row['q'] ?? '') => (bool) ($row['enabled'] ?? true)]);

        $queries = collect($intentQueryService->expand($criteria))
            ->map(fn (string $q) => [
                'q' => $q,
                'enabled' => $previousEnabled->get($q, true),
            ])
            ->values()
            ->all();

        // Empty result has two very different causes; name the real one so the UI does
        // not tell the user to "enter a target" they already entered. expand() returns []
        // both when no description was given AND when the Gemini call failed (429/500/etc.,
        // swallowed by design) — mirror its own trim() guard to tell them apart.
        $notice = null;
        if (empty($queries)) {
            $hasCriteria = trim((string) ($criteria->ai_target ?? '')) !== ''
                || trim((string) ($criteria->ai_exclude ?? '')) !== '';
            $notice = $hasCriteria
                ? "L'IA est momentanément indisponible (limite atteinte). Réessayez dans quelques minutes."
                : 'Renseignez une description de cible ou au moins un secteur/pays.';
        }

        return response()->json(['queries' => $queries, 'notice' => $notice]);
    }

    public function hunterDiscoverPreview(Request $request, $id, HunterDiscoverService $service)
    {
        $this->authorize('run discovery');
        $criteria = ProspectCriteria::findOrFail((int) $id);
        if (! $criteria->is_active) {
            return response()->json(['message' => 'Activez ce critère avant de lancer Discover IA.'], 422);
        }

        $validated = $request->validate([
            'target' => 'nullable|string|max:2000',
            'exclude' => 'nullable|string|max:2000',
        ]);
        $target = trim((string) ($validated['target'] ?? ''));
        $exclude = trim((string) ($validated['exclude'] ?? ''));
        if ($target === '' && $exclude === '') {
            return response()->json(['message' => 'Renseignez une cible ou une exclusion.'], 422);
        }

        $lock = Cache::lock('hunter-discover:'.sha1($request->user()->id.':'.$criteria->id), 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'Une prévisualisation est déjà en cours.'], 423);
        }

        try {
            $result = $service->preview($criteria, $target ?: null, $exclude ?: null);
        } finally {
            $lock->release();
        }

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], $result['status']);
        }

        $companies = $result['companies'];
        $cachedCompanies = $companies;
        $existing = Company::withRejected()->whereIn('domain', array_column($companies, 'domain'))->get()->keyBy('domain');
        foreach ($companies as &$company) {
            $current = $existing->get($company['domain']);
            if (! $current) {
                [$status, $label, $eligible] = ['new', 'Nouvelle', true];
            } elseif ((int) $current->criteria_id !== (int) $criteria->id) {
                [$status, $label, $eligible] = ['existing_other', 'Autre critère', false];
            } elseif ($current->qualification_status === 'rejected') {
                [$status, $label, $eligible] = ['rejected_current', 'Rejetée ici — réimportable', true];
            } else {
                [$status, $label, $eligible] = ['existing_current', 'Déjà importée', false];
            }
            $company += ['status' => $status, 'status_label' => $label, 'eligible' => $eligible, 'checked' => $eligible && $status === 'new'];
        }
        unset($company);

        $previewId = (string) Str::uuid();
        Cache::put('hunter-discover-preview:'.$previewId, [
            'user_id' => (int) $request->user()->id,
            'criteria_id' => (int) $criteria->id,
            'prompt' => Str::limit($result['prompt'], 2000, ''),
            'companies' => $cachedCompanies,
        ], now()->addMinutes(15));

        return response()->json(['preview_id' => $previewId, 'companies' => $companies]);
    }

    public function hunterDiscoverImport(Request $request, $id)
    {
        $this->authorize('run discovery');
        $criteria = ProspectCriteria::findOrFail((int) $id);
        if (! $criteria->is_active) {
            return response()->json(['message' => 'Activez ce critère avant l’import.'], 422);
        }

        $validated = $request->validate([
            'preview_id' => 'required|uuid',
            'domains' => 'required|array|min:1|max:100',
            'domains.*' => 'required|string|max:191|distinct',
        ]);
        $cacheKey = 'hunter-discover-preview:'.$validated['preview_id'];
        $lock = Cache::lock('hunter-discover-import:'.$validated['preview_id'], 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'Cet import est déjà en cours.'], 423);
        }

        try {
            $preview = Cache::get($cacheKey);
            if (! is_array($preview)) {
                return response()->json(['message' => 'Cet aperçu a expiré. Relancez la recherche.'], 410);
            }
            if ((int) ($preview['user_id'] ?? 0) !== (int) $request->user()->id || (int) ($preview['criteria_id'] ?? 0) !== (int) $criteria->id) {
                return response()->json(['message' => 'Cet aperçu ne vous appartient pas.'], 403);
            }

            $previewCompanies = collect($preview['companies'] ?? [])->keyBy('domain');
            $domains = array_values(array_unique($validated['domains']));
            if (array_diff($domains, $previewCompanies->keys()->all())) {
                return response()->json(['message' => 'La sélection ne correspond pas à cet aperçu.'], 422);
            }

            $counts = DB::transaction(function () use ($criteria, $domains, $previewCompanies, $preview) {
                $counts = ['imported' => 0, 'reactivated' => 0, 'skipped' => 0];
                foreach ($domains as $domain) {
                    $existing = Company::withRejected()->where('domain', $domain)->lockForUpdate()->first();
                    if ($existing) {
                        if ((int) $existing->criteria_id === (int) $criteria->id && $existing->qualification_status === 'rejected') {
                            $existing->forceFill(['qualification_status' => 'pending'])->save();
                            $counts['reactivated']++;
                        } else {
                            $counts['skipped']++;
                        }
                        continue;
                    }

                    $candidate = $previewCompanies->get($domain);
                    $now = now();
                    $inserted = DB::table('companies')->insertOrIgnore([
                        'criteria_id' => $criteria->id,
                        'domain' => $domain,
                        'name' => $candidate['organization'] ?: $domain,
                        'relationship' => 'prospect',
                        'source' => 'discovered',
                        'qualification_status' => 'pending',
                        'is_active' => true,
                        'discovery_query' => Str::limit((string) ($preview['prompt'] ?? ''), 2000, ''),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $inserted ? $counts['imported']++ : $counts['skipped']++;
                }

                return $counts;
            }, 3);

            Cache::forget($cacheKey);

            return response()->json($counts + ['redirect_url' => route('admin.prospect_criteria.view', $criteria->id).'#criteria_resultats']);
        } finally {
            $lock->release();
        }
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
     * @param  int  $id
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
                'text' => $e->getMessage(),
            ], 422);
        } catch (DiscoveryRunInFlightException $e) {
            return response()->json([
                'message' => 'error',
                'text' => $e->getMessage(),
                'run_id' => $e->existingRun->id,
                'status' => $e->existingRun->status,
                'status_url' => $this->discoveryStatusUrl($criteria, $e->existingRun),
            ], 409);
        } catch (QuotaExhaustedException $e) {
            return response()->json([
                'message' => 'error',
                'text' => $e->getMessage(),
            ], 422);
        } catch (QuotaLockUnavailableException $e) {
            return response()->json([
                'message' => 'error',
                'text' => 'Réservation temporairement indisponible — réessayez dans un instant.',
            ], 409);
        }

        // Dispatch AFTER reserveRun() returns (i.e. after the transaction commits)
        // so the worker always finds the committed run row.
        // If dispatch itself throws, mark the row failed immediately so no orphan
        // pending row is left without a backing job.
        try {
            RunDiscoveryPipelineJob::dispatch($criteria->id, $run->id);
        } catch (\Throwable $e) {
            $markedFailed = DiscoveryRun::failPendingDispatch((int) $run->id, (int) $criteria->id);

            Log::error('[ProspectCriteriaController] Discovery dispatch failed.', [
                'criteria_id' => $criteria->id,
                'run_id' => $run->id,
                'run_marked_failed' => $markedFailed,
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'message' => 'error',
                'text' => 'Impossible de lancer la découverte. Réessayez.',
            ], 500);
        }

        // Partial search-budget message: when limited and we got fewer searches than requested
        $wantedSearches = $criteria->daily_limit ?: 20;
        $reservedSearches = (int) ($run->searches_reserved ?? $run->credits_reserved);
        $isPartial = (! $quotaService->isUnlimited()) && ($reservedSearches < $wantedSearches);

        $successText = $isPartial
            ? "Découverte lancée — {$reservedSearches} recherches d’entreprises possibles aujourd'hui"
            : 'Découverte lancée en arrière-plan';

        // Hunter capacity is an independent reservation: one attempt means one
        // company lookup and is not the number of contacts that will be created.
        $reservedHunter = (int) ($run->contact_credits_reserved ?? 0);
        $successText .= " — {$reservedHunter} tentatives d’enrichissement réservées";

        return response()->json([
            'message' => 'success',
            'text' => $successText,
            'run_id' => $run->id,
            'status' => 'pending',
            'status_url' => $this->discoveryStatusUrl($criteria, $run),
        ]);
    }

    public function contactEnrichmentPreview($id, CriteriaContactEnrichmentService $service)
    {
        $this->authorize('enrich companies');
        $criteria = ProspectCriteria::findOrFail((int) $id);
        $snapshot = $service->snapshot($criteria);
        $snapshot['approval_token'] = Crypt::encryptString(json_encode([
            'criteria_id' => $criteria->id,
            'success_target' => $snapshot['success_target'],
            'attempt_limit' => $snapshot['attempt_limit'],
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json($snapshot, 200)
            ->header('Cache-Control', 'no-store');
    }

    public function dispatchContactEnrichment($id, CriteriaContactEnrichmentService $service)
    {
        $this->authorize('enrich companies');
        $criteria = ProspectCriteria::findOrFail((int) $id);
        try {
            $approval = json_decode(Crypt::decryptString((string) request('approval_token')), true, 512, JSON_THROW_ON_ERROR);
            $successTarget = (int) ($approval['success_target'] ?? 0);
            $attemptLimit = (int) ($approval['attempt_limit'] ?? 0);
            $validApproval = (int) ($approval['criteria_id'] ?? 0) === (int) $criteria->id
                && (int) ($approval['expires_at'] ?? 0) >= now()->timestamp
                && $successTarget >= 1
                && $successTarget <= CriteriaContactEnrichmentService::BATCH_SAFETY_MAX
                && $attemptLimit >= 1
                && $attemptLimit <= CriteriaContactEnrichmentService::BATCH_SAFETY_MAX;
        } catch (\Throwable) {
            $validApproval = false;
            $successTarget = 0;
            $attemptLimit = 0;
        }

        if (! $validApproval) {
            return response()->json([
                'message' => 'error',
                'text' => 'Confirmation expirée ou invalide — relancez la prévisualisation.',
            ], 422);
        }

        $snapshot = $service->snapshot($criteria);

        if ($snapshot['callable_count'] <= 0) {
            return response()->json([
                'message' => 'error',
                'text' => 'Aucune entreprise ne peut être enrichie maintenant.',
                ...$snapshot,
            ], 422);
        }

        $key = EnrichCriteriaContactsJob::admissionKey($criteria->id);
        $lock = Cache::lock($key, 3600);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'error',
                'text' => 'Une recherche de contacts est déjà en cours pour ce critère.',
            ], 409);
        }
        $owner = $lock->owner();

        try {
            EnrichCriteriaContactsJob::dispatch(
                criteriaId: $criteria->id,
                approvedAttempts: $attemptLimit,
                admissionOwner: $owner,
                successTarget: $successTarget,
                batchId: (string) Str::uuid(),
            );
        } catch (\Throwable $e) {
            Cache::restoreLock($key, $owner)->release();

            return response()->json([
                'message' => 'error',
                'text' => 'Impossible de lancer la recherche de contacts. Réessayez.',
            ], 500);
        }

        return response()->json([
            'message' => 'success',
            'text' => "Recherche lancée — objectif {$successTarget} enrichissement(s) réussi(s), {$attemptLimit} tentative(s) maximum.",
            ...$snapshot,
            'success_target' => $successTarget,
            'attempt_limit' => $attemptLimit,
        ], 202);
    }

    /**
     * Return discovery progress for the requested run, or the latest discovery run.
     *
     * Polled by the JS panel on the criteria detail page every ~3 s while in-flight.
     * Returns Cache-Control: no-store to prevent stale responses.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function discoveryStatus(Request $request, $id, DiscoveryProgressPresenter $presenter)
    {
        $criteria = ProspectCriteria::findOrFail((int) $id);
        $requestedRunId = null;

        if ($request->query->has('run_id')) {
            $requestedRunId = filter_var($request->query('run_id'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            abort_if($requestedRunId === false, 404);
        }

        $run = $requestedRunId !== null
            ? $criteria->discoveryRuns()
                ->where('type', 'discovery')
                ->whereKey((int) $requestedRunId)
                ->firstOrFail()
            : $criteria->latestDiscoveryRun;

        return response()->json($presenter->present(
            $criteria,
            $run,
            $criteria->companies()->count(),
            config('services.serpapi.driver', 'local') === 'local',
        ), 200)->header('Cache-Control', 'no-store');
    }

    private function discoveryStatusUrl(ProspectCriteria $criteria, DiscoveryRun $run): string
    {
        return route('admin.prospect_criteria.discovery_status', [
            'id' => $criteria->getKey(),
            'run_id' => $run->getKey(),
        ]);
    }
}
