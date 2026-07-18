// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { CompaniesPage } from '../pages/CompaniesPage';
import { waitForDataTable } from '../helpers/test-utils';

/**
 * datatables-length-control — regression guard for the DataTables "entries per page" control.
 *
 * Bug: resources/_keenthemes/src/js/vendors/plugins/datatables.init.js shallow-extended
 * `DataTable.ext.classes`. That replaced DataTables 2.x's
 * `length: { container: 'dt-length', select: … }` object WHOLESALE and dropped the
 * `dt-length` container class. Without that class upstream's
 * `div.dt-container div.dt-length select { width: auto; display: inline-block }`
 * could never match, so Bootstrap's `.form-select { display: block; width: 100% }`
 * turned the select into a block box mid-label and doubled the label height
 * (35px correct → 74px broken).
 *
 * Fixed by making it a deep extend: `$.extend( true, DataTable.ext.classes, { … } )`.
 * The `.dt-length` count assertion below is the one that fails without the fix.
 */

test.describe('DataTables length control', () => {

  // ── Length control keeps its container class and stays inline ─────────────

  test('length control renders a single .dt-length with an inline-block select', async ({ page }) => {
    const companies = new CompaniesPage(page);
    await companies.goto();

    await waitForDataTable(page, 'company-table');
    await companies.expectTableVisible();

    // 1. The container class dropped by the shallow extend. Fails without the fix.
    const lengthControl = page.locator('.dt-length');
    await expect(lengthControl).toHaveCount(1);

    // 2. Upstream's `div.dt-container div.dt-length select` rule must beat
    //    Bootstrap's `.form-select { display: block }`.
    const lengthSelect = lengthControl.locator('select');
    await expect(lengthSelect).toBeVisible();
    await expect(lengthSelect).toHaveCSS('display', 'inline-block');

    // 3. The label must stay on ONE line — 35px when correct, 74px when the
    //    select renders as a block box mid-label.
    const labelHeight = await lengthControl
      .locator('label')
      .first()
      .evaluate((el) => el.getBoundingClientRect().height);
    expect(labelHeight).toBeLessThan(50);
  });

});
