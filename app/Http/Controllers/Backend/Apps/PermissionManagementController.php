<?php

namespace App\Http\Controllers\Backend\Apps;

use App\DataTables\PermissionsDataTable;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * PermissionManagementController — AJAX-backed permission CRUD at
 * /admin/user-management/permissions.
 *
 * Security guards:
 *   - Middleware: permission:manage permissions (all actions)
 *   - System denylist: cannot rename/delete the core seeded permissions
 */
class PermissionManagementController extends Controller
{
    /**
     * Seeded permissions that cannot be renamed or deleted.
     * Renaming backend.access one-click bricks the entire panel.
     */
    private const SYSTEM_PERMISSIONS = [
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
        $this->middleware('permission:manage permissions');
    }

    // ── READ ──────────────────────────────────────────────────────────────────

    public function index(PermissionsDataTable $dataTable)
    {
        return $dataTable->render('backend.contents.users.permissions.list');
    }

    /**
     * AJAX: return permission data for editing.
     * Non-AJAX requests have no standalone page — 404.
     */
    public function edit(Permission $permission)
    {
        if (!request()->ajax()) {
            abort(404);
        }

        return response()->json([
            'permission' => $permission,
        ]);
    }

    public function show(Permission $permission)
    {
        // Modal-driven — no standalone show page.
        abort(404);
    }

    // ── WRITE ─────────────────────────────────────────────────────────────────

    public function create()
    {
        // Modal-driven — no standalone create page.
        abort(404);
    }

    /**
     * Store a new permission.
     * Name is always lowercased (convention: '{action} {entity}').
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|unique:permissions,name',
        ]);

        try {
            Permission::create([
                'name'       => strtolower(trim($request->input('name'))),
                'guard_name' => 'web',
            ]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Permission créée avec succès.',
                ]);
            }

            return redirect()->route('user-management.permissions.index')
                ->with('success', 'Permission créée avec succès.');

        } catch (\Exception $e) {
            \Log::error('PermissionManagementController@store: ' . $e->getMessage(), ['exception' => $e]);
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur interne est survenue lors de la création de la permission.',
                ], 500);
            }
            return back()->with('error', 'Erreur lors de la création.');
        }
    }

    /**
     * Update a permission name.
     *
     * Security: system permissions cannot be renamed.
     */
    public function update(Request $request, Permission $permission)
    {
        $request->validate([
            'name' => 'required|string|unique:permissions,name,' . $permission->id,
        ]);

        $newName = strtolower(trim($request->input('name')));

        // System denylist: cannot rename core permissions
        if (in_array($permission->name, self::SYSTEM_PERMISSIONS, true) && $newName !== $permission->name) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La permission "' . $permission->name . '" est une permission système et ne peut pas être renommée.',
                    'errors'  => ['name' => ['Cette permission système ne peut pas être renommée.']],
                ], 403);
            }
            return back()->with('error', 'Cette permission système ne peut pas être renommée.');
        }

        try {
            $permission->update(['name' => $newName]);

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Permission mise à jour avec succès.',
                ]);
            }

            return redirect()->route('user-management.permissions.index')
                ->with('success', 'Permission mise à jour avec succès.');

        } catch (\Exception $e) {
            \Log::error('PermissionManagementController@update: ' . $e->getMessage(), ['exception' => $e]);
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur interne est survenue lors de la mise à jour de la permission.',
                ], 500);
            }
            return back()->with('error', 'Erreur lors de la mise à jour.');
        }
    }

    /**
     * Delete a permission.
     *
     * Security: system permissions cannot be deleted.
     */
    public function destroy(Permission $permission)
    {
        // System denylist
        if (in_array($permission->name, self::SYSTEM_PERMISSIONS, true)) {
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La permission "' . $permission->name . '" est une permission système et ne peut pas être supprimée.',
                ], 403);
            }
            return back()->with('error', 'Cette permission système ne peut pas être supprimée.');
        }

        try {
            $permission->delete();

            if (request()->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Permission supprimée avec succès.',
                ]);
            }

            return redirect()->route('user-management.permissions.index')
                ->with('success', 'Permission supprimée avec succès.');

        } catch (\Exception $e) {
            \Log::error('PermissionManagementController@destroy: ' . $e->getMessage(), ['exception' => $e]);
            if (request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Une erreur interne est survenue lors de la suppression de la permission.',
                ], 500);
            }
            return back()->with('error', 'Erreur lors de la suppression.');
        }
    }
}
