import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * SequencePage — page object for the fretiq sequences module.
 *
 * Table ID: `sequence-table`
 *   SequencesDataTable → model Sequence → getName() → 'sequence' → 'sequence-table'
 *
 * Form field names (from form.blade.php):
 *   name (required, text)
 *   is_active   (checkbox switch, value="1")
 *
 *   No Select2 on the main create/edit form.
 *
 * Toggleable fields declared in SequenceController::$toggleableFields:
 *   is_active
 *   — toggled via PUT /sequences/executeSwitch/{id} (DataTable inline switch)
 *
 * Step-builder add form (on view AND edit pages, inside #sequence_steps pane):
 *   delay_days  (number input, required)
 *   template_id (plain HTML <select>, NOT Select2 — use selectOption + dispatchEvent)
 *   subject     (text input, optional)
 *   submit button: btn.btn-primary.btn-sm inside the add-step form
 *
 * Step rows: <table> inside #sequence_steps .card .table-responsive
 *   Edit: button[data-sequence-step-edit] opens #sequence_step_edit_modal.
 *   Delete: form[action*="/steps/"][method="post"] with @method('DELETE'),
 *           onsubmit="return confirm(...)" — native browser dialog (not SweetAlert).
 *
 * Enrollment rows (tracking table, same pane):
 *   Pause:  form[action*="/enrollments/"][action*="/pause"] → button.btn-light-warning
 *   Resume: form[action*="/enrollments/"][action*="/resume"] → button.btn-light-success
 *   Stop:   form[action*="/enrollments/"][action*="/stop"]  → button.btn-light-danger
 *   Status badge: span.badge inside the Statut column
 *
 * Routes (all relative to baseURL):
 *   index   GET   /admin/sequences
 *   create  GET   /admin/sequences/create
 *   store   POST  /admin/sequences
 *   view    GET   /admin/sequences/{id}
 *   edit    GET   /admin/sequences/{id}/edit
 *   update  PUT   /admin/sequences/{id}
 *   delete  DELETE /admin/sequences/{id}
 *   addStep     POST   /admin/sequences/{id}/steps
 *   updateStep  PUT    /admin/sequences/{id}/steps/{stepId}
 *   deleteStep  DELETE /admin/sequences/{id}/steps/{stepId}
 *   moveStepUp  POST   /admin/sequences/{id}/steps/{stepId}/move-up
 *   moveStepDown POST  /admin/sequences/{id}/steps/{stepId}/move-down
 *   pauseEnrollment  POST /admin/sequences/{id}/enrollments/{enrId}/pause
 *   resumeEnrollment POST /admin/sequences/{id}/enrollments/{enrId}/resume
 *   stopEnrollment   POST /admin/sequences/{id}/enrollments/{enrId}/stop
 *   executeSwitch PUT /admin/sequences/executeSwitch/{id}
 */
export class SequencePage extends DataTablePage {
  // ── Form field locators (create / edit) ──────────────────────────────────

  readonly nameInput: Locator;

  /** Checkbox switch for is_active — scoped to #form_crud */
  readonly isActiveCheckbox: Locator;

  // ── Step-builder add-form locators (inside #sequence_steps pane) ──────────

  /**
   * The plain HTML <select name="template_id"> in the add-step inline form.
   * NOT a Select2 — use .selectOption({ label }) + .dispatchEvent('change').
   */
  readonly addStepTemplateSelect: Locator;

  /** Delay days input in the add-step form */
  readonly addStepDelayInput: Locator;

  /** Subject input in the add-step form (optional) */
  readonly addStepSubjectInput: Locator;

  /** Submit button in the add-step form */
  readonly addStepSubmitButton: Locator;
  readonly stepDeleteButtons: Locator;
  readonly stepMoveUpButtons: Locator;
  readonly stepMoveDownButtons: Locator;
  readonly stepEditButtons: Locator;
  readonly editStepModal: Locator;
  readonly editStepDelayInput: Locator;
  readonly editStepTemplateSelect: Locator;
  readonly editStepSubjectInput: Locator;
  readonly editStepSubmitButton: Locator;
  readonly editStepError: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'sequence-table',
      searchSelector: '#mySearchInput',
      addButtonText: 'Ajouter',
    });

    // Main form fields — scoped to #form_crud
    this.nameInput          = page.locator('#form_crud input[name="name"]');
    this.isActiveCheckbox   = page.locator('#form_crud input[type="checkbox"][name="is_active"]');

    // Step-builder form — inside the #sequence_steps pane (view or edit page)
    // These are scoped to the add-step card-footer form to avoid matching step-row delete forms.
    const addStepForm = page.locator('#sequence_steps .card-footer form');
    this.addStepDelayInput    = addStepForm.locator('input[name="delay_days"]');
    this.addStepTemplateSelect = addStepForm.locator('select[name="template_id"]');
    this.addStepSubjectInput  = addStepForm.locator('input[name="subject"]');
    this.addStepSubmitButton  = addStepForm.locator('button[type="submit"]');
    this.stepDeleteButtons = page.locator("#sequence_steps button[aria-label=\"Supprimer l'étape\"]");
    this.stepMoveUpButtons = page.locator("#sequence_steps button[aria-label=\"Monter l'étape\"]");
    this.stepMoveDownButtons = page.locator("#sequence_steps button[aria-label=\"Descendre l'étape\"]");
    this.stepEditButtons = page.locator('#sequence_steps button[data-sequence-step-edit]');
    this.editStepModal = page.locator('#sequence_step_edit_modal');
    this.editStepDelayInput = this.editStepModal.locator('input[name="delay_days"]');
    this.editStepTemplateSelect = this.editStepModal.locator('select[name="template_id"]');
    this.editStepSubjectInput = this.editStepModal.locator('input[name="subject"]');
    this.editStepSubmitButton = this.editStepModal.locator('button[type="submit"]');
    this.editStepError = this.editStepModal.locator('#sequence_step_edit_error');
  }

  // ── Navigation ────────────────────────────────────────────────────────────

  /** Navigate to the sequences index page. */
  async goto() {
    await this.page.goto('/admin/sequences');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/sequences/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/sequences/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/sequences/${id}`);
  }

  // ── Form helpers ──────────────────────────────────────────────────────────

  /**
   * Fill and submit the create/edit form.
   * Only `name` is required server-side; checkboxes default to checked in the view.
   * Leave isActive undefined to keep whatever the form renders by default.
   */
  async fillAndSubmit(data: {
    name: string;
    isActive?: boolean;
  }) {
    await this.nameInput.fill(data.name);

    if (data.isActive !== undefined) {
      const checked = await this.isActiveCheckbox.isChecked();
      if (checked !== data.isActive) {
        await this.isActiveCheckbox.click();
      }
    }

    await this.page.locator('#form_crud button[name="save"]').click();
  }

  // ── DataTable row helpers ─────────────────────────────────────────────────

  /**
   * Assert exactly `count` DataTable rows contain `name` in their text.
   */
  async expectRowCountByName(name: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(count);
  }

  /**
   * Click the is_active toggle switch on a DataTable row.
   * Targets the switch input by its data-field attribute (stable, field-specific).
   * The status.blade.php component emits data-field="{{ $name }}" on every switch input.
   */
  async clickActiveSwitchOnRow(rowIndex: number) {
    const row = this.table.locator('tbody tr').nth(rowIndex);
    await row.locator('input[type="checkbox"][data-field="is_active"]').click();
  }

  /**
   * Loop-delete all rows matching `name` using the SweetAlert-based delete flow.
   * Handles the case where multiple rows were accidentally created.
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
      await expect(this.page.locator('.swal2-popup')).toContainText('supprimée', { timeout: 10000 });
      await this.page.locator('.swal2-confirm').click();
      await this.page.waitForLoadState('networkidle');
      await helpers.waitForDataTable(this.page, this.tableId);
    }
  }

  // ── Steps pane helpers ────────────────────────────────────────────────────

  /**
   * Click the "Étapes" tab to bring the #sequence_steps pane into view.
   * Works on both the view page (native tab) and edit page (moved by crud-tabs.js).
   * The tab link text is "Étapes".
   */
  async clickStepsTab() {
    // The tab nav link references href="#sequence_steps"
    const stepsTab = this.page.locator('a[href="#sequence_steps"]');
    await stepsTab.click();
    // Wait for the pane to become visible
    await this.page.locator('#sequence_steps').waitFor({ state: 'visible', timeout: 10000 });
  }

  /**
   * Fill and submit the add-step inline form.
   * template_id is a plain <select> — uses selectOption (NOT Select2 clicks).
   */
  async addStep(data: {
    templateOptionLabel: string;
    delayDays?: number;
    subject?: string;
  }) {
    await this.addStepDelayInput.fill(String(data.delayDays ?? 1));

    // Plain <select> — no Select2 overlay. selectOption + dispatchEvent per convention.
    await this.addStepTemplateSelect.selectOption({ label: data.templateOptionLabel });
    await this.addStepTemplateSelect.dispatchEvent('change');

    if (data.subject) {
      await this.addStepSubjectInput.fill(data.subject);
    }

    await this.addStepSubmitButton.click();
  }

  /** Open the editor for a step row and wait until its values are visible. */
  async openStepEditor(rowIndex = 0) {
    await this.stepsTableRows().nth(rowIndex).locator('[data-sequence-step-edit]').click();
    await this.editStepModal.waitFor({ state: 'visible', timeout: 10000 });
  }

  /** Fill and submit the shared step editor modal. */
  async submitStepEdit(data: {
    delayDays: number;
    templateOptionLabel?: string;
    subject?: string;
  }) {
    await this.editStepDelayInput.fill(String(data.delayDays));
    if (data.templateOptionLabel !== undefined) {
      await this.editStepTemplateSelect.selectOption({ label: data.templateOptionLabel });
    }
    if (data.subject !== undefined) {
      await this.editStepSubjectInput.fill(data.subject);
    }
    await this.editStepSubmitButton.click();
  }

  /**
   * Get all step rows from the steps table inside #sequence_steps.
   * Returns a Locator for tbody tr elements of the steps table (first table in the pane).
   */
  stepsTableRows(): Locator {
    return this.page.locator('#sequence_steps .table-responsive table tbody tr');
  }

  /**
   * Delete the first step row in the steps table.
   * Uses the native browser confirm dialog (onsubmit="return confirm(...)").
   * Caller must handle page.on('dialog') to auto-accept before calling this.
   */
  async deleteFirstStep() {
    const deleteBtn = this.page
      .locator('#sequence_steps .table-responsive table tbody tr')
      .first()
      .locator('button.btn-icon.btn-light-danger, button.btn-light-danger');
    await deleteBtn.click();
  }

  // ── Enrollment control helpers ────────────────────────────────────────────

  /**
   * Get all enrollment rows from the contact-tracking table.
   * The enrollment table is the second .card inside #sequence_steps.
   */
  enrollmentTableRows(): Locator {
    return this.page.locator(
      '#sequence_steps .card:last-of-type .table-responsive table tbody tr'
    );
  }

  /**
   * Click the Pause button on the first enrollment row (only visible when status=active).
   */
  async pauseFirstEnrollment() {
    const pauseBtn = this.page.locator(
      '#sequence_steps form[action*="/pause"] button[title="Mettre en pause"]'
    ).first();
    await pauseBtn.click();
  }

  /**
   * Click the Resume button on the first enrollment row (only visible when status=paused).
   */
  async resumeFirstEnrollment() {
    const resumeBtn = this.page.locator(
      '#sequence_steps form[action*="/resume"] button[title="Reprendre"]'
    ).first();
    await resumeBtn.click();
  }

  /**
   * Get the status badge text of the first enrollment row.
   * Badge is a <span class="badge badge-light-{color}"> in the Statut column (3rd td).
   */
  async getFirstEnrollmentStatusBadgeText(): Promise<string> {
    const statusCell = this.page
      .locator('#sequence_steps .card:last-of-type .table-responsive table tbody tr')
      .first()
      .locator('td')
      .nth(2);
    return (await statusCell.locator('.badge').textContent() ?? '').trim();
  }
}
