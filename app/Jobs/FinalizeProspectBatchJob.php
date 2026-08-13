<?php

namespace App\Jobs;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\ProspectBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FinalizeProspectBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $batchId)
    {
        $this->afterCommit = true;
    }

    public function uniqueId(): string
    {
        return "prospect-batch-finalize:{$this->batchId}";
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

    public function handle(ProspectBatchService $batches): void
    {
        $action = DB::transaction(function (): string {
            $batch = ProspectBatch::query()->lockForUpdate()->find($this->batchId);
            if ($batch === null || in_array($batch->status, ['draft', 'cancelled', 'completed', 'failed'], true)) {
                return 'done';
            }

            $this->recomputeCounters($batch);
            $items = ProspectBatchItem::query()->where('prospect_batch_id', $batch->getKey());
            $pending = (clone $items)->whereIn('status', ['pending', 'processing'])->exists();
            if ($pending) {
                return 'wait';
            }

            $cursor = is_array($batch->source_cursor) ? $batch->source_cursor : [];
            if ($batch->source_type === 'discover' && ($cursor['exhausted'] ?? false) !== true) {
                return 'discover';
            }

            $reviewRequired = (clone $items)->where('status', 'review')->exists();
            $failed = (clone $items)->where('status', 'failed')->count();
            $total = (clone $items)->count();
            $successful = (clone $items)->whereIn('status', ['ready', 'promoted'])->count();

            if ($reviewRequired || ($failed > 0 && $successful > 0)) {
                $this->terminalize($batch, 'review');

                return 'done';
            }
            if ($total > 0 && $failed === $total) {
                $this->terminalize($batch, 'failed');

                return 'done';
            }
            if ($failed === 0 && (clone $items)->whereNotIn('status', ['ready', 'promoted'])->doesntExist()) {
                $this->terminalize($batch, 'completed');

                return 'done';
            }

            $this->terminalize($batch, 'review');

            return 'done';
        });

        if ($action === 'wait') {
            // Terminal item jobs dispatch a fresh finalizer. Polling here burns
            // attempts while a provider retry is deliberately delayed.
            return;
        }
        if ($action !== 'discover') {
            return;
        }

        // The claim/HTTP/settlement sequence is deliberately outside the short
        // finalization transaction. Its own cache lock makes a replay harmless.
        $batch = ProspectBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }
        $batches->collectDiscoverPage($batch);
        $this->release(10);
    }

    public function failed(Throwable $exception): void
    {
        ProspectBatch::query()
            ->whereKey($this->batchId)
            ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
            ->update([
                'status' => 'review',
                'error' => 'finalization_delayed',
                'updated_at' => now(),
            ]);
    }

    private function recomputeCounters(ProspectBatch $batch): void
    {
        $items = ProspectBatchItem::query()->where('prospect_batch_id', $batch->getKey());
        $terminal = ['review', 'ready', 'promoted', 'failed', 'skipped'];
        $batch->forceFill([
            'total_items' => (clone $items)->count(),
            'processed_items' => (clone $items)->whereIn('status', $terminal)->count(),
            'review_items' => (clone $items)->where('status', 'review')->count(),
            'failed_items' => (clone $items)->where('status', 'failed')->count(),
            'promoted_companies' => (clone $items)->where('status', 'promoted')->count(),
            'imported_contacts' => ProspectBatchContact::query()
                ->where('prospect_batch_id', $batch->getKey())
                ->count(),
        ])->save();
    }

    private function terminalize(ProspectBatch $batch, string $status): void
    {
        $batch->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'error' => null,
        ])->save();
    }
}
