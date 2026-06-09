<?php

namespace App\Http\Controllers\Traits;

trait Crudable
{

    /**
     * Handle create request.
     *
     * Views live at backend/contents/{modelName}/crud/{form,view}.blade.php
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $view = $this->getView('backend.contents.' . $this->modelName . '.crud.form');

        return $view
            ->with('title', trans('app.new ' . $this->modelName))
            ->with('route', route($this->currentPrefixName . '.' . $this->modelName . '.store'))
            ->with('method', 'post')
            ->with('model', $this->currentModel);
    }

    /**
     * Store a newly created resource
     *
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
        $attributes = $this->beforeSave();
        $validator = $this->currentModel->validator($attributes);
        if ($validator->fails()) {
            return response()->json([
                'message' => trans('app.errors_occurred'),
                'errors' => $validator->errors()
            ], 406);
        }
        if (!$attributes) {
            return response()->json(['message' => trans('app.error')], 406);
        }

        $files = $this->saveFiles($this->currentRequest);
        $data = array_merge($attributes, $files);

        $this->currentModel->fill($data);

        if ($this->currentModel->save()) {
            // Capture response from afterSave
            $afterSaveResponse = $this->afterSave($data, $this->currentModel);

            // Flash success message
            session()->flash('success', trans('app.creation_completed'));

            // If afterSave returns a response, return it directly
            if ($afterSaveResponse instanceof \Illuminate\Http\JsonResponse) {
                return $afterSaveResponse;
            }

            if (array_key_exists('saveandcontinue', $attributes)) {
                // Save & Continue: go to edit page
                return response()->json([
                    'message' => 'success',
                    'model' => $this->currentModel,
                    'redirect' => route($this->currentPrefixName . '.' . $this->modelName . '.edit', $this->currentModel->id)
                ], 200);
            } else {
                // Save: go to list page
                return response()->json([
                    'message' => 'success',
                    'model' => $this->currentModel,
                    'redirect' => route($this->currentPrefixName . '.' . $this->modelName . '.index')
                ], 200);
            }
        } else {
            return response()->json(['message' => trans('app.error')], 500);
        }
    }

    /**
     * Handle view request
     *
     * Views live at backend/contents/{modelName}/crud/view.blade.php
     *
     * @param int $id => the id to view
     * @return Response
     */
    public function view($id)
    {
        $model = $this->currentModel->find($id);

        if ($model == null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route($this->currentPrefixName . '.' . $this->modelName . '.index'));
        }

        $view = $this->getView('backend.contents.' . $this->modelName . '.crud.view');
        return $view
            ->with('title', __('overview'))
            ->with('model', $model);
    }

    /**
     * Handle edit request
     *
     * Views live at backend/contents/{modelName}/crud/form.blade.php
     *
     * @param int $id => the id to edit
     * @return Response
     */
    public function edit($id)
    {
        $model = $this->currentModel->find($id);
        if ($model == null) {
            session()->flash('error', trans('app.not_found'));
            return redirect(route($this->currentPrefixName . '.' . $this->modelName . '.index'));
        }

        $view = $this->getView('backend.contents.' . $this->modelName . '.crud.form');

        return $view
            ->with('title', trans('app.edit ' . $this->modelName, ['name' => $model->{isset($this->title) ? $this->title : 'id'}]))
            ->with('route', route($this->currentPrefixName . '.' . $this->modelName . '.update', $id))
            ->with('method', 'post')
            ->with('page', 'edit')
            ->with('model', $model);
    }

    /**
     * Update the specified resource
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        $model = $this->currentModel->find($id);

        if ($model == null) {
            return response()->json(['message' => trans('app.not_found')], 404);
        }

        $attributes = $this->beforeSave($id);
        $validator = $model->validator($attributes, $id);
        if ($validator->fails()) {
            return response()->json([
                'message' => trans('app.errors_occurred'),
                'errors' => $validator->errors()
            ], 406);
        }

        $files = $this->saveFiles($this->currentRequest);
        $data = array_merge($attributes, $files);

        if ($model->update($data)) {
            // Capture response from afterSave
            $afterSaveResponse = $this->afterSave($data, $model);

            session()->flash('success', trans('app.update_completed'));

            // If afterSave returns a response, return it directly
            if ($afterSaveResponse instanceof \Illuminate\Http\JsonResponse) {
                return $afterSaveResponse;
            }

            if (array_key_exists('saveandcontinue', $attributes)) {
                // Save & Continue: stay on edit page
                return response()->json([
                    'message' => 'success',
                    'model' => $model,
                    'redirect' => route($this->currentPrefixName . '.' . $this->modelName . '.edit', $id)
                ], 200);
            } else {
                // Save: go to list page
                return response()->json([
                    'message' => 'success',
                    'model' => $model,
                    'redirect' => route($this->currentPrefixName . '.' . $this->modelName . '.index')
                ], 200);
            }
        }
    }

    /**
     * Before Save Model
     *
     * @param int $id
     * @return array
     */
    protected function beforeSave($id = null)
    {
        return $this->currentRequest->all();
    }

    /**
     * After save Model
     *
     * @param array $attributes
     * @param $model
     * @return void
     */
    protected function afterSave(array $attributes, $model)
    {
        // Override in child controllers for custom post-save logic
    }

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param string $name
     * @return \Illuminate\View\View
     */
    protected function getView($name)
    {
        $view = view($name);
        $vars = $this->getViewVars();
        foreach ($vars as $varName => $var) {
            $view->with($varName, $var);
        }
        $view->with('modelName', $this->modelName);
        return $view;
    }

    /**
     * Define view vars
     *
     * @return array
     */
    protected function getViewVars()
    {
        return [];
    }

    /**
     * Save uploaded files
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    protected function saveFiles($request)
    {
        $files = [];
        $attributes = $request->all();
        if (isset($this->files) && is_array($this->files)) {
            foreach ($this->files as $file) {
                if ($request->hasFile($file)) {
                    $files[$file] = $request->file($file)->store($this->currentModel->path ?? 'uploads', ['disk' => 'local']);
                } else if (!empty($attributes[$file . '_remove'])) {
                    $files[$file] = null;
                }
            }
        }
        return $files;
    }
}
