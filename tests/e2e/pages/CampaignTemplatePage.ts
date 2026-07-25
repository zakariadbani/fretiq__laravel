import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * CampaignTemplatePage — page object for the fretiq campaign_templates module.
 *
 * Table ID: `campaign_template-table`
 *   Model: CampaignTemplate → getName() → 'campaign_template' → '#campaign_template-table'
 *
 * ── Builder vs classic (create/edit form) ──────────────────────────────────
 * The create/edit form has two mutually-exclusive panes, toggled by
 * `#campaign_template_mode_toggle` ("Mode avancé"):
 *   - `#builder_pane`  — slot-based composer (header/footer/middle variant
 *     cards, hero_title/intro/bullets/departures|kpis|benefits/CTA inputs,
 *     a live preview iframe `#builder_preview_iframe`). ALWAYS the default
 *     pane on create. On edit it is shown iff the record has a stored
 *     builder_state.
 *   - `#classic_pane`  — the raw `textarea[name="html_content"]` (TinyMCE
 *     enhanced). Shown by default on edit when no builder_state is stored,
 *     or after switching modes via the toggle (Swal-confirmed).
 *
 * IMPORTANT: the html_content textarea is NOT always in the DOM in a fillable
 * state — it only carries `required` + `data-tinymce-html-field` (and
 * TinyMCE only inits on it) once the page is in classic mode. Filling it
 * directly while the page is still in builder mode does nothing useful: the
 * builder's capture-phase submit listener re-serializes `builder_state` from
 * in-memory JS state on every `.submit` click, ignoring the raw textarea.
 * `fillAndSubmit`/`fillAndSubmitClassic` below switch the page into classic
 * mode first (accepting the "Mode avancé" Swal confirm) before touching the
 * textarea. Use the builder-mode helpers further down to test the composer
 * pane instead.
 *
 * Form field names (from form.blade.php):
 *   name (required), subject (required), html_content (required in classic
 *   mode only — see above), preview_text (optional)
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
   * Underlying textarea for html_content (classic pane).
   * Note: TinyMCE hides it with aria-hidden="true" so Playwright .fill() will timeout.
   * Use page.evaluate() to set its value directly (see fillAndSubmitClassic), and
   * ensureClassicMode() first if the page may still be in builder mode.
   */
  readonly htmlContentTextarea: Locator;
  readonly previewTextInput: Locator;
  readonly saveButton: Locator;

  // ── Builder vs classic mode plumbing ───────────────────────────────────────

  /** "Mode avancé (HTML brut)" checkbox — checked = classic, unchecked = builder. */
  readonly modeToggle: Locator;
  readonly builderPane: Locator;
  readonly classicPane: Locator;
  /** Hidden input — 'builder' | 'classic'. */
  readonly editorModeInput: Locator;
  /** Hidden input — JSON-serialized builder_state, kept in sync on every builder edit. */
  readonly builderStateInput: Locator;

  // ── Builder pane controls ───────────────────────────────────────────────────

  readonly heroTitleInput: Locator;
  readonly ctaIntentSelect: Locator;
  readonly ctaLabelInput: Locator;
  readonly briefInput: Locator;
  readonly aiGenerateButton: Locator;
  /** Debounced (400ms) live-preview iframe, sandboxed srcdoc. */
  readonly previewIframe: Locator;
  readonly previewErrorEl: Locator;

  // ── View page ────────────────────────────────────────────────────────────

  /** Saved-content preview iframe on the view (detail) page — srcdoc = html_content. */
  readonly viewPreviewIframe: Locator;

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
    this.saveButton         = page.locator('#form_crud button[name="save"]');

    // Builder ⇄ classic mode plumbing
    this.modeToggle        = page.locator('#campaign_template_mode_toggle');
    this.builderPane        = page.locator('#builder_pane');
    this.classicPane         = page.locator('#classic_pane');
    this.editorModeInput    = page.locator('#campaign_template_editor_mode');
    this.builderStateInput  = page.locator('#campaign_template_builder_state');

    // Builder pane controls
    this.heroTitleInput  = page.locator('#slot_hero_title');
    this.ctaIntentSelect = page.locator('#slot_cta_intent');
    this.ctaLabelInput   = page.locator('#slot_cta_label');
    this.briefInput      = page.locator('#builder_brief_input');
    this.aiGenerateButton = page.locator('#builder_ai_generate_btn');
    this.previewIframe   = page.locator('#builder_preview_iframe');
    this.previewErrorEl  = page.locator('#builder_preview_error');

    // View page
    this.viewPreviewIframe = page.locator('iframe[srcdoc]');

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

  // ── Builder ⇄ classic mode switching ────────────────────────────────────

  /**
   * Confirm the SweetAlert2 dialog that gates every mode-toggle transition
   * (both builder→classic and classic→builder).
   */
  private async confirmModeSwal() {
    const swal = this.page.locator('.swal2-popup');
    await expect(swal).toBeVisible({ timeout: 5000 });
    await swal.locator('button.swal2-confirm').click();
  }

  /**
   * Wait for TinyMCE to have actually attached to #html_content. Needed after
   * switching into classic mode: campaign-template-builder.js lazily calls
   * KTTinymceHtmlField.init() (async — spins up an iframe-based editor) the
   * first time the user switches, so a raw DOM write right after clicking the
   * toggle can race the editor's own initial read of the textarea.
   */
  private async waitForClassicEditorReady() {
    await this.page.waitForFunction(() => {
      const tinymceGlobal = (window as any).tinymce;
      return !!(tinymceGlobal && tinymceGlobal.get('html_content'));
    }, { timeout: 10000 });
  }

  /**
   * Ensure the form is in CLASSIC mode, switching via the "Mode avancé"
   * toggle (and accepting its Swal confirm) if it is currently in builder
   * mode. No-ops if already classic. Always resolves once TinyMCE has
   * attached to #html_content, so callers can safely write to the textarea
   * immediately after.
   */
  async ensureClassicMode() {
    if (await this.modeToggle.isChecked()) {
      await this.waitForClassicEditorReady();
      return;
    }

    await this.modeToggle.click();
    await this.confirmModeSwal();

    await expect(this.modeToggle).toBeChecked({ timeout: 10000 });
    await expect(this.classicPane).not.toHaveClass(/d-none/, { timeout: 10000 });
    await expect(this.editorModeInput).toHaveValue('classic', { timeout: 10000 });

    await this.waitForClassicEditorReady();
  }

  /**
   * Ensure the form is in BUILDER mode, switching via the toggle (and
   * accepting its Swal confirm) if it is currently in classic mode. No-ops
   * if already builder. NOTE: confirming this transition discards nothing
   * client-side by itself — the classic HTML is only replaced by the
   * composed builder output when the form is actually saved.
   */
  async ensureBuilderMode() {
    if (!(await this.modeToggle.isChecked())) {
      return;
    }

    await this.modeToggle.click();
    await this.confirmModeSwal();

    await expect(this.modeToggle).not.toBeChecked({ timeout: 10000 });
    await expect(this.builderPane).not.toHaveClass(/d-none/, { timeout: 10000 });
    await expect(this.editorModeInput).toHaveValue('builder', { timeout: 10000 });
  }

  // ── Classic (raw HTML) authoring ────────────────────────────────────────

  /**
   * Fill and submit the create/edit form in CLASSIC mode.
   *
   * Switches the page into classic mode first (accepting the "Mode avancé"
   * Swal confirm) if it is still showing the builder pane — true on every
   * create-page load, since builder is always the default there. Then fills
   * name, subject, and html_content directly on the underlying textarea.
   * preview_text is optional.
   *
   * Returns after clicking the save button; the caller must await navigation.
   */
  async fillAndSubmitClassic(data: {
    name: string;
    subject: string;
    htmlContent: string;
    previewText?: string;
  }) {
    await this.ensureClassicMode();

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

    await this.saveButton.click();
  }

  /**
   * Alias for fillAndSubmitClassic — kept so every pre-existing call site in
   * module-7-campaign-templates.spec.ts (all of which author raw FIXTURE_HTML
   * content) keeps working unchanged. New specs may call either name.
   */
  async fillAndSubmit(data: {
    name: string;
    subject: string;
    htmlContent: string;
    previewText?: string;
  }) {
    return this.fillAndSubmitClassic(data);
  }

  // ── Builder (slot-based) authoring ──────────────────────────────────────

  private headerVariantCard(variant: string): Locator {
    return this.page.locator(`[data-variant-group="header_variant"][data-variant-value="${variant}"]`);
  }

  private footerVariantCard(variant: string): Locator {
    return this.page.locator(`[data-variant-group="footer_variant"][data-variant-value="${variant}"]`);
  }

  private middlePill(variant: string): Locator {
    return this.page.locator(`[data-middle-value="${variant}"]`);
  }

  /** Click a header variant card ('logo_center' | 'logo_tagline') and wait for it to become active. */
  async selectHeaderVariant(variant: 'logo_center' | 'logo_tagline') {
    await this.headerVariantCard(variant).click();
    await expect(this.headerVariantCard(variant)).toHaveClass(/is-active/);
  }

  /** Click a footer variant card ('detailed' | 'compact') and wait for it to become active. */
  async selectFooterVariant(variant: 'detailed' | 'compact') {
    await this.footerVariantCard(variant).click();
    await expect(this.footerVariantCard(variant)).toHaveClass(/is-active/);
  }

  /** Click a middle-block pill and wait for it to become active. */
  async selectMiddleVariant(variant: 'process' | 'departures' | 'kpi' | 'benefits' | 'case_study' | 'checklist' | 'solutions' | 'offer') {
    await this.middlePill(variant).click();
    await expect(this.middlePill(variant)).toHaveClass(/is-active/);
  }

  async expectOnlyMiddleFormActive(activeVariant: 'process' | 'departures' | 'kpi' | 'benefits' | 'case_study' | 'checklist' | 'solutions' | 'offer') {
    const variants = ['process', 'departures', 'kpi', 'benefits', 'case_study', 'checklist', 'solutions', 'offer'] as const;

    for (const variant of variants) {
      const section = this.page.locator(`#slot_middle_${variant}`);
      const editableControls = section.locator('input, textarea, select');
      const allControls = section.locator('input, textarea, select, button');

      if (variant === activeVariant) {
        await expect(section).toBeVisible();
        await expect(editableControls.first()).toBeEnabled();
      } else {
        await expect(section).toBeHidden();
        for (let index = 0; index < await allControls.count(); index++) {
          await expect(allControls.nth(index)).toBeDisabled();
        }
      }
    }
  }

  /** Fill the hero_title slot input. */
  async fillHeroTitle(text: string) {
    await this.heroTitleInput.fill(text);
  }

  /**
   * Grow a bounded repeater (via its "Ajouter" button) to at least
   * `values.length` items, then fill each item locator by index in order.
   */
  private async fillRepeater(itemSelector: string, addButtonSelector: string, values: string[]) {
    const items = this.page.locator(itemSelector);
    let count = await items.count();
    while (count < values.length) {
      await this.page.locator(addButtonSelector).click();
      count++;
    }
    for (let i = 0; i < values.length; i++) {
      await items.nth(i).fill(values[i]);
    }
  }

  /** Set the intro paragraphs repeater (1–3 items — SectionCatalog::INTRO_MIN/MAX). */
  async setIntro(paragraphs: string[]) {
    await this.fillRepeater('#slot_intro_list textarea', '#slot_intro_add', paragraphs);
  }

  /** Set the bullets repeater (2–4 items — SectionCatalog::BULLETS_MIN/MAX). */
  async setBullets(items: string[]) {
    await this.fillRepeater('#slot_bullets_list input', '#slot_bullets_add', items);
  }

  /**
   * Set the "departures" middle-block payload (2–5 rows — only meaningful
   * once middle_variant === 'departures', see selectMiddleVariant).
   */
  async setDepartures(rows: Array<{ origin: string; frequency: string }>) {
    const list = this.page.locator('#slot_departures_list > div.row');
    let count = await list.count();
    while (count < rows.length) {
      await this.page.locator('#slot_departures_add').click();
      count++;
    }
    for (let i = 0; i < rows.length; i++) {
      const row = list.nth(i);
      await row.locator('input').nth(0).fill(rows[i].origin);
      await row.locator('input').nth(1).fill(rows[i].frequency);
    }
  }

  /**
   * Set the "kpi" middle-block payload — fixed count of 3, no add/remove
   * (only meaningful once middle_variant === 'kpi', see selectMiddleVariant).
   */
  async setKpis(rows: Array<{ value: string; label: string }>) {
    const list = this.page.locator('#slot_kpis_list > div.row');
    for (let i = 0; i < rows.length; i++) {
      const row = list.nth(i);
      await row.locator('input').nth(0).fill(rows[i].value);
      await row.locator('input').nth(1).fill(rows[i].label);
    }
  }

  /**
   * Set the "benefits" middle-block payload — fixed count of 3, no add/remove
   * (only meaningful once middle_variant === 'benefits', see selectMiddleVariant).
   */
  async setBenefits(rows: Array<{ title: string; text: string }>) {
    const list = this.page.locator('#slot_benefits_list > div.row');
    for (let i = 0; i < rows.length; i++) {
      const row = list.nth(i);
      await row.locator('input').nth(0).fill(rows[i].title);
      await row.locator('textarea').fill(rows[i].text);
    }
  }

  /** Set the source-backed 3-step logistics process and its highlight. */
  async setProcess(steps: [string, string, string], highlight: string) {
    const inputs = this.page.locator('#slot_process_steps_list input');
    await expect(inputs).toHaveCount(3);
    for (let i = 0; i < steps.length; i++) {
      await inputs.nth(i).fill(steps[i]);
    }
    await this.page.locator('#slot_process_highlight').fill(highlight);
  }

  async setOffer(data: { title: string; description: string; highlight: string }) {
    const fields = this.page.locator('#slot_offer_fields input, #slot_offer_fields textarea');
    await expect(fields).toHaveCount(3);
    await fields.nth(0).fill(data.title);
    await fields.nth(1).fill(data.description);
    await fields.nth(2).fill(data.highlight);
  }

  /** Set the CTA intent (select) and label (text input). */
  async setCta(intent: string, label: string) {
    await this.ctaIntentSelect.selectOption(intent);
    await this.ctaLabelInput.fill(label);
  }

  /**
   * Read the live builder_state hidden input (kept in sync synchronously on
   * every builder field change via serializeState() — no debounce, unlike
   * the preview iframe). Returns null if empty/unset.
   */
  async getBuilderStateValue(): Promise<any | null> {
    const raw = await this.builderStateInput.inputValue();
    return raw ? JSON.parse(raw) : null;
  }

  /**
   * Wait for the debounced (400ms) live-preview iframe to contain `text`.
   * Uses Playwright's auto-retrying frame assertion, so it naturally absorbs
   * the debounce + the builder/preview XHR round-trip without manual
   * network-response hooking.
   */
  async waitForPreviewToContain(text: string, timeoutMs = 8000) {
    await expect(this.page.frameLocator('#builder_preview_iframe').locator('body'))
      .toContainText(text, { timeout: timeoutMs });
  }

  /** Full innerHTML of the live-preview iframe body. */
  async getPreviewBodyHtml(): Promise<string> {
    return this.page.frameLocator('#builder_preview_iframe').locator('body').innerHTML();
  }

  /**
   * Full innerHTML of the saved-content preview iframe on the view (detail)
   * page — reflects the persisted html_content byte-for-byte (srcdoc).
   */
  async getViewPreviewHtml(): Promise<string> {
    return this.page.frameLocator('iframe[srcdoc]').locator('body').innerHTML();
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
