<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\ProspectBatchesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\EnrichCriteriaContactsJob;
use App\Jobs\RescoreProspectBatchCompaniesJob;
use App\Models\ProspectBatch;
use App\Services\Discovery\CriteriaContactEnrichmentService;
use App\Services\Prospecting\CompanyListParser;
use App\Services\Prospecting\ContactLifecycleService;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectReviewPresenter;
use App\Services\Prospecting\ProspectReviewWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProspectBatchController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, ProspectBatch $model, ProspectBatchesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->viewConfigClass = \App\Crud\ViewConfigs\ProspectBatchViewConfig::class;
        $this->middleware('permission:view prospect_batches')->only(['index', 'view', 'status', 'rescoreStatus']);
        $this->middleware('permission:create prospect_batches')->only(['create', 'store']);
        $this->middleware('permission:edit prospect_batches')->only(['edit', 'update']);
        $this->middleware('permission:delete prospect_batches')->only(['delete']);
        $this->middleware('permission:run prospect resolution')->only(['estimate', 'confirm', 'resumeDiscovery', 'rescoreDispatch']);
        $this->middleware('permission:enrich companies')->only(['contactEnrichmentPreview', 'dispatchContactEnrichment']);

        $this->listTitle = 'Lots de prospection';
        $this->title = 'name';

        $this->bootResource(new BackendResource(
            modelClass: ProspectBatch::class,
            modelName: 'prospect_batches',
            dataTableClass: ProspectBatchesDataTable::class,
            permissionEntity: 'prospect_batches',
            prefixName: 'admin',
            titleField: 'name',
        ));
    }

    public function index()
    {
        $this->currentRequest = request();
        $this->currentDataTable = app(ProspectBatchesDataTable::class);

        if ($this->currentRequest->ajax() && $this->currentRequest->wantsJson()) {
            return $this->currentDataTable->ajax();
        }

        return $this->currentDataTable->render('backend.contents.prospect_batches.crud.index', [
            'listTitle' => $this->listTitle,
            'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
        ]);
    }

    public function create()
    {
        return view('backend.contents.prospect_batches.crud.form', [
            'model' => new ProspectBatch(['quality_preset' => 'balanced']),
            'route' => route('admin.prospect_batches.store'),
            'method' => 'POST',
            'page' => 'create',
            'initialStep' => 1,
        ]);
    }

    public function store(Request $request, CompanyListParser $parser, ProspectBatchService $batches)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'companies_text' => ['nullable', 'string', 'max:10485760'],
            'companies_csv' => ['nullable', 'file', 'max:10240'],
            'quality_preset' => ['required', Rule::in(['lean', 'balanced', 'deep'])],
            'domain_search_max_results' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $hasText = trim((string) ($validated['companies_text'] ?? '')) !== '';
        $hasFile = $request->hasFile('companies_csv');
        if ($hasText === $hasFile) {
            throw ValidationException::withMessages([
                'companies_text' => 'Choisissez soit le copier-coller, soit un fichier CSV.',
            ]);
        }

        $parsed = $hasText
            ? $parser->parseText((string) $validated['companies_text'])
            : $parser->parseCsv($request->file('companies_csv'));
        if (($parsed['rows'] ?? []) === []) {
            throw ValidationException::withMessages([
                'companies_text' => $this->parserMessage($parsed['errors'][0]['code'] ?? 'empty_list'),
            ]);
        }

        $qualitySettings = [];
        if (isset($validated['domain_search_max_results'])) {
            $qualitySettings['domain_search_max_results'] = (int) $validated['domain_search_max_results'];
        }
        $safeErrors = collect($parsed['errors'] ?? [])->take(100)->map(static fn (array $error): array => [
            'row_number' => isset($error['row_number']) ? (int) $error['row_number'] : null,
            'code' => preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($error['code'] ?? '')) === 1
                ? (string) $error['code']
                : 'row_invalid',
        ])->values()->all();

        $batch = $batches->createListBatch($request->user(), $parsed['rows'], [
            'name' => trim((string) ($validated['name'] ?? '')) ?: null,
            'quality_preset' => $validated['quality_preset'],
            'quality_settings' => $qualitySettings,
            'source_options' => ['import_errors' => $safeErrors],
        ]);

        return redirect()->route('admin.prospect_batches.edit', ['id' => $batch->id, 'step' => 2])
            ->with('success', $batch->total_items.' entreprise(s) ajoutée(s).');
    }

    /**
     * The "À vérifier" tab is rendered inline here (not on a separate route):
     * ?partial=1 returns just the review detail-pane fragment for the
     * workspace's queue-item fetch swap, everything else renders the full
     * tabbed page. Both branches gate on 'review prospect matches' at the
     * controller layer (A-1) — the Blade @can on the tab link alone would
     * leak the workspace data/queries to a user without the permission.
     */
    public function view(Request $request, ProspectReviewPresenter $presenter, ProspectReviewWorkspace $workspace, $id)
    {
        $batch = ProspectBatch::query()->with('creator')->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        $canReview = (bool) $request->user()?->can('review prospect matches');

        if ($request->boolean('partial')) {
            abort_unless($canReview, 403);
            $built = $workspace->buildFromRequest($request, $request->user(), $batch->getKey());

            return view('backend.contents.prospect_review.partials._review-detail', [
                ...$built['data'],
                'reviewPresenter' => $presenter,
                'hostBatchId' => $batch->getKey(),
            ]);
        }

        // ponytail: 'contacts' dropped from the sort whitelist — the Résultats
        // table now displays the company's LIVE contact count (company.contacts,
        // eager-loaded below), not imported_contacts_count. The two diverge after
        // any manual "Récupérer les contacts" click (company-centric, no batch
        // item in scope) and imported_contacts_count never moves again. Sorting by
        // the old column would visibly disagree with what's on screen. Upgrade
        // path if sorting is wanted back: a subquery sort on company contacts count.
        $allowedResultSorts = [
            'row' => 'row_number',
            'name' => 'company_name',
            'domain' => 'selected_domain',
            'status' => 'status',
        ];
        $resultsSortParam = $request->query('results_sort', 'row');
        $resultsSort = is_string($resultsSortParam) ? $resultsSortParam : 'row';
        if (! array_key_exists($resultsSort, $allowedResultSorts)) {
            $resultsSort = 'row';
        }
        $resultsDirParam = $request->query('results_dir', 'asc');
        $resultsDir = is_string($resultsDirParam) && strtolower($resultsDirParam) === 'desc' ? 'desc' : 'asc';
        $sortColumn = $allowedResultSorts[$resultsSort];

        $items = $batch->items()
            ->withCount('importedContacts')
            ->with(['company.contacts' => fn ($q) => app(ContactLifecycleService::class)->select($q)])
            ->orderBy($sortColumn, $resultsDir)
            ->when($resultsSort !== 'row', fn ($query) => $query->orderBy('row_number'))
            ->paginate(25, ['*'], 'results_page')
            ->withQueryString();

        // Grouped-by-status count — the only source ProspectBatchViewConfig's tab
        // badges and stat cards may use; the persisted row_number/processed_items
        // counters on $batch lag behind (see ViewConfig for why).
        $stats = [
            'status_counts' => $batch->items()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'imported_contacts_count' => $batch->importedContacts()->count(),
        ];
        // Computed once here and threaded into both the ViewConfig quick actions
        // and the hero _header-actions partial — those two rendered the same
        // COUNT(DISTINCT company_id) query independently before.
        $companyCount = (int) $batch->items()->whereNotNull('company_id')->distinct('company_id')->count('company_id');

        // Only built (and only ever gated visible) when the tab itself would be
        // shown — never build the review queue/company data for a user who
        // can't act on it, and never on a draft batch (nothing to review yet).
        $workspacePayload = ($canReview && $batch->status !== 'draft')
            ? $workspace->buildFromRequest($request, $request->user(), $batch->getKey())
            : null;

        // A requested ?item= that didn't resolve to itself (already decided,
        // stale bookmark, wrong owner) silently swapped in a different
        // company via the workspace fallback — the URL still shows the old
        // id, so say so. Skipped when a 'warning' is already queued for this
        // request (e.g. the 409 double-submit redirect) — that alert already
        // explains the swap; stacking this info notice on top would just
        // repeat it.
        if ($workspacePayload !== null) {
            $reviewFilters = $workspacePayload['filters'];
            $reviewData = $workspacePayload['data'];
            if ($reviewFilters['item'] !== null && $reviewData['activeItem'] !== null && (int) $reviewData['activeItem']->getKey() !== $reviewFilters['item'] && ! $request->session()->has('warning')) {
                $request->session()->now('info', 'Cette fiche n’est plus disponible ; une autre entreprise à vérifier a été affichée à la place.');
            }
        }

        // Same visibility gate as $workspacePayload — never run the stalled
        // count for a user who can't act on it or a batch with nothing to
        // review yet. Feeds the Résultats tab's "Reprendre les lignes en
        // attente" button (_results-tab.blade.php).
        $stalledCount = ($canReview && $batch->status !== 'draft')
            ? $workspace->stalledItemsCountForBatch($request->user(), $batch->getKey())
            : 0;

        return view('backend.contents.prospect_batches.crud.view', [
            'model' => $batch,
            'items' => $items,
            'resultsSort' => $resultsSort,
            'resultsDir' => $resultsDir,
            'viewConfig' => \App\Crud\ViewConfigs\ProspectBatchViewConfig::make($batch, $stats, $companyCount),
            'companyCount' => $companyCount,
            // Same source the "À vérifier" tab badge uses (ProspectBatchViewConfig's
            // $reviewCount) — the Aperçu banner must agree with the badge, never the
            // stale persisted $batch->status.
            'reviewItemsCount' => ($stats['status_counts']['review'] ?? 0) + ($stats['status_counts']['failed'] ?? 0),
            'presenter' => $presenter,
            'outcomeBreakdown' => $this->enrichmentOutcomeBreakdown($batch),
            'workspace' => $workspacePayload,
            'canReview' => $canReview,
            'stalledCount' => $stalledCount,
        ]);
    }

    /**
     * Why do this batch's promoted companies have no contacts? Scoped to
     * THIS batch's items (via company_id), not the criterion — grouping by
     * criteria_id would also pull in companies discovered by the separate
     * SerpAPI pipeline. One grouped query; never run per row.
     *
     * @return \Illuminate\Support\Collection<int, array{label:string,color:string,total:int}>
     */
    private function enrichmentOutcomeBreakdown(ProspectBatch $batch): \Illuminate\Support\Collection
    {
        return DB::table('prospect_batch_items')
            ->join('companies', 'companies.id', '=', 'prospect_batch_items.company_id')
            ->where('prospect_batch_items.prospect_batch_id', $batch->getKey())
            // ponytail: mirrors the row-level null-badge rule in
            // _results-tab.blade.php — a null-status company that already has
            // (non-deleted) contacts got them via CSV import, not a search
            // that ran and found nothing, so it's dropped from the null
            // bucket rather than counted alongside the genuinely contactless
            // ones. Non-null statuses are untouched — same bucket regardless
            // of contact count.
            ->where(function ($query) {
                $query->whereNotNull('companies.enrichment_status')
                    ->orWhereNotExists(function ($contacts) {
                        $contacts->selectRaw('1')->from('contacts')
                            ->whereColumn('contacts.company_id', 'companies.id')
                            ->whereNull('contacts.deleted_at');
                    });
            })
            ->selectRaw('companies.enrichment_status as status, count(*) as total')
            ->groupBy('companies.enrichment_status')
            ->orderByDesc('total')
            ->get()
            ->map(static function (object $row): array {
                $config = $row->status !== null
                    ? config('global.data.company_enrichment_statuses.'.$row->status)
                    : config('global.data.company_enrichment_status_null');

                return [
                    'label' => $config['label'] ?? ($row->status ?? 'Non tenté'),
                    'color' => $config['color'] ?? 'secondary',
                    'total' => (int) $row->total,
                ];
            });
    }

    public function edit($id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        // Steps 2-4 of the create wizard live on this URL — but only while the
        // batch is still a draft. Once launched (queued/running/review/...),
        // the wizard has nothing left to do (estimate/confirm/update all 422
        // on a non-draft batch) — send the user to the consult-only view page.
        if ($batch->status !== 'draft') {
            return redirect()->route('admin.prospect_batches.view', $batch)
                ->with('info', 'Ce lot est déjà lancé — il ne peut plus être modifié, seulement consulté.');
        }

        return view('backend.contents.prospect_batches.crud.form', [
            'model' => $batch,
            'route' => route('admin.prospect_batches.update', $batch),
            'method' => 'PUT',
            'page' => 'edit',
            'initialStep' => max(1, min(4, (int) request('step', 2))),
        ]);
    }

    public function update(Request $request, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        if ($batch->status !== 'draft') {
            throw ValidationException::withMessages(['name' => 'Un lot lancé ne peut plus être modifié.']);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quality_preset' => ['required', Rule::in(['lean', 'balanced', 'deep'])],
            'domain_search_max_results' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $batch->forceFill([
            'name' => trim($validated['name']),
            'quality_preset' => $validated['quality_preset'],
            'quality_settings' => isset($validated['domain_search_max_results'])
                ? ['domain_search_max_results' => (int) $validated['domain_search_max_results']]
                : [],
            'estimate' => null,
        ])->save();

        return redirect()->route('admin.prospect_batches.edit', ['id' => $batch->id, 'step' => 2])
            ->with('success', 'Préférences enregistrées.');
    }

    public function estimate(Request $request, ProspectBatchService $batches, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        if ($batch->status !== 'draft') {
            return response()->json(['message' => 'error', 'code' => 'batch_not_draft'], 422);
        }

        $validated = $request->validate([
            'quality_preset' => ['required', Rule::in(['lean', 'balanced', 'deep'])],
            'domain_search_max_results' => ['nullable', 'integer', 'min:10', 'max:500'],
        ]);
        $batch->forceFill([
            'quality_preset' => $validated['quality_preset'],
            'quality_settings' => isset($validated['domain_search_max_results'])
                ? ['domain_search_max_results' => (int) $validated['domain_search_max_results']]
                : [],
        ])->save();

        return response()->json([
            'estimate' => $batches->estimate($batch->fresh()),
            'estimated_at' => now()->toIso8601String(),
        ]);
    }

    public function confirm(Request $request, ProspectBatchService $batches, $id)
    {
        $request->validate(['confirm_cost' => ['accepted']]);
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        try {
            $batch = $batches->confirmAndDispatch($batch, $request->user());
        } catch (LogicException $exception) {
            $code = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'batch_confirmation_failed';

            return response()->json(['message' => 'error', 'code' => $code], $code === 'prospect_discover_active_batch_exists' ? 409 : 422);
        }

        return response()->json([
            'message' => 'success',
            'status' => $batch->status,
            'status_url' => route('admin.prospect_batches.status', $batch),
            'view_url' => route('admin.prospect_batches.view', $batch),
        ]);
    }

    public function resumeDiscovery(Request $request, ProspectBatchService $batches, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        try {
            $batch = $batches->resumeDiscoverBatch($batch, $request->user());
        } catch (LogicException $exception) {
            $code = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'batch_resume_failed';

            return response()->json(['message' => 'error', 'code' => $code], in_array($code, [
                'prospect_discover_not_resumable',
                'prospect_discover_resume_criteria_stale',
            ], true) ? 409 : 422);
        }

        return response()->json([
            'message' => 'success',
            'status' => $batch->status,
            'status_url' => route('admin.prospect_batches.status', $batch),
            'view_url' => route('admin.prospect_batches.view', $batch),
        ]);
    }

    public function status($id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        $total = max(0, (int) $batch->total_items);
        $processed = max(0, min($total, (int) $batch->processed_items));
        $active = in_array($batch->status, ['queued', 'running'], true);
        $workerWaiting = $active
            && $processed < $total
            && $batch->updated_at?->lt(now()->subMinutes(2));

        return response()->json([
            'id' => $batch->id,
            'status' => $batch->status,
            'progress' => [
                'total' => $total,
                'processed' => $processed,
                'percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
                'review' => (int) $batch->review_items,
                'failed' => (int) $batch->failed_items,
                'promoted' => (int) $batch->promoted_companies,
                'imported_contacts_count' => (int) $batch->imported_contacts,
            ],
            'worker_waiting' => $workerWaiting,
            'terminal' => in_array($batch->status, ['review', 'completed', 'failed', 'cancelled'], true),
            'view_url' => route('admin.prospect_batches.view', $batch),
            // À vérifier is a tab on the view page, not a separate route (A-3).
            'review_url' => route('admin.prospect_batches.view', $batch).'#prospect_batch_review',
        ]);
    }

    /**
     * Preview counts for the "Chercher les contacts manquants" batch action —
     * same shape as ProspectCriteriaController::contactEnrichmentPreview(),
     * scoped to this batch's promoted companies (CriteriaContactEnrichmentService
     * $batch param). Both actions require the batch to be linked to a criteria
     * (see governing constraint in the plan): batch-promoted companies inherit
     * criteria_id from the batch and are admitted/scored against it.
     */
    public function contactEnrichmentPreview($id, CriteriaContactEnrichmentService $service)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        if (($guard = $this->batchActionGuard($batch)) !== null) {
            return $guard;
        }

        $snapshot = $service->snapshot($batch->criteria, $batch);
        $snapshot['approval_token'] = Crypt::encryptString(json_encode([
            'criteria_id' => $batch->criteria->id,
            'prospect_batch_id' => $batch->id,
            'success_target' => $snapshot['success_target'],
            'attempt_limit' => $snapshot['attempt_limit'],
            'expires_at' => now()->addMinutes(5)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return response()->json($snapshot, 200)
            ->header('Cache-Control', 'no-store');
    }

    public function dispatchContactEnrichment(Request $request, $id, CriteriaContactEnrichmentService $service)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        if (($guard = $this->batchActionGuard($batch)) !== null) {
            return $guard;
        }
        $criteria = $batch->criteria;

        try {
            $approval = json_decode(Crypt::decryptString((string) $request->input('approval_token')), true, 512, JSON_THROW_ON_ERROR);
            $successTarget = (int) ($approval['success_target'] ?? 0);
            $attemptLimit = (int) ($approval['attempt_limit'] ?? 0);
            $validApproval = (int) ($approval['criteria_id'] ?? 0) === (int) $criteria->id
                && (int) ($approval['prospect_batch_id'] ?? 0) === (int) $batch->id
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

        $snapshot = $service->snapshot($criteria, $batch);
        if ($snapshot['callable_count'] <= 0) {
            return response()->json([
                'message' => 'error',
                'text' => 'Aucune entreprise ne peut être enrichie maintenant.',
                ...$snapshot,
            ], 422);
        }

        $key = EnrichCriteriaContactsJob::admissionKey($criteria->id, $batch->id);
        $lock = Cache::lock($key, 3600);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'error',
                'text' => 'Une recherche de contacts est déjà en cours pour ce lot.',
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
                prospectBatchId: $batch->id,
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
     * Manual "Relancer le scoring IA" batch action. No approval-token
     * handshake (unlike enrichment): LeadScoringService::score() never
     * throws and there's no metered provider quota to reserve up front —
     * the client-side SweetAlert confirm is informational only, backed by
     * data-company-count rendered server-side.
     */
    public function rescoreDispatch(Request $request, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        if (($guard = $this->batchActionGuard($batch)) !== null) {
            return $guard;
        }

        // TTL = job timeout (1800s, RescoreProspectBatchCompaniesJob::$timeout) + margin,
        // so a hard-killed worker can't leave the batch locked for an hour when the
        // job's own finally()/failed() never run.
        $key = RescoreProspectBatchCompaniesJob::admissionKey($batch->id);
        $lock = Cache::lock($key, 1900);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'error',
                'text' => 'Une relance de scoring IA est déjà en cours pour ce lot.',
            ], 409);
        }
        $owner = $lock->owner();

        $token = Str::random(40);
        $requestedBy = (int) $request->user()->id;
        Cache::put(RescoreProspectBatchCompaniesJob::cacheKeyFor($token), [
            'terminal' => false,
            'requested_by' => $requestedBy,
            'total' => 0,
            'rescored' => 0,
            'excluded' => 0,
            'error' => false,
        ], now()->addHour());

        try {
            RescoreProspectBatchCompaniesJob::dispatch($batch->id, $token, $requestedBy, $owner);
        } catch (\Throwable $e) {
            Cache::restoreLock($key, $owner)->release();

            return response()->json([
                'message' => 'error',
                'text' => 'Impossible de lancer le rescoring. Réessayez.',
            ], 500);
        }

        return response()->json([
            'message' => 'success',
            'text' => 'Relance du scoring IA lancée en arrière-plan.',
            'status_url' => route('admin.prospect_batches.rescore_status', $batch->id).'?token='.$token,
        ], 202);
    }

    /** Polled by the hero button's confirm-and-poll flow until the rescore job reports terminal. */
    public function rescoreStatus(Request $request, $id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);

        $token = (string) $request->query('token', '');
        $payload = $token !== '' ? Cache::get(RescoreProspectBatchCompaniesJob::cacheKeyFor($token)) : null;
        abort_if($payload === null, 404);

        return response()->json($payload);
    }

    /**
     * Shared 422 guard for both batch actions: both require the batch to be
     * linked to a criteria and to be past the draft/queued/running stages
     * (nothing to act on yet — see governing constraint in the plan).
     */
    private function batchActionGuard(ProspectBatch $batch): ?JsonResponse
    {
        if ($batch->prospect_criteria_id === null) {
            return response()->json([
                'message' => 'error',
                'text' => "Ce lot n'est lié à aucun critère — action indisponible.",
            ], 422);
        }
        if (in_array($batch->status, ['draft', 'queued', 'running'], true)) {
            return response()->json([
                'message' => 'error',
                'text' => "Cette action n'est pas disponible tant que le lot est en cours de traitement.",
            ], 422);
        }

        return null;
    }

    public function delete($id)
    {
        $batch = ProspectBatch::query()->findOrFail((int) $id);
        $this->authorizeBatch($batch);
        abort_unless(
            in_array($batch->status, ['draft', 'cancelled'], true),
            409,
            'Seuls les lots en brouillon ou annulés peuvent être supprimés.',
        );
        $batch->delete();

        return response()->json(['success' => true, 'message' => 'Lot supprimé.']);
    }

    private function authorizeBatch(ProspectBatch $batch): void
    {
        $user = request()->user();
        abort_unless($user !== null && (
            (int) $batch->created_by === (int) $user->getKey()
            || $user->hasRole(['admin', 'superadmin'])
        ), 403);
    }

    private function parserMessage(string $code): string
    {
        return match ($code) {
            'file_too_large', 'input_too_large' => 'Le fichier dépasse 10 Mo.',
            'too_many_rows' => 'La liste dépasse 10 000 entreprises.',
            'unsafe_file', 'unsafe_content' => 'Ce fichier ne peut pas être importé.',
            default => 'Ajoutez au moins une entreprise valide.',
        };
    }
}
