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
            'addStep', 'deleteStep',
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
            ->with('templates', CampaignTemplate::orderBy('name')->get());

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
            'templates' => CampaignTemplate::orderBy('name')->get(),
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

        return redirect()->route('admin.sequences.view', $sequence->id);
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

        return redirect()->route('admin.sequences.view', $sequence->id);
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

        return redirect()->route('admin.sequences.view', $sequence->id);
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

        return redirect()->route('admin.sequences.view', $sequence->id);
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

        return redirect()->route('admin.sequences.view', $sequence->id);
    }
}
