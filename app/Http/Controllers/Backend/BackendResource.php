<?php

namespace App\Http\Controllers\Backend;

/**
 * Typed configuration object for a CRUD module wired to BackendController.
 *
 * Usage in a module controller constructor (after parent::__construct()):
 *
 *   $this->bootResource(new BackendResource(
 *       modelClass     : \App\Models\Company::class,
 *       modelName      : 'companies',          // plural snake_case — drives routes + views
 *       dataTableClass : \App\DataTables\Backend\CompaniesDataTable::class,
 *       permissionEntity: 'companies',          // defaults to $modelName
 *       prefixName     : 'admin',              // route prefix
 *       titleField     : 'name',               // field shown in edit page title
 *   ));
 *
 * BackendController::bootResource() reads this object and throws a \RuntimeException
 * at construction time if any class, view, or route is missing — typos fail fast.
 */
final class BackendResource
{
    /**
     * @param string      $modelClass       Fully-qualified Eloquent model class name.
     * @param string      $modelName        Plural snake_case identifier used for:
     *                                       - route names:  admin.{modelName}.index / .create / ...
     *                                       - view paths:   backend.contents.{modelName}.crud.{index,form,view}
     *                                       - permissions:  view {modelName} / edit {modelName} / ...
     * @param string      $dataTableClass   Fully-qualified DataTable class name.
     * @param string|null $permissionEntity Permission entity name (defaults to $modelName if null).
     * @param string      $prefixName       Route prefix name (default: 'admin').
     * @param string|null $titleField       Model attribute shown in the edit page title (e.g. 'name').
     */
    public function __construct(
        public readonly string  $modelClass,
        public readonly string  $modelName,
        public readonly string  $dataTableClass,
        public readonly ?string $permissionEntity = null,
        public readonly string  $prefixName = 'admin',
        public readonly ?string $titleField = null,
    ) {}

    /**
     * Resolve the effective permission entity name.
     * Falls back to $modelName when not explicitly set.
     */
    public function permissionEntity(): string
    {
        return $this->permissionEntity ?? $this->modelName;
    }
}
