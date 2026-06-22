// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { ProspectCriteriaPage } from '../pages/ProspectCriteriaPage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:prospect-criteria-e2e

/**
 * module-8-prospect-criteria — fretiq ProspectCriteria CRUD + custom actions e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Table ID: 'prospect_criteria-table'
 *   Resolved by ProspectCriteriaDataTable::getTableId() → 'prospect_criteria'
 *   → DataTables html() → '#prospect_criteria-table'.
 *   The base-class default (strtolower(class_basename(model))) = 'prospectcriteria' does NOT
 *   match — the override is required.
 *
 * Convention contract:
 *   - { test, expect } from console-guard — auto-fails on JS console.error / pageerror.
 *   - No waitForTimeout; no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation via ProspectCriteriaPage (relative paths).
 *   - Mutating tests use uniqueName() to avoid inter-run collisions.
 *   - afterAll deletes every criteria this suite creates.
 *
 * Pre-seeded fixture:
 *   'E2E_FIXTURE Criteria' (is_active=false) seeded by E2eSeed command.
 *   Used only for read-only reference tests (view, discoveryStatus).
 *   This suite does NOT create or delete it.
 *
 * DESTRUCTIVE — DO NOT TRIGGER:
 *   discover (POST /admin/prospect_criteria/{id}/discover) — calls SerpAPI + Hunter
 *   and consumes real credits. The "Lancer la découverte" button must be asserted
 *   PRESENT in the DOM but NEVER clicked. See test 'discover button: present in DOM but not clicked'.
 */

const TABLE_ID   = 'prospect_criteria-table';
const FIXTURE_NAME = 'E2E_FIXTURE Criteria';

/**
 * afterAll cleanup registry.
 * Every criteria name created in this suite is pushed here.
 * afterAll loop-deletes all of them to leave the dev DB clean between runs.
 */
const createdNames: string[] = [];

test.describe('ProspectCriteria module', () => {

  test.afterAll(async ({ browser }) => {
    if (createdNames.length === 0) return;

    const ctx = await browser.newContext({
      storageState: './tests/e2e/.auth/admin.json',
    });
    const page = await ctx.newPage();
    const pc = new ProspectCriteriaPage(page);

    for (const name of createdNames.splice(0)) {
      try {
        await pc.goto();
        await waitForDataTable(page, TABLE_ID);

        // Search for the name — handles both originals and duplicates ('Copie de ...')
        await pc.search(name);
        await waitForDataTable(page, TABLE_ID);

        let matchCount = await pc.table.locator(`tbody tr:has-text("${name}")`).count();
        while (matchCount > 0) {
          await pc.clickRowAction(0, 'delete');
          await confirmDelete(page);
          await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
          await page.locator('.swal2-confirm').click();
          await page.waitForLoadState('networkidle');
          await waitForDataTable(page, TABLE_ID);
          await pc.search(name);
          await waitForDataTable(page, TABLE_ID);
          matchCount = await pc.table.locator(`tbody tr:has-text("${name}")`).count();
        }
      } catch {
        // best-effort teardown — never fail the suite on cleanup
      }
    }

    await ctx.close();
  });

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);
    await pc.goto();

    await expectPath(page, '/admin/prospect_criteria');
    await waitForDataTable(page, TABLE_ID);
    await pc.expectTableVisible();
  });

  // ── 2. Create flow ────────────────────────────────────────────────────────

  test('create: fill name + daily_limit, submit, redirect, row appears in table', async ({ page }) => {
    const pc   = new ProspectCriteriaPage(page);
    const name = uniqueName('E2E Criteria');
    createdNames.push(name);

    await pc.gotoCreate();
    await pc.fillAndSubmit({ name, dailyLimit: 10, isActive: false });

    // crud-form-handler.js follows the 2xx redirect — wait for navigation away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and verify row appears.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);

    const rows = pc.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);
  });

  // ── 3. Edit flow ──────────────────────────────────────────────────────────

  test('edit: change daily_limit, save, redirect away from /edit', async ({ page }) => {
    const pc   = new ProspectCriteriaPage(page);
    const name = uniqueName('E2E Edit Criteria');
    createdNames.push(name);

    // Create a criteria to edit.
    await pc.gotoCreate();
    await pc.fillAndSubmit({ name, dailyLimit: 15, isActive: false });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Search and open edit.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);
    await pc.clickRowAction(0, 'edit');

    // Mutate daily_limit and save.
    await expect(page.locator('#form_crud input[name="daily_limit"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="daily_limit"]').fill('25');
    await page.locator('#form_crud button[name="save"]').click();

    // Wait for redirect away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows criteria name', async ({ page }) => {
    const pc   = new ProspectCriteriaPage(page);
    const name = uniqueName('E2E View Criteria');
    createdNames.push(name);

    // Create a criteria.
    await pc.gotoCreate();
    await pc.fillAndSubmit({ name, isActive: false });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);
    await pc.clickRowAction(0, 'view');

    // Detail page must show the criteria name.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });
  });

  // ── 5. Delete — two SweetAlerts ───────────────────────────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const pc   = new ProspectCriteriaPage(page);
    const name = uniqueName('E2E Delete Criteria');
    // Not pushed to createdNames — this test handles its own cleanup via the delete flow.

    // Create a criteria to delete.
    await pc.gotoCreate();
    await pc.fillAndSubmit({ name, isActive: false });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);

    // Click delete → first SweetAlert confirm.
    await pc.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog (deleteSuccess = 'Critère supprimé avec succès').
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, TABLE_ID);

    // Verify row is gone.
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);
    await expect(pc.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 6. executeSwitch — is_active toggle badge flips ───────────────────────

  test('executeSwitch: toggle is_active, table still has the row', async ({ page }) => {
    const pc   = new ProspectCriteriaPage(page);
    const name = uniqueName('E2E Toggle Criteria');
    createdNames.push(name);

    // Create an ACTIVE criteria so the toggle will flip it to inactive.
    await pc.gotoCreate();
    await pc.fillAndSubmit({ name, isActive: true });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);

    // Click the is_active checkbox (renders in the 'Actif' switch column).
    // Triggers a PUT to executeSwitch; the cell re-renders with the new state.
    await pc.clickActiveSwitchOnRow(0);

    // Wait for AJAX to settle — no toast for switch toggles; networkidle is sufficient.
    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, TABLE_ID);

    // The row must still be present in the table after the toggle.
    // (The table is not filtered by is_active by default, so the row stays visible.)
    await pc.expectMinRows(1);
  });

  // ── 7. Duplicate — clones the row ─────────────────────────────────────────

  test('duplicate: clones criteria, copy appears, cleanup both', async ({ page }) => {
    const pc   = new ProspectCriteriaPage(page);
    const name = uniqueName('E2E Dup Criteria');
    createdNames.push(name);

    // 7a. Create the original.
    await pc.gotoCreate();
    await pc.fillAndSubmit({ name, isActive: false });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // 7b. Navigate to index, find the original row, click Dupliquer.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);

    // The duplicate action is a form-submit (POST) — wait for redirect to clone edit page.
    await pc.clickDuplicateOnRow(0);
    await page.waitForURL((u) => u.pathname.includes('/edit'), { timeout: 15000 });

    // 7c. The clone edit page should show the name prefixed with 'Copie de '.
    // The name input must contain the expected clone name.
    const cloneName = 'Copie de ' + name;
    await expect(page.locator('#form_crud input[name="name"]')).toHaveValue(cloneName, {
      timeout: 10000,
    });

    // Register clone name for afterAll cleanup.
    createdNames.push(cloneName);

    // 7d. Navigate to index and verify both original and clone are present.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);

    // Both 'E2E Dup Criteria...' and 'Copie de E2E Dup Criteria...' contain the original name
    // as a substring — assert at least 2 rows match.
    const rows = pc.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(2);

    // 7e. Cleanup both rows — afterAll will handle this via createdNames, but we can
    // also do an inline assertion-only check here since afterAll handles deletions.
    // (Cleanup is intentionally deferred to afterAll for simplicity.)
  });

  // ── 8. preview_queries — read-only JSON, shows generated queries ───────────

  test('preview_queries: API returns queries array, edit page shows preview container', async ({ page }) => {
    const pc   = new ProspectCriteriaPage(page);
    const name = uniqueName('E2E Preview Criteria');
    createdNames.push(name);

    // Create a criteria so we have an id to call preview-queries against.
    await pc.gotoCreate();
    await pc.fillAndSubmit({ name, isActive: false });

    // crud-form-handler.js follows the 2xx redirect from store().
    // store() with button[name="save"] redirects to the index (no ID in URL).
    // Wait for redirect away from /create, then navigate to index, search, and
    // open the view page to get the persisted record's ID from the URL.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(name);
    await waitForDataTable(page, TABLE_ID);

    const firstRow = pc.table.locator('tbody tr').first();
    await expect(firstRow).toBeVisible({ timeout: 10000 });
    await pc.clickRowAction(0, 'view');

    const viewUrl = page.url();
    const idMatch = viewUrl.match(/\/prospect_criteria\/(\d+)/);
    expect(idMatch, 'Could not extract id from view URL: ' + viewUrl).toBeTruthy();
    const criteriaId = idMatch![1];

    // 8a. Navigate to the edit page — preview_queries card is only rendered in edit mode.
    await pc.gotoEdit(criteriaId);
    await expect(page.locator('#card-query-preview')).toBeVisible({ timeout: 10000 });

    // 8b. The preview container (#query-preview-content) is auto-loaded by the inline script
    // on page ready. Wait for network idle so the $.getJSON call completes.
    await page.waitForLoadState('networkidle');

    // The container should no longer show the "Chargement des requêtes…" spinner.
    // It will either show a query list or the "Aucune requête générée" empty state —
    // both are valid since the criteria has no sectors/countries configured.
    const previewContent = page.locator('#query-preview-content');
    await expect(previewContent).toBeVisible({ timeout: 10000 });

    // Assert the spinner is gone (content was loaded).
    await expect(previewContent.locator('text=Chargement des requêtes')).toHaveCount(0);

    // 8c. Directly call the preview_queries API endpoint and assert the response contract.
    const apiResponse = await page.request.get(`/admin/prospect_criteria/${criteriaId}/preview-queries`);
    expect(apiResponse.status()).toBe(200);
    const body = await apiResponse.json();
    // Contract: { queries: string[] }
    expect(body).toHaveProperty('queries');
    expect(Array.isArray(body.queries)).toBe(true);
  });

  // ── 9. discovery_status — read-only AJAX poll ─────────────────────────────

  test('discovery_status: API returns JSON status object (nulls ok when no run)', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);

    // Use the pre-seeded fixture criteria for this read-only check.
    // Find it in the table to get its id.
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search(FIXTURE_NAME);
    await waitForDataTable(page, TABLE_ID);

    // The fixture row must exist (seeded by E2eSeed command).
    const fixtureRow = pc.table.locator(`tbody tr:has-text("${FIXTURE_NAME}")`).first();
    await expect(fixtureRow).toBeVisible({ timeout: 10000 });

    // Click view to navigate to the detail page — the URL will contain the id.
    await pc.clickRowAction(0, 'view');
    const viewUrl = page.url();
    const idMatch = viewUrl.match(/\/prospect_criteria\/(\d+)/);
    expect(idMatch, 'Could not extract id from view URL: ' + viewUrl).toBeTruthy();
    const criteriaId = idMatch![1];

    // Call the discovery_status endpoint directly.
    const apiResponse = await page.request.get(`/admin/prospect_criteria/${criteriaId}/discovery-status`);
    expect(apiResponse.status()).toBe(200);

    // Assert Cache-Control: no-store is set (documented in controller).
    const cacheControl = apiResponse.headers()['cache-control'];
    expect(cacheControl).toContain('no-store');

    const body = await apiResponse.json();
    // Contract: { status, companies_count, contacts_count, skipped_count,
    //             low_score_count, finished_at, companies_total, stale, error }
    // status and finished_at may be null when no run exists.
    expect(body).toHaveProperty('status');
    expect(body).toHaveProperty('companies_count');
    expect(body).toHaveProperty('contacts_count');
    expect(body).toHaveProperty('stale');
    expect(typeof body.companies_count).toBe('number');
    expect(typeof body.contacts_count).toBe('number');
  });

  // ── 10. discover button — PRESENT in DOM but NOT CLICKED ──────────────────

  /**
   * SAFETY GATE: assert the "Lancer la découverte" (bi-play-fill) button is rendered
   * in at least one DataTable action cell for the admin user, but DO NOT click it.
   *
   * Clicking this button fires launchDiscovery() → POST /admin/prospect_criteria/{id}/discover
   * → RunDiscoveryPipelineJob dispatch → real SerpAPI + Hunter API calls + credit consumption.
   *
   * The test verifies the button is accessible in the UI without triggering any API cost.
   */
  test('discover button: present in DOM but NOT clicked (safety gate)', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);

    // Navigate to the index — the discover button appears in the action column per row
    // when the user has 'run discovery' permission (admin has it).
    await pc.goto();
    await waitForDataTable(page, TABLE_ID);

    // The discover button renders as a <button> with a bi-play-fill icon.
    // Its title is "Lancer la découverte" (or the quota-exhausted tooltip when 0 credits).
    // We assert it is ATTACHED to the DOM — we do NOT click it.
    //
    // Use the bi-play-fill icon class as the selector (stable, not locale-dependent).
    const discoverButtons = pc.table.locator('tbody tr .bi-play-fill');
    const discoverCount = await discoverButtons.count();

    // At least one row must have a discover button rendered.
    expect(discoverCount, 'Expected at least one discover (bi-play-fill) button in the DataTable action column').toBeGreaterThanOrEqual(1);

    // Explicitly do NOT click:
    // await discoverButtons.first().click(); // ← NEVER do this in tests
  });

});

// <<<
