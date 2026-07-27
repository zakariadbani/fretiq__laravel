// IMPORTANT: import test/expect from the console-guard fixture (auto-fails on console.error/pageerror). Do NOT revert to '@playwright/test' — that silently disables the guard.
import { test, expect } from '../fixtures/console-guard';
import { PlannerPage } from '../pages/PlannerPage';
import { expectPath } from '../helpers/test-utils';

// >>> custom-test-author:planner-e2e

/**
 * module-17-planner — campaign calendar / planning screen read-only e2e suite.
 *
 * Read-only: asserts the FullCalendar container renders and the JSON feed
 * endpoint is reachable. No calendar interactions, no mutations.
 *
 * Stable selectors used:
 *   h2.card-title with text "Planning des campagnes" — card title
 *   #kt_calendar_app                                 — FullCalendar mount point (always in DOM)
 *
 * Feed endpoint: GET /admin/planner/feed?start=<ISO>&end=<ISO>
 * Expected response: HTTP 200, Content-Type application/json, body is a JSON array.
 * Each event shape (when data exists):
 *   { id, title, start, color, url, extendedProps: { status, statusLabel, statusColor } }
 *
 * Gate: permission:view campaigns — commercial, admin, superadmin all have this.
 */

test.describe('Planner module', () => {

  // ── 1. Path is /admin/planner ─────────────────────────────────────────────

  test('planner page loads at /admin/planner', async ({ page }) => {
    const planner = new PlannerPage(page);
    await planner.goto();

    await expectPath(page, '/admin/planner');
  });

  // ── 2. Card title renders ────────────────────────────────────────────────

  test('configured-timezone clock is visible', async ({ page }) => {
    const planner = new PlannerPage(page);
    await planner.goto();

    await expect(planner.currentTime).toBeVisible({ timeout: 10000 });
  });

  // ── 3. FullCalendar container is in the DOM ───────────────────────────────

  test('FullCalendar container #kt_calendar_app is present in the DOM', async ({ page }) => {
    const planner = new PlannerPage(page);
    await planner.goto();

    // Assert attached — FullCalendar may not have finished injecting its grid yet,
    // but the mount div from the Blade template must be in the DOM immediately.
    await planner.calendarContainer.waitFor({ state: 'attached', timeout: 10000 });
  });

  // ── 4. Feed endpoint returns a JSON array ────────────────────────────────

  test('feed endpoint GET /admin/planner/feed returns HTTP 200 and a JSON array', async ({ page }) => {
    // Use a 1-month window — FullCalendar sends start/end as ISO 8601 strings.
    const start = '2026-06-01';
    const end   = '2026-07-01';

    const res = await page.request.get(
      `/admin/planner/feed?start=${start}&end=${end}`,
    );

    expect(res.ok()).toBeTruthy();

    const body = await res.json();
    // The feed always returns an array (may be empty on a fresh DB).
    expect(Array.isArray(body)).toBe(true);
  });

});

// <<<
