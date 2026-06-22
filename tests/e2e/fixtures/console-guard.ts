import { test as base, expect } from '@playwright/test';
import type { ConsoleMessage } from '@playwright/test';

/**
 * Substrings that are allowlisted and will NOT fail the test.
 *
 * Add entries here only for known-safe noise (e.g. Metronic demo media 404s
 * that are pending cleanup). Do not add entries to suppress real bugs.
 */
export const CONSOLE_ALLOWLIST: string[] = [
  'assets/media/stock/',
  'assets/media/patterns/',
  'Failed to load resource',
  // Leftover Metronic v8 starterkit demo-modal scripts (public/assets/js/custom/utilities/modals/
  // create-*.js and apps/subscriptions/add/customer-select.js) auto-init against the stock demo
  // modal the layout still ships and throw because the KTStepper/KTSearch instances lack `.on`.
  // fretiq uses /create pages + #form_crud, NOT these demo modals — this is demo cruft pending
  // removal, NOT a fretiq feature bug. Do not broaden these entries.
  'stepperObj.on is not a function',
  'searchObject.on is not a function',
];

function isAllowlisted(text: string): boolean {
  return CONSOLE_ALLOWLIST.some((entry) => text.includes(entry));
}

export const test = base.extend<Record<string, never>>({
  // eslint-disable-next-line no-empty-pattern
  consoleGuard: [
    async ({ page }, use) => {
      const collected: string[] = [];

      const onConsole = (msg: ConsoleMessage) => {
        if (msg.type() === 'error') {
          collected.push(msg.text());
        }
      };

      const onPageError = (err: Error) => {
        collected.push('PAGEERROR: ' + err.message);
      };

      page.on('console', onConsole);
      page.on('pageerror', onPageError);

      await use();

      page.off('console', onConsole);
      page.off('pageerror', onPageError);

      const remaining = collected.filter((entry) => !isAllowlisted(entry));
      expect(
        remaining,
        'Console JS errors on page: ' + JSON.stringify(remaining),
      ).toEqual([]);
    },
    { auto: true },
  ],
});

export { expect };
