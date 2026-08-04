# Campaign Recipient Step Statistics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show campaign totals and, for sequence campaigns, a truthful per-email × per-step status matrix, while retaining one permission-gated campaign button that queues synchronization for every recent Zoho-backed execution.

**Architecture:** Keep `CampaignRun` as the step/execution authority and `CampaignRecipient` as the per-email event authority. Reuse the current recipient pagination and load matrix cells for only the 50 contacts on the visible page; do not duplicate recipient feedback into `sequence_step_sends`. Keep the existing POST sync action and two jobs, centralizing their repeated 30-day eligibility query in one model scope.

**Tech Stack:** Laravel 11, PHP 8.3, Eloquent, Blade, Bootstrap 5/Metronic, PHPUnit 10, Playwright/Chromium.

## Global Constraints

- Preserve the current dirty worktree. Inspect `git diff -- <target>` before every edit and merge with existing dashboard, inbox, test-send, and Zoho statistics work.
- No new database table, migration, package, cache, webhook, or JavaScript framework.
- No real email, campaign send, external client contact, or production action. Zoho HTTP remains faked in automated tests.
- Do not add a Zoho endpoint or unverified action. `campaignreports`, `sentcontacts`, `openedcontacts`, `clickedcontacts`, `unsentcontacts`, `optoutcontacts`, and `spamcontacts` were empirically verified on 2026-08-02.
- The sync control remains protected by `send campaigns`; `view campaigns` may see synchronization state and report data but cannot trigger synchronization.
- Keep one sync button only. It queues statistics jobs; it must never send or schedule a campaign.
- Do not label an individual email as “delivered/received.” Zoho exposes delivery and bounce only as aggregate campaign counts. Use “Envoyé — non ouvert” unless an individual open, click, unsent, unsubscribe, or complaint event is known.
- Keep non-sequence campaigns and explicit `?run_id=` recipient views working as they do today.
- Do not stage, commit, push, deploy, or open a PR.

## Truthful Cell Vocabulary

| Stored evidence | Cell label |
|---|---|
| No recipient row for that step | Pas encore planifié |
| `status=queued` | En attente |
| `status=skipped`, `skip_reason=zoho_unsent` | Non envoyé par Zoho — adresse invalide ou refusée |
| `sent_at` set, no `opened_at` | Envoyé — non ouvert |
| `opened_at` set | Ouvert le {date} |
| `clicked_at` set | Cliqué le {date} |
| `replied_at` or `status=replied` | Répondu |
| `status=unsubscribed` | Désinscrit |
| `status=bounced` | Rebond détecté |

Aggregate step headers may show delivered and bounced totals because those values come from Zoho’s campaign report. Individual cells must not infer them.

---

### Task 1: Centralize sync eligibility and keep exactly one campaign sync button

**Files:**

- Modify: `app/Models/CampaignRun.php`
- Modify: `app/Console/Commands/CampaignSyncStats.php`
- Modify: `app/Http/Controllers/Backend/CampaignController.php`
- Modify: `resources/views/backend/contents/campaigns/crud/view.blade.php`
- Test: `tests/Feature/Backend/CampaignSendAccessTest.php`
- Test: `tests/Feature/Backend/SyncCampaignStatsTest.php`

**Interfaces:**

- Produces: `CampaignRun::scopeEligibleForStatsSync(Builder $query, ?CarbonInterface $cutoff = null): Builder`
- Produces view data: `$latestSyncableZohoRun` and `$syncableZohoRunCount`
- Preserves route: `POST admin.campaigns.syncStats`

- [ ] **Step 1: Add failing eligibility and button tests.**

Add assertions that an admin sees exactly one `data-campaign-stats-sync` control when the campaign has a sent Zoho run inside the 30-day window, even when another newer execution is non-Zoho. Assert that view-only users see synchronization state but no button, and that old/non-Zoho/non-sent runs are not queued.

```php
$response->assertOk();
$response->assertSee('data-campaign-stats-sync', false);
$this->assertSame(1, substr_count($response->getContent(), 'data-campaign-stats-sync'));

Queue::assertPushed(SyncCampaignStatsJob::class, 1);
Queue::assertPushed(SyncCampaignRecipientEventsJob::class, 1);
```

- [ ] **Step 2: Run the focused tests and confirm the new cases fail.**

```powershell
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/CampaignSendAccessTest.php --filter="(manual_stats_sync|sync_state|sync_button)" --colors=never
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/SyncCampaignStatsTest.php --filter="last_thirty_days" --colors=never
```

- [ ] **Step 3: Add the shared model scope and replace both duplicated queries.**

```php
public function scopeEligibleForStatsSync(Builder $query, ?CarbonInterface $cutoff = null): Builder
{
    $cutoff ??= now()->subDays(30);

    return $query
        ->where('status', 'sent')
        ->where(function (Builder $recent) use ($cutoff): void {
            $recent->where('finished_at', '>=', $cutoff)
                ->orWhere(function (Builder $unfinished) use ($cutoff): void {
                    $unfinished->whereNull('finished_at')->where('run_at', '>=', $cutoff);
                });
        });
}
```

Use the scope in `CampaignSyncStats::handle()` and `CampaignController::syncStats()`. Keep `--zoho-only` and `--local-only` behavior unchanged.

- [ ] **Step 4: Drive the campaign control from eligible Zoho runs, not merely `$latestRun`.**

Query the campaign relation through the new scope, pass the latest eligible Zoho run and count to the view, and retain the current form action and CSRF token. Add `data-campaign-stats-sync` to the single button. The success message must state that synchronization was queued for N executions.

- [ ] **Step 5: Re-run the focused tests.**

Expected: the button appears once for authorized users, forbidden users cannot POST, and both jobs are queued once per eligible Zoho run.

---

### Task 2: Synchronize evidence that Zoho sent each individual email

**Files:**

- Modify: `app/Jobs/SyncCampaignRecipientEventsJob.php`
- Modify: `app/Services/Campaign/CampaignFeedbackService.php`
- Test: `tests/Feature/Backend/CampaignFeedbackTest.php`
- Test: `tests/Feature/Backend/SyncCampaignStatsTest.php`

**Interfaces:**

- Extends: `CampaignFeedbackService::apply(CampaignRecipient $recipient, string $outcome, ?CarbonInterface $occurredAt = null, string $detail = '', string $source = 'local'): void`
- Adds supported outcome: `sent`
- Adds job mapping: `sentcontacts => sent`

- [ ] **Step 1: Write failing tests for sent evidence and status precedence.**

Cover these cases:

```php
$service->apply($recipient, 'sent', $sentAt, source: 'zoho');
$this->assertSame('sent', $recipient->refresh()->status);
$this->assertTrue($recipient->sent_at->equalTo($sentAt));

$opened->update(['status' => 'opened', 'opened_at' => now()]);
$service->apply($opened, 'sent', $sentAt, source: 'zoho');
$this->assertSame('opened', $opened->refresh()->status);
```

Also fake `sentcontacts` followed by `unsentcontacts` and assert that the final state is `skipped/zoho_unsent`; unsent evidence must win.

- [ ] **Step 2: Run the two named tests and verify failure before implementation.**

```powershell
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/CampaignFeedbackTest.php --filter="sent_feedback" --colors=never
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/SyncCampaignStatsTest.php --filter="recipient_event_sync" --colors=never
```

- [ ] **Step 3: Add the smallest sent branch to the existing feedback service.**

```php
if ($outcome === 'sent') {
    $attributes = ['sent_at' => $recipient->sent_at ?? $occurredAt ?? now()];
    if ($recipient->status === 'queued') {
        $attributes['status'] = 'sent';
    }
    $recipient->update($attributes);

    return;
}
```

Add `sent` to the service’s outcome whitelist. Do not overwrite opened, clicked, replied, unsubscribed, bounced, or skipped terminal evidence.

- [ ] **Step 4: Add `sentcontacts` first in `ACTION_OUTCOMES`.**

```php
private const ACTION_OUTCOMES = [
    'sentcontacts' => 'sent',
    'openedcontacts' => 'opened',
    'clickedcontacts' => 'clicked',
    'optoutcontacts' => 'unsubscribe',
    'unsentcontacts' => 'unsent',
    'spamcontacts' => 'complaint',
];
```

Reuse the current `sent_time`/`sentdate` parser. Do not add an endpoint or response field.

- [ ] **Step 5: Re-run both focused files.**

```powershell
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/CampaignFeedbackTest.php tests/Feature/Backend/SyncCampaignStatsTest.php tests/Feature/Backend/SyncCampaignRecipientPaginationTest.php --colors=never
```

---

### Task 3: Build the paginated per-recipient × per-step report payload

**Files:**

- Modify: `app/Http/Controllers/Backend/CampaignController.php`
- Test: `tests/Feature/Backend/CampaignViewRecipientsTest.php`

**Interfaces:**

- Produces private method:

```php
private function recipientStepReport(
    Campaign $campaign,
    Collection $executedRuns,
    LengthAwarePaginator $recipients,
): ?array
```

- Return shape:

```php
[
    'steps' => Collection<SequenceStep>,
    'summaries' => array<int, array<string, int|float|null>>,
    'cells' => array<int, array<int, CampaignRecipient>>,
]
```

- [ ] **Step 1: Add failing rendering-data tests for a two-step sequence.**

Create fake runs with distinct `sequence_step_id` values and recipients where:

- Email A: step 1 opened; step 2 sent with no open.
- Email B: step 1 skipped with `zoho_unsent`; no step 2 row.

Assert the response contains step headings and unambiguous cell hooks:

```php
$response->assertSee('data-recipient-step-matrix', false);
$response->assertSee('data-step="1"', false);
$response->assertSee('data-step="2"', false);
$response->assertSee('Envoyé — non ouvert');
$response->assertSee('Pas encore planifié');
```

Also assert a one-shot campaign still uses the current rollup and an explicit `?run_id=` still shows only that run.

- [ ] **Step 2: Run only the new recipient-matrix tests and confirm failure.**

```powershell
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/CampaignViewRecipientsTest.php --filter="step_matrix" --colors=never
```

- [ ] **Step 3: Eager-load ordered sequence steps and construct the report only when needed.**

Change the campaign load from `sequence` to `sequence.steps`. Return `null` unless the campaign is a sequence and no explicit run scope is active.

Reuse the existing 50-contact paginator. Fetch all `CampaignRecipient` rows for only those page contact IDs and only executed runs with a non-null `sequence_step_id`, eager-loading `run.sequenceStep`. Group by `contact_id`, then by `sequence_step_id`; use the highest recipient id defensively if legacy duplicates exist.

- [ ] **Step 4: Compute step summaries from existing run KPIs.**

For every sequence step, sum the `kpis()` values of executed runs tied to that step: sent, delivered, opened, clicked, replied, bounced, and unsubscribed. These are attempt totals across waves; label them as such. Do not recalculate provider aggregates from the current 50-row page.

- [ ] **Step 5: Pass `$recipientStepReport` to the existing campaign detail view and re-run the focused tests.**

No new public controller route or service class is needed.

---

### Task 4: Render the matrix and truthful step/run labels

**Files:**

- Modify: `resources/views/backend/contents/campaigns/partials/_destinataires-tab.blade.php`
- Create: `resources/views/backend/contents/campaigns/partials/_recipient-step-cell.blade.php`
- Modify: `resources/views/backend/contents/campaigns/partials/_historique-tab.blade.php`
- Modify: `resources/views/backend/contents/campaigns/crud/view.blade.php`
- Test: `tests/Feature/Backend/CampaignViewRecipientsTest.php`
- Test: `tests/Feature/Backend/CampaignSendAccessTest.php`

**Interfaces:**

- Consumes: `$recipientStepReport`
- Adds stable UI hooks: `data-recipient-step-matrix`, `data-recipient-email`, `data-step`, `data-step-status`

- [ ] **Step 1: Add the matrix branch without deleting existing views.**

Render the matrix only for sequence campaigns in rollup scope. Keep the current contact rollup for non-sequence campaigns and the current raw table for `?run_id=`.

- [ ] **Step 2: Render one contact row and one cell per ordered sequence step.**

The first column shows email and company. Each step header shows subject plus compact totals: sent, delivered, opened, clicked, bounced. Each cell uses the vocabulary in this plan and includes event timestamps.

Use the cell priority below so later evidence is not hidden:

```php
$cellStatus = match (true) {
    $recipient === null => 'not_planned',
    $recipient->status === 'unsubscribed' => 'unsubscribed',
    $recipient->status === 'bounced' => 'bounced',
    $recipient->status === 'skipped' => 'skipped',
    $recipient->replied_at !== null || $recipient->status === 'replied' => 'replied',
    $recipient->clicked_at !== null => 'clicked',
    $recipient->opened_at !== null => 'opened',
    $recipient->sent_at !== null => 'sent_not_opened',
    default => 'queued',
};
```

- [ ] **Step 3: Preserve search, pagination, status chips, and replied action.**

The existing paginator remains the row source, so email/company search and page navigation keep working. The replied action targets the latest recipient row as today. Existing chip filtering remains contact-level; document visually that it filters contacts with matching historical evidence, not one selected step.

- [ ] **Step 4: Make the table safe on desktop and 390×844 mobile.**

Keep horizontal scrolling inside `.table-responsive`, give the matrix a content-based minimum width, and make the contact column sticky without causing page-level overflow. Do not hide step cells on mobile. Ensure badge text wraps and focus states remain visible.

- [ ] **Step 5: Label history rows with their sequence step.**

Add an “Étape” column in `_historique-tab.blade.php`, showing `Étape N — {subject}` for step runs and “Campagne” for ordinary runs. Keep “Voir destinataires” linking to the exact run.

- [ ] **Step 6: Keep the existing synchronization state and one button.**

Use `$latestSyncableZohoRun` for “Dernière synchronisation Zoho” and error display. The button text remains “Synchroniser les statistiques”; add helper text explaining that it refreshes all recent campaign steps and may require a page reload after the queue finishes.

- [ ] **Step 7: Run the campaign view and access tests.**

```powershell
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/CampaignViewRecipientsTest.php tests/Feature/Backend/CampaignSendAccessTest.php --colors=never
```

---

### Task 5: Add deterministic browser fixtures and human-style QA

**Files:**

- Modify: `app/Console/Commands/E2eSeed.php`
- Modify: `app/Console/Commands/E2ePurge.php`
- Modify: `tests/e2e/pages/CampaignPage.ts`
- Modify: `tests/e2e/specs/module-3-campaigns.spec.ts`

**Interfaces:**

- Adds local-only fixture: `E2E_FIXTURE Sequence Statistics Campaign`
- Adds page-object locators for the matrix, sync button, step cells, and recipients tab

- [ ] **Step 1: Extend the idempotent E2E fixture graph.**

Create one `E2E_FIXTURE` company, two `@example.test` contacts, a second sequence step, the sequence campaign, two sent fake runs, and recipient rows matching the Task 3 scenario. Use fake `zoho_campaign_key` values only as local identifiers; do not invoke a Zoho client.

- [ ] **Step 2: Extend targeted E2E cleanup.**

Delete fixture campaigns first so runs/recipients cascade, then soft/force-delete only contacts whose emails end in the exact fixture addresses, then delete the exact fixture company. Do not broaden cleanup beyond `E2E_FIXTURE`/`@example.test` rows created by the seed command.

- [ ] **Step 3: Add a Playwright test that behaves like a user.**

The browser test must:

1. Open the fixture campaign.
2. Open Destinataires.
3. Find Email A and verify step 1 “Ouvert” and step 2 “Envoyé — non ouvert”.
4. Find Email B and verify step 1 “Non envoyé” and step 2 “Pas encore planifié”.
5. Verify per-step aggregate headings.
6. Verify exactly one sync button exists, but do not click it.
7. Open an execution’s “Voir destinataires” link and verify the legacy run-scoped table still works.

- [ ] **Step 4: Run all focused PHP verification.**

```powershell
php -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Backend/CampaignFeedbackTest.php tests/Feature/Backend/SyncCampaignStatsTest.php tests/Feature/Backend/SyncCampaignRecipientPaginationTest.php tests/Feature/Backend/CampaignSendAccessTest.php tests/Feature/Backend/CampaignViewRecipientsTest.php --colors=never
```

- [ ] **Step 5: Run Chromium E2E with fake local data.**

```powershell
php artisan fretiq:e2e-seed
npm run e2e -- tests/e2e/specs/module-3-campaigns.spec.ts --project=chromium --grep "recipient step matrix"
php artisan fretiq:e2e-purge
```

The sync button is presence-tested only in Playwright. Its POST behavior is proven with `Queue::fake()` in PHPUnit so no live Zoho request is made.

- [ ] **Step 6: Perform human-style browser QA.**

At desktop and 390×844 widths, verify:

- every email/step cell is readable;
- the contact column stays understandable while horizontally scrolling steps;
- no page-level horizontal overflow;
- timestamps and empty states are truthful;
- non-sequence and run-scoped recipient tables remain intact;
- unauthorized users cannot see the sync button;
- there are no console errors or unexpected failed network requests.

- [ ] **Step 7: Review the final diff.**

```powershell
git diff --check
git status --short
```

Review for N+1 queries, permission leaks, duplicate buttons, incorrect “delivered” wording, accidental email/send actions, and unrelated changes.

## Rollout and Rollback

- Rollout is code-only; no schema operation is required.
- After a future manual deployment, restart both `default` and `campaigns` queue workers and keep the existing Laravel scheduler running.
- Rollback is the ordinary code rollback. Existing `campaign_runs` and `campaign_recipients` data remain compatible because this feature adds no columns.

## Ship Criteria

- A sequence campaign shows per-step totals and one matrix row per email.
- Email A can truthfully show “opened in step 1” and “sent but not opened in step 2.”
- Missing, queued, unsent, opened, clicked, replied, unsubscribed, and bounced states remain distinguishable.
- Exactly one authorized campaign sync button queues both jobs for every eligible recent Zoho run/step.
- No individual email is falsely labeled delivered.
- Focused PHPUnit and Chromium E2E pass, manual desktop/mobile QA passes, and `git diff --check` is clean.

## WHAT RAN

Ran: planning skills only; no coder, reviewer, tester, or runner agent was dispatched.
