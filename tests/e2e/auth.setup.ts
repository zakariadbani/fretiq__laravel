import { test as setup } from '@playwright/test';
import path from 'path';
import { LoginPage } from './pages';

const ADMIN_FILE = path.join(__dirname, '.auth/admin.json');

setup('authenticate as superadmin', async ({ page }) => {
  const loginPage = new LoginPage(page);

  // ADMIN_PASSWORD must be provided via env — never hardcoded here.
  // ADMIN_EMAIL falls back to the known seeded superadmin address (not a secret).
  // BASE_URL falls back to 'http://fretiq.test' (configured in playwright.config.ts).
  const adminEmail = process.env.ADMIN_EMAIL || 'admin@fretiq.test';
  const adminPassword = process.env.ADMIN_PASSWORD;
  if (!adminPassword) {
    throw new Error(
      'ADMIN_PASSWORD env var is required to authenticate the superadmin e2e session. ' +
      'See ../memory/credentials.md for the local dev value.'
    );
  }

  await loginPage.goto();
  await loginPage.login(adminEmail, adminPassword);
  await loginPage.expectRedirectToDashboard();
  await page.context().storageState({ path: ADMIN_FILE });
});
