<?php

namespace App\DataTables;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class GlobalDataTable extends DataTable
{

    protected $currentModel;
    protected $currentQuery;
    protected $currentRequest;
    protected $datatables;
    protected $buttons = [];
    protected $searchableJoins;
    protected $columns = [];
    protected $select;
    protected $joins;
    protected $leftJoins;
    protected $conditions;
    protected $table_filters = [];
    public $currentPrefixName = 'admin';

    /**
     * Optional single permission ability that gates ALL row actions (view/edit/delete).
     * When null, actions fall back to the per-action "{action} {modelName}" convention.
     * Set in a subclass for modules using a keyword permission (e.g. 'manage packages').
     */
    protected $actionPermission = null;

    /**
     * Initialize DataTable with model and request
     *
     * @param Model $model
     * @param Request $request
     */
    public function __construct(Model $model = null, Request $request = null)
    {
        $this->currentModel = $model;
        $this->currentRequest = $request;
    }

    /**
     * Build DataTable class.
     *
     * @param mixed $query Results from query() method.
     *
     * @return \Yajra\DataTables\DataTableAbstract
     */
    public function dataTable($query)
    {
        $this->currentQuery = $query;
        $this->initRows();
        $this->initJoins();
        $this->initLeftJoins();
        $this->initConditions();

        // Only mark columns that actually render HTML as raw; plain text columns stay escaped.
        $rawCols = ['action'];
        foreach ($this->columns as $key => $col) {
            if (!empty($col['switch']) || !empty($col['raw'])) {
                $rawCols[] = $key;
            }
        }

        $this->datatables = datatables()
            ->eloquent($this->currentQuery);
        $this->datatables->filter(function ($query) {
            $this->currentQuery = $query;
            $this->applyFilters();
        }, true);

        $this->datatables = $this->datatables->rawColumns($rawCols);

        // Add executeSwitch for datatable
        foreach ($this->columns as $key => $column) {
            if (isset($column['switch'])) {
                $typetoggle = $column['typetoggle'] ?? null;
                $toggleLabel = $column['title'] ?? $key;
                $this->datatables = $this->datatables->editColumn($key, function ($model) use ($key, $typetoggle, $toggleLabel) {
                    return view('backend.components.datatable.status', [
                        'model' => $model,
                        'name' => $key,
                        'typetoggle' => $typetoggle,
                        'toggleLabel' => $toggleLabel,
                    ]);
                });
            }
        }

        $this->datatables = $this->datatables
            ->editColumn('created_at', function (Model $model) {
                return $model->created_at ? $model->created_at->format('d/m/Y H:i') : '-';
            })
            ->addColumn('action', function (Model $model) {
                if (!empty($this->skipDefaultAction)) {
                    return '';
                }
                return view('backend.components.datatable.actions', [
                    'model' => $model,
                    'modelName' => \Str::plural($model->getName()),
                    'actionPermission' => $this->actionPermission,
                ])->render();
            });

        $this->createEditColumns();

        return $this->datatables;
    }

    /**
     * Get query source of dataTable.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        return $this->currentModel->newQuery();
    }

    /**
     * Optional method if you want to use html builder.
     *
     * @return \Yajra\DataTables\Html\Builder
     */
    public function html()
    {
        addVendors(['datatables']);
        addJavascriptFile('assets/js/custom/datatables-utils.js');

        return $this->builder()
            ->setTableId($this->currentModel->getName() . '-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0)
            ->responsive()
            ->autoWidth(false)
            ->parameters([
                'scrollX' => false,
                'searchDelay' => 350,
                'drawCallback' => 'function() { try { if (window.KTMenu) { KTMenu.createInstances(); } if (window.DataTableUtils && window.DataTableUtils.fixAccessibility instanceof Function) { window.DataTableUtils.fixAccessibility(this.api().table().container()); } } catch (e) { console.error(e); } }',
                'initComplete' => 'function() { try { if (window.DataTableUtils && window.DataTableUtils.fixAccessibility instanceof Function) { window.DataTableUtils.fixAccessibility(this.api().table().container()); } } catch (e) { console.error(e); } }',
                'buttons' => $this->buttons,
                'language' => $this->dataTableLanguage(),
            ])
            ->addTableClass('align-middle table-row-dashed fs-6 gy-3');
    }

    protected function dataTableLanguage(): array
    {
        $language = trans('datatables');

        return is_array($language) ? $language : [];
    }

    /**
     * Get columns.
     *
     * @return array
     */
    protected function getColumns()
    {
        $columns = [];
        $columns[] = Column::make('id')->title('ID')->addClass('ps-0')->responsivePriority(10000);
        $firstBusinessColumn = true;

        foreach ($this->columns as $key => $column) {
            $orderable = isset($column['orderable']) ? $column['orderable'] : true;
            $searchable = isset($column['searchable']) ? $column['searchable'] : true;
            $priority = $column['priority'] ?? ($firstBusinessColumn ? 1 : (in_array($key, ['status', 'is_active'], true) ? 2 : 4));
            $firstBusinessColumn = false;

            $col = Column::make($key)
                ->title(isset($column['title']) ? $column['title'] : __('attribute.' . $key))
                ->orderable($orderable)
                ->searchable($searchable)
                ->responsivePriority($priority);

            // Hide column but keep it searchable
            if (isset($column['visible']) && $column['visible'] === false) {
                $col->visible(false);
            }

            // Enable searchPanes for specific columns
            if (isset($column['searchPanes']) && $column['searchPanes']) {
                $col->searchPanes(true);
            }

            $columns[] = $col;
        }

        $columns[] = Column::computed('action')
            ->exportable(false)
            ->printable(false)
            ->addClass('')
            ->responsivePriority(3);

        return $columns;
    }

    /**
     * Get filename for export.
     *
     * @return string
     */
    protected function filename(): string
    {
        return $this->currentModel->getName() . '_' . date('YmdHis');
    }

    protected function initRows()
    {
        if ($this->select) {
            $this->currentQuery = $this->currentQuery->select($this->select)->distinct();
        }
    }

    protected function initSearch()
    {
        if (!empty($this->searchableJoins) && request()->has('search') && request()->input('search.value') !== '') {
            $searchValue = request()->input('search.value');

            $this->currentQuery = $this->currentQuery->where(function ($query) use ($searchValue) {
                // Search in related tables
                foreach ($this->searchableJoins as $relation => $fields) {
                    $query = $query->with($relation)
                        ->orWhereHas($relation, function ($q) use ($searchValue, $fields) {
                            foreach ($fields as $field) {
                                $q = $q->orWhere($field, 'like', "%$searchValue%");
                            }
                        });
                }
            });

            $this->currentQuery->where(function ($query) use ($searchValue) {
                foreach ($this->searchableJoins as $relation => $fields) {
                    $query->orWhereHas($relation, function ($q) use ($searchValue, $fields) {
                        foreach ($fields as $field) {
                            $q->orWhere($q->qualifyColumn($field), 'like', "%$searchValue%");
                        }
                    });
                }
            });
        }
    }

    protected function initJoins()
    {
        if ($this->joins) {
            foreach ($this->joins as $join) {
                $this->currentQuery = $this->currentQuery->join($join[0], $join[1], $join[2], $join[3]);
            }
        }
    }

    protected function initLeftJoins()
    {
        if ($this->leftJoins) {
            foreach ($this->leftJoins as $join) {
                $this->currentQuery = $this->currentQuery->leftJoin($join[0], $join[1], $join[2], $join[3]);
            }
        }
    }

    protected function initConditions()
    {
        if ($this->conditions) {
            foreach ($this->conditions as $condition) {
                $this->currentQuery = $this->currentQuery->where($condition[0], $condition[1], $condition[2]);
            }
        }
    }

    protected function createEditColumns()
    {
        return [];
    }

    /**
     * Apply filters from $table_filters declaration
     *
     * @return void
     */
    protected function applyFilters()
    {
        if (empty($this->table_filters)) {
            return;
        }

        $request = $this->currentRequest->all();
        $conditions = $this->createFilterConditions($request);

        foreach ($conditions as $condition) {
            $this->currentQuery = $this->currentQuery->where($condition[0], $condition[1], $condition[2]);
        }
    }

    /**
     * Create filter conditions from request parameters based on $table_filters
     *
     * @param array $request
     * @return array
     */
    protected function createFilterConditions(array $request)
    {
        $conditions = [];

        foreach ($this->table_filters as $field => $params) {
            $filterKey = $params['filterKey'] ?? $field;
            $prefix = $params['prefix'] ?? '';
            $type = $params['type'] ?? 'text';

            // Check if filter parameter exists in request
            $hasValue = isset($request[$field]) && trim($request[$field]) != '';
            $hasDateRange = !empty($request[$field . '_from']) || !empty($request[$field . '_to']);

            if (!$hasValue && !$hasDateRange) {
                continue;
            }

            // Apply filter based on type
            switch ($type) {
                case 'int':
                case 'select':
                case 'bool':
                    $conditions[] = [$prefix . $filterKey, '=', (int) $request[$field]];
                    break;

                case 'select_enum':
                    $conditions[] = [$prefix . $filterKey, '=', $request[$field]];
                    break;

                case 'text':
                    $conditions[] = [$prefix . $filterKey, 'LIKE', '%' . trim($request[$field]) . '%'];
                    break;

                case 'date':
                    if (!empty($request[$field . '_from'])) {
                        $dateFrom = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $request[$field . '_from'])));
                        $conditions[] = [$prefix . $filterKey, '>=', $dateFrom];
                    }
                    if (!empty($request[$field . '_to'])) {
                        $dateTo = date('Y-m-d 23:59:59', strtotime(str_replace('/', '-', $request[$field . '_to'])));
                        $conditions[] = [$prefix . $filterKey, '<=', $dateTo];
                    }
                    break;

                case 'money':
                    if (!empty($request[$field . '_from'])) {
                        $amountFrom = str_replace(',', '.', $request[$field . '_from']);
                        $conditions[] = [$prefix . $filterKey, '>=', $amountFrom];
                    }
                    if (!empty($request[$field . '_to'])) {
                        $amountTo = str_replace(',', '.', $request[$field . '_to']);
                        $conditions[] = [$prefix . $filterKey, '<=', $amountTo];
                    }
                    break;
            }
        }

        return $conditions;
    }

    /**
     * Get filters configuration for frontend consumption
     * Returns an array formatted for DataTableUtils.renderFilters()
     *
     * @return array
     */
    public function getFiltersConfig()
    {
        $filters = [];

        foreach ($this->table_filters as $field => $params) {
            $type = $params['type'] ?? 'text';
            $options = $params['options'] ?? [];

            // Auto-load options from config for select_enum types
            if ($type === 'select_enum' && isset($params['configKey'])) {
                $configData = config('global.data.' . $params['configKey'], []);
                $options = $this->formatConfigOptions($configData);
            }

            // Map PHP types to JS types
            $jsType = match($type) {
                'int', 'bool' => 'boolean',
                'select_enum', 'select' => 'select',
                default => $type
            };

            $filters[] = [
                'type' => $jsType,
                'name' => 'filter_' . $field,
                'label' => $params['title'] ?? ucfirst($field),
                'paramName' => $params['filterKey'] ?? $field,
                'options' => $options,
            ];
        }

        return $filters;
    }

    /**
     * Format config options for JavaScript consumption
     * Handles both simple 'key' => 'Label' and 'key' => ['label' => 'Label', ...] formats
     *
     * @param array $configData
     * @return array
     */
    protected function formatConfigOptions(array $configData): array
    {
        $options = [];
        foreach ($configData as $value => $data) {
            $text = is_array($data) ? ($data['label'] ?? $value) : $data;
            $options[] = ['value' => $value, 'text' => $text];
        }
        return $options;
    }

    /**
     * Get complete index page configuration for JavaScript initialization
     * This is passed to DataTableUtils.initializeIndex() in the blade view
     *
     * @return array
     */
    public function getIndexConfig(): array
    {
        return [
            'tableId' => $this->getTableId(),
            'filterConfigs' => $this->getFiltersConfig(),
            'messages' => $this->getMessages(),
        ];
    }

    /**
     * Get the table ID used by LaravelDataTables
     * Override in child classes if the table ID differs from model name
     *
     * Must derive from the same source as the rendered DOM id in html()
     * (setTableId($model->getName() . '-table')) so the JS getDataTable()
     * lookup finds an exact match instead of relying on the "exactly one
     * table on the page" fallback.
     *
     * @return string
     */
    protected function getTableId(): string
    {
        if ($this->currentModel !== null && method_exists($this->currentModel, 'getName')) {
            return $this->currentModel->getName();
        }

        return strtolower(class_basename($this->currentModel));
    }

    /**
     * Get localized messages for DataTable UI feedback
     * Override in child classes for custom entity names
     *
     * @return array
     */
    protected function getMessages(): array
    {
        $entityName = $this->getEntityName();
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => "Êtes-vous sûr de vouloir supprimer ce {$entityName} ?",
            'deleteSuccess' => ucfirst($entityName) . ' supprimé avec succès',
        ];
    }

    /**
     * Get the entity name for messages (singular, lowercase)
     * Override in child classes for custom entity names
     *
     * @return string
     */
    protected function getEntityName(): string
    {
        return strtolower(class_basename($this->currentModel));
    }
}
