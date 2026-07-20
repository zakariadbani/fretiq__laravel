// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { SequencePage } from '../pages/SequencePage';
import {
  waitForDataTable,
  confirmDelete,
  expectPath,
  uniqueName,
} from '../helpers/test-utils';

// >>> custom-test-author:sequences-e2e

/**
 * module-6-sequences — fretiq sequences CRUD e2e suite.
 *
 * Runs against the live dev site with the pre-authenticated admin storageState.
 *
 * Contract:
 *   - All async widget interactions use ONLY the declared test-utils helpers.
 *   - No waitForTimeout, no raw inline selectors outside the page object.
 *   - No hardcoded host — all navigation uses relative paths via SequencePage.
 *   - Mutating tests use uniqueName() so rows are distinct across repeated runs.
 *   - The main CRUD form has NO Select2 — name + checkbox switches only.
 *   - The step-builder add form uses a plain <select name="template_id"> (NOT Select2).
 *     Interaction: selectOption({ label }) + dispatchEvent('change').
 *   - Delete from DataTable uses the two-SweetAlert flow (AJAX DELETE + success dialog).
 *   - Delete from the step table uses the native browser confirm dialog (form onsubmit).
 *   - The E2E_FIXTURE Sequence (with one step) and E2E_FIXTURE Template are pre-seeded
 *     by `php artisan fretiq:e2e-seed` and are used for read-only reference.
 *     Mutation tests create their own rows and clean up in afterAll.
 *   - Toggleable fields: is_active (first switch per row), stop_on_reply (second switch).
 *   - Enrollment pause/resume test requires an enrollment to exist on the sequence.
 *     Since the e2e-seed does NOT create enrollments, this test uses the fixture sequence
 *     and skips gracefully if no enrollments are found rather than failing.
 *
 * Cleanup: all sequences created by this suite are tracked and deleted in afterAll.
 */

/** Names of sequences created during this suite; cleaned up in afterAll. */
const createdSequenceNames: string[] = [];

test.describe('Sequences module', () => {

  test.afterAll(async ({ browser }) => {
    if (createdSequenceNames.length === 0) return;
    const ctx = await browser.newContext({
      storageState: './tests/e2e/.auth/admin.json',
    });
    const page = await ctx.newPage();
    const sequences = new SequencePage(page);

    for (const name of createdSequenceNames.splice(0)) {
      try {
        await sequences.goto();
        await waitForDataTable(page, 'sequence-table');
        await sequences.search(name);
        await waitForDataTable(page, 'sequence-table');
        const remaining = await sequences.table.locator(`tbody tr:has-text("${name}")`).count();
        if (remaining === 0) continue;
        await sequences.deleteAllByName(name, {
          search: (q) => sequences.search(q),
          waitForDataTable,
          confirmDelete,
        });
      } catch {
        // best-effort teardown — never fail the suite on cleanup
      }
    }

    await ctx.close();
  });

  // ── 1. List page — DataTable renders ──────────────────────────────────────

  test('list page loads and DataTable renders', async ({ page }) => {
    const sequences = new SequencePage(page);
    await sequences.goto();

    await expectPath(page, '/admin/sequences');
    await waitForDataTable(page, 'sequence-table');
    await sequences.expectTableVisible();
  });

  // ── 2. Create flow ─────────────────────────────────────────────────────────

  test('create: fill form, submit, redirect, row appears in table, cleanup', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E Séquence');
    createdSequenceNames.push(name);

    await sequences.gotoCreate();

    // Fill the minimal required field (name). Checkboxes keep their default (checked).
    await sequences.fillAndSubmit({ name });

    // crud-form-handler.js follows the 2xx redirect away from /create.
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and confirm the row appears in the DataTable.
    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');

    // At least 1 matching row must be present.
    const rows = sequences.table.locator(`tbody tr:has-text("${name}")`);
    expect(await rows.count()).toBeGreaterThanOrEqual(1);

    // Cleanup: remove all rows matching the name.
    await sequences.deleteAllByName(name, {
      search: (q) => sequences.search(q),
      waitForDataTable,
      confirmDelete,
    });

    // Verify deletion.
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');
    await expect(sequences.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);

    // Row cleaned — remove from global tracker.
    const idx = createdSequenceNames.indexOf(name);
    if (idx !== -1) createdSequenceNames.splice(idx, 1);
  });

  // ── 3. Edit flow ───────────────────────────────────────────────────────────

  test('edit: change name, save, redirect away from /edit', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E Edit Séquence');
    createdSequenceNames.push(name);

    // Create a sequence to edit.
    await sequences.gotoCreate();
    await sequences.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Find the row and open edit.
    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');
    await sequences.clickRowAction(0, 'edit');

    // Change the name and save.
    const editedName = name + '-edited';
    await expect(page.locator('#form_crud input[name="name"]')).toBeVisible({ timeout: 10000 });
    await page.locator('#form_crud input[name="name"]').fill(editedName);
    await page.locator('#form_crud button[name="save"]').click();

    // Wait for redirect away from /edit.
    await page.waitForURL((u) => !u.pathname.endsWith('/edit'), { timeout: 15000 });

    // Track the edited name for afterAll cleanup (the original name no longer exists).
    const origIdx = createdSequenceNames.indexOf(name);
    if (origIdx !== -1) {
      createdSequenceNames[origIdx] = editedName;
    } else {
      createdSequenceNames.push(editedName);
    }
  });

  // ── 4. View (detail) page ──────────────────────────────────────────────────

  test('view: click view action, detail page shows sequence name', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E View Séquence');
    createdSequenceNames.push(name);

    // Create a sequence.
    await sequences.gotoCreate();
    await sequences.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index, search, open view.
    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');
    await sequences.clickRowAction(0, 'view');

    // Detail page must show the sequence name.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });
  });

  // ── 5. Delete confirm and row removal ─────────────────────────────────────

  test('delete: confirm dialog, row removed from table', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E Delete Séquence');
    // Note: we push here but will clean up inline — afterAll also guards
    createdSequenceNames.push(name);

    // Create a sequence to delete.
    await sequences.gotoCreate();
    await sequences.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and find the row.
    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');

    // Click delete → first SweetAlert confirm.
    await sequences.clickRowAction(0, 'delete');
    await confirmDelete(page);

    // Second SweetAlert: success dialog after DELETE.
    await expect(page.locator('.swal2-popup')).toContainText('supprimée', { timeout: 10000 });
    await page.locator('.swal2-confirm').click();

    await page.waitForLoadState('networkidle');
    await waitForDataTable(page, 'sequence-table');

    // Verify row is gone.
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');
    await expect(sequences.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(0);

    // Already deleted — remove from global tracker.
    const idx = createdSequenceNames.indexOf(name);
    if (idx !== -1) createdSequenceNames.splice(idx, 1);
  });

  // ── 6. executeSwitch — is_active toggle ───────────────────────────────────

  test('executeSwitch is_active: toggle switch, DataTable row remains visible', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E Toggle Active');
    createdSequenceNames.push(name);

    // Create a sequence (is_active defaults to checked/true via the form).
    await sequences.gotoCreate();
    await sequences.fillAndSubmit({ name, isActive: true });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // Navigate to index and locate the row.
    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');

    // Click the is_active switch (first checkbox per row) — fires PUT AJAX.
    await sequences.clickActiveSwitchOnRow(0);

    // Wait for AJAX to settle (no success toast for switch toggles — just network idle).
    await page.waitForLoadState('networkidle');

    // The row must still be present after the toggle.
    await sequences.expectMinRows(1);
  });

  // ── 7. executeSwitch — stop_on_reply toggle ───────────────────────────────

  test('executeSwitch stop_on_reply: toggle switch, DataTable row remains visible', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E Toggle StopOnReply');
    createdSequenceNames.push(name);

    // Create a sequence with stop_on_reply enabled.
    await sequences.gotoCreate();
    await sequences.fillAndSubmit({ name, stopOnReply: true });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');

    // Click the stop_on_reply switch (second checkbox per row).
    await sequences.clickStopOnReplySwitchOnRow(0);
    await page.waitForLoadState('networkidle');

    // Row must still be present after toggle.
    await sequences.expectMinRows(1);
  });

  // ── 8. Step management: addStep + deleteStep ──────────────────────────────

  test('addStep: navigate to view, open steps tab, add step via plain select, step row appears; deleteStep: delete step, row gone', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E Step Séquence');
    createdSequenceNames.push(name);

    // ── 8a. Create a sequence ──────────────────────────────────────────────
    await sequences.gotoCreate();
    await sequences.fillAndSubmit({ name });
    await page.waitForURL((u) => !u.pathname.endsWith('/create'), { timeout: 15000 });

    // ── 8b. Navigate to the view page ────────────────────────────────────
    // After create redirect, we may be on the view or index. Navigate to index to find the id.
    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');

    // Open the view page for this sequence.
    await sequences.clickRowAction(0, 'view');
    await page.waitForLoadState('networkidle');

    // Confirm we are on the view page.
    await expect(page.locator(`text=${name}`).first()).toBeVisible({ timeout: 10000 });

    // ── 8c. Open the Étapes tab ───────────────────────────────────────────
    await sequences.clickStepsTab();

    // ── 8d. Check the template select has options (needs E2E_FIXTURE Template) ──
    // The add-step form requires at least one CampaignTemplate to exist.
    // If the select has no options beyond the placeholder, the button is disabled.
    const templateSelectLocator = sequences.addStepTemplateSelect;
    await templateSelectLocator.waitFor({ state: 'visible', timeout: 10000 });

    const optionCount = await templateSelectLocator.locator('option').count();
    if (optionCount <= 1) {
      // No templates available — step form is disabled; skip this sub-test.
      // This can happen if fretiq:e2e-seed has not been run before the suite.
      test.info().annotations.push({
        type: 'skip-reason',
        description: 'No CampaignTemplate options available in add-step select; run fretiq:e2e-seed first.',
      });
      return;
    }

    // Count existing step rows before adding.
    const stepsBefore = await sequences.stepsTableRows().count();

    // ── 8e. Add a step using the plain <select> (NOT Select2) ────────────
    // Use the "E2E_FIXTURE Template" seeded by fretiq:e2e-seed, or fall back to
    // the first available option label if the fixture template is absent.
    const fixtureOptionLocator = templateSelectLocator.locator('option', { hasText: 'E2E_FIXTURE Template' });
    const fixtureCount = await fixtureOptionLocator.count();
    const templateLabel = fixtureCount > 0
      ? 'E2E_FIXTURE Template'
      : (await templateSelectLocator.locator('option:not([value=""])').first().textContent() ?? '').trim();

    // Accept the native browser dialog from onsubmit="return confirm(...)" during delete.
    // Register with page.once so the handler auto-removes after the first dialog fires
    // and cannot leak into later tests or interactions.
    // (No dialog fires on addStep; registering early is a no-op for this step.)
    page.once('dialog', (dialog) => dialog.accept());

    await sequences.addStep({
      templateOptionLabel: templateLabel,
      delayDays: 2,
      subject: 'E2E step subject',
    });

    // addStep POSTs and server redirects back (withFragment). Wait for navigation.
    await page.waitForURL((u) => u.pathname.includes('/sequences/'), { timeout: 15000 });
    await page.waitForLoadState('networkidle');

    // Re-open the steps tab (page reloaded).
    await sequences.clickStepsTab();

    // ── 8f. Assert the new step row is present ────────────────────────────
    const stepsAfter = await sequences.stepsTableRows().count();
    expect(stepsAfter).toBeGreaterThan(stepsBefore);

    // ── 8g. deleteStep: delete the step row just added ────────────────────
    // The delete form uses onsubmit="return confirm(...)" — native dialog.
    // The page.on('dialog', accept) handler registered above covers this.

    // Find the step row containing "E2E step subject" (or the last row if no subject shown).
    const allRows = sequences.stepsTableRows();
    const rowCount = await allRows.count();
    expect(rowCount).toBeGreaterThan(0);

    // Click delete on the last step row (the one we just added is appended at the end).
    const lastRow = allRows.nth(rowCount - 1);
    const deleteBtn = lastRow.locator('button.btn-icon.btn-light-danger, button.btn-light-danger');
    await deleteBtn.click();

    // Server redirects back after delete.
    await page.waitForURL((u) => u.pathname.includes('/sequences/'), { timeout: 15000 });
    await page.waitForLoadState('networkidle');

    // Re-open the steps tab.
    await sequences.clickStepsTab();

    // Step count must be back to what it was before addStep.
    const stepsAfterDelete = await sequences.stepsTableRows().count();
    expect(stepsAfterDelete).toBe(stepsBefore);
  });

  test('editStep: edit-page modal is prefilled, recovers from 422, saves, and preserves the steps hash', async ({ page }) => {
    const sequences = new SequencePage(page);
    const name = uniqueName('E2E Edit Step Séquence');
    createdSequenceNames.push(name);

    await sequences.gotoCreate();
    await sequences.fillAndSubmit({ name });
    await page.waitForURL((url) => !url.pathname.endsWith('/create'), { timeout: 15000 });

    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search(name);
    await waitForDataTable(page, 'sequence-table');
    await sequences.clickRowAction(0, 'view');
    await page.waitForLoadState('networkidle');

    const sequenceId = page.url().match(/\/admin\/sequences\/(\d+)/)?.[1];
    expect(sequenceId).toBeTruthy();
    await sequences.clickStepsTab();

    const templateSelect = sequences.addStepTemplateSelect;
    await templateSelect.waitFor({ state: 'visible', timeout: 10000 });
    const firstTemplate = templateSelect.locator('option:not([value=""])').first();
    if (await firstTemplate.count() === 0) {
      test.skip(true, 'No CampaignTemplate is available; run fretiq:e2e-seed first.');
      return;
    }

    const templateLabel = (await firstTemplate.textContent() ?? '').trim();
    await sequences.addStep({
      templateOptionLabel: templateLabel,
      delayDays: 2,
      subject: 'Sujet avant modification',
    });
    await page.waitForLoadState('networkidle');

    await sequences.gotoEdit(sequenceId!);
    await page.waitForLoadState('networkidle');
    await sequences.clickStepsTab();
    await sequences.openStepEditor(0);

    await expect(sequences.editStepDelayInput).toHaveValue('2');
    await expect(sequences.editStepTemplateSelect.locator('option:checked')).toHaveText(templateLabel);
    await expect(sequences.editStepSubjectInput).toHaveValue('Sujet avant modification');

    // Remove the browser's min constraint so this submission reaches Laravel's
    // validation endpoint and verifies the modal's recoverable 422 state.
    await sequences.editStepDelayInput.evaluate((input) => input.removeAttribute('min'));
    await sequences.submitStepEdit({ delayDays: -1, subject: 'Sujet invalide' });
    await expect(sequences.editStepModal).toBeVisible();
    await expect(sequences.editStepError).toContainText('Veuillez corriger');
    await expect(sequences.editStepDelayInput).toHaveClass(/is-invalid/);
    await expect(sequences.editStepDelayInput).toBeFocused();
    await expect(sequences.editStepSubmitButton).toBeEnabled();

    const reloadAfterSave = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 });
    await sequences.submitStepEdit({ delayDays: 5, subject: 'Sujet après modification' });
    await reloadAfterSave;
    expect(page.url()).toContain(`/admin/sequences/${sequenceId}/edit#sequence_steps`);
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#sequence_steps')).toBeVisible();
    await expect(sequences.stepsTableRows().first()).toContainText('Sujet après modification');
    await expect(sequences.stepsTableRows().first()).toContainText('+5 j');
  });

  test('steps: icon-only actions expose French accessible names and tooltips', async ({ page }) => {
    const sequences = new SequencePage(page);

    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search('E2E_FIXTURE Sequence');
    await waitForDataTable(page, 'sequence-table');

    const fixtureRows = sequences.table.locator('tbody tr:has-text("E2E_FIXTURE Sequence")');
    if ((await fixtureRows.count()) === 0) {
      test.skip(true, 'E2E_FIXTURE Sequence not found. Run fretiq:e2e-seed before the suite.');
      return;
    }

    await sequences.clickRowAction(0, 'view');
    await page.waitForLoadState('networkidle');
    await sequences.clickStepsTab();

    if ((await sequences.stepDeleteButtons.count()) === 0) {
      test.skip(true, 'Fixture sequence has no rendered step action buttons.');
      return;
    }

    const deleteButton = sequences.stepDeleteButtons.first();
    await expect(deleteButton).toHaveAttribute('aria-label', "Supprimer l'étape");
    const deleteTooltip = await deleteButton.getAttribute('data-bs-title') ?? await deleteButton.getAttribute('data-bs-original-title');
    expect(deleteTooltip).toBe("Supprimer l'étape");

    const unnamedButtons = await page.locator('#sequence_steps button.btn-icon, #sequence_steps button:has(.bi-arrow-up), #sequence_steps button:has(.bi-arrow-down)').evaluateAll((buttons) =>
      buttons.filter((button) => {
        const tooltip = button.getAttribute('data-bs-title') || button.getAttribute('data-bs-original-title');
        return !button.getAttribute('aria-label') || !tooltip;
      }).length
    );
    expect(unnamedButtons).toBe(0);
  });

  // ── 9. Enrollment control: pauseEnrollment / resumeEnrollment ─────────────

  test('pauseEnrollment / resumeEnrollment: toggle status badge via inline form buttons', async ({ page }) => {
    const sequences = new SequencePage(page);

    // Use the pre-seeded E2E_FIXTURE Sequence for this test (read-only reference).
    // The fixture sequence is guaranteed to exist after fretiq:e2e-seed.
    // However, the seed does NOT create enrollments — this test checks for an
    // existing active enrollment and skips gracefully if none is found.
    // To exercise this path in CI, seed an enrollment separately or create one manually.

    await sequences.goto();
    await waitForDataTable(page, 'sequence-table');
    await sequences.search('E2E_FIXTURE Sequence');
    await waitForDataTable(page, 'sequence-table');

    const fixtureRows = sequences.table.locator('tbody tr:has-text("E2E_FIXTURE Sequence")');
    const fixtureCount = await fixtureRows.count();
    if (fixtureCount === 0) {
      // E2E_FIXTURE Sequence not found — seed not run; skip.
      test.info().annotations.push({
        type: 'skip-reason',
        description: 'E2E_FIXTURE Sequence not found. Run fretiq:e2e-seed before the suite.',
      });
      return;
    }

    // Open the fixture sequence's view page.
    await sequences.clickRowAction(0, 'view');
    await page.waitForLoadState('networkidle');

    // Open the Étapes tab (which also contains the enrollment tracking table).
    await sequences.clickStepsTab();

    // Check if any enrollment rows exist.
    const enrollmentRows = sequences.enrollmentTableRows();
    const enrollmentCount = await enrollmentRows.count();

    // ── 9a. Assert enrollment management controls are present in the DOM ──
    // The enrollment panel (#sequence_steps) must render even when no enrollments
    // exist — the table container and action-column headers should always be present.
    // This verifies the view renders the enrollment management UI unconditionally.
    const enrollmentPanel = page.locator('#sequence_steps');
    await expect(enrollmentPanel).toBeVisible({ timeout: 10000 });

    if (enrollmentCount === 0) {
      // No enrollments found after seeding — the seed only creates the sequence +
      // steps, not active enrollments. The panel presence check above gives real
      // coverage. Skip the interactive pause→resume toggle (requires an active row).
      test.skip(true, 'No enrollments on E2E_FIXTURE Sequence — seed an active enrollment to exercise pause/resume toggle.');
      return;
    }

    // ── 9b. Pause the first active enrollment ─────────────────────────────
    // Check if a Pause button is present (only rendered when status=active).
    const pauseBtn = page.locator(
      '#sequence_steps form[action*="/pause"] button[title="Mettre en pause"]'
    ).first();
    const pauseBtnCount = await pauseBtn.count();

    if (pauseBtnCount === 0) {
      // First enrollment is not in 'active' state — nothing to pause.
      // Panel presence was already asserted; skip the toggle branch.
      test.skip(true, 'First enrollment is not in active state — set enrollment status to active to exercise pause/resume.');
      return;
    }

    // Click Pause.
    await pauseBtn.click();
    await page.waitForURL((u) => u.pathname.includes('/sequences/'), { timeout: 15000 });
    await page.waitForLoadState('networkidle');

    // Re-open the steps tab.
    await sequences.clickStepsTab();

    // Assert: status badge on the first enrollment row now reflects 'paused' state.
    // The badge text comes from config('global.data.sequence_enrollment_statuses').
    // We verify the Resume button is now visible (status=paused renders Resume).
    const resumeBtn = page.locator(
      '#sequence_steps form[action*="/resume"] button[title="Reprendre"]'
    ).first();
    await expect(resumeBtn).toBeVisible({ timeout: 10000 });

    // ── 9c. Resume the paused enrollment ──────────────────────────────────
    await resumeBtn.click();
    await page.waitForURL((u) => u.pathname.includes('/sequences/'), { timeout: 15000 });
    await page.waitForLoadState('networkidle');

    // Re-open the steps tab.
    await sequences.clickStepsTab();

    // Assert: Pause button is back (status=active again).
    const pauseBtnAfterResume = page.locator(
      '#sequence_steps form[action*="/pause"] button[title="Mettre en pause"]'
    ).first();
    await expect(pauseBtnAfterResume).toBeVisible({ timeout: 10000 });
  });

});

// <<<
