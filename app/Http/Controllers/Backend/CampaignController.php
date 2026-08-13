<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\CampaignViewConfig;
use App\DataTables\Backend\CampaignsDataTable;
use App\Exceptions\PacedCampaignBatchAlreadyExistsException;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\SendCampaignJob;
use App\Jobs\SendSequenceWaveStepJob;
use App\Jobs\SyncCampaignRecipientEventsJob;
use App\Jobs\SyncCampaignStatsJob;
use App\Jobs\SyncCampaignWaveZohoListJob;
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
use App\Models\SmtpSendReservation;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\CampaignTestMailService;
use App\Services\Campaign\CampaignRunTimelineService;
use App\Services\Campaign\CampaignRunResendService;
use App\Services\Campaign\CampaignWaveZohoListSyncService;
use App\Services\Campaign\CampaignZohoListSyncService;
use App\Services\Campaign\PacedCampaignBatchService;
use App\Services\Campaign\PacedSequenceEnrollmentService;
use App\Services\Campaign\SegmentService;
use App\Services\Campaign\WaveProjectionService;
use App\Services\Inbox\ReplyRecordingService;
use App\Services\Translation\LanguageResolver;
use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

        $this->middleware('permission:view campaigns')->only(['index', 'view', 'segmentCount', 'nextWavePreview']);
        $this->middleware('permission:create campaigns')->only(['create', 'store']);
        $this->middleware('permission:edit campaigns')->only(['edit', 'update', 'cancelRun', 'markReplied']);
        $this->middleware('permission:delete campaigns')->only(['delete']);
        $this->middleware('permission:send campaigns')->only(['dispatchPreview', 'schedule', 'sendNow', 'testSend', 'sequenceAutoEnroll', 'syncZohoList', 'syncStats', 'retryZohoWave', 'startRunNow', 'resendRun']);

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
     * Update under the same sender-identity/campaign fence used by SMTP claims.
     * This closes the gap between validating editable delivery settings and
     * persisting them while a queue worker is starting a real delivery.
     */
    public function update($id)
    {
        /** @var Campaign|null $snapshot */
        $snapshot = $this->currentModel->find((int) $id);
        if ($snapshot === null) {
            return response()->json(['message' => trans('app.not_found')], 404);
        }

        return DB::transaction(function () use ($id, $snapshot) {
            if ($snapshot->sender_identity_id !== null) {
                SenderIdentity::query()->whereKey($snapshot->sender_identity_id)->lockForUpdate()->first();
            }

            /** @var Campaign|null $model */
            $model = Campaign::query()->lockForUpdate()->find((int) $id);
            if ($model === null) {
                return response()->json(['message' => trans('app.not_found')], 404);
            }
            if ((int) $model->sender_identity_id !== (int) $snapshot->sender_identity_id) {
                throw ValidationException::withMessages([
                    'sender_identity_id' => 'La campagne a été modifiée en parallèle. Rechargez-la avant de réessayer.',
                ]);
            }

            $originalChannel = $model->effectiveDeliveryChannel();
            $originalSenderId = (int) $model->sender_identity_id;
            $attributes = $this->beforeSave((int) $id);
            $validator = $model->validator($attributes, (int) $id);
            if ($validator->fails()) {
                return response()->json([
                    'message' => trans('app.errors_occurred'),
                    'errors' => $validator->errors(),
                ], 406);
            }

            $files = $this->saveFiles($this->currentRequest);
            $data = array_merge($attributes, $files);
            if (! $model->update($data)) {
                return response()->json(['message' => trans('app.error')], 500);
            }

            $this->reconcileDeliveryChannelChange($model, $originalChannel, $originalSenderId);
            $afterSaveResponse = $this->afterSave($data, $model);
            session()->flash('success', trans('app.update_completed'));

            if ($afterSaveResponse instanceof \Illuminate\Http\JsonResponse) {
                return $afterSaveResponse;
            }

            $continue = array_key_exists('saveandcontinue', $attributes);

            return response()->json([
                'message' => 'success',
                'model' => $model,
                'redirect' => $continue
                    ? route($this->currentPrefixName . '.' . $this->modelName . '.edit', $id)
                    : route($this->currentPrefixName . '.' . $this->modelName . '.index'),
            ], 200);
        }, 3);
    }

    /**
     * Preserve SMTP quota history and never remove a campaign whose delivery
     * is in flight, accepted, uncertain, or already completed.
     */
    public function delete($id)
    {
        /** @var Campaign|null $snapshot */
        $snapshot = Campaign::query()->find((int) $id);
        if ($snapshot === null) {
            return response()->json(['success' => false, 'msg' => trans('app.not_found')]);
        }

        return DB::transaction(function () use ($id, $snapshot) {
            if ($snapshot->sender_identity_id !== null) {
                SenderIdentity::query()->whereKey($snapshot->sender_identity_id)->lockForUpdate()->first();
            }

            /** @var Campaign|null $campaign */
            $campaign = Campaign::query()->lockForUpdate()->find((int) $id);
            if ($campaign === null) {
                return response()->json(['success' => false, 'msg' => trans('app.not_found')]);
            }
            if ((int) $campaign->sender_identity_id !== (int) $snapshot->sender_identity_id) {
                return response()->json(['success' => false, 'msg' => trans('app.cannot_delete')]);
            }

            $smtpDeliveryExists = \App\Models\SmtpSendReservation::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('status', ['sending', 'accepted', 'sent', 'uncertain'])
                ->exists();
            $runDeliveryExists = $campaign->hasRunDeliveryEvidence();
            if ($campaign->delivery_started_at !== null || $smtpDeliveryExists || $runDeliveryExists) {
                return response()->json(['success' => false, 'msg' => trans('app.cannot_delete')]);
            }

            // A reservation that never crossed the transport boundary is safe
            // to release. The nullable FK then preserves it as an inert ledger row.
            \App\Models\SmtpSendReservation::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', 'reserved')
                ->update(['status' => 'released', 'lease_expires_at' => null]);

            $result = $campaign->delete();

            return response()->json([
                'success' => $result,
                'msg' => trans($result ? 'app.delete_success' : 'app.cannot_delete'),
            ]);
        }, 3);
    }

    private function reconcileDeliveryChannelChange(Campaign $campaign, string $originalChannel, int $originalSenderId): void
    {
        $newChannel = $campaign->effectiveDeliveryChannel();
        $senderChanged = (int) $campaign->sender_identity_id !== $originalSenderId;
        $smtpPaused = $newChannel === 'smtp' && $campaign->smtpDailyEmailLimit() === 0;
        if ($newChannel === $originalChannel && ! $senderChanged && ! $smtpPaused) {
            return;
        }

        // Any queued direct-SMTP work was authorized for the old channel.
        // Release it now so a stale delayed job cannot choose a mailbox later.
        \App\Models\SmtpSendReservation::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'reserved')
            ->update(['status' => 'released', 'lease_expires_at' => null]);

        if ($originalChannel === 'smtp' && ($newChannel !== 'smtp' || $senderChanged)) {
            CampaignRun::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', 'sending')
                ->where('occurrence_key', 'not like', 'sequence-wave-%')
                ->update(['status' => 'scheduled', 'started_at' => null, 'finished_at' => null]);
        }

        if ($campaign->schedule_type !== 'sequence' || $campaign->sequence_enrollment_mode !== 'paced') {
            return;
        }

        if ($newChannel === 'smtp' || $senderChanged) {
            SequenceEnrollment::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', 'active')
                ->whereNull('next_send_at')
                ->update(['next_send_at' => now()]);

            CampaignRun::query()
                ->where('campaign_id', $campaign->id)
                ->where('occurrence_key', 'like', 'sequence-wave-%')
                ->whereIn('status', ['prepared', 'scheduled', 'failed'])
                ->whereNotIn('driver_ref', ['zoho-send-attempted', 'zoho-send-uncertain'])
                ->update([
                    'status' => 'canceled',
                    'finished_at' => now(),
                    'failure_reason' => 'Canal remplacé par SMTP avant envoi.',
                ]);
        }
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
            'sequence.steps.template',
            'runs' => function ($q) {
                $q->with(['sequenceStep.template', 'sourceRun'])->withCount('companyDispatches')->orderByDesc('run_at');
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
        $stats = $this->campaignStats($executedRuns);
        $schedulerHealth = $this->schedulerHealth();
        $campaignReadiness = app(CampaignService::class)->dispatchPreflight($campaign);
        $currentAudience = $campaignReadiness['contacts'];

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
        $recipientFilters = $this->recipientFilters($campaign->runs, $executedRuns);
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
        $recipientStepReport = $isRunScope
            ? null
            : $this->recipientStepReport($campaign, $executedRuns, $recipients);

        // Tab badge = distinct contacts across all runs (structural, filter-independent).
        $recipientsTotal = CampaignRecipient::whereIn('campaign_run_id', $executedRunIds)
            ->distinct()->count('contact_id');

        $viewConfig    = CampaignViewConfig::make($campaign, $stats, $recipientsTotal, $currentAudience->count(), $campaign->runs->count());
        $timelineRunStates = $this->timelineRunStates($campaign, $campaign->runs);
        $managedTimelineRun = $campaign->schedule_type === 'sequence'
            ? null
            : $campaign->runs->filter(fn (CampaignRun $run) => in_array($run->status, ['prepared', 'scheduled', 'sending'], true))
                ->sortBy('run_at')->first();
        $waveData      = $this->campaignWaveData($campaign);
        $enrolledCount = SequenceEnrollment::where('campaign_id', $campaign->id)->count();
        $enrolledCompanyCount = SequenceEnrollment::query()
            ->where('sequence_enrollments.campaign_id', $campaign->id)
            ->join('contacts', 'contacts.id', '=', 'sequence_enrollments.contact_id')
            ->distinct()
            ->count('contacts.company_id');
        $campaignProgress = null;
        if ($campaign->schedule_type === 'sequence' && $campaign->sequence_enrollment_mode === 'paced') {
            $currentCompanyIds = $currentAudience
                ->pluck('company_id')
                ->filter()
                ->map(fn ($companyId) => (int) $companyId)
                ->unique()
                ->values();
            $contactedCompanyIds = SequenceEnrollment::query()
                ->where('sequence_enrollments.campaign_id', $campaign->id)
                ->join('contacts', 'contacts.id', '=', 'sequence_enrollments.contact_id')
                ->whereNotNull('contacts.company_id')
                ->whereHas('stepSends', fn ($query) => $query
                    ->whereNotNull('sent_at')
                    ->orWhereIn('status', ['sent', 'opened']))
                ->distinct()
                ->pluck('contacts.company_id')
                ->map(fn ($companyId) => (int) $companyId);
            $contactedCompanies = $currentCompanyIds->intersect($contactedCompanyIds)->count();
            $projection = app(WaveProjectionService::class)->projectNext($campaign);
            $baseWaves = $campaign->runs
                ->filter(fn (CampaignRun $run) => preg_match('/^sequence-wave-\d{6}$/', $run->occurrence_key) === 1);

            $campaignProgress = [
                'audience_companies' => $currentCompanyIds->count(),
                'audience_contacts' => $currentAudience->count(),
                'enrolled_companies' => $enrolledCompanyCount,
                'contacted_companies' => $contactedCompanies,
                'remaining_companies' => $currentCompanyIds->count() - $contactedCompanies,
                'progress_percent' => $currentCompanyIds->isEmpty()
                    ? 0
                    : (int) round(($contactedCompanies / $currentCompanyIds->count()) * 100),
                'daily_limit' => $projection['daily_limit'],
                'waves' => [
                    'created' => $baseWaves->count(),
                    'completed' => $baseWaves->where('status', 'sent')->count(),
                    'pending' => $baseWaves->whereIn('status', ['prepared', 'scheduled', 'sending'])->count(),
                    'failed' => $baseWaves->where('status', 'failed')->count(),
                    'empty' => $baseWaves->where('driver_ref', 'zoho-wave-empty')->count(),
                    'projected_remaining' => $projection['projected_remaining_waves'],
                    'projected_total' => $baseWaves->count() + $projection['projected_remaining_waves'],
                ],
            ];
        }

        return $this->getView('backend.contents.campaigns.crud.view')
            ->with('model', $campaign)
            ->with('latestRun', $latestRun)
            ->with('runs', $campaign->runs)
            ->with('stats', $stats)
            ->with('currentAudience', $currentAudience)
            ->with('recipients', $recipients)
            ->with('recipientStepReport', $recipientStepReport)
            ->with('recipientsTotal', $recipientsTotal)
            ->with('recipientFilters', $recipientFilters)
            ->with('chipCounts', $chipCounts)
            ->with('viewConfig', $viewConfig)
            ->with('enrolledCount', $enrolledCount)
            ->with('enrolledCompanyCount', $enrolledCompanyCount)
            ->with('campaignProgress', $campaignProgress)
            ->with('pacedProgress', $pacedProgress)
            ->with('waves', $waveData['waves'])
            ->with('selectedWave', $waveData['selectedWave'])
            ->with('selectedWaveRecipients', $waveData['recipients'])
            ->with('waveEnrollments', $waveData['enrollments'])
            ->with('legacyWaves', $waveData['legacy'])
            ->with('campaignReadiness', $campaignReadiness)
            ->with('schedulerHealth', $schedulerHealth)
            ->with('timelineRunStates', $timelineRunStates)
            ->with('managedTimelineRun', $managedTimelineRun);
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
        $waveRuns->each->loadMissing(['sequenceStep', 'recipients.contact.company']);

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
                'empty' => $run->driver_ref === 'zoho-wave-empty'
                    || (
                        $run->status === 'sent'
                        && $run->stats_sent !== null
                        && (int) $run->stats_sent === 0
                        && blank($run->zoho_campaign_key)
                        && $run->recipients->isNotEmpty()
                        && $run->recipients->every(fn (CampaignRecipient $recipient) => $recipient->status === 'skipped')
                    ),
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
                'label' => 'Préparation des exécutions',
                'key' => 'campaign_scheduler.commands.generate_runs.last_success_at',
            ],
            'dispatch_due' => [
                'label' => 'Départ des envois planifiés',
                'key' => 'campaign_scheduler.commands.dispatch_due.last_success_at',
            ],
        ];
        $parseTimestamp = static function ($value): ?Carbon {
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            try {
                return Carbon::parse($value, 'UTC')->utc();
            } catch (\Throwable) {
                return null;
            }
        };

        $globalLastTickAt = $parseTimestamp(Setting::get('observability.scheduler.last_tick_at'));
        $globalStatus = $globalLastTickAt === null
            ? 'missing'
            : ($globalLastTickAt->lt(Carbon::now('UTC')->subMinutes(2)) ? 'stale' : 'healthy');
        $commands = [];

        foreach ($requiredCommands as $name => $command) {
            $lastSuccessAt = $parseTimestamp(Setting::get($command['key']));
            $commands[$name] = [
                'label' => $command['label'],
                'status' => $lastSuccessAt === null
                    ? 'missing'
                    : ($lastSuccessAt->lt(Carbon::now('UTC')->subMinutes(2)) ? 'stale' : 'healthy'),
                'last_success_at' => $lastSuccessAt,
            ];
        }

        $commandStatuses = collect($commands)->pluck('status');
        $commandsStatus = $commandStatuses->contains('missing')
            ? 'missing'
            : ($commandStatuses->contains('stale') ? 'stale' : 'healthy');
        $statuses = collect([$globalStatus, $commandsStatus]);

        return [
            'status' => $statuses->contains('missing')
                ? 'missing'
                : ($statuses->contains('stale') ? 'stale' : 'healthy'),
            'global' => [
                'status' => $globalStatus,
                'last_tick_at' => $globalLastTickAt,
            ],
            'commands_status' => $commandsStatus,
            'commands' => $commands,
        ];
    }

    /**
     * Server-derived display/action facts for the history table.  This keeps the
     * Blade template descriptive only and avoids per-run queries.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timelineRunStates(Campaign $campaign, Collection $runs): array
    {
        $runIds = $runs->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($runIds === []) {
            return [];
        }

        $recipientFacts = CampaignRecipient::query()
            ->whereIn('campaign_run_id', $runIds)
            ->selectRaw('campaign_run_id, COUNT(*) AS total, SUM(status = "queued") AS queued, SUM(provider_message_id IS NOT NULL OR sent_at IS NOT NULL OR opened_at IS NOT NULL OR clicked_at IS NOT NULL) AS evidence')
            ->groupBy('campaign_run_id')->get()->keyBy('campaign_run_id');
        $recipientIds = CampaignRecipient::query()->whereIn('campaign_run_id', $runIds)->pluck('id');
        $reservations = $recipientIds->isEmpty() ? collect() : SmtpSendReservation::query()
            ->where('campaign_id', $campaign->id)
            ->where('source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
            ->whereIn('source_id', $recipientIds)
            ->get()->groupBy('source_id');
        $reservationByRun = [];
        if ($recipientIds->isNotEmpty()) {
            $recipientRunIds = CampaignRecipient::query()->whereIn('id', $recipientIds)->pluck('campaign_run_id', 'id');
            foreach ($reservations as $recipientId => $rows) {
                $runId = (int) ($recipientRunIds[$recipientId] ?? 0);
                if ($runId) $reservationByRun[$runId] = ($reservationByRun[$runId] ?? collect())->concat($rows);
            }
        }

        return $runs->mapWithKeys(function (CampaignRun $run) use ($campaign, $recipientFacts, $reservationByRun): array {
            $facts = $recipientFacts->get($run->id);
            $rows = $reservationByRun[$run->id] ?? collect();
            $isPending = in_array($run->status, ['prepared', 'scheduled', 'sending'], true);
            $smtpDriver = $run->driver_ref === 'smtp';
            $scheduledSmtp = blank($run->driver_ref) && $campaign->effectiveDeliveryChannel() === 'smtp';
            $driverIsSafe = $smtpDriver || $scheduledSmtp;
            $cancelUnsafeReservation = $rows->contains(fn (SmtpSendReservation $row) => ! in_array($row->status, ['reserved', 'released', 'failed'], true)
                || $row->hasProviderTransportEvidence()
                || $row->attempted_at !== null
                || (int) $row->attempt_count > 0);
            $recipientTotal = (int) ($facts?->total ?? 0);
            $runEvidence = (filled($run->driver_ref) && $run->driver_ref !== 'smtp')
                || ! blank($run->zoho_list_key)
                || ! blank($run->zoho_campaign_key)
                || collect([
                    $run->stats_sent,
                    $run->stats_delivered,
                    $run->stats_opened,
                    $run->stats_clicked,
                    $run->stats_bounced,
                    $run->stats_unsubscribed,
                    $run->stats_replied,
                    $run->conversion_count,
                ])->contains(fn ($value) => (int) $value > 0);
            $hasOnlyReusableReservations = $rows->every(
                fn (SmtpSendReservation $row) => $row->isReusableBeforeTransport(),
            );
            $safeUnreserved = $run->status === 'scheduled'
                && $hasOnlyReusableReservations
                && $driverIsSafe
                && (int) ($facts?->queued ?? 0) === $recipientTotal
                && (int) ($facts?->evidence ?? 0) === 0;
            $safeReservation = in_array($run->status, ['scheduled', 'sending'], true)
                && $recipientTotal > 0
                && (int) ($facts?->queued ?? 0) === $recipientTotal
                && (int) ($facts?->evidence ?? 0) === 0
                && $rows->count() === 1 && $rows->every(fn (SmtpSendReservation $row) => $row->status === 'reserved'
                    && ! $row->hasProviderTransportEvidence());
            $safe = $isPending && $campaign->schedule_type !== 'sequence' && $driverIsSafe && ! $runEvidence
                && ($safeUnreserved || $safeReservation);
            $canCancel = $campaign->schedule_type !== 'sequence'
                && ! $runEvidence
                && ! $cancelUnsafeReservation
                && (in_array($run->status, ['prepared', 'scheduled'], true)
                    || ($run->status === 'sending' && $smtpDriver && $safeReservation));
            $reservedFor = $rows->where('status', 'reserved')->sortBy('reserved_for')->first()?->reserved_for;
            if ($safeUnreserved) $reservedFor = $run->run_at;
            $status = $safe && $reservedFor ? 'scheduled' : $run->status;
            $config = config('global.data.campaign_run_statuses.' . $status, []);
            $reason = $run->failure_reason;
            if ($isPending && ! $safe) $reason = $reason ?: 'Lot en cours de traitement ou déjà remis au fournisseur : actions conservées à titre d’audit.';

            return [(int) $run->id => [
                'label' => $safe ? 'Programmé' : ($config['label'] ?? '—'),
                'color' => $safe ? 'info' : ($config['color'] ?? 'secondary'),
                'reserved_for' => $reservedFor?->copy()->setTimezone($campaign->scheduleTimezone()),
                'can_start' => $campaign->is_active && $safe,
                'can_cancel' => $canCancel,
                'can_resend' => $campaign->is_active && $campaign->schedule_type !== 'sequence' && $run->status === 'sent',
                'reason' => $reason,
                'source' => $run->sourceRun ? 'Renvoi du lot #' . $run->sourceRun->id : null,
            ]];
        })->all();
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

    /**
     * Build the sequence-step matrix for only the contacts on the current page.
     *
     * @param Collection<int, CampaignRun> $executedRuns
     * @return array{steps: Collection, summaries: array<int, array<string, int|float|null>>, cells: array<int, array<int, CampaignRecipient>>}|null
     */
    private function recipientStepReport(
        Campaign $campaign,
        Collection $executedRuns,
        LengthAwarePaginator $recipients,
    ): ?array {
        if ($campaign->schedule_type !== 'sequence' || $campaign->sequence === null) {
            return null;
        }

        $steps = $campaign->sequence->steps->values();
        $stepRuns = $executedRuns
            ->filter(fn (CampaignRun $run) => $run->sequence_step_id !== null)
            ->values();

        $summaries = [];
        foreach ($steps as $step) {
            $summaries[(int) $step->id] = CampaignRun::aggregateKpis(
                $stepRuns->where('sequence_step_id', $step->id),
            );
        }

        $cells = [];
        $contactIds = $recipients->getCollection()
            ->pluck('contact_id')
            ->filter()
            ->map(fn ($contactId) => (int) $contactId)
            ->unique()
            ->values();
        $stepIdByRun = $stepRuns
            ->pluck('sequence_step_id', 'id')
            ->map(fn ($stepId) => (int) $stepId)
            ->all();

        if ($contactIds->isNotEmpty() && $stepIdByRun !== []) {
            $rows = CampaignRecipient::query()
                ->whereIn('campaign_run_id', array_keys($stepIdByRun))
                ->whereIn('contact_id', $contactIds)
                ->orderByDesc('id')
                ->get();

            foreach ($rows as $recipient) {
                $contactId = (int) $recipient->contact_id;
                $stepId = $stepIdByRun[(int) $recipient->campaign_run_id] ?? null;

                if ($stepId !== null && ! isset($cells[$contactId][$stepId])) {
                    $cells[$contactId][$stepId] = $recipient;
                }
            }
        }

        return compact('steps', 'summaries', 'cells');
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
    private function recipientFilters(Collection $historyRuns, Collection $executedRuns): array
    {
        $runId = (int) request()->query('run_id', 0);
        // Any historical run can be inspected directly. The default rollup stays
        // intentionally limited to executed runs below.
        $run   = $runId > 0 ? $historyRuns->firstWhere('id', $runId) : null;

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
        $selectedSender = $this->resolveSourceModel('sender_identity_id', SenderIdentity::class, 'view sender_identities');
        $campaignId = request()->route('id');
        $editingCampaign = is_numeric($campaignId)
            ? Campaign::with(['segment', 'template', 'senderIdentity'])->find((int) $campaignId)
            : null;
        $syncableZohoRuns = is_numeric($campaignId)
            ? CampaignRun::query()
                ->where('campaign_id', (int) $campaignId)
                ->eligibleForStatsSync()
                ->whereNotNull('zoho_campaign_key')
                ->orderByDesc('run_at')
                ->get()
            : collect();
        $selectedCompany = $this->resolveSourceModel('company_id', Company::class, 'view companies');

        $segments = Segment::where('name', 'not like', 'E2E\_FIXTURE %')->orderBy('name')->get();
        $templates = CampaignTemplate::where('name', 'not like', 'E2E\_FIXTURE %')->orderBy('name')->get();
        $senderIdentities = SenderIdentity::where('is_active', true)->where('name', 'not like', 'E2E\_FIXTURE %')->orderBy('name')->get();
        foreach ([[$segments, $selectedSegment], [$templates, $selectedTemplate], [$senderIdentities, $selectedSender]] as [$options, $selected]) {
            if ($selected && ! $options->contains('id', $selected->id)) {
                $options->push($selected);
            }
        }

        $sequences = Sequence::where('is_active', true)
            ->where('name', 'not like', 'E2E\_FIXTURE %')
            ->with(['steps' => fn ($q) => $q->with('template')->orderBy('step_no')])
            ->orderBy('name')
            ->get();

        return [
            'segments'             => $segments->sortBy('name')->values(),
            'templates'            => $templates->sortBy('name')->values(),
            'senderIdentities'     => $senderIdentities->sortBy('name')->values(),
            'scheduleTypes'        => config('global.data.schedule_types', []),
            'emailVerificationPolicies' => config('global.data.campaign_email_verification_policies', []),
            'defaultEmailVerificationPolicy' => app(\App\Services\Discovery\EmailVerificationSettings::class)->defaultCampaignPolicy(),
            'recurrenceFrequencies'=> config('global.data.recurrence_frequencies', []),
            'sequences'            => $sequences,
            'selectedSegment'       => $selectedSegment,
            'selectedTemplate'      => $selectedTemplate,
            'selectedSender'        => $selectedSender,
            'selectedCompany'       => $selectedCompany,
            'latestSyncableZohoRun' => $syncableZohoRuns->first(),
            'syncableZohoRunCount'  => $syncableZohoRuns->count(),
            'campaignReadiness'     => $editingCampaign ? app(CampaignService::class)->dispatchPreflight($editingCampaign) : ['ok' => false, 'messages' => []],
            'schedulerHealth'       => $editingCampaign ? $this->schedulerHealth() : ['status' => 'missing'],
            'managedTimelineRun'    => $editingCampaign && $editingCampaign->schedule_type !== 'sequence'
                ? CampaignRun::query()->where('campaign_id', $editingCampaign->id)
                    ->whereIn('status', ['prepared', 'scheduled', 'sending'])->orderBy('run_at')->first()
                : null,
        ];
    }

    private function resolveSourceModel(string $queryKey, string $modelClass, string $permission): ?object
    {
        $request = request();

        if (! $request->user()?->can($permission)) {
            return null;
        }

        $id = $request->query($queryKey);
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

        if ($currentCampaign === null) {
            $attributes['delivery_channel'] = in_array($attributes['delivery_channel'] ?? null, ['zoho', 'smtp'], true)
                ? $attributes['delivery_channel']
                : 'zoho';
            $attributes['email_verification_policy'] = in_array(
                $attributes['email_verification_policy'] ?? null,
                Campaign::EMAIL_VERIFICATION_POLICIES,
                true,
            ) ? $attributes['email_verification_policy'] : app(\App\Services\Discovery\EmailVerificationSettings::class)->defaultCampaignPolicy();
        } elseif (! array_key_exists('delivery_channel', $attributes)) {
            $attributes['delivery_channel'] = $currentCampaign->delivery_channel;
        }

        if ($currentCampaign !== null && ! array_key_exists('email_verification_policy', $attributes)) {
            $attributes['email_verification_policy'] = $currentCampaign->emailVerificationPolicy();
        }

        if ($currentCampaign?->deliverySettingsLocked()) {
            $lockedErrors = [];
            if (array_key_exists('delivery_channel', $attributes)
                && (string) $attributes['delivery_channel'] !== (string) ($currentCampaign->delivery_channel ?? '')) {
                $lockedErrors['delivery_channel'] = 'Le canal est verrouillé après le début de la livraison.';
            }
            if (array_key_exists('sender_identity_id', $attributes)
                && (int) $attributes['sender_identity_id'] !== (int) $currentCampaign->sender_identity_id) {
                $lockedErrors['sender_identity_id'] = 'L’expéditeur est verrouillé après le début de la livraison.';
            }
            if (array_key_exists('email_verification_policy', $attributes)
                && (string) $attributes['email_verification_policy'] !== $currentCampaign->emailVerificationPolicy()) {
                $lockedErrors['email_verification_policy'] = 'La politique de vérification est verrouillée après la première livraison acceptée.';
            }

            if ($lockedErrors !== []) {
                throw ValidationException::withMessages($lockedErrors);
            }
        }

        if (($attributes['delivery_channel'] ?? null) === 'smtp') {
            $smtpLimit = $attributes['smtp_daily_email_limit'] ?? null;
            $attributes['smtp_daily_email_limit'] = $smtpLimit === null || (is_string($smtpLimit) && trim($smtpLimit) === '')
                ? 20
                : $smtpLimit;
        } elseif ($currentCampaign === null || $this->currentRequest->has('delivery_channel')) {
            $attributes['smtp_daily_email_limit'] = null;
        }

        // This operational flag is controlled exclusively by send-campaigns
        // endpoints. Ordinary create/edit payloads must never toggle it.
        unset($attributes['sequence_auto_enroll_enabled']);

        // NOTE: beforeSave() runs BEFORE model validation (Crudable trait behavior).
        // Defensive checks must not assume validated input.
        $scheduleType = $attributes['schedule_type'] ?? 'one_shot';
        $sequenceMode = $scheduleType === 'sequence'
            ? ($attributes['sequence_enrollment_mode'] ?? (config('services.zoho.driver', 'local') === 'zoho' ? 'paced' : 'immediate'))
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
    public function segmentCount(Request $request, $id)
    {
        $unavailable = [
            'count' => 0,
            'contact_count' => 0,
            'company_count' => 0,
            'contacts_count' => 0,
            'matched_count' => 0,
            'funnel' => null,
            'available' => false,
        ];

        $segment = Segment::find((int) $id);
        if (! $segment) {
            return response()->json($unavailable + ['message' => 'Segment introuvable.'], 404);
        }

        try {
            $policy = $request->validate([
                'email_verification_policy' => ['nullable', 'in:verified_only,all_sendable'],
            ])['email_verification_policy'] ?? Campaign::VERIFICATION_VERIFIED_ONLY;
            $segmentService = app(SegmentService::class);
            $stats = $segmentService->resolveWithStats(
                $segment->scope,
                $segment->filter ?? [],
                false,
                $segment->includedContactIds(),
                $segment->excludedContactIds(),
                $segment->is_manual,
                $policy,
            );
            $count = (int) $stats['final'];
            $companyCount = (int) $stats['company_count'];
            unset($stats['company_count']);
        } catch (\Throwable $exception) {
            Log::warning('Segment audience preview failed.', [
                'segment_id' => $segment->id,
                'exception' => $exception::class,
            ]);

            return response()->json($unavailable + [
                'message' => 'Le calcul de l’audience est momentanément indisponible. Réessayez.',
            ], 422);
        }

        return response()->json([
            'count' => $count,
            'contact_count' => $count,
            'company_count' => $companyCount,
            'contacts_count' => $count,
            'matched_count' => (int) $stats['matched'],
            'funnel' => $stats,
            'available' => true,
        ]);
    }

    /** Preview the next progressive-sequence batch using unsaved form values. */
    public function nextWavePreview(Request $request, $id)
    {
        $campaign = Campaign::findOrFail((int) $id);

        if ($campaign->schedule_type !== 'sequence' || $campaign->sequence_enrollment_mode !== 'paced') {
            return response()->json(['is_sequence_paced' => false]);
        }

        $validated = $request->validate([
            'segment_id' => ['sometimes', 'nullable', 'integer', 'exists:segments,id'],
            'daily_company_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'email_verification_policy' => ['sometimes', 'nullable', 'in:verified_only,all_sendable'],
        ]);

        $projection = app(WaveProjectionService::class)->projectNext(
            $campaign,
            isset($validated['segment_id']) ? (int) $validated['segment_id'] : null,
            isset($validated['daily_company_limit']) ? (int) $validated['daily_company_limit'] : null,
            $validated['email_verification_policy'] ?? null,
        );

        return response()->json(['is_sequence_paced' => true] + $projection);
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
            'email_verification_policy' => ['nullable', 'in:verified_only,all_sendable'],
        ]);

        $segment = Segment::findOrFail((int) $validated['segment_id']);

        // ── Bucket contacts by language ───────────────────────────────────────
        $fr      = 0;
        $en      = 0;
        $unknown = 0;

        try {
            $contacts  = app(SegmentService::class)->resolve(
                $segment,
                $validated['email_verification_policy'] ?? Campaign::VERIFICATION_VERIFIED_ONLY,
            );
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
        $request = $httpRequest->validate([
            'field' => ['required', 'string'],
            'state' => ['required', 'integer', 'in:0,1'],
        ]);
        $field = $request['field'];

        if ($field === 'is_active') {
            $state = (int) ($request['state'] ?? 0);
            if ($state === 0 && ! $httpRequest->user()?->can('edit campaigns')) {
                abort(403);
            }
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

            if (
                $campaign !== null
                && $campaign->schedule_type !== 'sequence'
                && (int) ($request['state'] ?? 0) === 1
                && ! $httpRequest->user()?->can('send campaigns')
            ) {
                return response()->json([
                    'success' => false,
                    'msg' => 'L’autorisation d’envoi de campagnes est obligatoire pour activer une campagne.',
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
            if ($campaign !== null && (int) ($request['state'] ?? 0) === 0) {
                app(CampaignRunTimelineService::class)->pause($campaign);

                return response()->json(['success' => true]);
            }

            if ($campaign !== null && (int) ($request['state'] ?? 0) === 1) {
                try {
                    app(CampaignRunTimelineService::class)->resume($campaign, now());
                } catch (\InvalidArgumentException $exception) {
                    return response()->json([
                        'success' => false,
                        'msg' => $exception->getMessage(),
                    ], 422);
                }

                return response()->json(['success' => true]);
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

    public function cancelRun(Request $request, $id, $runId)
    {
        $campaign = Campaign::findOrFail((int) $id);
        $run = $campaign->runs()->findOrFail((int) $runId);

        try {
            app(CampaignRunTimelineService::class)->cancel($campaign, $run);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'msg' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Le lot a été annulé.',
            'text' => 'Le lot a été annulé.',
            'redirect-to-view' => route('admin.campaigns.view', $campaign) . '#campaign_historique',
        ]);
    }

    public function startRunNow(Request $request, $id, $runId)
    {
        abort_unless($request->user()?->can('send campaigns'), 403);

        $campaign = Campaign::findOrFail((int) $id);
        $run = $campaign->runs()->findOrFail((int) $runId);

        try {
            $scheduledFor = app(CampaignRunTimelineService::class)->startNow($campaign, $run, now());
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'msg' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'message' => 'Le lot a été placé sur le premier créneau sûr.',
            'text' => 'Le lot a été placé sur le premier créneau sûr.',
        ]);
    }

    public function resendRun(Request $request, $id, $runId)
    {
        abort_unless($request->user()?->can('send campaigns'), 403);

        $campaign = Campaign::findOrFail((int) $id);
        $sourceRun = $campaign->runs()->findOrFail((int) $runId);

        try {
            $resendRun = app(CampaignRunResendService::class)->create($campaign, $sourceRun, now());
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'msg' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'run_id' => $resendRun->id,
            'message' => 'Le nouveau lot de renvoi a été préparé.',
            'text' => 'Le nouveau lot de renvoi a été préparé.',
            'redirect-to-view' => route('admin.campaigns.view', $campaign) . '#campaign_historique',
        ]);
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
    public function syncStats(Request $request, $id)
    {
        $campaign = Campaign::findOrFail((int) $id);
        $runs = $campaign->runs()
            ->eligibleForStatsSync()
            ->whereNotNull('zoho_campaign_key')
            ->get(['id']);

        foreach ($runs as $run) {
            SyncCampaignStatsJob::dispatch($run->id);
            SyncCampaignRecipientEventsJob::dispatch($run->id);
        }

        $message = $runs->isEmpty()
            ? html_entity_decode('Aucune ex&eacute;cution Zoho r&eacute;cente &agrave; synchroniser.')
            : html_entity_decode("Synchronisation Zoho mise en file pour {$runs->count()} ex&eacute;cution(s).");
        $redirect = route('admin.campaigns.view', $campaign->id);

        if ($request->expectsJson()) {
            return response()->json(compact('message', 'redirect'));
        }

        return redirect()->to($redirect)
            ->with($runs->isEmpty() ? 'warning' : 'success', $message);
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

    public function retryZohoWave($id, $runId)
    {
        $campaign = Campaign::findOrFail((int) $id);
        $run = $campaign->runs()->whereKey((int) $runId)->firstOrFail();
        abort_unless(
            $run->status === 'failed'
                && $run->canResyncZohoWave()
                && preg_match('/^sequence-wave-\d{6}$/', $run->occurrence_key) === 1,
            409,
            'Cette vague Zoho ne peut pas être relancée automatiquement.'
        );

        filled($run->zoho_list_key)
            ? SendSequenceWaveStepJob::dispatch($run->id)
            : SyncCampaignWaveZohoListJob::dispatch($run->id);

        return redirect()->to(route('admin.campaigns.view', $campaign->id) . "?wave_id={$run->id}#campaign_vagues")
            ->with('success', 'Relance Zoho mise en file d’attente. La date est dépassée : après synchronisation, la campagne sera créée et envoyée immédiatement.');
    }
    /** Send a safe preview without touching campaign operational rows. */
    public function testSend(\Illuminate\Http\Request $request, $id)
    {
        $campaign = Campaign::findOrFail((int) $id);
        $user = auth()->user();
        $attributes = $request->validate(['recipient_email' => 'required|email:rfc|max:191']);
        $router = app(\App\Services\Mail\SmtpMailRouter::class);

        try {
            app(CampaignTestMailService::class)->send($campaign, $user, $attributes['recipient_email']);
            $mode = $router->mode();
            $transport = $router->transportLabel($campaign->senderIdentity);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (\App\Services\Mail\SmtpConfigurationException $exception) {
            \Illuminate\Support\Facades\Log::warning('Campaign preview blocked by SMTP routing.', ['campaign_id' => $campaign->id, 'exception_class' => $exception::class]);

            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('Campaign preview failed.', ['campaign_id' => $campaign->id, 'exception_class' => $exception::class]);

            return response()->json(['success' => false, 'message' => 'Envoi test impossible. Vérifiez la configuration SMTP.'], 500);
        }

        return response()->json([
            'success' => true, 'message' => 'Email test envoyé.', 'recipient' => $attributes['recipient_email'],
            'sender' => ['name' => $campaign->senderIdentity->name, 'email' => $campaign->senderIdentity->email],
            'mode' => $mode,
            'transport' => $transport,
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
            } catch (PacedCampaignBatchAlreadyExistsException $e) {
                $warningText = $e->getMessage();
                session()->flash('warning', $warningText);

                return response()->json([
                    'message' => 'warning', 'text' => $warningText,
                    'redirect' => route('admin.campaigns.view', $id),
                ]);
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
     * Mark a campaign recipient as replied and stop every active sequence enrollment.
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

        return DB::transaction(function () use ($campaign, $recipient) {
            if ($recipient->contact) {
                app(ReplyRecordingService::class)->recordContact($recipient->contact, $recipient);
            }

            session()->flash('success', 'Destinataire marqué comme répondu. Ses séquences actives ont été arrêtées.');

            return redirect()->route('admin.campaigns.view', $campaign->id)
                ->withFragment('campaign_destinataires');
        });
    }
}
