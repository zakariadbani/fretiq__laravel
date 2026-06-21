import { Page, Locator, expect } from '@playwright/test';

/**
 * Base page object for any yajra DataTable-based list page.
 * Extend this for specific fretiq modules (companies, contacts, campaigns, etc.)
 *
 * All fretiq backend CRUD modules use the same yajra/DataTables pattern with
 * Bootstrap 5 + Metronic styling. Table IDs follow the {model}-table convention
 * (e.g. 'company-table', 'contact-table') — verify in each module's DataTable class.
 */
export class DataTablePage {
  readonly page: Page;
  readonly tableId: string;
  readonly table: Locator;
  readonly tableWrapper: Locator;
  readonly searchInput: Locator;
  readonly addButton: Locator;
  readonly emptyState: Locator;
  readonly paginationInfo: Locator;

  constructor(page: Page, options: {
    tableId: string;
    searchSelector?: string;
    addButtonText?: string;
  }) {
    this.page = page;
    this.tableId = options.tableId;
    this.table = page.locator(`#${options.tableId}`);
    // DataTables wraps the table in a div with id="${tableId}_wrapper"
    this.tableWrapper = page.locator(`#${options.tableId}_wrapper`);
    this.searchInput = page.locator(
      options.searchSelector || `[data-kt-${options.tableId}-filter="search"]`
    );
    this.addButton = page.locator(`button:has-text("${options.addButtonText || 'Add'}")`);
    // DataTables v2 French locale empty-state messages (both variants):
    //   emptyTable  (no records at all): "Aucune donnée disponible dans le tableau"
    //   zeroRecords (search no match):   "Aucune entrée correspondante trouvée"
    // Both appear in a <td class="dt-empty"> — accept either.
    this.emptyState = page.locator(
      'td:has-text("Aucune donnée disponible"), td:has-text("Aucune entrée correspondante trouvée")'
    );
    this.paginationInfo = page.locator('.dataTables_info');
  }

  async expectTableVisible() {
    // DataTables wraps the table in #${tableId}_wrapper once initialized.
    // We assert 'attached' (not 'visible') because Metronic may keep the wrapper
    // visibility:hidden for an indeterminate time under server load.
    await this.tableWrapper.waitFor({ state: 'attached', timeout: 30000 });
  }

  async expectRowCount(count: number) {
    // Exclude DataTables v2 empty-state rows (class="dt-empty")
    const rows = this.table.locator('tbody tr:not(:has(.dt-empty))');
    await expect(rows).toHaveCount(count);
  }

  async expectMinRows(min: number) {
    // Exclude DataTables v2 empty-state rows (class="dt-empty")
    const rows = this.table.locator('tbody tr:not(:has(.dt-empty))');
    expect(await rows.count()).toBeGreaterThanOrEqual(min);
  }

  async search(query: string) {
    await this.searchInput.fill(query);
    // DataTableUtils wires the search input to a 'keyup' listener.
    // Playwright's fill() sets value programmatically without firing keyboard events,
    // so we must explicitly dispatch 'keyup' after fill to trigger the DataTable search.
    await this.searchInput.dispatchEvent('keyup');
    // DataTables debounce — wait for processing indicator, then network idle.
    const processingEl = this.page.locator(`#${this.tableId}_processing`);
    const processingCount = await processingEl.count();
    if (processingCount > 0) {
      await expect(processingEl).toBeHidden({ timeout: 10000 });
    }
    await this.page.waitForLoadState('networkidle');
  }

  async clickAdd() {
    await this.addButton.scrollIntoViewIfNeeded();
    await this.addButton.click();
  }

  async getRowTexts(columnIndex: number): Promise<string[]> {
    const cells = this.table.locator(`tbody tr td:nth-child(${columnIndex + 1})`);
    return cells.allTextContents();
  }

  async clickRowAction(rowIndex: number, action: 'view' | 'edit' | 'delete') {
    const actionMap = {
      view: '[data-kt-action="view_row"], a[data-kt-action]',
      edit: '[data-kt-action="update_row"]',
      delete: '[data-kt-action="delete_row"]',
    };
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator(actionMap[action]).click();
  }
}
