# UX Confusion Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every major Fretiq workflow tell the truth about readiness, remain usable on a phone, recover clearly from empty/error states, and expose consistent French, accessible controls.

**Architecture:** Reuse the existing business authorities (`SegmentService`, `CampaignService::dispatchPreflight()`, `DiscoveryQuotaService`, scheduler settings, and current Zoho config). Fix repeated presentation defects in the existing shared CRUD/DataTable/layout components; keep workflow-specific behavior in the owning controller or Blade/JavaScript file. Do not introduce a new readiness service, design system, date picker, or monitoring layer.

**Tech Stack:** Laravel 11, Blade, Bootstrap 5/Metronic, jQuery, Yajra DataTables, Flatpickr, FullCalendar, Laravel Mix, PHPUnit, Playwright.

## Global Constraints

- Preserve the user's current dirty worktree. Inspect each target with `git diff -- <file>` before editing and merge with in-progress work.
- No database migration or seed command is required. Do not run `php artisan migrate` or `php artisan db:seed`.
- Do not commit, push, or open a PR without a separate explicit user request.
- Keep `PROSPECTING_COLD_SEND_ENABLED=false` outside production. Never weaken the cold-send or `send campaigns` permission gates.
- Do not add or probe a new Zoho endpoint. This plan only presents readiness facts already available locally; any new Zoho API behavior would require a live tinker verification and recorded `STATUS: 200 + summary`.
- Keep provider capacity separate from package entitlements and preserve the existing permissions around both.
- Use `php composer.phar` if Composer becomes necessary; no dependency change is planned.
- Run the named focused test after each behavior change. Run broader E2E only in the final verification task.

## User-visible readiness vocabulary

Use these distinctions consistently:

| Concept | Meaning shown to user | Authority |
|---|---|---|
| Correspondants | Contacts matching the saved segment before send-time exclusions | `SegmentService::resolveWithStats()` funnel |
| Destinataires éligibles | Contacts sendable now after suppressions, cold gate, personal-email rule, and deduplication | Final stage from `SegmentService` |
| Active | The campaign may be considered by automation; it does not mean it can send | `campaigns.is_active` |
| Prête à envoyer | All dispatch preflight checks pass | `CampaignService::dispatchPreflight()` |
| Automatisation opérationnelle | Laravel heartbeat and both campaign commands are fresh | Existing scheduler settings |
| Connexion configurée | A driver/config value exists; it is not proof that Zoho is usable | Existing Zoho config |

---

## Task 1: Establish the regression baseline and protect in-progress fixes

**Files:**

- Inspect: `resources/views/backend/contents/dashboard/index.blade.php`
- Inspect: `app/Http/Controllers/Backend/ProspectionDashboardController.php`
- Inspect: `resources/views/backend/contents/prospect_criteria/partials/_quota-strip.blade.php`
- Inspect: `app/Http/Controllers/Backend/ProspectCriteriaController.php`
- Test: `tests/Feature/Backend/DashboardGeneratedTest.php`
- Test: `tests/Feature/Backend/QuotaBadgeUiTest.php`

- [ ] Record the current changed-file baseline with `git status --short` and targeted `git diff --` calls. Do not reset any file.
- [ ] Confirm the canonical dashboard remains `GET /admin/dashboard` → `ProspectionDashboardController@index` → `backend.contents.dashboard.index`.
- [ ] Treat an already-removed prototype selector and an already-hidden unknown provider quota as completed only after the focused tests pass.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/DashboardGeneratedTest.php --filter=test_dashboard_renders_each_section_once_without_prototype_selector
php artisan test tests/Feature/Backend/QuotaBadgeUiTest.php
```

- [ ] If either test fails, repair only the existing intended implementation before starting later tasks.

**Acceptance:** The plan starts from a known green baseline, with no user edits discarded and no duplicate implementation of work already present.

## Task 2: Make segment counts and campaign audience readiness tell the same story

**Files:**

- Modify: `app/Http/Controllers/Backend/SegmentController.php`
- Modify: `app/Http/Controllers/Backend/CampaignController.php`
- Modify: `resources/views/backend/contents/segments/crud/form.blade.php`
- Modify: `resources/views/backend/contents/segments/crud/view.blade.php`
- Modify: `resources/views/backend/contents/campaigns/crud/form.blade.php`
- Modify only if needed: `app/Crud/ViewConfigs/CampaignViewConfig.php`
- Test: `tests/Feature/Backend/SegmentPreviewTest.php`
- Test: `tests/Feature/Backend/CampaignGeneratedTest.php`
- Test: `tests/Feature/Backend/CampaignDispatchPreflightTest.php`
- Test: `tests/e2e/specs/module-5-segments.spec.ts`
- Test: `tests/e2e/specs/module-3-campaigns.spec.ts`

- [ ] Keep `SegmentService::resolveWithStats()` as the single audience funnel and `CampaignService::dispatchPreflight()` as the single send authority. Do not duplicate cold-gate logic in a controller.
- [ ] Make the campaign segment-count endpoint return a truthful failure state instead of converting every exception to a believable zero. Preserve current keys used by the form and add only the smallest fields needed by the UI:

```php
return response()->json([
    'count' => $stats['final'] ?? 0,
    'contact_count' => $stats['final'] ?? 0,
    'company_count' => $companyCount,
    'contacts_count' => $stats['final'] ?? 0,
    'cold_gate_closed' => ! config('prospecting.cold_send_enabled'),
    'available' => true,
]);
```

On failure, return `available: false` and a safe French recovery message with a non-2xx status; do not expose exception text.

- [ ] Label raw/matched counts as “Correspondants” and final counts as “Destinataires éligibles”. Never display an unqualified “20 destinataires” when the final eligible count is zero.
- [ ] When the cold gate removes prospects, show a persistent warning explaining that client contacts remain eligible and cold prospects are excluded in this environment.
- [ ] Keep saving a segment/campaign possible when the audience is empty, but state that scheduling/sending will remain blocked by preflight. Do not imply that the “Active” field bypasses readiness.
- [ ] Reuse the resolved audience once within each controller request for contact and language counts; do not add caching or another service.
- [ ] Add assertions for the cold-gate warning, eligible count, and unavailable/error payload.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/SegmentFilterPipelineTest.php --filter=test_scope_prospect_with_cold_gate_closed_yields_zero_final
php artisan test tests/Feature/Backend/SegmentPinTest.php --filter=test_b2_contacts_count_matches_resolve_count_with_pins
php artisan test tests/Feature/Backend/SegmentPreviewTest.php --filter=test_cold_gate_closed_true_when_disabled
php artisan test tests/Feature/Backend/CampaignGeneratedTest.php --filter=test_segment_count_returns_count_for_known_segment
php artisan test tests/Feature/Backend/CampaignDispatchPreflightTest.php --filter=test_preview_returns_exact_eligible_count_after_compliance_exclusions
php artisan test tests/Feature/Backend/AudienceLanguageSplitTest.php --filter=test_mixed_countries_produce_correct_fr_en_unknown_counts
```

**Acceptance:** Segment, campaign builder, campaign detail, and dispatch preflight can differ only when their labels explain the different stage being counted; failures never masquerade as zero recipients.

## Task 3: Make the segment contacts preview load deterministically and recover from errors

**Files:**

- Modify: `resources/_keenthemes/src/js/custom/backend/segment-contacts.js`
- Modify only if event wiring is needed: `resources/_keenthemes/src/js/custom/backend/segment-form.js`
- Modify: `resources/views/backend/contents/segments/partials/_contacts-pane.blade.php`
- Modify only if returned content needs a state hook: `resources/views/backend/contents/segments/partials/_contacts-rows.blade.php`
- Test: `tests/Feature/Backend/SegmentContactsLiveFilterTest.php`
- Test: `tests/e2e/specs/module-5-segments.spec.ts`

- [ ] Reproduce the stuck state by entering a segment form/view where the contacts container is initially hidden or relocated into a tab.
- [ ] Preserve the existing `KTSegmentContacts.reload()` function, request cancellation, and server endpoint.
- [ ] Trigger the first fetch on the contacts tab's shown/activation event, with a single post-relocation visibility check as fallback. `IntersectionObserver` may remain an optimization, not the only trigger.
- [ ] Replace the permanent spinner/error combination with three mutually exclusive states: loading, loaded, or failed.
- [ ] On failure, retain the last successful count if one exists, state that the preview could not be refreshed, and provide a “Réessayer” button wired to `reload()`.
- [ ] Ensure repeated tab switches do not create duplicate requests or duplicate content.
- [ ] Add a Playwright scenario that opens the initially hidden tab, waits for the request, verifies the result, injects one failed request, and verifies retry recovery.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/SegmentContactsLiveFilterTest.php --filter=test_live_filter_fragment_carries_count_attribute
php artisan test tests/Feature/Backend/SegmentContactsLiveFilterTest.php --filter=test_saved_include_pin_applies_on_live_path
php artisan test tests/Feature/Backend/SegmentContactsLiveFilterTest.php --filter=test_saved_path_fragment_carries_correct_count
npx playwright test tests/e2e/specs/module-5-segments.spec.ts --grep "contacts preview"
```

**Acceptance:** Opening the contacts tab always ends in content or a retryable error; it never remains indefinitely on “Chargement…”.

## Task 4: Separate campaign activation, send readiness, and scheduler health

**Files:**

- Modify: `app/Http/Controllers/Backend/CampaignController.php`
- Modify: `resources/views/backend/contents/campaigns/partials/_scheduler-health.blade.php`
- Modify: `resources/views/backend/contents/campaigns/partials/_header-actions.blade.php`
- Modify: `resources/views/backend/contents/campaigns/crud/view.blade.php`
- Modify: `app/Crud/ViewConfigs/CampaignViewConfig.php`
- Test: `tests/Feature/Backend/CampaignSchedulerHealthTest.php`
- Test: `tests/Feature/Backend/CampaignDispatchPreflightTest.php`
- Test: `tests/Feature/Backend/CampaignSendAccessTest.php`

- [ ] Keep the existing scheduler settings and `CampaignController::schedulerHealth()`; do not create a monitoring service for one screen.
- [ ] Present both facts when they differ: global Laravel scheduler heartbeat and the freshness of `campaigns:generate-runs` / `campaigns:dispatch-due`.
- [ ] Show the expected next send in `Europe/Paris`, the last successful automation timestamp, and the reason an old timestamp is stale.
- [ ] Disable schedule/send operations when authoritative preflight or required command freshness fails. Preserve controller-side permission and preflight enforcement even when buttons are disabled.
- [ ] Replace duplicated activation controls with one status control. Group secondary operations such as test email and Zoho list synchronization under a plain “Actions” menu; retain one visually dominant next action.
- [ ] Rename technical labels for commercial users, for example “Préparer la liste d'envoi” with optional secondary detail “Synchronisation Zoho”.
- [ ] Keep history, audience, recipients, and progressive-wave concepts separate, but remove tabs that merely duplicate hero information. Drive the final tab list from `CampaignViewConfig`.
- [ ] Add test assertions for: active-but-not-ready, stale command heartbeat, fresh global heartbeat with stale campaign commands, and fully ready.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/CampaignSchedulerHealthTest.php --filter=test_index_hides_scheduler_warnings_while_view_renders_them_until_all_required_commands_are_fresh
php artisan test tests/Feature/Backend/ObservabilityOperationsTest.php --filter=test_scheduler_heartbeat_and_task_switches_are_reported
php artisan test tests/Feature/Backend/CampaignDispatchPreflightTest.php --filter=test_missing_zoho_list_key_blocks_send_without_run_or_queue
php artisan test tests/Feature/Backend/CampaignSendAccessTest.php
php artisan test tests/Feature/Backend/CampaignViewRecipientsTest.php
```

**Acceptance:** “Active” never reads as “ready”; stale automation cannot look operational; the next safe action is obvious and is enforced server-side.

## Task 5: Make Zoho status evidence-based without adding API calls

**Files:**

- Modify: `app/Http/Controllers/Backend/ZohoController.php`
- Modify: `resources/views/backend/contents/zoho/index.blade.php`
- Modify only for label maps: `config/global/data.php`
- Test: `tests/Feature/Backend/ZohoAccessTest.php`
- Test: `tests/Feature/Backend/ZohoGeneratedTest.php`
- Test: `tests/Feature/Backend/ZohoDriverSelectionTest.php`

- [ ] Replace the binary “Zoho actif” badge based solely on `services.zoho.driver` with separate rows for driver selection, CRM token presence/validity, last CRM sync, Campaigns topic/list prerequisites, and live-verification status documented by the driver.
- [ ] Use four plain statuses: “Non configuré”, “Configuration incomplète”, “Prêt pour test”, and “Opérationnel vérifié”. Never call a configuration value “active” when required OAuth or preflight facts are missing.
- [ ] Derive these statuses only from existing config, token/cache/log data, and the same prerequisites already checked by `dispatchPreflight()`.
- [ ] Normalize module and status names through `config/global/data.php`; do not add Blade condition chains for known module labels.
- [ ] Keep operational details visible to authorized admins while giving commercial users a short recovery instruction rather than raw API terms.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/ZohoAccessTest.php --filter=test_admin_can_view_zoho_status
php artisan test tests/Feature/Backend/ZohoDriverSelectionTest.php --filter=test_zoho_config_resolves_zoho_driver
php artisan test tests/Feature/Backend/CampaignDispatchPreflightTest.php --filter=test_missing_zoho_topic_id_blocks_send_without_run_or_queue
php artisan test tests/Feature/Backend/ZohoGeneratedTest.php
```

**Acceptance:** A missing token or prerequisite cannot coexist with an “operational” badge, and no new Zoho request is made.

## Task 6: Clarify package consumption versus provider capacity

**Files:**

- Modify: `resources/views/backend/contents/prospect_criteria/partials/_quota-strip.blade.php`
- Modify: `resources/views/backend/contents/consumption/index.blade.php`
- Modify only if the current payload is inaccurate: `app/Http/Controllers/Backend/ProspectCriteriaController.php`
- Test: `tests/Feature/Backend/QuotaBadgeUiTest.php`
- Test: `tests/Feature/Backend/ConsumptionPageTest.php`
- Test: `tests/Feature/Backend/ProviderQuotaPageTest.php`

- [ ] Keep `DiscoveryQuotaService` as the authority for client package usage and the provider quota page as the authority for external capacity.
- [ ] Label package meters “Quota Fretiq — aujourd'hui / ce mois” and provider balance “Capacité du fournisseur”, visible only where the current permission allows it.
- [ ] Hide an unknown provider balance (`null`); never render it as zero.
- [ ] If provider capacity is zero while package credits remain, show both values with a blocking explanation instead of collapsing them into one contradictory “crédits restants” number.
- [ ] Ensure the commercial consumption page continues to avoid provider names, provider pricing, and provider account capacity.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/QuotaBadgeUiTest.php
php artisan test tests/Feature/Backend/ConsumptionPageTest.php --filter=test_page_does_not_leak_price_or_capacity_labels
php artisan test tests/Feature/Backend/ConsumptionPageTest.php --filter=test_daily_series_is_dense_30_points_and_sums_match_used_on
php artisan test tests/Feature/Backend/ProviderQuotaPageTest.php --filter=test_hunter_quota_values_keep_provider_usage_separate_from_app_bundle_reservations
```

**Acceptance:** A user can tell whether discovery is blocked by their Fretiq entitlement or by an external provider, without leaking provider details to commercial roles.

## Task 7: Load the installed French Flatpickr locale

**Files:**

- Modify: `resources/mix/plugins.js`
- Verify: `resources/views/backend/contents/campaigns/crud/form.blade.php`
- Test: `tests/e2e/specs/module-3-campaigns.spec.ts`
- Test: `tests/Feature/Backend/CampaignSchedulerHealthTest.php`

- [ ] Add `flatpickr/dist/l10n/fr.js` beside the existing Flatpickr assets in the Mix vendor bundle. Do not add a package; Flatpickr is already installed.
- [ ] Keep the five existing `locale: 'fr'` initializers and Paris/UTC conversion logic unchanged.
- [ ] Build the existing Mix assets:

```powershell
npm run dev
```

- [ ] Add a browser assertion for French month/day labels and retain the PHP timezone round-trip assertion.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/CampaignSchedulerHealthTest.php --filter=test_controller_stores_paris_schedule_as_utc_and_edits_it_as_paris
npx playwright test tests/e2e/specs/module-3-campaigns.spec.ts --grep "French date picker"
```

**Acceptance:** No “invalid locale fr” console error appears, the picker is French, and stored schedule timestamps remain unchanged.

## Task 8: Fix mobile DataTable priorities once

**Files:**

- Modify: `app/DataTables/GlobalDataTable.php`
- Modify: `resources/_keenthemes/src/js/custom/datatables-utils.js`
- Test: `tests/Unit/CompaniesDataTableTest.php`
- Test: new focused unit test for `GlobalDataTable` default priorities
- Test: `tests/e2e/specs/datatables-length-control.spec.ts`
- Test helpers: `tests/e2e/pages/DataTablePage.ts`

- [ ] In `GlobalDataTable::getColumns()`, apply default responsive priorities only when a concrete DataTable has not set one: first meaningful business column = 1, status/active = 2, actions = 3, technical ID = lowest priority. Preserve explicit priorities such as those in `ProspectCriteriaDataTable`.
- [ ] At phone width, keep the company/campaign name and primary action visible. Campaign status should remain visible when space permits; audience/schedule must remain discoverable in the responsive child row.
- [ ] Ensure the responsive detail control has an accessible name and an expanded state.
- [ ] Extend the existing DataTable accessibility utility to hide cloned tables from assistive technology and keep only the interactive table focusable. Avoid per-module JavaScript.
- [ ] Add a unit assertion for default-versus-explicit priorities and Playwright assertions at 390×844 for companies and campaigns.
- [ ] Run:

```powershell
php artisan test tests/Unit/CompaniesDataTableTest.php
php artisan test tests/Unit/GlobalDataTableResponsivePriorityTest.php
npx playwright test tests/e2e/specs/datatables-length-control.spec.ts --grep "mobile"
```

**Acceptance:** Mobile lists identify the record before the ID/action, hidden campaign data can be expanded, and desktop column behavior is unchanged.

## Task 9: Make the campaign planner responsive

**Files:**

- Modify: `resources/views/backend/contents/planner/index.blade.php`
- Modify: `tests/e2e/pages/PlannerPage.ts`
- Modify: `tests/e2e/specs/module-17-planner.spec.ts`

- [ ] Replace fixed `initialView: 'timeGridWeek'` and `height: 800` behavior with viewport-aware configuration using FullCalendar's installed APIs.
- [ ] Use week view on desktop and day/list view on narrow screens. On resize, switch only when crossing the chosen breakpoint; do not recreate the calendar.
- [ ] Use `height: 'auto'` on mobile and keep “Aujourd'hui”, previous, and next controls reachable with explicit labels.
- [ ] Preserve Paris timezone display and existing event feed behavior.
- [ ] Add desktop and 390×844 assertions for non-overlapping headings, visible event title/time, and working previous/next/today navigation.
- [ ] Run:

```powershell
npx playwright test tests/e2e/specs/module-17-planner.spec.ts
```

**Acceptance:** No day headers or events overlap at phone width, and calendar navigation is understandable without icon interpretation.

## Task 10: Fix shared form actions, landmarks, headings, tabs, switches, and Select2

**Files:**

- Modify: `resources/views/layout/master.blade.php`
- Modify: `resources/views/layout/_default.blade.php`
- Modify: `resources/views/backend/elements/form-actions.blade.php`
- Modify: `resources/views/backend/partials/crud/_tabbar.blade.php`
- Modify: `resources/views/components/crud/status-bar.blade.php`
- Modify: `resources/_keenthemes/src/js/custom/backend/crud-tabs.js`
- Modify: `resources/_keenthemes/src/js/custom/datatables-utils.js`
- Modify local duplicate headings: `resources/views/backend/contents/dashboard/index.blade.php`
- Modify local duplicate headings: `resources/views/backend/contents/campaign_templates/crud/form.blade.php`
- Test: `tests/e2e/specs/accessibility-smoke.spec.ts`

- [ ] Add a keyboard-visible “Aller au contenu principal” link before backend chrome and change the sole backend content wrapper to `<main id="main-content">`.
- [ ] Keep the toolbar's single page `<h1>` and demote local visual duplicates to `<h2>`.
- [ ] Add `role="tablist"` to the shared tab list and keep `aria-selected`, `tabindex`, panel `hidden`, and panel ARIA state synchronized in `crud-tabs.js`.
- [ ] Give each shared status switch an accessible name derived from its visible label; replace technical visible copy such as “Modifier is_active” with domain language.
- [ ] Guard DataTable Select2 initialization with the existing `.data('select2')` state. Do not add a second initializer.
- [ ] Adjust the shared sticky form action bar for phone width: reserve its height, respect safe-area inset, avoid covering the final control/error, and allow buttons to wrap or stack.
- [ ] Add browser assertions for skip-link focus, one H1, tab keyboard state, named switches, one accessible Select2 instance, and unobscured final form fields at 390×844.
- [ ] Run:

```powershell
npx playwright test tests/e2e/specs/accessibility-smoke.spec.ts
```

**Acceptance:** A keyboard/screen-reader user reaches the main content, encounters one page heading, understands tabs and switches, and can submit a mobile form without controls being covered.

## Task 11: Replace generic empty states and expose inbox polling status

**Files:**

- Modify: `app/DataTables/Backend/DemandesDataTable.php`
- Modify: `app/DataTables/Backend/InboxEmailsDataTable.php`
- Modify: `app/DataTables/Backend/SuppressionsDataTable.php`
- Modify: `app/Http/Controllers/Backend/InboxEmailController.php`
- Modify: `resources/views/backend/contents/inbox/crud/index.blade.php`
- Test: `tests/Feature/Backend/InboxModuleTest.php`
- Test: relevant demande and suppression feature tests

- [ ] Configure module-specific DataTables `language.emptyTable` content in each owning DataTable; do not build a new generic empty-state framework.
- [ ] Distinguish “no records exist” from “no records match this filter”.
- [ ] Give each empty state one recovery route: create/import a demande where permitted, configure/poll inbox for inbox, and add a suppression where permitted.
- [ ] For inbox, aggregate active IMAP sender identities and their `last_polled_at` values in `InboxEmailController@index`. Do not infer poll health from message `received_at`.
- [ ] Show “Dernière relève réussie”, “Relève jamais effectuée”, or “Aucune boîte active”, with a link to sender identity configuration for authorized users.
- [ ] Ensure empty-state actions obey existing permissions.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/InboxModuleTest.php --filter=test_index_and_detail_require_view_permission
php artisan test tests/Feature/Backend/InboxModuleTest.php --filter=test_datatable_is_latest_first
php artisan test tests/Feature/Backend/InboxModuleTest.php --filter=test_index_shows_last_successful_poll_status
```

**Acceptance:** Empty pages explain why they are empty and what to do; inbox health reflects polling, not the date of the last email.

## Task 12: Remove workflow dead ends and development artifacts

**Files:**

- Modify: `app/Crud/ViewConfigs/CompanyViewConfig.php`
- Modify: `resources/views/backend/contents/companies/partials/_contacts-tab.blade.php`
- Modify: `resources/views/backend/contents/sequences/crud/form.blade.php`
- Modify: `resources/views/backend/contents/sequences/crud/view.blade.php`
- Modify selectors: `app/Http/Controllers/Backend/CampaignController.php`
- Modify selector: `app/Http/Controllers/Backend/SequenceController.php`
- Modify listings: `app/DataTables/Backend/CampaignTemplatesDataTable.php`
- Modify listings: `app/DataTables/Backend/SequencesDataTable.php`
- Modify: `app/DataTables/Backend/CampaignsDataTable.php`
- Test: `tests/Feature/Backend/CompanyEnrichmentUiTest.php`
- Test: `tests/Feature/Backend/SequenceGeneratedTest.php`
- Test: campaign CRUD tests

- [ ] Hide or disable “Lancer une campagne” for a company with zero contacts and make the existing “Récupérer les contacts” action the primary recovery route.
- [ ] Preserve clear explanations when enrichment is unavailable because a domain or social-domain prerequisite is missing.
- [ ] Replace `(sujet du modèle)` with the actual fallback subject from the already eager-loaded template:

```blade
{{ $step->subject ?? $step->template?->subject ?? 'Sans sujet' }}
```

Do not add a model accessor unless a third real caller appears.

- [ ] Exclude records whose display name starts with the exact `E2E_FIXTURE ` prefix from every user-facing campaign/template/sequence selector and listing. Use direct query predicates in the existing queries; do not introduce a trait or global scope.
- [ ] Keep fixtures addressable by ID for E2E setup/operations and keep the existing purge lifecycle. Never delete them from a normal UI request.
- [ ] Add rendered-view assertions for zero-contact CTA, resolved template subject, and absence of fixture labels.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/CompanyEnrichmentUiTest.php
php artisan test tests/Feature/Backend/SequenceGeneratedTest.php --filter=test_update_step_can_clear_subject_to_null
php artisan test tests/Feature/Backend/SequenceGeneratedTest.php --filter=test_view_resolves_template_subject_when_step_subject_is_null
php artisan test tests/Feature/Backend/CampaignCrudTest.php --filter=test_campaign_selectors_hide_e2e_fixtures
```

**Acceptance:** Users cannot launch an impossible company campaign, see the real subject that will be used, or encounter E2E fixtures in normal choices.

## Task 13: Remove the dashboard prototype leak and align product copy

**Files:**

- Modify: `resources/views/layout/partials/header-layout/header/_menu/_menu.blade.php`
- Delete only after reference check: `resources/views/layout/partials/header-layout/header/_menu/__dashboards.blade.php`
- Inspect/delete only if truly unreachable: `app/Http/Controllers/DashboardController.php`
- Inspect/delete only if truly unreachable: `resources/views/pages/dashboards/index.blade.php`
- Modify: `resources/landing/index.html`
- Modify: `resources/views/pages/auth/login.blade.php`
- Modify: `resources/views/layout/_auth.blade.php`
- Test: `tests/Feature/Backend/DashboardGeneratedTest.php`
- Test: add focused public/auth copy assertions to the closest authentication feature test

- [ ] Remove the Metronic prototype dashboard chooser from the header menu. Keep `/dashboard` redirecting to the canonical admin dashboard.
- [ ] Before deleting legacy controller/view files, verify with `rg` that no route, include, or test references them. If any reference remains, leave the files and only remove the exposed chooser.
- [ ] Use one product sentence across landing and authentication surfaces: Fretiq helps TCL France find prospects and run compliant freight-prospecting email campaigns. Remove “hologramme”/prototype language and capabilities the app does not provide.
- [ ] Keep copy in French and avoid internal terms such as driver, cron, endpoint, fixture, and preflight in primary user text.
- [ ] Run:

```powershell
php artisan test tests/Feature/Backend/DashboardGeneratedTest.php --filter=test_dashboard_renders_each_section_once_without_prototype_selector
php artisan test tests/Feature/NavbarAuthenticationTest.php
```

**Acceptance:** There is one real dashboard and one consistent explanation of what Fretiq does.

## Task 14: Final role, viewport, console, and regression verification

**Files:**

- Modify only failing relevant specs under: `tests/e2e/specs/`
- Report results in: implementation handoff/task notes, not a new runtime feature

- [ ] Run the smallest affected PHP test files after all tasks are integrated:

```powershell
php artisan test tests/Feature/Backend/SegmentPreviewTest.php
php artisan test tests/Feature/Backend/CampaignDispatchPreflightTest.php
php artisan test tests/Feature/Backend/CampaignSchedulerHealthTest.php
php artisan test tests/Feature/Backend/ZohoGeneratedTest.php
php artisan test tests/Feature/Backend/ConsumptionPageTest.php
php artisan test tests/Feature/Backend/InboxModuleTest.php
php artisan test tests/Feature/Backend/DashboardGeneratedTest.php
```

- [ ] Build once with `npm run dev`, then use browser control/Playwright against `http://fretiq.test` with the documented local accounts from `../memory/credentials.md`.
- [ ] Walk the major paths as `commercial`: dashboard → companies → zero-contact recovery → contacts → segments create/preview → sequences → campaign create/detail → planner → inbox → suppressions → consumption.
- [ ] Walk admin-only paths as `admin` or `superadmin`: Zoho status, sender identities, provider quota, and relevant configuration links.
- [ ] Repeat core list, form, campaign detail, and planner paths at 1440×900 and 390×844.
- [ ] Verify no uncaught console error, failed first-party request, duplicate visible Select2/table, overlapping sticky action bar, duplicate H1, or indefinite loading state.
- [ ] Verify destructive actions still require existing confirmation, operational actions still require permissions, and cold sending remains blocked.
- [ ] Review `git diff --check` and `git diff --stat`; inspect the diff for unrelated changes and accidental compiled/vendor files.
- [ ] Record pass/fail evidence by path and viewport, plus any intentionally deferred issue with a concrete trigger for revisiting it.

**Acceptance:** Every audited path has desktop/mobile evidence, role gates remain intact, and the implementation introduces no migrations, new dependencies, new Zoho calls, or unrelated cleanup.

## Delivery order and release slices

1. **Trust first:** Tasks 2–7. Ship together because counts, send readiness, scheduler, Zoho, quota, and date localization form one operational truth layer.
2. **Responsive/accessibility foundation:** Tasks 8–10. These are shared-component changes and should land before module-specific empty-state verification.
3. **Workflow clarity:** Tasks 11–13. These are independently reversible UI/copy changes.
4. **Release gate:** Task 14. Do not call the UX audit fixed until both roles and both target viewports have been walked.

## Explicitly out of scope

- Rewriting `SegmentController::contacts()` into database-level pagination; measure it separately if large segments become slow.
- A new design system or replacement for Metronic/Bootstrap.
- A new readiness/monitoring service.
- Changing package accounting or provider pricing.
- Enabling cold sends.
- Implementing or empirically verifying a new Zoho endpoint.
- Replacing DataTables, Flatpickr, FullCalendar, or Select2.
- Broad cleanup of unused Metronic templates unrelated to an exposed user path.
