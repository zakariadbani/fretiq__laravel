<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Package;
use Illuminate\Http\Request;

/**
 * DataTable for the Packages CRUD module.
 *
 * Columns: name, daily_credits, monthly_credits, daily_contact_credits, price_monthly, is_active (toggle), sort_order, created_at.
 * Row actions: view / edit / delete — all gated @can('manage packages').
 */
class PackagesDataTable extends BackendDataTable
{
    /**
     * Packages use the single keyword permission 'manage packages' (superadmin-only)
     * for every action - menu, controller middleware, and these row actions. The standard
     * "{action} packages" permissions do not exist. See PermissionsSeeder.
     */
    protected $actionPermission = 'manage packages';

    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
            'raw'        => true,
        ],
        'daily_credits' => [
            'title'      => 'Crédits / jour',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'monthly_credits' => [
            'title'      => 'Crédits / mois',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'daily_contact_credits' => [
            'title'      => 'Crédits contacts / j',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'price_monthly' => [
            'title'      => 'Prix / mois',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'is_active' => [
            'title'      => 'Actif',
            'orderable'  => false,
            'searchable' => false,
            'switch'     => true,
            'typetoggle' => 'status',
            'raw'        => true,
        ],
        'sort_order' => [
            'title'      => 'Ordre',
            'orderable'  => true,
            'searchable' => false,
        ],
        'created_at' => [
            'title'      => 'Créé le',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
    ];

    /**
     * Filters: name text search; no enum filters (no status enum for packages).
     */
    protected $table_filters = [
        'name' => [
            'type'      => 'text',
            'filterKey' => 'name',
            'title'     => 'Nom',
        ],
    ];

    public function __construct(Package $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Simple query — no relationships to eager-load.
     */
    public function query()
    {
        return $this->currentModel->newQuery();
    }

    /**
     * Build rich HTML cells for raw columns.
     * All dynamic model values are wrapped in e() to prevent XSS.
     */
    protected function createEditColumns(): void
    {
        // ── Name cell: initials avatar + name link ─────────────────────────────
        $this->datatables->editColumn('name', function (Package $row) {
            $initial  = e(mb_strtoupper(mb_substr($row->name ?? '', 0, 1)));
            $nameText = e($row->name ?? '');
            $viewUrl  = e(route('admin.packages.view', $row->id));

            return '
<div class="d-flex align-items-center">
    <div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
        <div class="symbol-label fs-3 bg-light-primary text-primary fw-bold">' . $initial . '</div>
    </div>
    <div class="d-flex flex-column">
        <a href="' . $viewUrl . '" class="text-gray-800 text-hover-primary mb-1 fw-bold">' . $nameText . '</a>
    </div>
</div>';
        });

        // ── daily_credits: "Illimité" badge when null, else integer ───────────
        $this->datatables->editColumn('daily_credits', function (Package $row) {
            if ($row->daily_credits === null) {
                return '<span class="badge badge-light-success">Illimité</span>';
            }
            return '<span class="fw-semibold">' . (int) $row->daily_credits . '</span>';
        });

        // ── monthly_credits: "Illimité" badge when null, else integer ─────────
        $this->datatables->editColumn('monthly_credits', function (Package $row) {
            if ($row->monthly_credits === null) {
                return '<span class="badge badge-light-success">Illimité</span>';
            }
            return '<span class="fw-semibold">' . (int) $row->monthly_credits . '</span>';
        });

        // ── daily_contact_credits: "Illimité" badge when null, else integer ───
        $this->datatables->editColumn('daily_contact_credits', function (Package $row) {
            if ($row->daily_contact_credits === null) {
                return '<span class="badge badge-light-success">Illimité</span>';
            }
            return '<span class="fw-semibold">' . (int) $row->daily_contact_credits . '</span>';
        });

        // ── price_monthly: "—" when null, else formatted € ────────────────────
        $this->datatables->editColumn('price_monthly', function (Package $row) {
            if ($row->price_monthly === null) {
                return '<span class="text-muted">—</span>';
            }
            return '<span class="fw-semibold">' . number_format((float) $row->price_monthly, 2, ',', ' ') . ' €</span>';
        });

        // ── created_at: formatted date ────────────────────────────────────────
        $this->datatables->editColumn('created_at', function (Package $row) {
            return $row->created_at ? e($row->created_at->format('d/m/Y')) : '<span class="text-muted">—</span>';
        });
    }

    protected function getEntityName(): string
    {
        return 'pack';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer ce pack ?',
            'deleteSuccess'  => 'Pack supprimé avec succès',
        ];
    }
}
