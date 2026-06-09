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
            'title'      => '≈ Contacts',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'last_built_at' => [
            'title'      => 'Dernière construction',
            'orderable'  => true,
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
     * Eagerly computes the scope-aware contact count via a correlated subquery
     * to avoid the N+1 produced by calling contactsCount() per row.
     * The subquery mirrors the logic in Segment::contactsCount():
     *   - scope 'client'   → contacts joined to companies where relationship = 'client'
     *   - scope 'prospect' → contacts joined to companies where relationship = 'prospect'
     *   - scope 'mixed'    → all contacts joined to companies (no relationship filter)
     */
    public function query()
    {
        return $this->currentModel->newQuery()->selectRaw(
            'segments.*,
            (
                SELECT COUNT(*)
                FROM contacts
                INNER JOIN companies ON contacts.company_id = companies.id
                WHERE contacts.deleted_at IS NULL
                AND (
                    segments.scope = \'mixed\'
                    OR (segments.scope = \'client\'   AND companies.relationship = \'client\')
                    OR (segments.scope = \'prospect\' AND companies.relationship = \'prospect\')
                )
            ) AS computed_contacts_count'
        );
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
            $count = (int) ($row->computed_contacts_count ?? 0);
            return '<span class="badge badge-light-primary">' . $count . '</span>';
        });

        $this->datatables->editColumn('last_built_at', function (Segment $row) {
            return $row->last_built_at ? $row->last_built_at->format('d/m/Y H:i') : '<span class="text-muted">—</span>';
        });
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
