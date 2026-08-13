// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { ZohoPage } from '../pages/ZohoPage';
import { expectPath } from '../helpers/test-utils';

/**
 * Read-only contract for GET /admin/zoho. Never click or submit sync and
 * maintenance controls: those POST forms enqueue real work.
 */
test.describe('Zoho synchronization module', () => {
  test('loads the focused synchronization screen at /admin/zoho', async ({ page }) => {
    const zoho = new ZohoPage(page);
    await zoho.goto();

    await expectPath(page, '/admin/zoho');
    await expect(page).toHaveTitle(/Synchronisation Zoho/);
  });

  test('renders only synchronization controls and not operational diagnostics', async ({ page }) => {
    const zoho = new ZohoPage(page);
    await zoho.goto();

    await expect(zoho.syncCenter).toBeVisible();
    await expect(zoho.syncAllButton).toBeVisible();
    await expect(zoho.moduleGrid).toBeVisible();
    expect(await zoho.moduleCards.count()).toBeGreaterThan(0);
    expect(await zoho.moduleButtons.count()).toBeGreaterThan(0);
    await expect(zoho.maintenanceControls).toBeVisible();

    for (const heading of [
      'Lecture seule',
      'Santé globale',
      'OAuth CRM',
      'File Zoho',
      'Modules, fraîcheur et qualité',
      'Schémas vérifiés',
      'Fiabilité récente',
      'Anomalies redigées et corrélation',
      'Correspondances utilisateurs',
    ]) {
      await expect(page.getByText(heading, { exact: true })).toHaveCount(0);
    }
  });

  test('fits desktop and mobile viewports without document horizontal overflow', async ({ page }) => {
    for (const viewport of [
      { width: 1440, height: 900 },
      { width: 390, height: 844 },
    ]) {
      await page.setViewportSize(viewport);

      const zoho = new ZohoPage(page);
      await zoho.goto();

      await expect(zoho.syncCenter).toBeVisible();
      await expect(zoho.moduleGrid).toBeVisible();
      await expect(zoho.maintenanceControls).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewport.width);
    }
  });
});
