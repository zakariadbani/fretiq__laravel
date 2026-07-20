// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { ProspectCriteriaPage } from '../pages/ProspectCriteriaPage';
import type { Locator, Page } from '@playwright/test';
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
 * DESTRUCTIVE WHEN UNMOCKED — DO NOT TRIGGER:
 *   discover (POST /admin/prospect_criteria/{id}/discover) — calls SerpAPI + Hunter
 *   and consumes real credits. The baseline safety test only asserts that the button
 *   exists. Progress tests may click it only after both POST and status routes have
 *   been installed with page.route(), so no discovery job or provider can run.
 */

const TABLE_ID   = 'prospect_criteria-table';
const FIXTURE_NAME = 'E2E_FIXTURE Criteria';

/**
 * afterAll cleanup registry.
 * Every criteria name created in this suite is pushed here.
 * afterAll loop-deletes all of them to leave the dev DB clean between runs.
 */
const createdNames: string[] = [];

async function openFixtureCriteria(page: Page): Promise<string> {
  const pc = new ProspectCriteriaPage(page);
  await pc.goto();
  await waitForDataTable(page, TABLE_ID);
  await pc.search(FIXTURE_NAME);
  await waitForDataTable(page, TABLE_ID);
  await pc.clickRowAction(0, 'view');

  const match = page.url().match(/\/prospect_criteria\/(\d+)/);
  expect(match, `Could not extract the fixture id from ${page.url()}`).toBeTruthy();
  return match![1];
}

function progressPayload(
  criteriaId: string | number,
  runId: number,
  overrides: Record<string, unknown> = {},
) {
  return {
    run_id: runId,
    prospect_criteria_id: Number(criteriaId),
    status: 'running',
    phase: 'collecting',
    searches_consumed: 0,
    searches_reserved: 4,
    candidates_processed: 0,
    candidates_total: 0,
    candidates_total_final: false,
    progress_percent: null,
    heartbeat_at: new Date().toISOString(),
    companies_count: 0,
    contacts_count: 0,
    successful_enrichments: 0,
    successful_enrichments_target: 4,
    contact_attempts_reserved: 4,
    contact_attempts_consumed: 0,
    excluded_count: 0,
    skipped_count: 0,
    companies_total: 0,
    stale: false,
    error: null,
    ...overrides,
  };
}

async function enableInterceptedLaunch(buttons: Locator): Promise<void> {
  // These scenarios intercept the POST before enabling anything. Removing a
  // quota-disabled presentation state keeps the UI contract deterministic and
  // can never dispatch a real discovery from the browser test.
  await buttons.evaluateAll(elements => {
    elements.forEach(element => {
      const button = element as HTMLButtonElement;
      button.disabled = false;
      button.dataset.discoveryStaticDisabled = 'false';
    });
  });
}

async function installDiscoveryPollTimerSpy(
  page: Page,
  accelerateRequestTimeout = false,
): Promise<void> {
  await page.addInitScript(({ accelerateRequestTimeout }) => {
    const browserWindow = window as typeof window & {
      __discoveryPollDelays?: number[];
      __discoveryReconcileDelays?: number[];
      __discoveryRequestTimeouts?: number[];
      __discoveryRequestTimeoutFirings?: number[];
    };
    const nativeSetTimeout = window.setTimeout.bind(window);
    browserWindow.__discoveryPollDelays = [];
    browserWindow.__discoveryReconcileDelays = [];
    browserWindow.__discoveryRequestTimeouts = [];
    browserWindow.__discoveryRequestTimeoutFirings = [];

    window.setTimeout = ((handler: TimerHandler, timeout?: number, ...args: any[]) => {
      const callbackSource = typeof handler === 'function'
        ? Function.prototype.toString.call(handler)
        : '';

      if (callbackSource.includes('request.timedOut = true')) {
        browserWindow.__discoveryRequestTimeouts!.push(Number(timeout || 0));
        if (accelerateRequestTimeout && typeof handler === 'function') {
          return nativeSetTimeout(() => {
            browserWindow.__discoveryRequestTimeoutFirings!.push(Number(timeout || 0));
            handler(...args);
          }, 100);
        }
      }

      // Only the tracker schedules this exact closure. DataTables, SweetAlert,
      // toastr and every other application timer retain their real delays.
      if (callbackSource.includes('poll(state)')) {
        browserWindow.__discoveryPollDelays!.push(Number(timeout || 0));
        return nativeSetTimeout(handler, 10, ...args);
      }
      if (callbackSource.includes('reconcileLaunch(state')) {
        browserWindow.__discoveryReconcileDelays!.push(Number(timeout || 0));
        return nativeSetTimeout(handler, 10, ...args);
      }

      return nativeSetTimeout(handler, timeout, ...args);
    }) as typeof window.setTimeout;
  }, { accelerateRequestTimeout });
}

function indexTrackerMarkup(
  criteriaId: string,
  runId: number | '',
  status: 'running' | 'failed' | '',
  draw: number,
): string {
  return `<div data-discovery-tracker data-discovery-context="index" data-criteria-id="${criteriaId}" ` +
    `data-run-id="${runId}" data-status="${status}" data-e2e-draw="${draw}" ` +
    `data-status-url="/admin/prospect_criteria/${criteriaId}/discovery-status?run_id=${runId}">` +
    '<span data-discovery-status-badge>En cours</span>' +
    '<span data-discovery-searches>0</span><span data-discovery-searches-total>2</span>' +
    '<span data-discovery-contact-attempts>0</span><span data-discovery-contact-attempts-total>2</span>' +
    '<span data-discovery-candidates>0</span><span data-discovery-candidates-total>1</span>' +
    '<span data-discovery-total-growing></span><div data-discovery-progress></div>' +
    '<div data-discovery-error class="d-none"></div></div>';
}

function dynamicallyEnabledDiscoveryAction(actionHtml: string, draw: number): string {
  return actionHtml.replace(
    /<button\b([^>]*\bdata-discovery-launch\b[^>]*)>/g,
    (_match, attributes: string) => {
      const normalized = attributes
        .replace(/\sdisabled\b/g, '')
        .replace(/data-discovery-static-disabled="true"/g, 'data-discovery-static-disabled="false"')
        .replace('data-discovery-launch', `data-discovery-launch data-e2e-draw="${draw}"`);
      return `<button${normalized}>`;
    },
  );
}

test.describe('ProspectCriteria module', () => {

  test.beforeEach(async ({ page }) => {
    // Last-resort safety net. Test-specific discover mocks are registered later
    // and therefore take precedence; an unmocked click can never reach Laravel.
    await page.route('**/admin/prospect_criteria/*/discover', async route => {
      await route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'error', text: 'Blocked unmocked discovery POST in e2e.' }),
      });
    });
  });

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
    const generalPane = page.locator('#criteria_general');
    const previewCard = generalPane.locator('#card-query-preview');
    await expect(previewCard).toBeVisible({ timeout: 10000 });

    // The preview belongs exclusively to Général and must disappear with its tab pane.
    await page.locator('a[href="#criteria_automatisation"]').click();
    await expect(page.locator('#criteria_automatisation')).toBeVisible();
    await expect(page.locator('#criteria_automatisation #card-query-preview')).toHaveCount(0);
    await expect(previewCard).not.toBeVisible();

    // Return to Général before asserting the asynchronously loaded preview content.
    await page.locator('a[href="#criteria_general"]').click();
    await expect(previewCard).toBeVisible();

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
    // Contact attempts are Hunter company lookups; contacts_count is contacts created.
    // status and finished_at may be null when no run exists.
    expect(body).toHaveProperty('status');
    expect(body).toHaveProperty('companies_count');
    expect(body).toHaveProperty('contacts_count');
    expect(body).toHaveProperty('successful_enrichments');
    expect(body).toHaveProperty('successful_enrichments_target');
    expect(body).toHaveProperty('contact_attempts_reserved');
    expect(body).toHaveProperty('contact_attempts_consumed');
    expect(body).toHaveProperty('stale');
    expect(body).toHaveProperty('run_id');
    expect(body).toHaveProperty('prospect_criteria_id');
    expect(body).toHaveProperty('phase');
    expect(body).toHaveProperty('searches_consumed');
    expect(body).toHaveProperty('searches_reserved');
    expect(body).toHaveProperty('candidates_processed');
    expect(body).toHaveProperty('candidates_total');
    expect(body).toHaveProperty('candidates_total_final');
    expect(body).toHaveProperty('progress_percent');
    expect(body).toHaveProperty('heartbeat_at');
    expect(typeof body.companies_count).toBe('number');
    expect(typeof body.contacts_count).toBe('number');
    expect(typeof body.successful_enrichments).toBe('number');
    expect(typeof body.successful_enrichments_target).toBe('number');
    expect(typeof body.contact_attempts_reserved).toBe('number');
    expect(typeof body.contact_attempts_consumed).toBe('number');
  });

  // ── 10. discover button — PRESENT in DOM but NOT CLICKED ──────────────────

  /**
   * SAFETY GATE: assert the "Lancer la découverte" (bi-play-fill) button is rendered
   * in at least one DataTable action cell for the admin user, but DO NOT click it.
   *
   * Clicking this button delegates to the shared tracker → POST /admin/prospect_criteria/{id}/discover
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

  test('discovery launch controls: every same-criteria button declares one static-disable contract', async ({ page }) => {
    const criteriaId = await openFixtureCriteria(page);
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);

    const contracts = await launchButtons.evaluateAll(buttons => buttons.map(element => {
      const button = element as HTMLButtonElement;
      return {
        staticDisabled: button.dataset.discoveryStaticDisabled || null,
        disabled: button.disabled,
        launchUrl: button.dataset.launchUrl || null,
        statusUrl: button.dataset.statusUrl || null,
      };
    }));

    expect(contracts.length, 'Expected the header and results launch controls for the fixture').toBeGreaterThanOrEqual(2);
    expect(contracts.every(contract => ['true', 'false'].includes(String(contract.staticDisabled)))).toBe(true);
    expect(new Set(contracts.map(contract => contract.staticDisabled)).size).toBe(1);
    expect(new Set(contracts.map(contract => contract.launchUrl)).size).toBe(1);
    expect(new Set(contracts.map(contract => contract.statusUrl)).size).toBe(1);

    if (contracts[0].staticDisabled === 'true') {
      expect(contracts.every(contract => contract.disabled)).toBe(true);
    }
  });

  test('discovery tracker: an untrackable launch failure survives an idle DataTable redraw without starting polling', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);
    const failureMessage = 'Le lancement est refusé par la validation.';
    let criteriaId: string | null = null;
    let templateRow: Record<string, unknown> | null = null;
    let dataDraws = 0;
    let statusPolls = 0;

    await page.route('**/admin/prospect_criteria?**', async route => {
      const url = new URL(route.request().url());
      if (!url.searchParams.has('draw')) {
        await route.continue();
        return;
      }

      const response = await route.fetch();
      const body = await response.json();
      if (!Array.isArray(body.data) || body.data.length === 0) {
        await route.fulfill({ response, json: body });
        return;
      }

      if (!templateRow) templateRow = structuredClone(body.data[0]);
      dataDraws++;
      const row = structuredClone(templateRow) as Record<string, unknown>;
      const actionHtml = String(row.action || '');
      const idMatch = actionHtml.match(/data-criteria-id="(\d+)"/);
      expect(idMatch, 'The intercepted DataTable row must carry a discovery criteria id').toBeTruthy();
      criteriaId = idMatch![1];
      row.action = dynamicallyEnabledDiscoveryAction(actionHtml, dataDraws);
      row.last_discovery = indexTrackerMarkup(criteriaId, '', '', dataDraws);
      body.data = [row];
      body.recordsTotal = 1;
      body.recordsFiltered = 1;
      await route.fulfill({ response, json: body });
    });
    await page.route('**/admin/prospect_criteria/*/discover', async route => {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'error', text: failureMessage }),
      });
    });
    await page.route('**/admin/prospect_criteria/*/discovery-status**', async route => {
      statusPolls++;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: null, phase: 'idle', run_id: null }),
      });
    });

    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    expect(criteriaId).toBeTruthy();

    const tracker = page.locator(`[data-discovery-tracker][data-criteria-id="${criteriaId!}"]`);
    const launchButton = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId!}"]`);
    await launchButton.click();
    await page.locator('.swal2-confirm').click();

    await expect(tracker.locator('[data-discovery-error]')).toContainText(failureMessage);
    await expect(launchButton).toBeEnabled();

    const drawBaseline = dataDraws;
    await page.evaluate(() => {
      const browserWindow = window as any;
      browserWindow.LaravelDataTables['prospect_criteria-table'].ajax.reload(null, false);
    });
    await expect.poll(() => dataDraws).toBeGreaterThan(drawBaseline);
    await expect(launchButton).toHaveAttribute('data-e2e-draw', String(dataDraws));

    // Run the redraw scan synchronously too: a stale optimistic `pending` state
    // used to attach to the generic URL here and disable the fresh row forever.
    await page.evaluate(() => (window as any).DiscoveryProgressTracker.scan());
    await expect(launchButton).toBeEnabled();
    await expect(tracker.locator('[data-discovery-error]')).toContainText(failureMessage);
    expect(statusPolls).toBe(0);
  });

  test('discovery tracker: server-provided Toastr text is escaped as text', async ({ page }) => {
    const criteriaId = await openFixtureCriteria(page);
    const hostileMessage = '<img src=x onerror="window.__discoveryToastXss=true"> Refus contrôlé.';

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'error', text: hostileMessage }),
      });
    });

    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();

    const toast = page.locator('.toastr, #toast-container .toast, .toast').filter({ hasText: 'Refus contrôlé.' }).first();
    await expect(toast).toContainText(hostileMessage);
    await expect(toast.locator('img')).toHaveCount(0);
    expect(await page.evaluate(() => (window as any).__discoveryToastXss)).toBeUndefined();
  });

  // ── 11. Shared progress tracker — every discovery request is intercepted ─

  test('discovery tracker: first run collects, processes, succeeds, and reloads detail exactly once', async ({ page }) => {
    const criteriaId = await openFixtureCriteria(page);
    const runId = 987601;
    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="view"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);

    // The seeded fixture is intentionally never discovered: the complete tracker
    // shell and both counters must nevertheless be visible before the first POST.
    await expect(tracker).toBeVisible();
    await expect(tracker).toHaveAttribute('data-run-id', '');
    await expect(tracker.locator('[data-discovery-status-badge]')).toContainText('Jamais lancée');
    await expect(tracker.locator('[data-discovery-searches]')).toHaveText('0');
    await expect(tracker.locator('[data-discovery-candidates]')).toHaveText('0');
    expect(await launchButtons.count()).toBeGreaterThanOrEqual(2); // header + action rapide

    let postCalls = 0;
    let polls = 0;
    let detailReloads = 0;
    const polledUrls: string[] = [];
    const detailPath = new URL(page.url()).pathname;

    page.on('request', request => {
      const url = new URL(request.url());
      if (request.isNavigationRequest() && url.pathname === detailPath) detailReloads++;
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      postCalls++;
      expect(route.request().method()).toBe('POST');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'success', status: 'pending', run_id: runId,
          status_url: `/admin/prospect_criteria/${criteriaId}/discovery-status?run_id=${runId}`,
        }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      polls++;
      polledUrls.push(route.request().url());
      const payloads = [
        progressPayload(criteriaId, runId, {
          phase: 'collecting', searches_consumed: 1, candidates_total: 3,
        }),
        progressPayload(criteriaId, runId, {
          phase: 'processing', searches_consumed: 4, candidates_processed: 2,
          candidates_total: 4, candidates_total_final: true, progress_percent: 50,
          contact_attempts_consumed: 2,
        }),
        progressPayload(criteriaId, runId, {
          status: 'completed', phase: 'completed', searches_consumed: 4,
          candidates_processed: 4, candidates_total: 4, candidates_total_final: true,
          progress_percent: 100, companies_count: 2, contacts_count: 1, companies_total: 2,
          contact_attempts_consumed: 4,
        }),
      ];
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(payloads[Math.min(polls - 1, payloads.length - 1)]),
      });
    });

    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();
    for (let index = 0; index < await launchButtons.count(); index++) {
      await expect(launchButtons.nth(index)).toBeDisabled();
    }

    await expect(tracker.locator('[data-discovery-phase]')).toContainText('Collecte');
    await expect(tracker.locator('[data-discovery-searches]')).toHaveText('1');
    await expect(tracker.locator('[data-discovery-candidates-total]')).toHaveText('3');
    await expect(tracker.locator('[data-discovery-progress]')).not.toHaveAttribute('aria-valuenow');

    await expect(tracker.locator('[data-discovery-phase]')).toContainText('Traitement', { timeout: 8000 });
    await expect(tracker.locator('[data-discovery-candidates]')).toHaveText('2');
    await expect(tracker.locator('[data-discovery-contact-attempts]')).toHaveText('2');
    await expect(tracker.locator('[data-discovery-contact-attempts-total]')).toHaveText('4');
    await expect(tracker.locator('[data-discovery-progress]')).toHaveAttribute('aria-valuenow', '50');

    await expect.poll(() => detailReloads, { timeout: 12000 }).toBe(1);
    await page.waitForLoadState('domcontentloaded');
    expect(detailReloads).toBe(1);
    expect(postCalls).toBe(1);
    expect(polls).toBe(3);
    expect(polledUrls.every(url => new URL(url).searchParams.get('run_id') === String(runId))).toBe(true);
  });

  test('discovery tracker: a 409 attaches to the exact run and failed detail reloads once', async ({ page }) => {
    const criteriaId = await openFixtureCriteria(page);
    const runId = 987602;
    let polls = 0;
    let observedRunId: string | null = null;
    let detailReloads = 0;
    let releaseFailure!: () => void;
    const failureGate = new Promise<void>(resolve => {
      releaseFailure = resolve;
    });
    const detailPath = new URL(page.url()).pathname;

    page.on('request', request => {
      const url = new URL(request.url());
      if (request.isNavigationRequest() && url.pathname === detailPath) detailReloads++;
    });

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      await route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'error', text: 'Une découverte est déjà en cours.', status: 'running', run_id: runId,
          status_url: `/admin/prospect_criteria/${criteriaId}/discovery-status?run_id=${runId}`,
        }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      polls++;
      observedRunId = new URL(route.request().url()).searchParams.get('run_id');
      await failureGate;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId, runId, {
          status: 'failed', phase: 'failed', error: 'Fin contrôlée du run existant.',
        })),
      });
    });

    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="view"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();

    try {
      await expect(tracker).toHaveAttribute('data-run-id', String(runId));
      await expect.poll(() => observedRunId).toBe(String(runId));
    } finally {
      releaseFailure();
    }
    await expect.poll(() => detailReloads, { timeout: 10000 }).toBe(1);
    await page.waitForLoadState('domcontentloaded');
    expect(detailReloads).toBe(1);
    expect(polls).toBe(1);
  });

  for (const ambiguity of [
    { label: 'network failure', kind: 'abort' as const },
    { label: 'HTTP 408', kind: 'status' as const, status: 408 },
    { label: 'HTTP 429', kind: 'status' as const, status: 429 },
    { label: 'HTTP 503', kind: 'status' as const, status: 503 },
  ]) {
    test(`discovery tracker: ${ambiguity.label} launch response reconciles the active exact run`, async ({ page }) => {
      const pc = new ProspectCriteriaPage(page);
      const criteriaId = await openFixtureCriteria(page);
      await pc.gotoEdit(criteriaId);
      const runId = 988000 + (ambiguity.status || 1);
      let postCalls = 0;
      let genericChecks = 0;
      let exactPolls = 0;

      await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
        postCalls++;
        if (ambiguity.kind === 'abort') {
          await route.abort('connectionfailed');
          return;
        }
        await route.fulfill({
          status: ambiguity.status,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'error', text: `Réponse ambiguë ${ambiguity.status}.` }),
        });
      });
      await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
        const requestedRunId = new URL(route.request().url()).searchParams.get('run_id');
        if (requestedRunId === null) {
          genericChecks++;
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(progressPayload(criteriaId, runId, {
              status: 'running', phase: 'collecting',
            })),
          });
          return;
        }

        exactPolls++;
        expect(requestedRunId).toBe(String(runId));
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(progressPayload(criteriaId, runId, {
            status: 'failed', phase: 'failed', error: 'Fin contrôlée du run réconcilié.',
          })),
        });
      });

      const tracker = page.locator('[data-discovery-tracker][data-discovery-context="edit"]');
      const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
      await enableInterceptedLaunch(launchButtons);
      await launchButtons.first().click();
      await page.locator('.swal2-confirm').click();

      await expect(tracker).toHaveAttribute('data-run-id', String(runId));
      await expect(tracker.locator('[data-discovery-error]')).toContainText('Fin contrôlée du run réconcilié');
      expect(postCalls).toBe(1);
      expect(genericChecks).toBe(1);
      expect(exactPolls).toBe(1);
    });
  }

  test('discovery tracker: reconciliation tolerates idle once before the new active run becomes visible', async ({ page }) => {
    await installDiscoveryPollTimerSpy(page);
    const pc = new ProspectCriteriaPage(page);
    const criteriaId = await openFixtureCriteria(page);
    await pc.gotoEdit(criteriaId);
    const runId = 988610;
    let postCalls = 0;
    let genericChecks = 0;
    let exactPolls = 0;

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      postCalls++;
      await route.fulfill({ status: 503, contentType: 'application/json', body: '{}' });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      const requestedRunId = new URL(route.request().url()).searchParams.get('run_id');
      if (requestedRunId !== null) {
        exactPolls++;
        expect(requestedRunId).toBe(String(runId));
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(progressPayload(criteriaId, runId, {
            status: 'failed', phase: 'failed', error: 'Fin contrôlée après visibilité différée.',
          })),
        });
        return;
      }

      genericChecks++;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(genericChecks === 1
          ? progressPayload(criteriaId, 0, { run_id: null, status: null, phase: 'idle' })
          : progressPayload(criteriaId, runId)),
      });
    });

    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="edit"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();

    await expect(tracker.locator('[data-discovery-error]')).toContainText('visibilité différée');
    expect(postCalls).toBe(1);
    expect(genericChecks).toBe(2);
    expect(exactPolls).toBe(1);
    await expect.poll(() => page.evaluate(() => {
      const browserWindow = window as typeof window & { __discoveryReconcileDelays?: number[] };
      return browserWindow.__discoveryReconcileDelays || [];
    })).toEqual([3000]);
  });

  test('discovery tracker: reconciliation accepts a newly visible terminal run without polling or relaunching', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);
    const criteriaId = await openFixtureCriteria(page);
    await pc.gotoEdit(criteriaId);
    const runId = 988611;
    let postCalls = 0;
    let genericChecks = 0;
    let exactPolls = 0;

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      postCalls++;
      await route.fulfill({ status: 503, contentType: 'application/json', body: '{}' });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      const requestedRunId = new URL(route.request().url()).searchParams.get('run_id');
      if (requestedRunId !== null) exactPolls++;
      genericChecks++;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId, runId, {
          status: 'completed', phase: 'completed', progress_percent: 100,
          candidates_total_final: true,
        })),
      });
    });

    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="edit"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();

    await expect(tracker).toHaveAttribute('data-run-id', String(runId));
    await expect(tracker).toHaveAttribute('data-status', 'completed');
    await expect(tracker.locator('[data-discovery-error]')).toContainText('Découverte terminée');
    expect(postCalls).toBe(1);
    expect(genericChecks).toBe(1);
    expect(exactPolls).toBe(0);
  });

  test('discovery tracker: a suspended status fetch hits the application timeout and retries silently', async ({ page }) => {
    await installDiscoveryPollTimerSpy(page, true);
    const pc = new ProspectCriteriaPage(page);
    const criteriaId = await openFixtureCriteria(page);
    await pc.gotoEdit(criteriaId);
    const runId = 988612;
    let polls = 0;
    let releaseSuspendedPoll!: () => void;
    const suspendedPollGate = new Promise<void>(resolve => {
      releaseSuspendedPoll = resolve;
    });

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'pending', run_id: runId }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      polls++;
      if (polls === 1) {
        await suspendedPollGate;
        try {
          await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
        } catch {
          // The application AbortController already cancelled this request.
        }
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId, runId, {
          status: 'failed', phase: 'failed', error: 'Fin après timeout applicatif.',
        })),
      });
    });

    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="edit"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();

    try {
      await expect(tracker.locator('[data-discovery-error]')).toContainText('timeout applicatif');
      expect(polls).toBe(2);
      await expect.poll(() => page.evaluate(() => {
        const browserWindow = window as typeof window & { __discoveryRequestTimeoutFirings?: number[] };
        return browserWindow.__discoveryRequestTimeoutFirings || [];
      })).toEqual([15000]);
    } finally {
      releaseSuspendedPoll();
    }
  });

  test('discovery tracker: launch reconciliation retries transient checks with capped backoff and no duplicate POST', async ({ page }) => {
    await installDiscoveryPollTimerSpy(page);
    const pc = new ProspectCriteriaPage(page);
    const criteriaId = await openFixtureCriteria(page);
    await pc.gotoEdit(criteriaId);
    const runId = 988503;
    let postCalls = 0;
    let genericChecks = 0;
    let exactPolls = 0;
    let releaseActiveRun!: () => void;
    const activeRunGate = new Promise<void>(resolve => {
      releaseActiveRun = resolve;
    });
    const transientStatuses = [503, 408, 425, 429];

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      postCalls++;
      await route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'error', text: 'Réponse de lancement incertaine.' }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      const requestedRunId = new URL(route.request().url()).searchParams.get('run_id');
      if (requestedRunId !== null) {
        exactPolls++;
        expect(requestedRunId).toBe(String(runId));
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(progressPayload(criteriaId, runId, {
            status: 'failed', phase: 'failed', error: 'Fin contrôlée après réconciliation.',
          })),
        });
        return;
      }

      genericChecks++;
      const transientStatus = transientStatuses[genericChecks - 1];
      if (transientStatus) {
        await route.fulfill({ status: transientStatus, contentType: 'application/json', body: '{}' });
        return;
      }

      await activeRunGate;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId, runId)),
      });
    });

    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="edit"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();

    try {
      await expect.poll(() => genericChecks, { timeout: 5000 }).toBe(5);
      expect(postCalls).toBe(1);
      for (let index = 0; index < await launchButtons.count(); index++) {
        await expect(launchButtons.nth(index)).toBeDisabled();
      }
      await expect.poll(() => page.evaluate(() => {
        const browserWindow = window as typeof window & { __discoveryReconcileDelays?: number[] };
        return browserWindow.__discoveryReconcileDelays || [];
      })).toEqual([3000, 6000, 12000, 24000]);
    } finally {
      releaseActiveRun();
    }

    await expect(tracker.locator('[data-discovery-error]')).toContainText('Fin contrôlée après réconciliation');
    expect(postCalls).toBe(1);
    expect(exactPolls).toBe(1);
  });

  for (const fatalStatus of [401, 403, 404, 419]) {
    test(`discovery tracker: fatal ${fatalStatus} blocks polling and keeps every launch action disabled`, async ({ page }) => {
      const criteriaId = await openFixtureCriteria(page);
      const runId = 987600 + fatalStatus;
      let postCalls = 0;
      let polls = 0;

      await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
        postCalls++;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'success', status: 'pending', run_id: runId,
            status_url: `/admin/prospect_criteria/${criteriaId}/discovery-status?run_id=${runId}`,
          }),
        });
      });
      await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
        polls++;
        expect(new URL(route.request().url()).searchParams.get('run_id')).toBe(String(runId));
        await route.fulfill({ status: fatalStatus, contentType: 'application/json', body: '{}' });
      });

      const tracker = page.locator('[data-discovery-tracker][data-discovery-context="view"]');
      const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
      expect(await launchButtons.count()).toBeGreaterThanOrEqual(2);
      await enableInterceptedLaunch(launchButtons);
      await launchButtons.first().click();
      await page.locator('.swal2-confirm').click();

      await expect(tracker.locator('[data-discovery-error]')).toContainText('Rechargez la page');
      for (let index = 0; index < await launchButtons.count(); index++) {
        await expect(launchButtons.nth(index)).toBeDisabled();
      }
      expect(postCalls).toBe(1);
      expect(polls).toBe(1);
    });
  }

  test('discovery tracker: network, 408, 425, 429, and 5xx retries follow capped backoff', async ({ page }) => {
    await installDiscoveryPollTimerSpy(page);
    const pc = new ProspectCriteriaPage(page);
    const criteriaId = await openFixtureCriteria(page);
    await pc.gotoEdit(criteriaId);

    const runId = 987604;
    let polls = 0;
    let navigations = 0;
    let releaseTerminalPoll!: () => void;
    const terminalPollGate = new Promise<void>(resolve => {
      releaseTerminalPoll = resolve;
    });
    const transientAttempts = [
      { kind: 'network-connection', abort: 'connectionfailed' as const },
      { kind: 'server-500', status: 500 },
      { kind: 'request-timeout-408', status: 408 },
      { kind: 'too-early-425', status: 425 },
      { kind: 'rate-limit-429', status: 429 },
      { kind: 'server-503', status: 503 },
      { kind: 'network-timeout', abort: 'timedout' as const },
      { kind: 'server-502', status: 502 },
    ];
    const observedAttempts: string[] = [];
    const editUrl = page.url();
    const nameInput = page.locator('#form_crud input[name="name"]');
    await nameInput.fill('Modification non enregistrée');
    page.on('framenavigated', frame => {
      if (frame === page.mainFrame()) navigations++;
    });

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'success', status: 'pending', run_id: runId,
          status_url: `/admin/prospect_criteria/${criteriaId}/discovery-status?run_id=${runId}`,
        }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      polls++;
      expect(new URL(route.request().url()).searchParams.get('run_id')).toBe(String(runId));

      const transient = transientAttempts[polls - 1];
      if (transient) {
        observedAttempts.push(transient.kind);
        if ('abort' in transient) {
          await route.abort(transient.abort);
          return;
        }
        await route.fulfill({ status: transient.status, contentType: 'application/json', body: '{}' });
        return;
      }

      observedAttempts.push('terminal-200');
      await terminalPollGate;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId, runId, {
          status: 'failed', phase: 'failed', error: 'Fin contrôlée après les reprises transitoires.',
        })),
      });
    });

    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="edit"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();

    try {
      await expect.poll(() => polls, { timeout: 5000 }).toBe(9);
      await expect(tracker.locator('[data-discovery-error]')).toContainText('nouvelle tentative automatique');
      await expect.poll(() => page.evaluate(() => {
        const browserWindow = window as typeof window & { __discoveryPollDelays?: number[] };
        return browserWindow.__discoveryPollDelays || [];
      })).toEqual([3000, 6000, 12000, 24000, 30000, 30000, 30000, 30000]);
      expect(observedAttempts).toEqual([
        'network-connection',
        'server-500',
        'request-timeout-408',
        'too-early-425',
        'rate-limit-429',
        'server-503',
        'network-timeout',
        'server-502',
        'terminal-200',
      ]);
    } finally {
      releaseTerminalPoll();
    }

    await expect(tracker.locator('[data-discovery-error]')).toContainText('Fin contrôlée après les reprises');
    await expect(nameInput).toHaveValue('Modification non enregistrée');
    expect(page.url()).toBe(editUrl);
    expect(navigations).toBe(0);
    expect(polls).toBe(9);
  });

  test('discovery tracker: a DataTable redraw during an active run keeps the replacement launch control disabled', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);
    const runId = 887605;
    let criteriaId: string | null = null;
    let templateRow: Record<string, unknown> | null = null;
    let dataDraws = 0;
    let statusPolls = 0;

    await page.route('**/admin/prospect_criteria?**', async route => {
      const url = new URL(route.request().url());
      if (!url.searchParams.has('draw')) {
        await route.continue();
        return;
      }
      const response = await route.fetch();
      const body = await response.json();
      if (!Array.isArray(body.data) || body.data.length === 0) {
        await route.fulfill({ response, json: body });
        return;
      }

      if (!templateRow) templateRow = structuredClone(body.data[0]);
      dataDraws++;
      const row = structuredClone(templateRow) as Record<string, unknown>;
      const actionHtml = String(row.action || '');
      const idMatch = actionHtml.match(/data-criteria-id="(\d+)"/);
      expect(idMatch, 'The intercepted DataTable row must carry a discovery criteria id').toBeTruthy();
      criteriaId = idMatch![1];
      row.action = dynamicallyEnabledDiscoveryAction(actionHtml, dataDraws);
      row.last_discovery = indexTrackerMarkup(criteriaId, runId, 'running', dataDraws);
      body.data = [row];
      body.recordsTotal = 1;
      body.recordsFiltered = 1;
      await route.fulfill({ response, json: body });
    });
    await page.route('**/admin/prospect_criteria/*/discovery-status**', async route => {
      statusPolls++;
      expect(criteriaId).toBeTruthy();
      expect(new URL(route.request().url()).searchParams.get('run_id')).toBe(String(runId));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId!, runId, {
          status: 'running', phase: 'processing', candidates_processed: 1,
          candidates_total: 2, candidates_total_final: true, progress_percent: 50,
        })),
      });
    });

    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    expect(criteriaId).toBeTruthy();
    const tracker = page.locator(`[data-discovery-tracker][data-criteria-id="${criteriaId!}"]`);
    const launchButton = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId!}"]`);
    await expect(tracker).toBeVisible();
    await expect.poll(() => statusPolls).toBeGreaterThanOrEqual(1);
    await expect(launchButton).toHaveAttribute('data-e2e-draw', String(dataDraws));
    await expect(launchButton).toBeDisabled();

    const drawBaseline = dataDraws;
    await page.evaluate(() => {
      const browserWindow = window as any;
      browserWindow.LaravelDataTables['prospect_criteria-table'].ajax.reload(null, false);
    });
    await expect.poll(() => dataDraws).toBeGreaterThan(drawBaseline);
    await expect(launchButton).toHaveAttribute('data-e2e-draw', String(dataDraws));
    await expect(launchButton).toHaveAttribute('data-discovery-static-disabled', 'false');
    await expect(launchButton).toBeDisabled();
  });

  test('discovery tracker: index failure reloads in place and keeps the error through later redraws', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);
    const runId = 887606;
    const failureMessage = 'Erreur persistante après redraw.';
    let criteriaId: string | null = null;
    let templateRow: Record<string, unknown> | null = null;
    let dataDraws = 0;
    let statusPolls = 0;

    await page.route('**/admin/prospect_criteria?**', async route => {
      const url = new URL(route.request().url());
      if (!url.searchParams.has('draw')) {
        await route.continue();
        return;
      }
      const response = await route.fetch();
      const body = await response.json();
      if (!Array.isArray(body.data) || body.data.length === 0) {
        await route.fulfill({ response, json: body });
        return;
      }

      if (!templateRow) templateRow = structuredClone(body.data[0]);
      dataDraws++;
      const row = structuredClone(templateRow) as Record<string, unknown>;
      const actionHtml = String(row.action || '');
      const idMatch = actionHtml.match(/data-criteria-id="(\d+)"/);
      expect(idMatch, 'The intercepted DataTable row must carry a discovery criteria id').toBeTruthy();
      criteriaId = idMatch![1];
      row.action = dynamicallyEnabledDiscoveryAction(actionHtml, dataDraws);
      row.last_discovery = indexTrackerMarkup(
        criteriaId,
        runId,
        // Deliberately stale: a delayed DataTable response may still describe
        // the run as active after polling already confirmed the failure.
        'running',
        dataDraws,
      );
      body.data = [row];
      body.recordsTotal = 1;
      body.recordsFiltered = 1;
      await route.fulfill({ response, json: body });
    });
    await page.route('**/admin/prospect_criteria/*/discovery-status**', async route => {
      statusPolls++;
      expect(criteriaId).toBeTruthy();
      expect(new URL(route.request().url()).searchParams.get('run_id')).toBe(String(runId));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId!, runId, {
          status: 'failed', phase: 'failed', error: failureMessage,
        })),
      });
    });

    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    expect(criteriaId).toBeTruthy();
    const tracker = page.locator(`[data-discovery-tracker][data-criteria-id="${criteriaId!}"]`);
    await expect(tracker.locator('[data-discovery-error]')).toContainText(failureMessage);
    expect(dataDraws).toBeGreaterThanOrEqual(2);
    expect(statusPolls).toBe(1);

    const drawBaseline = dataDraws;
    await page.evaluate(() => {
      const browserWindow = window as any;
      browserWindow.LaravelDataTables['prospect_criteria-table'].ajax.reload(null, false);
    });
    await expect.poll(() => dataDraws).toBeGreaterThan(drawBaseline);
    await expect(tracker).toHaveAttribute('data-e2e-draw', String(dataDraws));
    await expect(tracker.locator('[data-discovery-error]')).toContainText(failureMessage);
    expect(statusPolls).toBe(1);
  });

  test('discovery tracker: launch tracking coexists with the separate contact-enrichment handler', async ({ page }) => {
    const criteriaId = await openFixtureCriteria(page);
    const runId = 987607;
    let discoveryPosts = 0;
    let discoveryPolls = 0;
    let enrichmentPreviews = 0;
    let enrichmentDispatches = 0;

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      discoveryPosts++;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'success', status: 'pending', run_id: runId,
          status_url: `/admin/prospect_criteria/${criteriaId}/discovery-status?run_id=${runId}`,
        }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      discoveryPolls++;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId, runId, {
          status: 'running', phase: 'processing', candidates_processed: 1,
          candidates_total: 2, candidates_total_final: true, progress_percent: 50,
        })),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/contact-enrichment/preview`, async route => {
      enrichmentPreviews++;
      expect(route.request().method()).toBe('GET');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          eligible_count: 0,
          callable_count: 0,
          success_target: 20,
          attempt_limit: 0,
          effective_min_score: 60,
          limit_note: 'Aucun appel fournisseur depuis ce test.',
          approval_token: 'intercepted-preview-only',
        }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/contact-enrichment`, async route => {
      enrichmentDispatches++;
      await route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'error', text: 'Blocked enrichment dispatch in e2e.' }),
      });
    });

    const tracker = page.locator('[data-discovery-tracker][data-discovery-context="view"]');
    const launchButtons = page.locator(`[data-discovery-launch][data-criteria-id="${criteriaId}"]`);
    const enrichmentButton = page.locator('#criteria-contact-enrichment-btn');
    expect(await launchButtons.count()).toBeGreaterThanOrEqual(2);
    await expect(enrichmentButton).toBeVisible();
    await expect(enrichmentButton).toBeEnabled();
    await expect(enrichmentButton).toHaveAttribute('onclick', /launchMissingContactEnrichment/);
    expect(await page.evaluate(() => typeof (window as any).launchMissingContactEnrichment)).toBe('function');

    await enableInterceptedLaunch(launchButtons);
    await launchButtons.first().click();
    await page.locator('.swal2-confirm').click();
    await expect.poll(() => discoveryPolls).toBeGreaterThanOrEqual(1);
    await expect(tracker.locator('[data-discovery-phase]')).toContainText('Traitement');
    for (let index = 0; index < await launchButtons.count(); index++) {
      await expect(launchButtons.nth(index)).toBeDisabled();
    }

    // The discovery selector/state must not capture or disable the independent
    // enrichment control. Its preview is intercepted and returns zero callable
    // companies, so the dispatch/provider path is never reached.
    await expect(enrichmentButton).toBeEnabled();
    await enrichmentButton.click();
    await expect(page.locator('.swal2-popup')).toContainText('Aucune entreprise à interroger');
    expect(enrichmentPreviews).toBe(1);
    expect(enrichmentDispatches).toBe(0);
    expect(discoveryPosts).toBe(1);
    for (let index = 0; index < await launchButtons.count(); index++) {
      await expect(launchButtons.nth(index)).toBeDisabled();
    }

    await page.locator('.swal2-confirm').click();
    await expect(enrichmentButton).toBeEnabled();
  });

  test('discovery tracker: index completion preserves search, page, and URL filter', async ({ page }) => {
    const pc = new ProspectCriteriaPage(page);
    let templateRow: Record<string, unknown> | null = null;
    let dataRequests = 0;
    const dataRequestUrls: string[] = [];

    await page.route('**/admin/prospect_criteria?**', async route => {
      const url = new URL(route.request().url());
      if (!url.searchParams.has('draw')) {
        await route.continue();
        return;
      }
      dataRequests++;
      dataRequestUrls.push(url.toString());
      const response = await route.fetch();
      const body = await response.json();
      if (!templateRow && Array.isArray(body.data) && body.data.length > 0) {
        templateRow = structuredClone(body.data[0]);
      }
      if (!templateRow) {
        await route.fulfill({ response, json: body });
        return;
      }

      const start = Number(url.searchParams.get('start') || 0);
      body.data = Array.from({ length: 10 }, (_, offset) => ({
        ...structuredClone(templateRow),
        name: `<div class="min-w-200px">Mock row ${start + offset + 1}</div>`,
      }));
      body.recordsTotal = 30;
      body.recordsFiltered = 30;
      await route.fulfill({ response, json: body });
    });

    await pc.goto();
    await waitForDataTable(page, TABLE_ID);
    await pc.search('Mock row');

    const filterRequestBaseline = dataRequests;
    await page.evaluate(() => {
      const browserWindow = window as any;
      const url = new URL(window.location.href);
      url.searchParams.set('is_active', '1');
      window.history.replaceState(null, '', url.toString());
      browserWindow.LaravelDataTables['prospect_criteria-table'].ajax.url(url.pathname + url.search).load();
    });
    await expect.poll(() => dataRequests).toBeGreaterThan(filterRequestBaseline);

    await page.evaluate(() => {
      const browserWindow = window as any;
      browserWindow.LaravelDataTables['prospect_criteria-table'].page(1).draw('page');
    });
    await expect.poll(() => page.evaluate(() => {
      const browserWindow = window as any;
      return browserWindow.LaravelDataTables['prospect_criteria-table'].page.info().page;
    })).toBe(1);

    const launchButton = pc.table.locator('tbody tr').first().locator('[data-discovery-launch]');
    const criteriaId = await launchButton.getAttribute('data-criteria-id');
    expect(criteriaId).toBeTruthy();
    const runId = 987606;

    await page.route(`**/admin/prospect_criteria/${criteriaId}/discover`, async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'success', status: 'pending', run_id: runId,
          status_url: `/admin/prospect_criteria/${criteriaId}/discovery-status?run_id=${runId}`,
        }),
      });
    });
    await page.route(`**/admin/prospect_criteria/${criteriaId}/discovery-status**`, async route => {
      expect(new URL(route.request().url()).searchParams.get('run_id')).toBe(String(runId));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(progressPayload(criteriaId!, runId, {
          status: 'completed', phase: 'completed', searches_consumed: 1, searches_reserved: 1,
          candidates_processed: 1, candidates_total: 1, candidates_total_final: true,
          progress_percent: 100, companies_count: 1, companies_total: 1,
        })),
      });
    });

    const reloadBaseline = dataRequests;
    await enableInterceptedLaunch(launchButton);
    await launchButton.click();
    await page.locator('.swal2-confirm').click();
    await expect.poll(() => dataRequests, { timeout: 10000 }).toBeGreaterThan(reloadBaseline);

    await expect(pc.searchInput).toHaveValue('Mock row');
    await expect.poll(() => page.evaluate(() => {
      const browserWindow = window as any;
      return browserWindow.LaravelDataTables['prospect_criteria-table'].page.info().page;
    })).toBe(1);
    expect(new URL(page.url()).searchParams.get('is_active')).toBe('1');
    expect(dataRequestUrls.at(-1)).toContain('is_active=1');
  });

});

// <<<
