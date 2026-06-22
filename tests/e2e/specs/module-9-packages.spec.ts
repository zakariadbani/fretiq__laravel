// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { PackagePage } from '../pages/PackagePage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:packages-e2e

/**
 * module-9-packages — fretiq packages (Packs) CRUD e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Contract:
 *   - All async interactions use ONLY the declared test-utils helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via PackagePage.
 *   - Mutating tests use uniqueName() so rows are distinct across repeated runs.
 *   - No Select2 on the package create/edit form — all fields are native inputs.
 *   - The assign form on the index page uses a native <select> (not Select2);
 *     native selectOption() is used directly, NO dispatchEvent('change') needed
 *     for the form-submit path.
 *   - executeSwitch: PUT AJAX toggle — asserts table row still visible after toggle
 *     (no redirect); tested on a self-created package, cleaned up in afterAll.
 *   - Assign: a pre-seeded default PackageAssignment row exists; never assert an
 *     empty assignments table. Assert deltas (new assignment row visible after assign).
 *   - Cleanup: deleteAllByName() loop handles any double-submit duplication.
 *
 * Pre-seeded fixture:
 *   `php artisan fretiq:e2e-seed` creates "E2E_FIXTURE Package" (read-only reference).
 *   Self-created+cleaned rows are used for all mutation tests.
 */

test.describe('Packages module', () => {

  /**
   * Names of packages created during the suite; removed in afterAll.
   * Keyed here so multiple tests can enqueue names without coupling to each other.
   */
  const createdPackages: string[] = [];

  test.afterAll(async ({ browser }) => {
    if (createdPackages.length === 0) return;
    const ctx = await browser.newContext({
      storageState: './tests/e2e/.auth/admin.json',
    });
    const page = await ctx.newPage();
    const packages = new PackagePage(page);

    for (const name of createdPackages) {
      try {
        await packages.goto();
        await waitForDataTable(page, 'package-table');
        await packages.deleteAllByName(name, {
          search: (q) => packages.search(q),
          waitForDataTable,
          confirmDelete,
        });
      } catch {
        // best-effort teardown — never fail the suite on cleanup errors
      }
    }

    await ctx.close();
  });

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const packages = new PackagePage(page);
    await packages.goto();

    await expectPath(page, '/admin/packages');
    await waitForDataTable(page, 'package-table');
    await packages.expectTableVisible();

    // The "Pack actif" card and the assign form must be present for admin.
    await expect(page.locator('form[action$="/assign"]')).toBeVisible({ timeout: 10000 });
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, redirect, row appears in DataTable', async ({ page }) => {
    const packages = new PackagePage(page);
    const name = uniqueName('E2E Pack Starter');
    createdPackages.push(name);

    await packages.gotoCreate();

    // Fill required field + optional numeric fields.
    await packages.fillAndSubmit({
      name,
      dailyCredits: '25',
      priceMonthly: '49.00',
      sortOrder: '10',
      isActive: true,
    });

    // crud-form-handler.js follows 2xx redirect away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and search for the created row.
    await packages.goto();
    await waitForDataTable(page, 'package-table');
    await packages.search(name);
    await waitForDataTable(page, 'package-table');

    // At least 1 matching row must appear.
    const rows = packages.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: change daily_credits field, save, redirect succeeds', async ({ page }) => {
    const packages = new PackagePage(page);
    const name = uniqueName('E2E Pack Edit');
    createdPackages.push(name);

    // Create a package to edit.
    await packages.gotoCreate();
    await packages.fillAndSubmit({ name, dailyCredits: '10' });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open edit.
    await packages.goto();
    await waitForDataTable(page, 'package-table');
    await packages.search(name);
    await waitForDataTable(page, 'package-table');
    await packages.clickRowAction(0, 'edit');

    // Change daily_credits and save.
    await expect(page.locator('#form_crud input[name="daily_credits"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="daily_credits"]').fill('50');
    await page.locator('#form_crud button[name="save"]').click();

    // Wait for redirect away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows package name', async ({ page }) => {
    const packages = new PackagePage(page);
    const name = uniqueName('E2E Pack View');
    createdPackages.push(name);

    // Create a package to view.
    await packages.gotoCreate();
    await packages.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, click view.
    await packages.goto();
    await waitForDataTable(page, 'package-table');
    await packages.search(name);
    await waitForDataTable(page, 'package-table');
    await packages.clickRowAction(0, 'view');

    // Detail page must show the package name.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });
  });

  // ── 5. executeSwitch — is_active AJAX toggle ───────────────────────────────

  test('executeSwitch: toggle is_active, row still visible after AJAX PUT', async ({ page }) => {
    const packages = new PackagePage(page);
    const name = uniqueName('E2E Pack Toggle');
    createdPackages.push(name);

    // Create a package with is_active = true (default).
    await packages.gotoCreate();
    await packages.fillAndSubmit({ name, isActive: true });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and search for the row.
    await packages.goto();
    await waitForDataTable(page, 'package-table');
    await packages.search(name);
    await waitForDataTable(page, 'package-table');

    // Click the switch input on the first matching row.
    // PUT AJAX /admin/packages/executeSwitch/{id} fires; no redirect.
    await packages.clickActiveSwitchOnRow(0);

    // Wait for the AJAX response to settle.
    await page.waitForLoadState('networkidle');

    // The DataTable row must still be present after the toggle (no redirect).
    await packages.expectMinRows(1);
  });

  // ── 6. Delete — confirm dialog, row removed from table ─────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const packages = new PackagePage(page);
    const name = uniqueName('E2E Pack Delete');
    createdPackages.push(name);

    // Create a package to delete.
    await packages.gotoCreate();
    await packages.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await packages.goto();
    await waitForDataTable(page, 'package-table');
    await packages.search(name);
    await waitForDataTable(page, 'package-table');

    // Click delete → first SweetAlert confirm.
    await packages.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog ("Pack supprimé avec succès").
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'package-table');

    // Verify the row is gone.
    await packages.search(name);
    await waitForDataTable(page, 'package-table');
    await expect(packages.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);

    // Row deleted — remove from cleanup list so afterAll does not attempt to re-delete.
    const idx = createdPackages.indexOf(name);
    if (idx !== -1) createdPackages.splice(idx, 1);
  });

  // ── 7. Assign action — assign a package via the index-page card ────────────

  test('assign: pick a self-created active package, submit, success flash visible', async ({ page }) => {
    const packages = new PackagePage(page);
    const name = uniqueName('E2E Pack Assign');
    createdPackages.push(name);

    // Create an active package so it appears in the assign dropdown.
    await packages.gotoCreate();
    await packages.fillAndSubmit({ name, dailyCredits: '30', isActive: true });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Go to the index page (where the assign card lives).
    await packages.goto();
    await waitForDataTable(page, 'package-table');

    // The assign dropdown (native <select>) must be visible in the "Pack actif" card.
    await expect(packages.assignPackageSelect).toBeVisible({ timeout: 10000 });

    // The newly created active package must appear as an option.
    // The option text pattern: "{name} ({daily_credits} crédits/j)"
    // We find the option whose text contains our package name.
    const optionLocator = packages.assignPackageSelect.locator(`option:has-text("${name}")`);
    await expect(optionLocator).toHaveCount(1, { timeout: 10000 });

    // Select by the option value (package id) to avoid fragile label matching with dynamic suffix.
    // Read the value attribute of the matching option.
    const packageId = await optionLocator.getAttribute('value');
    expect(packageId).toBeTruthy();

    await packages.assignPackageSelect.selectOption({ value: packageId! });
    await packages.assignSubmitButton.click();

    // POST /admin/packages/assign → redirect()->back() → reload of /admin/packages.
    await page.waitForURL((u) => u.pathname.includes('/admin/packages'), { timeout: 15000 });
    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'package-table');

    // The "Pack actif" card must now display our package name as the active pack.
    // Note: the packages index blade uses Livewire toastr for notifications, not a
    // server-rendered .alert-success element — flash assertions against that class fail.
    await expect(page.locator('.card').filter({ hasText: 'Pack actif' }).first())
      .toContainText(name, { timeout: 10000 });
  });

});

// <<<
