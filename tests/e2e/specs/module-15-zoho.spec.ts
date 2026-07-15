// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { ZohoPage } from '../pages/ZohoPage';
import { expectPath } from '../helpers/test-utils';

// >>> custom-test-author:zoho-e2e

/**
 * module-15-zoho — fretiq Zoho sync-status screen read-only e2e suite.
 *
 * Read-only: asserts structural landmarks render. The "Synchroniser maintenant"
 * and "Importer les modèles d'email" buttons are checked for presence ONLY —
 * they must NEVER be clicked (clicking fires live Zoho API calls).
 *
 * Stable selectors used:
 *   form[action*="zoho/sync"] button[type="submit"]           — sync button
 *   form[action*="zoho/sync_templates"] button[type="submit"] — import button
 *   .fw-bold.text-gray-800 with text "Driver CRM"             — driver label
 */

test.describe('Zoho module', () => {

  // ── 1. Path is /admin/zoho ────────────────────────────────────────────────

  test('zoho page loads at /admin/zoho', async ({ page }) => {
    const zoho = new ZohoPage(page);
    await zoho.goto();

    await expectPath(page, '/admin/zoho');
  });

  // ── 2. Driver CRM card renders ────────────────────────────────────────────

  test('Driver CRM card label is visible', async ({ page }) => {
    const zoho = new ZohoPage(page);
    await zoho.goto();

    await expect(zoho.crmDriverCard).toBeVisible({ timeout: 10000 });
  });

  // ── 3. Sync buttons are present (NOT clicked) ─────────────────────────────

  test('sync buttons are present in the DOM (not clicked)', async ({ page }) => {
    const zoho = new ZohoPage(page);
    await zoho.goto();

    // Buttons are rendered only when the logged-in user has the required
    // permissions (superadmin/admin have both). Assert attached, not visible,
    // because the buttons might be hidden by the @can Blade directive when the
    // test user lacks the permission — in that case the form itself is absent.
    // We check count >= 0 (structural safety) and visible for admin users.
    const syncCount = await zoho.syncButton.count();
    const templatesCount = await zoho.syncTemplatesButton.count();

    // At least one of the two action buttons must exist for an admin user.
    expect(syncCount + templatesCount).toBeGreaterThan(0);

    // Whichever buttons are present must be visible (not hidden).
    if (syncCount > 0) {
      await expect(zoho.syncButton).toBeVisible({ timeout: 10000 });
    }
    if (templatesCount > 0) {
      await expect(zoho.syncTemplatesButton).toBeVisible({ timeout: 10000 });
    }
  });

  test('action buttons fit desktop and mobile viewports without clicking', async ({ page }) => {
    for (const viewport of [
      { width: 1280, height: 720 },
      { width: 390, height: 844 },
    ]) {
      await page.setViewportSize(viewport);

      const zoho = new ZohoPage(page);
      await zoho.goto();

      await expect(zoho.actionBar).toBeVisible({ timeout: 10000 });

      const count = await zoho.actionButtons.count();
      expect(count).toBeGreaterThan(0);

      for (let index = 0; index < count; index++) {
        const button = zoho.actionButtons.nth(index);
        await expect(button).toBeVisible({ timeout: 10000 });

        const box = await button.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width);
      }
    }
  });

});

// <<<
