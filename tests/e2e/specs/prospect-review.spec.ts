import { expect, test } from '../fixtures/console-guard';

test.describe('guided prospect review', () => {
  test('shows one company decision with checks and one recommended action', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=companies');
    await expect(page).toHaveURL(/\/admin\/prospect-review\?tab=companies/);
    await expect(page.getByRole('heading', { level: 1, name: 'À revoir' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'Vérifier les entreprises' })).toBeVisible();
    await expect(page.getByText('Concentrez-vous sur le premier point orange ou rouge', { exact: false })).toBeVisible();
    await expect(page.locator('[data-review-stepper]')).toContainText(/Traitement interrompu|Vérification requise/);
    await expect(page.getByText('Progression des lots')).toHaveCount(0);

    const queueEntries = page.locator('[data-review-company-queue-entry]');
    if (await queueEntries.count()) {
      const containment = await page.evaluate(() => {
        const queue = document.querySelector('.prospect-review-queue')?.getBoundingClientRect();
        if (!queue) return [];

        return [...document.querySelectorAll('[data-review-company-queue-entry]')]
          .flatMap((entry) => [...entry.children].map((child) => ({ text: child.textContent?.trim(), right: child.getBoundingClientRect().right })))
          .filter((entry) => entry.right > queue.right + 1);
      });
      expect(containment).toEqual([]);
      await expect(page.locator('[data-review-queue-dot]')).toHaveCount(await queueEntries.count());
      await expect(page.locator('[data-review-state-filter]')).toHaveCount(3);
      const queueVisibility = await queueEntries.first().evaluate((entry) => ({
        bottom: entry.getBoundingClientRect().bottom,
        viewport: window.innerHeight,
        queueWidth: document.querySelector('.prospect-review-queue')?.getBoundingClientRect().width || 0,
      }));
      expect(queueVisibility.bottom).toBeLessThanOrEqual(queueVisibility.viewport);
      expect(queueVisibility.queueWidth).toBeGreaterThanOrEqual(315);
      expect(queueVisibility.queueWidth).toBeLessThanOrEqual(325);
      await expect(page.locator('[data-review-company-detail]')).toHaveCount(1);
      await expect(page.locator('[data-review-check]')).toHaveCount(4);
      await expect(page.locator('[data-review-company-actions]')).toHaveCount(1);
      await expect(page.locator('[data-review-primary-action]')).toHaveCount(1);
      await expect(page.locator('[data-review-secondary-actions]')).not.toHaveAttribute('open', '');

      const alternatives = page.locator('[data-review-alternatives]');
      if (await alternatives.count()) {
        await expect(alternatives).not.toHaveAttribute('open', '');
      }
    } else {
      await expect(page.locator('[data-review-empty]')).toBeVisible();
    }

    expect(await page.locator('body').innerText()).not.toMatch(/\b(domain_identity_conflict|hunter_perfect_match|rate_limit)\b/);
  });

  test('shows and focuses the monitored retry outcome with readable contrast', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=companies&item=7&monitor_item=7');
    await expect(page).toHaveURL(/monitor_item=7/);

    const monitor = page.locator('[data-review-monitor]');
    await expect(monitor).toBeVisible();
    await expect(monitor).toBeFocused();
    const contrast = await monitor.evaluate((element) => {
      const parse = (value: string): [number, number, number] => {
        const channels = value.match(/\d+(?:\.\d+)?/g)?.slice(0, 3).map(Number) ?? [];
        return [channels[0] ?? 0, channels[1] ?? 0, channels[2] ?? 0];
      };
      const luminance = ([red, green, blue]: [number, number, number]) => [red, green, blue]
        .map((channel) => {
          const normalized = channel / 255;
          return normalized <= 0.03928 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
        })
        .reduce((total, channel, index) => total + channel * [0.2126, 0.7152, 0.0722][index], 0);
      const style = getComputedStyle(element);
      const foreground = luminance(parse(style.color));
      const background = luminance(parse(style.backgroundColor));
      return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
    });

    expect(contrast).toBeGreaterThanOrEqual(4.5);
    await expect(monitor).toContainText('Relance terminée · DEANTE MAROC');
    await expect(monitor.locator('[data-review-monitor-provider-results]')).toContainText('2');
    await expect(monitor.locator('[data-review-monitor-recorded-units]')).toContainText('1');
    await expect(monitor.locator('[data-review-monitor-imported-contacts]')).toContainText('Contacts importés');
    await expect(monitor.locator('[data-review-monitor-imported-contacts]')).toContainText('aucun envoi');
    await expect(monitor.getByRole('link', { name: /Examiner.*contact/ })).toHaveCount(0);
    await expect(monitor.getByRole('link', { name: 'Continuer la file' })).toBeVisible();
    await expect(page.locator('[data-review-company-detail]')).toHaveCount(0);
    await expect(page.locator('[data-review-company-actions]')).toHaveCount(0);
    await expect(page.locator('[data-review-company-card="6"]')).toHaveCount(0);
  });

  test('reloads the monitored result exactly once when polling becomes terminal', async ({ page }) => {
    let statusRequests = 0;
    let resultNavigations = 0;

    await page.addInitScript(() => {
      const addEventListener = Document.prototype.addEventListener;
      Document.prototype.addEventListener = function (type, listener, options) {
        if (type !== 'DOMContentLoaded' || typeof listener !== 'function') {
          return addEventListener.call(this, type, listener, options);
        }

        return addEventListener.call(this, type, function (event) {
          const monitor = document.querySelector<HTMLElement>('[data-review-monitor]');
          if (monitor) monitor.dataset.reviewMonitorTerminal = '0';
          return listener.call(this, event);
        }, options);
      };
    });
    await page.route(/\/admin\/prospect-review\/items\/6\/status$/, async (route) => {
      statusRequests += 1;
      await route.fulfill({
        json: {
          id: 6,
          status: 'ready',
          terminal: true,
          level: 'success',
          title: 'Relance terminée · COMAREV',
          message: 'Le traitement de COMAREV est terminé.',
          review_url: '/admin/prospect-review?poll_result=complete',
          provider_result_count: 0,
          recorded_units: 0,
          imported_contacts_count: 0,
        },
      });
    });
    await page.route(/\/admin\/prospect-review\?poll_result=complete$/, async (route) => {
      resultNavigations += 1;
      await route.fulfill({ contentType: 'text/html', body: '<main data-poll-result>Résultat actualisé</main>' });
    });

    await page.goto('/admin/prospect-review?tab=companies&item=6&monitor_item=6');
    await expect(page.locator('[data-poll-result]')).toBeVisible({ timeout: 7_000 });
    await page.waitForTimeout(2_500);

    expect(statusRequests).toBe(1);
    expect(resultNavigations).toBe(1);
  });

  test('does not navigate to a cross-origin polling result', async ({ page }) => {
    let statusRequests = 0;
    let crossOriginRequests = 0;

    await page.addInitScript(() => {
      const addEventListener = Document.prototype.addEventListener;
      Document.prototype.addEventListener = function (type, listener, options) {
        if (type !== 'DOMContentLoaded' || typeof listener !== 'function') {
          return addEventListener.call(this, type, listener, options);
        }

        return addEventListener.call(this, type, function (event) {
          const monitor = document.querySelector<HTMLElement>('[data-review-monitor]');
          if (monitor) monitor.dataset.reviewMonitorTerminal = '0';
          return listener.call(this, event);
        }, options);
      };
    });
    await page.route(/\/admin\/prospect-review\/items\/6\/status$/, async (route) => {
      statusRequests += 1;
      await route.fulfill({
        json: {
          id: 6,
          status: 'failed',
          terminal: true,
          level: 'danger',
          title: 'Recherche de contacts à relancer · COMAREV',
          message: 'Le traitement de COMAREV reste interrompu.',
          review_url: 'https://attacker.invalid/leave-fretiq',
          provider_result_count: null,
          recorded_units: null,
          imported_contacts_count: 0,
        },
      });
    });
    await page.route('https://attacker.invalid/**', async (route) => {
      crossOriginRequests += 1;
      await route.abort();
    });

    await page.goto('/admin/prospect-review?tab=companies&item=6&monitor_item=6');
    await expect(page.locator('[data-review-monitor]')).toContainText('Le traitement de COMAREV reste interrompu.', { timeout: 7_000 });

    await expect(page).toHaveURL(/\/admin\/prospect-review\?tab=companies&item=6&monitor_item=6$/);
    expect(statusRequests).toBe(1);
    expect(crossOriginRequests).toBe(0);
  });

  test('shows immediate pending feedback without submitting a retry twice', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=companies&item=6');
    await expect(page).toHaveURL(/\/admin\/prospect-review\?tab=companies&item=6/);

    const form = page.locator('form[data-review-primary-form]');
    const button = form.locator('[data-review-primary-action="retry"]');
    await expect(form).toHaveCount(1);
    await form.evaluate((element) => {
      (window as typeof window & { __prospectRetrySubmits?: number }).__prospectRetrySubmits = 0;
      element.addEventListener('submit', (event) => {
        event.preventDefault();
        const retryWindow = window as typeof window & { __prospectRetrySubmits?: number };
        retryWindow.__prospectRetrySubmits = (retryWindow.__prospectRetrySubmits ?? 0) + 1;
      });
    });
    await button.dblclick();

    await expect(button).toBeDisabled();
    await expect(button).toHaveAttribute('aria-busy', 'true');
    await expect(button).toContainText('Relance demandée');
    expect(await page.evaluate(() => (window as typeof window & { __prospectRetrySubmits?: number }).__prospectRetrySubmits)).toBe(1);
  });

  test('keeps review actions inside a 390px viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/prospect-review?tab=companies');
    await expect(page).toHaveURL(/\/admin\/prospect-review\?tab=companies/);
    const widths = await page.evaluate(() => ({ document: document.documentElement.scrollWidth, viewport: document.documentElement.clientWidth }));
    expect(widths.document).toBeLessThanOrEqual(widths.viewport + 1);

    if (await page.locator('[data-review-company-detail]').count()) {
      await expect(page.getByLabel('Entreprise affichée')).toBeVisible();
      await expect(page.locator('#review-search')).toBeVisible();
      await expect(page.locator('.prospect-review-more-filters > summary')).toBeVisible();
      await page.locator('.prospect-review-more-filters > summary').click();
      await expect(page.locator('#review-reason')).toBeVisible();
      await expect(page.locator('[data-review-company-queue-entry]:visible')).toHaveCount(0);
      await expect(page.locator('[data-review-check]')).toHaveCount(4);
    }

    const visibleButtons = page.locator('[data-review-workspace] button:visible');
    for (let index = 0; index < await visibleButtons.count(); index += 1) {
      const box = await visibleButtons.nth(index).boundingBox();
      expect(box).not.toBeNull();
      expect(box!.x).toBeGreaterThanOrEqual(0);
      expect(box!.x + box!.width).toBeLessThanOrEqual(391);
    }
  });

  test('redirects the legacy contacts tab to company review while preserving the batch', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=contacts&batch=2');
    await expect(page).toHaveURL(/\/admin\/prospect-review\?batch=2&tab=companies/);
    await expect(page.getByText('Les contacts sont désormais importés automatiquement', { exact: false })).toBeVisible();
    await expect(page.getByText('Décider des contacts')).toHaveCount(0);
  });

  // ── Default landing + pill/row consistency ──────────────────────────────

  test('lands on the "À décider" pill by default, and each pill count matches its own filtered total', async ({ page }) => {
    await page.goto('/admin/prospect-review');
    await expect(page).toHaveURL(/\/admin\/prospect-review(\?.*)?$/);

    const attentionPill = page.locator('[data-review-state-filter="attention"]');
    const allPill = page.locator('[data-review-state-filter="all"]');
    await expect(attentionPill).toHaveClass(/is-active/);
    await expect(attentionPill).toHaveAttribute('aria-current', 'true');
    await expect(allPill).not.toHaveClass(/is-active/);
    await expect(page.locator('[data-review-state-filter="blocked"]')).not.toHaveClass(/is-active/);

    // The queue header always renders $items->total() (the full filtered count,
    // not just the current page) — a page-independent way to check the pill
    // badge against "the rows shown for that pill" even when a state paginates.
    for (const state of ['attention', 'blocked', 'all'] as const) {
      await page.goto(`/admin/prospect-review?tab=companies&state=${state}`);

      const pillText = await page.locator(`[data-review-state-filter="${state}"]`).textContent();
      const pillCount = Number(pillText?.match(/\((\d+)\)/)?.[1]);
      expect(Number.isInteger(pillCount)).toBe(true);

      const headerText = await page.locator('.prospect-review-queue-header').getByText(/résultat\(s\) avec ces filtres/).textContent();
      const headerTotal = Number(headerText?.match(/(\d+)/)?.[1]);
      expect(headerTotal).toBe(pillCount);

      // When the filtered set fits on one page, the DOM row count must match too.
      if (pillCount > 0 && (await page.locator('.prospect-review-queue-pagination').count()) === 0) {
        await expect(page.locator('[data-review-company-queue-entry]')).toHaveCount(pillCount);
      }
    }
  });

  // ── Pane swap without navigation (change 6) ─────────────────────────────

  test('swaps the detail pane on a queue click without a full page navigation, and Back restores the previous item', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=companies&state=blocked');
    const entries = page.locator('[data-review-company-queue-entry]');
    const entryCount = await entries.count();
    test.skip(entryCount < 2, 'Needs at least two "À relancer" queue entries to prove the pane swap.');

    const firstItemId = new URL((await entries.nth(0).getAttribute('href'))!, page.url()).searchParams.get('item');
    const secondItemId = new URL((await entries.nth(1).getAttribute('href'))!, page.url()).searchParams.get('item');

    // A full navigation wipes window state — surviving this sentinel proves
    // the click was handled by fetch()+innerHTML swap, not a page reload.
    await page.evaluate(() => { (window as typeof window & { __e2eSentinel?: string }).__e2eSentinel = 'alive'; });

    await entries.nth(1).click();
    await expect(page).toHaveURL(new RegExp(`item=${secondItemId}`));
    await expect(page.locator('[data-review-company-card]')).toHaveAttribute('data-review-company-card', String(secondItemId));
    expect(await page.evaluate(() => (window as typeof window & { __e2eSentinel?: string }).__e2eSentinel)).toBe('alive');

    await page.goBack();
    await expect(page).not.toHaveURL(new RegExp(`item=${secondItemId}`));
    await expect(page.locator('[data-review-company-card]')).toHaveAttribute('data-review-company-card', String(firstItemId));
  });

  // ── Pagination preserved across a decision (the companies_page trap) ────

  test('preserves the current queue page across a decision via return_companies_page', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=companies&state=blocked&companies_page=2');
    const entries = page.locator('[data-review-company-queue-entry]');
    const entryCount = await entries.count();
    test.skip(entryCount === 0, 'The "À relancer" queue does not currently have a second page.');

    await entries.nth(entryCount > 1 ? 1 : 0).click();
    await expect(page).toHaveURL(/companies_page=2/);

    const returnPageInput = page.locator('[data-review-company-detail] input[name="return_companies_page"]').first();
    await expect(returnPageInput).toHaveValue('2');
  });

  // ── Inactive-criterion banner ────────────────────────────────────────────

  test('shows the inactive-criterion explanatory banner on "À relancer" with no enabled bulk-retry button', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=companies&state=blocked');

    const drainBar = page.locator('[data-drain-bar]');
    if ((await drainBar.count()) === 0) {
      test.skip(true, 'No blocked items under these filters right now — the bulk bar is not rendered at all.');
    }

    const openButton = page.locator('[data-drain-open]');
    if ((await openButton.count()) > 0) {
      test.skip(true, 'At least one criterion is active — the inactive-criterion banner scenario no longer applies.');
    }

    const blockedBanner = page.locator('[data-drain-blocked]');
    await expect(blockedBanner).toBeVisible();
    await expect(blockedBanner).toContainText('critère inactif');
    await expect(openButton).toHaveCount(0);
  });
});
