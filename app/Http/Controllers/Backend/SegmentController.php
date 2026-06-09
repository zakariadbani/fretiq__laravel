<?php

namespace App\Http\Controllers\Backend;

use App\Crud\ViewConfigs\SegmentViewConfig;
use App\DataTables\Backend\SegmentsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Segment;
use Illuminate\Http\Request;

class SegmentController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, Segment $model, SegmentsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        // Assigned at runtime to avoid a trait+class property default conflict.
        $this->viewConfigClass = SegmentViewConfig::class;

        $this->middleware('permission:view segments')->only(['index', 'view']);
        $this->middleware('permission:create segments')->only(['create', 'store']);
        $this->middleware('permission:edit segments')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete segments')->only(['delete']);

        $this->listTitle = 'Segments';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       Segment::class,
            modelName:        'segments',
            dataTableClass:   SegmentsDataTable::class,
            permissionEntity: 'segments',
            prefixName:       'admin',
            titleField:       'name',
        ));
    }

    /**
     * Override view() to inject contacts count stats for ViewConfig/apercu.
     */
    public function view($id)
    {
        $model = $this->currentModel->find($id);

        if ($model == null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route('admin.segments.index'));
        }

        $stats  = $this->segmentStats($model);
        $config = SegmentViewConfig::make($model, $stats);

        $view = $this->getView('backend.contents.segments.crud.view');
        $view
            ->with('title', 'Aperçu')
            ->with('model', $model)
            ->with('viewConfig', $config)
            ->with('stats', $stats);

        return $view;
    }

    /**
     * Compute lightweight stats for the ViewConfig.
     */
    private function segmentStats(Segment $segment): array
    {
        return [
            'contacts_count' => $segment->contactsCount(),
        ];
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.segments.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * Provide select options to the create/edit form views.
     */
    protected function getViewVars(): array
    {
        return [
            'scopes' => config('global.data.segment_scopes', []),
        ];
    }

    /**
     * Decode the JSON textarea value for the filter field before validation.
     *
     * The model casts filter => array (JSON column), but a textarea submits a plain
     * string. This hook converts that string to an array (or null) so the
     * nullable|array validation rule passes and the Eloquent cast works correctly.
     *
     * - Empty / whitespace-only string  → null  (nullable rule passes)
     * - Valid JSON object/array string  → decoded array (array rule passes)
     * - Invalid JSON string             → left as-is (string), so the array rule
     *   fails with a clear validation error for the user
     *
     * @param int|null $id
     * @return array
     */
    protected function beforeSave($id = null): array
    {
        $attributes = $this->currentRequest->all();

        if (array_key_exists('filter', $attributes)) {
            $raw = trim((string) $attributes['filter']);

            if ($raw === '') {
                $attributes['filter'] = null;
            } else {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $attributes['filter'] = $decoded;
                }
                // else: leave as original string — validation will reject it with "must be an array"
            }
        }

        return $attributes;
    }
}
