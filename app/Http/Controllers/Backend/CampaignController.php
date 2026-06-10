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
use Illuminate\Http\Request;

class CampaignController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * No toggleable boolean fields on Campaign.
     *
     * @var array<string>
     */
    protected $toggleableFields = [];

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
     * Loads the campaign with all runs and recipients from the latest run.
     * Computes $stats and passes $viewConfig (with real KPI values) to the view.
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
            'runs.recipients',
        ])->find((int) $id);

        if ($campaign === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.campaigns.index'));
        }

        $latestRun      = $campaign->runs->first();
        $stats          = $this->campaignStats($campaign);
        $viewConfig     = CampaignViewConfig::make($campaign, $stats);
        $enrolledCount  = SequenceEnrollment::where('campaign_id', $campaign->id)->count();

        return $this->getView('backend.contents.campaigns.crud.view')
            ->with('model', $campaign)
            ->with('latestRun', $latestRun)
            ->with('runs', $campaign->runs)
            ->with('stats', $stats)
            ->with('viewConfig', $viewConfig)
            ->with('enrolledCount', $enrolledCount);
    }

    /**
     * Compute aggregate KPI stats for a campaign from its runs and recipients.
     * Reuses the already-eager-loaded runs/recipients (no extra queries when called
     * after view() has loaded the campaign with runs.recipients).
     *
     * @param Campaign $campaign  Must already have runs + runs.recipients eager-loaded.
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
     * Schedule a one-shot run for the given campaign.
     * Sequence-type campaigns are rejected — they launch via sendNow().
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

        return redirect()->route('admin.campaigns.view', $campaign->id);
    }
}
