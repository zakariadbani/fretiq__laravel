import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * ProspectCriteriaPage — page object for the fretiq prospect_criteria module.
 *
 * Table ID: `prospect_criteria-table`
 *   Resolved via ProspectCriteriaDataTable::getTableId() → 'prospect_criteria'
 *   → html() builder → setTableId('prospect_criteria' + '-table') → '#prospect_criteria-table'.
 *   The base-class default (strtolower(class_basename(model))) = 'prospectcriteria' does NOT
 *   match — hence getTableId() is overridden in the DataTable class.
 *
 * Form field names (from form.blade.php):
 *   name            (text,     required)
 *   daily_limit     (number,   required, default 20)
 *   is_active       (checkbox, hidden+checkbox D9 pattern — hidden=0 ensures false always posts)
 *   sectors[]       (select2 multi, tags allowed)
 *   countries[]     (select2 multi, no tags)
 *   company_sizes[] (select2 multi)
 *   target_positions[] (select2 multi, tags allowed)
 *
 * Routes (all relative to baseURL):
 *   index            GET    /admin/prospect_criteria
 *   create           GET    /admin/prospect_criteria/create
 *   store            POST   /admin/prospect_criteria
 *   view             GET    /admin/prospect_criteria/{id}
 *   edit             GET    /admin/prospect_criteria/{id}/edit
 *   update           PUT    /admin/prospect_criteria/{id}
 *   delete           DELETE /admin/prospect_criteria/{id}
 *   executeSwitch    PUT    /admin/prospect_criteria/executeSwitch/{id}
 *   duplicate        POST   /admin/prospect_criteria/{prospect_criteria}/duplicate
 *   preview_queries  GET    /admin/prospect_criteria/{prospect_criteria}/preview-queries
 *   discovery_status GET    /admin/prospect_criteria/{id}/discovery-status
 *   discover         POST   /admin/prospect_criteria/{id}/discover  ← DO NOT TRIGGER IN TESTS
 *
 * Toggleable fields: ['is_active'] — ProspectCriteriaController::$toggleableFields.
 *
 * Custom actions (safe, no external API cost):
 *   duplicate        — clones the row; redirects to clone edit page
 *   preview_queries  — returns JSON { queries: string[] }; no SerpAPI call
 *   discovery_status — JSON poll; returns nulls when no run exists
 *
 * DESTRUCTIVE — NEVER TRIGGER in tests:
 *   discover (POST) — fires SerpAPI + Hunter calls; costs real credits.
 *   The "Lancer la découverte" button must be asserted PRESENT but never clicked.
 */
export class ProspectCriteriaPage extends DataTablePage {
  // ── Form field locators (create / edit) ──────────────────────────────────

  readonly nameInput: Locator;
  readonly dailyLimitInput: Locator;
  readonly isActiveCheckbox: Locator;

  // Multi-select wrappers (Select2) — locate parent div of the native <select>
  readonly sectorsSelect: Locator;
  readonly countriesSelect: Locator;
  readonly companySizesSelect: Locator;
  readonly targetPositionsSelect: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'prospect_criteria-table',  // getTableId() → 'prospect_criteria' → '#prospect_criteria-table'
      searchSelector: '#mySearchInput',
      addButtonText: 'Ajouter un critère',
    });

    // Form fields — scoped to #form_crud
    this.nameInput        = page.locator('#form_crud input[name="name"]');
    this.dailyLimitInput  = page.locator('#form_crud input[name="daily_limit"]');
    // D9 pattern: two inputs share name="is_active"; the checkbox is the one with type="checkbox"
    this.isActiveCheckbox = page.locator('#form_crud input[type="checkbox"][name="is_active"]');

    // Select2 wrappers — parent div of the native <select>
    // Note: multi-selects use name="sectors[]", "countries[]", etc.
    this.sectorsSelect         = page.locator('#form_crud select[name="sectors[]"]').locator('..');
    this.countriesSelect       = page.locator('#form_crud select[name="countries[]"]').locator('..');
    this.companySizesSelect    = page.locator('#form_crud select[name="company_sizes[]"]').locator('..');
    this.targetPositionsSelect = page.locator('#form_crud select[name="target_positions[]"]').locator('..');
  }

  /** Navigate to the prospect_criteria index page. */
  async goto() {
    await this.page.goto('/admin/prospect_criteria');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/prospect_criteria/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/prospect_criteria/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/prospect_criteria/${id}`);
  }

  /**
   * Fill and submit the create/edit form with minimal required fields.
   * Caller must navigate to the form page first (gotoCreate / gotoEdit).
   *
   * Only `name` is truly required by server validation.
   * `daily_limit` defaults to 20 in the form — omit to accept the default.
   * `is_active` defaults to checked (true) on create — omit unless you need false.
   */
  async fillAndSubmit(data: {
    name: string;
    dailyLimit?: number;
    isActive?: boolean;
  }) {
    await this.nameInput.fill(data.name);

    if (data.dailyLimit !== undefined) {
      await this.dailyLimitInput.fill(String(data.dailyLimit));
    }

    if (data.isActive !== undefined) {
      const checked = await this.isActiveCheckbox.isChecked();
      if (data.isActive && !checked) {
        await this.isActiveCheckbox.check();
      } else if (!data.isActive && checked) {
        await this.isActiveCheckbox.uncheck();
      }
    }

    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Click the is_active toggle switch on a DataTable row (executeSwitch).
   * Metronic renders boolean switch columns as <input type="checkbox"> inside the cell.
   * The DataTable wires a PUT AJAX call to executeSwitch on toggle.
   */
  async clickActiveSwitchOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator('input[type="checkbox"]').click();
  }

  /**
   * Click the "Dupliquer" (duplicate) action button on a DataTable row.
   * The button calls submitPostForm(duplicateUrl, csrfToken) which creates a
   * hidden <form> and submits it — triggers a POST to duplicate endpoint.
   * The server creates a clone (name = 'Copie de {original}', is_active=false)
   * and redirects to the clone's edit page.
   *
   * Selector: button with title="Dupliquer" (bi-copy icon).
   */
  async clickDuplicateOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator('button[title="Dupliquer"]').click();
  }

  /**
   * Assert exactly `count` DataTable rows contain `name` in their text.
   */
  async expectRowCountByName(name: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(count);
  }

  /**
   * Delete the first row matching `name` through the two-SweetAlert UI flow.
   * delete-btn → SweetAlert confirm → success SweetAlert confirm.
   *
   * getMessages() in the DataTable sets deleteSuccess = 'Critère supprimé avec succès'.
   * The success SweetAlert contains 'supprimé' — match on that substring.
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
    // getMessages()::deleteSuccess = 'Critère supprimé avec succès' → contains 'supprimé'
    await expect(this.page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
    await this.page.locator('.swal2-confirm').click();

    await this.page.waitForLoadState('networkidle');
    await helpers.waitForDataTable(this.page, this.tableId);
  }
}
