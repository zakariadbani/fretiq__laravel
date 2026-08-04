import { expect, test } from '../fixtures/console-guard';
import type { Page } from '@playwright/test';
import { waitForDataTable } from '../helpers/test-utils';

async function expectNamedFormControls(page: Page) {
  const unnamed = await page.locator('input:not([type="hidden"]), select, textarea').evaluateAll((controls) => {
    return controls
      .filter((control: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement) => {
        const id = control.id;
        return !control.getAttribute('aria-label') &&
          !control.getAttribute('aria-labelledby') &&
          !control.getAttribute('title') &&
          !(id && document.querySelector(`label[for="${CSS.escape(id)}"]`)) &&
          !control.closest('label');
      })
      .map((control) => control.getAttribute('name') || control.id || control.outerHTML.slice(0, 80));
  });

  expect(unnamed).toEqual([]);
}

async function expectPath(page: Page, path: string) {
  await expect(page).toHaveURL(new RegExp(`${path.replace(/\//g, '\\/')}(?:[?#].*)?$`));
}

async function expectDemoDrawersAndModalsAbsent(page: Page) {
  const demoSurfaces = page.locator([
    '#kt_activities',
    '#kt_drawer_chat',
    '#kt_shopping_cart',
    '#kt_modal_upgrade_plan',
    '#kt_modal_create_app',
    '#kt_modal_create_campaign',
    '#kt_modal_create_project',
    '#kt_modal_new_target',
    '#kt_modal_view_users',
    '#kt_modal_users_search',
    '#kt_modal_invite_friends',
  ].join(', '));

  await expect(demoSurfaces).toHaveCount(0);
}

async function expectMenuTriggerKeyOpens(page: Page, name: string, key: 'Enter' | 'Space') {
  const trigger = page.getByRole('button', { name });
  const menu = trigger.locator('xpath=following-sibling::*[contains(concat(" ", normalize-space(@class), " "), " menu-sub ")][1]');

  await expect(trigger).toBeVisible();
  await trigger.focus();
  await page.keyboard.press(key);
  await expect(menu).toHaveClass(/show/);
  await page.evaluate(() => (window as any).KTMenu?.hideDropdowns());
}

test.describe('accessibility smoke', () => {
  test('navigation triggers are semantic and keyboard focusable', async ({ page }) => {
    await page.goto('/dashboard');
    await expectPath(page, '/admin/dashboard');
    await expectDemoDrawersAndModalsAbsent(page);

    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Aller au contenu principal' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);

    await expect(page.getByRole('button', { name: 'Ouvrir le menu du compte' })).toBeVisible();
    await expectMenuTriggerKeyOpens(page, 'Ouvrir le menu du compte', 'Enter');
    await expectMenuTriggerKeyOpens(page, 'Ouvrir le menu du compte', 'Space');
    await expectMenuTriggerKeyOpens(page, 'Ouvrir les actions rapides', 'Enter');
    await expectMenuTriggerKeyOpens(page, 'Ouvrir les actions rapides', 'Space');
    await expect(page.locator('#kt_app_sidebar_toggle')).toHaveJSProperty('tagName', 'BUTTON');

    await page.keyboard.press('Tab');
    const focusedTag = await page.evaluate(() => document.activeElement?.tagName);
    expect(['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA']).toContain(focusedTag);
  });

  test('company form controls expose accessible names', async ({ page }) => {
    await page.goto('/admin/companies/create');
    await expectPath(page, '/admin/companies/create');

    await expectNamedFormControls(page);
  });

  test('company listing exposes one logical table header and named icon actions', async ({ page }) => {
    await page.goto('/admin/companies');
    await expectPath(page, '/admin/companies');
    await expect(page.locator('#company-table')).toBeVisible();
    await waitForDataTable(page, 'company-table');

    await expectNamedFormControls(page);
    await expectDemoDrawersAndModalsAbsent(page);

    const exposedClones = await page.locator('.dataTables_scrollHead:not([aria-hidden="true"]), .dt-scroll-head:not([aria-hidden="true"]), .fixedHeader-floating:not([aria-hidden="true"])').count();
    expect(exposedClones).toBe(0);

    const unnamedIconActions = await page.locator('a.btn-icon, button.btn-icon').evaluateAll((actions) => {
      return actions
        .filter((action) => !action.textContent?.trim() && !action.getAttribute('aria-label'))
        .map((action) => action.outerHTML.slice(0, 80));
    });
    expect(unnamedIconActions).toEqual([]);

    const filters = page.locator('#filters-container select');
    expect(await filters.count()).toBeGreaterThan(0);
    for (let index = 0; index < await filters.count(); index += 1) {
      await expect(filters.nth(index).locator('xpath=following-sibling::*[contains(@class, "select2-container")]')).toHaveCount(1);
    }
  });

  test('company detail tabs and status switch expose synchronized semantics', async ({ page }) => {
    await page.goto('/admin/companies');
    await waitForDataTable(page, 'company-table');
    await page.locator('#company-table a[title="Voir"]').first().click();

    const tabs = page.getByRole('tab');
    expect(await tabs.count()).toBeGreaterThan(1);
    await tabs.first().focus();
    await page.keyboard.press('ArrowRight');

    await expect(tabs.nth(1)).toBeFocused();
    await expect(tabs.nth(1)).toHaveAttribute('aria-selected', 'true');
    await expect(tabs.nth(1)).toHaveAttribute('tabindex', '0');
    await expect(tabs.first()).toHaveAttribute('aria-selected', 'false');
    await expect(page.locator(`#${await tabs.first().getAttribute('aria-controls')}`)).toHaveAttribute('hidden', '');
    await expect(page.locator(`#${await tabs.nth(1).getAttribute('aria-controls')}`)).not.toHaveAttribute('hidden', '');

    const statusSwitch = page.getByRole('switch').first();
    await expect(statusSwitch).toHaveAccessibleName(/Modifier : (?!is_active).+/);
  });

  test('mobile form actions stay below the final control and stack safely', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/companies/create');

    const finalControl = page.locator('input:not([type="hidden"]):visible, textarea:visible, select:visible').last();
    const actions = page.locator('[data-crud-form-actions="sticky"]');
    await finalControl.scrollIntoViewIfNeeded();
    await expect(actions).toBeVisible();

    const finalBox = await finalControl.boundingBox();
    const actionBox = await actions.boundingBox();
    expect(finalBox).not.toBeNull();
    expect(actionBox).not.toBeNull();
    expect(finalBox!.y + finalBox!.height).toBeLessThanOrEqual(actionBox!.y + 1);

    const buttons = actions.locator('.btn');
    expect(await buttons.count()).toBeGreaterThan(1);
    for (let index = 0; index < await buttons.count(); index += 1) {
      expect((await buttons.nth(index).boundingBox())!.width).toBeGreaterThan(300);
    }
  });
});
