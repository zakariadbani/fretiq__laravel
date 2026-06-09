<?php

namespace App\DataTables\Backend;

use App\DataTables\BackendDataTable;
use App\Models\User;
use Illuminate\Http\Request;

class UsersDataTable extends BackendDataTable
{
    /**
     * Skip the base addColumn('action') closure which calls $model->getName().
     * User has no getName() (no Validator trait). We add our own action column
     * via editColumn('action') in createEditColumns() below.
     */
    protected $skipDefaultAction = true;

    // HTML-returning columns must be raw => true to avoid escaped output.
    protected $columns = [
        'user' => [
            'title'      => 'Utilisateur',
            'orderable'  => true,
            'searchable' => true,
            'raw'        => true,
        ],
        'role' => [
            'title'      => 'Rôle',
            'orderable'  => false,
            'searchable' => false,
        ],
        'status' => [
            'title'      => 'Statut',
            'orderable'  => false,
            'searchable' => false,
            'raw'        => true,
        ],
        'last_login_at' => [
            'title'      => 'Dernière connexion',
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

    protected $table_filters = [];

    public function __construct(User $model, Request $request)
    {
        parent::__construct($model, $request);
    }

    /**
     * Return all users except superadmin rows.
     */
    public function query()
    {
        return $this->currentModel
            ->newQuery()
            ->with('roles')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'superadmin'));
    }

    /**
     * Build rich columns: avatar/name cell, role label, is_active badge,
     * last login relative time, and action buttons.
     */
    protected function createEditColumns(): void
    {
        // ── User cell: avatar initial + name + email ─────────────────────────
        $this->datatables->editColumn('user', function (User $row) {
            $initial  = e(mb_strtoupper(mb_substr($row->name ?? '', 0, 1)));
            $nameText = e($row->name ?? '');
            $email    = e($row->email ?? '');
            $viewUrl  = e(route('admin.users.view', $row->id));

            return '
<div class="d-flex align-items-center">
    <div class="symbol symbol-circle symbol-50px overflow-hidden me-3">
        <div class="symbol-label fs-3 bg-light-primary text-primary fw-bold">' . $initial . '</div>
    </div>
    <div class="d-flex flex-column">
        <a href="' . $viewUrl . '" class="text-gray-800 text-hover-primary mb-1 fw-bold">' . $nameText . '</a>
        <span class="text-muted fs-7">' . $email . '</span>
    </div>
</div>';
        });

        // ── Role label ───────────────────────────────────────────────────────
        $this->datatables->editColumn('role', function (User $row) {
            $roleName = $row->roles->first()?->name ?? '—';
            return ucfirst($roleName);
        });

        // ── is_active badge ──────────────────────────────────────────────────
        $this->datatables->editColumn('status', function (User $row) {
            // is_active defaults to true (cast boolean); NULL also treated as active.
            $active = $row->is_active !== false;
            if ($active) {
                return '<span class="badge badge-light-success">Actif</span>';
            }
            return '<span class="badge badge-light-danger">Inactif</span>';
        });

        // ── Last login ───────────────────────────────────────────────────────
        $this->datatables->editColumn('last_login_at', function (User $row) {
            if ($row->last_login_at) {
                return $row->last_login_at->diffForHumans();
            }
            return '<span class="text-muted">—</span>';
        });

        // ── Created at ───────────────────────────────────────────────────────
        $this->datatables->editColumn('created_at', function (User $row) {
            return $row->created_at?->format('d/m/Y H:i') ?? '—';
        });

        // ── Action buttons ───────────────────────────────────────────────────
        $this->datatables->editColumn('action', function (User $row) {
            return view(
                'backend.contents.users.crud.columns._actions',
                compact('row')
            )->render();
        });
    }

    protected function getEntityName(): string
    {
        return 'utilisateur';
    }

    protected function getMessages(): array
    {
        return [
            'deleteConfirm' => 'Êtes-vous sûr de vouloir supprimer cet utilisateur ?',
            'deleteSuccess'  => 'Utilisateur supprimé avec succès',
        ];
    }
}
