<?php

namespace App\Http\Controllers\Traits;

trait Datatableable
{

    /**
     * Display a listing of the resource.
     *
     * Views live at backend/contents/{modelName}/crud/index.blade.php
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return $this->currentDataTable->render(
            'backend.contents.' . $this->modelName . '.crud.index',
            ['listTitle' => $this->listTitle ?? ucfirst($this->modelName) . ' List']
        );
    }

    /**
     * Handle delete request
     *
     * @param int $id
     * @return void
     */
    public function delete($id)
    {
        $model = $this->currentModel->find($id);
        if ($model == null) {
            return response()->json(['success' => false, 'msg' => trans('app.not_found')]);
        }

        $result = $model->delete();

        return response()->json([
            'success' => $result,
            'msg' => trans($result ? 'app.delete_success' : 'app.cannot_delete')
        ]);
    }

    /**
     * Handle a generic switch request.
     * Used for switching a boolean field status
     *
     * @param int $id
     * @return json
     */
    public function executeSwitch($id)
    {
        $request = $this->currentRequest->all();
        $model = $this->currentModel->find($id);
        if ($model == null) {
            return response()->json(['success' => false, 'msg' => trans('app.not_found')]);
        }

        $allowedFields = $this->toggleableFields ?? [];
        if (!in_array($request['field'] ?? '', $allowedFields, true)) {
            return response()->json(['success' => false, 'msg' => trans('app.cannot_delete')], 403);
        }

        $state = (int) $request['state'];
        $model->update([$request['field'] => $state]);

        return response()->json(['success' => true]);
    }
}
