<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\CampaignViewConfig;
use App\DataTables\Backend\CampaignsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignCompanyDispatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\CampaignWaveZohoListSyncService;
use App\Services\Campaign\CampaignZohoListSyncService;
use App\Services\Campaign\PacedCampaignBatchService;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use App\Services\Campaign\SegmentService;
use App\Services\Demande\DemandeCaptureService;
use App\Services\Translation\LanguageResolver;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $this->middleware('permission:send campaigns')->only(['dispatchPreview', 'schedule', 'sendNow', 'sequenceAutoEnroll', 'syncZohoList']);
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
                'schedulerHealth' => $this->schedulerHealth(),
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
                $q->withCount('companyDispatches')->orderByDesc('run_at');
            },
        ])->find((int) $id);

        if ($campaign === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.campaigns.index'));
        }

        $executedRuns = $campaign->runs
            ->filter(fn (CampaignRun $run) => $run->isExecuted())
            ->values();
        $executedRunIds = $executedRuns->pluck('id');

        $latestRun = $executedRuns->first();
        $stats     = $this->campaignStats($executedRuns);
        $currentAudience = $campaign->segment
            ? app(SegmentService::class)->resolve($campaign->segment)
            : collect();

        $pacedProgress = null;
        if ($campaign->schedule_type === 'paced') {
            $knownCompanyIds = $campaign->companyDispatches()
                ->pluck('company_id')
                ->mapWithKeys(fn ($companyId) => [(int) $companyId => true])
                ->all();
            $backlog = $currentAudience
                ->reject(fn ($contact) => isset($knownCompanyIds[(int) $contact->company_id]));
            $statusCounts = $campaign->companyDispatches()
                ->selectRaw("SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) AS processed_count")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count")
                ->first();
            $lastBatch = $campaign->runs
                ->first(fn (CampaignRun $run) => str_starts_with($run->occurrence_key, 'paced-'));

            $pacedProgress = [
                'processed' => (int) ($statusCounts?->processed_count ?? 0),
                'failed' => (int) ($statusCounts?->failed_count ?? 0),
                'backlog_companies' => $backlog->pluck('company_id')->filter()->unique()->count(),
                'backlog_contacts' => $backlog->count(),
                'last_batch' => $lastBatch,
                'last_batch_companies' => (int) ($lastBatch?->company_dispatches_count ?? 0),
            ];
        }

        // Rollup / run-scope recipients — see recipientFilters() + recipientScopeQuery().
        $recipientFilters = $this->recipientFilters($executedRuns);
        $isRunScope       = $recipientFilters['run'] !== null;

        $scopeQuery = $this->recipientScopeQuery($executedRunIds, $recipientFilters);
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
        $recipientsTotal = CampaignRecipient::whereIn('campaign_run_id', $executedRunIds)
            ->distinct()->count('contact_id');

        $viewConfig    = CampaignViewConfig::make($campaign, $stats, $recipientsTotal, $currentAudience->count(), $executedRuns->count());
        $waveData      = $this->campaignWaveData($campaign);
        $enrolledCount = SequenceEnrollment::where('campaign_id', $campaign->id)->count();
        $enrolledCompanyCount = SequenceEnrollment::query()
            ->where('sequence_enrollments.campaign_id', $campaign->id)
            ->join('contacts', 'contacts.id', '=', 'sequence_enrollments.contact_id')
            ->distinct()
            ->count('contacts.company_id');

        return $this->getView('backend.contents.campaigns.crud.view')
            ->with('model', $campaign)
            ->with('latestRun', $latestRun)
            ->with('runs', $executedRuns)
            ->with('stats', $stats)
            ->with('currentAudience', $currentAudience)
            ->with('recipients', $recipients)
            ->with('recipientsTotal', $recipientsTotal)
            ->with('recipientFilters', $recipientFilters)
            ->with('chipCounts', $chipCounts)
            ->with('viewConfig', $viewConfig)
            ->with('enrolledCount', $enrolledCount)
            ->with('enrolledCompanyCount', $enrolledCompanyCount)
            ->with('pacedProgress', $pacedProgress)
            ->with('waves', $waveData['waves'])
            ->with('selectedWave', $waveData['selectedWave'])
            ->with('selectedWaveRecipients', $waveData['recipients'])
            ->with('waveEnrollments', $waveData['enrollments'])
            ->with('legacyWaves', $waveData['legacy'])
            ->with('schedulerHealth', $this->schedulerHealth());
    }

    /** Campaign-specific paced-sequence wave summaries and selected membership. */
    private function campaignWaveData(Campaign $campaign): array
    {
        $empty = ['waves' => collect(), 'selectedWave' => null, 'recipients' => collect(), 'enrollments' => collect(), 'legacy' => collect()];
        if ($campaign->schedule_type !== 'sequence' || $campaign->sequence_enrollment_mode !== 'paced') {
            return $empty;
        }

        $waveRuns = $campaign->runs
            ->filter(fn (CampaignRun $run) => preg_match('/^sequence-wave-\d{6}$/', $run->occurrence_key) === 1)
            ->sortByDesc(fn (CampaignRun $run) => (int) substr($run->occurrence_key, strlen('sequence-wave-')))
            ->values();
        $waveRuns->each->loadMissing('recipients.contact.company');

        $selectedId = (int) request()->query('wave_id', 0);
        $selectedWave = $waveRuns->firstWhere('id', $selectedId) ?? $waveRuns->first();
        $selectedRecipients = $selectedWave?->recipients ?? collect();
        $selectedContactIds = $selectedRecipients->pluck('contact_id');
        $enrollments = $selectedContactIds->isEmpty()
            ? collect()
            : SequenceEnrollment::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('contact_id', $selectedContactIds)
                ->with(['contact.company', 'stepSends' => fn ($query) => $query->orderBy('step_no')])
                ->get()
                ->keyBy('contact_id');

        $listService = app(CampaignWaveZohoListSyncService::class);
        $waves = $waveRuns->map(function (CampaignRun $run) use ($listService): array {
            $number = (int) substr($run->occurrence_key, strlen('sequence-wave-'));
            $companyCount = $run->recipients->pluck('contact.company_id')->filter()->unique()->count();

            return [
                'run' => $run,
                'number' => $number,
                'list_name' => $listService->listName($run),
                'contacts' => $run->recipients->count(),
                'companies' => $companyCount,
            ];
        });

        $snapshottedContactIds = $waveRuns->flatMap(fn (CampaignRun $run) => $run->recipients->pluck('contact_id'))->unique();
        $legacyQuery = SequenceEnrollment::query()
            ->where('campaign_id', $campaign->id)
            ->with(['contact.company', 'stepSends' => fn ($query) => $query->orderBy('step_no')]);
        if ($snapshottedContactIds->isNotEmpty()) {
            $legacyQuery->whereNotIn('contact_id', $snapshottedContactIds);
        }
        $timezone = $campaign->scheduleTimezone();
        $legacy = $legacyQuery->get()
            ->groupBy(fn (SequenceEnrollment $enrollment) => $enrollment->created_at->copy()->setTimezone($timezone)->toDateString())
            ->sortKeysDesc();

        return ['waves' => $waves, 'selectedWave' => $selectedWave, 'recipients' => $selectedRecipients, 'enrollments' => $enrollments, 'legacy' => $legacy];
    }
    private function schedulerHealth(): array
    {
        $requiredCommands = [
            'generate_runs' => [
                'label' => 'campaigns:generate-runs',
                'key' => 'campaign_scheduler.commands.generate_runs.last_success_at',
            ],
            'dispatch_due' => [
                'label' => 'campaigns:dispatch-due',
                'key' => 'campaign_scheduler.commands.dispatch_due.last_success_at',
            ],
        ];

        $commands = [];

        foreach ($requiredCommands as $name => $command) {
            $value = Setting::get($command['key']);
            $lastSuccessAt = null;

            if (is_string($value) && trim($value) !== '') {
                try {
                    $lastSuccessAt = Carbon::parse($value, 'UTC')->utc();
                } catch (\Throwable) {
                    $lastSuccessAt = null;
                }
            }

            $commands[$name] = [
                'label' => $command['label'],
                'status' => $lastSuccessAt === null
                    ? 'missing'
                    : ($lastSuccessAt->lt(Carbon::now('UTC')->subMinutes(2)) ? 'stale' : 'healthy'),
                'last_success_at' => $lastSuccessAt,
            ];
        }

        $statuses = collect($commands)->pluck('status');

        return [
            'status' => $statuses->contains('missing')
                ? 'missing'
                : ($statuses->contains('stale') ? 'stale' : 'healthy'),
            'commands' => $commands,
        ];
    }

    /**
     * Compute aggregate KPI stats from executed runs.
     * Uses only run-level stats columns — no recipient rows needed.
     *
     * @param iterable<CampaignRun> $runs
     * @return array
     */
    protected function campaignStats(iterable $runs): array
    {
        $runs = collect($runs);

        // Opens over time: one data-point per run (ordered oldest-first)
        $runsAsc  = $runs->sortBy('run_at');
        $otLabels = $runsAsc->map(fn ($r) => $r->run_at ? $r->run_at->format('d/m') : '—')->values()->toArray();
        $otSeries = $runsAsc->map(fn (CampaignRun $run) => $run->kpis()['opened'])->values()->toArray();

        return array_merge(CampaignRun::aggregateKpis($runs), [
            'opens_over_time'  => [
                'series' => $otSeries,
                'labels' => $otLabels,
            ],
        ]);
    }

    // ── Recipients helpers ─────────────────────────────────────────────────────

    /**
     * Parse recipient filter params from the request.
     * run  — CampaignRun|null (null = rollup scope)
     * q    — trimmed search string (max 100 chars)
     * statut — whitelisted status slug|null
     *
     * @param  \Illuminate\Support\Collection<int, CampaignRun> $executedRuns
     * @return array{run: \App\Models\CampaignRun|null, q: string, statut: string|null}
     */
    private function recipientFilters($executedRuns): array
    {
        $runId = (int) request()->query('run_id', 0);
        $run   = $runId > 0 ? $executedRuns->firstWhere('id', $runId) : null;

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
     * Rollup uses a MySQL 5.7-compatible grouped aggregate subquery. Its
     * MAX(id) identifies the latest recipient row to expose for each contact,
     * while the other aggregates retain history across all executed runs.
     *
     * @param  \Illuminate\Support\Collection<int, int> $runIds
     * @param  array    $filters   From recipientFilters().
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function recipientScopeQuery($runIds, array $filters)
    {
        $run = $filters['run'];
        $q   = $filters['q'];

        if ($run !== null) {
            // Run scope: raw rows for this specific execution.
            $query = CampaignRecipient::where('campaign_run_id', $run->id);
        } else {
            // Rollup scope: one row per contact = latest recipient row + per-contact aggregates.
            $rollup = CampaignRecipient::query()
                ->whereIn('campaign_run_id', $runIds)
                ->select('contact_id')
                ->selectRaw('MAX(id) AS latest_id')
                ->selectRaw('COUNT(*) AS envois')
                ->selectRaw('MAX(sent_at) AS max_sent_at')
                ->selectRaw('MAX(opened_at) AS max_opened_at')
                ->selectRaw('MAX(clicked_at) AS max_clicked_at')
                ->selectRaw("MAX(CASE WHEN status = 'replied' THEN 1 ELSE 0 END) AS has_replied")
                ->groupBy('contact_id');

            $query = CampaignRecipient::query()
                ->select([
                    'campaign_recipients.*',
                    'recipient_rollup.envois',
                    'recipient_rollup.max_sent_at',
                    'recipient_rollup.max_opened_at',
                    'recipient_rollup.max_clicked_at',
                    'recipient_rollup.has_replied',
                ])
                ->joinSub($rollup, 'recipient_rollup', function ($join) {
                    $join->on('campaign_recipients.id', '=', 'recipient_rollup.latest_id');
                })
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
        $aggregateQuery = (clone $scopeQuery)
            ->reorder()
            ->toBase()
            ->select([]);

        if ($isRunScope) {
            $row = $aggregateQuery->selectRaw(
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
            $row = $aggregateQuery->selectRaw(
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
        $selectedSegment = $this->resolveSourceModel('segment_id', Segment::class, 'view segments');
        $selectedTemplate = $this->resolveSourceModel('template_id', CampaignTemplate::class, 'view campaign_templates');
        $selectedCompany = $this->resolveSourceModel('company_id', Company::class, 'view companies');

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
            'selectedSegment'       => $selectedSegment,
            'selectedTemplate'      => $selectedTemplate,
            'selectedCompany'       => $selectedCompany,
        ];
    }

    private function resolveSourceModel(string $queryKey, string $modelClass, string $permission): ?object
    {
        if (! $this->currentRequest->user()?->can($permission)) {
            return null;
        }

        $id = $this->currentRequest->query($queryKey);
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        return $modelClass::find((int) $id);
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
        $currentCampaign = $id !== null ? Campaign::find((int) $id) : null;

        // This operational flag is controlled exclusively by send-campaigns
        // endpoints. Ordinary create/edit payloads must never toggle it.
        unset($attributes['sequence_auto_enroll_enabled']);

        // NOTE: beforeSave() runs BEFORE model validation (Crudable trait behavior).
        // Defensive checks must not assume validated input.
        $scheduleType = $attributes['schedule_type'] ?? 'one_shot';
        $sequenceMode = $scheduleType === 'sequence'
            ? ($attributes['sequence_enrollment_mode'] ?? 'immediate')
            : 'immediate';
        $attributes['sequence_enrollment_mode'] = $sequenceMode;

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

        if ($scheduleType === 'paced') {
            $attributes['next_run_at'] = $attributes['paced_first_send_at'] ?? null;
            $dailyLimit = $attributes['daily_company_limit'] ?? null;
            $attributes['daily_company_limit'] = $dailyLimit === null || (is_string($dailyLimit) && trim($dailyLimit) === '')
                ? 20
                : $dailyLimit;
            $attributes['scheduled_at'] = null;
            $attributes['recurrence'] = null;
        } elseif ($scheduleType === 'sequence' && $sequenceMode === 'paced') {
            $attributes['next_run_at'] = $attributes['sequence_first_batch_at'] ?? null;
            $dailyLimit = $attributes['sequence_daily_company_limit'] ?? null;
            $attributes['daily_company_limit'] = $dailyLimit;
            $attributes['scheduled_at'] = null;
            $attributes['recurrence'] = null;
        } elseif ($scheduleType === 'one_shot') {
            $attributes['daily_company_limit'] = null;
            $attributes['next_run_at'] = null;
            $attributes['recurrence'] = null;
        } elseif ($scheduleType === 'recurring') {
            $attributes['daily_company_limit'] = null;
            $attributes['scheduled_at'] = null;
        } elseif ($scheduleType === 'sequence') {
            $attributes['daily_company_limit'] = null;
            $attributes['scheduled_at'] = null;
            $attributes['next_run_at'] = null;
            $attributes['recurrence'] = null;
        } else {
            $attributes['daily_company_limit'] = null;
            $attributes['scheduled_at'] = null;
            $attributes['next_run_at'] = null;
            $attributes['recurrence'] = null;
        }

        // Remove flat recurring helper fields that are not model columns
        unset($attributes['recurrence_frequency'], $attributes['recurrence_interval'], $attributes['recurrence_until'], $attributes['paced_first_send_at'], $attributes['sequence_first_batch_at'], $attributes['sequence_daily_company_limit']);

        // For non-sequence schedule types, null out sequence_id — prevents stale
        // sequence associations from a previous edit that changed the schedule type.
        if ($scheduleType !== 'sequence') {
            $attributes['sequence_id'] = null;
            if ($currentCampaign?->sequence_auto_enroll_enabled) {
                $attributes['sequence_auto_enroll_enabled'] = false;
            }
        }

        // For sequence schedule type, null out template_id — sequence campaigns carry
        // templates per step, not at the campaign level. Mirrors the sequence_id null-out
        // above for symmetry; prevents a stale template_id from a prior one_shot edit.
        if ($scheduleType === 'sequence') {
            $attributes['template_id'] = null;

            if ($currentCampaign !== null) {
                $sequenceChanged = (int) $currentCampaign->sequence_id !== (int) ($attributes['sequence_id'] ?? 0);
                $modeChanged = $currentCampaign->sequence_enrollment_mode !== $sequenceMode;
                if ($sequenceChanged || $modeChanged) {
                    $attributes['sequence_auto_enroll_enabled'] = false;
                }
            }
        }

        $timezone = $attributes['timezone'] ?? 'Europe/Paris';
        if ($timezone === '' && ! ($scheduleType === 'sequence' && $sequenceMode === 'paced')) {
            $timezone = 'Europe/Paris';
        }

        try {
            $timezone = (new DateTimeZone($timezone))->getName();
        } catch (\Throwable) {
            return $attributes;
        }

        foreach (['scheduled_at', 'next_run_at'] as $field) {
            if (empty($attributes[$field])) {
                continue;
            }

            try {
                $attributes[$field] = Carbon::parse($attributes[$field], $timezone)
                    ->utc()
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                // Leave invalid date input for the model validator.
            }
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
            return response()->json(['count' => 0, 'contact_count' => 0, 'company_count' => 0]);
        }

        try {
            $contacts = app(SegmentService::class)->resolve($segment);
            $count = $contacts->count();
            $companyCount = $contacts->pluck('company_id')->filter()->unique()->count();
        } catch (\Throwable $e) {
            $count = 0;
            $companyCount = 0;
        }

        return response()->json([
            'count' => $count,
            'contact_count' => $count,
            'company_count' => $companyCount,
        ]);
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
            Log::warning('[CampaignController] audienceLanguageSplit failed', [
                'segment_id' => $segment->id,
                'error'      => $e->getMessage(),
            ]);

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
    public function executeSwitch(Request $httpRequest, $id)
    {
        $request = $httpRequest->all();
        $field   = $request['field'] ?? '';

        if ($field === 'is_active') {
            $campaign = $this->currentModel->find($id);

            if (
                $campaign !== null
                && $campaign->schedule_type === 'paced'
                && (int) ($request['state'] ?? 0) === 1
                && ! $httpRequest->user()?->can('send campaigns')
            ) {
                return response()->json([
                    'success' => false,
                    'msg' => 'L’autorisation d’envoi de campagnes est obligatoire pour activer une campagne progressive.',
                ], 403);
            }

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

            if (
                $campaign !== null
                && $campaign->schedule_type === 'paced'
                && (int) ($request['state'] ?? 0) === 1
                && ($campaign->next_run_at === null || (int) $campaign->daily_company_limit < 1)
            ) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Renseignez le premier envoi et un nombre de sociétés par jour valide avant d’activer cette campagne progressive.',
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


    public function dispatchPreview(Request $request, $id)
    {
        $campaign = Campaign::findOrFail((int) $id);
        $validated = $request->validate([
            'action' => ['nullable', 'in:schedule,send'],
        ]);
        $allowEmptyAudience = ($campaign->schedule_type === 'paced' && ($validated['action'] ?? null) === 'schedule')
            || ($campaign->schedule_type === 'sequence' && $campaign->sequence_enrollment_mode === 'paced');
        $preflight = app(CampaignService::class)->dispatchPreflight($campaign, null, $allowEmptyAudience);

        return response()->json([
            'ok' => $preflight['ok'],
            'count' => $preflight['count'],
            'contact_count' => $preflight['contact_count'],
            'company_count' => $preflight['company_count'],
            'messages' => $preflight['messages'],
            'message' => $preflight['ok']
                ? ($campaign->schedule_type === 'paced'
                    ? "Audience vérifiée : {$preflight['company_count']} société(s), {$preflight['contact_count']} contact(s) éligible(s)."
                    : "Audience vérifiée : {$preflight['count']} destinataire(s) éligible(s).")
                : implode(' ', $preflight['messages']),
        ], $preflight['ok'] ? 200 : 422);
    }

    private function blockedPreflightResponse(Campaign $campaign, array $preflight)
    {
        return response()->json([
            'message' => 'error',
            'text' => implode(' ', $preflight['messages']),
            'count' => $preflight['count'],
            'redirect' => route('admin.campaigns.view', $campaign->id),
        ], 422);
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

        $preflight = app(CampaignService::class)->dispatchPreflight(
            $campaign,
            null,
            $campaign->schedule_type === 'paced',
        );
        if (! $preflight['ok']) {
            return $this->blockedPreflightResponse($campaign, $preflight);
        }
        if ($campaign->schedule_type === 'paced') {
            if ($campaign->next_run_at === null || (int) $campaign->daily_company_limit < 1) {
                return response()->json([
                    'message' => 'error',
                    'text' => 'Définissez le premier envoi et le nombre de sociétés par jour avant de planifier.',
                ], 422);
            }

            $campaign->update(['is_active' => true]);
            $next = $campaign->next_run_at->copy()->setTimezone($campaign->scheduleTimezone())->format('d/m/Y H:i');
            $successText = "Campagne progressive activée — prochain lot le {$next}. L’audience dynamique sera vérifiée à chaque jour ouvré.";
            session()->flash('success', $successText);

            return response()->json([
                'message' => 'success', 'text' => $successText,
                'redirect' => route('admin.campaigns.view', $id),
            ]);
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
            $successText = "Campagne récurrente planifiée pour {$preflight['count']} destinataire(s) éligible(s) vérifié(s) — prochaine occurrence le {$next}.";

            session()->flash('success', $successText);

            return response()->json([
                'message'  => 'success',
                'text'     => $successText,
                'redirect' => route('admin.campaigns.view', $id),
            ]);
        }

        // One-shot branch (default).
        app(CampaignService::class)->scheduleOneShot($campaign);
        $successText = "Campagne planifiée avec succès pour {$preflight['count']} destinataire(s) éligible(s) vérifié(s).";

        session()->flash('success', $successText);

        return response()->json([
            'message'  => 'success',
            'text'     => $successText,
            'redirect' => route('admin.campaigns.view', $id),
        ]);
    }

    /**
     * Add a compliance-filtered run snapshot to its dedicated campaign-owned Zoho
     * list, then verify all prepared emails are present. This endpoint never
     * creates or sends a Zoho campaign and never removes existing list members.
     */
    public function syncZohoList($id)
    {
        $campaign = Campaign::with('segment')->findOrFail((int) $id);

        try {
            $summary = app(CampaignZohoListSyncService::class)->sync($campaign);

            return response()->json([
                'status' => $summary['status'],
                'verified' => $summary['verified'],
                'added' => $summary['added'],
                'message' => "Liste Zoho dédiée : {$summary['added']} ajout(s) vérifié(s). Les contacts existants sont conservés. Aucune campagne Zoho n’a été créée ni envoyée.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'blocked',
                'verified' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
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
        $isPacedSequence = $campaign->schedule_type === 'sequence'
            && $campaign->sequence_enrollment_mode === 'paced';
        $preflight = app(CampaignService::class)->dispatchPreflight($campaign, null, $isPacedSequence);
        if (! $preflight['ok']) {
            return $this->blockedPreflightResponse($campaign, $preflight);
        }
        if ($campaign->schedule_type === 'sequence') {
            try {
                if ($isPacedSequence) {
                    $result = app(PacedSequenceEnrollmentService::class)->activate($campaign, now());
                    $next = $result['next_run_at']?->copy()->setTimezone($campaign->scheduleTimezone())->format('d/m/Y H:i');
                    $successText = $result['processed_due']
                        ? "Lot progressif traité : {$result['companies']} société(s), {$result['enrolled']} contact(s) inscrit(s), {$result['skipped']} déjà suivi(s). Prochain lot : {$next}."
                        : "Séquence progressive activée — prochain lot le {$next}.";
                    session()->flash('success', $successText);

                    return response()->json([
                        'message' => 'success',
                        'text' => $successText,
                        'result' => $result,
                        'redirect' => route('admin.campaigns.view', $id),
                    ]);
                }

                $result = app(CampaignService::class)->launchSequence($campaign);
                $campaign->update(['sequence_auto_enroll_enabled' => true]);
                $enrolled = $result['enrolled'];
                $skipped  = $result['skipped'];
                $successText = "Séquence démarrée — {$enrolled} contact(s) ajouté(s) sur {$preflight['count']} destinataire(s) éligible(s) vérifié(s) ({$skipped} déjà suivis).";

                if ($enrolled === 0 && $skipped === 0) {
                    // Empty segment
                    session()->flash('warning', $successText);
                } elseif ($enrolled === 0) {
                    // All contacts already enrolled
                    session()->flash('warning', $successText);
                } else {
                    session()->flash('success', $successText);
                }

                return response()->json([
                    'message'  => 'success',
                    'text'     => $successText,
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

        if ($campaign->schedule_type === 'paced') {
            if ($campaign->next_run_at === null || (int) $campaign->daily_company_limit < 1) {
                return response()->json([
                    'message' => 'error',
                    'text' => 'Définissez le premier envoi et le nombre de sociétés par jour avant de lancer un lot.',
                ], 422);
            }

            try {
                $run = app(PacedCampaignBatchService::class)->prepareManualBatch($campaign, now());
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'message' => 'error', 'text' => $e->getMessage(),
                    'redirect' => route('admin.campaigns.view', $id),
                ], 422);
            }

            SendCampaignJob::dispatch($run->id);
            $companyCount = $run->companyDispatches()->count();
            $contactCount = $run->recipients()->count();
            $successText = "Lot du jour lancé : {$companyCount} société(s), {$contactCount} contact(s) en file d’attente.";
            session()->flash('success', $successText);

            return response()->json([
                'message' => 'success', 'text' => $successText,
                'redirect' => route('admin.campaigns.view', $id),
            ]);
        }

        // ── One-shot / recurring branch ────────────────────────────────────────
        // Manual sends must create a fresh run every click. Reusing the scheduled
        // one-shot occurrence can target an already-finished run and make the UI
        // appear to do nothing.
        $run = app(CampaignService::class)->scheduleImmediate($campaign);

        SendCampaignJob::dispatch($run->id);

        $successText = "Envoi lancé pour {$preflight['count']} destinataire(s) éligible(s) vérifié(s) — la campagne est en file d'attente.";

        session()->flash('success', $successText);

        return response()->json([
            'message'  => 'success',
            'text'     => $successText,
            'redirect' => route('admin.campaigns.view', $id),
        ]);
    }

    /**
     * Enable or stop continuous enrollment for one sequence campaign.
     * Stopping enrollment never changes existing SequenceEnrollment rows.
     */
    public function sequenceAutoEnroll(Request $request, $id)
    {
        $validated = $request->validate([
            'state' => ['required', 'boolean'],
        ]);

        $campaign = Campaign::with(['segment', 'senderIdentity', 'sequence'])->findOrFail((int) $id);
        if ($campaign->schedule_type !== 'sequence') {
            return response()->json([
                'success' => false,
                'msg' => 'Cette option est réservée aux campagnes séquentielles.',
            ], 422);
        }

        if (! (bool) $validated['state']) {
            $campaign->update(['sequence_auto_enroll_enabled' => false]);

            return response()->json([
                'success' => true,
                'msg' => 'Inscription automatique arrêtée. Les parcours en cours continuent.',
            ]);
        }

        $service = app(CampaignService::class);
        $isPacedSequence = $campaign->sequence_enrollment_mode === 'paced';
        $preflight = $service->dispatchPreflight($campaign, null, $isPacedSequence);
        if (! $preflight['ok']) {
            return response()->json([
                'success' => false,
                'msg' => implode(' ', $preflight['messages']),
            ], 422);
        }

        try {
            if ($isPacedSequence) {
                $result = app(PacedSequenceEnrollmentService::class)->activate($campaign, now());
                $next = $result['next_run_at']?->copy()->setTimezone($campaign->scheduleTimezone())->format('d/m/Y H:i');
                $message = $result['processed_due']
                    ? "Lot traité ({$result['companies']} société(s), {$result['enrolled']} inscrit(s), {$result['skipped']} déjà suivi(s)). Prochain lot : {$next}."
                    : "Inscription progressive active — prochain lot le {$next}.";

                return response()->json(['success' => true, 'msg' => $message, 'result' => $result]);
            }

            $result = $service->launchSequence($campaign);
            $campaign->update(['sequence_auto_enroll_enabled' => true]);

            return response()->json([
                'success' => true,
                'msg' => "Inscription automatique active ({$result['enrolled']} nouveau(x), {$result['skipped']} déjà suivi(s)).",
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'msg' => $e->getMessage(),
            ], 422);
        }
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
