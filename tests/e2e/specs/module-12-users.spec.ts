// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror).
import { test, expect } from '../fixtures/console-guard';
import { UserPage } from '../pages/UserPage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:users-e2e

/**
 * module-12-users — fretiq users CRUD e2e suite.
 *
 * Runs against the live dev site (http://fretiq.test) with the
 * pre-authenticated superadmin storageState (tests/e2e/.auth/admin.json).
 *
 * Contract:
 *   - All mutation tests use uniqueName() so rows are distinct across runs.
 *   - Every test that creates a user MUST delete it (cleanup).
 *   - No 403/permission-guard cases in e2e — those are covered by code tests.
 *   - role='superadmin' is BLOCKED by the controller (not_in:superadmin validation);
 *     always use 'commercial' or 'admin'.
 *   - The superadmin session user does NOT appear in the DataTable (filtered out by
 *     UsersDataTable::query() → whereDoesntHave roles 'superadmin').
 *
 * Delete flow (non-standard — users use .btn-delete-user, not .delete-btn):
 *   1. click .btn-delete-user → SweetAlert2 confirm dialog
 *   2. confirmDelete() → AJAX DELETE fires
 *   3. SweetAlert2 success dialog ("Utilisateur supprimé avec succès.")
 *   4. dismiss success → DataTable ajax.reload() (no full page navigation)
 */

test.describe('Users module', () => {

  // Track every user a test creates; the afterEach below removes it even if the
  // test fails before its inline cleanup runs (prevents orphan rows on dev DB).
  const createdUsers: string[] = [];

  test.afterEach(async ({ page }) => {
    if (createdUsers.length === 0) return;
    const users = new UserPage(page);
    for (const name of createdUsers.splice(0)) {
      try {
        await users.goto();
        await waitForDataTable(page, 'users-table');
        await users.search(name);
        await waitForDataTable(page, 'users-table');
        const remaining = await users.table.locator(`tbody tr:has-text("${name}")`).count();
        if (remaining === 0) continue; // self-cleaned by the test, or never persisted
        await users.clickDeleteOnRow(0);
        await confirmDelete(page);
        await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
        await page.locator('.swal2-confirm').click();
        await page.waitForLoadState('networkidle');
      } catch {
        // best-effort teardown — never fail the suite on cleanup
      }
    }
  });

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const users = new UserPage(page);
    await users.goto();

    await expectPath(page, '/admin/users');
    await waitForDataTable(page, 'users-table');
    await users.expectTableVisible();
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form with role=commercial, submit, row appears, then delete (cleanup)', async ({ page }) => {
    const users = new UserPage(page);
    const name  = uniqueName('E2E User');
    createdUsers.push(name);
    const email = uniqueName('e2e') + '@example.test';
    const password = 'E2ePassw0rd!';

    await users.gotoCreate();

    await users.fillAndSubmit({
      name,
      email,
      password,
      passwordConfirmation: password,
      role: 'commercial',
    });

    // crud-form-handler.js on 2xx follows response.data.redirect (window.location.replace).
    // store() redirects to admin.users.index — wait for navigation off /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and verify the row appears.
    await users.goto();
    await waitForDataTable(page, 'users-table');
    await users.search(name);
    await waitForDataTable(page, 'users-table');
    await users.expectRowCountByText(name, 1);

    // Cleanup: delete the created user.
    await users.clickDeleteOnRow(0);
    await confirmDelete(page);

    // Second SweetAlert: success dialog after DELETE.
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    // DataTable ajax.reload() fires — wait for network to settle.
    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'users-table');

    // Verify deletion.
    await users.search(name);
    await waitForDataTable(page, 'users-table');
    await users.expectUserRowGone(name);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: create user, change name, save, navigate back, updated name present', async ({ page }) => {
    const users    = new UserPage(page);
    const origName = uniqueName('E2E EditUser');
    const newName  = uniqueName('E2E Edited');
    createdUsers.push(origName);
    createdUsers.push(newName);
    const email    = uniqueName('e2e-edit') + '@example.test';
    const password = 'E2ePassw0rd!';

    // Create a user to edit.
    await users.gotoCreate();
    await users.fillAndSubmit({
      name: origName,
      email,
      password,
      passwordConfirmation: password,
      role: 'commercial',
    });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Find the user in the table and click edit.
    await users.goto();
    await waitForDataTable(page, 'users-table');
    await users.search(origName);
    await waitForDataTable(page, 'users-table');

    await users.clickRowAction(0, 'edit');

    // On the edit form: change the name and save.
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="name"]').fill(newName);

    // Role must be re-selected on edit (required field — keep 'commercial').
    const roleSelect = page.locator('#form_crud select[name="role"]');
    if (await roleSelect.count() > 0) {
      const currentValue = await roleSelect.inputValue();
      if (!currentValue) {
        await roleSelect.selectOption({ value: 'commercial' });
        await roleSelect.dispatchEvent('change');
      }
    }

    await page.locator('#form_crud button[name="save"]').click();

    // update() redirects to admin.users.edit — page stays on /edit URL.
    // Wait for network to settle.
    await page.waitForLoadState('networkidle');

    // Navigate back to index and confirm updated name.
    await users.goto();
    await waitForDataTable(page, 'users-table');
    await users.search(newName);
    await waitForDataTable(page, 'users-table');
    await users.expectRowCountByText(newName, 1);

    // Cleanup: delete the edited user.
    await users.clickDeleteOnRow(0);
    await confirmDelete(page);
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('networkidle');
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: create user, click view action, detail page shows user name', async ({ page }) => {
    const users    = new UserPage(page);
    const name     = uniqueName('E2E ViewUser');
    createdUsers.push(name);
    const email    = uniqueName('e2e-view') + '@example.test';
    const password = 'E2ePassw0rd!';

    // Create a user to view.
    await users.gotoCreate();
    await users.fillAndSubmit({
      name,
      email,
      password,
      passwordConfirmation: password,
      role: 'commercial',
    });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Find the user in the table and click view.
    await users.goto();
    await waitForDataTable(page, 'users-table');
    await users.search(name);
    await waitForDataTable(page, 'users-table');

    await users.clickRowAction(0, 'view');

    // Detail page must show the user name somewhere on the page.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });

    // Cleanup: navigate back, delete.
    await users.goto();
    await waitForDataTable(page, 'users-table');
    await users.search(name);
    await waitForDataTable(page, 'users-table');

    await users.clickDeleteOnRow(0);
    await confirmDelete(page);
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('networkidle');
  });

});

// <<<
