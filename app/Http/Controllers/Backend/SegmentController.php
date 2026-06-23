<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\SegmentViewConfig;
use App\DataTables\Backend\SegmentsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Company;
use App\Models\Segment;
use App\Services\Campaign\SegmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SegmentController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, Segment $model, SegmentsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Assigned at runtime to avoid a trait+class property default conflict.
        $this->viewConfigClass = SegmentViewConfig::class;

        $this->middleware('permission:view segments')->only(['index', 'view', 'preview', 'contacts', 'contactsSearch']);
        $this->middleware('permission:create segments')->only(['create', 'store']);
        $this->middleware('permission:edit segments')->only(['edit', 'update', 'executeSwitch', 'pinContact', 'unpinContact']);
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

        if ($model == null) {
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
     * N.B. On construit la vue manuellement (sans passer par getView()) afin de
     * pouvoir transmettre le $model à getViewVars(), ce qui active la fusion des
     * valeurs filtre stockées qui ne seraient plus présentes dans les entreprises.
     */
    public function edit($id)
    {
        $model = $this->currentModel->find($id);

        if ($model == null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.segments.index'));
        }

        $stats = $this->segmentStats($model);

        // Construction manuelle pour passer $model à getViewVars() (stale-merge).
        $view = view('backend.contents.segments.crud.form');

        // Inject des vars dynamiques (scopes, sectors, countries…) avec fusion stale.
        foreach ($this->getViewVars($model) as $varName => $var) {
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
     *   filter.status string   nullable, in contact_statuses keys
     *
     * Réponse 200 :
     *   matched, suppressed, cold_excluded, personal_excluded,
     *   duplicates_excluded, final, sample[],
     *   summary (string), cold_gate_closed (bool)
     *
     * Réponse 422 : erreurs de validation Laravel standard (JSON).
     * Réponse 500 : { error: 'preview_failed' }
     */
    public function preview(Request $request)
    {
        $countryCodes    = array_keys(config('global.data.company_countries', []));
        $contactStatuses = array_keys(config('global.data.contact_statuses', []));
        $segmentScopes   = array_keys(config('global.data.segment_scopes', []));

        $validated = $request->validate([
            'scope'            => 'required|string|in:' . implode(',', $segmentScopes),
            'filter'           => 'nullable|array',
            'filter.sector'    => 'nullable|array|max:20',
            'filter.sector.*'  => 'string|max:100',
            'filter.country'   => 'nullable|array|max:20',
            'filter.country.*' => 'string|size:2|in:' . implode(',', $countryCodes),
            'filter.status'    => 'nullable|string|in:' . implode(',', $contactStatuses),
        ]);

        $scope  = $validated['scope'];
        $filter = $this->normalizeFilter($validated['filter'] ?? []);

        try {
            $stats = app(SegmentService::class)->resolveWithStats($scope, $filter, withSample: false);
        } catch (\Throwable $e) {
            Log::warning('segments.preview failed', [
                'message' => $e->getMessage(),
                'scope'   => $scope,
            ]);
            return response()->json(['error' => 'preview_failed'], 500);
        }

        $summary        = $this->buildSummary($scope, $filter);
        $coldGateClosed = ! (bool) config('prospecting.cold_send_enabled', false);

        return response()->json($stats + [
            'summary'          => $summary,
            'cold_gate_closed' => $coldGateClosed,
        ]);
    }

    /**
     * Provide select options to the create/edit form views.
     * Le paramètre $model (optionnel) permet de fusionner les valeurs stockées
     * qui ne sont peut-être plus présentes dans les entreprises.
     */
    protected function getViewVars(?Segment $model = null): array
    {
        $allCountries = config('global.data.company_countries', []);

        // Secteurs distincts présents dans les entreprises.
        $sectors = Company::query()
            ->whereNotNull('sector')
            ->where('sector', '!=', '')
            ->distinct()
            ->orderBy('sector')
            ->pluck('sector')
            ->all();

        // Pays distincts présents dans les entreprises, avec libellé français.
        $dbCountryCodes = Company::query()
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->pluck('country')
            ->all();

        $countries = [];
        foreach ($dbCountryCodes as $iso) {
            $countries[$iso] = $allCountries[$iso] ?? $iso;
        }
        asort($countries); // tri par libellé

        // Fusion des valeurs stockées dans le modèle afin qu'un edit ne supprime
        // pas silencieusement un filtre dont la valeur n'est plus dans la DB.
        if ($model !== null) {
            $stored = $model->filter ?? [];

            // Secteurs stockés manquants dans la liste DB.
            foreach ((array) ($stored['sector'] ?? []) as $s) {
                if ($s !== '' && $s !== null && ! in_array($s, $sectors, true)) {
                    $sectors[] = $s;
                }
            }
            sort($sectors);

            // Pays stockés manquants dans la liste DB.
            foreach ((array) ($stored['country'] ?? []) as $iso) {
                if ($iso !== '' && $iso !== null && ! array_key_exists($iso, $countries)) {
                    $countries[$iso] = $allCountries[$iso] ?? $iso;
                }
            }
            asort($countries);
        }

        return [
            'scopes'         => config('global.data.segment_scopes', []),
            'sectors'        => $sectors,
            'countries'      => $countries,
            'contactStatuses' => config('global.data.contact_statuses', []),
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

        // filter.status — scalaire, on exclut si vide.
        $status = trim((string) ($rawFilter['status'] ?? ''));
        if ($status !== '') {
            $filter['status'] = $status;
        }

        $attributes['filter'] = empty($filter) ? null : $filter;

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

        $includeIds = $segment->includedContactIds();
        $excludeIds = $segment->excludedContactIds();

        // Resolve the final audience (post-dedup, post-exclude).
        $resolved = $service->resolve($segment);

        // Build provenance map: contact id → 'pinned' | 'filter'
        $provenance = $resolved->mapWithKeys(fn ($c) => [
            $c->id => in_array($c->id, $includeIds, true) ? 'pinned' : 'filter',
        ])->all();

        // PHP-paginate the resolved collection.
        $perPage     = 25;
        $currentPage = max(1, (int) $this->currentRequest->input('page', 1));
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
        $excludedContacts = $segment->excludedContacts()->with('company:id,name')->get();

        // Stats for the header.
        $counts = $this->segmentStats($segment);

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
     * @param array  $filter  Normalized filter array (keys: sector, country, status)
     * @return string
     */
    private function buildSummary(string $scope, array $filter): string
    {
        $scopeLabels = [
            'client'   => 'clients',
            'prospect' => 'prospects',
            'mixed'    => 'clients et prospects',
        ];
        $scopeLabel = $scopeLabels[$scope] ?? $scope;

        $hasFilter = ! empty($filter['sector']) || ! empty($filter['country']) || ! empty($filter['status']);

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

        // Statut
        if (! empty($filter['status'])) {
            $statusConfig = config('global.data.contact_statuses.' . $filter['status'], null);
            $statusLabel  = $statusConfig['label'] ?? $filter['status'];
            $parts[] = 'au statut ' . $statusLabel;
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

        $status = trim((string) ($rawFilter['status'] ?? ''));
        if ($status !== '') {
            $filter['status'] = $status;
        }

        return $filter;
    }
}
