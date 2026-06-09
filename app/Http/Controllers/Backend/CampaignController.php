<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\CampaignsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\Sequence;
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
            'runs' => function ($q) {
                $q->orderByDesc('run_at');
            },
            'runs.recipients',
        ])->find((int) $id);

        if ($campaign === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.campaigns.index'));
        }

        $latestRun = $campaign->runs->first();

        return $this->getView('backend.contents.campaigns.crud.view')
            ->with('model', $campaign)
            ->with('latestRun', $latestRun)
            ->with('runs', $campaign->runs);
    }

    /**
     * Provide select options to the create/edit form views.
     */
    protected function getViewVars(): array
    {
        return [
            'segments'             => Segment::orderBy('name')->get(),
            'templates'            => CampaignTemplate::orderBy('name')->get(),
            'senderIdentities'     => SenderIdentity::where('is_active', true)->orderBy('name')->get(),
            'scheduleTypes'        => config('global.data.schedule_types', []),
            'recurrenceFrequencies'=> config('global.data.recurrence_frequencies', []),
            'sequences'            => Sequence::where('is_active', true)->orderBy('name')->get(),
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
     * Requires `send campaigns` permission (enforced via middleware).
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function schedule($id)
    {
        $campaign = Campaign::findOrFail((int) $id);

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
     * Requires `send campaigns` permission (enforced via middleware).
     * The job runs asynchronously on the database queue driver.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendNow($id)
    {
        $campaign = Campaign::findOrFail((int) $id);

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
