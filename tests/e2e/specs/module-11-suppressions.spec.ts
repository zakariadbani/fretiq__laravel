// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { SuppressionPage } from '../pages/SuppressionPage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:suppressions-e2e

/**
 * module-11-suppressions — fretiq suppressions CRUD e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Contract:
 *   - All async widget interactions use ONLY the declared test-utils helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via SuppressionPage.
 *   - Suppressions are keyed by email address — uniqueName() is used as the email
 *     prefix, not the display name. Email must be a valid format (required field).
 *   - Submit button is #submit_btn (NOT button[name="save"]) — handled by SuppressionPage.
 *   - No toggle tests: SuppressionController declares no $toggleableFields.
 *   - Delete success SweetAlert contains 'retirée' (from 'Suppression retirée avec succès').
 *   - Cleanup uses deleteAllByEmail() loop to handle any double-submit bug.
 */

test.describe('Suppressions module', () => {

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const suppressions = new SuppressionPage(page);
    await suppressions.goto();

    await expectPath(page, '/admin/suppressions');
    await waitForDataTable(page, 'suppression-table');
    await suppressions.expectTableVisible();
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill email, submit, row appears in table, cleanup', async ({ page }) => {
    const suppressions = new SuppressionPage(page);
    // Use a deterministic unique email (suppression unique constraint is on email).
    const email = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 6)}@fretiq-suppression.test`;

    await suppressions.gotoCreate();
    await suppressions.fillAndSubmit({ email, reason: 'manual', source: 'manual' });

    // crud-form-handler.js follows redirect on 2xx — wait for navigation away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and confirm the row.
    await suppressions.goto();
    await waitForDataTable(page, 'suppression-table');
    await suppressions.search(email);
    await waitForDataTable(page, 'suppression-table');

    // At least 1 matching row (tolerates double-submit bug producing 2).
    const rows = suppressions.table.locator(`tbody tr:has-text("${email}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);

    // Cleanup: loop-delete all rows matching the email.
    await suppressions.deleteAllByEmail(email, {
      search: (q) => suppressions.search(q),
      waitForDataTable,
      confirmDelete,
    });

    // Verify deletion.
    await suppressions.search(email);
    await waitForDataTable(page, 'suppression-table');
    await expect(suppressions.table.locator(`tbody tr:has-text("${email}")`)).toHaveCount(0);
  });

  // ── 3. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows suppressed email', async ({ page }) => {
    const suppressions = new SuppressionPage(page);
    const email = `e2e-view-${Date.now()}@fretiq-suppression.test`;

    // Create a suppression.
    await suppressions.gotoCreate();
    await suppressions.fillAndSubmit({ email });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await suppressions.goto();
    await waitForDataTable(page, 'suppression-table');
    await suppressions.search(email);
    await waitForDataTable(page, 'suppression-table');
    await suppressions.clickRowAction(0, 'view');

    // Detail page must show the email address.
    await expect(page.locator(`text=${email}`).first()).toBeVisible({ timeout: 10000 });

    // Cleanup.
    await suppressions.goto();
    await waitForDataTable(page, 'suppression-table');
    await suppressions.deleteAllByEmail(email, {
      search: (q) => suppressions.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 4. Delete confirm and row removal ─────────────────────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const suppressions = new SuppressionPage(page);
    const email = `e2e-del-${Date.now()}@fretiq-suppression.test`;

    // Create a suppression to delete.
    await suppressions.gotoCreate();
    await suppressions.fillAndSubmit({ email });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await suppressions.goto();
    await waitForDataTable(page, 'suppression-table');
    await suppressions.search(email);
    await waitForDataTable(page, 'suppression-table');

    // Click delete → first SweetAlert confirm.
    await suppressions.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog 'Suppression retirée avec succès'.
    await expect(page.locator('.swal2-popup')).toContainText('retirée', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'suppression-table');

    // Verify row is gone.
    await suppressions.search(email);
    await waitForDataTable(page, 'suppression-table');
    await expect(suppressions.table.locator(`tbody tr:has-text("${email}")`)).toHaveCount(0);
  });

});

// <<<
