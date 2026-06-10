<?php

declare(strict_types=1);

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\Segment;
use Illuminate\Http\Request;

class SegmentsDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'scope' => [
            'title'      => 'Portée',
            'orderable'  => true,
            'searchable' => false,
            'raw'        => true,
        ],
        'contacts_count' => [
            'title'      => 'Destinataires',
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
        'scope' => [
            'type'      => 'select_enum',
            'filterKey' => 'scope',
            'configKey' => 'segment_scopes',
            'title'     => 'Portée',
        ],
    ];

    public function __construct(Segment $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Get query source for DataTable.
     *
     * Plain query — contacts_count is now computed per-row via contactsCount()
     * (full compliance pipeline) in createEditColumns(). The old correlated
     * subquery only applied scope-level counting and diverged from send reality.
     */
    public function query()
    {
        return $this->currentModel->newQuery();
    }

    /**
     * Render scope as a coloured badge and contacts_count as a badge.
     */
    protected function createEditColumns(): void
    {
        $scopes = config('global.data.segment_scopes', []);

        $this->datatables->editColumn('scope', function (Segment $row) use ($scopes) {
            if (empty($row->scope)) {
                return '<span class="text-muted">—</span>';
            }
            $cfg   = $scopes[$row->scope] ?? [];
            $label = $cfg['label'] ?? $row->scope;
            $color = $cfg['color'] ?? 'secondary';

            return '<span class="badge badge-light-' . e($color) . '">' . e($label) . '</span>';
        });

        $this->datatables->editColumn('contacts_count', function (Segment $row) {
            // Coût: pipeline complet par ligne — acceptable à l'échelle actuelle;
            // revoir avec cache (last_built_at) au-delà de 50 segments / 50k contacts (D5/D12).
            $count = $row->contactsCount();
            return '<span class="badge badge-light-primary">' . $count . '</span>';
        });
    }

    /**
     * Override html() to inject a French emptyTable message.
     * The language.emptyTable key is the DataTables option that replaces
     * "No data available in table" when the server returns zero rows.
     * parent::html() already sets scrollX / drawCallback / buttons via parameters();
     * this call merges 'language' on top of those.
     */
    public function html()
    {
        return parent::html()->parameters([
            'language' => [
                'emptyTable' => 'Aucun segment — créez votre premier segment pour cibler vos campagnes.',
            ],
        ]);
    }

    protected function getEntityName(): string
    {
        return 'segment';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer ce segment ?',
            'deleteSuccess'  => 'Segment supprimé avec succès',
        ];
    }
}
