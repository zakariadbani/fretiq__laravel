import { Page, Locator, expect } from '@playwright/test';
import { DataTablePage } from './DataTablePage';

/**
 * CampaignPage — page object for the fretiq campaigns module.
 *
 * Table ID: `campaign-table`
 *   Model: Campaign → getName() → 'campaign' → html() builder → 'campaign-table'
 *
 * Form field names (from form.blade.php):
 *   name (required), sender_identity_id (Select2 required), segment_id (Select2 required),
 *   template_id (Select2), schedule_type (Select2, default one_shot)
 *
 * AJAX endpoints triggered from the form:
 *   GET  /campaigns/segment-count/{id}       → updates #segment-count-label
 *   POST /campaigns/audience-language-split  → updates #audience-lang-split / #audience-lang-chips
 *
 * Routes (all relative to baseURL):
 *   index   GET  /admin/campaigns
 *   create  GET  /admin/campaigns/create
 *   view    GET  /admin/campaigns/{id}
 *   edit    GET  /admin/campaigns/{id}/edit
 *
 * Destructive action buttons on the view page (assert presence only — never click):
 *   #btn-send-now  — dispatches SendCampaignJob (real email send via local driver)
 *
 * Safe action buttons on the view page:
 *   #btn-schedule  — sets campaign status to active/scheduled (local state mutation only)
 */
export class CampaignPage extends DataTablePage {
  // ── Form field locators (create / edit) ─────────────────────────────────────

  readonly nameInput: Locator;

  // Select2 underlying native <select> elements
  // (for selectOption + dispatchEvent('change') pattern)
  readonly segmentIdSelect: Locator;
  readonly templateIdSelect: Locator;
  readonly senderIdentityIdSelect: Locator;
  readonly scheduleTypeSelect: Locator;

  // AJAX-populated UI elements (read-only assertions)
  readonly segmentCountLabel: Locator;
  readonly audienceLangSplit: Locator;
  readonly audienceLangChips: Locator;

  // View-page action buttons
  readonly sendNowButton: Locator;
  readonly scheduleButton: Locator;

  constructor(page: Page) {
    super(page, {
      tableId: 'campaign-table',        // Campaign.getName() → 'campaign' → '#campaign-table'
      searchSelector: '#mySearchInput', // shared id across all modules
      addButtonText: 'Ajouter',
    });

    // Form fields scoped to #form_crud to avoid Metronic demo modal collision
    this.nameInput = page.locator('#form_crud input[name="name"]');

    // Native <select> elements for Select2 — set value + dispatchEvent('change')
    this.segmentIdSelect         = page.locator('#form_crud select[name="segment_id"]');
    this.templateIdSelect        = page.locator('#form_crud select[name="template_id"]');
    this.senderIdentityIdSelect  = page.locator('#form_crud select[name="sender_identity_id"]');
    this.scheduleTypeSelect      = page.locator('#form_crud select[name="schedule_type"]');

    // AJAX-populated display elements
    this.segmentCountLabel  = page.locator('#segment-count-label');
    this.audienceLangSplit  = page.locator('#audience-lang-split');
    this.audienceLangChips  = page.locator('#audience-lang-chips');

    // View-page action buttons (do NOT click sendNowButton in tests)
    this.sendNowButton  = page.locator('#btn-send-now');
    this.scheduleButton = page.locator('#btn-schedule');
  }

  /** Navigate to the campaigns index page. */
  async goto() {
    await this.page.goto('/admin/campaigns');
  }

  /** Navigate to the create form. */
  async gotoCreate() {
    await this.page.goto('/admin/campaigns/create');
  }

  /** Navigate to the edit form for a known id. */
  async gotoEdit(id: number | string) {
    await this.page.goto(`/admin/campaigns/${id}/edit`);
  }

  /** Navigate to the view (detail) page for a known id. */
  async gotoView(id: number | string) {
    await this.page.goto(`/admin/campaigns/${id}`);
  }

  /**
   * Fill and submit the create/edit form for a one_shot campaign.
   *
   * All three Select2 fields use the native-select pattern:
   *   selectOption({ label }) + dispatchEvent('change')
   *
   * @param data.name               Campaign name (required)
   * @param data.segmentLabel       Visible label of the segment option in the Select2
   * @param data.templateLabel      Visible label of the template option in the Select2
   * @param data.senderLabel        Visible label of the sender identity option (name <email>)
   * @param data.scheduleType       One of the schedule_type option values (default 'one_shot')
   */
  async fillAndSubmit(data: {
    name: string;
    segmentLabel: string;
    templateLabel: string;
    senderLabel: string;
    scheduleType?: string;
  }) {
    await this.nameInput.fill(data.name);

    // sender_identity_id Select2 — set native select + dispatch change
    await this.senderIdentityIdSelect.selectOption({ label: data.senderLabel });
    await this.senderIdentityIdSelect.dispatchEvent('change');

    // segment_id Select2
    await this.segmentIdSelect.selectOption({ label: data.segmentLabel });
    await this.segmentIdSelect.dispatchEvent('change');

    // template_id Select2
    await this.templateIdSelect.selectOption({ label: data.templateLabel });
    await this.templateIdSelect.dispatchEvent('change');

    // schedule_type Select2 (default 'one_shot' is pre-selected; still set explicitly)
    const scheduleType = data.scheduleType ?? 'one_shot';
    await this.scheduleTypeSelect.selectOption({ value: scheduleType });
    await this.scheduleTypeSelect.dispatchEvent('change');

    // ponytail: create relies on the index search + retrying toBeVisible assertion to confirm persistence — campaign create form has a known double-submit bug that makes a strict response-wait unreliable.
    await this.page.locator('#form_crud button[name="save"]').click();
  }

  /**
   * Assert exactly `count` DataTable rows contain `name` in their text.
   */
  async expectRowCountByName(name: string, count: number) {
    await expect(this.table.locator(`tbody tr:has-text("${name}")`)).toHaveCount(count);
  }

  /**
   * Loop-delete all rows matching `name` until none remain.
   * Guards against the known double-submit bug (two rows created per save).
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
      // Second SweetAlert: success dialog after DELETE
      await expect(this.page.locator('.swal2-popup')).toContainText('supprimé', { timeout: 10000 });
      await this.page.locator('.swal2-confirm').click();
      await this.page.waitForLoadState('networkidle');
      await helpers.waitForDataTable(this.page, this.tableId);
    }
  }
}
