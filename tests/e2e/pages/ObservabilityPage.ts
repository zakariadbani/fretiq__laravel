import { Page, Locator } from '@playwright/test';

/** Page object for the queue and scheduler operations dashboard. */
export class ObservabilityPage {
  readonly page: Page;
  readonly cronKpi: Locator;
  readonly waitingJobsKpi: Locator;
  readonly failedJobsKpi: Locator;
  readonly activeQueueTitle: Locator;
  readonly scheduledTasksTitle: Locator;
  readonly failedJobsCardTitle: Locator;
  readonly failedRunsCardTitle: Locator;

  constructor(page: Page) {
    this.page = page;
    this.cronKpi = page.locator('.fw-semibold.text-gray-600', { hasText: 'Planificateur Laravel' }).first();
    this.waitingJobsKpi = page.locator('.fw-semibold.text-gray-600', { hasText: 'Jobs en attente / différés' }).first();
    this.failedJobsKpi = page.locator('.fw-semibold.text-gray-600', { hasText: 'Jobs échoués' }).first();
    this.activeQueueTitle = page.locator('.card-label', { hasText: 'File active' }).first();
    this.scheduledTasksTitle = page.locator('.card-label', { hasText: 'Tâches planifiées' }).first();
    this.failedJobsCardTitle = page.locator('.card-label', { hasText: 'Jobs échoués' }).first();
    this.failedRunsCardTitle = page.locator('.card-label', { hasText: 'Exécutions de campagne échouées' }).first();
  }

  async goto() {
    await this.page.goto('/admin/observability');
  }
}