<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\SenderIdentitiesDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\SenderIdentity;
use Illuminate\Http\Request;

class SenderIdentityController extends BackendController
{
    use Crudable, Datatableable;

    /**
     * Whitelist of boolean fields that may be toggled via executeSwitch.
     *
     * @var array<string>
     */
    protected $toggleableFields = ['is_default', 'is_active'];

    public function __construct(Request $request, SenderIdentity $model, SenderIdentitiesDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view sender_identities')->only(['index', 'view']);
        $this->middleware('permission:create sender_identities')->only(['create', 'store']);
        $this->middleware('permission:edit sender_identities')->only(['edit', 'update', 'executeSwitch']);
        $this->middleware('permission:delete sender_identities')->only(['delete']);

        $this->listTitle = "Identités d'expéditeur";
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       SenderIdentity::class,
            modelName:        'sender_identities',
            dataTableClass:   SenderIdentitiesDataTable::class,
            permissionEntity: 'sender_identities',
            prefixName:       'admin',
            titleField:       'name',
        ));
    }

    /**
     * Override executeSwitch to enforce single-default rule when toggling is_default.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function executeSwitch($id)
    {
        $request = $this->currentRequest->all();
        $model   = SenderIdentity::find((int) $id);

        if ($model === null) {
            return response()->json(['success' => false, 'msg' => trans('app.not_found')]);
        }

        $allowedFields = $this->toggleableFields;
        $field         = $request['field'] ?? '';

        if (!in_array($field, $allowedFields, true)) {
            return response()->json(['success' => false, 'msg' => trans('app.cannot_delete')], 403);
        }

        $state = (int) $request['state'];
        $model->update([$field => $state]);

        // Enforce single-default: if we just enabled is_default, clear all others.
        if ($field === 'is_default' && $state === 1) {
            SenderIdentity::where('id', '!=', $model->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Override index() to inject the dynamic filter config for JavaScript.
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.sender_identities.crud.index',
            [
                'listTitle'       => $this->listTitle,
                'dataTableConfig' => $this->currentDataTable->getIndexConfig(),
            ]
        );
    }

    /**
     * After saving, ensure at most one identity is marked as default.
     * If the saved identity has is_default=true, reset all other identities to false.
     *
     * This handles two code paths:
     *   - Form submit: checkbox checked → is_default='1' in $attributes.
     *   - executeSwitch: state=1, field='is_default' → model already persisted with is_default=true.
     *
     * @param array $attributes
     * @param SenderIdentity $model
     * @return void
     */
    protected function afterSave(array $attributes, $model): void
    {
        // Re-read from DB so we always have the fresh persisted value.
        $fresh = SenderIdentity::find($model->id);

        if ($fresh && $fresh->is_default) {
            SenderIdentity::where('id', '!=', $fresh->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }
}
