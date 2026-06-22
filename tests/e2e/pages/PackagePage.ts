import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * PackagePage — page object for the fretiq packages (Packs) module.
 *
 * Table ID: `package-table`
 *   GlobalDataTable::getTableId() → strtolower(class_basename(Package)) → 'package'
 *   html() builder: setTableId('package' + '-table') → '#package-table'
 *
 * Form field names (from form.blade.php — NO Select2 fields in this form):
 *   name (required, text), daily_credits (nullable integer), price_monthly (nullable decimal),
 *   sort_order (integer), is_active (checkbox)
 *
 * Toggleable fields declared in PackageController: ['is_active']
 *   executeSwitch PUT /admin/packages/executeSwitch/{id} — AJAX, badge/switch re-renders in-cell.
 *
 * Assign action (index page "Pack actif" card):
 *   POST /admin/packages/assign with package_id (native <select>, not Select2)
 *   Redirects back on success with session flash 'success'.
 *
 * Routes (all relative to baseURL):
 *   index   GET    /admin/packages
 *   create  GET    /admin/packages/create
 *   view    GET    /admin/packages/{id}
 *   edit    GET    /admin/packages/{id}/edit
 *   assign  POST   /admin/packages/assign
 */
export class PackagePage extends DataTablePage {
  // ── Form field locators (create / edit) ─────────────────────────────────

  readonly nameInput: Locator;
  readonly dailyCreditsInput: Locator;
  readonly priceMonthlyInput: Locator;
  readonly sortOrderInput: Locator;
  readonly isActiveCheckbox: Locator;

  // ── Assign form locators (index page "Pack actif" card) ─────────────────

  /** Native <select name="package_id"> in the assign card (NOT Select2). */
  readonly assignPackageSelect: Locator;
  /** Submit button for the assign form. */
  readonly assignSubmitButton: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'package-table',         // Package.getName() → 'package' → '#package-table'
      searchSelector: '#mySearchInput',  // shared id across all modules
      addButtonText: 'Ajouter un Pack',
    });

    // Form fields scoped to #form_crud to avoid Metronic demo modal collision.
    this.nameInput         = page.locator('#form_crud input[name="name"]');
    this.dailyCreditsInput = page.locator('#form_crud input[name="daily_credits"]');
    this.priceMonthlyInput = page.locator('#form_crud input[name="price_monthly"]');
    this.sortOrderInput    = page.locator('#form_crud input[name="sort_order"]');
    this.isActiveCheckbox  = page.locator('#form_crud input[name="is_active"]');

    // Assign card — scoped to form[action$="/assign"] to avoid any #form_crud collision.
    this.assignPackageSelect = page.locator('form[action$="/assign"] select[name="package_id"]');
    this.assignSubmitButton  = page.locator('form[action$="/assign"] button[type="submit"]');
  }

  /** Navigate to the packages index page. */
  async goto() {
    await this.page.goto('/admin/packages');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/packages/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/packages/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/packages/${id}`);
  }

  /**
   * Fill and submit the create/edit form.
   * Only `name` is required. All other fields are optional and nullable server-side.
   */
  async fillAndSubmit(data: {
    name: string;
    dailyCredits?: string;
    priceMonthly?: string;
    sortOrder?: string;
    isActive?: boolean;
  }) {
    await this.nameInput.fill(data.name);

    if (data.dailyCredits !== undefined) {
      await this.dailyCreditsInput.fill(data.dailyCredits);
    }

    if (data.priceMonthly !== undefined) {
      await this.priceMonthlyInput.fill(data.priceMonthly);
    }

    if (data.sortOrder !== undefined) {
      await this.sortOrderInput.fill(data.sortOrder);
    }

    if (data.isActive !== undefined) {
      const checked = await this.isActiveCheckbox.isChecked();
      if (checked !== data.isActive) {
        await this.isActiveCheckbox.click();
      }
    }

    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Click a row-action button in the DataTable action cell.
   * The action column renders edit (title="Modifier"), view (title="Voir"),
   * and delete (.delete-btn) anchors, all gated @can('manage packages').
   * The delete button is wired to datatables-utils.js click-delegate
   * (SweetAlert confirm -> AJAX DELETE); callers follow with confirmDelete().
   */
  async clickRowAction(rowIndex: number, action: 'view' | 'edit' | 'delete') {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    const selector =
      action === 'edit'   ? 'a[title="Modifier"]' :
      action === 'view'   ? 'a[title="Voir"]' :
                            'a.delete-btn';
    await row.locator(selector).first().click();
  }

  /**
   * Click the executeSwitch toggle (inline AJAX PUT) for the row at `rowIndex`.
   * The toggle input renders as <input type="checkbox"> inside the is_active cell.
   * The PUT fires without page navigation; wait for networkidle after.
   */
  async clickActiveSwitchOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    // The switch column renders a Bootstrap form-switch checkbox.
    const toggle = row.locator('input[type="checkbox"]').first();
    await toggle.click();
  }

  /**
   * Assign a package via the index-page assign card.
   * Selects the package by its visible option text (e.g. "Starter (50 crédits/j)")
   * using native selectOption — no Select2 in the assign form.
   * Submits and waits for the redirect back to the index page.
   */
  async assignPackage(packageOptionText: string) {
    await this.assignPackageSelect.selectOption({ label: packageOptionText });
    await this.assignSubmitButton.click();
    // POST /admin/packages/assign → redirect()->back() → index page reload.
    await this.page.waitForLoadState('networkidle');
  }

  /**
   * Assert exactly `count` DataTable rows contain `name` in their text.
   */
  async expectRowCountByName(name: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(count);
  }

  /**
   * Loop-delete all rows matching `name` until none remain.
   * Guards against any double-submit producing duplicate rows.
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
