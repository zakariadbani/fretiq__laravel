// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { DashboardPage } from '../pages/DashboardPage';
import { expectPath } from '../helpers/test-utils';

// >>> custom-test-author:dashboard-e2e

/**
 * module-0-dashboard — fretiq prospection dashboard read-only e2e suite.
 *
 * Read-only: asserts structural landmarks render on page load.
 * No form submissions, no data mutations.
 *
 * Stable selectors used:
 *   .fw-semibold.text-gray-600 with text "Entreprises" — KPI card label (always rendered)
 *   .card-label with text "Funnel de prospection"       — chart section header
 *   .card-label with text "Engagement"                  — chart section header
 *   .card-label with text "Top campagnes"               — table section header
 */

test.describe('Dashboard module', () => {

  // ── 1. Path is /admin/dashboard ───────────────────────────────────────────

  test('dashboard page loads at /admin/dashboard', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    await expectPath(page, '/admin/dashboard');
  });

  // ── 2. KPI cards render ───────────────────────────────────────────────────

  test('KPI card "Entreprises" is visible', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    await expect(dashboard.kpiRow).toBeVisible({ timeout: 10000 });
  });

  // ── 3. Chart section headers render ──────────────────────────────────────

  test('funnel and engagement section headers render', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    await expect(dashboard.funnelCard).toBeVisible({ timeout: 10000 });
    await expect(dashboard.engagementCard).toBeVisible({ timeout: 10000 });
  });

  // ── 4. Top-campagnes card renders ─────────────────────────────────────────

  test('top-campagnes card header renders', async ({ page }) => {
    const dashboard = new DashboardPage(page);
    await dashboard.goto();

    await expect(dashboard.topCampaignesCard).toBeVisible({ timeout: 10000 });
  });

});

// <<<
