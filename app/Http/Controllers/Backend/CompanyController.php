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
use Carbon\Carbon;
use Illuminate\Http\Request;

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
        $this->middleware('permission:create companies')->only(['create', 'store']);
        $this->middleware('permission:edit companies')->only(['edit', 'update', 'executeSwitch', 'explainScore', 'restore']);
        $this->middleware('permission:delete companies')->only(['delete']);
        $this->middleware('permission:enrich companies')->only(['enrich']);

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
     * Chart-only colour overrides per contact status.
     * Badges keep their Bootstrap classes; this map only affects chart payloads
     * where 'secondary' grey is invisible and qualified/converted would collide.
     */
    private const STATUS_CHART_HEX = [
        'new' => '#A1A5B7', // visible grey (gray-500) instead of near-white secondary
        'converted' => '#00A3A3', // distinct teal — config 'success' would collide with qualified
    ];

    /**
     * Override the trait's view() to eager-load contacts and inject $stats.
     */
    public function view($id)
    {
        $model = $this->currentModel->withRejected()->with('contacts')->find($id);

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
        $contactsQualified = $company->contacts
            ->whereIn('status', ['qualified', 'converted'])
            ->count();

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
        $statusCounts = $company->contacts->countBy('status');
        $contactStatuses = config('global.data.contact_statuses', []);
        $donutSeries = [];
        $donutLabels = [];
        $donutColors = [];

        foreach ($contactStatuses as $key => $cfg) {
            $count = $statusCounts->get($key, 0);
            if ($count > 0) {
                $donutSeries[] = $count;
                $donutLabels[] = $cfg['label'];
                $donutColors[] = self::STATUS_CHART_HEX[$key] ?? (self::SEMANTIC_HEX[$cfg['color']] ?? '#E1E3EA');
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
            'contacts_qualified' => $contactsQualified,
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
        $model = $this->currentModel->withRejected()->with('contacts')->find($id);

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
        return [
            'relationships' => config('global.data.company_relationships', []),
            'sources' => config('global.data.company_sources', []),
            'qualificationStatuses' => config('global.data.company_qualification_statuses', []),
            'sizeBuckets' => config('global.data.company_size_buckets', []),
            'countries' => config('global.data.company_countries', []),
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
                'text' => 'Solde du jour épuisé — recharge demain à minuit.',
            ], 422);
        } catch (EnrichmentInFlightException $e) {
            return response()->json([
                'message' => 'error',
                'text' => 'Enrichissement déjà en cours pour cette entreprise.',
            ], 409);
        } catch (QuotaLockUnavailableException $e) {
            return response()->json([
                'message' => 'error',
                'text' => 'Système occupé — réessayez dans quelques secondes.',
            ], 409);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'error',
                'text' => "Erreur lors de l'enrichissement — réessayez.",
            ], 500);
        }

        if ($result['outcome'] === 'provider_failed') {
            return response()->json([
                'message' => 'success',
                'text' => 'Aucun contact trouvé pour ce domaine — 1 crédit consommé.',
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
}
