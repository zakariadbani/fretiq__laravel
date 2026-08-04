import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * SegmentPage — page object for the fretiq segments module.
 *
 * Table ID: `segment-table`
 *   Model: Segment → SegmentsDataTable::getEntityName() → 'segment' → '#segment-table'
 *
 * Form field names (from form.blade.php):
 *   name     (text, required)
 *   scope    (native <select>, required — NOT a Select2 data-control select)
 *
 * Optional filter fields (Select2 multiple — plain selectOption + dispatchEvent):
 *   filter[sector][]   (multiple select, data-control="select2")
 *   filter[country][]  (multiple select, data-control="select2")
 *   filter[status]     (plain select)
 *
 * Preview panel (AJAX, auto-triggered on scope change by segment-form.js):
 *   #segment_preview_card  — wrapper card (data-preview-url holds endpoint)
 *   #preview_final         — big count (shows '—' until a scope is selected)
 *   #preview_loading       — spinner (shown during AJAX call)
 *   #preview_summary       — summary sentence
 *   #preview_error         — error badge (d-none by default)
 *
 * Routes (all relative to baseURL):
 *   index   GET   /admin/segments
 *   create  GET   /admin/segments/create
 *   view    GET   /admin/segments/{id}
 *   edit    GET   /admin/segments/{id}/edit
 *   preview POST  /admin/segments/preview  (AJAX, throttle 60/min)
 */
export class SegmentPage extends DataTablePage {
  // ── Form field locators (create / edit) ──────────────────────────────────

  readonly nameInput: Locator;

  // scope is a native <select> (no data-control="select2") — use selectOption + dispatchEvent
  readonly scopeSelect: Locator;

  // Filter selects (optional, Select2 multiple — use selectOption + dispatchEvent)
  readonly sectorSelect: Locator;
  readonly countrySelect: Locator;
  readonly statusSelect: Locator;
  readonly dynamicModeRadio: Locator;
  readonly manualModeRadio: Locator;
  readonly manualCreateGuidance: Locator;
  readonly targetingFields: Locator;
  readonly saveButton: Locator;
  readonly contactsPane: Locator;
  readonly contactsHeading: Locator;
  readonly addContactsButton: Locator;
  readonly contactsWrapper: Locator;
  readonly contactsFragment: Locator;
  readonly contactsSkeleton: Locator;
  readonly contactsFailure: Locator;
  readonly contactsRetryButton: Locator;

  // Preview panel locators
  readonly previewCard: Locator;
  readonly previewFinal: Locator;
  readonly previewLoading: Locator;
  readonly previewSummary: Locator;
  readonly previewError: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'segment-table',         // SegmentsDataTable::getEntityName() → 'segment' → '#segment-table'
      searchSelector: '#mySearchInput', // shared id across all modules
      addButtonText: 'Ajouter',
    });

    // Form fields scoped to #form_crud
    this.nameInput   = page.locator('#form_crud input[name="name"]');
    this.scopeSelect = page.locator('#form_crud select[name="scope"]');

    // Filter multi-selects (optional)
    this.sectorSelect  = page.locator('#form_crud select[name="filter[sector][]"]');
    this.countrySelect = page.locator('#form_crud select[name="filter[country][]"]');
    this.statusSelect  = page.locator('#form_crud select[name="filter[status]"]');
    this.dynamicModeRadio = page.locator('[data-segment-mode="dynamic"]');
    this.manualModeRadio = page.locator('[data-segment-mode="manual"]');
    this.manualCreateGuidance = page.locator('[data-segment-manual-create-guidance]');
    this.targetingFields = page.locator('[data-segment-targeting-fields]');
    this.saveButton = page.locator('#form_crud button[name="save"]');
    this.contactsPane = page.locator('#segment_contacts[data-crud-pane]');
    this.contactsHeading = this.contactsPane.getByRole('heading', { name: 'Contacts' });
    this.addContactsButton = this.contactsPane.getByRole('button', { name: 'Ajouter des contacts à ce segment' });
    this.contactsWrapper = this.contactsPane.locator('#segment_contacts_wrapper');
    this.contactsFragment = this.contactsWrapper.locator('[data-contacts-count]');
    this.contactsSkeleton = this.contactsPane.locator('#segment_contacts_skeleton');
    this.contactsFailure = this.contactsPane.locator('[data-segment-contacts-failure]');
    this.contactsRetryButton = this.contactsPane.getByRole('button', { name: 'Réessayer le chargement des contacts' });

    // Preview panel
    this.previewCard    = page.locator('#segment_preview_card');
    this.previewFinal   = page.locator('#preview_final');
    this.previewLoading = page.locator('#preview_loading');
    this.previewSummary = page.locator('#preview_summary');
    this.previewError   = page.locator('#preview_error');
  }

  // ── Navigation ────────────────────────────────────────────────────────────

  async goto() {
    await this.page.goto('/admin/segments');
  }

  async gotoCreate() {
    await this.page.goto('/admin/segments/create');
  }

  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/segments/${id}/edit`);
  }

  async gotoView(id: number | string) {
    await this.page.goto(`/admin/segments/${id}`);
  }

  // ── Form helpers ──────────────────────────────────────────────────────────

  /**
   * Fill and submit the create/edit form.
   *
   * scope must be a valid key from config('global.data.segment_scopes'):
   *   'prospect' | 'client' | 'mixed'
   *
   * scope is a native <select> (not Select2 data-control) so we call
   * selectOption directly and dispatch 'change' to trigger the preview AJAX.
   */
  async fillAndSubmit(data: {
    name: string;
    scope: string;  // 'prospect' | 'client' | 'mixed'
  }) {
    await this.nameInput.fill(data.name);

    // scope: native select — selectOption + dispatchEvent to trigger segment-form.js preview
    await this.scopeSelect.selectOption({ value: data.scope });
    await this.scopeSelect.dispatchEvent('change');

    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Wait for the preview AJAX call to settle.
   *
   * segment-form.js debounces 400 ms then fires an AJAX POST.
   * During the call it removes 'd-none' from #preview_loading (spinner appears),
   * then re-adds it when done.  #preview_loading is always in the DOM but starts
   * hidden (d-none).
   *
   * Strategy:
   *   1. Wait for network idle — covers both the debounce delay and the round-trip.
   *      networkidle fires once all pending XHRs have settled (Playwright waits for
   *      500 ms of no network activity), which is sufficient here.
   *   2. Additionally assert the spinner is hidden, as a belt-and-suspenders check.
   */
  async waitForPreview() {
    // Wait for all pending network activity (AJAX POST) to settle.
    await this.page.waitForLoadState('networkidle');
    // Spinner must be hidden once the call is done.
    await expect(this.previewLoading).toBeHidden({ timeout: 5000 });
  }

  async expectContactsPaneIntersectingAndLoaded() {
    await expect.poll(async () => this.contactsPane.evaluate((pane) => {
      const rect = pane.getBoundingClientRect();
      return rect.bottom > 0 && rect.top < window.innerHeight;
    })).toBe(true);
    await expect(this.contactsSkeleton).toHaveCount(0, { timeout: 10000 });
  }

  // ── Row helpers ───────────────────────────────────────────────────────────

  /**
   * Assert exactly `count` DataTable rows contain `name` in their text.
   */
  async expectRowCountByName(name: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(count);
  }

  /**
   * Loop-delete all rows matching `name` until none remain.
   * Guards against any double-submit bug producing extra rows.
   */
  async deleteAllByName(
    name: string,
    helpers: {
      search: (q: string) => Promise<void>;
      waitForDataTable: (page: import('@playwright/test').Page, id: string) => Promise<void>;
      confirmDelete: (page: import('@playwright/test').Page) => Promise<void>;
    }
  ) {
    let found = true;
    while (found) {
      await helpers.search(name);
      await helpers.waitForDataTable(this.page, this.tableId);
      const matchingRows = this.table.locator(`tbody tr:has-text("${name}")`);
      const count = await matchingRows.count();
      if (count === 0) {
        found = false;
        break;
      }
      // Click delete on the first matching row
      await this.clickRowAction(0, 'delete');
      await helpers.confirmDelete(this.page);
      // Second SweetAlert: success dialog — SegmentsDataTable::getMessages() deleteSuccess
      await expect(this.page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
      await this.page.locator('.swal2-confirm').click();
      await this.page.waitForLoadState('networkidle');
      await helpers.waitForDataTable(this.page, this.tableId);
    }
  }
}
