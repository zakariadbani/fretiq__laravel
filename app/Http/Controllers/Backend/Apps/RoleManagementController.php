<?php

namespace App\Http\Controllers\Backend\Apps;

use App\DataTables\UsersAssignedRoleDataTable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * RoleManagementController — AJAX-backed role CRUD at /admin/user-management/roles.
 *
 * Security guards:
 *   - Middleware: permission:manage roles (all actions)
 *   - System denylist: cannot rename/delete roles {superadmin, admin, commercial}
 *   - Users-assigned guard: cannot delete a role that has active users
 *   - Only superadmin may create/edit roles containing privileged keyword permissions
 */
class RoleManagementController extends Controller
{
    /**
     * System roles that may never be renamed or deleted.
     */
    private const SYSTEM_ROLES = ['superadmin', 'admin', 'commercial'];

    /**
     * Permission keywords that are restricted to superadmin-only assignment.
     * Aligned with PermissionManagementController::SYSTEM_PERMISSIONS — any
     * permission that touches user/role/access management is privileged so that
     * a "manage roles" admin cannot escalate the commercial role into user CRUD.
     */
    private const PRIVILEGED_PERMS = [
        'backend.access',
        'manage roles',
        'manage permissions',
        'view users',
        'create users',
        'edit users',
        'delete users',
    ];

    public function __construct()
    {
        $this->middleware('permission:manage roles');
    }

    // ── READ ──────────────────────────────────────────────────────────────────

    public function index()
    {
        return view('backend.contents.users.roles.list');
    }

    /**
     * Show role detail + users assigned to this role (DataTable).
     */
    public function show(Role $role, UsersAssignedRoleDataTable $dataTable)
    {
        return $dataTable->with('role', $role)
            ->render('backend.contents.users.roles.show', compact('role'));
    }

    /**
     * AJAX: return permissions assigned to a role.
     * Used by the edit modal to pre-check checkboxes.
     */
    public function permissions(Role $role)
    {
        return response()->json([
            'permissions' => $role->permissions->pluck('name'),
        ]);
    }

    /**
     * AJAX: return role + permissions for editing.
     * Non-AJAX requests have no standalone page — 404.
     */
    public function edit(Role $role)
    {
        if (!request()->ajax()) {
            abort(404);
        }

        return response()->json([
            'role'        => $role,
            'permissions' => $role->permissions->pluck('name'),
        ]);
    }

    // ── WRITE ─────────────────────────────────────────────────────────────────

    public function create()
    {
        // Modal-driven — no standalone create page.
        abort(404);
    }

    /**
     * Store a new role.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|unique:roles,name',
            'permissions' => 'nullable|array',
        ]);

        // Privilege escalation guard: only superadmin may include privileged perms
        $this->checkPrivilegedPerms($request->input('permissions', []));

        try {
            DB::transaction(function () use ($request) {
                $role = Role::create(['name' => $request->input('name')]);
                if ($request->has('permissions')) {
                    $role->syncPermissions($request->input('permissions', []));
                }
            });

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Rôle créé avec succès.',
                ]);
            }

            return redirect()->route('user-management.roles.index')
                ->with('success', 'Rôle créé avec succès.');

        } catch (\Exception $e) {
            \Log::error('RoleManagementController@store: ' . $e->getMessage(), ['exception' => $e]);
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur interne est survenue lors de la création du rôle.',
                ], 500);
            }
            return back()->with('error', 'Erreur lors de la création du rôle.');
        }
    }

    /**
     * Update an existing role.
     *
     * Security: cannot rename system roles; only superadmin may add privileged perms.
     */
    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name'        => 'required|string|unique:roles,name,' . $role->id,
            'permissions' => 'nullable|array',
        ]);

        // System denylist: cannot rename core roles
        if (in_array($role->name, self::SYSTEM_ROLES, true) && $request->input('name') !== $role->name) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le rôle "' . $role->name . '" est un rôle système et ne peut pas être renommé.',
                    'errors'  => ['name' => ['Ce rôle système ne peut pas être renommé.']],
                ], 403);
            }
            return back()->with('error', 'Ce rôle système ne peut pas être renommé.');
        }

        // Privilege escalation guard
        $this->checkPrivilegedPerms($request->input('permissions', []));

        try {
            DB::transaction(function () use ($request, $role) {
                // Only update the name if it changed (and not a system role name — already checked above)
                if ($request->input('name') !== $role->name && !in_array($role->name, self::SYSTEM_ROLES, true)) {
                    $role->update(['name' => $request->input('name')]);
                }
                $role->syncPermissions($request->input('permissions', []));
            });

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Rôle mis à jour avec succès.',
                ]);
            }

            return redirect()->route('user-management.roles.index')
                ->with('success', 'Rôle mis à jour avec succès.');

        } catch (\Exception $e) {
            \Log::error('RoleManagementController@update: ' . $e->getMessage(), ['exception' => $e]);
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur interne est survenue lors de la mise à jour du rôle.',
                ], 500);
            }
            return back()->with('error', 'Erreur lors de la mise à jour du rôle.');
        }
    }

    /**
     * Delete a role.
     *
     * Security: system roles are protected; roles with active users cannot be deleted.
     */
    public function destroy(Role $role)
    {
        // System denylist
        if (in_array($role->name, self::SYSTEM_ROLES, true)) {
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le rôle "' . $role->name . '" est un rôle système et ne peut pas être supprimé.',
                ], 403);
            }
            return back()->with('error', 'Ce rôle système ne peut pas être supprimé.');
        }

        // Users-assigned guard: cannot delete a role with active users
        $userCount = $role->users()->count();
        if ($userCount > 0) {
            $msg = 'Ce rôle est assigné à ' . $userCount . ' utilisateur(s) et ne peut pas être supprimé. Réassignez les utilisateurs d\'abord.';
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $msg,
                ], 403);
            }
            return back()->with('error', $msg);
        }

        try {
            $role->delete();

            if (request()->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Rôle supprimé avec succès.',
                ]);
            }

            return redirect()->route('user-management.roles.index')
                ->with('success', 'Rôle supprimé avec succès.');

        } catch (\Exception $e) {
            \Log::error('RoleManagementController@destroy: ' . $e->getMessage(), ['exception' => $e]);
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur interne est survenue lors de la suppression du rôle.',
                ], 500);
            }
            return back()->with('error', 'Erreur lors de la suppression du rôle.');
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Guard: only a superadmin actor may include privileged keyword permissions
     * in a role. Throws a 403 JSON / redirect for non-superadmin actors.
     */
    private function checkPrivilegedPerms(array $permissions): void
    {
        if (empty($permissions)) {
            return;
        }

        $hasPrivileged = array_intersect($permissions, self::PRIVILEGED_PERMS);
        if (empty($hasPrivileged)) {
            return;
        }

        $actor = auth()->user();
        if ($actor && $actor->hasRole('superadmin')) {
            return; // allowed
        }

        $permList = implode(', ', $hasPrivileged);
        if (request()->ajax()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Seul un superadmin peut assigner les permissions système : ' . $permList,
                'errors'  => ['permissions' => ['Permission refusée : ' . $permList]],
            ], 403));
        }

        abort(403, 'Seul un superadmin peut assigner les permissions système : ' . $permList);
    }
}
