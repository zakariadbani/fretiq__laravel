<?php

namespace App\Http\Controllers\Backend;

use App\DataTables\Backend\UsersDataTable;
use App\Http\Controllers\Traits\Crudable;
use App\Http\Controllers\Traits\Datatableable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * UserController — manages the /admin/users CRUD module.
 *
 * Architecture note (IMPORTANT — do NOT revert):
 *   User has NO Validator trait. Crudable::store()/update() call $model->validator()
 *   which would fatal-error here. store()/update()/delete() are FULLY OVERRIDDEN with
 *   explicit $request->validate([...]) + explicit ->input() per field. The Crudable
 *   trait is kept for create()/edit()/view() resolution only.
 *
 * Security guards implemented per plan:
 *   - Block creating/editing a user with role 'superadmin' (not_in:superadmin validation)
 *   - Block editing a superadmin (abort 403)
 *   - Block self-role-change and self-deactivation
 *   - Block self-deletion
 *   - Last-admin lockout: cannot delete the last active admin/superadmin
 *   - is_active gate: deactivated users rejected at login (LoginRequest)
 */
class UserController extends BackendController
{
    use Crudable, Datatableable;

    public function __construct(Request $request, User $model, UsersDataTable $dataTable)
    {
        parent::__construct($request, $model, $dataTable);

        $this->middleware('permission:view users')->only(['index', 'view']);
        $this->middleware('permission:create users')->only(['create', 'store']);
        $this->middleware('permission:edit users')->only(['edit', 'update']);
        $this->middleware('permission:delete users')->only(['delete']);

        $this->listTitle = 'Utilisateurs';
        $this->title     = 'name';

        $this->bootResource(new BackendResource(
            modelClass:       User::class,
            modelName:        'users',
            dataTableClass:   UsersDataTable::class,
            permissionEntity: 'users',
            prefixName:       'admin',
            titleField:       'name',
        ));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // READ (index / create / edit / view)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * List users (superadmin rows excluded by DataTable query).
     */
    public function index()
    {
        $this->authorize('view users');

        return $this->currentDataTable->render(
            'backend.contents.users.crud.index',
            ['listTitle' => $this->listTitle]
        );
    }

    /**
     * Show create form.
     */
    public function create()
    {
        $this->authorize('create users');

        $roles = Role::where('name', '!=', 'superadmin')->orderBy('name')->get();

        return view('backend.contents.users.crud.form', [
            'title'  => 'Ajouter un utilisateur',
            'route'  => route('admin.users.store'),
            'method' => 'post',
            'model'  => new User(),
            'roles'  => $roles,
        ]);
    }

    /**
     * Show edit form.
     */
    public function edit($id)
    {
        $this->authorize('edit users');

        $user = User::with('roles')->findOrFail($id);

        // Block editing superadmin users
        if ($user->hasRole('superadmin')) {
            abort(403, 'Les comptes superadmin ne peuvent pas être modifiés.');
        }

        $roles = Role::where('name', '!=', 'superadmin')->orderBy('name')->get();

        return view('backend.contents.users.crud.form', [
            'title'  => 'Modifier ' . $user->name,
            'route'  => route('admin.users.update', $id),
            'method' => 'put',
            'model'  => $user,
            'roles'  => $roles,
        ]);
    }

    /**
     * Show read-only user detail.
     */
    public function view($id)
    {
        $this->authorize('view users');

        $model = User::with('roles')->findOrFail($id);

        return view('backend.contents.users.crud.view', compact('model'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // WRITE (store / update / delete) — fully explicit, NO Crudable trait path
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Store a new user.
     * Returns 200 JSON on success or 422 JSON on validation failure.
     */
    public function store(Request $request)
    {
        $this->authorize('create users');

        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'role'                  => 'required|string|exists:roles,name|not_in:superadmin',
            'is_active'             => 'nullable|boolean',
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name'      => $request->input('name'),
                'email'     => $request->input('email'),
                'password'  => Hash::make($request->input('password')),
                'is_active' => $request->boolean('is_active', true),
            ]);

            // Auto-verify email — admin-created accounts are trusted
            $user->email_verified_at = now();
            $user->save();

            $user->assignRole($request->input('role'));

            DB::commit();

            session()->flash('success', 'Utilisateur créé avec succès.');

            return response()->json([
                'message'  => 'success',
                'model'    => $user,
                'redirect' => route('admin.users.index'),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Une erreur est survenue.',
                'errors'  => ['error' => [$e->getMessage()]],
            ], 422);
        }
    }

    /**
     * Update an existing user.
     *
     * Security guards:
     *   - Block editing superadmin
     *   - Block self-role-change
     *   - Block self-deactivation
     */
    public function update(Request $request, $id)
    {
        $this->authorize('edit users');

        $user = User::with('roles')->findOrFail($id);

        // Hard block: superadmin accounts are immutable
        if ($user->hasRole('superadmin')) {
            abort(403, 'Les comptes superadmin ne peuvent pas être modifiés.');
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email,' . $user->id,
            'role'     => 'required|string|exists:roles,name|not_in:superadmin',
            'is_active' => 'nullable|boolean',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        $isSelf   = (int) $id === Auth::id();
        $newRole  = $request->input('role');
        $currRole = $user->roles->first()?->name;

        // Finding #4: default to current state when is_active is absent from the
        // request (an unchecked HTML checkbox sends nothing, not "0").
        $newIsActive = $request->has('is_active')
            ? $request->boolean('is_active')
            : (bool) $user->is_active;

        // Block self-role-change
        if ($isSelf && $newRole !== $currRole) {
            return response()->json([
                'message' => 'Vous ne pouvez pas modifier votre propre rôle.',
                'errors'  => ['role' => ['Vous ne pouvez pas modifier votre propre rôle.']],
            ], 422);
        }

        // Block self-deactivation
        if ($isSelf && $newIsActive === false) {
            return response()->json([
                'message' => 'Vous ne pouvez pas désactiver votre propre compte.',
                'errors'  => ['is_active' => ['Vous ne pouvez pas désactiver votre propre compte.']],
            ], 422);
        }

        // Finding #3: block deactivating / demoting the last active admin/superadmin.
        $isCurrentlyAdmin = $user->hasAnyRole(['admin', 'superadmin']);
        $wouldDeactivate  = $isCurrentlyAdmin && $newIsActive === false;
        $wouldDemote      = $isCurrentlyAdmin && !in_array($newRole, ['admin', 'superadmin'], true);

        if ($wouldDeactivate || $wouldDemote) {
            $activeAdminCount = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'superadmin']))
                ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
                ->count();

            if ($activeAdminCount <= 1) {
                $reason = $wouldDeactivate
                    ? 'désactiver'
                    : 'retirer le rôle de';
                return response()->json([
                    'message' => 'Impossible de ' . $reason . ' ce compte : il s\'agit du dernier administrateur actif.',
                    'errors'  => ['is_active' => ['Opération refusée — dernier administrateur actif.']],
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $user->update([
                'name'      => $request->input('name'),
                'email'     => $request->input('email'),
                'is_active' => $newIsActive,
            ]);

            // Sync role (only if not self-editing — already blocked above)
            $user->syncRoles([$newRole]);

            // Optional password change
            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->input('password'))]);
            }

            DB::commit();

            session()->flash('success', 'Utilisateur mis à jour avec succès.');

            return response()->json([
                'message'  => 'success',
                'model'    => $user,
                'redirect' => route('admin.users.edit', $id),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Une erreur est survenue.',
                'errors'  => ['error' => [$e->getMessage()]],
            ], 422);
        }
    }

    /**
     * Delete a user.
     *
     * Security guards:
     *   - Block self-deletion
     *   - Block deletion of last active admin/superadmin
     */
    public function delete($id)
    {
        $this->authorize('delete users');

        // Block self-deletion
        if ((int) $id === Auth::id()) {
            return response()->json([
                'success' => false,
                'msg'     => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], 403);
        }

        $user = User::with('roles')->findOrFail($id);

        // Last-admin lockout: cannot delete the last active admin or superadmin
        if ($user->hasAnyRole(['admin', 'superadmin'])) {
            $activeAdminCount = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'superadmin']))
                ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
                ->count();

            if ($activeAdminCount <= 1) {
                return response()->json([
                    'success' => false,
                    'msg'     => 'Impossible de supprimer le dernier administrateur actif.',
                ], 403);
            }
        }

        $result = $user->delete();

        return response()->json([
            'success' => $result,
            'msg'     => $result ? 'Utilisateur supprimé avec succès.' : 'Impossible de supprimer cet utilisateur.',
        ]);
    }
}
