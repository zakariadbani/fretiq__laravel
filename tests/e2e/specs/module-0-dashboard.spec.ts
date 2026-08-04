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
    await expect(dashboard.shell).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Centre de commandement' })).toBeVisible();
  });

  test('each operational dashboard section renders exactly once', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    for (const section of dashboard.sections) {
      await expect(section).toHaveCount(1);
      await expect(section).toBeVisible({ timeout: 10000 });
    }

    await expect(dashboard.sendIncidents).toBeVisible({ timeout: 10000 });
    await expect(dashboard.inbox).toBeVisible({ timeout: 10000 });
  });

  test('charts render a populated visualization or their explicit empty state', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    const engagementCanvasCount = await dashboard.engagementCanvas.count();
    const engagementEmptyCount = await dashboard.engagementEmpty.count();
    expect(engagementCanvasCount + engagementEmptyCount).toBe(1);
    if (engagementCanvasCount === 1) {
      await expect(dashboard.engagementCanvas).toBeVisible();
      await expect(dashboard.engagementFallback).toBeHidden();
      await expect(dashboard.engagementSummary).toHaveCount(1);
    } else {
      await expect(dashboard.engagementEmpty).toBeVisible();
    }

    const funnelCanvasCount = await dashboard.funnelCanvas.count();
    const funnelEmptyCount = await dashboard.funnelEmpty.count();
    expect(funnelCanvasCount + funnelEmptyCount).toBe(1);
    if (funnelCanvasCount === 1) {
      await expect(dashboard.funnelCanvas).toBeVisible();
      await expect(dashboard.funnelFallback).toBeHidden();
      await expect(dashboard.funnelSummary).toHaveCount(1);
    } else {
      await expect(dashboard.funnelEmpty).toBeVisible();
    }
  });

  test('legacy prototype query no longer changes the dashboard', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();
    const canonicalSectionCounts = await Promise.all(dashboard.sections.map((section) => section.count()));

    await page.goto('/admin/dashboard?prototype=4');

    await expect(Promise.all(dashboard.sections.map((section) => section.count())))
      .resolves.toEqual(canonicalSectionCounts);
    await expect(dashboard.shell).not.toHaveAttribute('data-prototype');
    await expect(page.getByTestId('dashboard-prototype-switcher')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Centre de commandement' })).toBeVisible();
  });
});

// <<<
