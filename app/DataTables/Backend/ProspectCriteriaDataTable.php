<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\ProspectCriteria;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Http\Request;

class ProspectCriteriaDataTable extends BackendDataTable
{
    /**
     * Maximum number of sector badges rendered inline in the "Secteurs" cell.
     *
     * Badges are inline-block — atomic boxes that cannot break internally — so the
     * column's min-content floor is its widest single badge and its max-content
     * demand is the sum of all of them. Rendering every sector put
     * "Machines & Équipements industriels" (~200px) on that floor, and the combined
     * demand pushed DataTables Responsive past the available width at both 1440px
     * and 1280px, collapsing BOTH "Secteurs" and "Pays" behind the "+" row expander.
     * Capping at 2 drops the widest badge out of the DOM and cuts the column's width
     * demand; the remainder is surfaced as a "+N" chip whose title lists the names.
     */
    protected const SECTOR_BADGE_LIMIT = 2;

    /**
     * Deliberate column cut.
     *
     * The listing is structurally over-subscribed: inside the admin shell the
     * .table-responsive container measures ~1055px at a 1440px viewport (sidebar
     * plus card padding consume the rest), while the full visible column set
     * demanded ~1054px — effectively zero slack. DataTables Responsive resolved
     * that by hiding whichever columns lost the priority tie, so "Pays" and
     * "Requêtes/j" disappeared at 1440px and "Secteurs" as well at 1280px.
     * Priority tuning only changes WHICH column is sacrificed, never whether one
     * is, so the column set is cut here instead of letting Responsive choose.
     *
     * Visible set: Nom, Secteurs, Pays, Requêtes/j, Actif, Dern. découverte, Action.
     * Hidden here: Entreprises, Contacts, Automat., Créé le (plus ID, see
     * getColumns()). All remain in the payload — hidden, not removed — so export
     * and ordering keep working and re-enabling one is a one-line change.
     */
    protected $columns = [
        // 'raw' => true is required: createEditColumns() wraps the name in a
        // .min-w-200px div. Without it yajra escapes the markup and the wrapper
        // renders as literal text in the cell.
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
            'raw'        => true,
            'priority'   => 1,
        ],
        'sectors' => [
            'title'      => 'Secteurs',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
            'priority'   => 4,
        ],
        'countries' => [
            'title'      => 'Pays',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
            'priority'   => 5,
        ],
        // Hidden: see the "deliberate column cut" note above $columns.
        // visible(false) rather than removal keeps the withCount() aggregate
        // available to the export and to any future re-enable.
        'companies_count' => [
            'title'      => 'Entreprises',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
            'priority'   => 5,
            'visible'    => false,
        ],
        'contacts_count' => [
            'title'      => 'Contacts',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
            'priority'   => 5,
            'visible'    => false,
        ],
        'daily_limit' => [
            'title'      => 'Requêtes/j',
            'orderable'  => true,
            'searchable' => false,
            'priority'   => 6,
        ],
        'automation' => [
            'title'      => 'Automat.',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
            'priority'   => 3,
            'visible'    => false,
        ],
        'is_active' => [
            'title'      => 'Actif',
            'orderable'  => true,
            'searchable' => false,
            'switch'     => true,
            'typetoggle' => 'status',
            'raw'        => true,
            'priority'   => 2,
        ],
        'last_discovery' => [
            'title'      => 'Dern. découverte',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
            'priority'   => 3,
        ],
        // 'created_at' hidden rather than removed (CompaniesDataTable drops it outright).
        // Keeping the key registered leaves GlobalDataTable's editColumn('created_at')
        // and the export intact; visible(false) only pulls it out of the rendered table.
        'created_at' => [
            'title'      => 'Créé le',
            'orderable'  => true,
            'searchable' => false,
            'priority'   => 8,
            'visible'    => false,
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
     * Disable scrollX for this table only.
     *
     * scrollX fights DataTables Responsive: with a horizontal scroll container
     * active, Responsive under-measures available width and collapses columns
     * that comfortably fit (at 1440px this crushed "Nom" to ~90px and hid
     * "Dern. découverte" behind a "+" expander). The blade already wraps this
     * table in .table-responsive, so horizontal overflow is still handled.
     *
     * Builder::parameters() array_merges into the existing attributes, so this
     * overrides only scrollX and leaves searchDelay, drawCallback, initComplete,
     * buttons and language from GlobalDataTable::html() untouched.
     */
    public function html()
    {
        return parent::html()->parameters(['scrollX' => false]);
    }

    /**
     * Hide the ID column for this table only.
     *
     * GlobalDataTable::getColumns() unconditionally prepends
     * Column::make('id')->title('ID')->responsivePriority(1). "id" is not a key
     * in $columns, so the 'visible' => false flag that the parent honours for
     * declared columns cannot reach it.
     *
     * Two ways to hide it from a subclass: a JS columnDefs entry in html()
     * targeting index 0, or this. columnDefs targets by position, which silently
     * hides the wrong column the moment a column is added ahead of it, and it
     * splits hiding across two mechanisms (PHP visible flags + a JS override).
     * Taking the parent's already-built list and flipping the flag on the column
     * whose data key is 'id' matches by identity instead of position, reuses the
     * parent's loop rather than restating it, and keeps Column::visible(false)
     * as the single hiding mechanism for this table.
     *
     * The column is hidden, not dropped, so GlobalDataTable::html()'s orderBy(0)
     * still resolves to `id` — DataTables sorts on hidden columns fine.
     */
    protected function getColumns()
    {
        $columns = parent::getColumns();

        // Column objects are held by handle, so mutating in place updates the array.
        foreach ($columns as $column) {
            if (($column['data'] ?? null) === 'id') {
                $column->visible(false);
                break;
            }
        }

        return $columns;
    }

    /**
     * Render sectors as badge spans (escaped) and countries as escaped comma list.
     * Appends a "Lancer la découverte" button to the standard action column.
     * The is_active switch column is handled automatically by the base class.
     */
    protected function createEditColumns(): void
    {
        // Width floor for the "Nom" column.
        //
        // GlobalDataTable::html() sets autoWidth(false), so DataTables assigns no
        // explicit column widths and the browser's auto table-layout distributes
        // space by min-content demand. The "Secteurs" cells render inline-block
        // .badge spans — atomic boxes that cannot break internally — so their
        // min-content floor is the widest badge (~200px). "Nom" is plain text whose
        // longest unbreakable token is short, so auto-layout squeezed it to near
        // min-content (~102px at 1440px, wrapping long names over 7 lines).
        // The min-w-200px wrapper gives the cell a floor auto-layout must respect.
        //
        // e() is mandatory here: criterion names are user-supplied and the column
        // is registered raw (see $columns['name']['raw']), so yajra no longer escapes.
        $this->datatables->editColumn('name', function (ProspectCriteria $row) {
            return '<div class="min-w-200px">' . e($row->name ?? '') . '</div>';
        });

        $this->datatables->editColumn('sectors', function (ProspectCriteria $row) {
            $sectors = is_array($row->sectors) ? $row->sectors : [];

            // Normalise before slicing so a blank entry cannot consume one of the
            // visible slots or render as an empty badge box.
            $sectors = array_values(array_filter(
                array_map(static fn($s) => trim((string) $s), $sectors),
                static fn(string $s) => $s !== ''
            ));

            if (empty($sectors)) {
                return '<span class="text-muted">—</span>';
            }

            $visible = array_slice($sectors, 0, self::SECTOR_BADGE_LIMIT);
            $hidden  = array_slice($sectors, self::SECTOR_BADGE_LIMIT);

            // e() is mandatory on every value emitted here: sector names are
            // user-supplied and this column is registered raw (see
            // $columns['sectors']['raw']), so yajra no longer escapes. Laravel's e()
            // uses ENT_QUOTES, which also makes the value safe to interpolate into
            // the double-quoted title attribute of the counter chip below.
            $html = '';
            foreach ($visible as $sector) {
                $html .= '<span class="badge badge-light-primary me-1">' . e($sector) . '</span>';
            }

            if ($hidden !== []) {
                // Deliberately lighter than the sector badges so it reads as a
                // control, not as a sector. Plain title rather than
                // data-bs-toggle="tooltip": native, and needs no JS re-init after a
                // DataTables redraw swaps the row out.
                $html .= '<span class="badge badge-light text-muted"'
                    . ' title="' . e(implode(', ', $hidden)) . '">'
                    . '+' . count($hidden)
                    . '</span>';
            }

            return $html;
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
