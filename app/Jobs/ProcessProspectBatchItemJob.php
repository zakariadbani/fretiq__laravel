<?php

namespace App\Jobs;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectItemProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class ProcessProspectBatchItemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $itemId)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "prospect-item:{$this->itemId}";
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->shared()
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(ProspectItemProcessor $processor, ProspectBatchService $batches): void
    {
        $item = ProspectBatchItem::query()->find($this->itemId);
        if ($item === null) {
            return;
        }

        ProspectBatch::query()
            ->whereKey($item->prospect_batch_id)
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        try {
            $processor->process($item);
            $item->refresh();
            if ($item->status === 'ready') {
                try {
                    $batches->promoteItem($item);
                } catch (LogicException $exception) {
                    if ($item->fresh()->status !== 'review') {
                        throw $exception;
                    }
                }
            }
        } finally {
            $this->dispatchFinalizer((int) $item->prospect_batch_id);
        }
    }

    public function failed(Throwable $exception): void
    {
        $item = ProspectBatchItem::query()->find($this->itemId);
        if ($item === null) {
            return;
        }

        // The remote outcome of a job-level failure is unknown. Do not retry
        // blindly or expose exception contents (which could include PII).
        DB::transaction(function () use ($item): void {
            ProspectBatchItem::query()
                ->whereKey($item->getKey())
                ->where('status', 'processing')
                ->update([
                    'status' => 'review',
                    'domain_reason' => 'provider_outcome_uncertain',
                    'error_code' => 'provider_outcome_uncertain',
                    'error_message' => null,
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        app(ProspectBatchService::class)->refreshCounters($item->batch);
        $this->dispatchFinalizer((int) $item->prospect_batch_id);
    }

    private function dispatchFinalizer(int $batchId): void
    {
        DB::afterCommit(static function () use ($batchId): void {
            FinalizeProspectBatchJob::dispatch($batchId)->delay(now()->addSeconds(5));
        });
    }
}
