// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror).
import { test, expect } from '../fixtures/console-guard';

test.describe('Prospect batch create', () => {
  test('shows the paste and CSV inputs on desktop and mobile', async ({ page }) => {
    await page.goto('/admin/prospect_batches/create');

    await expect(page).toHaveURL(/\/admin\/prospect_batches\/create$/);
    await expect(page.getByRole('heading', { name: 'Importer des entreprises' })).toBeVisible();

    await expect(page.locator('#companies_text')).toBeVisible();
    await expect(page.locator('#companies_csv')).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });

    await expect(page.locator('#companies_text')).toBeVisible();
    await expect(page.locator('#companies_csv')).toBeVisible();
  });
});
