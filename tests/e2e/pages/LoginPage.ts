import { Page, Locator, expect } from '@playwright/test';

/**
 * LoginPage — fretiq auth page.
 * Selectors verified against Metronic Blade auth form pattern
 * (same stack as rapidlead_v2: input[name="email"], input[name="password"],
 *  #kt_sign_in_submit, #kt_sign_in_form).
 */
export class LoginPage {
  readonly page: Page;
  readonly emailInput: Locator;
  readonly passwordInput: Locator;
  readonly signInButton: Locator;
  readonly errorDialog: Locator;
  readonly loginForm: Locator;

  constructor(page: Page) {
    this.page = page;
    this.emailInput = page.locator('input[name="email"]');
    this.passwordInput = page.locator('input[name="password"]');
    this.signInButton = page.locator('#kt_sign_in_submit');
    this.errorDialog = page.locator('.swal2-popup');
    this.loginForm = page.locator('#kt_sign_in_form');
  }

  async goto() {
    const base = process.env.BASE_URL ?? 'http://fretiq.test';
    const url = new URL('/login', base).toString();
    console.log(`Navigating to: ${url}`);
    try {
      await this.page.goto(url, { timeout: 15000 });
      await this.page.waitForLoadState('networkidle');
    } catch (error) {
      console.error(`Navigation failed to ${url}: ${error}`);
      try {
        await this.page.screenshot({ path: '.screenshots/debug_login_failure.png' });
      } catch {
        // Ignore screenshot failures — preserve original error
      }
      throw error;
    }
  }

  async login(email: string, password: string) {
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    await this.signInButton.click();
  }

  async expectError() {
    await expect(this.errorDialog).toBeVisible({ timeout: 5000 });
  }

  async expectRedirectToDashboard() {
    // Superadmin redirects to /admin/dashboard after login
    await this.page.waitForURL(
      (url) =>
        url.pathname === '/admin/dashboard' ||
        url.pathname.startsWith('/admin/'),
      { timeout: 15000 },
    );
  }
}
