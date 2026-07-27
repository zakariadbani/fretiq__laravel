// IMPORTANT: console-guard auto-fails on console.error/pageerror.
import { test, expect } from '../fixtures/console-guard';
import { ObservabilityPage } from '../pages/ObservabilityPage';
import { expectPath } from '../helpers/test-utils';

/** Read-only structural coverage; mutation behavior is covered by Laravel Feature tests. */
test.describe('Observability module', () => {
  test('operational dashboard loads with queue and scheduler sections', async ({ page }) => {
    const obs = new ObservabilityPage(page);
    await obs.goto();

    await expectPath(page, '/admin/observability');
    await expect(obs.cronKpi).toBeVisible({ timeout: 10000 });
    await expect(obs.waitingJobsKpi).toBeVisible();
    await expect(obs.failedJobsKpi).toBeVisible();
    await expect(obs.activeQueueTitle).toBeVisible();
    await expect(obs.scheduledTasksTitle).toBeVisible();
    await expect(obs.failedJobsCardTitle).toBeVisible();
    await expect(obs.failedRunsCardTitle).toBeVisible();
  });
});