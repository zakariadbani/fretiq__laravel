<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\SequenceViewConfig;
use App\DataTables\Backend\SequencesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\CampaignTemplate;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SequenceController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * Toggleable boolean fields for the executeSwitch action.
     *
     * @var array<string>
     */
    protected $toggleableFields = ['is_active', 'stop_on_reply'];

    public function __construct(Request $request, Sequence $model, SequencesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view sequences')->only(['index', 'view']);
        $this->middleware('permission:create sequences')->only(['create', 'store']);
        $this->middleware('permission:edit sequences')->only([
            'edit', 'update', 'executeSwitch',
            'addStep', 'updateStep', 'deleteStep', 'moveStepUp', 'moveStepDown',
            'pauseEnrollment', 'resumeEnrollment', 'stopEnrollment',
        ]);
        $this->middleware('permission:delete sequences')->only(['delete']);

        $this->listTitle = 'Séquences';
        $this->title     = 'name';

        // ViewConfig must be set inside the constructor body (never as a class property — FATAL otherwise).
        $this->viewConfigClass = SequenceViewConfig::class;

        $this->bootResource(new BackendResource(
            modelClass:       Sequence::class,
            modelName:        'sequences',
            dataTableClass:   SequencesDataTable::class,
            permissionEntity: 'sequences',
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
            'backend.contents.sequences.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * Override view() to eager-load relationships and pass view vars (for the step builder).
     *
     * @param int $id
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function view($id)
    {
        $model = Sequence::with(['steps.template', 'enrollments.contact'])->find((int) $id);

        if ($model === null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.sequences.index'));
        }

        $view = $this->getView('backend.contents.sequences.crud.view')
            ->with('model', $model)
            ->with('templates', CampaignTemplate::where('name', 'not like', 'E2E\_FIXTURE %')->orderBy('name')->get());

        // Inject viewConfig for the hero + tabbar partials.
        $viewConfig = $this->buildViewConfig($model);
        if ($viewConfig !== null) {
            $view->with('viewConfig', $viewConfig);
        }

        return $view;
    }

    /**
     * Provide select options to the create/edit form views.
     */
    protected function getViewVars(): array
    {
        return [
            'templates' => CampaignTemplate::where('name', 'not like', 'E2E\_FIXTURE %')->orderBy('name')->get(),
        ];
    }

    // ── Step management ────────────────────────────────────────────────────────

    /**
     * Add a step to the sequence.
     * POST /sequences/{id}/steps
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function addStep($id)
    {
        $sequence = Sequence::findOrFail((int) $id);

        $data = $this->currentRequest->validate([
            'delay_days'  => 'required|integer|min:0',
            'template_id' => 'required|integer|exists:campaign_templates,id',
            'subject'     => 'nullable|string|max:255',
        ]);

        $maxStepNo = $sequence->steps()->max('step_no') ?? 0;

        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no'     => $maxStepNo + 1,
            'delay_days'  => $data['delay_days'],
            'template_id' => $data['template_id'],
            'subject'     => $data['subject'] ?? null,
        ]);

        session()->flash('success', 'Étape ajoutée avec succès.');

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }

    /**
     * Update the editable fields of a step owned by the requested sequence.
     * PUT /sequences/{id}/steps/{stepId}
     *
     * @param int $id
     * @param int $stepId
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function updateStep($id, $stepId)
    {
        $sequence = Sequence::findOrFail((int) $id);
        $step = $sequence->steps()->findOrFail((int) $stepId);

        $data = $this->currentRequest->validate([
            'delay_days'  => 'required|integer|min:0',
            'template_id' => 'required|integer|exists:campaign_templates,id',
            'subject'     => 'nullable|string|max:255',
        ]);

        $step->update($data);

        $message = 'Étape modifiée avec succès.';
        $redirect = route('admin.sequences.view', $sequence->id) . '#sequence_steps';

        if ($this->currentRequest->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => $redirect,
            ]);
        }

        session()->flash('success', $message);

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }

    /**
     * Delete a step from the sequence.
     * DELETE /sequences/{id}/steps/{stepId}
     *
     * @param int $id
     * @param int $stepId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deleteStep($id, $stepId)
    {
        $sequence = Sequence::findOrFail((int) $id);
        $step = SequenceStep::where('sequence_id', $sequence->id)->findOrFail((int) $stepId);

        $step->delete();

        session()->flash('success', 'Étape supprimée avec succès.');

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }

    /**
     * Move a step up (lower step_no) within the sequence.
     * POST /sequences/{id}/steps/{stepId}/move-up
     *
     * Uses temp-value (step_no = 0) trick to satisfy the unique(sequence_id, step_no)
     * constraint during the swap: two direct updates would collide mid-transaction.
     *
     * @param int $id
     * @param int $stepId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function moveStepUp($id, $stepId)
    {
        $sequence = Sequence::findOrFail((int) $id);
        $step     = SequenceStep::where('sequence_id', $sequence->id)->findOrFail((int) $stepId);

        // Guard: already the first step — cannot move up
        $prevStep = SequenceStep::where('sequence_id', $sequence->id)
            ->where('step_no', '<', $step->step_no)
            ->orderByDesc('step_no')
            ->first();

        if ($prevStep === null) {
            session()->flash('warning', 'Cette étape est déjà en première position.');
            return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
                ->withFragment('sequence_steps');
        }

        $movingStepNo   = $step->step_no;
        $neighborStepNo = $prevStep->step_no;

        DB::transaction(function () use ($step, $prevStep, $movingStepNo, $neighborStepNo) {
            // Lock both rows at the top of the transaction to prevent concurrent
            // reorder operations on the same sequence from interleaving and
            // corrupting step_no ordering or deadlocking on the unique index.
            $ids = collect([$step->id, $prevStep->id])->sort()->values()->all();
            \App\Models\SequenceStep::whereIn('id', $ids)->lockForUpdate()->get();

            // 1. Move target step to a temp value to release the slot
            $step->update(['step_no' => 0]);
            // 2. Move neighbor up into the freed slot
            $prevStep->update(['step_no' => $movingStepNo]);
            // 3. Place target step into neighbor's old slot
            $step->update(['step_no' => $neighborStepNo]);
        });

        session()->flash('success', 'Étape déplacée.');

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }

    /**
     * Move a step down (higher step_no) within the sequence.
     * POST /sequences/{id}/steps/{stepId}/move-down
     *
     * Uses temp-value (step_no = 0) trick to satisfy the unique(sequence_id, step_no)
     * constraint during the swap.
     *
     * @param int $id
     * @param int $stepId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function moveStepDown($id, $stepId)
    {
        $sequence = Sequence::findOrFail((int) $id);
        $step     = SequenceStep::where('sequence_id', $sequence->id)->findOrFail((int) $stepId);

        // Guard: already the last step — cannot move down
        $nextStep = SequenceStep::where('sequence_id', $sequence->id)
            ->where('step_no', '>', $step->step_no)
            ->orderBy('step_no')
            ->first();

        if ($nextStep === null) {
            session()->flash('warning', 'Cette étape est déjà en dernière position.');
            return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
                ->withFragment('sequence_steps');
        }

        $movingStepNo   = $step->step_no;
        $neighborStepNo = $nextStep->step_no;

        DB::transaction(function () use ($step, $nextStep, $movingStepNo, $neighborStepNo) {
            // Lock both rows at the top of the transaction to prevent concurrent
            // reorder operations on the same sequence from interleaving and
            // corrupting step_no ordering or deadlocking on the unique index.
            $ids = collect([$step->id, $nextStep->id])->sort()->values()->all();
            \App\Models\SequenceStep::whereIn('id', $ids)->lockForUpdate()->get();

            // 1. Move target step to a temp value to release the slot
            $step->update(['step_no' => 0]);
            // 2. Move neighbor down into the freed slot
            $nextStep->update(['step_no' => $movingStepNo]);
            // 3. Place target step into neighbor's old slot
            $step->update(['step_no' => $neighborStepNo]);
        });

        session()->flash('success', 'Étape déplacée.');

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }

    // ── Enrollment management ──────────────────────────────────────────────────

    /**
     * Pause an enrollment.
     * POST /sequences/{id}/enrollments/{enrId}/pause
     *
     * @param int $id
     * @param int $enrId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function pauseEnrollment($id, $enrId)
    {
        $sequence   = Sequence::findOrFail((int) $id);
        $enrollment = SequenceEnrollment::where('sequence_id', $sequence->id)->findOrFail((int) $enrId);

        $enrollment->update([
            'status'         => 'paused',
            'stopped_reason' => null,
        ]);

        session()->flash('success', 'Inscription mise en pause.');

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }

    /**
     * Resume a paused enrollment.
     * POST /sequences/{id}/enrollments/{enrId}/resume
     *
     * @param int $id
     * @param int $enrId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function resumeEnrollment($id, $enrId)
    {
        $sequence   = Sequence::findOrFail((int) $id);
        $enrollment = SequenceEnrollment::where('sequence_id', $sequence->id)->findOrFail((int) $enrId);

        $enrollment->update([
            'status'         => 'active',
            'stopped_reason' => null,
        ]);

        session()->flash('success', 'Inscription reprise.');

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }

    /**
     * Stop an enrollment permanently.
     * POST /sequences/{id}/enrollments/{enrId}/stop
     *
     * @param int $id
     * @param int $enrId
     * @return \Illuminate\Http\RedirectResponse
     */
    public function stopEnrollment($id, $enrId)
    {
        $sequence   = Sequence::findOrFail((int) $id);
        $enrollment = SequenceEnrollment::where('sequence_id', $sequence->id)->findOrFail((int) $enrId);

        $enrollment->update([
            'status'         => 'stopped',
            'stopped_reason' => 'manual',
        ]);

        session()->flash('success', 'Inscription stoppée.');

        return redirect()->back(fallback: route('admin.sequences.view', $sequence->id))
            ->withFragment('sequence_steps');
    }
}
