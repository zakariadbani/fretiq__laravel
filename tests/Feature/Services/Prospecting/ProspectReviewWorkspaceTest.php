<?php

namespace Tests\Feature\Services\Prospecting;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Prospecting\ProspectReviewWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectReviewWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_selects_only_an_active_company_item_from_the_authorized_queue(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $owned = ProspectBatch::factory()->create(['created_by' => $user->id, 'status' => 'review']);
        $foreign = ProspectBatch::factory()->create(['created_by' => $other->id, 'status' => 'review']);
        $selected = ProspectBatchItem::factory()->for($owned, 'batch')->create([
            'company_name' => 'Selected Company',
            'status' => 'review',
            'domain_reason' => 'ambiguous_domain',
            'domain_alternatives' => ['selected-company.com'],
            'source_metadata' => ['resolution' => ['finder' => [['domain' => 'selected-company.com', 'company_name' => 'Selected Company']]]],
        ]);
        $foreignItem = ProspectBatchItem::factory()->for($foreign, 'batch')->create([
            'company_name' => 'Foreign Company',
            'status' => 'review',
        ]);

        $selectedData = app(ProspectReviewWorkspace::class)->build($user, $this->filters(['batch' => $owned->id, 'item' => $selected->id]));
        $foreignData = app(ProspectReviewWorkspace::class)->build($user, $this->filters(['batch' => $owned->id, 'item' => $foreignItem->id]));

        $this->assertSame($selected->id, $selectedData['activeItem']->id);
        $this->assertSame('selected-company.com', $selectedData['activeReview']['primary_candidate']['domain']);
        // Requesting an item that isn't in the caller's authorized queue no
        // longer resolves to an empty pane — it falls back to the caller's
        // own first visible item (Bug A structural fix: same fallback that
        // covers already-decided/409/stale-bookmark items).
        $this->assertSame($selected->id, $foreignData['activeItem']->id);
        $this->assertStringNotContainsString('Foreign Company', $foreignData['items']->pluck('company_name')->join(' '));
        $this->assertArrayNotHasKey('candidates', $selectedData);
        $this->assertArrayNotHasKey('contactItem', $selectedData);
    }

    public function test_workspace_suppresses_the_fallback_when_the_requested_item_is_the_monitored_item(): void
    {
        $user = User::factory()->create();
        $batch = ProspectBatch::factory()->create(['created_by' => $user->id, 'status' => 'review']);
        // Simulates the state right after a retry decision: the retried item has
        // already flipped to 'pending' and no longer resolves in the
        // ['review','failed'] scope. A second, genuinely reviewable item exists,
        // which is exactly the situation that made the unconditional fallback
        // silently swap in the wrong company's card.
        $retried = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'pending']);
        ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);

        $data = app(ProspectReviewWorkspace::class)->build($user, $this->filters([
            'batch' => $batch->id,
            'item' => $retried->id,
            'monitor_item' => $retried->id,
        ]));

        $this->assertNull($data['activeItem']);
        $this->assertNull($data['activeReview']);
    }

    public function test_workspace_counts_only_authorized_company_decisions_and_imported_provenance(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $owned = ProspectBatch::factory()->create(['created_by' => $user->id, 'status' => 'review']);
        $foreign = ProspectBatch::factory()->create(['created_by' => $other->id, 'status' => 'review']);
        $company = Company::factory()->create();
        $ready = ProspectBatchItem::factory()->for($owned, 'batch')->create(['status' => 'ready', 'company_id' => $company->id]);
        ProspectBatchItem::factory()->for($owned, 'batch')->create(['status' => 'review']);
        ProspectBatchItem::factory()->for($foreign, 'batch')->create(['status' => 'review', 'company_name' => 'Foreign Secret']);
        $contact = Contact::factory()->for($company)->create();
        ProspectBatchContact::query()->create([
            'prospect_batch_id' => $owned->id,
            'prospect_batch_item_id' => $ready->id,
            'contact_id' => $contact->id,
            'provider_source' => 'hunter_domain_search',
            'imported_at' => now(),
        ]);

        $data = app(ProspectReviewWorkspace::class)->build($user, $this->filters(['batch' => $owned->id]));

        $this->assertSame([
            'handled' => 1,
            'companies_pending' => 1,
            'companies_attention' => 1,
            'companies_blocked' => 0,
            'imported_contacts' => 1,
            'batches' => 1,
        ], $data['summary']);
        $this->assertStringNotContainsString('Foreign Secret', $data['items']->pluck('company_name')->join(' '));
    }

    public function test_workspace_filters_attention_and_blocked_company_states(): void
    {
        $user = User::factory()->create();
        $batch = ProspectBatch::factory()->create(['created_by' => $user->id, 'status' => 'review']);
        $attention = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);
        $blocked = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed', 'error_code' => 'pagination_error']);

        $attentionData = app(ProspectReviewWorkspace::class)->build($user, $this->filters(['batch' => $batch->id, 'state' => 'attention']));
        $blockedData = app(ProspectReviewWorkspace::class)->build($user, $this->filters(['batch' => $batch->id, 'state' => 'blocked']));

        $this->assertSame([$attention->id], $attentionData['items']->pluck('id')->all());
        $this->assertSame([$blocked->id], $blockedData['items']->pluck('id')->all());
        $this->assertSame(['all' => 2, 'attention' => 1, 'blocked' => 1], $attentionData['stateCounts']);
        $this->assertSame(['all' => 2, 'attention' => 1, 'blocked' => 1], $blockedData['stateCounts']);
    }

    public function test_next_queued_item_returns_the_filter_respecting_visible_neighbour(): void
    {
        $user = User::factory()->create();
        $batch = ProspectBatch::factory()->create(['created_by' => $user->id, 'status' => 'review']);

        // Ascending creation order (ascending id): older, blockedBetween, active, newest.
        $older = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);
        ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed']);
        $active = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);
        ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);

        $data = app(ProspectReviewWorkspace::class)->build($user, $this->filters([
            'batch' => $batch->id,
            'item' => $active->id,
            'state' => 'attention',
        ]));

        // Not the "newest" item (what the old whereKeyNot()->latest('id') logic would have
        // returned — a jump backward in the visible, descending-id-ordered list) and not the
        // failed item sitting between them (state=attention only shows 'review' items).
        $this->assertSame($older->id, $data['nextItem']->id);
    }

    public function test_next_queued_item_is_null_at_the_end_of_the_filtered_list(): void
    {
        $user = User::factory()->create();
        $batch = ProspectBatch::factory()->create(['created_by' => $user->id, 'status' => 'review']);
        $only = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);

        $data = app(ProspectReviewWorkspace::class)->build($user, $this->filters(['batch' => $batch->id, 'item' => $only->id]));

        $this->assertNull($data['nextItem']);
    }

    public function test_build_trusts_the_caller_supplied_host_batch_without_its_own_ownership_check(): void
    {
        // build() no longer branches on ownership itself (Phase A dropped the
        // cross-batch / unauthorized-batch path along with $hostScoped) — every
        // real caller (ProspectReviewWorkspace::buildFromRequest(), always given
        // a host batch id) is only ever reached after
        // ProspectBatchController::authorizeBatch() has already checked
        // ownership, using the same created_by/admin rule authorizedItems()
        // applies below. Calling build() directly with a non-owning, non-admin
        // user (as this test deliberately does) skips that upstream check, so
        // batchProgress() — no longer ownership-scoped — still returns the
        // batch's progress row, while the item-level authorizedItems() filter
        // (unchanged by Phase A) still excludes its items. build() itself is
        // not the ownership boundary; the controller is.
        $owner = User::factory()->create();
        $caller = User::factory()->create();
        $batch = ProspectBatch::factory()->create(['created_by' => $owner->id, 'status' => 'review']);
        ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);

        $data = app(ProspectReviewWorkspace::class)->build($caller, $this->filters(['batch' => $batch->id]));

        $this->assertSame(1, $data['summary']['batches']);
        $this->assertCount(0, $data['items']);
    }

    public function test_status_facts_use_provenance_and_only_the_latest_current_attempt_domain_search(): void
    {
        $user = User::factory()->create();
        $batch = ProspectBatch::factory()->create(['created_by' => $user->id, 'status' => 'review']);
        $company = Company::factory()->create();
        $startedAt = now()->subMinutes(10);
        $processedAt = now()->subMinutes(2);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'ready',
            'company_id' => $company->id,
            'processing_started_at' => $startedAt,
            'processed_at' => $processedAt,
        ]);

        foreach (['one@example.test', 'two@example.test'] as $email) {
            $contact = Contact::factory()->for($company)->create(['email' => $email]);
            ProspectBatchContact::query()->create([
                'prospect_batch_id' => $batch->id,
                'prospect_batch_item_id' => $item->id,
                'contact_id' => $contact->id,
                'provider_source' => 'hunter_domain_search',
                'imported_at' => now(),
            ]);
        }

        $this->providerCall($batch, $item, 99, 9, $startedAt->copy()->subMinutes(2), $startedAt->copy()->subMinute());
        $this->providerCall($batch, $item, 1, .5, $startedAt->copy()->addSeconds(30), $startedAt->copy()->addMinute());
        $this->providerCall($batch, $item, 2, 1, $startedAt->copy()->addMinutes(2), $startedAt->copy()->addMinutes(3));
        $this->providerCall($batch, $item, 42, 4, $processedAt->copy()->addSecond(), $processedAt->copy()->addMinute());

        $this->assertSame([
            'imported_contacts_count' => 2,
            'provider_result_count' => 2,
            'recorded_units' => 1.0,
        ], app(ProspectReviewWorkspace::class)->statusFacts($item->fresh()));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function filters(array $overrides = []): array
    {
        return array_replace([
            'batch' => null,
            'tab' => 'companies',
            'reason' => null,
            'state' => 'all',
            'q' => '',
            'item' => null,
            'monitor_item' => null,
        ], $overrides);
    }

    private function providerCall(
        ProspectBatch $batch,
        ProspectBatchItem $item,
        int $resultCount,
        float $units,
        \DateTimeInterface $startedAt,
        \DateTimeInterface $finishedAt,
    ): void {
        ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'engine' => 'prospect_item',
            'idempotency_key' => hash('sha256', uniqid('review-facts-', true)),
            'status' => 'succeeded',
            'result_count' => $resultCount,
            'consumed_units' => $units,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);
    }
}
