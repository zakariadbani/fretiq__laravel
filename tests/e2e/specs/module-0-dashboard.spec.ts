// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror).
import { test, expect } from '../fixtures/console-guard';
import { DashboardPage } from '../pages/DashboardPage';
import { expectPath } from '../helpers/test-utils';

// >>> custom-test-author:dashboard-e2e

test.describe('Dashboard module', () => {
  test('dashboard page loads at /admin/dashboard', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    await expectPath(page, '/admin/dashboard');
    await expect(dashboard.shell).toHaveAttribute('data-prototype', '1');
  });

  test('prototype navigation and KPI summary render', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    await expect(dashboard.switcher).toBeVisible({ timeout: 10000 });
    await expect(dashboard.kpiRow).toBeVisible({ timeout: 10000 });
  });

  test('campaign, planning, and discovery sections render', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    await expect(dashboard.campaigns).toBeVisible({ timeout: 10000 });
    await expect(dashboard.planning).toBeVisible({ timeout: 10000 });
    await expect(dashboard.criteria).toBeVisible({ timeout: 10000 });
  });

  test('all four prototypes are selectable', async ({ page }) => {
    const dashboard = new DashboardPage(page);

    for (const prototype of [1, 2, 3, 4]) {
      await dashboard.goto(prototype);
      await expect(dashboard.shell).toHaveAttribute('data-prototype', String(prototype));
    }
  });
});

// <<<
