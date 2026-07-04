<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\ProspectCriteria;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Http\Request;

class ProspectCriteriaDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'sectors' => [
            'title'      => 'Secteurs',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'countries' => [
            'title'      => 'Pays',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'companies_count' => [
            'title'      => 'Entreprises',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'contacts_count' => [
            'title'      => 'Contacts',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'daily_limit' => [
            'title'      => 'Découvertes/j',
            'orderable'  => true,
            'searchable' => false,
        ],
        'automation' => [
            'title'      => 'Automatisation',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'is_active' => [
            'title'      => 'Actif',
            'orderable'  => true,
            'searchable' => false,
            'switch'     => true,
            'typetoggle' => 'status',
            'raw'        => true,
        ],
        'last_discovery' => [
            'title'      => 'Dernière découverte',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'created_at' => [
            'title'      => 'Créé le',
            'orderable'  => true,
            'searchable' => false,
        ],
    ];

    protected $table_filters = [
        'is_active' => [
            'type'      => 'int',
            'filterKey' => 'is_active',
            'title'     => 'Actif',
        ],
    ];

    /**
     * Computed once per DataTable render: whether the daily OR monthly company
     * quota is exhausted. null = unlimited or tables not yet migrated;
     * false = credits remain on both meters; true = at least one meter is at 0.
     *
     * @var bool|null
     */
    protected ?bool $quotaExhausted = null;

    public function __construct(ProspectCriteria $model, Request $request, DiscoveryQuotaService $quotaService)
    {
        parent::__construct($model, $request);

        // Compute quota state once for all rows — avoids N+1 DB reads.
        // Wrapped in QueryException catch so the DataTable still renders
        // when the quota tables do not yet exist (pre-migration dev DB).
        try {
            $remaining        = $quotaService->remainingTodayForDisplay();
            $monthlyRemaining = $quotaService->monthlyRemainingForDisplay();
            // null = unlimited (never exhausted); 0 = exhausted.
            // Disable when EITHER the daily OR the monthly company meter is at 0.
            // (null === 0 is false, so unlimited meters never trip this.)
            $this->quotaExhausted = ($remaining === 0) || ($monthlyRemaining === 0);
        } catch (\Illuminate\Database\QueryException $e) {
            $this->quotaExhausted = null; // unknown — allow the button
        }
    }

    /**
     * Get query source for DataTable.
     */
    public function query()
    {
        return $this->currentModel->newQuery()
            ->withCount(['companies', 'contacts'])
            ->with('latestDiscoveryRun');
    }

    /**
     * Render sectors as badge spans (escaped) and countries as escaped comma list.
     * Appends a "Lancer la découverte" button to the standard action column.
     * The is_active switch column is handled automatically by the base class.
     */
    protected function createEditColumns(): void
    {
        $this->datatables->editColumn('sectors', function (ProspectCriteria $row) {
            $sectors = is_array($row->sectors) ? $row->sectors : [];
            if (empty($sectors)) {
                return '<span class="text-muted">—</span>';
            }
            $badges = array_map(fn($s) => '<span class="badge badge-light-primary me-1">' . e(trim($s)) . '</span>', $sectors);
            return implode('', $badges);
        });

        $this->datatables->editColumn('countries', function (ProspectCriteria $row) {
            $countries = is_array($row->countries) ? $row->countries : [];
            if (empty($countries)) {
                return '<span class="text-muted">—</span>';
            }
            // Map ISO-2 codes → French labels; unknown values pass through unchanged.
            $countryLabels = config('global.data.company_countries', []);
            $labels = array_map(fn($v) => $countryLabels[$v] ?? $v, $countries);
            return e(implode(', ', $labels));
        });

        $this->datatables->editColumn('companies_count', function (ProspectCriteria $row) {
            $n = (int) ($row->companies_count ?? 0);
            $class = $n > 0 ? 'badge badge-light-primary' : 'badge badge-light text-muted';
            return '<span class="' . $class . '">' . $n . '</span>';
        });

        $this->datatables->editColumn('contacts_count', function (ProspectCriteria $row) {
            $n = (int) ($row->contacts_count ?? 0);
            $class = $n > 0 ? 'badge badge-light-info' : 'badge badge-light text-muted';
            return '<span class="' . $class . '">' . $n . '</span>';
        });

        $this->datatables->addColumn('automation', function (ProspectCriteria $row) {
            if ($row->auto_run && $row->run_at_hour !== null) {
                $html = '<span class="badge badge-light-success">Auto &middot; ' . sprintf('%02d:00', $row->run_at_hour) . '</span>';
            } else {
                $html = '<span class="text-muted">Manuel</span>';
            }
            if ($row->contact_limit !== null) {
                $html .= '<div class="text-muted fs-8 mt-1">&le; ' . (int) $row->contact_limit . ' contacts</div>';
            }
            return $html;
        });

        $this->datatables->addColumn('last_discovery', function (ProspectCriteria $row) {
            $run = $row->latestDiscoveryRun;
            if (!$run) {
                return '<span class="badge badge-light-secondary">Jamais lancée</span>';
            }
            $cfg   = config('global.data.discovery_run_statuses.' . $run->status, []);
            $label = $cfg['label'] ?? $run->status;
            $color = $cfg['color'] ?? 'secondary';
            $html  = '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
            $when  = $run->finished_at ?? $run->started_at;
            if ($when) {
                $html .= '<div class="text-muted fs-8 mt-1">' . e($when->format('d/m/Y H:i')) . '</div>';
            }
            return $html;
        });

        // Extend the action column with a "Lancer la découverte" button (permission-gated).
        $this->datatables->editColumn('action', function (ProspectCriteria $row) {
            $user = auth()->user();
            $id   = (int) $row->id;
            $csrf = e(csrf_token());
            $html = '<div class="d-flex justify-content-end flex-shrink-0">';

            // Discover button (run discovery permission)
            // Disabled when the daily OR monthly company quota is exhausted (0 remaining);
            // enabled when both meters are unlimited or have credits.
            if ($user?->can('run discovery')) {
                $quotaExhausted = $this->quotaExhausted === true;
                $disabledAttr   = $quotaExhausted ? ' disabled' : '';
                $tooltipTitle   = $quotaExhausted
                    ? 'Solde &#233;puis&#233; &#8212; recharge demain &#224; minuit'
                    : 'Lancer la d&#233;couverte';
                $onclickAttr    = $quotaExhausted ? '' : ' onclick="launchDiscovery(' . $id . ', \'' . $csrf . '\')"';

                $html .= '<button type="button"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-success btn-sm me-1"'
                    . $onclickAttr
                    . ' data-bs-toggle="tooltip"'
                    . ' title="' . $tooltipTitle . '"'
                    . $disabledAttr . '>'
                    . '<i class="bi bi-play-fill fs-4"></i>'
                    . '</button>';
            }

            // Duplicate button (create prospect_criteria permission)
            if ($user?->can('create prospect_criteria')) {
                $duplicateUrl = e(route('admin.prospect_criteria.duplicate', $id));
                $html .= '<button type="button"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"'
                    . ' onclick="submitPostForm(\'' . $duplicateUrl . '\', \'' . $csrf . '\')"'
                    . ' data-bs-toggle="tooltip"'
                    . ' title="Dupliquer">'
                    . '<i class="bi bi-copy fs-4"></i>'
                    . '</button>';
            }

            // Edit button
            if ($user?->can('edit prospect_criteria')) {
                $html .= '<a href="' . route('admin.prospect_criteria.edit', $id) . '"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"'
                    . ' data-bs-toggle="tooltip" title="Modifier">'
                    . '<i class="bi bi-pencil fs-4"></i>'
                    . '</a>';
            }

            // View button
            if ($user?->can('view prospect_criteria')) {
                $html .= '<a href="' . route('admin.prospect_criteria.view', $id) . '"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"'
                    . ' data-bs-toggle="tooltip" title="Voir">'
                    . '<i class="bi bi-eye fs-4"></i>'
                    . '</a>';
            }

            // Delete button
            if ($user?->can('delete prospect_criteria')) {
                $html .= '<a href="javascript:void(0);"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm delete-btn"'
                    . ' data-id="' . $id . '"'
                    . ' data-url="' . route('admin.prospect_criteria.delete', $id) . '"'
                    . ' data-bs-toggle="tooltip" title="Supprimer">'
                    . '<i class="bi bi-trash fs-4"></i>'
                    . '</a>';
            }

            $html .= '</div>';
            return $html;
        });
    }

    /**
     * Return the JS tableId that matches the HTML table id set by html().
     * html() calls setTableId(getName() . '-table') → 'prospect_criteria-table'.
     * getDataTable() appends '-table', so we return 'prospect_criteria' here.
     * The base class default (strtolower(class_basename(model))) gives
     * 'prospectcriteria' which does NOT match — hence this override.
     */
    protected function getTableId(): string
    {
        return 'prospect_criteria';
    }

    protected function getEntityName(): string
    {
        return 'critère';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer ce critère ?',
            'deleteSuccess'  => 'Critère supprimé avec succès',
        ];
    }
}
