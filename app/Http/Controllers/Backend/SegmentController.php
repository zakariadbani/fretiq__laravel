<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\SegmentViewConfig;
use App\DataTables\Backend\SegmentsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Http\Controllers\Traits\HandlesImportPreviewToken;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Services\Campaign\SegmentCsvImporter;
use App\Services\Campaign\SegmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SegmentController extends BackendController
{
    use Crudable {
        store as private crudStore;
    }
    use Datatableable;
    use HandlesImportPreviewToken;

    public function __construct(Request $request, Segment $model, SegmentsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Assigned at runtime to avoid a trait+class property default conflict.
        $this->viewConfigClass = SegmentViewConfig::class;

        $this->middleware('permission:view segments')->only(['index', 'view', 'preview', 'contacts', 'contactsSearch']);
        $this->middleware('permission:create segments')->only(['create', 'store', 'importForm', 'importPreview', 'importStore', 'downloadImportTemplate']);
        // importPreview/importStore also require edit — importers upsert existing rows.
        $this->middleware('permission:edit segments')->only(['edit', 'update', 'executeSwitch', 'pinContact', 'unpinContact', 'importPreview', 'importStore']);
        $this->middleware('permission:delete segments')->only(['delete']);

        $this->listTitle = 'Segments';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       Segment::class,
            modelName:        'segments',
            dataTableClass:   SegmentsDataTable::class,
            permissionEntity: 'segments',
            prefixName:       'admin',
            titleField:       'name',
        ));
    }

    /**
     * Override view() to inject contacts count stats for ViewConfig/apercu.
     */
    public function view($id)
    {
        $model = $this->currentModel->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.segments.index'));
        }

        $stats  = $this->segmentStats($model);
        $config = SegmentViewConfig::make($model, $stats);

        $view = $this->getView('backend.contents.segments.crud.view');
        $view
            ->with('title', 'Aperçu')
            ->with('model', $model)
            ->with('viewConfig', $config)
            ->with('stats', $stats);

        return $view;
    }

    /**
     * Override edit() to inject segment stats alongside the standard form vars.
     * Mirrors view() pattern: find or redirect, compute stats, pass to form.
     *
     * N.B. On construit la vue manuellement (sans passer par getView()) afin
     * d'injecter les statistiques et les variables standard du formulaire.
     */
    public function edit($id)
    {
        $model = $this->currentModel->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.segments.index'));
        }

        $stats = $this->segmentStats($model);

        // Construction manuelle pour injecter les statistiques du segment.
        $view = view('backend.contents.segments.crud.form');

        // Inject des vars dynamiques (scopes, sectors, countries…).
        foreach ($this->getViewVars() as $varName => $var) {
            $view->with($varName, $var);
        }
        $view->with('modelName', $this->modelName);

        $view
            ->with('title', trans('app.edit segments', ['name' => $model->{$this->title}]))
            ->with('route', route('admin.segments.update', $id))
            ->with('method', 'post')
            ->with('page', 'edit')
            ->with('model', $model)
            ->with('stats', $stats);

        $view->with('viewConfig', SegmentViewConfig::make($model, $stats));

        return $view;
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.segments.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * Manual segments must land on the saved segment's contact picker.
     * Creating one also requires edit permission because selecting contacts is
     * an edit-only operation.
     */
    public function store()
    {
        $request = request();
        $manual = $request->boolean('is_manual');

        if ($manual && ! $request->user()?->can('edit segments')) {
            abort(403);
        }

        $response = $this->crudStore();

        if ($manual && $response instanceof \Illuminate\Http\JsonResponse && $response->isSuccessful()) {
            $payload = $response->getData(true);
            if (isset($payload['model']['id'])) {
                $payload['redirect'] = route('admin.segments.edit', $payload['model']['id']) . '#segment_contacts';
                $response->setData($payload);
            }
        }

        return $response;
    }

    /**
     * Aperçu AJAX — renvoie les statistiques du funnel de conformité pour
     * un scope + filtre donnés, sans persister quoi que ce soit.
     *
     * POST /admin/segments/preview
     * Throttle : 60 req/min par IP.
     *
     * Corps attendu :
     *   scope         string   required, in segment_scopes keys
     *   filter        array    nullable
     *   filter.sector array    nullable, max 20, chaque entrée string max 100
     *   filter.country array   nullable, max 20, chaque entrée string size:2 in company_countries keys
     *   filter.lifecycle_state string nullable, in calculated lifecycle keys
     *   filter.position array   nullable, max 20, chaque entrée string max 100
     *   filter.exclude_contacted boolean nullable
     *   filter.exclude_generic_mailbox boolean nullable
     *
     * Réponse 200 :
     *   matched, suppressed, duplicates_excluded, final, sample[],
     *   summary (string)
     *
     * Réponse 422 : erreurs de validation Laravel standard (JSON).
     * Réponse 500 : { error: 'preview_failed' }
     */
    /**
     * Centralise la validation de scope + filter.* et la normalisation du filtre.
     * Partagée entre preview() et contacts() pour éviter la dérive de règles.
     *
     * @param  Request  $request
     * @return array{scope: string, filter: array}
     */
    private function validatedScopeFilter(Request $request): array
    {
        $countryCodes    = array_keys(config('global.data.company_countries', []));
        $lifecycleStates = array_keys(config('global.data.contact_lifecycle_states', []));
        $segmentScopes   = array_keys(config('global.data.segment_scopes', []));

        $validated = $request->validate([
            'scope'                => 'required|string|in:' . implode(',', $segmentScopes),
            'filter'               => 'nullable|array',
            'filter.sector'        => 'nullable|array|max:20',
            'filter.sector.*'      => 'string|max:100',
            'filter.country'       => 'nullable|array|max:20',
            'filter.country.*'     => 'string|size:2|in:' . implode(',', $countryCodes),
            'filter.criteria_id'   => ['nullable', 'array'],
            'filter.criteria_id.*' => ['integer', 'exists:prospect_criteria,id'],
            'filter.lifecycle_state' => 'nullable|string|in:' . implode(',', $lifecycleStates),
            'filter.position'       => 'nullable|array|max:20',
            'filter.position.*'     => 'string|max:100',
            'filter.exclude_contacted'       => 'nullable|boolean',
            'filter.exclude_generic_mailbox' => 'nullable|boolean',
            'filter.engagement'                => 'nullable|array',
            'filter.engagement.campaign_id'    => 'nullable|array|max:20',
            'filter.engagement.campaign_id.*'  => 'integer|exists:campaigns,id',
            'filter.engagement.opened'         => 'nullable|boolean',
            'filter.engagement.clicked'        => 'nullable|boolean',
            'filter.engagement.sans_demande'   => 'nullable|boolean',
        ]);

        return [
            'scope'  => $validated['scope'],
            'filter' => $this->normalizeFilter($validated['filter'] ?? []),
        ];
    }

    public function preview(Request $request)
    {
        ['scope' => $scope, 'filter' => $filter] = $this->validatedScopeFilter($request);

        try {
            $stats = app(SegmentService::class)->resolveWithStats($scope, $filter, withSample: false);
        } catch (\Throwable $e) {
            Log::warning('segments.preview failed', [
                'message' => $e->getMessage(),
                'scope'   => $scope,
            ]);
            return response()->json(['error' => 'preview_failed'], 500);
        }

        $summary = $this->buildSummary($scope, $filter);

        return response()->json($stats + [
            'summary' => $summary,
        ]);
    }

    /**
     * Provide select options to the create/edit form views.
     */
    protected function getViewVars(): array
    {
        try {
            $sectorTaxonomy = \App\Models\Sector::where('is_active', true)->pluck('label')->all();
        } catch (\Illuminate\Database\QueryException $e) {
            $sectorTaxonomy = config('global.data.prospect_sectors', []);
        }

        $sectors = array_values(array_unique(array_merge(
            $sectorTaxonomy,
            Company::query()
                ->whereNotNull('sector')
                ->where('sector', '!=', '')
                ->distinct()
                ->pluck('sector')
                ->all()
        )));
        sort($sectors);

        $dbCountryCodes = Company::query()
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->pluck('country')
            ->all();

        $countries = config('global.data.company_countries', []);
        foreach ($dbCountryCodes as $iso) {
            if (! array_key_exists($iso, $countries)) {
                $countries[$iso] = $iso;
            }
        }
        asort($countries);

        return [
            'scopes'         => config('global.data.segment_scopes', []),
            'sectors'        => $sectors,
            'countries'      => $countries,
            'lifecycleStates' => config('global.data.contact_lifecycle_states', []),
            'positionGroups' => config('global.data.prospect_positions', []),
            'criteriaOptions' => ProspectCriteria::query()->orderBy('name')->pluck('name', 'id')->all(),
            'campaigns'      => Campaign::query()->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /**
     * Decode and clean the structured filter fields from the form.
     *
     * La forme attend maintenant des champs structurés (filter[sector][],
     * filter[country][], filter[status]) — plus de textarea JSON.
     * Cette méthode nettoie les tableaux (supprime les chaînes vides, réindexe)
     * et omet les clés vides du filtre final. Si tout est vide → null.
     *
     * @param int|null $id
     * @return array
     */
    protected function beforeSave($id = null): array
    {
        $attributes = $this->currentRequest->all();

        // Construction du filtre structuré à partir des inputs du formulaire.
        $rawFilter = $this->currentRequest->input('filter', []);

        if (! is_array($rawFilter)) {
            $rawFilter = [];
        }

        $filter = [];

        // filter.sector — tableau de chaînes, on supprime les valeurs vides.
        $sector = array_values(array_filter((array) ($rawFilter['sector'] ?? []), fn ($v) => $v !== null && $v !== ''));
        if (! empty($sector)) {
            $filter['sector'] = $sector;
        }

        // filter.country — tableau de codes ISO, on supprime les valeurs vides.
        $country = array_values(array_filter((array) ($rawFilter['country'] ?? []), fn ($v) => $v !== null && $v !== ''));
        if (! empty($country)) {
            $filter['country'] = $country;
        }

        // filter.lifecycle_state — scalaire, on exclut si vide.
        $status = trim((string) ($rawFilter['lifecycle_state'] ?? ''));
        if ($status !== '') {
            $filter['lifecycle_state'] = $status;
        }

        // filter.criteria_id — tableau d'ids de prospect_criteria, castés en int.
        $criteriaId = array_values(array_map(
            fn ($v) => (int) $v,
            array_filter((array) ($rawFilter['criteria_id'] ?? []), fn ($v) => $v !== null && $v !== '')
        ));
        if (! empty($criteriaId)) {
            $filter['criteria_id'] = $criteriaId;
        }

        // filter.position — tableau de tags libres, on supprime les valeurs vides.
        $position = array_values(array_filter((array) ($rawFilter['position'] ?? []), fn ($v) => $v !== null && $v !== ''));
        if (! empty($position)) {
            $filter['position'] = $position;
        }

        if ($this->boolInputSet($rawFilter['exclude_contacted'] ?? null)) {
            $filter['exclude_contacted'] = true;
        }

        if ($this->boolInputSet($rawFilter['exclude_generic_mailbox'] ?? null)) {
            $filter['exclude_generic_mailbox'] = true;
        }

        $engagement = $this->buildEngagementFilter((array) ($rawFilter['engagement'] ?? []));
        if (! empty($engagement)) {
            $filter['engagement'] = $engagement;
        }

        $attributes['is_manual'] = $this->currentRequest->boolean('is_manual');
        $attributes['filter'] = $attributes['is_manual'] || empty($filter) ? null : $filter;

        return $attributes;
    }

    /**
     * Calcule les statistiques du funnel pour un segment sauvegardé.
     * Utilisé par view() et edit() pour alimenter le ViewConfig et le formulaire.
     *
     * B2: threads the segment's pin id-sets into resolveWithStats so the hero tile,
     * stat-card, and datatable column show the pin-adjusted count, not filter-only.
     *
     * Retourne : contacts_count (int) + funnel (array complet de resolveWithStats)
     *          + pinned_in (int) + pinned_out (int).
     */
    private function segmentStats(Segment $segment): array
    {
        $includeIds = $segment->includedContactIds();
        $excludeIds = $segment->excludedContactIds();

        $stats = app(SegmentService::class)->resolveWithStats(
            $segment->scope,
            $segment->filter ?? [],
            false,  // withSample: false — sample table removed (M2)
            $includeIds,
            $excludeIds,
            $segment->is_manual,
        );

        return [
            'contacts_count' => $stats['final'],
            'funnel'         => $stats,
            'pinned_in'      => count($includeIds),
            'pinned_out'     => count($excludeIds),
        ];
    }

    /**
     * Pin (or flip mode of) a contact on this segment.
     *
     * POST /admin/segments/{id}/contacts
     * Body: contact_id (exists:contacts,id), mode (in:include,exclude)
     * Returns: JSON { success: true, counts: { contacts_count, pinned_in, pinned_out } }
     *
     * @param  int  $id  Segment ID
     */
    public function pinContact(int $id)
    {
        $segment = Segment::findOrFail($id);

        // Use request() helper to guarantee we get the current request, not the one
        // injected at construction time (which may be stale when multiple requests are
        // dispatched in the same test process against the same kernel singleton).
        $currentRequest = request();
        $validated = $currentRequest->validate([
            'contact_id' => 'required|integer|exists:contacts,id',
            'mode'       => 'required|in:include,exclude',
        ]);

        $contactId = (int) $validated['contact_id'];
        $mode      = $validated['mode'];

        // Upsert the pin row: insert if new, update mode if the pair already exists.
        // Using DB::table upsert (MySQL INSERT ... ON DUPLICATE KEY UPDATE) for
        // guaranteed atomicity regardless of the pivot's current mode value.
        // The unique key is (segment_id, contact_id) — see migration.
        \Illuminate\Support\Facades\DB::table('contact_segment')->upsert(
            [
                [
                    'segment_id' => $segment->id,
                    'contact_id' => $contactId,
                    'mode'       => $mode,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ],
            uniqueBy: ['segment_id', 'contact_id'],
            update: ['mode', 'updated_at'],
        );

        // Flush cached id-sets so segmentStats reflects the new pin.
        $segment->refresh();

        return response()->json([
            'success' => true,
            'counts'  => $this->segmentStats($segment),
        ]);
    }

    /**
     * Remove a contact pin from this segment (revert to filter default).
     *
     * DELETE /admin/segments/{id}/contacts/{contact}
     * Returns: JSON { success: true, counts: { contacts_count, pinned_in, pinned_out } }
     *
     * @param  int  $id       Segment ID
     * @param  int  $contact  Contact ID
     */
    public function unpinContact(int $id, int $contact)
    {
        $segment = Segment::findOrFail($id);

        $segment->pinnedContacts()->detach($contact);

        $segment->refresh();

        return response()->json([
            'success' => true,
            'counts'  => $this->segmentStats($segment),
        ]);
    }

    /**
     * Search contacts for the picker — returns contacts not already pinned.
     *
     * GET /admin/segments/{id}/contacts/search?q=
     * Returns: JSON { results: [{ id, name, email, company }] }
     *
     * Soft-deleted contacts are excluded (Contact uses SoftDeletes global scope).
     * Throttle: 60 req/min (applied at route level).
     *
     * @param  int  $id  Segment ID
     */
    public function contactsSearch(int $id)
    {
        $segment   = Segment::findOrFail($id);
        $pinnedIds = $segment->pinnedContacts()->pluck('contacts.id')->toArray();

        $q = trim((string) $this->currentRequest->input('q', ''));

        $query = \App\Models\Contact::with('company:id,name')
            ->whereNotIn('id', $pinnedIds)
            ->whereNotNull('email')
            ->whereRaw("TRIM(email) != ''");

        if ($q !== '') {
            $query->where(function (\Illuminate\Database\Eloquent\Builder $builder) use ($q) {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $builder->where('name', 'LIKE', $like)
                        ->orWhere('email', 'LIKE', $like);
            });
        }

        $contacts = $query->orderBy('name')->limit(20)->get();

        $results = $contacts->map(fn (\App\Models\Contact $c) => [
            'id'      => $c->id,
            'name'    => $c->name,
            'email'   => $c->email,
            'company' => $c->company?->name ?? '',
        ])->values()->all();

        return response()->json(['results' => $results]);
    }

    /**
     * Return the paginated resolved audience for a segment's Contacts pane.
     *
     * GET /admin/segments/{id}/contacts
     * Query params: page (int, default 1)
     *
     * Resolves the full audience via SegmentService, PHP-paginates the post-dedup
     * collection, computes per-row provenance (pinned | filter), and renders the
     * _contacts-rows partial fragment (AJAX re-render pattern).
     *
     * Excluded contacts are fetched separately (not in paginator total).
     *
     * @param  int  $id  Segment ID
     */
    public function contacts(int $id)
    {
        $segment = Segment::findOrFail($id);
        $service = app(SegmentService::class);

        // Pins always come from the saved segment — independent of live filter.
        $includeIds = $segment->includedContactIds();
        $excludeIds = $segment->excludedContactIds();

        // Use request() helper to guarantee we get the current request, not the one
        // injected at construction time (which may be stale in test scenarios — see pinContact).
        $currentRequest = request();

        // Live filtering applies only to dynamic segments. A manual segment is an
        // explicit saved selection, so its scope/filter must never widen the audience.
        if (! $segment->is_manual && filled($currentRequest->input('scope'))) {
            ['scope' => $scope, 'filter' => $filter] = $this->validatedScopeFilter($currentRequest);
            $resolved = $service->resolveAudience($scope, $filter, $includeIds, $excludeIds);
            $counts   = [
                'contacts_count' => $resolved->count(),
                'pinned_in'      => count($includeIds),
                'pinned_out'     => count($excludeIds),
            ];
        } else {
            $resolved = $service->resolve($segment);
            $counts   = $this->segmentStats($segment);
        }

        // Build provenance map: contact id → 'pinned' | 'filter'
        $provenance = $resolved->mapWithKeys(fn ($c) => [
            $c->id => in_array($c->id, $includeIds, true) ? 'pinned' : 'filter',
        ])->all();

        // PHP-paginate the resolved collection.
        $perPage     = 25;
        $currentPage = max(1, (int) $currentRequest->input('page', 1));
        $total       = $resolved->count();
        $items       = $resolved->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $currentPage,
            ['path' => route('admin.segments.contacts', $id)]
        );

        // Excluded contacts (separate query, not in paginator total).
        // Always the saved excluded contacts — pins are filter-independent.
        $excludedContacts = app(\App\Services\Prospecting\ContactLifecycleService::class)
            ->select($segment->excludedContacts()->with('company:id,name'))
            ->get();

        return view('backend.contents.segments.partials._contacts-rows', [
            'segment'          => $segment,
            'paginator'        => $paginator,
            'excludedContacts' => $excludedContacts,
            'counts'           => $counts,
            'provenance'       => $provenance,
        ]);
    }

    /**
     * Construit la phrase de résumé française décrivant l'audience cible.
     *
     * Exemples :
     *   « Cible : contacts clients du secteur Transport en France au statut Qualifié »
     *   « Cible : tous les contacts prospects »
     *   « Cible : contacts clients et prospects des secteurs Transport ou Logistique »
     *
     * @param string $scope   One of: 'client', 'prospect', 'mixed'
     * @param array  $filter  Normalized filter array (keys: sector, country, lifecycle_state,
     *                        criteria_id, position, exclude_contacted, exclude_generic_mailbox)
     * @return string
     */
    private function buildSummary(string $scope, array $filter): string
    {
        $scopeLabels = [
            'client'   => 'clients',
            'prospect' => 'prospects',
            'test'     => 'tests',
            'mixed'    => 'clients et prospects',
        ];
        $scopeLabel = $scopeLabels[$scope] ?? $scope;

        $hasFilter = ! empty($filter['sector']) || ! empty($filter['country']) || ! empty($filter['lifecycle_state'])
            || ! empty($filter['position']) || ! empty($filter['exclude_contacted']) || ! empty($filter['exclude_generic_mailbox'])
            || ! empty($filter['engagement']);

        if (! $hasFilter) {
            return "Cible : tous les contacts {$scopeLabel}";
        }

        $parts = ["Cible : contacts {$scopeLabel}"];

        // Secteurs
        if (! empty($filter['sector'])) {
            $sectors = $filter['sector'];
            if (count($sectors) === 1) {
                $parts[] = 'du secteur ' . $sectors[0];
            } else {
                $parts[] = 'des secteurs ' . implode(' ou ', $sectors);
            }
        }

        // Pays — on cherche les libellés dans la config.
        if (! empty($filter['country'])) {
            $allCountries = config('global.data.company_countries', []);
            $labels = array_map(fn ($iso) => $allCountries[$iso] ?? $iso, $filter['country']);
            if (count($labels) === 1) {
                $parts[] = 'en ' . $labels[0];
            } else {
                $parts[] = 'en ' . implode(' ou ', $labels);
            }
        }

        // État
        if (! empty($filter['lifecycle_state'])) {
            $statusConfig = config('global.data.contact_lifecycle_states.' . $filter['lifecycle_state'], null);
            $statusLabel  = $statusConfig['label'] ?? $filter['lifecycle_state'];
            $parts[] = 'à l’état ' . $statusLabel;
        }

        // Postes ciblés
        if (! empty($filter['position'])) {
            $positions = $filter['position'];
            if (count($positions) === 1) {
                $parts[] = 'au poste ' . $positions[0];
            } else {
                $parts[] = 'aux postes ' . implode(' ou ', $positions);
            }
        }

        // Exclusions
        if (! empty($filter['exclude_contacted'])) {
            $parts[] = 'hors contacts déjà sollicités';
        }
        if (! empty($filter['exclude_generic_mailbox'])) {
            $parts[] = 'hors boîtes génériques';
        }

        // Engagement
        if (! empty($filter['engagement'])) {
            $engagement = $filter['engagement'];
            if (array_key_exists('opened', $engagement)) {
                $parts[] = $engagement['opened'] ? 'ayant ouvert' : "n'ayant pas ouvert";
            }
            if (array_key_exists('clicked', $engagement)) {
                $parts[] = $engagement['clicked'] ? 'ayant cliqué' : "n'ayant pas cliqué";
            }
            if (! empty($engagement['sans_demande'])) {
                $parts[] = 'sans demande de cotation';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Normalise un tableau de filtre brut : supprime les tableaux vides,
     * les chaînes vides, et les valeurs null. Retourne un tableau propre
     * (potentiellement vide si tous les champs étaient vides).
     *
     * @param array $rawFilter
     * @return array
     */
    private function normalizeFilter(array $rawFilter): array
    {
        $filter = [];

        $sector = array_values(array_filter((array) ($rawFilter['sector'] ?? []), fn ($v) => $v !== null && $v !== ''));
        if (! empty($sector)) {
            $filter['sector'] = $sector;
        }

        $country = array_values(array_filter((array) ($rawFilter['country'] ?? []), fn ($v) => $v !== null && $v !== ''));
        if (! empty($country)) {
            $filter['country'] = $country;
        }

        // filter.criteria_id — tableau d'ids de prospect_criteria, castés en int.
        $criteriaId = array_values(array_map(
            fn ($v) => (int) $v,
            array_filter((array) ($rawFilter['criteria_id'] ?? []), fn ($v) => $v !== null && $v !== '')
        ));
        if (! empty($criteriaId)) {
            $filter['criteria_id'] = $criteriaId;
        }

        $status = trim((string) ($rawFilter['lifecycle_state'] ?? ''));
        if ($status !== '') {
            $filter['lifecycle_state'] = $status;
        }

        $position = array_values(array_filter((array) ($rawFilter['position'] ?? []), fn ($v) => $v !== null && $v !== ''));
        if (! empty($position)) {
            $filter['position'] = $position;
        }

        if ($this->boolInputSet($rawFilter['exclude_contacted'] ?? null)) {
            $filter['exclude_contacted'] = true;
        }

        if ($this->boolInputSet($rawFilter['exclude_generic_mailbox'] ?? null)) {
            $filter['exclude_generic_mailbox'] = true;
        }

        $engagement = $this->buildEngagementFilter((array) ($rawFilter['engagement'] ?? []));
        if (! empty($engagement)) {
            $filter['engagement'] = $engagement;
        }

        return $filter;
    }

    /**
     * Build the cleaned filter.engagement sub-array from raw form/AJAX input.
     * Shared by beforeSave() (persist) and normalizeFilter() (live preview) so
     * the two paths can never drift apart on how the tri-state fields decode.
     *
     * campaign_id  → array of ints, empty entries dropped.
     * opened/clicked → tri-state: '' or absent = omitted entirely (indifférent);
     *   a stored `false` would actively exclude contacts on that dimension
     *   instead of leaving it unfiltered, so only an explicit value is kept.
     * sans_demande → plain checkbox boolean, omitted unless truthy.
     *
     * @param  array  $rawEngagement
     * @return array<string, mixed>
     */
    private function buildEngagementFilter(array $rawEngagement): array
    {
        $engagement = [];

        $campaignIds = array_values(array_map(
            fn ($v) => (int) $v,
            array_filter((array) ($rawEngagement['campaign_id'] ?? []), fn ($v) => $v !== null && $v !== '')
        ));
        if (! empty($campaignIds)) {
            $engagement['campaign_id'] = $campaignIds;
        }

        $opened = $this->triStateInput($rawEngagement['opened'] ?? null);
        if ($opened !== null) {
            $engagement['opened'] = $opened;
        }

        $clicked = $this->triStateInput($rawEngagement['clicked'] ?? null);
        if ($clicked !== null) {
            $engagement['clicked'] = $clicked;
        }

        if ($this->boolInputSet($rawEngagement['sans_demande'] ?? null)) {
            $engagement['sans_demande'] = true;
        }

        return $engagement;
    }

    /**
     * Tri-state select parser for filter.engagement.opened / .clicked: '' (or
     * null/absent) means "indifférent" and returns null so the caller omits
     * the key entirely. Only an explicit value becomes a real boolean.
     *
     * @param  mixed  $value
     */
    private function triStateInput(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * True when a raw boolean-ish input value is present AND truthy.
     * Absent/false must not be persisted as filter noise — only a real "on" is kept.
     *
     * @param  mixed  $value
     */
    private function boolInputSet(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // ── CSV import ─────────────────────────────────────────────────────────────
    // Clones App\Http\Controllers\Backend\ProspectCriteriaController's
    // importForm/importPreview/importStore flow. Unlike criteria (which
    // rejects a re-import of an existing name), segments upsert by name —
    // persistence lives in SegmentCsvImporter::store() rather than inline
    // here, see that class's docblock.

    public function importForm()
    {
        return view('backend.contents.segments.crud.import', [
            'rows' => [],
            'importToken' => null,
        ]);
    }

    public function importPreview(Request $request, SegmentCsvImporter $importer)
    {
        $request->validate(['csv' => ['required', 'file', 'max:512', 'mimes:csv,txt']]);
        $file = $request->file('csv');
        if ($file === null) {
            throw ValidationException::withMessages(['csv' => ['Le fichier CSV est requis.']]);
        }

        $rows = $importer->parse((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());

        return view('backend.contents.segments.crud.import', [
            'rows' => $rows,
            'importToken' => $this->makeImportToken($request->user()->id, $rows),
        ]);
    }

    public function importStore(Request $request, SegmentCsvImporter $importer)
    {
        try {
            $rows = $this->decryptImportToken((string) $request->input('import_token'), (int) $request->user()->id, SegmentCsvImporter::MAX_ROWS);
            $result = $importer->store($rows);
        } catch (ValidationException $exception) {
            return redirect()->route('admin.segments.import_form')->withErrors($exception->errors());
        }

        $redirect = redirect()->route('admin.segments.index')
            ->with('success', "{$result['created']} segment(s) créé(s), {$result['updated']} mis à jour.");

        if ($result['warnings'] !== []) {
            $redirect->with('warning', implode(' ', $result['warnings']));
        }

        return $redirect;
    }

    public function downloadImportTemplate(SegmentCsvImporter $importer)
    {
        return response($importer->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modele-segments.csv"',
        ]);
    }
}
