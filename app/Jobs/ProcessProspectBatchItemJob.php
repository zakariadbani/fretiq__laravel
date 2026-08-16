<?php

namespace App\Jobs;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectItemProcessor;
use App\Services\Providers\ProviderRequestException;
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
        $middleware = [
            (new WithoutOverlapping($this->uniqueId()))
                ->shared()
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 60),
        ];
        $batchId = ProspectBatchItem::query()->whereKey($this->itemId)
            ->whereHas('batch', fn ($query) => $query->whereNotNull('prospect_criteria_id'))
            ->value('prospect_batch_id');
        if ($batchId !== null) {
            $middleware[] = (new WithoutOverlapping("prospect-criterion-batch:{$batchId}"))
                ->shared()->releaseAfter(10)->expireAfter($this->timeout + 60);
        }

        return $middleware;
    }

    public function handle(ProspectItemProcessor $processor, ProspectBatchService $batches): void
    {
        $item = ProspectBatchItem::query()->find($this->itemId);
        if ($item === null) {
            return;
        }

        ProspectBatch::query()
            ->whereKey($item->prospect_batch_id)
            ->whereIn('status', ['queued', 'review', 'failed'])
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
                'error' => null,
                'updated_at' => now(),
            ]);

        $released = false;
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
        } catch (ProviderRequestException $exception) {
            if (! $exception->retryable) {
                throw $exception;
            }
            $released = true;
            $this->release(min(86400, max(1, (int) ($exception->retryAfterSeconds ?? 30) + ($this->itemId % 11))));
        } finally {
            if (! $released) {
                $this->dispatchFinalizer((int) $item->prospect_batch_id);
            }
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
                ->whereIn('status', ['pending', 'processing'])
                ->update([
                    'status' => $item->status === 'pending' ? 'failed' : 'review',
                    'domain_reason' => $item->status === 'pending' ? $item->domain_reason : 'provider_outcome_uncertain',
                    'error_code' => $item->status === 'pending' ? ($item->error_code ?: 'provider_retry_exhausted') : 'provider_outcome_uncertain',
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
