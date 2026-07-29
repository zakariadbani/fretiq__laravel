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
     *                      view zoho, sync zoho, run discovery, view settings, edit settings,
     *                      enrich companies, view consumption, view provider quota
     *
     * Role mapping:
     *   superadmin  → all permissions
     *   admin       → all permissions except manage packages, view provider quota,
     *                 manage roles, manage permissions, view settings, edit settings
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
            'manage roles',       // superadmin only — gates role management and observability
            'manage permissions', // superadmin only — gates permission management
            'view zoho',
            'sync zoho',
            'run discovery',
            'manage packages',   // superadmin only — admin/commercial MUST NOT receive this
            'view settings',     // superadmin only — gates the settings page
            'edit settings',     // superadmin only — gates settings updates
            'enrich companies',  // commercial can trigger Hunter enrichment manually
            'view consumption',  // client-facing "Ma consommation" page — commercial + admin
            'view provider quota', // superadmin only — real vendor-account balances, NOT for admin/commercial
            'view inbox',
            'edit inbox',
        ];

        foreach ($keywordPermissions as $perm) {
            Permission::firstOrCreate([
                'name'       => $perm,
                'guard_name' => 'web',
            ]);
        }

        // ── 3. Assign permissions to roles ─────────────────────────────────────

        // superadmin gets everything; admin excludes only superadmin-only controls
        $allPermissions = Permission::all();

        $superadmin = Role::where('name', 'superadmin')->where('guard_name', 'web')->first();
        if ($superadmin) {
            $superadmin->syncPermissions($allPermissions);
        }

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            $superadminOnlyPermissions = [
                'manage packages',
                'view provider quota',
                'manage roles',
                'manage permissions',
                'view settings',
                'edit settings',
            ];

            $adminPermissions = $allPermissions->filter(
                fn ($p) => ! in_array($p->name, $superadminOnlyPermissions, true)
            );
            $admin->syncPermissions($adminPermissions);
        }

        // commercial: view/create/edit on business entities + backend.access + run discovery + enrich companies
        // campaign_templates + sender_identities + sequences: view/create/edit (NO delete)
        // suppressions: view/create only (NO edit, NO delete — immutable compliance records)
        // send campaigns: NOT granted — sending is gated to admin/superadmin
        // view settings / edit settings: NOT granted to commercial
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
        $commercialPermissions[] = 'enrich companies';
        $commercialPermissions[] = 'view consumption';
        $commercialPermissions[] = 'view inbox';
        $commercialPermissions[] = 'edit inbox';

        $commercial = Role::where('name', 'commercial')->where('guard_name', 'web')->first();
        if ($commercial) {
            $commercial->syncPermissions($commercialPermissions);
        }

        // ── 4. Flush Spatie's cached permissions ───────────────────────────────

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
