import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 1,
  workers: 1,
  reporter: [['html', { open: 'never' }], ['list']],
  timeout: 60_000,

  use: {
    baseURL: process.env.BASE_URL || 'http://fretiq.test',
    channel: 'chrome',            // use machine-installed Chrome, no bundled download
    headless: false,              // visible browser by default (custom-test-run Stage 3)
    navigationTimeout: 60_000,
    actionTimeout: 20_000,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    // Setup: authenticate superadmin and save session state
    {
      name: 'auth-setup',
      testMatch: /auth\.setup\.ts/,
      testDir: './tests/e2e',
    },
    // Main admin tests — use pre-authenticated superadmin session
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        storageState: './tests/e2e/.auth/admin.json',
      },
      dependencies: ['auth-setup'],
    },
  ],
});
