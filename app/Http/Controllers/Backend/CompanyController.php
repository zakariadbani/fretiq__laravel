<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\CompaniesDataTable;
use App\Exceptions\EnrichmentInFlightException;
use App\Exceptions\InvalidEnrichmentDomainException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\CampaignRecipient;
use App\Models\Company;
use App\Models\Demande;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Services\Prospecting\HunterCsvImportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * Fields the generic executeSwitch() AJAX toggle is allowed to update.
     */
    protected array $toggleableFields = ['is_active'];

    public function __construct(Request $request, Company $model, CompaniesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Assigned at runtime to avoid a trait+class property default conflict.
        $this->viewConfigClass = \App\Crud\ViewConfigs\CompanyViewConfig::class;

        $this->middleware('permission:view companies')->only(['index', 'view']);
        $this->middleware('permission:create companies')->only(['create', 'store', 'importForm', 'importPreview', 'importStore']);
        $this->middleware('permission:edit companies')->only(['edit', 'update', 'executeSwitch', 'explainScore', 'restore', 'importPreview', 'importStore']);
        $this->middleware('permission:delete companies')->only(['delete']);
        $this->middleware('permission:enrich companies')->only(['enrich']);
        // Importer also creates/merges contacts — gate on the contacts entity too.
        $this->middleware('permission:create contacts')->only(['importForm', 'importPreview', 'importStore']);
        $this->middleware('permission:edit contacts')->only(['importPreview', 'importStore']);

        $this->listTitle = 'Entreprises';
        $this->title = 'name';

        $this->bootResource(new BackendResource(
            modelClass: Company::class,
            modelName: 'companies',
            dataTableClass: CompaniesDataTable::class,
            permissionEntity: 'companies',
            prefixName: 'admin',
            titleField: 'name',
        ));
    }

    // ── Metronic semantic colour → hex (used server-side to build chart payloads) ──

    private const SEMANTIC_HEX = [
        'primary' => '#009EF7',
        'secondary' => '#E1E3EA',
        'success' => '#50CD89',
        'info' => '#7239EA',
        'warning' => '#FFC700',
        'danger' => '#F1416C',
        'dark' => '#181C32',
    ];

    /**
     * Override the trait's view() to eager-load contacts and inject $stats.
     */
    public function view($id)
    {
        $model = $this->currentModel->withRejected()->with([
            'contacts' => fn ($query) => app(\App\Services\Prospecting\ContactLifecycleService::class)->select($query),
        ])->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));

            return redirect(route('admin.companies.index'));
        }

        return $this->getView('backend.contents.companies.crud.view')
            ->with('title', __('overview'))
            ->with('model', $model)
            ->with('stats', $this->companyStats($model));
    }

    /**
     * Build chart-ready analytics for a company.
     * Contacts are already eager-loaded on $company->contacts.
     * Only 2 aggregate DB queries beyond the eager-load.
     */
    private function companyStats(Company $company): array
    {
        // ── Contact IDs (from eager-loaded collection) ────────────────────────
        $contactIds = $company->contacts->pluck('id');

        // ── KPI scalars ───────────────────────────────────────────────────────
        $contactsTotal = $company->contacts->count();
        $contactsReplied = $company->contacts->where('lifecycle_state', 'replied')->count();

        // ── CampaignRecipient rows for this company's contacts (single fetch, filtered in PHP — swap to DB aggregates when Phase 3 volume arrives) ────
        $recipients = $contactIds->isNotEmpty()
            ? CampaignRecipient::whereIn('contact_id', $contactIds)->get()
            : collect();

        $emailsSent = $recipients->filter(fn ($r) => ! is_null($r->sent_at))->count();

        // Funnel — stages that have a *_at column: sent, opened, clicked, replied
        // "Délivrés" has no delivered_at column → status-based fallback
        $funnelSeries = [
            $emailsSent,
            $recipients->whereIn('status', ['delivered', 'opened', 'clicked', 'replied'])->count(),
            $recipients->filter(fn ($r) => ! is_null($r->opened_at))->count(),
            $recipients->filter(fn ($r) => ! is_null($r->clicked_at))->count(),
            $recipients->filter(fn ($r) => ! is_null($r->replied_at))->count(),
        ];

        // ── Demandes (single query) ───────────────────────────────────────────
        $demandesTotal = $contactIds->isNotEmpty()
            ? Demande::whereIn('contact_id', $contactIds)->count()
            : 0;

        // ── Contacts donut (config-ordered, zero-excluded) ────────────────────
        $statusCounts = $company->contacts->countBy('lifecycle_state');
        $contactStatuses = config('global.data.contact_lifecycle_states', []);
        $donutSeries = [];
        $donutLabels = [];
        $donutColors = [];

        foreach ($contactStatuses as $key => $cfg) {
            $count = $statusCounts->get($key, 0);
            if ($count > 0) {
                $donutSeries[] = $count;
                $donutLabels[] = $cfg['label'];
                $donutColors[] = self::SEMANTIC_HEX[$cfg['color']] ?? '#E1E3EA';
            }
        }

        // ── Contacts over time (last 12 months, zero-filled) ──────────────────
        $now = Carbon::now();
        $skeleton = [];  // 'Y-m' => 0

        for ($i = 11; $i >= 0; $i--) {
            $skeleton[$now->copy()->subMonths($i)->format('Y-m')] = 0;
        }

        $byMonth = $company->contacts
            ->filter(fn ($c) => $c->created_at && $c->created_at->gte($now->copy()->subMonths(11)->startOfMonth()))
            ->groupBy(fn ($c) => $c->created_at->format('Y-m'));

        foreach ($byMonth as $ym => $group) {
            if (array_key_exists($ym, $skeleton)) {
                $skeleton[$ym] = $group->count();
            }
        }

        $otLabels = [];
        foreach (array_keys($skeleton) as $ym) {
            [$y, $m] = explode('-', $ym);
            $otLabels[] = Carbon::createFromDate((int) $y, (int) $m, 1)->locale('fr')->translatedFormat('M Y');
        }

        return [
            'contacts_total' => $contactsTotal,
            'contacts_replied' => $contactsReplied,
            'emails_sent' => $emailsSent,
            'demandes_total' => $demandesTotal,
            'ai_score' => $company->ai_score,
            'contacts_donut' => [
                'series' => $donutSeries,
                'labels' => $donutLabels,
                'colors' => $donutColors,
            ],
            'funnel' => [
                'labels' => ['Envoyés', 'Délivrés', 'Ouverts', 'Cliqués', 'Répondus'],
                'series' => $funnelSeries,
            ],
            'contacts_over_time' => [
                'labels' => $otLabels,
                'series' => array_values($skeleton),
            ],
        ];
    }

    /**
     * Override the trait's edit() — the edit page renders the Activité tab,
     * whose acquisition chart needs the same $stats payload as view().
     */
    public function edit($id)
    {
        $model = $this->currentModel->withRejected()->with([
            'contacts' => fn ($query) => app(\App\Services\Prospecting\ContactLifecycleService::class)->select($query),
        ])->find($id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));

            return redirect(route('admin.companies.index'));
        }

        if ($model->qualification_status === 'rejected') {
            session()->flash('warning', 'Cette entreprise est archivée. Restaurez-la avant de la modifier.');

            return redirect(route('admin.companies.view', $model->id));
        }

        $view = $this->getView('backend.contents.companies.crud.form');

        return $view
            ->with('title', trans('app.edit companies', ['name' => $model->{isset($this->title) ? $this->title : 'id'}]))
            ->with('route', route('admin.companies.update', $id))
            ->with('method', 'post')
            ->with('page', 'edit')
            ->with('model', $model)
            ->with('stats', $this->companyStats($model));
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        $this->currentRequest = request();
        $this->currentDataTable = app(CompaniesDataTable::class);

        if ($this->currentRequest->ajax() && $this->currentRequest->wantsJson()) {
            return $this->currentDataTable->ajax();
        }

        $isArchive = $this->currentRequest->routeIs('admin.companies.archive');

        return $this->currentDataTable->render(
            'backend.contents.companies.crud.index',
            [
                'listTitle' => $isArchive ? 'Rejetées / Archives' : $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
                'isArchive' => $isArchive,
                'rejectedCount' => Company::rejected()->count(),
            ]
        );
    }

    public function restore($id)
    {
        $model = Company::rejected()->find($id);

        abort_if($model === null, 404);

        $model->update(['qualification_status' => 'pending']);

        return redirect(route('admin.companies.view', $model->id))
            ->with('success', 'Entreprise restaurée dans la liste active.');
    }

    protected function afterSave(array $attributes, $model)
    {
        if ($model instanceof Company && $model->qualification_status === 'rejected') {
            return response()->json([
                'message' => 'success',
                'model' => $model,
                'redirect' => route('admin.companies.archive'),
            ]);
        }
    }

    /**
     * Provide select options to the create/edit form views.
     */
    protected function getViewVars(): array
    {
        try {
            $sectors = \App\Models\Sector::where('is_active', true)
                ->orderBy('sort_order')->orderBy('label')
                ->pluck('label', 'label')->all();
        } catch (\Illuminate\Database\QueryException $e) {
            // Table not migrated yet — fall back to the config taxonomy so the form still renders.
            $sectors = array_combine(
                config('global.data.company_sectors', []),
                config('global.data.company_sectors', [])
            );
        }

        return [
            'relationships' => config('global.data.company_relationships', []),
            'sources' => config('global.data.company_sources', []),
            'qualificationStatuses' => config('global.data.company_qualification_statuses', []),
            'sizeBuckets' => config('global.data.company_size_buckets', []),
            'countries' => config('global.data.company_countries', []),
            'sectors' => $sectors,
        ];
    }

    /**
     * Manually enrich a company with Hunter contact data.
     *
     * Requires `enrich companies` permission (enforced via middleware).
     * Debits 1 credit before calling Hunter (debit-before-call semantics).
     * No path may leave the DiscoveryRun row in status='running'.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function enrich($id, CompanyEnrichmentService $service)
    {
        /** @var Company $company */
        $company = Company::findOrFail((int) $id);

        try {
            $result = $service->enrich($company);
        } catch (InvalidEnrichmentDomainException $e) {
            $text = $e->reason === InvalidEnrichmentDomainException::BLOCKED
                ? "Ce domaine appartient \u{00E0} un r\u{00E9}seau social \u{2014} renseignez le domaine du site de l'entreprise."
                : "Cette entreprise n'a pas de domaine — enrichissement impossible.";

            return response()->json(['message' => 'error', 'text' => $text], 422);
        } catch (QuotaExhaustedException $e) {
            return response()->json([
                'message' => 'error',
                'text' => 'Quota contacts atteint.',
            ], 422);
        } catch (EnrichmentInFlightException $e) {
            return response()->json([
                'message' => 'error',
                'text' => 'Recherche de contacts en cours.',
            ], 409);
        } catch (QuotaLockUnavailableException $e) {
            return response()->json([
                'message' => 'error',
                'text' => 'Système occupé — réessayez dans quelques secondes.',
            ], 409);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'error',
                'text' => "Échec de la recherche de contacts.",
            ], 500);
        }

        if ($result['outcome'] === 'provider_failed') {
            return response()->json([
                'message' => 'success',
                'text' => 'Recherche de contacts indisponible — 1 crédit consommé.',
            ], 200);
        }

        $count = $result['contacts_count'];

        return response()->json([
            'message' => 'success',
            'text' => "{$count} contact(s) récupéré(s) — 1 crédit contact consommé.",
            'contacts_count' => $count,
        ], 200);
    }

    /**
     * Generate an AI narrative explaining the company's current ai_score.
     *
     * Requires `edit companies` permission (enforced via middleware).
     * Writes ai_explanation ONLY — never touches ai_score.
     */
    public function explainScore($id, \App\Services\Scoring\ScoreExplanationService $svc): \Illuminate\Http\JsonResponse
    {
        $company = Company::findOrFail((int) $id);

        if ($company->ai_score === null) {
            return response()->json(['message' => 'error', 'text' => 'Aucun score IA à expliquer.'], 422);
        }

        $text = $svc->explain($company);

        Company::whereKey($company->id)->update(['ai_explanation' => $text]);

        return response()->json(['message' => 'success', 'text' => 'Récapitulatif IA généré.', 'explanation' => $text], 200);
    }

    // ── CSV import (companies + contacts) ─────────────────────────────────────
    // Two optional file inputs (companies CSV, contacts CSV — at least one
    // required). Preview payload is cache-backed under hunter_import:{uuid},
    // bound to the requesting user, 30-minute TTL — NOT HandlesImportPreviewToken
    // (rows can run into the hundreds of KB, too large for a signed hidden field).

    private const IMPORT_CACHE_PREFIX = 'hunter_import:';

    private const IMPORT_CACHE_MINUTES = 30;

    /** Row numbers capped per error code so the preview list stays bounded. */
    private const IMPORT_ERROR_ROWS_SHOWN = 20;

    /** @var array<string,string> */
    private const IMPORT_ERROR_LABELS = [
        'file_too_large' => 'Fichier trop volumineux',
        'unsafe_file' => 'Fichier non autorisé',
        'file_unreadable' => 'Fichier illisible',
        'unsafe_content' => 'Contenu non autorisé',
        'header_required' => 'Colonnes attendues introuvables dans l’en-tête',
        'too_many_rows' => 'Trop de lignes dans le fichier',
        'company_name_required' => 'Nom de société manquant',
        'company_name_too_long' => 'Nom de société trop long',
        'domain_missing' => 'Domaine manquant',
        'domain_invalid' => 'Domaine invalide',
        'platform_domain' => 'Domaine de plateforme (réseau social, annuaire…)',
        'country_invalid' => 'Pays non reconnu',
        'email_required' => 'Adresse e-mail manquante',
    ];

    public function importForm()
    {
        return view('backend.contents.companies.crud.import', [
            'summary' => null,
            'parseErrors' => [],
            'uuid' => null,
        ]);
    }

    public function importPreview(Request $request, HunterCsvImportService $importer)
    {
        $request->validate([
            'companies_csv' => ['nullable', 'file', 'max:10240', 'mimes:csv,txt'],
            'contacts_csv' => ['nullable', 'file', 'max:10240', 'mimes:csv,txt'],
        ]);

        $companiesFile = $request->file('companies_csv');
        $contactsFile = $request->file('contacts_csv');

        if ($companiesFile === null && $contactsFile === null) {
            throw ValidationException::withMessages(['companies_csv' => ['Sélectionnez au moins un fichier.']]);
        }

        $companyParse = $companiesFile !== null ? $importer->parseCompanies($companiesFile) : ['rows' => [], 'errors' => []];
        $leadParse = $contactsFile !== null ? $importer->parseLeads($contactsFile) : ['rows' => [], 'errors' => []];

        $summary = $importer->preview($companyParse['rows'], $leadParse['rows']);

        $uuid = (string) Str::uuid();
        Cache::put(self::IMPORT_CACHE_PREFIX.$uuid, [
            'user_id' => $request->user()->id,
            'company_rows' => $companyParse['rows'],
            'lead_rows' => $leadParse['rows'],
        ], now()->addMinutes(self::IMPORT_CACHE_MINUTES));

        return view('backend.contents.companies.crud.import', [
            'summary' => $summary,
            'parseErrors' => $this->aggregateImportErrors([...$companyParse['errors'], ...$leadParse['errors']]),
            'uuid' => $uuid,
        ]);
    }

    public function importStore(Request $request, HunterCsvImportService $importer)
    {
        $uuid = (string) $request->input('import_uuid');
        $payload = $uuid !== '' ? Cache::get(self::IMPORT_CACHE_PREFIX.$uuid) : null;

        if ($payload === null || (int) $payload['user_id'] !== (int) $request->user()->id) {
            return redirect()->route('admin.companies.import_form')
                ->withErrors(['import_uuid' => ["Aperçu expiré — veuillez réimporter le fichier."]]);
        }

        // Sync import measured ~4.6s for 108+1607 rows on dev; PHP's default
        // max_execution_time (30s/60s) can cut off a larger file mid-commit.
        set_time_limit(0);

        // Forget only after a successful commit — if commit() throws mid-run,
        // the cached payload survives so a resubmit can pick up where it left
        // off (commit is idempotent — a double-submit is a safe no-op).
        $result = $importer->commit($payload['company_rows'], $payload['lead_rows']);

        Cache::forget(self::IMPORT_CACHE_PREFIX.$uuid);

        $companies = $result['companies_created'] + $result['companies_merged'];
        $contacts = $result['contacts_created'] + $result['contacts_updated'];

        return redirect()->route('admin.companies.index')->with(
            'success',
            "Import terminé : {$companies} entreprise(s) traitée(s) ({$result['stub_companies_created']} créée(s) automatiquement), {$contacts} contact(s) traité(s)."
        );
    }

    /**
     * Aggregate raw parse errors by code — a bare code-per-row list would be
     * both unrenderable ({{ $error }} on an array) and unbounded (country
     * bugs alone produced 100+ rows). Caps row numbers shown per code.
     *
     * @param  list<array{row_number:?int, code:string}>  $errors
     * @return list<array{code:string, label:string, count:int, rows:list<int>, more:int}>
     */
    private function aggregateImportErrors(array $errors): array
    {
        $byCode = [];
        foreach ($errors as $error) {
            $code = (string) $error['code'];
            $byCode[$code]['count'] = ($byCode[$code]['count'] ?? 0) + 1;
            if ($error['row_number'] !== null) {
                $byCode[$code]['rows'][] = (int) $error['row_number'];
            }
        }

        $aggregated = [];
        foreach ($byCode as $code => $data) {
            $rows = $data['rows'] ?? [];
            $aggregated[] = [
                'code' => $code,
                'label' => self::IMPORT_ERROR_LABELS[$code] ?? $code,
                'count' => $data['count'],
                'rows' => array_slice($rows, 0, self::IMPORT_ERROR_ROWS_SHOWN),
                'more' => max(0, count($rows) - self::IMPORT_ERROR_ROWS_SHOWN),
            ];
        }

        return $aggregated;
    }
}
