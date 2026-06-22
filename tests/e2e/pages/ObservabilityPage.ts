import { Page, Locator } from '@playwright/test';

/**
 * ObservabilityPage — page object for the queue / job-failure monitoring screen.
 *
 * Route: GET /admin/observability
 * Controller: ObservabilityController (gated: permission:manage roles)
 *
 * The page renders:
 *   - 4 KPI counter cards (failed_jobs, failed_runs, queued_recipients, scheduled_runs)
 *   - "Jobs échoués" card with a plain HTML table (NOT a yajra DataTable)
 *   - "Exécutions de campagne échouées" card with a plain HTML table
 *
 * On a fresh dev DB both tables show their empty-state paragraph — the spec
 * targets the card headers which are always rendered regardless of data.
 */
export class ObservabilityPage {
  readonly page: Page;

  /**
   * "Jobs échoués" card label — always rendered.
   */
  readonly failedJobsCardTitle: Locator;

  /**
   * "Exécutions de campagne échouées" card label — always rendered.
   */
  readonly failedRunsCardTitle: Locator;

  /**
   * KPI counter card label "Jobs échoués" (the small stat card at the top).
   * Uses the fw-semibold.text-gray-600 pattern from the dashboard.
   */
  readonly failedJobsKpi: Locator;

  /**
   * KPI counter card label "Exécutions échouées".
   */
  readonly failedRunsKpi: Locator;

  constructor(page: Page) {
    this.page = page;

    // Card-label in the card header (larger section heading)
    this.failedJobsCardTitle = page.locator('.card-label', { hasText: 'Jobs échoués' }).first();
    this.failedRunsCardTitle = page.locator('.card-label', {
      hasText: 'Exécutions de campagne échouées',
    }).first();

    // Small KPI card labels (fw-semibold text-gray-600 pattern from the blade)
    this.failedJobsKpi = page.locator('.fw-semibold.text-gray-600', {
      hasText: 'Jobs échoués',
    }).first();
    this.failedRunsKpi = page.locator('.fw-semibold.text-gray-600', {
      hasText: 'Exécutions échouées',
    }).first();
  }

  async goto() {
    await this.page.goto('/admin/observability');
  }
}
