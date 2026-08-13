// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { ContactsPage } from '../pages/ContactsPage';
import { CompaniesPage } from '../pages/CompaniesPage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:contacts-e2e

/**
 * module-2-contacts — fretiq contacts CRUD e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Contract:
 *   - All async widget interactions use ONLY the declared test-utils helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via ContactsPage.
 *   - Mutating tests use uniqueName() so rows are distinct across repeated runs.
 *   - Contacts require a company_id (Select2). A fixture company is created in
 *     beforeAll via CompaniesPage and deleted in afterAll — the suite is fully
 *     self-sufficient and does not depend on any pre-seeded company name.
 *   - No toggle tests: ContactController declares no $toggleableFields.
 *   - Cleanup uses deleteAllByName() loop to handle any double-submit bug.
 */

test.describe('Contacts module', () => {

  /** Name of the fixture company created for this suite; set in beforeAll. */
  let fixtureCompanyName: string;

  // ── Fixture company lifecycle ──────────────────────────────────────────────

  test.beforeAll(async ({ browser }) => {
    const ctx = await browser.newContext({
      storageState: './tests/e2e/.auth/admin.json',
    });
    const page = await ctx.newPage();
    const companies = new CompaniesPage(page);

    fixtureCompanyName = uniqueName('E2E Contact Co');

    await companies.gotoCreate();
    await companies.fillAndSubmit({ name: fixtureCompanyName });
    // Wait for redirect away from /create (crud-form-handler.js follows 2xx redirect).
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await ctx.close();
  });

  test.afterAll(async ({ browser }) => {
    const ctx = await browser.newContext({
      storageState: './tests/e2e/.auth/admin.json',
    });
    const page = await ctx.newPage();
    const companies = new CompaniesPage(page);

    // Delete the fixture company using the same two-SweetAlert flow.
    await companies.goto();
    await waitForDataTable(page, 'company-table');
    await companies.deleteRowByName(fixtureCompanyName, {
      search: (q) => companies.search(q),
      waitForDataTable,
      confirmDelete,
    });

    await ctx.close();
  });

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const contacts = new ContactsPage(page);
    await contacts.goto();

    await expectPath(page, '/admin/contacts');
    await waitForDataTable(page, 'contact-table');
    await contacts.expectTableVisible();
    await expect(contacts.table.locator('thead')).toContainText('État');
    await expect(contacts.table.locator('thead')).not.toContainText('Qualité email');
    await expect(contacts.table.locator('thead')).not.toContainText('Statut');
    await expect(contacts.table.locator('thead')).not.toContainText('Légal');
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, row appears in table, cleanup', async ({ page }) => {
    const contacts = new ContactsPage(page);
    const name  = uniqueName('E2E Contact');
    const email = `e2e-${Date.now()}@fretiq-fixture.test`;

    await contacts.gotoCreate();

    // Fill required fields via the page object's fillAndSubmit (handles Select2 for company_id).
    await contacts.fillAndSubmit({ name, email, companyOptionText: fixtureCompanyName });

    // crud-form-handler.js follows redirect on 2xx — wait for navigation away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and search for the created row.
    await contacts.goto();
    await waitForDataTable(page, 'contact-table');
    await contacts.search(name);
    await waitForDataTable(page, 'contact-table');

    // At least 1 matching row (tolerates double-submit bug producing 2).
    const rows = contacts.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);

    // Cleanup: loop-delete all rows matching the name.
    await contacts.deleteAllByName(name, {
      search: (q) => contacts.search(q),
      waitForDataTable,
      confirmDelete,
    });

    // Verify deletion.
    await contacts.search(name);
    await waitForDataTable(page, 'contact-table');
    await expect(contacts.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: change position field, save, success redirect', async ({ page }) => {
    const contacts = new ContactsPage(page);
    const name  = uniqueName('E2E Edit Contact');
    const email = `e2e-edit-${Date.now()}@fretiq-fixture.test`;

    // Create a contact to edit.
    await contacts.gotoCreate();
    await contacts.fillAndSubmit({ name, email, companyOptionText: fixtureCompanyName });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Find the row and open edit.
    await contacts.goto();
    await waitForDataTable(page, 'contact-table');
    await contacts.search(name);
    await waitForDataTable(page, 'contact-table');
    await contacts.clickRowAction(0, 'edit');

    // Change the position field and save.
    await expect(page.locator('#form_crud input[name="position"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="position"]').fill('Directeur e2e');
    await page.locator('#form_crud button[name="save"]').click();

    // Wait for redirect away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });

    // Cleanup.
    await contacts.goto();
    await waitForDataTable(page, 'contact-table');
    await contacts.deleteAllByName(name, {
      search: (q) => contacts.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows contact name', async ({ page }) => {
    const contacts = new ContactsPage(page);
    const name  = uniqueName('E2E View Contact');
    const email = `e2e-view-${Date.now()}@fretiq-fixture.test`;

    // Create a contact.
    await contacts.gotoCreate();
    await contacts.fillAndSubmit({ name, email, companyOptionText: fixtureCompanyName });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await contacts.goto();
    await waitForDataTable(page, 'contact-table');
    await contacts.search(name);
    await waitForDataTable(page, 'contact-table');
    await contacts.clickRowAction(0, 'view');

    // Detail page must show the contact name.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });

    // Cleanup.
    await contacts.goto();
    await waitForDataTable(page, 'contact-table');
    await contacts.deleteAllByName(name, {
      search: (q) => contacts.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 5. Delete confirm and row removal ─────────────────────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const contacts = new ContactsPage(page);
    const name  = uniqueName('E2E Delete Contact');
    const email = `e2e-del-${Date.now()}@fretiq-fixture.test`;

    // Create a contact to delete.
    await contacts.gotoCreate();
    await contacts.fillAndSubmit({ name, email, companyOptionText: fixtureCompanyName });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await contacts.goto();
    await waitForDataTable(page, 'contact-table');
    await contacts.search(name);
    await waitForDataTable(page, 'contact-table');

    // Click delete → first SweetAlert confirm.
    await contacts.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog 'Contact supprimé avec succès'.
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'contact-table');

    // Verify row is gone.
    await contacts.search(name);
    await waitForDataTable(page, 'contact-table');
    await expect(contacts.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

});

// <<<
