<?php

namespace Database\Seeders\Acl;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsSeeder extends Seeder
{
    /**
     * Seed the application's permissions and assign them to roles.
     *
     * Permission naming convention: "{action} {entity}"
     * Entities: companies, contacts, segments, campaigns, campaign_templates, sequences, demandes,
     *           users, prospect_criteria, sender_identities, suppressions
     * Actions:  view, create, edit, delete
     *
     * Keyword permissions: backend.access, send campaigns, manage roles, manage permissions,
     *                      view zoho, sync zoho, run discovery
     *
     * Role mapping:
     *   superadmin  → all permissions
     *   admin       → all permissions
     *   commercial  → view/create/edit on companies, contacts, segments, campaigns, sequences,
     *                 demandes, prospect_criteria, campaign_templates, sender_identities
     *                 + view/create suppressions + backend.access + run discovery
     *                 (NO delete on any entity; NO send campaigns — sending is gated to admin/superadmin;
     *                  NO users perms, NO manage roles/permissions)
     */
    public function run(): void
    {
        // ── 1. Build CRUD permissions ──────────────────────────────────────────

        $entities = [
            'companies', 'contacts', 'segments', 'campaigns', 'campaign_templates',
            'sequences', 'demandes', 'users', 'prospect_criteria', 'sender_identities', 'suppressions',
        ];
        $actions  = ['view', 'create', 'edit', 'delete'];

        foreach ($entities as $entity) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name'       => "{$action} {$entity}",
                    'guard_name' => 'web',
                ]);
            }
        }

        // ── 2. Keyword permissions ─────────────────────────────────────────────

        $keywordPermissions = [
            'backend.access',
            'send campaigns',
            'manage roles',
            'manage permissions',
            'view zoho',
            'sync zoho',
            'run discovery',
            'manage packages',  // superadmin only — admin/commercial MUST NOT receive this
        ];

        foreach ($keywordPermissions as $perm) {
            Permission::firstOrCreate([
                'name'       => $perm,
                'guard_name' => 'web',
            ]);
        }

        // ── 3. Assign permissions to roles ─────────────────────────────────────

        // superadmin and admin get everything
        $allPermissions = Permission::all();

        $superadmin = Role::where('name', 'superadmin')->where('guard_name', 'web')->first();
        if ($superadmin) {
            $superadmin->syncPermissions($allPermissions);
        }

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            // Admin gets all permissions EXCEPT 'manage packages' — package management
            // is superadmin-only so the client (admin role) never sees quota management.
            $adminPermissions = $allPermissions->filter(
                fn ($p) => $p->name !== 'manage packages'
            );
            $admin->syncPermissions($adminPermissions);
        }

        // commercial: view/create/edit on business entities + backend.access + run discovery
        // campaign_templates + sender_identities + sequences: view/create/edit (NO delete)
        // suppressions: view/create only (NO edit, NO delete — immutable compliance records)
        // send campaigns: NOT granted — sending is gated to admin/superadmin
        $commercialEntities = [
            'companies', 'contacts', 'segments', 'campaigns', 'sequences', 'demandes',
            'prospect_criteria', 'campaign_templates', 'sender_identities',
        ];
        $commercialActions  = ['view', 'create', 'edit'];

        $commercialPermissions = [];
        foreach ($commercialEntities as $entity) {
            foreach ($commercialActions as $action) {
                $commercialPermissions[] = "{$action} {$entity}";
            }
        }

        // suppressions: view + create only
        $commercialPermissions[] = 'view suppressions';
        $commercialPermissions[] = 'create suppressions';

        $commercialPermissions[] = 'backend.access';
        $commercialPermissions[] = 'run discovery';

        $commercial = Role::where('name', 'commercial')->where('guard_name', 'web')->first();
        if ($commercial) {
            $commercial->syncPermissions($commercialPermissions);
        }

        // ── 4. Flush Spatie's cached permissions ───────────────────────────────

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
