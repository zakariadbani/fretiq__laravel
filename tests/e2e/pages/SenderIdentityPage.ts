import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * SenderIdentityPage — page object for the fretiq sender_identities module.
 *
 * Table ID: `sender_identity-table`
 *   Model: SenderIdentity → getName() converts CamelCase to snake_case
 *   → 'sender_identity' → html() builder → setTableId('sender_identity-table')
 *   SenderIdentitiesDataTable no longer overrides getTableId() — it inherits
 *   GlobalDataTable's default, which now matches the rendered DOM id.
 *
 * Form field names (from form.blade.php):
 *   name (required), email (required), reply_to (optional), signature_html (optional),
 *   is_default (checkbox), is_active (checkbox)
 *
 * Toggleable fields (SenderIdentityController::$toggleableFields): is_default, is_active
 * Switch column selector in DataTable: input.status-toggle[data-field="is_default"]
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/sender_identities
 *   create  GET  /admin/sender_identities/create
 *   view    GET  /admin/sender_identities/{id}
 *   edit    GET  /admin/sender_identities/{id}/edit
 */
export class SenderIdentityPage extends DataTablePage {
  // ── Form field locators (create / edit) ──────────────────────────────────

  readonly nameInput: Locator;
  readonly emailInput: Locator;
  readonly replyToInput: Locator;
  readonly isDefaultCheckbox: Locator;
  readonly isActiveCheckbox: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'sender_identity-table',  // SenderIdentity.getName() → 'sender_identity'
      searchSelector: '#mySearchInput',   // shared id across all modules
      addButtonText: 'Ajouter',
    });

    // Form fields scoped to #form_crud
    this.nameInput        = page.locator('#form_crud input[name="name"]');
    this.emailInput       = page.locator('#form_crud input[name="email"]');
    this.replyToInput     = page.locator('#form_crud input[name="reply_to"]');
    this.isDefaultCheckbox = page.locator('#form_crud input[name="is_default"]');
    this.isActiveCheckbox  = page.locator('#form_crud input[name="is_active"]');
  }

  /** Navigate to the sender_identities index page. */
  async goto() {
    await this.page.goto('/admin/sender_identities');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/sender_identities/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/sender_identities/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/sender_identities/${id}`);
  }

  /**
   * Fill and submit the create/edit form with required fields.
   * No Select2 selects in this form — all plain inputs/checkboxes.
   */
  async fillAndSubmit(data: {
    name: string;
    email: string;
    replyTo?: string;
  }) {
    await this.nameInput.fill(data.name);
    await this.emailInput.fill(data.email);
    if (data.replyTo) {
      await this.replyToInput.fill(data.replyTo);
    }
    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Assert exactly `count` DataTable rows contain `name` in their text.
   */
  async expectRowCountByName(name: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(count);
  }

  /**
   * Click the is_default switch on a DataTable row.
   * The DataTable status component renders:
   *   <input class="form-check-input status-toggle" type="checkbox"
   *          data-field="is_default" data-id="{id}" ...>
   */
  async clickIsDefaultSwitchOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator('input.status-toggle[data-field="is_default"]').click();
  }

  /**
   * Loop-delete all rows matching `name` until none remain.
   * Guards against the known double-submit bug (two rows created per save).
   * Delete success text: 'supprimée' (from getMessages: 'Identité supprimée avec succès')
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
      // Second SweetAlert: 'Identité supprimée avec succès'
      await expect(this.page.locator('.swal2-popup')).toContainText('supprimée', { timeout: 10000 });
      await this.page.locator('.swal2-confirm').click();
      await this.page.waitForLoadState('networkidle');
      await helpers.waitForDataTable(this.page, this.tableId);
    }
  }
}
