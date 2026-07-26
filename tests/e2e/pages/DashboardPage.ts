import { Page, Locator } from '@playwright/test';

/** Page object for the selectable fretiq prospection dashboard. */
export class DashboardPage {
  readonly page: Page;
  readonly shell: Locator;
  readonly switcher: Locator;
  readonly kpiRow: Locator;
  readonly campaigns: Locator;
  readonly planning: Locator;
  readonly criteria: Locator;

  constructor(page: Page) {
    this.page = page;
    this.shell = page.getByTestId('dashboard-shell');
    this.switcher = page.getByTestId('dashboard-prototype-switcher');
    this.kpiRow = page.getByTestId('dashboard-kpis');
    this.campaigns = page.getByTestId('dashboard-campaigns');
    this.planning = page.getByTestId('dashboard-planning');
    this.criteria = page.getByTestId('dashboard-criteria');
  }

  async goto(prototype = 1) {
    await this.page.goto(`/admin/dashboard?prototype=${prototype}`);
  }
}
