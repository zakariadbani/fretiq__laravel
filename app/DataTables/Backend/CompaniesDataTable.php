<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Company;
use Illuminate\Http\Request;

class CompaniesDataTable extends BackendDataTable
{
    // Column order mirrors prototype/companies.html.
    // 'raw' => true is required on any column whose editColumn returns HTML
    // (GlobalDataTable::dataTable() only adds a column to rawColumns when raw=true).
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
            'raw'        => true,
        ],
        'domain' => [
            'title'      => 'Domaine',
            'orderable'  => true,
            'searchable' => true,
        ],
        'sector' => [
            'title'      => 'Secteur',
            'orderable'  => true,
            'searchable' => true,
        ],
        'country' => [
            'title'      => 'Pays',
            'orderable'  => true,
            'searchable' => true,
            'raw'        => true,
        ],
        'relationship' => [
            'title'      => 'Relation',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'ai_score' => [
            'title'      => 'Score IA',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'qualification_status' => [
            'title'      => 'Statut qualif.',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'contacts_count' => [
            'title'      => '# Contacts',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        // 'created_at' intentionally removed from visible columns (prototype has none).
        // GlobalDataTable still registers editColumn('created_at') but it is a no-op
        // when the column is not visible — verified safe on yajra v11.1.5.
    ];

    protected $table_filters = [
        'relationship' => [
            'type'      => 'select_enum',
            'filterKey' => 'relationship',
            'configKey' => 'company_relationships',
            'title'     => 'Relation',
        ],
        'qualification_status' => [
            'type'      => 'select_enum',
            'filterKey' => 'qualification_status',
            'configKey' => 'company_qualification_statuses',
            'title'     => 'Statut qualification',
        ],
        'source' => [
            'type'      => 'select_enum',
            'filterKey' => 'source',
            'configKey' => 'company_sources',
            'title'     => 'Source',
        ],
        'sector' => [
            'type'      => 'text',
            'filterKey' => 'sector',
            'title'     => 'Secteur',
        ],
    ];

    public function __construct(Company $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Get query source for DataTable.
     */
    public function query()
    {
        return $this->currentModel->newQuery()->withCount('contacts');
    }

    /**
     * Build rich columns: avatar name cell, country badge, AI-score progress bar,
     * relationship/qualification badges, contacts count.
     *
     * NOTE: every dynamic fragment is e()-escaped to prevent XSS.
     * The DataTable version (PHP closure) and the Blade score component
     * (resources/views/components/companies/score.blade.php) are intentionally
     * kept visually identical — update both if the design changes.
     */
    protected function createEditColumns(): void
    {
        $relationships         = config('global.data.company_relationships', []);
        $qualificationStatuses = config('global.data.company_qualification_statuses', []);

        // ── Name cell: avatar initial + name link + sector subtext ──────────
        $this->datatables->editColumn('name', function (Company $row) {
            $initial  = e(mb_strtoupper(mb_substr($row->name ?? '', 0, 1)));
            $nameText = e($row->name ?? '');
            $viewUrl  = e(route('admin.companies.view', $row->id));
            $sector   = e($row->sector ?? '');

            return '
<div class="d-flex align-items-center">
    <div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
        <div class="symbol-label fs-3 bg-light-primary text-primary fw-bold">' . $initial . '</div>
    </div>
    <div class="d-flex flex-column">
        <a href="' . $viewUrl . '" class="text-gray-800 text-hover-primary mb-1 fw-bold">' . $nameText . '</a>
        <span class="text-muted fs-7">' . $sector . '</span>
    </div>
</div>';
        });

        // ── Country: ISO code badge or muted dash ───────────────────────────
        $this->datatables->editColumn('country', function (Company $row) {
            if (empty($row->country)) {
                return '<span class="text-muted">—</span>';
            }
            return '<span class="fw-semibold">' . e(strtoupper($row->country)) . '</span>';
        });

        // ── Relationship badge ───────────────────────────────────────────────
        $this->datatables->editColumn('relationship', function (Company $row) use ($relationships) {
            if (empty($row->relationship)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $relationships[$row->relationship] ?? [];
            $label = e($cfg['label'] ?? $row->relationship);
            $color = e($cfg['color'] ?? 'secondary');

            return '<span class="badge badge-light-' . $color . '">' . $label . '</span>';
        });

        // ── AI score: number + progress bar ─────────────────────────────────
        // Threshold: ≥70 success, ≥40 warning, else danger.
        // Keep in sync with resources/views/components/companies/score.blade.php.
        $this->datatables->editColumn('ai_score', function (Company $row) {
            if ($row->ai_score === null) {
                return '<span class="text-muted">—</span>';
            }
            $score = (int) $row->ai_score;
            if ($score >= 70) {
                $textClass  = 'text-success';
                $barClass   = 'bg-success';
                $trackClass = 'bg-light-success';
            } elseif ($score >= 40) {
                $textClass  = 'text-warning';
                $barClass   = 'bg-warning';
                $trackClass = 'bg-light-warning';
            } else {
                $textClass  = 'text-danger';
                $barClass   = 'bg-danger';
                $trackClass = 'bg-light-danger';
            }

            return '
<div class="d-flex align-items-center">
    <span class="fw-bold me-2 ' . $textClass . '">' . $score . '</span>
    <div class="progress h-6px w-60px ' . $trackClass . '">
        <div class="progress-bar ' . $barClass . '" role="progressbar"
             style="width: ' . $score . '%"
             aria-valuenow="' . $score . '" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
</div>';
        });

        // ── Qualification status badge ───────────────────────────────────────
        $this->datatables->editColumn('qualification_status', function (Company $row) use ($qualificationStatuses) {
            if (empty($row->qualification_status)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $qualificationStatuses[$row->qualification_status] ?? [];
            $label = e($cfg['label'] ?? $row->qualification_status);
            $color = e($cfg['color'] ?? 'secondary');

            return '<span class="badge badge-light-' . $color . '">' . $label . '</span>';
        });

        // ── Contacts count badge ─────────────────────────────────────────────
        $this->datatables->editColumn('contacts_count', function (Company $row) {
            $count = (int) ($row->contacts_count ?? 0);
            return '<span class="badge badge-light-primary">' . $count . '</span>';
        });
    }

    protected function getEntityName(): string
    {
        return 'entreprise';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer cette entreprise ?',
            'deleteSuccess'  => 'Entreprise supprimée avec succès',
        ];
    }
}
