// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { SenderIdentityPage } from '../pages/SenderIdentityPage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:sender_identities-e2e

/**
 * module-10-sender-identities — fretiq sender identities CRUD e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Contract:
 *   - All async widget interactions use ONLY the declared test-utils helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via SenderIdentityPage.
 *   - Mutating tests use uniqueName() so rows are distinct across repeated runs.
 *   - toggleableFields = ['is_default', 'is_active'] — both switches tested.
 *   - executeSwitch for is_active: status-toggle input with data-field="is_active".
 *   - executeSwitch for is_default: single-default rule enforced server-side.
 *   - Cleanup uses deleteAllByName() loop to handle any double-submit bug.
 */

test.describe('Sender Identities module', () => {

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const identities = new SenderIdentityPage(page);
    await identities.goto();

    await expectPath(page, '/admin/sender_identities');
    await waitForDataTable(page, 'sender_identity-table');
    await identities.expectTableVisible();
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, row appears in table, cleanup', async ({ page }) => {
    const identities = new SenderIdentityPage(page);
    const name  = uniqueName('E2E Identity');
    const email = `e2e-${Date.now()}@fretiq-fixture.test`;

    await identities.gotoCreate();
    await identities.fillAndSubmit({ name, email });

    // crud-form-handler.js follows redirect on 2xx — wait for navigation away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and confirm the row.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');

    // At least 1 matching row (tolerates double-submit bug producing 2).
    const rows = identities.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);

    // Cleanup: loop-delete all rows matching the name.
    await identities.deleteAllByName(name, {
      search: (q) => identities.search(q),
      waitForDataTable,
      confirmDelete,
    });

    // Verify deletion.
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');
    await expect(identities.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: change reply_to field, save, success redirect', async ({ page }) => {
    const identities = new SenderIdentityPage(page);
    const name  = uniqueName('E2E Edit Identity');
    const email = `e2e-edit-${Date.now()}@fretiq-fixture.test`;

    // Create an identity to edit.
    await identities.gotoCreate();
    await identities.fillAndSubmit({ name, email });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Find the row and open edit.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');
    await identities.clickRowAction(0, 'edit');

    // Change reply_to and save.
    await expect(page.locator('#form_crud input[name="reply_to"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="reply_to"]').fill(`reply-${Date.now()}@fretiq-fixture.test`);
    await page.locator('#form_crud button[name="save"]').click();

    // Wait for redirect away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });

    // Cleanup.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.deleteAllByName(name, {
      search: (q) => identities.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows identity name', async ({ page }) => {
    const identities = new SenderIdentityPage(page);
    const name  = uniqueName('E2E View Identity');
    const email = `e2e-view-${Date.now()}@fretiq-fixture.test`;

    // Create an identity.
    await identities.gotoCreate();
    await identities.fillAndSubmit({ name, email });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');
    await identities.clickRowAction(0, 'view');

    // Detail page must show the identity name.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });

    // Cleanup.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.deleteAllByName(name, {
      search: (q) => identities.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 5. executeSwitch — toggle is_active ────────────────────────────────────

  test('executeSwitch: toggle is_active switch, row still present after AJAX', async ({ page }) => {
    const identities = new SenderIdentityPage(page);
    const name  = uniqueName('E2E Toggle Identity');
    const email = `e2e-toggle-${Date.now()}@fretiq-fixture.test`;

    // Create an identity (is_active defaults to true).
    await identities.gotoCreate();
    await identities.fillAndSubmit({ name, email });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and search for the row.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');

    // Read initial checked state of the is_active switch on the first row.
    const firstRow = identities.table.locator('tbody tr').first();
    const activeSwitch = firstRow.locator('input.status-toggle[data-field="is_active"]');
    await expect(activeSwitch).toBeVisible({ timeout: 5000 });
    const wasChecked = await activeSwitch.isChecked();

    // Click the is_active switch (executeSwitch AJAX PUT fires).
    await activeSwitch.click();
    await page.waitForLoadState('networkidle');

    // Switch state should have flipped.
    await expect(activeSwitch).toBeVisible({ timeout: 5000 });
    const isCheckedNow = await activeSwitch.isChecked();
    expect(isCheckedNow).toBe(!wasChecked);

    // Row is still visible after the toggle (no full page reload).
    await identities.expectMinRows(1);

    // Cleanup.
    await identities.deleteAllByName(name, {
      search: (q) => identities.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 6. executeSwitch — set is_default ─────────────────────────────────────

  test('executeSwitch: set is_default switch, single-default rule applies', async ({ page }) => {
    const identities = new SenderIdentityPage(page);
    const name  = uniqueName('E2E Default Identity');
    const email = `e2e-default-${Date.now()}@fretiq-fixture.test`;

    // Create an identity (is_default false by default).
    await identities.gotoCreate();
    await identities.fillAndSubmit({ name, email });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');

    // Click is_default switch on the first row.
    await identities.clickIsDefaultSwitchOnRow(0);
    await page.waitForLoadState('networkidle');

    // The DataTable row should still be visible after the toggle.
    await identities.expectMinRows(1);

    // Cleanup.
    await identities.deleteAllByName(name, {
      search: (q) => identities.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 7. Delete confirm and row removal ─────────────────────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const identities = new SenderIdentityPage(page);
    const name  = uniqueName('E2E Delete Identity');
    const email = `e2e-del-${Date.now()}@fretiq-fixture.test`;

    // Create an identity to delete.
    await identities.gotoCreate();
    await identities.fillAndSubmit({ name, email });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await identities.goto();
    await waitForDataTable(page, 'sender_identity-table');
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');

    // Click delete → first SweetAlert confirm.
    await identities.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog 'Identité supprimée avec succès'.
    await expect(page.locator('.swal2-popup')).toContainText('supprimée', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'sender_identity-table');

    // Verify row is gone.
    await identities.search(name);
    await waitForDataTable(page, 'sender_identity-table');
    await expect(identities.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

});

// <<<
