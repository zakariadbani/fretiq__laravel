import { Page, Locator } from '@playwright/test';

/**
 * SettingsPage — page object for the application settings screen.
 *
 * Route: GET /admin/settings
 * Controller: SettingController (gated: permission:view settings)
 *
 * The page renders a card with Bootstrap tab navigation. Tabs are driven by
 * SettingController::$tabs — one enabled tab ("Découverte") and five disabled
 * placeholder tabs ("Envoi & Identités", "Envoi & permissions", "Conformité",
 * "Délivrabilité", "Zoho & Intégrations").
 *
 * Key stable IDs / landmarks from the blade:
 *   #settingsNav         — the <ul> tab nav
 *   #settingsTabContent  — the tab panes wrapper
 *   #kt_tab_decouverte   — the active "Découverte" pane
 *   nav-decouverte-tab   — the first enabled nav link (id attribute)
 *
 * IMPORTANT: do NOT click the Save button (POST /admin/settings/save) —
 * that mutates the settings table. Assert presence only.
 */
export class SettingsPage {
  readonly page: Page;

  /**
   * The tab nav <ul> — always rendered.
   */
  readonly tabNav: Locator;

  /**
   * The "Découverte" nav link — the only enabled tab link.
   * Selector: #nav-decouverte-tab (id assigned by the blade loop).
   */
  readonly decouverteTab: Locator;

  /**
   * The "Découverte" tab pane content area.
   * Selector: #kt_tab_decouverte
   */
  readonly decouvertePane: Locator;

  /**
   * Save button in the card footer — rendered only for users with
   * `edit settings` permission. Spec asserts visible but NEVER clicks.
   */
  readonly saveButton: Locator;

  constructor(page: Page) {
    this.page = page;

    this.tabNav = page.locator('#settingsNav');
    this.decouverteTab = page.locator('#nav-decouverte-tab');
    this.decouvertePane = page.locator('#kt_tab_decouverte');
    this.saveButton = page.locator('button[type="submit"]', { hasText: 'Enregistrer' }).first();
  }

  async goto() {
    await this.page.goto('/admin/settings');
  }
}
