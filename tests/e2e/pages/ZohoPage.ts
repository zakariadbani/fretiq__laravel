import { Locator, Page } from '@playwright/test';

/**
 * Read-only page object for the focused Zoho synchronization screen.
 *
 * Route: GET /admin/zoho
 *
 * The spec asserts the controls' presence only. It must never submit a form,
 * because every control here can enqueue synchronization or maintenance work.
 */
export class ZohoPage {
  readonly page: Page;
  readonly syncCenter: Locator;
  readonly syncAllButton: Locator;
  readonly moduleGrid: Locator;
  readonly moduleCards: Locator;
  readonly moduleButtons: Locator;
  readonly maintenanceControls: Locator;
  readonly liveProgress: Locator;

  constructor(page: Page) {
    this.page = page;
    this.syncCenter = page.locator('[data-zoho-sync-center]');
    this.syncAllButton = page.locator('[data-zoho-sync-all-button]');
    this.moduleGrid = page.locator('[data-zoho-module-grid]');
    this.moduleCards = page.locator('[data-zoho-module-card]');
    this.moduleButtons = page.locator('[data-zoho-sync-button]');
    this.maintenanceControls = page.locator('[data-zoho-maintenance-controls]');
    this.liveProgress = page.locator('[data-zoho-live-progress]');
  }

  async goto() {
    await this.page.goto('/admin/zoho');
  }
}
