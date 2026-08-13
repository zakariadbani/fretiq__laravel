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

  test('shows and focuses the monitored retry outcome', async ({ page }) => {
    await page.goto('/admin/prospect-review?tab=companies&item=7&monitor_item=7');
    await expect(page).toHaveURL(/monitor_item=7/);

    const monitor = page.locator('[data-review-monitor]');
    await expect(monitor).toBeVisible();
    await expect(monitor).toBeFocused();
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
});
