import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * UserPage — page object for the fretiq users module.
 *
 * Table ID: `users-table`
 *   User::getName() returns 'users' → GlobalDataTable::html() builds '#users-table'.
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/users
 *   create  GET  /admin/users/create
 *   view    GET  /admin/users/{id}
 *   edit    GET  /admin/users/{id}/edit
 *
 * IMPORTANT — delete flow:
 *   The users index has its own inline delete JS (not the generic .delete-btn handler).
 *   1. Click `.btn-delete-user` button on the row.
 *   2. SweetAlert2 confirm dialog ("Êtes-vous sûr de vouloir supprimer <name> ?").
 *   3. AJAX DELETE succeeds → SweetAlert2 success dialog ("Utilisateur supprimé avec succès.").
 *   4. Dismiss success dialog → DataTable ajax.reload() is called (no full page navigation).
 *
 * IMPORTANT — edit-form update redirects to /admin/users/{id}/edit (NOT /index).
 *   Wait for navigation OFF the current edit URL using a waitForURL predicate that checks
 *   the URL changed (e.g., !u.pathname.endsWith('/edit')) — in practice the redirect is
 *   to the same /edit URL for the saved record, so we use network-idle to settle.
 *
 * IMPORTANT — superadmin excluded from DataTable query (whereDoesntHave role 'superadmin').
 *   The session user (superadmin) cannot see/delete themselves. Use role=commercial on create.
 */
export class UserPage extends DataTablePage {
  // ── Form field locators (create / edit) — scoped to #form_crud ──────────

  readonly nameInput: Locator;
  readonly emailInput: Locator;
  readonly passwordInput: Locator;
  readonly passwordConfirmationInput: Locator;
  /** The native <select name="role"> (wrapped by Select2 with data-hide-search). */
  readonly roleSelect: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'users-table',          // User::getName() → 'users' → 'users-table'
      searchSelector: '#mySearchInput',
      addButtonText: 'Ajouter un utilisateur',
    });

    // Form fields scoped to #form_crud to avoid Metronic modal collisions.
    this.nameInput                   = page.locator('#form_crud input[name="name"]');
    this.emailInput                  = page.locator('#form_crud input[name="email"]');
    this.passwordInput               = page.locator('#form_crud input[name="password"]');
    this.passwordConfirmationInput   = page.locator('#form_crud input[name="password_confirmation"]');
    // Native <select name="role"> — Select2 wraps it but the native value is what gets submitted.
    this.roleSelect                  = page.locator('#form_crud select[name="role"]');
  }

  /** Navigate to the users index page. */
  async goto() {
    await this.page.goto('/admin/users');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/users/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/users/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/users/${id}`);
  }

  /**
   * Fill and submit the create/edit form.
   *
   * role must be a non-superadmin role ('commercial' or 'admin').
   * Password fields are required on create; omit on edit (empty = no change).
   * Caller must navigate to the form page first (gotoCreate / gotoEdit).
   */
  async fillAndSubmit(data: {
    name: string;
    email: string;
    password?: string;
    passwordConfirmation?: string;
    role?: string;
  }) {
    await this.nameInput.fill(data.name);
    await this.emailInput.fill(data.email);

    if (data.password !== undefined) {
      await this.passwordInput.fill(data.password);
    }
    if (data.passwordConfirmation !== undefined) {
      await this.passwordConfirmationInput.fill(data.passwordConfirmation);
    }

    // Role: use native <select> directly (Select2 wraps it with data-hide-search="true").
    // selectOption() sets the native value; dispatching 'change' syncs Select2 display.
    if (data.role !== undefined) {
      await this.roleSelect.selectOption({ value: data.role });
      await this.roleSelect.dispatchEvent('change');
    }

    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Click the delete button on a DataTable row by rowIndex.
   * Users use `.btn-delete-user` (NOT the generic `.delete-btn`).
   * Triggers SweetAlert2 confirm; caller must handle both swal dialogs after.
   */
  async clickDeleteOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator('.btn-delete-user').click();
  }

  /**
   * Assert that the given user name appears in a table row.
   */
  async expectUserRowVisible(name: string) {
    await expect(
      this.table.locator(`tbody tr:has-text("${name}")`).first()
    ).toBeVisible({ timeout: 10000 });
  }

  /**
   * Assert that no table row contains the given name.
   */
  async expectUserRowGone(name: string) {
    await expect(
      this.table.locator(`tbody tr:has-text("${name}")`)
    ).toHaveCount(0);
  }

  /**
   * Assert exactly `count` DataTable rows contain `text` in their text.
   */
  async expectRowCountByText(text: string, count: number) {
    await expect(
      this.table.locator(`tbody tr:has-text("${text}")`)
    ).toHaveCount(count);
  }
}
