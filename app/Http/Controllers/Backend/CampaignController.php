<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\CampaignViewConfig;
use App\DataTables\Backend\CampaignsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SenderIdentity;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\SegmentService;
use App\Services\Demande\DemandeCaptureService;
use App\Services\Translation\LanguageResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * Toggleable boolean fields on Campaign.
     * is_active is allowed; sequence campaigns are protected by executeSwitch override.
     *
     * @var array<string>
     */
    protected $toggleableFields = ['is_active'];

    public function __construct(Request $request, Campaign $model, CampaignsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Wire ViewConfig — MUST be inside constructor body, never as a class property.
        // The Crudable trait declares $viewConfigClass = null; re-declaring it at class level
        // with a non-null default would be a PHP fatal (conflicting default).
        $this->viewConfigClass = CampaignViewConfig::class;

        $this->middleware('permission:view campaigns')->only(['index', 'view', 'segmentCount']);
        $this->middleware('permission:create campaigns')->only(['create', 'store']);
        $this->middleware('permission:edit campaigns')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete campaigns')->only(['delete']);
        $this->middleware('permission:send campaigns')->only(['schedule', 'sendNow']);
        $this->middleware('permission:create demandes')->only(['markReplied']);

        $this->listTitle = 'Campagnes';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       Campaign::class,
            modelName:        'campaigns',
            dataTableClass:   CampaignsDataTable::class,
            permissionEntity: 'campaigns',
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
            'backend.contents.campaigns.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * Override view() to render the campaign report.
     * Loads the campaign with all runs (no runs.recipients — stats use stats_* columns only).
     * Builds a paginated all-recipients query across all runs for the Destinataires tab.
     * Computes $stats and passes $viewConfig (with real KPI values + tab counts) to the view.
     *
     * @param int $id
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function view($id)
    {
        $campaign = Campaign::with([
            'segment',
            'template',
            'senderIdentity',
            'sequence',
            'runs' => function ($q) {
                $q->orderByDesc('run_at');
            },
        ])->find((int) $id);

        if ($campaign === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.campaigns.index'));
        }

        $latestRun = $campaign->runs->first();
        $stats     = $this->campaignStats($campaign);

        // Rollup / run-scope recipients — see recipientFilters() + recipientScopeQuery().
        $recipientFilters = $this->recipientFilters($campaign);
        $isRunScope       = $recipientFilters['run'] !== null;

        $scopeQuery = $this->recipientScopeQuery($campaign, $recipientFilters);
        $chipCounts = $this->recipientChipCounts($scopeQuery, $isRunScope);

        $pageQuery = clone $scopeQuery;
        $this->applyRecipientChipFilter($pageQuery, $recipientFilters['statut'], $isRunScope);

        $recipients = $pageQuery
            ->with('contact.company')
            ->orderByDesc('id')
            ->paginate(50, ['*'], 'recipients_page')
            ->appends(array_filter([
                'run_id' => $recipientFilters['run']?->id,
                'q'      => $recipientFilters['q'] !== '' ? $recipientFilters['q'] : null,
                'statut' => $recipientFilters['statut'],
            ]));

        // Tab badge = distinct contacts across all runs (structural, filter-independent).
        $recipientsTotal = CampaignRecipient::whereIn('campaign_run_id', $campaign->runs->pluck('id'))
            ->distinct()->count('contact_id');

        $viewConfig    = CampaignViewConfig::make($campaign, $stats, $recipientsTotal);
        $enrolledCount = SequenceEnrollment::where('campaign_id', $campaign->id)->count();

        return $this->getView('backend.contents.campaigns.crud.view')
            ->with('model', $campaign)
            ->with('latestRun', $latestRun)
            ->with('runs', $campaign->runs)
            ->with('stats', $stats)
            ->with('recipients', $recipients)
            ->with('recipientsTotal', $recipientsTotal)
            ->with('recipientFilters', $recipientFilters)
            ->with('chipCounts', $chipCounts)
            ->with('viewConfig', $viewConfig)
            ->with('enrolledCount', $enrolledCount);
    }

    /**
     * Compute aggregate KPI stats for a campaign from its runs (stats_* columns).
     * Uses only run-level stats columns — no recipient rows needed.
     *
     * @param Campaign $campaign  Must already have runs eager-loaded.
     * @return array
     */
    protected function campaignStats(Campaign $campaign): array
    {
        $runs = $campaign->runs ?? collect();

        $totalSent      = $runs->sum('stats_sent');
        $totalDelivered = $runs->sum('stats_delivered');
        $totalOpened    = $runs->sum('stats_opened');
        $totalClicked   = $runs->sum('stats_clicked');
        $totalReplied   = $runs->sum('stats_replied');
        $totalBounced   = $runs->sum('stats_bounced');
        $totalConversions = $runs->sum('conversion_count');

        $openRate = $totalDelivered > 0
            ? round(($totalOpened / $totalDelivered) * 100, 1)
            : 0;

        $clickRate = $totalDelivered > 0
            ? round(($totalClicked / $totalDelivered) * 100, 1)
            : 0;

        $conversionRate = $totalDelivered > 0
            ? round(($totalConversions / $totalDelivered) * 100, 1)
            : 0;

        // Opens over time: one data-point per run (ordered oldest-first)
        $runsAsc  = $runs->sortBy('run_at');
        $otLabels = $runsAsc->map(fn ($r) => $r->run_at ? $r->run_at->format('d/m') : '—')->values()->toArray();
        $otSeries = $runsAsc->map(fn ($r) => (int) ($r->stats_opened ?? 0))->values()->toArray();

        return [
            'total_sent'       => $totalSent,
            'total_delivered'  => $totalDelivered,
            'total_opened'     => $totalOpened,
            'total_clicked'    => $totalClicked,
            'total_replied'    => $totalReplied,
            'total_bounced'    => $totalBounced,
            'total_conversions'=> $totalConversions,
            'open_rate'        => $openRate,
            'click_rate'       => $clickRate,
            'conversion_rate'  => $conversionRate,
            'opens_over_time'  => [
                'series' => $otSeries,
                'labels' => $otLabels,
            ],
        ];
    }

    // ── Recipients helpers ─────────────────────────────────────────────────────

    /**
     * Parse recipient filter params from the request.
     * run  — CampaignRun|null (null = rollup scope)
     * q    — trimmed search string (max 100 chars)
     * statut — whitelisted status slug|null
     *
     * @param  Campaign $campaign  Must have runs eager-loaded.
     * @return array{run: \App\Models\CampaignRun|null, q: string, statut: string|null}
     */
    private function recipientFilters(Campaign $campaign): array
    {
        $runId = (int) request()->query('run_id', 0);
        $run   = $runId > 0 ? $campaign->runs->firstWhere('id', $runId) : null;

        $q = mb_substr(trim((string) request()->query('q', '')), 0, 100);

        $validStatuses = ['queued', 'sent', 'opened', 'clicked', 'replied', 'bounced', 'skipped'];
        $statut        = request()->query('statut');
        if (!in_array($statut, $validStatuses, true)) {
            $statut = null;
        }

        return compact('run', 'q', 'statut');
    }

    /**
     * Build the base scope query (rollup OR run-scope) with optional search applied.
     *
     * Rollup uses a window subquery aliased 'campaign_recipients' so that
     * whereHas('contact') correlates correctly via the real table name.
     *
     * CRITICAL window spec: only ROW_NUMBER carries ORDER BY id DESC.
     * Aggregate windows (COUNT/MAX) are PARTITION BY only — no ORDER BY.
     *
     * @param  Campaign $campaign
     * @param  array    $filters   From recipientFilters().
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function recipientScopeQuery(Campaign $campaign, array $filters)
    {
        $run = $filters['run'];
        $q   = $filters['q'];

        if ($run !== null) {
            // Run scope: raw rows for this specific execution.
            $query = CampaignRecipient::where('campaign_run_id', $run->id);
        } else {
            // Rollup scope: one row per contact = latest recipient row + per-contact aggregates.
            $runIds = $campaign->runs->pluck('id');

            $sub = CampaignRecipient::query()
                ->whereIn('campaign_run_id', $runIds)
                ->select('campaign_recipients.*')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY contact_id ORDER BY id DESC) AS rn')
                ->selectRaw('COUNT(*) OVER (PARTITION BY contact_id) AS envois')
                ->selectRaw('MAX(sent_at) OVER (PARTITION BY contact_id) AS max_sent_at')
                ->selectRaw('MAX(opened_at) OVER (PARTITION BY contact_id) AS max_opened_at')
                ->selectRaw('MAX(clicked_at) OVER (PARTITION BY contact_id) AS max_clicked_at')
                ->selectRaw("MAX(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) OVER (PARTITION BY contact_id) AS has_replied");

            $query = CampaignRecipient::query()
                ->fromSub($sub, 'campaign_recipients')
                ->where('rn', 1)
                ->withCasts([
                    'max_sent_at'    => 'datetime',
                    'max_opened_at'  => 'datetime',
                    'max_clicked_at' => 'datetime',
                    'envois'         => 'integer',
                    'has_replied'    => 'boolean',
                ]);
        }

        // Search: contact email OR company name (both scopes).
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $query->whereHas('contact', function ($cq) use ($like) {
                $cq->where('email', 'like', $like)
                   ->orWhereHas('company', function ($compQ) use ($like) {
                       $compQ->where('name', 'like', $like);
                   });
            });
        }

        return $query;
    }

    /**
     * Compute chip counts as a single aggregate query over the current scope+search.
     *
     * Returns array keys: total, queued, sent, opened, clicked, replied, bounced, skipped.
     * Column references differ between rollup (max_sent_at, has_replied) and run scope (sent_at, status).
     *
     * reorder() is mandatory before toBase() to avoid ONLY_FULL_GROUP_BY errors from
     * any ORDER BY on a non-aggregate column that may leak from the builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $scopeQuery
     * @param  bool $isRunScope
     * @return array<string, int>
     */
    private function recipientChipCounts($scopeQuery, bool $isRunScope): array
    {
        if ($isRunScope) {
            $row = (clone $scopeQuery)->reorder()->toBase()->selectRaw(
                "COUNT(*) AS total,
                 COALESCE(SUM(status = 'queued'), 0) AS queued,
                 COALESCE(SUM(sent_at IS NOT NULL), 0) AS sent,
                 COALESCE(SUM(opened_at IS NOT NULL), 0) AS opened,
                 COALESCE(SUM(clicked_at IS NOT NULL), 0) AS clicked,
                 COALESCE(SUM(status = 'replied'), 0) AS replied,
                 COALESCE(SUM(status = 'bounced'), 0) AS bounced,
                 COALESCE(SUM(status = 'skipped'), 0) AS skipped"
            )->first();
        } else {
            $row = (clone $scopeQuery)->reorder()->toBase()->selectRaw(
                "COUNT(*) AS total,
                 COALESCE(SUM(status = 'queued'), 0) AS queued,
                 COALESCE(SUM(max_sent_at IS NOT NULL), 0) AS sent,
                 COALESCE(SUM(max_opened_at IS NOT NULL), 0) AS opened,
                 COALESCE(SUM(max_clicked_at IS NOT NULL), 0) AS clicked,
                 COALESCE(SUM(has_replied = 1), 0) AS replied,
                 COALESCE(SUM(status = 'bounced'), 0) AS bounced,
                 COALESCE(SUM(status = 'skipped'), 0) AS skipped"
            )->first();
        }

        return array_map('intval', (array) $row);
    }

    /**
     * Apply a chip filter to a scope query in-place.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  string|null $chip     Whitelisted status slug from recipientFilters().
     * @param  bool $isRunScope
     * @return void
     */
    private function applyRecipientChipFilter($query, ?string $chip, bool $isRunScope): void
    {
        if ($chip === null) {
            return;
        }

        switch ($chip) {
            case 'queued':
            case 'bounced':
            case 'skipped':
                $query->where('status', $chip);
                break;

            case 'sent':
                $query->whereNotNull($isRunScope ? 'sent_at' : 'max_sent_at');
                break;

            case 'opened':
                $query->whereNotNull($isRunScope ? 'opened_at' : 'max_opened_at');
                break;

            case 'clicked':
                $query->whereNotNull($isRunScope ? 'clicked_at' : 'max_clicked_at');
                break;

            case 'replied':
                if ($isRunScope) {
                    $query->where('status', 'replied');
                } else {
                    $query->where('has_replied', 1);
                }
                break;
        }
    }

    /**
     * Provide select options to the create/edit form views.
     * Eager-loads sequence steps + template for W2 preview (embedded as JSON).
     */
    protected function getViewVars(): array
    {
        $sequences = Sequence::where('is_active', true)
            ->with(['steps' => fn ($q) => $q->with('template')->orderBy('step_no')])
            ->orderBy('name')
            ->get();

        return [
            'segments'             => Segment::orderBy('name')->get(),
            'templates'            => CampaignTemplate::orderBy('name')->get(),
            'senderIdentities'     => SenderIdentity::where('is_active', true)->orderBy('name')->get(),
            'scheduleTypes'        => config('global.data.schedule_types', []),
            'recurrenceFrequencies'=> config('global.data.recurrence_frequencies', []),
            'sequences'            => $sequences,
        ];
    }

    /**
     * Pre-process form inputs before save.
     * - If schedule_type=recurring: assemble recurrence JSON from flat inputs + set next_run_at.
     * - If schedule_type=one_shot: pass scheduled_at through as-is.
     *
     * @param int|null $id
     * @return array
     */
    protected function beforeSave($id = null): array
    {
        $attributes = $this->currentRequest->all();

        // NOTE: beforeSave() runs BEFORE model validation (Crudable trait behavior).
        // Defensive checks must not assume validated input.
        $scheduleType = $attributes['schedule_type'] ?? 'one_shot';

        if ($scheduleType === 'recurring') {
            $recurrence = [
                'frequency' => $attributes['recurrence_frequency'] ?? 'weekly',
                'interval'  => (int) ($attributes['recurrence_interval'] ?? 1),
            ];
            if (!empty($attributes['recurrence_until'])) {
                $recurrence['until'] = $attributes['recurrence_until'];
            }
            $attributes['recurrence'] = $recurrence;

            // next_run_at is submitted as a flat input — keep it in $attributes as-is
            // (the model/migration handles the datetime column)
        }

        // Remove flat recurring helper fields that are not model columns
        unset($attributes['recurrence_frequency'], $attributes['recurrence_interval'], $attributes['recurrence_until']);

        // For non-sequence schedule types, null out sequence_id — prevents stale
        // sequence associations from a previous edit that changed the schedule type.
        if ($scheduleType !== 'sequence') {
            $attributes['sequence_id'] = null;
        }

        // For sequence schedule type, null out template_id — sequence campaigns carry
        // templates per step, not at the campaign level. Mirrors the sequence_id null-out
        // above for symmetry; prevents a stale template_id from a prior one_shot edit.
        if ($scheduleType === 'sequence') {
            $attributes['template_id'] = null;
        }

        return $attributes;
    }

    /**
     * Return the estimated contact count for a segment (for live preview in the builder form).
     *
     * @param int $id  Segment ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function segmentCount($id)
    {
        $segment = Segment::find((int) $id);
        if (!$segment) {
            return response()->json(['count' => 0]);
        }

        try {
            $count = app(SegmentService::class)->previewCount($segment);
        } catch (\Throwable $e) {
            $count = 0;
        }

        return response()->json(['count' => $count]);
    }

    /**
     * Return the audience language split for a segment + optional template.
     *
     * POST /campaigns/audience-language-split
     * Body: segment_id (required), template_id (optional)
     *
     * Returns JSON:
     *   fr               int  — contacts resolved to French
     *   en               int  — contacts resolved to English
     *   unknown          int  — contacts with no country (mapped to FR at send time)
     *   total            int
     *   has_en           bool — template has an EN translation
     *   en_stale         bool — EN translation exists but is out of date
     *   warning          bool — en > 0 AND (no EN translation OR EN is stale)
     *   template_edit_url string|null — deep-link to Traductions tab
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function audienceLanguageSplit(Request $request)
    {
        abort_unless($request->user()->can('view campaigns'), 403);

        $validated = $request->validate([
            'segment_id'  => ['required', 'integer', 'exists:segments,id'],
            'template_id' => ['nullable', 'integer', 'exists:campaign_templates,id'],
        ]);

        $segment = Segment::findOrFail((int) $validated['segment_id']);

        // ── Bucket contacts by language ───────────────────────────────────────
        $fr      = 0;
        $en      = 0;
        $unknown = 0;

        try {
            $contacts  = app(SegmentService::class)->resolve($segment);
            $baseLang  = config('translation.base_language', 'fr');
            $resolver  = app(LanguageResolver::class);

            foreach ($contacts as $contact) {
                $country = $contact->company?->country;

                if (blank($country)) {
                    $unknown++;
                } elseif ($resolver->forCountry($country) === $baseLang) {
                    $fr++;
                } else {
                    $en++;
                }
            }
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => true,
                'message' => 'Impossible de résoudre le segment.',
            ], 200); // soft error — form stays usable
        }

        // ── Template EN status ────────────────────────────────────────────────
        $hasEn      = false;
        $enStale    = false;
        $editUrl    = null;
        $templateId = isset($validated['template_id']) ? (int) $validated['template_id'] : null;

        if ($templateId) {
            $tpl = CampaignTemplate::with('translations')->find($templateId);
            if ($tpl) {
                $tr      = $tpl->translationFor('en');
                $hasEn   = $tr !== null;
                $enStale = $hasEn && count($tpl->staleFieldsFor($tr)) > 0;
                $editUrl = route('admin.campaign_templates.edit', $tpl->id) . '#template_traductions';
            }
        }

        $warning = $en > 0 && (! $hasEn || $enStale);

        return response()->json([
            'fr'                => $fr,
            'en'                => $en,
            'unknown'           => $unknown,
            'total'             => $fr + $en + $unknown,
            'has_en'            => $hasEn,
            'en_stale'          => $enStale,
            'warning'           => $warning,
            'template_edit_url' => $editUrl,
        ]);
    }

    /**
     * Override the generic Datatableable::executeSwitch to guard sequence campaigns.
     *
     * Sequence campaigns must not be paused via is_active — pause logic for drip
     * sequences is controlled through sequence.is_active, not campaign.is_active.
     * Toggling campaign.is_active on a sequence would show a misleading pause badge
     * without actually stopping the drip engine.
     *
     * - is_active flip on a sequence campaign → 403 JSON with French message.
     * - All other field + campaign type combinations fall through to the Datatableable
     *   trait logic (replicated here since PHP traits do not support parent:: calls).
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function executeSwitch($id)
    {
        $request = $this->currentRequest->all();
        $field   = $request['field'] ?? '';

        if ($field === 'is_active') {
            $campaign = $this->currentModel->find($id);

            if ($campaign !== null && $campaign->schedule_type === 'sequence') {
                return response()->json([
                    'success' => false,
                    'msg'     => 'Une campagne séquence se met en pause via sa séquence.',
                ], 403);
            }

            // Guard: a recurring campaign whose recurrence has ended (next_run_at IS NULL)
            // cannot be re-activated via toggle — the scheduler would never fire.
            // The user must re-schedule to define a new next_run_at.
            if (
                $campaign !== null
                && $campaign->schedule_type === 'recurring'
                && (int) ($request['state'] ?? 0) === 1
                && $campaign->next_run_at === null
            ) {
                return response()->json([
                    'success' => false,
                    'msg'     => 'Cette campagne récurrente est terminée. Renseignez le champ Premier envoi avant de l’activer.',
                ], 422);
            }
        }

        // Delegate to Datatableable trait logic (trait methods cannot use parent::).
        $model = $this->currentModel->find($id);
        if ($model === null) {
            return response()->json(['success' => false, 'msg' => trans('app.not_found')]);
        }

        $allowedFields = $this->toggleableFields ?? [];
        if (! in_array($field, $allowedFields, true)) {
            return response()->json(['success' => false, 'msg' => trans('app.cannot_delete')], 403);
        }

        $state = (int) ($request['state'] ?? 0);
        $model->update([$field => $state]);

        return response()->json(['success' => true]);
    }

    /**
     * Schedule a campaign run.
     *
     * - sequence type  : rejected — must launch via sendNow().
     * - recurring type : activates the recurrence (sets is_active=true); does NOT
     *                    enqueue an immediate blast. next_run_at must already be set.
     * - one_shot type  : delegates to CampaignService::scheduleOneShot().
     *
     * Requires `send campaigns` permission (enforced via middleware).
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function schedule($id)
    {
        $campaign = Campaign::findOrFail((int) $id);

        // Defense in depth: sequence campaigns must never reach the one-shot scheduler.
        // The UI hides the Planifier button for sequence type; this guard is a server-side backstop.
        if ($campaign->schedule_type === 'sequence') {
            return response()->json([
                'message'  => 'error',
                'text'     => 'Une campagne séquence se lance via "Démarrer la séquence".',
                'redirect' => route('admin.campaigns.view', $id),
            ], 422);
        }

        // Recurring branch — activate the recurrence; do NOT create an immediate run.
        if ($campaign->schedule_type === 'recurring') {
            if ($campaign->next_run_at === null) {
                return response()->json([
                    'message' => 'Définissez la récurrence (date de début) avant de planifier.',
                ], 422);
            }

            $campaign->update(['is_active' => true]);

            $next = $campaign->next_run_at->copy()->setTimezone($campaign->timezone ?? 'UTC')->format('d/m/Y H:i');

            session()->flash('success', "Campagne récurrente planifiée — prochaine occurrence le {$next}.");

            return response()->json([
                'message'  => 'success',
                'text'     => "Campagne récurrente planifiée — prochaine occurrence le {$next}.",
                'redirect' => route('admin.campaigns.view', $id),
            ]);
        }

        // One-shot branch (default).
        app(CampaignService::class)->scheduleOneShot($campaign);

        session()->flash('success', 'Campagne planifiée avec succès.');

        return response()->json([
            'message'  => 'success',
            'text'     => 'Campagne planifiée avec succès.',
            'redirect' => route('admin.campaigns.view', $id),
        ]);
    }

    /**
     * Schedule + immediately dispatch a send job for the given campaign.
     * For sequence-type campaigns: enroll the segment contacts into the sequence.
     * Requires `send campaigns` permission (enforced via middleware).
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendNow($id)
    {
        $campaign = Campaign::with('sequence')->findOrFail((int) $id);

        // ── Sequence-type branch ───────────────────────────────────────────────
        if ($campaign->schedule_type === 'sequence') {
            try {
                $result   = app(CampaignService::class)->launchSequence($campaign);
                $enrolled = $result['enrolled'];
                $skipped  = $result['skipped'];

                if ($enrolled === 0 && $skipped === 0) {
                    // Empty segment
                    session()->flash('warning', 'Aucun contact éligible dans ce segment.');
                } elseif ($enrolled === 0) {
                    // All contacts already enrolled
                    session()->flash('warning', "Aucun contact éligible. ({$skipped} déjà suivis ignorés)");
                } else {
                    session()->flash('success', "Séquence démarrée — {$enrolled} contact(s) ajouté(s) ({$skipped} déjà suivis).");
                }

                return response()->json([
                    'message'  => 'success',
                    'text'     => "Séquence démarrée — {$enrolled} contact(s) ajouté(s) ({$skipped} déjà suivis).",
                    'redirect' => route('admin.campaigns.view', $id),
                ]);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'message'  => 'error',
                    'text'     => $e->getMessage(),
                    'redirect' => route('admin.campaigns.view', $id),
                ], 422);
            }
        }

        // ── One-shot / recurring branch (unchanged) ───────────────────────────
        $run = app(CampaignService::class)->scheduleOneShot($campaign);

        SendCampaignJob::dispatch($run->id);

        session()->flash('success', "Envoi lancé — la campagne est en file d'attente.");

        return response()->json([
            'message'  => 'success',
            'text'     => "Envoi lancé — la campagne est en file d'attente.",
            'redirect' => route('admin.campaigns.view', $id),
        ]);
    }

    /**
     * Mark a campaign recipient as replied and capture a Demande.
     * Requires `create demandes` permission (enforced via middleware).
     *
     * POST /campaigns/{id}/recipients/{recipientId}/replied
     *
     * @param int $id          Campaign ID
     * @param int $recipientId CampaignRecipient ID
     * @return \Illuminate\Http\RedirectResponse
     */
    public function markReplied($id, $recipientId)
    {
        $campaign  = Campaign::findOrFail((int) $id);
        $recipient = CampaignRecipient::whereHas('run', function ($q) use ($campaign) {
            $q->where('campaign_id', $campaign->id);
        })->findOrFail((int) $recipientId);

        // Idempotency guard — early-return if this recipient OR any other recipient
        // of the same campaign + same contact already has status 'replied'.
        $alreadyReplied = $recipient->status === 'replied'
            || CampaignRecipient::where('contact_id', $recipient->contact_id)
                ->whereHas('run', function ($q) use ($campaign) {
                    $q->where('campaign_id', $campaign->id);
                })
                ->where('status', 'replied')
                ->exists();

        if ($alreadyReplied) {
            session()->flash('info', 'Ce contact a déjà été marqué comme répondu pour cette campagne.');

            return redirect()->route('admin.campaigns.view', $campaign->id)
                ->withFragment('campaign_destinataires');
        }

        return DB::transaction(function () use ($campaign, $recipient) {
            // Mark the recipient as replied
            $recipient->update([
                'status'     => 'replied',
                'replied_at' => now(),
            ]);

            // Capture the Demande
            if ($recipient->contact) {
                app(DemandeCaptureService::class)->capture(
                    $recipient->contact,
                    [
                        'campaign_id'     => $campaign->id,
                        'campaign_run_id' => $recipient->campaign_run_id,
                    ],
                    'reply',
                    null
                );
            }

            session()->flash('success', 'Destinataire marqué comme répondu. Une demande a été créée.');

            return redirect()->route('admin.campaigns.view', $campaign->id)
                ->withFragment('campaign_destinataires');
        });
    }
}
