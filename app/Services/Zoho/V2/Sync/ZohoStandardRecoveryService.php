<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Sync;

use App\Jobs\Zoho\RunZohoPostReconciliationJob;
use App\Models\Zoho\ZohoBulkReadJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Services\Zoho\V2\Bulk\ZohoModuleDispatcher;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Durable continuation outbox recovery. A checkpoint with an unexpired-free
 * lease is intentionally enough to reconstruct a lost queue delivery; this
 * sweeper makes the dispatch-after-commit crash window eventually recover.
 */
final class ZohoStandardRecoveryService
{
    public function __construct(
        private readonly ZohoModuleRegistry $registry,
        private readonly ZohoModuleDispatcher $dispatcher,
    ) {}

    /** @return array{module_jobs:int,post_jobs:int} */
    public function recover(): array
    {
        $moduleJobs = 0;
        // A worker can die after writing the final module log but before its
        // ordinary finalize call. Finalization is idempotent and repairs that
        // narrow outbox window without re-enqueueing any module.
        $this->finalizeTerminalBatches();
        foreach (ZohoSyncCheckpoint::query()
            ->whereNotNull('sync_batch_id')
            ->whereNotNull('correlation_id')
            ->whereIn('status', ['queued', 'running', 'retrying'])
            ->orderBy('id')->limit(100)->pluck('id') as $checkpointId) {
            $delivery = $this->claimModuleContinuation((int) $checkpointId);
            if ($delivery === null) {
                continue;
            }

            $this->dispatcher->dispatchPrepared(...$delivery);
            $moduleJobs++;
        }

        // The first queue dispatch happens after batch creation. If the
        // process dies in that narrow window there is no checkpoint yet, so
        // reconstruct only batches which have no durable module evidence.
        $initialStaleBefore = now()->subSeconds($this->initialStaleSeconds());
        foreach (ZohoSyncBatch::query()->whereNull('completed_at')->whereIn('status', ['queued', 'pending', 'running'])
            ->where('created_at', '<=', $initialStaleBefore)
            ->orderBy('id')->limit(25)->get() as $batch) {
            foreach ((array) $batch->modules as $moduleKey) {
                if ($this->claimInitialDelivery($batch, (string) $moduleKey)) {
                    $this->dispatcher->dispatch((int) $batch->id, (string) $moduleKey, (string) $batch->mode, (string) $batch->correlation_id);
                    $moduleJobs++;
                }
            }
        }

        $postJobs = 0;
        foreach (ZohoSyncBatch::query()
            ->whereNotNull('completed_at')
            ->whereIn('status', ['success', 'partial'])
            ->whereIn('post_reconciliation_status', ['pending', 'failed', 'running', 'retrying'])
            ->where(function ($query): void {
                $query->whereNull('post_reconciliation_retry_not_before')
                    ->orWhere('post_reconciliation_retry_not_before', '<=', now())
                    // A hard-killed running delivery at its final attempt
                    // must be terminalized even if stale retry metadata was
                    // left behind.
                    ->orWhere('post_reconciliation_status', 'running');
            })
            ->orderBy('id')->limit(100)->pluck('id') as $batchId) {
            if (! $this->claimPostOutbox((int) $batchId)) {
                continue;
            }

            $batch = ZohoSyncBatch::query()->find($batchId);
            RunZohoPostReconciliationJob::dispatch((int) $batchId, $batch?->post_reconciliation_retry_deadline_at?->toIso8601String())
                ->onConnection((string) config('zoho-v2.queue_connection', 'zoho'))
                ->onQueue((string) config('zoho-v2.queue', 'zoho'));
            $postJobs++;
        }

        return ['module_jobs' => $moduleJobs, 'post_jobs' => $postJobs];
    }

    /** @return array{0:int,1:string,2:string,3:string,4:string,5:int}|null */
    private function claimModuleContinuation(int $checkpointId): ?array
    {
        return DB::transaction(function () use ($checkpointId): ?array {
            // Discover ownership without a row lock, then acquire the global
            // runtime order: authoritative batch before checkpoint.
            $ownerBatchId = ZohoSyncCheckpoint::query()->whereKey($checkpointId)->value('sync_batch_id');
            if ($ownerBatchId === null) {
                return null;
            }
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($ownerBatchId);
            $checkpoint = ZohoSyncCheckpoint::query()->lockForUpdate()->find($checkpointId);
            if ($checkpoint === null || (int) $checkpoint->sync_batch_id !== (int) $ownerBatchId
                || $checkpoint->correlation_id === null || ! $this->checkpointIsRecoverable($checkpoint)) {
                return null;
            }
            $moduleKey = str_starts_with((string) $checkpoint->module, 'v2:')
                ? substr((string) $checkpoint->module, 3)
                : null;
            if ($moduleKey === null || $moduleKey === '' || $batch === null || $batch->completed_at !== null
                || ! in_array($batch->status, ['queued', 'running'], true)
                || ! hash_equals((string) $batch->correlation_id, (string) $checkpoint->correlation_id)
                || ! in_array($moduleKey, (array) $batch->modules, true)) {
                return null;
            }
            if (ZohoBulkReadJob::query()
                ->where('sync_batch_id', $batch->id)
                ->where('module', $moduleKey)
                ->where('submodule', (string) $checkpoint->submodule)
                ->exists()) {
                // A Bulk root is a durable ownership marker. Only the Bulk
                // recovery state machine may reconstruct that module's work.
                return null;
            }
            // Claim the durable outbox briefly. If dispatch crashes after this
            // commit, the next sweep can claim it once this heartbeat is stale.
            $generation = max(0, (int) $checkpoint->generation);
            if ($checkpoint->status !== 'queued') {
                // Atomically fence the abandoned worker before this claim is
                // visible. There is no lease-free gen-N retrying gap in which
                // its late failed() callback can terminalize the replacement.
                $generation++;
            }
            $checkpoint->update([
                'status' => 'queued',
                'generation' => $generation,
                'heartbeat_at' => now(),
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);

            $deadline = $checkpoint->delivery_retry_deadline_at?->toIso8601String()
                ?? now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12))->toIso8601String();
            if ($checkpoint->delivery_retry_deadline_at === null) {
                $checkpoint->update(['delivery_retry_deadline_at' => $deadline]);
            }

            return [
                (int) $batch->id,
                $moduleKey,
                (string) $batch->mode,
                (string) $batch->correlation_id,
                $deadline,
                $generation,
            ];
        });
    }

    private function claimPostOutbox(int $batchId): bool
    {
        return DB::transaction(function () use ($batchId): bool {
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($batchId);
            if ($batch === null || $batch->completed_at === null
                || ! in_array($batch->status, ['success', 'partial'], true)
                || $batch->post_reconciliation_status === 'completed'
                || ($batch->post_reconciliation_status === 'running' && $batch->post_reconciliation_lease_expires_at?->isFuture())) {
                return false;
            }
            if ($batch->post_reconciliation_retry_deadline_at !== null
                && $batch->post_reconciliation_retry_deadline_at->lessThanOrEqualTo(now())) {
                // A recovered job would be rejected by retryUntil() before
                // handle(), so it cannot acquire an owner for failed(). Make
                // this durable outbox terminal rather than enqueueing forever.
                $batch->update([
                    'post_reconciliation_status' => 'failed',
                    'post_reconciliation_attempts' => max(1, (int) config('zoho-v2.retry.max_attempts', 5)),
                    'post_reconciliation_lease_owner' => null,
                    'post_reconciliation_lease_expires_at' => null,
                    'post_reconciliation_retry_not_before' => null,
                    'post_reconciliation_error' => 'Post-reconciliation retry window expired.',
                ]);

                return false;
            }
            if ((int) $batch->post_reconciliation_attempts >= max(1, (int) config('zoho-v2.retry.max_attempts', 5))) {
                if ($batch->post_reconciliation_status === 'running') {
                    // The queue process was killed after claiming its final
                    // attempt, so failed() cannot own this lease. Converge
                    // the durable outbox without enqueueing another job.
                    $batch->update([
                        'post_reconciliation_status' => 'failed',
                        'post_reconciliation_lease_owner' => null,
                        'post_reconciliation_lease_expires_at' => null,
                        'post_reconciliation_retry_not_before' => null,
                        'post_reconciliation_error' => 'Post-reconciliation processing exhausted before completion.',
                    ]);
                }

                return false;
            }
            if ($batch->post_reconciliation_status === 'retrying'
                && $batch->updated_at?->greaterThan(now()->subSeconds($this->retryingStaleSeconds()))) {
                return false;
            }
            $batch->update([
                'post_reconciliation_status' => 'pending',
                'post_reconciliation_lease_owner' => null,
                'post_reconciliation_lease_expires_at' => null,
                'post_reconciliation_error' => null,
                'post_reconciliation_retry_not_before' => null,
                'post_reconciliation_retry_deadline_at' => $batch->post_reconciliation_retry_deadline_at
                    ?? now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12)),
            ]);

            return true;
        });
    }

    private function claimInitialDelivery(ZohoSyncBatch $batch, string $moduleKey): bool
    {
        return DB::transaction(function () use ($batch, $moduleKey): bool {
            $definition = $this->registry->get($moduleKey);
            $fresh = ZohoSyncBatch::query()->lockForUpdate()->find($batch->id);
            if ($fresh === null || $fresh->completed_at !== null
                || ! in_array($fresh->status, ['queued', 'running'], true)
                || ! in_array($moduleKey, (array) $fresh->modules, true)) {
                return false;
            }
            // A Bulk root is the durable hand-off marker. Check it before the
            // Bulk branch so a long-running export is never re-dispatched by
            // each standard-recovery sweep.
            if (ZohoBulkReadJob::query()->where('sync_batch_id', $fresh->id)->where('module', $moduleKey)->exists()) {
                return false;
            }
            if ($this->dispatcher->usesBulk($moduleKey, (string) $fresh->mode)) {
                // Bulk has its own durable root/outbox state machine. Do not
                // manufacture a standard checkpoint that could later fence it.
                return true;
            }
            $logExists = \App\Models\ZohoSyncLog::query()->where('sync_batch_id', $fresh->id)->where('module', $moduleKey)->exists();
            if ($logExists) {
                return false;
            }
            $submodule = $definition->submodule ?? '';
            $checkpoint = ZohoSyncCheckpoint::query()->where('module', 'v2:'.$moduleKey)->where('submodule', $submodule)->lockForUpdate()->first();
            if ($checkpoint !== null) {
                $priorBatchIsUnfinished = $checkpoint->sync_batch_id !== null
                    && ZohoSyncBatch::query()
                        ->whereKey($checkpoint->sync_batch_id)
                        ->whereNull('completed_at')
                        ->exists();
                if ($priorBatchIsUnfinished) {
                    return false;
                }
                // Keep the module cursor/counters: this is a new batch delivery,
                // not a data reset. Only ownership/outbox fields move forward.
                $checkpoint->update([
                    'sync_batch_id' => $fresh->id, 'correlation_id' => $fresh->correlation_id,
                    'sync_mode' => $fresh->mode, 'status' => 'retrying',
                    'heartbeat_at' => now(), 'lease_owner' => null, 'lease_expires_at' => null,
                    'completed_at' => null,
                    // This is a new durable queue chain, not a continuation
                    // of the completed batch. Never carry an expired retry
                    // window into the newly reconstructed delivery.
                    'delivery_retry_deadline_at' => now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12)),
                ]);
            } else {
                // This checkpoint is the durable outbox marker before queue
                // dispatch; a later sweep sees it and cannot clone the chain.
                ZohoSyncCheckpoint::query()->create([
                    'module' => 'v2:'.$moduleKey, 'submodule' => $submodule,
                    'sync_batch_id' => $fresh->id, 'correlation_id' => $fresh->correlation_id,
                    'sync_mode' => $fresh->mode, 'status' => 'retrying',
                    'heartbeat_at' => now(), 'counters' => [], 'generation' => 0,
                    'delivery_retry_deadline_at' => now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12)),
                ]);
            }
            $fresh->update(['status' => 'running', 'started_at' => $fresh->started_at ?? now()]);

            return true;
        });
    }

    private function checkpointIsRecoverable(ZohoSyncCheckpoint $checkpoint): bool
    {
        if ($checkpoint->lease_expires_at?->isFuture()) {
            return false;
        }

        $staleBefore = now()->subSeconds(
            $checkpoint->status === 'retrying' ? $this->retryingStaleSeconds() : $this->initialStaleSeconds(),
        );

        // A lease expiring while its worker is still heartbeating is not an
        // orphan. Reclaim only when both ownership signals are stale.
        return $checkpoint->heartbeat_at === null || $checkpoint->heartbeat_at->lessThanOrEqualTo($staleBefore);
    }

    private function initialStaleSeconds(): int
    {
        return max(1, (int) config('zoho-v2.module.recovery_stale_seconds', 60));
    }

    private function retryingStaleSeconds(): int
    {
        return max(
            $this->initialStaleSeconds(),
            (int) config('zoho-v2.module.retrying_recovery_stale_seconds', 1860),
        );
    }

    private function finalizeTerminalBatches(): void
    {
        foreach (ZohoSyncBatch::query()->whereNull('completed_at')
            ->whereIn('status', ['queued', 'pending', 'running'])
            ->orderBy('id')->limit(100)->pluck('id') as $batchId) {
            app(ZohoSyncOrchestrator::class)->finalizeBatch((int) $batchId);
        }
    }
}
