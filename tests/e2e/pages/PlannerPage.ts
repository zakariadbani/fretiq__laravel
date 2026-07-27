import { Page, Locator } from '@playwright/test';

/**
 * PlannerPage — page object for the campaign calendar / planning screen.
 *
 * Route: GET /admin/planner
 * Controller: PlannerController (gated: permission:view campaigns)
 *
 * The page renders a single card containing a FullCalendar instance mounted
 * on the div#kt_calendar_app element. The card header shows "Planning des
 * campagnes".
 *
 * A companion JSON endpoint /admin/planner/feed returns FullCalendar events:
 *   { id, title, start, color, url, extendedProps: { status, statusLabel, statusColor } }
 *
 * Note: FullCalendar initialisation happens via @push('scripts') — the
 * #kt_calendar_app div is always present in the DOM (rendered by Blade),
 * but FullCalendar's internal grid elements are injected by JS.
 */
export class PlannerPage {
  readonly page: Page;

  /**
   * Card title — always present in the Blade markup.
   */
  readonly currentTime: Locator;

  /**
   * FullCalendar mount point — always present in the Blade markup.
   * FullCalendar renders its grid inside this div after JS initialisation.
   */
  readonly calendarContainer: Locator;

  constructor(page: Page) {
    this.page = page;

    this.currentTime = page.locator('#planner-current-time');
    this.calendarContainer = page.locator('#kt_calendar_app');
  }

  async goto() {
    await this.page.goto('/admin/planner');
  }
}
