import { Page, Locator, expect } from '@playwright/test';

/**
 * RolesPermissionsPage — page object for the roles + permissions management module.
 *
 * Routes:
 *   Roles index        GET  /admin/user-management/roles
 *   Permissions index  GET  /admin/user-management/permissions
 *
 * UI pattern (from roles/list.blade.php + _role-list.blade.php + _role-modal.blade.php):
 *   - Roles page renders CARD-based grid (NOT a DataTable).
 *     Each role card is in #roles-list-container .col-md-4.
 *     Card title: <h2> with ucwords($role->name).
 *     "Add new" card: .btn-add-role button that opens the modal.
 *   - Create/Edit uses a Bootstrap modal (#kt_modal_update_role) with AJAX submit.
 *     Modal form id: #kt_modal_update_role_form
 *     Role name input: #role-name-input
 *     Submit button:   #role-submit-btn
 *     After success: SweetAlert2 success dialog → dismiss → window.location.reload().
 *   - Delete: global JS function window.deleteRole(roleId, roleName) — called from
 *     card footer "Supprimer" buttons (if present). But the _role-list partial
 *     does NOT render a delete button on the card by default; delete is exposed only
 *     via the role show page or programmatically. We therefore use a direct API delete
 *     via the form action after locating the role card to get its route URL.
 *
 * IMPORTANT — the _role-list partial does NOT render a "Supprimer" button on role cards
 * by default (the partial defines window.deleteRole but card footer only has "Voir" +
 * "Modifier"). To delete the e2e-created role we must either:
 *   (a) Navigate to the role's show page (role.show route includes a delete button), or
 *   (b) Call deleteRole() directly via page.evaluate() after finding the role's id.
 * Strategy: after create, find the new role's card, get its "Voir le rôle" href to
 * extract the role id, then call window.deleteRole(id, name) via evaluate.
 *
 * Permissions page renders a yajra DataTable (table id: 'permissions-table').
 *   The DataTable is read-only in e2e (no create/delete — system permissions are guarded).
 */
export class RolesPermissionsPage {
  readonly page: Page;

  // Roles page
  readonly rolesListContainer: Locator;
  readonly addRoleButton: Locator;

  // Role modal
  readonly roleModal: Locator;
  readonly roleNameInput: Locator;
  readonly roleSubmitButton: Locator;

  // Permissions page — DataTable
  readonly permissionsTable: Locator;
  readonly permissionsTableWrapper: Locator;

  constructor(page: Page) {
    this.page = page;

    // Roles page
    this.rolesListContainer = page.locator('#roles-list-container');
    this.addRoleButton      = page.locator('.btn-add-role');

    // Role modal
    this.roleModal          = page.locator('#kt_modal_update_role');
    this.roleNameInput      = page.locator('#role-name-input');
    this.roleSubmitButton   = page.locator('#role-submit-btn');

    // Permissions DataTable (table id set in PermissionsDataTable::html() → 'permissions-table')
    this.permissionsTable        = page.locator('#permissions-table');
    this.permissionsTableWrapper = page.locator('#permissions-table_wrapper');
  }

  // ── Navigation ────────────────────────────────────────────────────────────

  /** Navigate to the roles index page. */
  async gotoRoles() {
    await this.page.goto('/admin/user-management/roles');
  }

  /** Navigate to the permissions index page. */
  async gotoPermissions() {
    await this.page.goto('/admin/user-management/permissions');
  }

  // ── Roles page assertions ─────────────────────────────────────────────────

  /** Assert the roles card grid is visible (at least the add-new card). */
  async expectRolesListVisible() {
    await expect(this.rolesListContainer).toBeVisible({ timeout: 15000 });
    // The "Ajouter un rôle" button is always rendered (last card in grid).
    await expect(this.addRoleButton).toBeVisible({ timeout: 10000 });
  }

  /**
   * Assert a role card with the given name is present in the grid.
   * The partial renders ucwords($role->name) in an <h2> inside .col-md-4.
   */
  async expectRoleCardVisible(roleName: string) {
    // ucwords capitalises first letter of each word.
    const ucName = roleName.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
    await expect(
      this.rolesListContainer.locator(`.card-title h2:has-text("${ucName}")`)
    ).toBeVisible({ timeout: 10000 });
  }

  /**
   * Assert a role card with the given name is NOT present in the grid.
   */
  async expectRoleCardGone(roleName: string) {
    const ucName = roleName.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
    await expect(
      this.rolesListContainer.locator(`.card-title h2:has-text("${ucName}")`)
    ).toHaveCount(0);
  }

  // ── Role create (modal) ───────────────────────────────────────────────────

  /**
   * Open the "Ajouter un rôle" modal.
   * The .btn-add-role button triggers Bootstrap modal #kt_modal_update_role.
   */
  async openCreateRoleModal() {
    await this.addRoleButton.click();
    await expect(this.roleModal).toBeVisible({ timeout: 10000 });
  }

  /**
   * Fill the role name input and submit the modal form.
   * After success, the modal JS fires a SweetAlert2 success → then window.location.reload().
   * Caller must wait for the success swal and dismiss it.
   */
  async fillRoleNameAndSubmit(roleName: string) {
    await this.roleNameInput.fill(roleName);
    await this.roleSubmitButton.click();
  }

  // ── Role delete ───────────────────────────────────────────────────────────

  /**
   * Delete a role by its name using window.deleteRole(id, name).
   *
   * Strategy:
   *   1. Find the role card matching roleName (case-insensitive partial text search
   *      via ucwords transform).
   *   2. Locate its "Voir le rôle" button → href = /admin/user-management/roles/{id}.
   *   3. Extract the id from the href.
   *   4. Call window.deleteRole(id, roleName) via page.evaluate().
   *
   * The JS function opens a SweetAlert2 confirm → then fires DELETE AJAX → then
   * opens a success SweetAlert2 → then reloads the page.
   * Caller must handle both swal dialogs after this call.
   */
  async deleteRoleByName(roleName: string): Promise<void> {
    const ucName = roleName.split(' ').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');

    // Find the card that contains this role name.
    const card = this.rolesListContainer.locator(`.col-md-4`).filter({
      has: this.page.locator(`.card-title h2:has-text("${ucName}")`),
    });

    // Get the "Voir le rôle" link href to extract the role id.
    const viewLink = card.locator('a:has-text("Voir le rôle")');
    const href = await viewLink.getAttribute('href', { timeout: 10000 });
    if (!href) {
      throw new Error(`RolesPermissionsPage: could not find "Voir le rôle" href for role "${roleName}"`);
    }

    // Extract id: href = /admin/user-management/roles/{id}
    const idMatch = href.match(/\/roles\/(\d+)/);
    if (!idMatch) {
      throw new Error(`RolesPermissionsPage: could not parse role id from href "${href}"`);
    }
    const roleId = idMatch[1];

    // Call window.deleteRole(id, name) — defined in _role-modal.blade.php.
    await this.page.evaluate(
      ([id, name]) => (window as unknown as { deleteRole: (id: string, name: string) => void }).deleteRole(id, name),
      [roleId, roleName] as [string, string]
    );
  }

  // ── Permissions page assertions ───────────────────────────────────────────

  /** Assert the permissions DataTable wrapper is attached (DataTable rendered). */
  async expectPermissionsTableVisible() {
    await this.permissionsTableWrapper.waitFor({ state: 'attached', timeout: 30000 });
    await expect(this.permissionsTable).toBeVisible({ timeout: 15000 });
  }
}
