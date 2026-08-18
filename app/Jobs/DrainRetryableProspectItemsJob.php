<?php

namespace App\Jobs;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderRequestException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Background half of the "À relancer" / "Reprendre les lignes en attente"
 * bulk drains. The controller only selects and caps the item ids (either
 * `failed` items via blockedItemsQuery(), or stalled `pending` items an
 * operator's own retry click orphaned via stalledItemsQuery()) and enqueues
 * this job — everything that can fail, race, or exhaust a retry budget
 * happens here, one item at a time, each under its own short lock (never one
 * giant transaction across the whole batch of items).
 */
final class DrainRetryableProspectItemsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // `failed` items flip status inside this job, so a re-run of the whole
    // job (infra hiccup) just finds them no longer 'failed' and skips them
    // as already_handled. `pending` items get NO flip here — they're
    // already the target status — so that reasoning alone can't cover them.
    // The actual safety net for both branches is
    // ProspectItemProcessor::claim()'s atomic
    // whereIn('status',['pending','failed'])->update(...): only one
    // dispatched ProcessProspectBatchItemJob per item can win the claim, so
    // a duplicate dispatch (this job re-running, or a drain firing twice) is
    // a wasted job, never a double-processed item. Nothing here needs
    // Laravel-level retries on top of that.
    public int $tries = 1;

    public int $timeout = 300;

    private const CACHE_TTL_MINUTES = 60;

    // Spacing between dispatches of ProcessProspectBatchItemJob. Two reasons:
    // (1) that job shares a WithoutOverlapping("prospect-criterion-batch:{id}")
    // mutex (releaseAfter=10s) across every item of the same criterion batch —
    // firing them all at once burns their tries on lock-miss releases before
    // handle() ever runs; (2) the whole reason these items are stuck is a
    // provider rate limit, so hammering it again defeats the point of this
    // feature. 8s clears a typical single-item Hunter round trip comfortably
    // without a per-batch grouping step that isn't worth the complexity for
    // a capped 100-item sweep (~13 minutes worst case).
    private const DISPATCH_SPACING_SECONDS = 8;

    /** @param list<int> $itemIds */
    public function __construct(
        public readonly array $itemIds,
        public readonly string $drainToken,
        public readonly int $requestedBy,
    ) {
        $this->afterCommit = true;
    }

    public function handle(ProspectBatchService $batches, ProviderCallLedger $ledger): void
    {
        $counts = [
            'retried' => 0,
            'retry_window_open' => 0,
            'budget_exhausted' => 0,
            'provider_outcome_uncertain' => 0,
            'criterion_inactive' => 0,
            'already_handled' => 0,
        ];
        /** @var array<int, ProspectBatch> $touchedBatches */
        $touchedBatches = [];
        $dispatchSequence = 0;

        foreach ($this->itemIds as $itemId) {
            $outcome = DB::transaction(function () use ($itemId, $batches, $ledger, &$touchedBatches): array {
                $locked = ProspectBatchItem::query()
                    ->lockForUpdate()
                    ->with(['batch:id,status,created_by,prospect_criteria_id', 'batch.criteria:id,name,is_active'])
                    ->find($itemId);

                if ($locked === null || ! in_array($locked->status, ['failed', 'pending'], true)) {
                    return ['code' => 'already_handled', 'itemId' => null];
                }

                $reason = $batches->preflightRetrySkipReason($locked);
                if ($reason !== null) {
                    return ['code' => $reason, 'itemId' => null];
                }

                // Mandatory on both branches, not just `failed`: this is a
                // MAX_ATTEMPTS gate (plus the failed → retryable flip
                // execute() requires), and it's a safe no-op for a genuinely
                // stalled `pending` item (error_code null →
                // allowedFailureCodesFor('') → [] → returns 0 without a
                // query). Skipping it on the `pending` branch would let an
                // attempt-exhausted item re-dispatch straight into
                // provider_call_not_replayable.
                try {
                    $ledger->authorizeKnownFailureRetryForItem((int) $locked->getKey(), (string) $locked->error_code);
                } catch (ProviderRequestException) {
                    return ['code' => 'budget_exhausted', 'itemId' => null];
                }

                if ($locked->status === 'failed') {
                    // Same retry transition decideItem() applies — never a
                    // second retry mechanism for the same state change.
                    $locked->forceFill([
                        'status' => 'pending',
                        'error_code' => null,
                        'error_message' => null,
                        'processing_started_at' => null,
                        'processed_at' => null,
                    ])->save();
                }
                // `pending` items are already the target status — nothing to
                // flip, just dispatch below.

                if ($locked->batch !== null) {
                    $touchedBatches[(int) $locked->batch->getKey()] = $locked->batch;
                }

                return ['code' => 'retried', 'itemId' => (int) $locked->getKey()];
            });

            $counts[$outcome['code']]++;

            if ($outcome['itemId'] !== null) {
                $itemIdToDispatch = $outcome['itemId'];
                $delay = now()->addSeconds($dispatchSequence * self::DISPATCH_SPACING_SECONDS);
                $dispatchSequence++;
                DB::afterCommit(static function () use ($itemIdToDispatch, $delay): void {
                    ProcessProspectBatchItemJob::dispatch($itemIdToDispatch)->delay($delay);
                });
            }
        }

        foreach ($touchedBatches as $batch) {
            $batches->refreshCounters($batch);
        }

        $this->writeResult($counts);
    }

    public function failed(Throwable $exception): void
    {
        // Honest reporting extends to the failure path: without this, a
        // polling UI would spin to its own timeout instead of telling the
        // operator the drain didn't finish and the queue needs a reload.
        Cache::put($this->cacheKey(), [
            'terminal' => true,
            'requested_by' => $this->requestedBy,
            'total' => count($this->itemIds),
            'error' => true,
            'finished_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    /** @param array<string, int> $counts */
    private function writeResult(array $counts): void
    {
        Cache::put($this->cacheKey(), [
            'terminal' => true,
            'requested_by' => $this->requestedBy,
            'total' => count($this->itemIds),
            'retried' => $counts['retried'],
            'skipped' => [
                'retry_window_open' => $counts['retry_window_open'],
                'budget_exhausted' => $counts['budget_exhausted'],
                'provider_outcome_uncertain' => $counts['provider_outcome_uncertain'],
                'criterion_inactive' => $counts['criterion_inactive'],
            ],
            'already_handled' => $counts['already_handled'],
            'error' => false,
            'finished_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    public static function cacheKeyFor(string $token): string
    {
        return 'prospect-retry-drain:'.$token;
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor($this->drainToken);
    }
}
