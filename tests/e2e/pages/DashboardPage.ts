import { Page, Locator } from '@playwright/test';

/**
 * DashboardPage — page object for the fretiq prospection dashboard.
 *
 * Route: GET /admin/dashboard
 * Controller: ProspectionDashboardController
 *
 * The page renders 8 KPI cards, a funnel chart container (#kt_funnel_chart
 * or an empty-state text), an engagement chart container (#kt_engagement_chart
 * or an empty-state text), and a top-campaigns card.
 *
 * All elements that depend on data (chart canvases, table rows) may be absent
 * on a fresh dev DB — target the stable structural containers only.
 */
export class DashboardPage {
  readonly page: Page;

  /**
   * Row that wraps the 8 KPI cards — always present regardless of data.
   * Selector: first .row with g-5 class, which is the KPI row at the top.
   */
  readonly kpiRow: Locator;

  /**
   * Card that contains the funnel chart OR empty-state copy.
   * The card itself is always rendered; it contains either #kt_funnel_chart
   * or the "Aucune donnée de funnel" text.
   */
  readonly funnelCard: Locator;

  /**
   * Card that contains the engagement chart OR empty-state copy.
   */
  readonly engagementCard: Locator;

  /**
   * Card title "Top campagnes" — always rendered.
   */
  readonly topCampaignesCard: Locator;

  constructor(page: Page) {
    this.page = page;

    // The KPI row is the first .row.g-5 element on the page body.
    // We scope by the "Entreprises" label text which is always present.
    this.kpiRow = page.locator('.fw-semibold.text-gray-600', { hasText: 'Entreprises' }).first();

    // Funnel card: the card header contains "Funnel de prospection".
    this.funnelCard = page.locator('.card-label', { hasText: 'Funnel de prospection' }).first();

    // Engagement card: the card header contains "Engagement".
    this.engagementCard = page.locator('.card-label', { hasText: 'Engagement' }).first();

    // Top campaigns card title.
    this.topCampaignesCard = page.locator('.card-label', { hasText: 'Top campagnes' }).first();
  }

  async goto() {
    await this.page.goto('/admin/dashboard');
  }
}
