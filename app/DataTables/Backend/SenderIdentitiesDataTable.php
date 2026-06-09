<?php

declare(strict_types=1);

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\SenderIdentity;
use Illuminate\Http\Request;

class SenderIdentitiesDataTable extends BackendDataTable
{
    protected $columns = [
        'name' => [
            'title'      => 'Nom',
            'orderable'  => true,
            'searchable' => true,
        ],
        'email' => [
            'title'      => 'Email',
            'orderable'  => true,
            'searchable' => true,
        ],
        'reply_to' => [
            'title'      => 'Répondre à',
            'orderable'  => false,
            'searchable' => false,
        ],
        'is_default' => [
            'title'      => 'Par défaut',
            'orderable'  => true,
            'searchable' => false,
            'switch'     => true,
            'typetoggle' => 'status',
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

    public function __construct(SenderIdentity $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Get query source for DataTable.
     */
    public function query()
    {
        return $this->currentModel->newQuery();
    }

    /**
     * Override the action column to avoid calling getName() which requires
     * the Validator trait (SenderIdentity does not use it).
     */
    protected function createEditColumns(): void
    {
        $this->datatables->editColumn('action', function (SenderIdentity $row) {
            $user = auth()->user();
            $id   = (int) $row->id;
            $html = '<div class="d-flex justify-content-end flex-shrink-0">';

            if ($user?->can('edit sender_identities')) {
                $html .= '<a href="' . route('admin.sender_identities.edit', $id) . '"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"'
                    . ' data-bs-toggle="tooltip" title="Modifier">'
                    . '<i class="bi bi-pencil fs-4"></i>'
                    . '</a>';
            }

            if ($user?->can('view sender_identities')) {
                $html .= '<a href="' . route('admin.sender_identities.view', $id) . '"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-primary btn-sm me-1"'
                    . ' data-bs-toggle="tooltip" title="Voir">'
                    . '<i class="bi bi-eye fs-4"></i>'
                    . '</a>';
            }

            if ($user?->can('delete sender_identities')) {
                $html .= '<a href="javascript:void(0);"'
                    . ' class="btn btn-icon btn-bg-light btn-active-color-danger btn-sm delete-btn"'
                    . ' data-id="' . $id . '"'
                    . ' data-url="' . route('admin.sender_identities.delete', $id) . '"'
                    . ' data-bs-toggle="tooltip" title="Supprimer">'
                    . '<i class="bi bi-trash fs-4"></i>'
                    . '</a>';
            }

            $html .= '</div>';
            return $html;
        });
    }

    protected function getEntityName(): string
    {
        return 'identité';
    }

    protected function getMessages(): array
    {
        return [
            'toggleSuccess' => 'Statut mis à jour avec succès',
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer cette identité ?',
            'deleteSuccess' => 'Identité supprimée avec succès',
        ];
    }

    protected function getTableId(): string
    {
        return 'senderidentity';
    }
}
