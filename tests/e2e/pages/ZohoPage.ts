import { Page, Locator } from '@playwright/test';

/**
 * ZohoPage — page object for the Zoho sync-status screen.
 *
 * Route: GET /admin/zoho
 * Controller: ZohoController
 *
 * Key structural elements:
 *   - Drivers row (Driver CRM / Driver Campaigns cards)
 *   - Readiness checklist card ("Driver Campaigns réel — prérequis")
 *   - Sync history table
 *   - Action buttons: "Synchroniser maintenant" (POST /admin/zoho/sync)
 *     and "Importer les modèles d'email" (POST /admin/zoho/sync_templates)
 *     — rendered only when user has the required permissions.
 *
 * IMPORTANT: never click the sync buttons in specs — they fire live Zoho calls.
 */
export class ZohoPage {
  readonly page: Page;

  /**
   * Card title for the campaigns-driver readiness checklist.
   * Always rendered (even when driver=local and all items are red).
   */
  readonly readinessCardTitle: Locator;

  /**
   * The readiness checklist table body.
   * Contains one row per prerequisite item from CampaignsReadinessService.
   */
  readonly readinessTable: Locator;

  /**
   * "Synchroniser maintenant" submit button.
   * Visible only when user has `sync zoho` permission.
   * Spec must assert visible/present but NEVER click.
   */
  readonly syncButton: Locator;

  /**
   * "Importer les modèles d'email" submit button.
   * Visible only when user has `create campaign_templates` permission.
   * Spec must assert visible/present but NEVER click.
   */
  readonly syncTemplatesButton: Locator;

  /**
   * Compact, wrapping action bar. Buttons are asserted read-only in specs.
   */
  readonly actionBar: Locator;

  readonly actionButtons: Locator;

  /**
   * Driver CRM badge cell — always rendered.
   */
  readonly crmDriverCard: Locator;

  constructor(page: Page) {
    this.page = page;

    this.readinessCardTitle = page.locator('.card-label', {
      hasText: 'Driver Campaigns réel — prérequis',
    }).first();

    // The readiness table is the one inside the card that follows the card-label above.
    // Locate by the thead content "Prérequis".
    this.readinessTable = page.locator('table').filter({
      has: page.locator('th', { hasText: 'Prérequis' }),
    }).first();

    // Buttons are inside <form> elements with specific actions.
    this.syncButton = page.locator('form[action*="zoho/sync"] button[type="submit"]').first();
    this.syncTemplatesButton = page.locator(
      'form[action*="zoho/sync_templates"] button[type="submit"]',
    ).first();
    this.actionBar = page.locator('[data-zoho-action-bar]').first();
    this.actionButtons = this.actionBar.locator('button[type="submit"]');

    this.crmDriverCard = page.locator('.fw-bold.text-gray-800', { hasText: 'Driver CRM' }).first();
  }

  async goto() {
    await this.page.goto('/admin/zoho');
  }
}
