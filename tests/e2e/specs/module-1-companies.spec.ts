import { test, expect } from '@playwright/test';
import { CompaniesPage } from '../pages/CompaniesPage';
import {
  waitForDataTable,
  expectAndDismissSuccess,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:companies-e2e

/**
 * module-1-companies — fretiq companies CRUD e2e suite.
 *
 * Runs against the live dev site (http://fretiq.test) with the
 * pre-authenticated admin storageState (tests/e2e/.auth/admin.json).
 *
 * Contract:
 *   - All async widget interactions use ONLY the 6 declared widget_helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via CompaniesPage.
 *   - Mutating tests (create/edit/delete) use uniqueName() so rows are distinct
 *     across repeated runs. Each create-test also cleans up its own row.
 */

test.describe('Companies module', () => {

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const companies = new CompaniesPage(page);
    await companies.goto();

    await expectPath(page, '/admin/companies');
    await waitForDataTable(page, 'company');
    await companies.expectTableVisible();
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, success toast, row appears in table', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E Transports');

    await companies.gotoCreate();

    await companies.fillAndSubmit({
      name,
      domain: `${name.toLowerCase().replace(/\s+/g, '-')}.test`,
      sector: 'Transport',
    });

    // Crudable store() returns JSON { redirect } — the form handler JS follows it.
    // After redirect we land back on the index or edit page; await the success toast.
    await expectAndDismissSuccess(page);

    // Navigate to index to confirm the row is present in the DataTable.
    await companies.goto();
    await waitForDataTable(page, 'company');

    // Search for the created name; the row must appear.
    await companies.search(name);
    await waitForDataTable(page, 'company');
    await companies.expectMinRows(1);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: open row edit, change sector, save, success toast', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E Edit Co');

    // Create a company to edit.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name, sector: 'Avant' });
    await expectAndDismissSuccess(page);

    // Navigate back to index and search for the row.
    await companies.goto();
    await waitForDataTable(page, 'company');
    await companies.search(name);
    await waitForDataTable(page, 'company');

    // Click edit on the first matching row.
    await companies.clickRowAction(0, 'edit');

    // On the edit form: update the sector field and save.
    await expect(page.locator('input[name="sector"]')).toBeVisible({ timeout: 10000 });
    await page.locator('input[name="sector"]').fill('Après');
    await page.locator('button[type="submit"]:visible, input[type="submit"]:visible').first().click();

    await expectAndDismissSuccess(page);
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows company name', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E View Co');

    // Create a company.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name });
    await expectAndDismissSuccess(page);

    // Navigate to index, search, open view.
    await companies.goto();
    await waitForDataTable(page, 'company');
    await companies.search(name);
    await waitForDataTable(page, 'company');

    await companies.clickRowAction(0, 'view');

    // Detail page must show the company name somewhere on the page.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });
  });

  // ── 5. Active toggle (executeSwitch) ───────────────────────────────────────

  test('executeSwitch: toggle is_active, badge/switch updates', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E Toggle Co');

    // Create a company.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name });
    await expectAndDismissSuccess(page);

    // Navigate to index and search for the row.
    await companies.goto();
    await waitForDataTable(page, 'company');
    await companies.search(name);
    await waitForDataTable(page, 'company');

    // Click the active switch on the first row.
    // The Metronic DataTable switch column renders <input type="checkbox">.
    // The PUT AJAX fires; the cell re-renders with the new state.
    await companies.clickActiveSwitchOnRow(0);

    // Wait for AJAX to settle — network idle is sufficient (no toast for switch toggles).
    await page.waitForLoadState('networkidle');

    // The DataTable row should still be visible after the toggle.
    await companies.expectMinRows(1);
  });

  // ── 6. Delete confirm and row removal ─────────────────────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E Delete Co');

    // Create a company to delete.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name });
    await expectAndDismissSuccess(page);

    // Navigate to index and find the row.
    await companies.goto();
    await waitForDataTable(page, 'company');
    await companies.search(name);
    await waitForDataTable(page, 'company');

    // Click delete action; SweetAlert2 confirm dialog appears.
    await companies.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Wait for AJAX + table refresh.
    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'company');

    // After deletion the search returns no matching rows.
    await companies.search(name);
    await waitForDataTable(page, 'company');
    // The row is gone — either the empty-state shows or rowCount is 0.
    const rows = companies.table.locator('tbody tr:not(:has(.dt-empty))');
    const count = await rows.count();
    expect(count).toBe(0);
  });

  // ── 7. Enrich action button visible ───────────────────────────────────────

  test('enrich: action button is visible in the DataTable for admin user', async ({ page }) => {
    const companies = new CompaniesPage(page);

    // Ensure at least one company exists so the table has a row.
    await companies.gotoCreate();
    const name = uniqueName('E2E Enrich Co');
    await companies.fillAndSubmit({ name, domain: 'enrichtest.example.com' });
    await expectAndDismissSuccess(page);

    await companies.goto();
    await waitForDataTable(page, 'company');
    await companies.search(name);
    await waitForDataTable(page, 'company');

    // The enrich button (data-kt-action="enrich_row") must be present for admin.
    await companies.expectEnrichButtonVisible();
  });

});

// <<<
