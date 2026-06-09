<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;
use Yajra\DataTables\Services\DataTable;

class BackendController extends Controller
{

    /**
     * @var \Illuminate\Http\Request current Request
     */
    protected $currentRequest;

    /**
     * @var Model current Model
     */
    protected $currentModel;

    /**
     * @var DataTable current DataTable
     */
    protected $currentDataTable;

    /**
     * @var string current prefix name for routes
     */
    public $currentPrefixName = 'admin';

    /**
     * @var string model name for routes and views
     * Must be plural snake_case to match route names (admin.{modelName}.*)
     * and view paths (backend.contents.{modelName}.crud.*)
     */
    protected $modelName;

    /**
     * @var string list title for index page
     */
    protected $listTitle;

    /**
     * @var string title field name for edit page
     */
    protected $title;

    /**
     * Save the $request in this->currentRequest
     *
     * @param Request $request
     * @param Model $model
     * @param DataTable $dataTable
     */
    public function __construct(Request $request, Model $model = null, DataTable $dataTable = null)
    {
        $this->currentRequest = $request;
        $this->currentModel = $model;
        $this->currentDataTable = $dataTable;
    }

    /**
     * Check if current user is admin or superadmin
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        $user = \Auth::user();
        if (!$user) {
            return false;
        }

        return $user->hasRole(['admin', 'superadmin']);
    }

    /**
     * Boot from a typed BackendResource config object.
     *
     * Throws \RuntimeException immediately (fail-fast) if any of these checks fail:
     *   - model class exists
     *   - injected model is an instance of the declared class
     *   - injected DataTable is an instance of the declared DataTable class
     *   - crud views exist (index, form, view)
     *   - CRUD routes are registered (index, create, store, edit, update, delete)
     *
     * Call at the end of the child controller's constructor:
     *   $this->bootResource(new BackendResource(...));
     *
     * @param BackendResource $r
     * @throws \RuntimeException
     */
    protected function bootResource(BackendResource $r): void
    {
        $failures = [];

        // 1. Model class must exist
        if (!class_exists($r->modelClass)) {
            $failures[] = "Model class [{$r->modelClass}] does not exist.";
        }

        // 2. Injected model must be an instance of the declared class
        if ($this->currentModel !== null && class_exists($r->modelClass) && !($this->currentModel instanceof $r->modelClass)) {
            $actualClass = get_class($this->currentModel);
            $failures[] = "Injected model is [{$actualClass}] but BackendResource declares [{$r->modelClass}].";
        }

        // 3. Injected DataTable must be an instance of the declared class (when declared)
        if ($r->dataTableClass && $this->currentDataTable !== null) {
            if (!class_exists($r->dataTableClass)) {
                $failures[] = "DataTable class [{$r->dataTableClass}] does not exist.";
            } elseif (!($this->currentDataTable instanceof $r->dataTableClass)) {
                $actualDt = get_class($this->currentDataTable);
                $failures[] = "Injected DataTable is [{$actualDt}] but BackendResource declares [{$r->dataTableClass}].";
            }
        }

        // 4. Crud views must exist
        $viewBase = "backend.contents.{$r->modelName}.crud";
        foreach (['index', 'form', 'view'] as $viewName) {
            if (!\Illuminate\Support\Facades\View::exists("{$viewBase}.{$viewName}")) {
                $failures[] = "View [{$viewBase}.{$viewName}] does not exist.";
            }
        }

        // 5. CRUD routes must be registered
        $routeBase = "{$r->prefixName}.{$r->modelName}";
        foreach (['index', 'create', 'store', 'edit', 'update', 'delete'] as $action) {
            if (!\Illuminate\Support\Facades\Route::has("{$routeBase}.{$action}")) {
                $failures[] = "Route [{$routeBase}.{$action}] is not registered.";
            }
        }

        if (!empty($failures)) {
            $list = implode("\n  - ", $failures);
            throw new \RuntimeException(
                "BackendResource boot failed for modelName=[{$r->modelName}]:\n  - " . $list
            );
        }

        // All checks passed — apply the resource config
        $this->modelName = $r->modelName;
        $this->currentPrefixName = $r->prefixName;
        $this->title = $r->titleField;
    }
}
