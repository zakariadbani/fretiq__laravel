// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';

/**
 * Prospect batch view — targets batch id 2 on the live dev site, which is a
 * real, currently-interrupted Discover batch (status=review,
 * error=finalization_delayed, source cursor stuck mid-way through
 * discovery). Read-only: never clicks "Continuer la découverte" — that
 * resumes a real discovery run and spends provider credits.
 */
test.describe('Prospect batch view — batch 2 (live dev data)', () => {
  test('shows the interrupted-discovery banner, item links to review, and French status/motif text with no raw codes', async ({ page }) => {
    await page.goto('/admin/prospect_batches/2');
    await expect(page).toHaveURL(/\/admin\/prospect_batches\/2$/);

    const resumeBanner = page.locator('[data-discover-resume-banner]');
    await expect(resumeBanner).toBeVisible();
    await expect(resumeBanner).toContainText(/Il reste environ \d+ entreprise/);

    // Present and enabled, never clicked — resuming discovery spends real
    // provider credits (SerpAPI/Hunter).
    const resumeButton = page.locator('[data-discover-resume-button]');
    await expect(resumeButton).toBeVisible();
    await expect(resumeButton).toBeEnabled();
    await expect(resumeButton).toContainText('Continuer la découverte');

    const itemLinks = page.locator('table a[href*="/admin/prospect-review"]');
    const linkCount = await itemLinks.count();
    if (linkCount > 0) {
      const hrefs = await itemLinks.evaluateAll((anchors) => anchors.map((a) => a.getAttribute('href')));
      for (const href of hrefs) {
        expect(href).toMatch(/\/admin\/prospect-review\?.*\bitem=\d+\b/);
      }
    }

    const bodyText = await page.locator('body').innerText();
    expect(bodyText).not.toMatch(/\btoo_many_requests\b/);
    expect(bodyText).not.toMatch(/\bFailed\b/);
    expect(bodyText).not.toMatch(/\bPromoted\b/);
  });
});
