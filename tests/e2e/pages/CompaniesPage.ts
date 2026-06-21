import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * CompaniesPage — page object for the fretiq companies module.
 *
 * Table ID: `company` (GlobalDataTable::getTableId() returns strtolower(class_basename(model))
 *   → strtolower('Company') → 'company').
 * DataTables appends `_wrapper`, `_processing` from that base id.
 *
 * Form field names (from form.blade.php):
 *   name, domain, sector, phone, country (select), estimated_size (select),
 *   relationship (select), source (select), qualification_status (select),
 *   ai_score, ai_explanation
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/companies
 *   create  GET  /admin/companies/create
 *   view    GET  /admin/companies/{id}
 *   edit    GET  /admin/companies/{id}/edit
 *   enrich  POST /admin/companies/{id}/enrich (AJAX — button in DataTable action cell)
 */
export class CompaniesPage extends DataTablePage {
  // ── Form field locators (create / edit) ──────────────────────────────────

  readonly nameInput: Locator;
  readonly domainInput: Locator;
  readonly sectorInput: Locator;
  readonly phoneInput: Locator;
  readonly aiScoreInput: Locator;

  // Select fields (Select2 — use selectSelect2() helper in specs)
  readonly countrySelect: Locator;
  readonly relationshipSelect: Locator;
  readonly sourceSelect: Locator;
  readonly qualificationStatusSelect: Locator;
  readonly estimatedSizeSelect: Locator;

  // DataTable action — enrich button (rendered as .enrich-btn in _row-actions partial)
  readonly enrichButtonSelector = '.enrich-btn';

  constructor(page: Page) {
    super(page, {
      tableId: 'company-table',       // index blade: tableId='company' → '#company-table'
      searchSelector: '#mySearchInput', // index blade: id="mySearchInput"
      addButtonText: 'Ajouter',       // French label on the add button in Metronic header toolbar
    });

    // Form fields — scoped to #form_crud to avoid collisions with the stock
    // Metronic demo modal (#kt_modal_create_app_form) which also has input[name="name"].
    this.nameInput                  = page.locator('#form_crud input[name="name"]');
    this.domainInput                = page.locator('#form_crud input[name="domain"]');
    this.sectorInput                = page.locator('#form_crud input[name="sector"]');
    this.phoneInput                 = page.locator('#form_crud input[name="phone"]');
    this.aiScoreInput               = page.locator('#form_crud input[name="ai_score"]');

    // Select2 selects — locate their wrapper div for selectSelect2() helper.
    this.countrySelect              = page.locator('#form_crud select[name="country"]').locator('..');
    this.relationshipSelect         = page.locator('#form_crud select[name="relationship"]').locator('..');
    this.sourceSelect               = page.locator('#form_crud select[name="source"]').locator('..');
    this.qualificationStatusSelect  = page.locator('#form_crud select[name="qualification_status"]').locator('..');
    this.estimatedSizeSelect        = page.locator('#form_crud select[name="estimated_size"]').locator('..');
  }

  /** Navigate to the companies index page. */
  async goto() {
    await this.page.goto('/admin/companies');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/companies/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/companies/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/companies/${id}`);
  }

  /**
   * Fill and submit the create/edit form with minimal required fields.
   * Caller must navigate to the form page first (gotoCreate / gotoEdit).
   */
  async fillAndSubmit(data: {
    name: string;
    domain?: string;
    sector?: string;
  }) {
    await this.nameInput.fill(data.name);
    if (data.domain) {
      await this.domainInput.fill(data.domain);
    }
    if (data.sector) {
      await this.sectorInput.fill(data.sector);
    }
    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Click the enrich action button on the first DataTable row matching `rowIndex`.
   * The enrich button uses data-kt-action="enrich_row" in the action cell partial.
   */
  async clickEnrichOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator(this.enrichButtonSelector).click();
  }

  /**
   * Click the is_active toggle switch on a DataTable row.
   * Metronic renders boolean switch columns as <input type="checkbox"> inside a label
   * with data-kt-switch="true" — the DataTable switch column wires a PUT AJAX call.
   */
  async clickActiveSwitchOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator('input[type="checkbox"]').click();
  }

  /**
   * Assert that the enrich button (.enrich-btn) is visible on at least one row.
   * Rendered only when the company has a domain AND user can `enrich companies`.
   */
  async expectEnrichButtonVisible() {
    const enrichBtn = this.table.locator(this.enrichButtonSelector).first();
    await expect(enrichBtn).toBeVisible({ timeout: 10000 });
  }

  /**
   * Assert exactly `count` DataTable rows contain `name` in their text.
   * Used by the create test to verify EXACTLY ONE row was inserted (not two).
   */
  async expectRowCountByName(name: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(count);
  }

  /**
   * Delete the first DataTable row matching `name` through the UI:
   * click delete action → SweetAlert2 confirm → success dialog confirm.
   * Assumes the DataTable is already filtered/searched so the target row is first.
   */
  async deleteRowByName(
    name: string,
    helpers: {
      search: (q: string) => Promise<void>;
      waitForDataTable: (page: import('@playwright/test').Page, id: string) => Promise<void>;
      confirmDelete: (page: import('@playwright/test').Page) => Promise<void>;
    }
  ) {
    await helpers.search(name);
    await helpers.waitForDataTable(this.page, this.tableId);

    await this.clickRowAction(0, 'delete');
    await helpers.confirmDelete(this.page);

    // Second SweetAlert: success dialog after DELETE completes.
    await expect(this.page.locator('.swal2-popup')).toContainText('supprimée', { timeout: 10000 });
    await this.page.locator('.swal2-confirm').click();

    await this.page.waitForLoadState('networkidle');
    await helpers.waitForDataTable(this.page, this.tableId);
  }
}
