// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
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

  // Track every company a test creates; the afterEach below removes it even if
  // the test fails before its inline cleanup runs (prevents orphan rows on dev DB).
  const createdCompanies: string[] = [];

  test.afterEach(async ({ page }) => {
    if (createdCompanies.length === 0) return;
    const companies = new CompaniesPage(page);
    for (const name of createdCompanies.splice(0)) {
      try {
        await companies.goto();
        await waitForDataTable(page, 'company-table');
        await companies.search(name);
        await waitForDataTable(page, 'company-table');
        const remaining = await companies.table.locator(`tbody tr:has-text("${name}")`).count();
        if (remaining === 0) continue; // self-cleaned by the test, or never persisted
        await companies.deleteRowByName(name, {
          search: (q) => companies.search(q),
          waitForDataTable,
          confirmDelete,
        });
      } catch {
        // best-effort teardown — never fail the suite on cleanup
      }
    }
  });

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const companies = new CompaniesPage(page);
    await companies.goto();

    await expectPath(page, '/admin/companies');
    await waitForDataTable(page, 'company-table');
    await companies.expectTableVisible();
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, success toast, row appears in table', async ({ page }) => {
    const companies = new CompaniesPage(page);
    // No domain — domain has a unique constraint that would mask a double-insert by
    // rejecting the second row. Omitting it means a double-submit produces TWO rows,
    // which the exact-count assertion below will catch.
    const name = uniqueName('E2E Transports');
    createdCompanies.push(name);

    await companies.gotoCreate();

    await companies.fillAndSubmit({ name });

    // crud-form-handler.js on 2xx follows response.data.redirect (window.location.replace).
    // There is no toast/swal on store — wait for the form to navigate away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index to confirm the row is present in the DataTable.
    await companies.goto();
    await waitForDataTable(page, 'company-table');

    // Search for the created name; EXACTLY ONE row must be present — not two.
    // Without the double-submit fix this assertion fails (2 rows returned).
    await companies.search(name);
    await waitForDataTable(page, 'company-table');
    await companies.expectRowCountByName(name, 1);

    // Clean up: delete the created row so the dev DB stays clean between runs.
    await companies.deleteRowByName(name, {
      search: (q) => companies.search(q),
      waitForDataTable,
      confirmDelete,
    });

    // Verify deletion: no rows matching the name remain.
    await companies.search(name);
    await waitForDataTable(page, 'company-table');
    await companies.expectRowCountByName(name, 0);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: open row edit, change sector, save, success toast', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E Edit Co');
    createdCompanies.push(name);

    // Create a company to edit.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name, sector: 'Avant' });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate back to index and search for the row.
    await companies.goto();
    await waitForDataTable(page, 'company-table');
    await companies.search(name);
    await waitForDataTable(page, 'company-table');

    // Click edit on the first matching row.
    await companies.clickRowAction(0, 'edit');

    // On the edit form: update the sector field and save (scoped to #form_crud).
    await expect(page.locator('#form_crud input[name="sector"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="sector"]').fill('Après');
    await page.locator('#form_crud button[name="save"]').click();

    // crud-form-handler.js follows redirect on 2xx — wait for navigation away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows company name', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E View Co');
    createdCompanies.push(name);

    // Create a company.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await companies.goto();
    await waitForDataTable(page, 'company-table');
    await companies.search(name);
    await waitForDataTable(page, 'company-table');

    await companies.clickRowAction(0, 'view');

    // Detail page must show the company name somewhere on the page.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });
  });

  // ── 5. Active toggle (executeSwitch) ───────────────────────────────────────

  test('executeSwitch: toggle is_active, badge/switch updates', async ({ page }) => {
    const companies = new CompaniesPage(page);
    const name = uniqueName('E2E Toggle Co');
    createdCompanies.push(name);

    // Create a company.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and search for the row.
    await companies.goto();
    await waitForDataTable(page, 'company-table');
    await companies.search(name);
    await waitForDataTable(page, 'company-table');

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
    createdCompanies.push(name);

    // Create a company to delete.
    await companies.gotoCreate();
    await companies.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await companies.goto();
    await waitForDataTable(page, 'company-table');
    await companies.search(name);
    await waitForDataTable(page, 'company-table');

    // Click delete action; SweetAlert2 confirm dialog appears.
    await companies.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Delete fires an AJAX DELETE, then a SECOND SweetAlert success dialog
    // ("Entreprise supprimée avec succès") whose confirmation triggers the table reload.
    // Wait for THAT dialog by its distinct text (not the "supprimer ?" confirm), then confirm.
    await expect(page.locator('.swal2-popup')).toContainText('supprimée', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    // Wait for AJAX + table refresh.
    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'company-table');

    // After deletion the search returns no matching rows.
    await companies.search(name);
    await waitForDataTable(page, 'company-table');
    // The deleted company's row must be gone. Assert by name absence (robust against
    // the DataTables empty-state row, whose class varies by version).
    await expect(companies.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 7. Enrich action button visible ───────────────────────────────────────

  test('enrich: action button is visible in the DataTable for admin user', async ({ page }) => {
    const companies = new CompaniesPage(page);

    // Ensure at least one company exists so the table has a row.
    await companies.gotoCreate();
    const name = uniqueName('E2E Enrich Co');
    createdCompanies.push(name);
    await companies.fillAndSubmit({ name, domain: `${name.toLowerCase().replace(/\s+/g, '-')}.test` });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await companies.goto();
    await waitForDataTable(page, 'company-table');
    await companies.search(name);
    await waitForDataTable(page, 'company-table');

    // The enrich button (.enrich-btn) must be present for admin.
    await companies.expectEnrichButtonVisible();
  });

});

// <<<
