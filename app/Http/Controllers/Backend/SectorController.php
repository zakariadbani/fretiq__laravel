<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\SectorsDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\Sector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectorController extends BackendController
{
    use Crudable;
    use Datatableable;

    /**
     * Whitelist of boolean fields that may be toggled via executeSwitch and toggle().
     *
     * @var array<string>
     */
    protected $toggleableFields = ['is_active', 'use_in_discovery'];

    public function __construct(Request $request, Sector $model, SectorsDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view sectors')->only(['index', 'view']);
        $this->middleware('permission:create sectors')->only(['create', 'store']);
        $this->middleware('permission:edit sectors')->only(['edit', 'update', 'executeSwitch', 'toggle']);
        $this->middleware('permission:delete sectors')->only(['delete']);

        $this->listTitle = 'Secteurs';
        $this->title = 'label';

        $this->bootResource(new BackendResource(
            modelClass: Sector::class,
            modelName: 'sectors',
            dataTableClass: SectorsDataTable::class,
            permissionEntity: 'sectors',
            prefixName: 'admin',
            titleField: 'label',
        ));
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.sectors.crud.index',
            [
                'listTitle' => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * Standalone boolean toggle endpoint (dual-toggle rule — mirrors executeSwitch's
     * $toggleableFields allow-list for any non-listing caller of a live switch).
     *
     * POST /admin/sectors/{id}/toggle
     * Body:   { field: string, value: 0|1 }
     * Returns: { success: bool, message: string, field: string, value: int }
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $field = (string) $request->input('field');
        if (! in_array($field, $this->toggleableFields, true)) {
            return response()->json(['success' => false, 'message' => 'Champ non autorisé'], 403);
        }

        $model = Sector::findOrFail($id);
        $model->update([$field => (bool) $request->input('value')]);

        return response()->json([
            'success' => true,
            'message' => 'Mis à jour',
            'field' => $field,
            'value' => (int) $model->{$field},
        ]);
    }
}
