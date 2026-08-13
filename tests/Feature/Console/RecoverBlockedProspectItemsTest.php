<?php

namespace Tests\Feature\Console;

use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RecoverBlockedProspectItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_preview_does_not_change_items_or_dispatch_jobs(): void
    {
        Bus::fake();
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed', 'error_code' => 'too_many_requests']);
        ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id, 'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter', 'operation' => 'company_enrichment', 'idempotency_key' => hash('sha256', 'blocked-'.$item->id),
            'status' => 'retryable', 'attempt_count' => 1, 'retry_at' => now()->subMinute(), 'metadata' => ['error_code' => 'too_many_requests'],
        ]);

        $this->artisan('prospecting:recover-blocked-items', ['--batch' => $batch->id])
            ->assertExitCode(0);

        $this->assertSame('failed', $item->fresh()->status);
        Bus::assertNotDispatched(ProcessProspectBatchItemJob::class);
    }

    public function test_apply_requeues_only_due_eligible_items_once_and_spaces_dispatched_jobs(): void
    {
        Bus::fake();
        $now = now()->startOfSecond();
        $this->travelTo($now);
        $batch = ProspectBatch::factory()->create();
        $eligible = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'failed',
            'error_code' => 'usage_limit',
            'selected_domain' => 'eligible.test',
            'source_metadata' => ['processing' => ['resolution_done' => true]],
        ]);
        $eligibleSecond = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'failed',
            'error_code' => 'too_many_requests',
            'selected_domain' => 'second.test',
            'source_metadata' => ['processing' => ['resolution_done' => true]],
        ]);
        $future = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'failed',
            'error_code' => 'too_many_requests',
            'selected_domain' => 'future.test',
        ]);
        foreach ([[$eligible, now()->subMinute()], [$eligibleSecond, now()->subMinute()], [$future, now()->addHour()]] as [$item, $retryAt]) {
            ProviderCall::query()->create([
                'prospect_batch_id' => $batch->id,
                'prospect_batch_item_id' => $item->id,
                'provider' => 'hunter',
                'operation' => 'company_enrichment',
                'idempotency_key' => hash('sha256', 'blocked-'.$item->id),
                'status' => 'retryable',
                'attempt_count' => 1,
                'retry_at' => $retryAt,
                'metadata' => ['error_code' => $item->error_code],
            ]);
        }

        $this->artisan('prospecting:recover-blocked-items', [
            '--batch' => $batch->id,
            '--apply' => true,
            '--spacing' => 7,
        ])->assertExitCode(0);

        $eligible->refresh();
        $this->assertSame('pending', $eligible->status);
        $this->assertSame('eligible.test', $eligible->selected_domain);
        $this->assertTrue((bool) data_get($eligible->source_metadata, 'processing.resolution_done'));
        $this->assertSame('pending', $eligibleSecond->fresh()->status);
        $this->assertSame('failed', $future->fresh()->status);
        Bus::assertDispatchedTimes(ProcessProspectBatchItemJob::class, 2);
        Bus::assertDispatched(ProcessProspectBatchItemJob::class, fn (ProcessProspectBatchItemJob $job): bool => $job->itemId === $eligible->id
            && $job->delay?->equalTo($now));
        Bus::assertDispatched(ProcessProspectBatchItemJob::class, fn (ProcessProspectBatchItemJob $job): bool => $job->itemId === $eligibleSecond->id
            && $job->delay?->equalTo($now->copy()->addSeconds(7)));

        $this->artisan('prospecting:recover-blocked-items', ['--batch' => $batch->id, '--apply' => true])->assertExitCode(0);
        Bus::assertDispatchedTimes(ProcessProspectBatchItemJob::class, 2);
    }
}
