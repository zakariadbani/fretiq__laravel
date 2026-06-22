// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { SegmentPage } from '../pages/SegmentPage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:segments-e2e

/**
 * module-5-segments — fretiq segments CRUD e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Contract:
 *   - All async widget interactions use ONLY the declared test-utils helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via SegmentPage.
 *   - Mutating tests use uniqueName() so rows are distinct across repeated runs.
 *   - Segments require only name + scope — no foreign-key dependency on other entities.
 *     No fixture company/contact setup is needed.
 *   - scope select is a native <select> (NOT a Select2 data-control), so we use
 *     selectOption({ value }) + dispatchEvent('change') directly.
 *   - Preview panel (#segment_preview_card) is triggered by segment-form.js on
 *     scope 'change' — AJAX POST to /admin/segments/preview. We assert the panel
 *     becomes populated (loading hides, #preview_final shows a numeric value or 0)
 *     without asserting the exact count (data varies by environment).
 *   - Delete uses two SweetAlert dialogs: confirm (first) → success 'supprimé' (second).
 *   - Cleanup uses deleteAllByName() loop to handle any double-submit producing extra rows.
 */

test.describe('Segments module', () => {

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const segments = new SegmentPage(page);
    await segments.goto();

    await expectPath(page, '/admin/segments');
    await waitForDataTable(page, 'segment-table');
    await segments.expectTableVisible();
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, redirect, row appears in table, cleanup', async ({ page }) => {
    const segments = new SegmentPage(page);
    const name = uniqueName('E2E Segment');

    await segments.gotoCreate();

    // Fill required fields (name + scope). scope is a native select.
    await segments.fillAndSubmit({ name, scope: 'prospect' });

    // crud-form-handler.js follows redirect on 2xx — wait for navigation away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and search for the created row.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.search(name);
    await waitForDataTable(page, 'segment-table');

    // At least 1 matching row (tolerates double-submit producing 2).
    const rows = segments.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);

    // Cleanup: loop-delete all rows matching the name.
    await segments.deleteAllByName(name, {
      search: (q) => segments.search(q),
      waitForDataTable,
      confirmDelete,
    });

    // Verify deletion.
    await segments.search(name);
    await waitForDataTable(page, 'segment-table');
    await expect(segments.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: change name field, save, redirect', async ({ page }) => {
    const segments = new SegmentPage(page);
    const name    = uniqueName('E2E Edit Segment');
    const newName = uniqueName('E2E Edit Segment Updated');

    // Create a segment to edit.
    await segments.gotoCreate();
    await segments.fillAndSubmit({ name, scope: 'client' });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Find the row and open edit.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.search(name);
    await waitForDataTable(page, 'segment-table');
    await segments.clickRowAction(0, 'edit');

    // Change the name field and save.
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="name"]').fill(newName);
    await page.locator('#form_crud button[name="save"]').click();

    // Wait for redirect away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });

    // Cleanup: delete by the updated name.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.deleteAllByName(newName, {
      search: (q) => segments.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows segment name', async ({ page }) => {
    const segments = new SegmentPage(page);
    const name = uniqueName('E2E View Segment');

    // Create a segment.
    await segments.gotoCreate();
    await segments.fillAndSubmit({ name, scope: 'mixed' });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.search(name);
    await waitForDataTable(page, 'segment-table');
    await segments.clickRowAction(0, 'view');

    // Detail page must show the segment name somewhere on the page.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });

    // Cleanup.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.deleteAllByName(name, {
      search: (q) => segments.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 5. Delete: two-SweetAlert flow, row removed ────────────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const segments = new SegmentPage(page);
    const name = uniqueName('E2E Delete Segment');

    // Create a segment to delete.
    await segments.gotoCreate();
    await segments.fillAndSubmit({ name, scope: 'prospect' });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.search(name);
    await waitForDataTable(page, 'segment-table');

    // Click delete → first SweetAlert confirm.
    await segments.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog — SegmentsDataTable::getMessages() deleteSuccess
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'segment-table');

    // Verify row is gone.
    await segments.search(name);
    await waitForDataTable(page, 'segment-table');
    await expect(segments.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 6. Preview panel: AJAX fires and shows a count ─────────────────────────

  test('preview panel: selecting scope triggers AJAX, #preview_final shows a number', async ({ page }) => {
    const segments = new SegmentPage(page);

    await segments.gotoCreate();

    // On page load, segment-form.js calls doPreview() immediately — but scope is
    // empty so it bails to showWaiting() without firing AJAX.
    // #preview_final should show '—' (the waiting state).
    await expect(segments.previewFinal).toHaveText('—', { timeout: 5000 });

    // Select a scope value — this fires the 'change' event that triggers refreshPreview()
    // → debounced 400 ms → doPreview() → AJAX POST to /admin/segments/preview.
    // We use selectOption + dispatchEvent to mirror the FIXED Select2 convention.
    await segments.scopeSelect.selectOption({ value: 'prospect' });
    await segments.scopeSelect.dispatchEvent('change');

    // Wait for the loading spinner to appear (AJAX in flight) then disappear (done).
    // Allow generous timeout: debounce (400 ms) + network round-trip.
    await segments.waitForPreview();

    // After a successful preview call, #preview_final should contain a number (≥ 0).
    // We do NOT assert the exact count — it varies by environment seed data.
    // The rendered value is either '0' or a positive integer — never '—' after success.
    const finalText = (await segments.previewFinal.textContent()) ?? '';
    expect(finalText.trim()).toMatch(/^\d+$/);

    // The error badge must remain hidden (preview succeeded).
    await expect(segments.previewError).toBeHidden();
  });

  // ── 7. Preview panel: edit form — preview loads for existing segment ────────

  test('preview panel: edit form loads existing segment and preview populates', async ({ page }) => {
    const segments = new SegmentPage(page);
    const name = uniqueName('E2E Preview Edit Segment');

    // Create a segment first.
    await segments.gotoCreate();
    await segments.fillAndSubmit({ name, scope: 'client' });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, find row, open edit.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.search(name);
    await waitForDataTable(page, 'segment-table');
    await segments.clickRowAction(0, 'edit');

    // Edit form loads with scope pre-filled — segment-form.js calls doPreview() on init.
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    // Wait for the preview AJAX initiated by KTSegmentForm.init() to settle.
    await segments.waitForPreview();

    // #preview_final should show a number (the saved scope is 'client', a valid value).
    const finalText = (await segments.previewFinal.textContent()) ?? '';
    expect(finalText.trim()).toMatch(/^\d+$/);

    // Error badge must be hidden.
    await expect(segments.previewError).toBeHidden();

    // Cleanup.
    await segments.goto();
    await waitForDataTable(page, 'segment-table');
    await segments.deleteAllByName(name, {
      search: (q) => segments.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

});

// <<<
