import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * DemandePage — page object for the fretiq demandes module.
 *
 * Table ID: `demande-table`
 *   Demande uses the Validator trait. getName() → strtolower('Demande') = 'demande'
 *   → GlobalDataTable::html() builds '#demande-table'.
 *
 * DataTable columns rendered (from DemandesDataTable):
 *   id | contact (email link) | company (name text) | source | kind | status | captured_at | action
 *
 * Row location strategy for created demandes:
 *   - The DataTable renders the contact email as a link in the 'contact' column.
 *   - The company name appears as plain text in the 'company' column.
 *   - The delete button uses the generic `.delete-btn` (standard BackendDataTable action).
 *   - After create, the spec captures the redirect URL to extract the demande ID
 *     for scoped row matching. Fallback: search by contact email text.
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/demandes
 *   create  GET  /admin/demandes/create
 *   view    GET  /admin/demandes/{id}
 *   edit    GET  /admin/demandes/{id}/edit
 *
 * Form fields (from form.blade.php):
 *   contact_id  — Select2 select (required — pick existing contact)
 *   status      — Select2 select (optional but listed)
 *   kind        — text input (optional)
 *   captured_at — datetime-local input (defaults to now() if omitted in beforeSave)
 *   notes       — textarea (optional)
 *
 * No `name` column on Demande — rows are located by contact email / company name text.
 */
export class DemandePage extends DataTablePage {
  // ── Form field locators ─────────────────────────────────────────────────

  /** Select2 wrapper for contact_id select. Parent div of the native <select>. */
  readonly contactIdSelectWrapper: Locator;
  /** Select2 wrapper for status select. */
  readonly statusSelectWrapper: Locator;
  readonly kindInput: Locator;
  readonly capturedAtInput: Locator;
  readonly notesTextarea: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'demande-table',        // Demande::getName() → 'demande' → 'demande-table'
      searchSelector: '#mySearchInput',
      addButtonText: 'Ajouter une demande',
    });

    // Scoped to #form_crud.
    this.contactIdSelectWrapper = page.locator('#form_crud select[name="contact_id"]').locator('..');
    this.statusSelectWrapper    = page.locator('#form_crud select[name="status"]').locator('..');
    this.kindInput              = page.locator('#form_crud input[name="kind"]');
    this.capturedAtInput        = page.locator('#form_crud input[name="captured_at"]');
    this.notesTextarea          = page.locator('#form_crud textarea[name="notes"]');
  }

  /** Navigate to the demandes index page. */
  async goto() {
    await this.page.goto('/admin/demandes');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/demandes/create');
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/demandes/${id}`);
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/demandes/${id}/edit`);
  }

  /**
   * Assert that the contact_id Select2 field is visible (create form fields present).
   * Used to verify the form rendered correctly before any mutation.
   */
  async expectCreateFormFieldsVisible() {
    // Contact Select2 must be attached and the captured_at datetime input present.
    await expect(
      this.page.locator('#form_crud select[name="contact_id"]')
    ).toBeAttached({ timeout: 10000 });
    await expect(this.capturedAtInput).toBeVisible({ timeout: 10000 });
  }

  /**
   * Count selectable contacts from the native contact_id <select>.
   * Returns the number of non-placeholder <option> elements (value != "").
   * Used to gate the create-mutation test.
   */
  async countSelectableContacts(): Promise<number> {
    const options = await this.page
      .locator('#form_crud select[name="contact_id"] option[value]:not([value=""])')
      .count();
    return options;
  }

  /**
   * Select a contact by its option text using the native <select> directly.
   * Select2 wraps contact_id — we set the native value and dispatch 'change'
   * so Select2 syncs its display label.
   *
   * @param contactOptionText  The full option text (e.g. "John Doe <john@example.com>").
   *   If exact text is unknown, use the index variant below.
   */
  async selectContactByIndex(optionIndex: number): Promise<string> {
    const select = this.page.locator('#form_crud select[name="contact_id"]');
    // Get all non-placeholder options.
    const options = select.locator('option[value]:not([value=""])');
    const count = await options.count();
    if (count === 0) {
      throw new Error('DemandePage: no selectable contacts in contact_id select');
    }
    const idx = Math.min(optionIndex, count - 1);
    const optText = await options.nth(idx).textContent() ?? '';
    const optValue = await options.nth(idx).getAttribute('value') ?? '';
    await select.selectOption({ value: optValue });
    await select.dispatchEvent('change');
    return optText.trim();
  }

  /**
   * Fill and submit the create form with the first available contact.
   * Returns the contact option text chosen (for row location).
   * Throws if no contacts are selectable.
   */
  async fillAndSubmitCreate(data: {
    capturedAt?: string; // ISO datetime-local string e.g. '2026-01-01T10:00'
    status?: string;
    notes?: string;
  } = {}): Promise<string> {
    const contactOptionText = await this.selectContactByIndex(0);

    // captured_at defaults to now() in beforeSave on create, but fill it explicitly.
    if (data.capturedAt) {
      await this.capturedAtInput.fill(data.capturedAt);
    }

    if (data.status) {
      const statusSelect = this.page.locator('#form_crud select[name="status"]');
      await statusSelect.selectOption({ value: data.status });
      await statusSelect.dispatchEvent('change');
    }

    if (data.notes) {
      await this.notesTextarea.fill(data.notes);
    }

    await this.page.locator('#form_crud button[name="save"]').click();

    return contactOptionText;
  }

  /**
   * Assert exactly `count` rows in the DataTable contain the given text.
   * Used to verify a created demande appears (by contact email or company name).
   */
  async expectRowCountByText(text: string, count: number) {
    await expect(
      this.table.locator(`tbody tr:has-text("${text}")`)
    ).toHaveCount(count);
  }
}
