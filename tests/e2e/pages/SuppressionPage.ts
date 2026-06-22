import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * SuppressionPage — page object for the fretiq suppressions module.
 *
 * Table ID: `suppression-table`
 *   Model: Suppression → getName() → 'suppression' → html() builder → setTableId('suppression-table')
 *
 * Form field names (from form.blade.php):
 *   email (required), reason (native select, optional), source (native select, optional)
 *
 * NOTE: suppressions form uses its own submit button:
 *   <button type="submit" class="btn btn-danger submit" id="submit_btn">
 *   NOT the form-actions partial / button[name="save"].
 *   crud-form-handler.js intercepts it via the `.submit` class.
 *   Selector: #form_crud #submit_btn
 *
 * No toggleable fields — SuppressionController declares no $toggleableFields.
 *
 * Delete success text: 'retirée' (from getMessages: 'Suppression retirée avec succès')
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/suppressions
 *   create  GET  /admin/suppressions/create
 *   view    GET  /admin/suppressions/{id}
 *   edit    GET  /admin/suppressions/{id}/edit
 */
export class SuppressionPage extends DataTablePage {
  // ── Form field locators (create / edit) ──────────────────────────────────

  readonly emailInput: Locator;
  readonly reasonSelect: Locator;
  readonly sourceSelect: Locator;

  // Suppression form uses its own submit button (not form-actions partial)
  readonly submitButton: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'suppression-table',    // Suppression.getName() → 'suppression' → '#suppression-table'
      searchSelector: '#mySearchInput', // shared id across all modules
      addButtonText: 'Ajouter',
    });

    // Form fields scoped to #form_crud
    this.emailInput  = page.locator('#form_crud input[name="email"]');
    // reason + source use plain <select> (no Select2 / data-control)
    this.reasonSelect = page.locator('#form_crud select[name="reason"]');
    this.sourceSelect = page.locator('#form_crud select[name="source"]');

    // Suppressions form has its own submit: <button type="submit" id="submit_btn">
    this.submitButton = page.locator('#form_crud #submit_btn');
  }

  /** Navigate to the suppressions index page. */
  async goto() {
    await this.page.goto('/admin/suppressions');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/suppressions/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/suppressions/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/suppressions/${id}`);
  }

  /**
   * Fill and submit the create/edit form with the required email field.
   * reason and source are optional selects (native, no Select2).
   * Uses #submit_btn (not button[name="save"]) — this form has its own submit button.
   */
  async fillAndSubmit(data: {
    email: string;
    reason?: string;  // option value string (e.g. 'manual', 'bounce', etc.)
    source?: string;  // option value string
  }) {
    await this.emailInput.fill(data.email);
    if (data.reason) {
      await this.reasonSelect.selectOption({ value: data.reason });
    }
    if (data.source) {
      await this.sourceSelect.selectOption({ value: data.source });
    }
    await this.submitButton.click();
  }

  /**
   * Assert exactly `count` DataTable rows contain `email` in their text.
   */
  async expectRowCountByEmail(email: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${email}")`)).toHaveCount(count);
  }

  /**
   * Loop-delete all rows matching `email` until none remain.
   * Guards against the known double-submit bug (two rows created per save).
   * Delete success text: 'retirée' (from getMessages: 'Suppression retirée avec succès')
   */
  async deleteAllByEmail(
    email: string,
    helpers: {
      search: (q: string) => Promise<void>;
      waitForDataTable: (page: import('@playwright/test').Page, id: string) => Promise<void>;
      confirmDelete: (page: import('@playwright/test').Page) => Promise<void>;
    }
  ) {
    let found = true;
    while (found) {
      await helpers.search(email);
      await helpers.waitForDataTable(this.page, this.tableId);
      const matchingRows = this.table.locator(`tbody tr:has-text("${email}")`);
      const count = await matchingRows.count();
      if (count === 0) {
        found = false;
        break;
      }
      await this.clickRowAction(0, 'delete');
      await helpers.confirmDelete(this.page);
      // Second SweetAlert: 'Suppression retirée avec succès'
      await expect(this.page.locator('.swal2-popup')).toContainText('retirée', { timeout: 10000 });
      await this.page.locator('.swal2-confirm').click();
      await this.page.waitForLoadState('networkidle');
      await helpers.waitForDataTable(this.page, this.tableId);
    }
  }
}
