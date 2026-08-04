import { Page, Locator } from '@playwright/test';

/** Page object for the fretiq operational dashboard. */
export class DashboardPage {
  readonly page: Page;
  readonly shell: Locator;
  readonly operationalPriorities: Locator;
  readonly executiveKpis: Locator;
  readonly engagementChart: Locator;
  readonly funnelChart: Locator;
  readonly engagementCanvas: Locator;
  readonly engagementEmpty: Locator;
  readonly engagementFallback: Locator;
  readonly engagementSummary: Locator;
  readonly funnelCanvas: Locator;
  readonly funnelEmpty: Locator;
  readonly funnelFallback: Locator;
  readonly funnelSummary: Locator;
  readonly overduePlanning: Locator;
  readonly upcomingPlanning: Locator;
  readonly campaignHealth: Locator;
  readonly topCampaigns: Locator;
  readonly discoverySummary: Locator;
  readonly criteriaYield: Locator;
  readonly enterpriseQualificationContacts: Locator;
  readonly recentEnterprises: Locator;
  readonly sendIncidents: Locator;
  readonly inbox: Locator;
  readonly sections: Locator[];

  constructor(page: Page) {
    this.page = page;
    this.shell = page.getByTestId('dashboard-shell');
    this.operationalPriorities = page.getByTestId('dashboard-operational-priorities');
    this.executiveKpis = page.getByTestId('dashboard-executive-kpis');
    this.engagementChart = page.getByTestId('dashboard-engagement-chart');
    this.funnelChart = page.getByTestId('dashboard-funnel-chart');
    this.engagementCanvas = page.locator('#dashboard-engagement-chart-canvas .apexcharts-canvas');
    this.engagementEmpty = page.getByTestId('dashboard-engagement-empty');
    this.engagementFallback = page.getByTestId('dashboard-engagement-fallback');
    this.engagementSummary = page.getByTestId('dashboard-engagement-summary');
    this.funnelCanvas = page.locator('#dashboard-funnel-chart-canvas .apexcharts-canvas');
    this.funnelEmpty = page.getByTestId('dashboard-funnel-empty');
    this.funnelFallback = page.getByTestId('dashboard-funnel-fallback');
    this.funnelSummary = page.getByTestId('dashboard-funnel-summary');
    this.overduePlanning = page.getByTestId('dashboard-planning-overdue');
    this.upcomingPlanning = page.getByTestId('dashboard-planning-upcoming');
    this.campaignHealth = page.getByTestId('dashboard-campaign-health');
    this.topCampaigns = page.getByTestId('dashboard-top-campaigns');
    this.discoverySummary = page.getByTestId('dashboard-discovery-summary');
    this.criteriaYield = page.getByTestId('dashboard-criteria-yield');
    this.enterpriseQualificationContacts = page.getByTestId('dashboard-enterprise-qualification-contacts');
    this.recentEnterprises = page.getByTestId('dashboard-recent-enterprises');
    this.sendIncidents = page.getByTestId('dashboard-send-incidents');
    this.inbox = page.getByTestId('dashboard-inbox');
    this.sections = [
      this.operationalPriorities,
      this.executiveKpis,
      this.engagementChart,
      this.funnelChart,
      this.overduePlanning,
      this.upcomingPlanning,
      this.campaignHealth,
      this.topCampaigns,
      this.discoverySummary,
      this.criteriaYield,
      this.enterpriseQualificationContacts,
      this.recentEnterprises,
    ];
  }

  async goto() {
    await this.page.goto('/admin/dashboard');
  }
}
