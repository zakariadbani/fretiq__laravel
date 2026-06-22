// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { SettingsPage } from '../pages/SettingsPage';
import { expectPath } from '../helpers/test-utils';

// >>> custom-test-author:settings-e2e

/**
 * module-14-settings — application settings page read-only e2e suite.
 *
 * Read-only: asserts the settings tabs and "Découverte" pane render.
 * The Save button is verified as visible but NEVER clicked (clicking would
 * fire a POST to /admin/settings/save and mutate the settings table).
 *
 * Stable selectors used:
 *   #settingsNav             — Bootstrap tab navigation <ul>
 *   #nav-decouverte-tab      — "Découverte" tab link (first enabled tab)
 *   #kt_tab_decouverte       — "Découverte" tab pane content
 *   button[type="submit"]    — Save button in card footer (admin/superadmin only)
 *
 * Gate: permission:view settings — admin and superadmin have this.
 */

test.describe('Settings module', () => {

  // ── 1. Path is /admin/settings ────────────────────────────────────────────

  test('settings page loads at /admin/settings', async ({ page }) => {
    const settings = new SettingsPage(page);
    await settings.goto();

    await expectPath(page, '/admin/settings');
  });

  // ── 2. Tab navigation renders ────────────────────────────────────────────

  test('tab navigation #settingsNav is visible', async ({ page }) => {
    const settings = new SettingsPage(page);
    await settings.goto();

    await expect(settings.tabNav).toBeVisible({ timeout: 10000 });
  });

  // ── 3. Découverte tab and pane render ────────────────────────────────────

  test('"Découverte" tab link and pane content are visible', async ({ page }) => {
    const settings = new SettingsPage(page);
    await settings.goto();

    await expect(settings.decouverteTab).toBeVisible({ timeout: 10000 });
    // The active pane should be visible immediately (it is the first tab).
    await expect(settings.decouvertePane).toBeVisible({ timeout: 10000 });
  });

  // ── 4. Save button present (NOT clicked) ─────────────────────────────────

  test('Save button "Enregistrer" is visible for admin user', async ({ page }) => {
    const settings = new SettingsPage(page);
    await settings.goto();

    // Button is rendered via @can('edit settings') — visible for admin/superadmin.
    await expect(settings.saveButton).toBeVisible({ timeout: 10000 });
  });

});

// <<<
