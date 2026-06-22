// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { ObservabilityPage } from '../pages/ObservabilityPage';
import { expectPath } from '../helpers/test-utils';

// >>> custom-test-author:observability-e2e

/**
 * module-16-observability — queue / job-failure monitoring screen read-only e2e suite.
 *
 * Read-only: asserts structural landmarks render on page load.
 * No DataTable (observability uses plain HTML tables rendered server-side).
 * No mutations.
 *
 * Stable selectors used:
 *   .fw-semibold.text-gray-600 with text "Jobs échoués"       — KPI card label
 *   .fw-semibold.text-gray-600 with text "Exécutions échouées"— KPI card label
 *   .card-label with text "Jobs échoués"                      — section card header
 *   .card-label with text "Exécutions de campagne échouées"   — section card header
 *
 * Gate: permission:manage roles — only superadmin / admin; ensure the test
 * storageState uses an admin or superadmin user.
 */

test.describe('Observability module', () => {

  // ── 1. Path is /admin/observability ──────────────────────────────────────

  test('observability page loads at /admin/observability', async ({ page }) => {
    const obs = new ObservabilityPage(page);
    await obs.goto();

    await expectPath(page, '/admin/observability');
  });

  // ── 2. KPI counter cards render ───────────────────────────────────────────

  test('KPI card labels "Jobs échoués" and "Exécutions échouées" are visible', async ({ page }) => {
    const obs = new ObservabilityPage(page);
    await obs.goto();

    await expect(obs.failedJobsKpi).toBeVisible({ timeout: 10000 });
    await expect(obs.failedRunsKpi).toBeVisible({ timeout: 10000 });
  });

  // ── 3. Card section headers render ────────────────────────────────────────

  test('failed-jobs and failed-runs card headers render', async ({ page }) => {
    const obs = new ObservabilityPage(page);
    await obs.goto();

    await expect(obs.failedJobsCardTitle).toBeVisible({ timeout: 10000 });
    await expect(obs.failedRunsCardTitle).toBeVisible({ timeout: 10000 });
  });

});

// <<<
