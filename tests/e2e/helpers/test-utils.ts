import { Page, expect } from '@playwright/test';

/**
 * Wait for a SweetAlert2 success toast and dismiss it.
 * Matches the Metronic/SweetAlert2 stack used across all fretiq CRUD modules.
 */
export async function expectAndDismissSuccess(page: Page) {
  const swal = page.locator('.swal2-popup');
  await expect(swal).toBeVisible({ timeout: 5000 });
  const okButton = swal.locator('button.swal2-confirm');
  if (await okButton.isVisible()) {
    await okButton.click();
  }
}

/**
 * Confirm a SweetAlert2 delete dialog.
 */
export async function confirmDelete(page: Page) {
  const swal = page.locator('.swal2-popup');
  await expect(swal).toBeVisible({ timeout: 5000 });
  await swal.locator('button.swal2-confirm').click();
}

/**
 * Wait for a yajra DataTable to finish loading (AJAX).
 *
 * Strategy: DataTables toggles `#${tableId}_processing` display:block/none during AJAX fetch.
 * We wait for that element to become hidden (or absent — treat missing as already settled),
 * then wait for network idle so in-flight XHRs have fully resolved.
 */
export async function waitForDataTable(page: Page, tableId: string) {
  const processingSelector = `#${tableId}_processing`;

  // If the processing indicator exists, wait for it to be hidden.
  // If it doesn't exist (DataTables not yet initialised or already gone), resolve immediately.
  const processingEl = page.locator(processingSelector);
  const processingCount = await processingEl.count();
  if (processingCount > 0) {
    await expect(processingEl).toBeHidden({ timeout: 10000 });
  }

  await page.waitForLoadState('networkidle');
}

/**
 * Assert the current URL path matches exactly.
 */
export async function expectPath(page: Page, path: string) {
  expect(new URL(page.url()).pathname).toBe(path);
}

/**
 * Generate a unique test name to avoid collisions across parallel/repeated runs.
 */
export function uniqueName(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
}

/**
 * Open a Select2 dropdown, type to search, and click the matching option.
 * Falls back to setting the underlying native <select> value + dispatching
 * input/change events if the Select2 overlay is not found.
 *
 * fretiq uses Select2 4.1.0-rc.0 (same as rapidlead_v2).
 */
export async function selectSelect2(
  page: Page,
  containerSelector: string,
  optionText: string
): Promise<void> {
  const container = page.locator(containerSelector);

  // Try to open the Select2 overlay
  const select2Selection = container.locator('.select2-selection');
  const isSelect2 = await select2Selection.count() > 0;

  if (isSelect2) {
    await select2Selection.click();

    // Wait for the results dropdown to appear
    const results = page.locator('.select2-results');
    await expect(results).toBeVisible({ timeout: 5000 });

    // Type into search field if present
    const searchField = page.locator('.select2-search__field');
    if (await searchField.count() > 0) {
      await searchField.fill(optionText);
    }

    // Click the matching option
    const option = page.locator('.select2-results__option', { hasText: optionText });
    await expect(option.first()).toBeVisible({ timeout: 5000 });
    await option.first().click();
  } else {
    // Fallback: set the native <select> value directly
    const select = container.locator('select');
    await select.waitFor({ state: 'attached' });
    await select.selectOption({ label: optionText });
    await select.dispatchEvent('input');
    await select.dispatchEvent('change');
  }
}

// NOTE: waitForLivewire is intentionally ABSENT.
// fretiq backend UI is Controller+Blade+yajra-DataTables+jQuery.
// There are no [wire:loading] elements on backend pages.
// Do not add it — any occurrence in a spec is a regression.
