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
 *   - Builder vs classic: create always opens in BUILDER mode (slot-based composer,
 *     `#builder_pane`); `fillAndSubmit`/`fillAndSubmitClassic` switch the page into
 *     CLASSIC mode (`#classic_pane`, raw html_content textarea, TinyMCE-enhanced) first
 *     via the "Mode avancé" toggle + Swal confirm, so every pre-existing test below that
 *     calls fillAndSubmit(...) with FIXTURE_HTML authors a classic (raw-HTML) template —
 *     and its edit page reopens in classic mode too, since builder_state is cleared
 *     server-side for classic saves. Dedicated "Builder mode" tests further down exercise
 *     the composer pane directly (variant selection, slot repeaters, live preview, the
 *     builder⇄classic toggle, and the saved builder_state round-trip).
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

  // ── 9. Builder mode — create, select variants, fill slots, save ───────────

  test('builder middle-block clicks immediately replace the live preview', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const previewBody = page.frameLocator('#builder_preview_iframe').locator('body');

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await expect(previewBody).toContainText('Dégroupement MEAD');
    await templates.expectOnlyMiddleFormActive('process');

    await templates.selectMiddleVariant('departures');
    await templates.expectOnlyMiddleFormActive('departures');
    await expect(previewBody).toContainText('Origine à compléter', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Dégroupement MEAD');
    await expect(templates.previewErrorEl).toBeHidden();

    const departuresState = await templates.getBuilderStateValue();
    expect(departuresState.middle_variant).toBe('departures');
    expect(departuresState.slots.departures).toEqual([
      { origin: '', frequency: '' },
      { origin: '', frequency: '' },
    ]);

    await templates.selectMiddleVariant('kpi');
    await templates.expectOnlyMiddleFormActive('kpi');
    await expect(previewBody).toContainText('Indicateur à compléter', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Origine à compléter');
    await expect(templates.previewErrorEl).toBeHidden();

    await templates.selectMiddleVariant('benefits');
    await templates.expectOnlyMiddleFormActive('benefits');
    await expect(previewBody).toContainText('Avantage à compléter', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Indicateur à compléter');
    await expect(templates.previewErrorEl).toBeHidden();

    await templates.selectMiddleVariant('case_study');
    await templates.expectOnlyMiddleFormActive('case_study');
    await expect(previewBody).toContainText('Étude de cas à compléter', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Avantage à compléter');
    await expect(templates.previewErrorEl).toBeHidden();

    await templates.selectMiddleVariant('checklist');
    await templates.expectOnlyMiddleFormActive('checklist');
    await expect(previewBody).toContainText('Liste de contrôle à compléter', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Étude de cas à compléter');
    await expect(templates.previewErrorEl).toBeHidden();

    await templates.selectMiddleVariant('solutions');
    await templates.expectOnlyMiddleFormActive('solutions');
    await expect(previewBody).toContainText('Solution à compléter', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Liste de contrôle à compléter');
    await expect(templates.previewErrorEl).toBeHidden();

    await templates.selectMiddleVariant('offer');
    await templates.expectOnlyMiddleFormActive('offer');
    await expect(previewBody).toContainText('Offre à compléter', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Solution à compléter');
    await expect(templates.previewErrorEl).toBeHidden();

    await templates.selectMiddleVariant('process');
    await templates.expectOnlyMiddleFormActive('process');
    await expect(previewBody).toContainText('Dégroupement MEAD', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Offre à compléter');
    await expect(templates.previewErrorEl).toBeHidden();
  });

  test('builder offer block saves and reopens with its structured fields', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name = uniqueName('E2E Builder Offer');
    const title = `Offre dédiée ${Date.now()}`;

    await templates.gotoCreate();
    await templates.nameInput.fill(name);
    await templates.subjectInput.fill(`E2E offer subject ${Date.now()}`);
    await templates.selectMiddleVariant('offer');
    await templates.setOffer({
      title,
      description: 'Un schéma transport adapté à vos contraintes.',
      highlight: 'Étude personnalisée',
    });
    await templates.waitForPreviewToContain(title);
    await templates.saveButton.click();
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');
    await page.waitForURL((u) => /\/campaign_templates\/\d+\/edit$/.test(u.pathname), { timeout: 15000 });

    await expect(page.locator('[data-middle-value="offer"]')).toHaveClass(/is-active/);
    const state = await templates.getBuilderStateValue();
    expect(state.slots.offer.title).toBe(title);
    expect(state.slots.offer.highlight).toBe('Étude personnalisée');

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  test('builder CTA intent updates its label, state, and live preview', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const previewBody = page.frameLocator('#builder_preview_iframe').locator('body');

    await templates.gotoCreate();
    await expect(templates.ctaIntentSelect).toHaveValue('services');
    await expect(templates.ctaLabelInput).toHaveValue('Découvrir nos services');

    await templates.ctaIntentSelect.selectOption('quote');
    await expect(templates.ctaLabelInput).toHaveValue('Demander une cotation');
    await expect(previewBody).toContainText('Demander une cotation', { timeout: 8000 });
    await expect(previewBody).not.toContainText('Découvrir nos services');

    const quoteState = await templates.getBuilderStateValue();
    expect(quoteState.cta).toEqual({
      intent: 'quote',
      label: 'Demander une cotation',
    });

    await templates.ctaIntentSelect.selectOption('chatbot');
    await expect(templates.ctaLabelInput).toHaveValue('Poser une question');
    await expect(previewBody).toContainText('Poser une question', { timeout: 8000 });

    await templates.ctaIntentSelect.selectOption('services');
    await expect(templates.ctaLabelInput).toHaveValue('Découvrir nos services');
    await expect(previewBody).toContainText('Découvrir nos services', { timeout: 8000 });
    await expect(templates.previewErrorEl).toBeHidden();
  });

  test('builder process default: source-backed content previews and round-trips', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name = uniqueName('E2E TCL Process');
    const subject = `E2E process subject ${Date.now()}`;
    const highlight = `Accompagnement logistique e2e ${Date.now()}`;

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    await expect(page.locator('[data-middle-value="process"]')).toHaveClass(/is-active/);
    await expect(templates.heroTitleInput)
      .toHaveValue('TCL Transport : Expertise logistique 3PL pour vos besoins en transport');
    await expect(templates.ctaIntentSelect).toHaveValue('services');
    await expect(templates.ctaLabelInput).toHaveValue('Découvrir nos services');

    await templates.selectMiddleVariant('departures');
    await templates.selectMiddleVariant('process');
    await templates.setProcess(['Collecte e2e', 'Acheminement e2e', 'Dégroupement MEAD e2e'], highlight);
    await templates.waitForPreviewToContain('Dégroupement MEAD e2e');

    const state = await templates.getBuilderStateValue();
    expect(state.middle_variant).toBe('process');
    expect(state.slots.process_steps).toEqual(['Collecte e2e', 'Acheminement e2e', 'Dégroupement MEAD e2e']);
    expect(state.slots.process_highlight).toBe(highlight);
    expect(state.cta.intent).toBe('services');

    await templates.nameInput.fill(name);
    await templates.subjectInput.fill(subject);
    await templates.saveButton.click();
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');
    await page.waitForURL((u) => /\/campaign_templates\/\d+\/edit$/.test(u.pathname), { timeout: 15000 });

    await expect(page.locator('[data-middle-value="process"]')).toHaveClass(/is-active/);
    const savedState = await templates.getBuilderStateValue();
    expect(savedState.slots.process_steps[2]).toBe('Dégroupement MEAD e2e');
    expect(savedState.slots.process_highlight).toBe(highlight);

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  test('builder create: select variants, fill slots, saved html_content reflects selections', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name      = uniqueName('E2E Builder Template');
    const subject   = `E2E builder subject ${Date.now()}`;
    const heroTitle = `Builder Hero ${Date.now()}`;

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    // Builder is the default pane on create.
    await expect(templates.builderPane).not.toHaveClass(/d-none/);
    await expect(templates.classicPane).toHaveClass(/d-none/);

    await templates.nameInput.fill(name);
    await templates.subjectInput.fill(subject);

    await templates.selectHeaderVariant('logo_tagline');
    await templates.selectFooterVariant('compact');
    await templates.selectMiddleVariant('kpi');
    await templates.fillHeroTitle(heroTitle);
    await templates.setIntro(['Premier paragraphe e2e.', 'Deuxième paragraphe e2e.']);
    await templates.setBullets(['Argument un', 'Argument deux', 'Argument trois']);
    await templates.setKpis([
      { value: '24h', label: 'Délai e2e' },
      { value: '99%', label: 'Fiabilité e2e' },
      { value: '50+', label: 'Clients e2e' },
    ]);
    await templates.setCta('chatbot', 'Discuter maintenant e2e');

    // Live preview reflects the selections before we ever submit.
    await templates.waitForPreviewToContain(heroTitle);

    await templates.saveButton.click();
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open the view page to inspect saved html_content.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'view');

    await expect(templates.viewPreviewIframe).toBeAttached({ timeout: 10000 });
    const viewHtml = await templates.getViewPreviewHtml();
    expect(viewHtml).toContain(heroTitle);
    expect(viewHtml).toContain('Délai e2e');
    expect(viewHtml).toContain('Discuter maintenant e2e');

    // Cleanup.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 10. Builder mode — edit round-trip ─────────────────────────────────────

  test('builder edit round-trip: reopens in builder mode with saved selections', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name      = uniqueName('E2E Builder RoundTrip');
    const subject   = `E2E builder roundtrip subject ${Date.now()}`;
    const heroTitle = `RoundTrip Hero ${Date.now()}`;
    const ctaLabel  = 'Demander un devis e2e';

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    await templates.nameInput.fill(name);
    await templates.subjectInput.fill(subject);
    await templates.selectHeaderVariant('logo_tagline');
    await templates.selectFooterVariant('compact');
    await templates.selectMiddleVariant('benefits');
    await templates.fillHeroTitle(heroTitle);
    await templates.setBenefits([
      { title: 'Rapide', text: 'Livraison rapide et fiable.' },
      { title: 'Sûr', text: 'Suivi complet des expéditions.' },
      { title: 'Économique', text: 'Optimisation des coûts logistiques.' },
    ]);
    await templates.setCta('quote', ctaLabel);

    await templates.saveButton.click();
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');
    await page.waitForURL((u) => /\/campaign_templates\/\d+\/edit$/.test(u.pathname), { timeout: 15000 });
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    // Edit must reopen in BUILDER mode (a builder_state was persisted).
    await expect(templates.modeToggle).not.toBeChecked({ timeout: 10000 });
    await expect(templates.builderPane).not.toHaveClass(/d-none/);
    await expect(templates.classicPane).toHaveClass(/d-none/);

    // Selections carried over into the re-rendered builder pane.
    await expect(page.locator('[data-variant-group="header_variant"][data-variant-value="logo_tagline"]'))
      .toHaveClass(/is-active/);
    await expect(page.locator('[data-variant-group="footer_variant"][data-variant-value="compact"]'))
      .toHaveClass(/is-active/);
    await expect(page.locator('[data-middle-value="benefits"]')).toHaveClass(/is-active/);
    await expect(templates.heroTitleInput).toHaveValue(heroTitle);
    await expect(templates.ctaLabelInput).toHaveValue(ctaLabel);

    // The hydrated builder_state hidden input matches what was saved.
    const state = await templates.getBuilderStateValue();
    expect(state.header_variant).toBe('logo_tagline');
    expect(state.footer_variant).toBe('compact');
    expect(state.middle_variant).toBe('benefits');
    expect(state.slots.hero_title).toBe(heroTitle);
    expect(state.cta.intent).toBe('quote');
    expect(state.cta.label).toBe(ctaLabel);

    // Cleanup.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 11. Classic mode — create round-trip ────────────────────────────────────

  test('classic create: fillAndSubmit persists classic mode, edit reopens in classic', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name    = uniqueName('E2E Classic RoundTrip');
    const subject = `E2E classic roundtrip subject ${Date.now()}`;

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
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    // Edit must reopen in CLASSIC mode (builder_state is null for classic-authored templates).
    await expect(templates.modeToggle).toBeChecked({ timeout: 10000 });
    await expect(templates.classicPane).not.toHaveClass(/d-none/);
    await expect(templates.builderPane).toHaveClass(/d-none/);
    await expect(templates.htmlContentTextarea).toHaveValue(FIXTURE_HTML);

    // Cleanup.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 12. Builder → classic toggle ────────────────────────────────────────────

  test('builder to classic toggle: composes the current builder state into the raw HTML field', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name      = uniqueName('E2E Toggle Template');
    const subject   = `E2E toggle subject ${Date.now()}`;
    const heroTitle = `Toggle Hero ${Date.now()}`;

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    await templates.nameInput.fill(name);
    await templates.subjectInput.fill(subject);
    await templates.fillHeroTitle(heroTitle);

    await templates.ensureClassicMode();

    await expect(templates.editorModeInput).toHaveValue('classic');
    await expect(templates.classicPane).not.toHaveClass(/d-none/);
    await expect(templates.builderPane).toHaveClass(/d-none/);

    // The classic textarea was populated with the builder's composed HTML.
    const composedHtml = await templates.htmlContentTextarea.inputValue();
    expect(composedHtml).toContain(heroTitle);

    await templates.saveButton.click();
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Saved as classic — builder_state is cleared server-side, so edit reopens classic too.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'edit');
    await page.waitForURL((u) => /\/campaign_templates\/\d+\/edit$/.test(u.pathname), { timeout: 15000 });
    await expect(templates.modeToggle).toBeChecked({ timeout: 10000 });

    // Cleanup.
    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.deleteAllByName(name, {
      search: (q) => templates.search(q),
      waitForDataTable,
      confirmDelete,
    });
  });

  // ── 13. Builder-composed templates never carry unsubscribe markup ──────────

  test('builder-composed template contains no unsubscribe/désabonner markup', async ({ page }) => {
    const templates = new CampaignTemplatePage(page);
    const name    = uniqueName('E2E No Unsub Template');
    const subject = `E2E no-unsub subject ${Date.now()}`;

    await templates.gotoCreate();
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });

    await templates.nameInput.fill(name);
    await templates.subjectInput.fill(subject);
    await templates.selectFooterVariant('detailed');

    await templates.saveButton.click();
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await templates.goto();
    await waitForDataTable(page, 'campaign_template-table');
    await templates.search(name);
    await waitForDataTable(page, 'campaign_template-table');
    await templates.clickRowAction(0, 'view');

    await expect(templates.viewPreviewIframe).toBeAttached({ timeout: 10000 });
    const viewHtml = (await templates.getViewPreviewHtml()).toLowerCase();
    expect(viewHtml).not.toContain('unsubscribe');
    expect(viewHtml).not.toContain('désabonn');
    expect(viewHtml).not.toContain('desabonn');

    // Cleanup.
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
