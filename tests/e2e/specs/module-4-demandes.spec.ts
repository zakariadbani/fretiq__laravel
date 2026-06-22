// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror).
import { test, expect } from '../fixtures/console-guard';
import { DemandePage } from '../pages/DemandePage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
} from '../helpers/test-utils';

// >>> custom-test-author:demandes-e2e

/**
 * module-4-demandes — fretiq demandes CRUD e2e suite.
 *
 * Runs against the live dev site (http://fretiq.test) with the
 * pre-authenticated superadmin storageState (tests/e2e/.auth/admin.json).
 *
 * Contract:
 *   - Lean suite — demandes has heavy code-test coverage; e2e only validates
 *     the rendered UI, form fields, and one create→locate→delete cycle.
 *   - No `name` column on Demande. Row location uses the contact email text
 *     shown in the DataTable 'contact' column.
 *   - Create requires contact_id (Select2 — picks first available contact).
 *     If no contacts exist in the dev DB the mutation test is skipped with a
 *     clear note.
 *   - DataTable table ID: 'demande-table'
 *     (Demande::getName() → 'demande' → GlobalDataTable → 'demande-table').
 *   - Delete flow: standard .delete-btn → SweetAlert2 confirm → success dialog.
 *
 * Row locate strategy after create:
 *   crud-form-handler.js on 2xx follows the redirect URL
 *   (admin.demandes.show/{id}). We capture the id from the post-create URL
 *   via page.url(), then use the contact email extracted from the option text
 *   to locate/search the row in the DataTable.
 */

test.describe('Demandes module', () => {

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const demandes = new DemandePage(page);
    await demandes.goto();

    await expectPath(page, '/admin/demandes');
    await waitForDataTable(page, 'demande-table');
    await demandes.expectTableVisible();
  });

  // ── 2. Create form fields render ───────────────────────────────────────────

  test('create form renders required fields', async ({ page }) => {
    const demandes = new DemandePage(page);
    await demandes.gotoCreate();

    // Assert the required form fields are visible.
    await demandes.expectCreateFormFieldsVisible();

    // Verify the contact_id and status Select2 selects are present.
    await expect(
      page.locator('#form_crud select[name="contact_id"]')
    ).toBeAttached({ timeout: 10000 });

    await expect(
      page.locator('#form_crud select[name="status"]')
    ).toBeAttached({ timeout: 10000 });

    // The captured_at datetime input must be visible.
    await expect(demandes.capturedAtInput).toBeVisible({ timeout: 10000 });
  });

  // ── 3. Create → locate → delete cycle (contact-conditional) ───────────────

  test('create demande (if contacts exist), row appears by contact text, then delete (cleanup)', async ({ page }) => {
    const demandes = new DemandePage(page);
    await demandes.gotoCreate();

    // Gate: only run mutation if at least one contact is selectable.
    const contactCount = await demandes.countSelectableContacts();

    if (contactCount === 0) {
      // ponytail: skipping create mutation — no contacts available in dev DB.
      // This is not a test failure; the form field test above already verifies
      // the UI renders. Seed contacts to unlock this test.
      test.skip();
      return;
    }

    // Select first contact and submit the form.
    // fillAndSubmitCreate() returns the raw option text (e.g. "John Doe <john@example.com>").
    const contactOptionText = await demandes.fillAndSubmitCreate({});

    // Extract the email portion from "Name <email>" or use the full option text for search.
    // The DataTable renders the contact email as a link in the 'contact' column.
    const emailMatch = contactOptionText.match(/<([^>]+)>/);
    const searchToken = emailMatch ? emailMatch[1] : contactOptionText;

    // crud-form-handler.js follows the redirect to admin.demandes.view/{id}.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and locate the row by contact email text directly —
    // no search: every column in DemandesDataTable is searchable:false by design
    // (status-filter only). The contact column renders the email as link text,
    // so we match against that text in the rendered rows without filtering.
    await demandes.goto();
    await waitForDataTable(page, 'demande-table');

    // At least one row whose text contains the contact email must be visible.
    const rows = demandes.table.locator(`tbody tr:has-text("${searchToken}")`);
    await expect(rows.first()).toBeVisible({ timeout: 10000 });

    // Cleanup: delete the first matching row.
    await demandes.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog after DELETE.
    await expect(page.locator('.swal2-popup')).toContainText('supprimée', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'demande-table');
  });

});

// <<<
