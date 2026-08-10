<?php

namespace Tests\Feature;

use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Services\Zoho\V2\Sync\ZohoStandardWorklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ZohoStandardWorklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_is_unique_normalizes_ids_and_returns_queued_items_in_id_order(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));

        $worklist->stage($run, [' 300 ', '', '100', '300', '  ']);

        $this->assertSame(['100', '300'], $worklist->queued($run, 10)->pluck('zoho_id')->all());
        $this->assertSame(2, $run->workItems()->count());
    }

    public function test_restart_enumeration_clears_only_the_token_and_preserves_completed_items(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $run->update([
            'page_token' => 'opaque-token',
            'page_token_expires_at' => '2026-08-10 12:00:00',
        ]);
        $worklist->stage($run, ['100', '200']);
        $checkpoint = $this->checkpoint($batch, 'Quotes', 1, 'worker-1');
        $claimed = $worklist->claimQueued($run, $checkpoint, 1, 'worker-1', 1)->firstOrFail();
        $worklist->markCompleted($run, $checkpoint, $claimed, 1, 'worker-1', [
            'records_created' => 1,
            'records_updated' => 2,
            'records_unchanged' => 3,
        ]);

        $worklist->restartEnumeration($run, $checkpoint, 1, 'worker-1');

        $run->refresh();
        $this->assertNull($run->page_token);
        $this->assertNull($run->page_token_expires_at);
        $this->assertSame(1, $run->enumeration_restart_count);
        $this->assertSame('completed', $run->workItems()->where('zoho_id', '100')->value('status'));
        $this->assertSame(['200'], $worklist->queued($run, 10)->pluck('zoho_id')->all());
    }

    public function test_seeding_completed_ids_leaves_unresolved_ids_queued(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));

        $worklist->seedCompleted($run, ['100', '300'], ['200']);

        $this->assertSame('completed', $run->workItems()->where('zoho_id', '100')->value('status'));
        $this->assertSame('queued', $run->workItems()->where('zoho_id', '200')->value('status'));
        $this->assertSame('completed', $run->workItems()->where('zoho_id', '300')->value('status'));
    }

    public function test_marking_completed_persists_outcome_counters_atomically(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $worklist->stage($run, ['100']);

        $checkpoint = $this->checkpoint($batch, 'Quotes', 1, 'worker-1');
        $claimed = $worklist->claimQueued($run, $checkpoint, 1, 'worker-1', 1)->firstOrFail();
        $worklist->markCompleted($run, $checkpoint, $claimed, 1, 'worker-1', [
            'records_created' => 2,
            'records_updated' => 3,
            'records_unchanged' => 4,
            'outcome' => 'updated',
        ]);

        $item = $run->workItems()->firstOrFail();
        $this->assertSame('completed', $item->status);
        $this->assertSame(2, $item->records_created);
        $this->assertSame(3, $item->records_updated);
        $this->assertSame(4, $item->records_unchanged);
        $this->assertSame('updated', $item->outcome);
        $this->assertNotNull($item->processed_at);
    }

    public function test_existing_run_rejects_changed_authoritative_or_immutable_attributes(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $attributes = $this->runAttributes($batch);
        $worklist->getOrCreateRun($batch, 'Quotes', '', $attributes);

        $sameSemantics = array_replace($attributes, [
            'query_params' => ['sort_by' => 'id', 'fields' => 'id'],
            'watermark_at' => '2026-08-09T14:00:00+02:00',
        ]);
        $this->assertNotNull($worklist->getOrCreateRun($batch, 'Quotes', '', $sameSemantics));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('query fingerprint');
        $worklist->getOrCreateRun($batch, 'Quotes', '', array_replace($attributes, ['query_fingerprint' => str_repeat('c', 64)]));
    }

    public function test_persisting_enumeration_page_stages_ids_and_advances_the_run_together(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));

        $checkpoint = $this->checkpoint($batch, 'Quotes', 1, 'worker-1');
        $worklist->persistEnumerationPage($run, $checkpoint, 1, 'worker-1', ['300', '100', '100'], 'next-token', '2026-08-10 12:00:00', false, ['seen' => 3]);
        $run->refresh();

        $this->assertSame('enumerating', $run->status);
        $this->assertSame('next-token', $run->page_token);
        $this->assertSame(['seen' => 3], $run->counters);
        $this->assertSame(['100', '300'], $worklist->queued($run, 10)->pluck('zoho_id')->all());

        $worklist->persistEnumerationPage($run, $checkpoint, 1, 'worker-1', [], null, null, true);
        $run->refresh();
        $this->assertSame('hydrating', $run->status);
        $this->assertNotNull($run->enumerated_at);
        $this->assertNull($run->page_token);
    }

    public function test_stale_claim_completion_is_a_no_op_and_restart_requeues_processing_items(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $worklist->stage($run, ['100']);
        $checkpoint = $this->checkpoint($batch, 'Quotes', 1, 'worker-1');
        $claimed = $worklist->claimQueued($run, $checkpoint, 1, 'worker-1', 1)->firstOrFail();

        $claimed->delivery_generation++;
        $this->assertFalse($worklist->markCompleted($run, $checkpoint, $claimed, 1, 'worker-1', ['outcome' => 'updated']));
        $this->assertSame('processing', $run->workItems()->firstOrFail()->status);

        $worklist->restartEnumeration($run, $checkpoint, 1, 'worker-1');
        $run->refresh();
        $this->assertSame('enumerating', $run->status);
        $this->assertNull($run->enumerated_at);
        $this->assertSame('queued', $run->workItems()->firstOrFail()->status);
    }

    public function test_newer_checkpoint_generation_reclaims_crashed_processing_work_without_accepting_stale_completion(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $worklist->stage($run, ['100']);

        $checkpoint = $this->checkpoint($batch, 'Quotes', 7, 'worker-7');
        $generationSeven = $worklist->claimQueued($run, $checkpoint, 7, 'worker-7', 1)->firstOrFail();
        $checkpoint->update(['generation' => 8, 'lease_owner' => 'worker-8']);
        $generationEight = $worklist->claimQueued($run, $checkpoint, 8, 'worker-8', 1)->firstOrFail();

        $this->assertSame(8, $generationEight->delivery_generation);
        $this->assertSame('worker-8', $generationEight->lease_owner);
        try {
            $worklist->markCompleted($run, $checkpoint, $generationSeven, 7, 'worker-7', ['outcome' => 'stale']);
            $this->fail('A stale checkpoint generation must not complete reclaimed work.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('checkpoint fence is stale', $exception->getMessage());
        }
        $this->assertTrue($worklist->markCompleted($run, $checkpoint, $generationEight, 8, 'worker-8', ['outcome' => 'updated']));
        $this->assertSame('completed', $run->workItems()->firstOrFail()->status);
    }

    public function test_incomplete_enumeration_page_requires_next_token_and_expiry(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('next token and expiry');
        $checkpoint = $this->checkpoint($batch, 'Quotes', 1, 'worker-1');
        $worklist->persistEnumerationPage($run, $checkpoint, 1, 'worker-1', ['100'], null, null, false);
    }

    public function test_same_generation_reclaims_only_expired_work_item_leases(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $checkpoint = $this->checkpoint($batch, 'Quotes', 7, 'worker-7');
        $worklist->stage($run, ['100']);

        $first = $worklist->claimQueued($run, $checkpoint, 7, 'worker-7', 1)->firstOrFail();
        $this->assertSame($checkpoint->lease_expires_at->timestamp, $first->lease_expires_at->timestamp);
        $this->assertCount(0, $worklist->claimQueued($run, $checkpoint, 7, 'worker-7', 1));

        $first->update(['lease_expires_at' => now()->subSecond()]);
        $checkpoint->update(['lease_owner' => 'worker-7b']);
        $reclaimed = $worklist->claimQueued($run, $checkpoint, 7, 'worker-7b', 1)->firstOrFail();
        $this->assertSame($first->id, $reclaimed->id);
        $this->assertSame('worker-7b', $reclaimed->lease_owner);
    }

    public function test_complete_claimed_rolls_back_callback_write_when_callback_throws(): void
    {
        [$worklist, $batch, $run, $checkpoint, $item] = $this->claimedWork();
        $failureKey = hash('sha256', 'complete-callback-rollback');

        try {
            $worklist->completeClaimed($run, $checkpoint, 1, 'worker-1', $item, function () use ($batch, $failureKey): array {
                $this->insertCallbackEffect($batch, $failureKey);
                throw new RuntimeException('persist failed');
            });
            $this->fail('The persistence exception must escape the atomic completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('persist failed', $exception->getMessage());
        }

        $this->assertDatabaseMissing('zoho_sync_failures', ['failure_key' => $failureKey]);
        $this->assertSame('processing', $run->workItems()->findOrFail($item->id)->status);
    }

    public function test_quarantine_claimed_rolls_back_failure_write_when_callback_throws(): void
    {
        [$worklist, $batch, $run, $checkpoint, $item] = $this->claimedWork();
        $failureKey = hash('sha256', 'quarantine-callback-rollback');

        try {
            $worklist->quarantineClaimed($run, $checkpoint, 1, 'worker-1', $item, function () use ($batch, $failureKey): array {
                $this->insertCallbackEffect($batch, $failureKey);
                throw new RuntimeException('failure upsert failed');
            });
            $this->fail('The failure-upsert exception must escape the atomic quarantine.');
        } catch (RuntimeException $exception) {
            $this->assertSame('failure upsert failed', $exception->getMessage());
        }

        $this->assertDatabaseMissing('zoho_sync_failures', ['failure_key' => $failureKey]);
        $this->assertSame('processing', $run->workItems()->findOrFail($item->id)->status);
    }

    public function test_paused_batch_blocks_callback_before_domain_persistence(): void
    {
        [$worklist, $batch, $run, $checkpoint, $item] = $this->claimedWork();
        $batch->update(['status' => 'paused']);
        $called = false;

        try {
            $worklist->completeClaimed($run, $checkpoint, 1, 'worker-1', $item, function () use (&$called): array {
                $called = true;

                return ['records_updated' => 1];
            });
            $this->fail('A paused batch must fail its runtime fence.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('live running batch', $exception->getMessage());
        }

        $this->assertFalse($called);
        $this->assertSame('processing', $run->workItems()->findOrFail($item->id)->status);
    }

    public function test_complete_run_if_drained_treats_quarantine_as_terminal(): void
    {
        [$worklist, , $run, $checkpoint, $item] = $this->claimedWork();
        $this->assertFalse($worklist->completeRunIfDrained($run, $checkpoint, 1, 'worker-1', ['quarantined' => 0]));
        $this->assertSame('enumerating', $run->fresh()->status);

        $result = $worklist->quarantineClaimed($run, $checkpoint, 1, 'worker-1', $item, static fn (): array => [
            'outcome' => 'quarantined',
            'error_summary' => 'invalid payload',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($worklist->completeRunIfDrained($run, $checkpoint, 1, 'worker-1', ['quarantined' => 1]));
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(['quarantined' => 1], $run->counters);
        $this->assertNotNull($run->completed_at);
    }

    public function test_durable_counters_reconstruct_completed_and_quarantined_outcomes_after_caller_crash(): void
    {
        [$worklist, , $run, $checkpoint, $item] = $this->claimedWork();
        $run->update(['counters' => [
            'seen' => 10,
            'created' => 1,
            'updated' => 2,
            'unchanged' => 3,
            'quarantined' => 4,
            'api_requests' => 5,
            'custom' => 'preserved',
        ]]);

        $worklist->completeClaimed($run, $checkpoint, 1, 'worker-1', $item, static fn (): array => [
            'records_created' => 2,
            'records_updated' => 3,
            'records_unchanged' => 4,
            'api_requests' => 6,
            'outcome' => 'updated',
        ]);
        $worklist->stage($run, ['200']);
        $quarantined = $worklist->claimQueued($run, $checkpoint, 1, 'worker-1', 1)->firstOrFail();
        $worklist->quarantineClaimed($run, $checkpoint, 1, 'worker-1', $quarantined, static fn (): array => [
            'api_requests' => 1,
            'error_summary' => 'invalid',
        ]);

        $this->assertEquals([
            'seen' => 12,
            'created' => 3,
            'updated' => 5,
            'unchanged' => 7,
            'quarantined' => 5,
            'api_requests' => 12,
            'custom' => 'preserved',
        ], $worklist->durableCounters($run));
    }

    public function test_seeded_completions_are_excluded_and_unresolved_items_are_reset(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $run->update(['counters' => ['seen' => 8, 'custom' => 'kept']]);
        $worklist->stage($run, ['200']);
        $run->workItems()->where('zoho_id', '200')->update([
            'status' => 'completed',
            'outcome' => 'updated',
            'error_summary' => 'old',
            'records_created' => 1,
            'records_updated' => 2,
            'records_unchanged' => 3,
            'api_requests' => 4,
            'processed_at' => now(),
        ]);

        $worklist->seedCompleted($run, ['100'], ['200']);

        $seeded = $run->workItems()->where('zoho_id', '100')->firstOrFail();
        $unresolved = $run->workItems()->where('zoho_id', '200')->firstOrFail();
        $this->assertSame('seeded', $seeded->outcome);
        $this->assertSame('queued', $unresolved->status);
        $this->assertNull($unresolved->outcome);
        $this->assertNull($unresolved->error_summary);
        $this->assertSame(0, $unresolved->records_created);
        $this->assertSame(0, $unresolved->records_updated);
        $this->assertSame(0, $unresolved->records_unchanged);
        $this->assertSame(0, $unresolved->api_requests);
        $this->assertNull($unresolved->processed_at);
        $this->assertSame(['seen' => 8, 'custom' => 'kept', 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'quarantined' => 0, 'api_requests' => 0], $worklist->durableCounters($run));
    }

    public function test_completed_run_returns_stored_final_counters_without_double_counting_items(): void
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $worklist->stage($run, ['100']);
        $run->workItems()->where('zoho_id', '100')->update([
            'status' => 'completed',
            'outcome' => 'created',
            'records_created' => 1,
            'api_requests' => 1,
            'processed_at' => now(),
        ]);
        $run->update(['status' => 'completed', 'completed_at' => now(), 'counters' => ['seen' => 1, 'created' => 1, 'api_requests' => 1]]);

        $this->assertSame(['seen' => 1, 'created' => 1, 'api_requests' => 1], $worklist->durableCounters($run));
    }

    /** @return array{ZohoStandardWorklist, ZohoSyncBatch, \App\Models\Zoho\ZohoStandardSyncRun, ZohoSyncCheckpoint, \App\Models\Zoho\ZohoStandardSyncWorkItem} */
    private function claimedWork(): array
    {
        $worklist = new ZohoStandardWorklist;
        $batch = $this->batch();
        $run = $worklist->getOrCreateRun($batch, 'Quotes', '', $this->runAttributes($batch));
        $checkpoint = $this->checkpoint($batch, 'Quotes', 1, 'worker-1');
        $worklist->stage($run, ['100']);
        $item = $worklist->claimQueued($run, $checkpoint, 1, 'worker-1', 1)->firstOrFail();

        return [$worklist, $batch, $run, $checkpoint, $item];
    }

    private function insertCallbackEffect(ZohoSyncBatch $batch, string $failureKey): void
    {
        DB::table('zoho_sync_failures')->insert([
            'sync_batch_id' => $batch->id,
            'module' => 'Quotes',
            'submodule' => '',
            'failure_kind' => 'test_callback',
            'failure_key' => $failureKey,
            'error_summary' => 'test callback effect',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function batch(): ZohoSyncBatch
    {
        return ZohoSyncBatch::query()->create([
            'correlation_id' => 'batch-'.fake()->uuid(),
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'running',
        ]);
    }

    private function checkpoint(ZohoSyncBatch $batch, string $module, int $generation, string $owner): ZohoSyncCheckpoint
    {
        return ZohoSyncCheckpoint::query()->create([
            'module' => 'v2:'.$module,
            'submodule' => '',
            'sync_mode' => $batch->mode,
            'status' => 'running',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'generation' => $generation,
            'lease_owner' => $owner,
            'lease_expires_at' => now()->addMinutes(5),
        ]);
    }

    /** @return array<string, mixed> */
    private function runAttributes(?ZohoSyncBatch $batch = null): array
    {
        return [
            'correlation_id' => $batch?->correlation_id ?? 'resume-test-'.fake()->uuid(),
            'mode' => $batch?->mode ?? 'delta',
            'query_fingerprint' => str_repeat('b', 64),
            'query_params' => ['fields' => 'id', 'sort_by' => 'id'],
            'watermark_at' => '2026-08-09 12:00:00',
        ];
    }
}
