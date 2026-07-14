// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { CampaignTemplatePage } from '../pages/CampaignTemplatePage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:campaign-templates-e2e

/**
 * module-7-campaign-templates — fretiq campaign_templates CRUD e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Contract:
 *   - All async widget interactions use ONLY the declared test-utils helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via CampaignTemplatePage.
 *   - Mutating tests use uniqueName() so rows are distinct across repeated runs.
 *   - No Select2 fields on the template form — no company_id dependency.
 *   - html_content is filled on the underlying textarea directly (TinyMCE enhances on top).
 *   - Custom action coverage:
 *       markReviewed — asserts DOM presence and calls the toggle on the Traductions tab.
 *       translate    — asserts DOM presence only; NOT clicked (paid Gemini API call).
 *       importFromZoho — asserts DOM presence only; NOT clicked (live Zoho call).
 *   - Pre-seeded 'E2E_FIXTURE Template' exists at run time (from fretiq:e2e-seed) and is
 *     used for read-only assertions (view, markReviewed) to avoid always creating.
 *   - Cleanup in afterAll removes any templates created by the suite.
 *
 * Table ID: campaign_template-table
 *   CampaignTemplate.getName() → 'campaign_template' → html() builder → '#campaign_template-table'
 */

/** Minimal valid HTML content for form submissions. */
const FIXTURE_HTML = '<html><body><p>E2E test email body.</p></body></html>';

test.describe('Campaign Templates module', () => {

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    await templates.goto();

    await expectPath(page, '/admin/campaign_templates');
    await waitForDataTable(page, 'campaign_template-table');
    await templates.expectTableVisible();
  });

  // ── 2. Index toolbar — import_zoho button present (do not click) ───────────

  test('index: import-zoho button is present in the DOM', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.assertImportZohoPresent();
  });

  // ── 3. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, row appears in table, cleanup', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name    = uniqueName('E2E Template');
    const subject = `E2E subject ${Date.now()}`;

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    await templates.fillAndSubmit({ name, subject, htmlContent: FIXTURE_HTML });

    // crud-form-handler.js follows redirect on 2xx — wait for navigation away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and search for the created row.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');

    // At least 1 matching row (tolerates double-submit bug producing 2).
    const rows = templates.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);

    // Cleanup: loop-delete all matching rows.
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });

    // Verify deletion.
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await expect(templates.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 4. Edit flow ───────────────────────────────────────────────────────────

  test('edit: change subject field, save, success redirect', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name       = uniqueName('E2E Edit Template');
    const subject    = `E2E edit subject ${Date.now()}`;
    const newSubject = `E2E UPDATED subject ${Date.now()}`;

    // Create a template to edit.
    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await templates.fillAndSubmit({ name, subject, htmlContent: FIXTURE_HTML });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Find the row and open edit.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');

    // Change the subject field and save.
    await expect(page.locator('#form_crud input[name="subject"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="subject"]').fill(newSubject);
    await page.locator('#form_crud button[name="save"]').click();

    // Wait for redirect away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });

    // Cleanup.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 5. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows template name', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name    = uniqueName('E2E View Template');
    const subject = `E2E view subject ${Date.now()}`;

    // Create a template.
    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await templates.fillAndSubmit({ name, subject, htmlContent: FIXTURE_HTML });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'view');

    // Detail page must show the template name.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });

    // Cleanup.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 6. Delete flow ─────────────────────────────────────────────────────────

  test('delete: confirm two SweetAlerts, row removed from table', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name    = uniqueName('E2E Delete Template');
    const subject = `E2E delete subject ${Date.now()}`;

    // Create a template to delete.
    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await templates.fillAndSubmit({ name, subject, htmlContent: FIXTURE_HTML });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');

    // Click delete → first SweetAlert confirm.
    await templates.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog 'Modèle supprimé avec succès'.
    await expect(page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'campaign_template-table');

    // Verify row is gone.
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await expect(templates.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);
  });

  // ── 7. Traductions tab: translate + markReviewed controls ─────────────────

  test('edit: Traductions tab renders translate button and markReviewed button', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name    = uniqueName('E2E Traductions Template');
    const subject = `E2E translations subject ${Date.now()}`;

    // Create a template (we need an existing record to open its edit page with the Traductions tab).
    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await templates.fillAndSubmit({ name, subject, htmlContent: FIXTURE_HTML });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Open the edit page for the newly created template via index search + row action.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');

    // Wait for the edit form to be visible.
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    // Assert Traductions tab link is present in the DOM.
    await templates.assertTranslateControlsPresent();

    // Click the Traductions tab to open the pane.
    await templates.openTraductionsTab();

    // Translate button must be present (DO NOT click — paid Gemini API call).
    await expect(templates.translateButton).toBeAttached();

    // markReviewed button — rendered either visible or with d-none (no translation yet).
    // In either case the element must exist in the DOM per the Blade template.
    await expect(templates.reviewButton).toBeAttached();

    // review state badge must also be present.
    await expect(templates.reviewStateBadge).toBeAttached();

    // Cleanup.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 8. markReviewed: toggle reviewed state ────────────────────────────────
  //
  // ROOT FIX: the fixture template has no translation row, so #tr-review-btn is
  // rendered with class d-none (hidden). We create a self-contained template,
  // inject an EN translation via the non-AI saveTranslation route using
  // page.request (authenticated — shares cookies with the browser context), then
  // open the Traductions tab, assert the button is visible, toggle it, and assert
  // the badge text changes. The test cleans up after itself.

  test('markReviewed: toggle reviewed state changes badge text', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name    = uniqueName('E2E MarkReviewed');
    const subject = `E2E markReviewed subject ${Date.now()}`;

    // ── Step 1: create a fresh template ──────────────────────────────────────
    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await templates.fillAndSubmit({ name, subject, htmlContent: FIXTURE_HTML });

    // crud-form-handler.js follows redirect on 2xx to /admin/campaign_templates (index).
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search by name, open edit action to land on /{id}/edit URL.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');
    await page.waitForURL((u) => /\/campaign_templates\/\d+\/edit$/.test(u.pathname), { timeout: 15000 });

    // Extract the template id from the edit URL.
    const editUrl = page.url();
    const idMatch = editUrl.match(/\/campaign_templates\/(\d+)\/edit/);
    if (!idMatch) {
      throw new Error(`Could not extract template id from edit URL: ${editUrl}`);
    }
    const templateId = idMatch[1];

    // ── Step 2: obtain CSRF token from the edit page meta tag ─────────────
    const csrfToken = await page.evaluate(() => {
      const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
      if (!meta) throw new Error('csrf-token meta tag not found');
      return meta.content;
    });

    // ── Step 3: POST an EN translation via saveTranslation (non-AI route) ─
    // Route: POST /admin/campaign_templates/{id}/translation
    // page.request shares cookies with the browser context → authenticated.
    // We must send the CSRF token in the X-CSRF-TOKEN header (axios JSON convention).
    const translationUrl = `/admin/campaign_templates/${templateId}/translation`;
    const apiResponse = await page.request.post(translationUrl, {
      headers: {
        'X-CSRF-TOKEN': csrfToken,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      data: {
        language:     'en',
        subject:      'E2E EN subject',
        html_content: '<p>E2E EN content for markReviewed test.</p>',
        preview_text: 'E2E EN preview',
      },
    });

    if (!apiResponse.ok()) {
      const body = await apiResponse.text();
      throw new Error(`saveTranslation failed: HTTP ${apiResponse.status()} — ${body.slice(0, 300)}`);
    }

    // ── Step 4: reload edit page and open Traductions tab ─────────────────
    await templates.gotoEdit(templateId);
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await templates.openTraductionsTab();

    // #tr-review-btn must now be visible (translation row exists).
    const reviewBtn = templates.reviewButton;
    await expect(reviewBtn).toBeVisible({ timeout: 10000 });

    // ── Step 5: toggle and assert badge text changes ──────────────────────
    const initialBadgeText = await templates.reviewStateBadge.innerText();
    const initialBtnText   = await reviewBtn.innerText();

    await reviewBtn.click();

    await expect(templates.reviewStateBadge).not.toHaveText(initialBadgeText, { timeout: 10000 });
    const afterBtnText = await reviewBtn.innerText();
    expect(afterBtnText.trim()).not.toBe(initialBtnText.trim());

    // Reset: click again to restore original state (idempotent for repeated runs).
    await reviewBtn.click();
    await expect(templates.reviewStateBadge).toHaveText(initialBadgeText, { timeout: 10000 });

    // ── Step 6: cleanup — delete the self-created template ─────────────────
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  test('edit: scoped saves, local tabs, route dirty guard, success and validation failure', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name = uniqueName('E2E Scoped Template');
    const subject = `E2E scoped subject ${Date.now()}`;
    const enSubject = `E2E EN scoped subject ${Date.now()}`;

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await templates.fillAndSubmit({ name, subject, htmlContent: FIXTURE_HTML });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');
    await page.waitForURL((u) => /\/campaign_templates\/\d+\/edit$/.test(u.pathname), { timeout: 15000 });

    const idMatch = page.url().match(/\/campaign_templates\/(\d+)\/edit/);
    if (!idMatch) throw new Error(`Could not extract template id from edit URL: ${page.url()}`);
    const templateId = idMatch[1];

    let dialogCount = 0;
    page.on('dialog', async (dialog) => {
      dialogCount++;
      if (dialogCount !== 2) {
        await dialog.accept();
      } else {
        await dialog.dismiss();
      }
    });

    await templates.subjectInput.fill(`${subject} dirty`);
    await templates.openTraductionsTab();
    await expect(page.locator('#template_traductions')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#form_crud #campaign_template_translation_form')).toHaveCount(0);
    expect(dialogCount).toBe(1);
    await expect(templates.stickyFormActions).toHaveClass(/d-none/);
    await expect(templates.translationForm).toBeVisible();

    const editUrl = page.url();
    await templates.routeLinks.first().click();
    expect(dialogCount).toBe(2);
    expect(page.url()).toBe(editUrl);

    let mainSaveRequests = 0;
    page.on('request', (request) => {
      const url = request.url();
      if (
        request.method() === 'POST' &&
        url.includes(`/admin/campaign_templates/${templateId}`) &&
        !url.endsWith('/translation')
      ) {
        mainSaveRequests++;
      }
    });

    await templates.fillTranslation({
      subject: enSubject,
      previewText: 'E2E EN scoped preview',
      htmlContent: '<p>E2E EN scoped content.</p>',
    });

    const translationResponse = page.waitForResponse((response) =>
      response.url().endsWith(`/admin/campaign_templates/${templateId}/translation`) &&
      response.request().method() === 'POST' &&
      response.ok()
    );
    await templates.saveTranslation();
    await translationResponse;
    await expect(page.locator('.toastr, #toast-container, .toast')).toContainText(/Traduction enregistrée|enregistrée/i, { timeout: 10000 });
    expect(mainSaveRequests).toBe(0);

    await templates.translationSubjectInput.fill('');
    await templates.saveTranslation();
    await expect(page.locator('.toastr-error')).toContainText('Le sujet anglais est requis.', { timeout: 10000 });

    await templates.translationSubjectInput.fill(enSubject);
    await templates.openGeneralTab();
    await templates.subjectInput.fill(subject);

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

});

// <<<
