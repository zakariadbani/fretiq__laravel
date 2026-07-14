import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * CampaignTemplatePage — page object for the fretiq campaign_templates module.
 *
 * Table ID: `campaign_template-table`
 *   Model: CampaignTemplate → getName() → 'campaign_template' → '#campaign_template-table'
 *
 * Form field names (from form.blade.php):
 *   name (required), subject (required), html_content (required — textarea, may be
 *   enhanced by TinyMCE but the underlying textarea is always in the DOM and can be
 *   filled directly), preview_text (optional)
 *
 * No Select2 fields — CampaignTemplate has no enum/relation selects.
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/campaign_templates
 *   create  GET  /admin/campaign_templates/create
 *   view    GET  /admin/campaign_templates/{id}
 *   edit    GET  /admin/campaign_templates/{id}/edit
 *
 * Custom actions:
 *   markReviewed — toggled via #tr-review-btn on the Traductions tab (edit page).
 *                  Available only when a translation row exists. In the no-translation
 *                  state, the button is rendered with class 'd-none' (hidden).
 *   translate    — #tr-translate-btn on the Traductions tab — DO NOT click (paid API).
 *   importFromZoho — form[action$="import-zoho"] on the index page — DO NOT submit (live Zoho).
 */
export class CampaignTemplatePage extends DataTablePage {
  // ── Form field locators (create / edit — scoped to #form_crud) ────────────

  readonly nameInput: Locator;
  readonly subjectInput: Locator;
  /**
   * Underlying textarea for html_content.
   * Note: TinyMCE hides it with aria-hidden="true" so Playwright .fill() will timeout.
   * Use page.evaluate() to set its value directly (see fillAndSubmit).
   */
  readonly htmlContentTextarea: Locator;
  readonly previewTextInput: Locator;

  // ── Index toolbar controls (assert presence; do not click) ────────────────

  /** "Importer depuis Zoho" form button — live Zoho call; assert presence only. */
  readonly importZohoButton: Locator;

  // ── Traductions tab controls (edit page only) ─────────────────────────────

  /** Tab link that activates the Traductions pane. */
  readonly traductionsTabLink: Locator;

  /** "Marquer comme relue / Marquer comme non relue" toggle button. */
  readonly reviewButton: Locator;

  /** Review state badge showing current reviewed status. */
  readonly reviewStateBadge: Locator;

  /**
   * "Traduire avec l'IA / Mettre à jour la traduction" button.
   * DO NOT click — calls paid Gemini API.
   */
  readonly translateButton: Locator;
  readonly translationForm: Locator;
  readonly translationSubjectInput: Locator;
  readonly translationHtmlTextarea: Locator;
  readonly translationSaveButton: Locator;
  readonly stickyFormActions: Locator;
  readonly routeLinks: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'campaign_template-table',  // getName() → 'campaign_template' → '#campaign_template-table'
      searchSelector: '#mySearchInput',
      addButtonText: 'Ajouter',
    });

    // Form fields scoped to #form_crud to avoid any layout collision
    this.nameInput          = page.locator('#form_crud input[name="name"]');
    this.subjectInput       = page.locator('#form_crud input[name="subject"]');
    this.htmlContentTextarea = page.locator('#form_crud textarea[name="html_content"]');
    this.previewTextInput   = page.locator('#form_crud input[name="preview_text"]');

    // Index toolbar
    this.importZohoButton = page.locator('button:has-text("Importer depuis Zoho")');

    // Traductions tab controls (edit page only)
    this.traductionsTabLink = page.locator('a[href="#template_traductions"]');
    this.reviewButton       = page.locator('#tr-review-btn');
    this.reviewStateBadge   = page.locator('#tr-review-state');
    this.translateButton    = page.locator('#tr-translate-btn');
    this.translationForm = page.locator('#campaign_template_translation_form');
    this.translationSubjectInput = page.locator('#campaign_template_translation_form input[name="subject"]');
    this.translationHtmlTextarea = page.locator('#campaign_template_translation_form textarea[name="html_content"]');
    this.translationSaveButton = page.locator('#tr-save-btn');
    this.stickyFormActions = page.locator('[data-crud-form-actions="sticky"]');
    this.routeLinks = page.locator('a[data-crud-route-link="true"]');
  }

  /** Navigate to the campaign templates index page. */
  async goto() {
    await this.page.goto('/admin/campaign_templates');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/campaign_templates/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/campaign_templates/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/campaign_templates/${id}`);
  }

  /**
   * Fill and submit the create/edit form.
   *
   * Fills name, subject, and html_content directly on the underlying textarea
   * (TinyMCE is initialised on top but the raw textarea is always in the DOM).
   * preview_text is optional.
   *
   * Returns after clicking the save button; the caller must await navigation.
   */
  async fillAndSubmit(data: {
    name: string;
    subject: string;
    htmlContent: string;
    previewText?: string;
  }) {
    await this.nameInput.fill(data.name);
    await this.subjectInput.fill(data.subject);

    // TinyMCE hides the textarea (aria-hidden="true") after init, so Playwright's
    // .fill() refuses to act on it (element not visible).  We bypass visibility by
    // setting the textarea value directly via evaluate() and then dispatching an
    // 'input' event so FormValidation / any listeners see the new value.
    // TinyMCE's capture-phase submit listener only overwrites the textarea when
    // editor.isDirty() is true; programmatic setContent via evaluate() does NOT
    // set the dirty flag, so our value survives the form submission.
    await this.page.evaluate((html: string) => {
      const ta = document.querySelector<HTMLTextAreaElement>(
        '#form_crud textarea[name="html_content"]'
      );
      if (!ta) throw new Error('html_content textarea not found in DOM');
      ta.value = html;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.dispatchEvent(new Event('change', { bubbles: true }));
    }, data.htmlContent);

    if (data.previewText) {
      await this.previewTextInput.fill(data.previewText);
    }

    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Click the Traductions tab to activate the Traductions pane (edit page only).
   * Waits for the pane to become visible.
   */
  async openTraductionsTab() {
    await this.traductionsTabLink.click();
    await this.page.locator('#template_traductions').waitFor({ state: 'visible', timeout: 10000 });
  }

  async openGeneralTab() {
    await this.page.locator('a[data-bs-toggle="tab"][href="#template_general"]').click();
    await this.page.locator('#template_general').waitFor({ state: 'visible', timeout: 10000 });
  }

  async fillTranslation(data: { subject: string; htmlContent: string; previewText?: string }) {
    await this.translationSubjectInput.fill(data.subject);
    if (data.previewText !== undefined) {
      await this.page.locator('#campaign_template_translation_form input[name="preview_text"]').fill(data.previewText);
    }

    await this.page.evaluate((html: string) => {
      const editor = (window as any).tinymce?.get('tr_en_html');
      if (editor) {
        editor.setContent(html);
      }
      const ta = document.querySelector<HTMLTextAreaElement>(
        '#campaign_template_translation_form textarea[name="html_content"]'
      );
      if (!ta) throw new Error('translation html_content textarea not found in DOM');
      ta.value = html;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.dispatchEvent(new Event('change', { bubbles: true }));
    }, data.htmlContent);
  }

  async saveTranslation() {
    await this.translationSaveButton.click();
  }

  /**
   * Assert that the Traductions tab exists and the translate button is present in the DOM.
   * Does NOT click translate (paid API call).
   */
  async assertTranslateControlsPresent() {
    await expect(this.traductionsTabLink).toBeAttached();
    await expect(this.translateButton).toBeAttached();
  }

  /**
   * Assert the "Importer depuis Zoho" button is present in the DOM on the index page.
   * Does NOT click it (live Zoho call).
   */
  async assertImportZohoPresent() {
    await expect(this.importZohoButton).toBeAttached();
  }

  /**
   * Assert that a DataTable row containing `name` exists.
   */
  async expectRowByName(name: string) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`).first()).toBeVisible();
  }

  /**
   * Loop-delete all rows matching `name` until none remain.
   * Handles the double-submit edge case where two rows could exist.
   */
  async deleteAllByName(
    name: string,
    helpers: {
      search: (q: string) => Promise<void>;
      waitForDataTable: (page: import('@playwright/test').Page, id: string) => Promise<void>;
      confirmDelete: (page: import('@playwright/test').Page) => Promise<void>;
    }
  ) {
    let found = true;
    while (found) {
      await helpers.search(name);
      await helpers.waitForDataTable(this.page, this.tableId);
      const matchingRows = this.table.locator(`tbody tr:has-text("${name}")`);
      const count = await matchingRows.count();
      if (count === 0) {
        found = false;
        break;
      }
      await this.clickRowAction(0, 'delete');
      await helpers.confirmDelete(this.page);
      // Second SweetAlert: success dialog after DELETE
      await expect(this.page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
      await this.page.locator('.swal2-confirm').click();
      await this.page.waitForLoadState('networkidle');
      await helpers.waitForDataTable(this.page, this.tableId);
    }
  }
}
