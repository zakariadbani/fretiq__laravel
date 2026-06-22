// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror).
import { test, expect } from '../fixtures/console-guard';
import { RolesPermissionsPage } from '../pages/RolesPermissionsPage';
import {
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:roles_permissions-e2e

/**
 * module-13-roles-permissions — fretiq roles + permissions e2e suite.
 *
 * Runs against the live dev site (http://fretiq.test) with the
 * pre-authenticated superadmin storageState (tests/e2e/.auth/admin.json).
 *
 * Contract:
 *   - Roles page is card-based (NOT a DataTable). Grid id: #roles-list-container.
 *   - Create role: .btn-add-role → Bootstrap modal #kt_modal_update_role → AJAX submit.
 *     After success: SweetAlert2 → dismiss → window.location.reload().
 *   - Delete role: window.deleteRole(id, name) called via page.evaluate() after
 *     extracting the role id from the "Voir le rôle" href on the card.
 *     After success: SweetAlert2 → dismiss → window.location.reload().
 *   - NEVER touch system roles (superadmin, admin, commercial).
 *   - Permissions page: DataTable (id: 'permissions-table') — READ-ONLY in e2e.
 *     No create or delete of permissions (system permissions are guarded; all
 *     seeded permissions are system permissions in this project).
 *
 * Create-role selector: #role-name-input (inside #kt_modal_update_role_form).
 * Submit: #role-submit-btn.
 * Form action: POST /admin/user-management/roles (AJAX, expects JSON {success:true}).
 */

test.describe('Roles & Permissions module', () => {

  // ── 1. Roles index renders ─────────────────────────────────────────────────

  test('roles index renders card grid with system roles visible', async ({ page }) => {
    const rp = new RolesPermissionsPage(page);
    await rp.gotoRoles();

    await expectPath(page, '/admin/user-management/roles');

    // Roles grid must be visible.
    await rp.expectRolesListVisible();

    // System roles (admin, commercial) must be present (superadmin is filtered out
    // by _role-list partial: where('name', '!=', 'superadmin')).
    await rp.expectRoleCardVisible('admin');
    await rp.expectRoleCardVisible('commercial');
  });

  // ── 2. Create role → verify card → delete (cleanup) ──────────────────────

  test('create role: modal submit, card appears, then delete (cleanup)', async ({ page }) => {
    const rp       = new RolesPermissionsPage(page);
    const roleName = uniqueName('e2e-role');

    await rp.gotoRoles();
    await rp.expectRolesListVisible();

    // Open the "Ajouter un rôle" modal.
    await rp.openCreateRoleModal();

    // Fill the role name and submit.
    await rp.fillRoleNameAndSubmit(roleName);

    // After AJAX success: SweetAlert2 success dialog.
    await expect(page.locator('.swal2-popup')).toContainText('créé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    // The modal JS does window.location.reload() after dismissing the swal.
    await page.waitForLoadState('networkidle');

    // The new role card must appear in the grid.
    await rp.expectRoleCardVisible(roleName);

    // Cleanup: delete the created role.
    await rp.deleteRoleByName(roleName);

    // SweetAlert2 confirm dialog from window.deleteRole().
    await expect(page.locator('.swal2-popup')).toBeVisible({ timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    // Second SweetAlert2: success dialog ("Rôle supprimé avec succès.").
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    // window.location.reload() fires after dismissing.
    await page.waitForLoadState('networkidle');

    // Verify the card is gone.
    await rp.expectRoleCardGone(roleName);
  });

  // ── 3. Permissions index renders (read-only) ──────────────────────────────

  test('permissions index renders DataTable (read-only — no create/delete)', async ({ page }) => {
    const rp = new RolesPermissionsPage(page);
    await rp.gotoPermissions();

    await expectPath(page, '/admin/user-management/permissions');

    // The permissions DataTable must be rendered and visible.
    await rp.expectPermissionsTableVisible();

    // The table must contain at least one row (seeded permissions exist).
    const rows = rp.permissionsTable.locator('tbody tr');
    const rowCount = await rows.count();
    expect(rowCount).toBeGreaterThan(0);
  });

});

// <<<
