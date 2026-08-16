import { defineConfig, devices } from '@playwright/test';
import fs from 'fs';
import path from 'path';

// Load .env so ADMIN_PASSWORD (see auth.setup.ts) can live in the gitignored
// .env file instead of being passed inline on the command line. Uses Node's
// built-in loader (no `dotenv` dependency — it's only a transitive package,
// not in package.json). Never overrides a var already set in the shell/CI
// env — process.loadEnvFile() leaves existing process.env values alone.
const envPath = path.resolve(__dirname, '.env');
if (fs.existsSync(envPath)) {
  process.loadEnvFile(envPath);
}

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
