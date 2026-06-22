import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * ContactsPage — page object for the fretiq contacts module.
 *
 * Table ID: `contact-table`
 *   Model: Contact → getName() → 'contact' → html() builder → setTableId('contact-table')
 *
 * Form field names (from form.blade.php):
 *   name (required), email (required), company_id (Select2 required), position,
 *   phone, source_url, status (Select2), source (Select2), legal_basis (Select2),
 *   email_kind (Select2), consent_at
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/contacts
 *   create  GET  /admin/contacts/create
 *   view    GET  /admin/contacts/{id}
 *   edit    GET  /admin/contacts/{id}/edit
 *
 * No toggleable fields — ContactController declares no $toggleableFields (inherits empty default).
 */
export class ContactsPage extends DataTablePage {
  // ── Form field locators (create / edit) ──────────────────────────────────

  readonly nameInput: Locator;
  readonly emailInput: Locator;
  readonly positionInput: Locator;
  readonly phoneInput: Locator;
  readonly sourceUrlInput: Locator;

  // Select2 selects — locate wrapper div for selectSelect2() helper
  // company_id is required; others are optional
  readonly companyIdSelect: Locator;
  readonly statusSelect: Locator;
  readonly sourceSelect: Locator;
  readonly legalBasisSelect: Locator;
  readonly emailKindSelect: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'contact-table',         // Contact.getName() → 'contact' → '#contact-table'
      searchSelector: '#mySearchInput',  // shared id across all modules
      addButtonText: 'Ajouter',
    });

    // Form fields scoped to #form_crud to avoid Metronic demo modal collision
    this.nameInput      = page.locator('#form_crud input[name="name"]');
    this.emailInput     = page.locator('#form_crud input[name="email"]');
    this.positionInput  = page.locator('#form_crud input[name="position"]');
    this.phoneInput     = page.locator('#form_crud input[name="phone"]');
    this.sourceUrlInput = page.locator('#form_crud input[name="source_url"]');

    // Select2 wrappers — parent div of the native <select>
    this.companyIdSelect  = page.locator('#form_crud select[name="company_id"]').locator('..');
    this.statusSelect     = page.locator('#form_crud select[name="status"]').locator('..');
    this.sourceSelect     = page.locator('#form_crud select[name="source"]').locator('..');
    this.legalBasisSelect = page.locator('#form_crud select[name="legal_basis"]').locator('..');
    this.emailKindSelect  = page.locator('#form_crud select[name="email_kind"]').locator('..');
  }

  /** Navigate to the contacts index page. */
  async goto() {
    await this.page.goto('/admin/contacts');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/contacts/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/contacts/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/contacts/${id}`);
  }

  /**
   * Fill and submit the create/edit form.
   * Requires name + email. company_id must be supplied as a visible Select2 option text
   * (e.g. the name of an existing company). If omitted, the form will fail server-side
   * validation (company_id is required).
   */
  async fillAndSubmit(data: {
    name: string;
    email: string;
    companyOptionText: string;  // visible label of an existing company in the Select2
    position?: string;
  }) {
    await this.nameInput.fill(data.name);
    await this.emailInput.fill(data.email);

    // company_id: set value on the native <select> directly (Select2 reads it via change event)
    const companySelect = this.page.locator('#form_crud select[name="company_id"]');
    await companySelect.selectOption({ label: data.companyOptionText });
    await companySelect.dispatchEvent('change');

    if (data.position) {
      await this.positionInput.fill(data.position);
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
   * Loop-delete all rows matching `name` until none remain.
   * Guards against the known double-submit bug (two rows created per save).
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
      // Click delete on the first matching row
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
