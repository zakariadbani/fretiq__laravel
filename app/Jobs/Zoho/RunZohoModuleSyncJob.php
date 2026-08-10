<?php

declare(strict_types=1);

namespace App\Jobs\Zoho;

use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Services\Zoho\V2\Bulk\ZohoModuleDispatcher;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class RunZohoModuleSyncJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public readonly string $retryDeadline;

    /** Immutable identity for this serialized queue delivery. */
    public readonly string $deliveryToken;

    public function __construct(
        public readonly int $batchId,
        public readonly string $module,
        public readonly string $mode,
        public readonly string $correlationId,
        ?string $retryDeadline = null,
        ?string $deliveryToken = null,
        public readonly ?int $checkpointGeneration = null,
    ) {
        $this->tries = (int) config('zoho-v2.retry.max_attempts', 5);
        $this->timeout = max(60, (int) config('zoho-v2.module.delivery_timeout_seconds', 1200));
        $this->retryDeadline = $retryDeadline
            ?? now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12))->toIso8601String();
        $this->deliveryToken = $deliveryToken ?? 'module:'.Str::uuid();
        $this->onConnection((string) config('zoho-v2.queue_connection', 'zoho'));
        $this->onQueue((string) config('zoho-v2.queue', 'zoho'));
    }

    public function uniqueId(): string
    {
        // Suppress duplicate deliveries inside one batch without orphaning a
        // later batch. Cross-batch module exclusion is enforced by the shared
        // durable checkpoint lease, not by a queue lock that can drop work.
        return $this->batchId.':'.$this->module.':g'.($this->checkpointGeneration ?? 0);
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('zoho-v2.module.lease_seconds', 1500));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('zoho-v2.retry.backoff_seconds', [60, 300, 900, 1800]);
    }

    public function retryUntil(): \DateTime
    {
        return CarbonImmutable::parse($this->retryDeadline)->toDateTime();
    }

    public function handle(): void
    {
        $batch = ZohoSyncBatch::query()->find($this->batchId);
        if ($batch === null
            || $batch->completed_at !== null
            || ! in_array($batch->status, ['queued', 'running'], true)
            || $batch->mode !== $this->mode
            || ! in_array($this->module, (array) $batch->modules, true)
            || ! hash_equals((string) $batch->correlation_id, $this->correlationId)
            || ! $this->preparedGenerationIsCurrent()) {
            return;
        }

        /** @var ZohoSyncOrchestrator $orchestrator */
        $orchestrator = app(ZohoSyncOrchestrator::class);
        $result = $orchestrator->runModule($this->batchId, $this->module, $this->mode, $this->deliveryToken, $this->checkpointGeneration);
        if ($result->ignoredDelivery) {
            return;
        }
        if ($result->leaseConflict) {
            $this->release(max(1, $result->retryAfterSeconds ?? (int) config('zoho-v2.lease_conflict_retry_seconds', 60)));

            return;
        }
        if ($result->continuationRequired) {
            app(ZohoModuleDispatcher::class)->dispatch(
                $this->batchId,
                $this->module,
                $this->mode,
                $this->correlationId,
                $this->retryDeadline,
                max(
                    0,
                    (int) config('zoho-v2.module.continuation_delay_seconds', 5),
                    $result->retryAfterSeconds ?? 0,
                ),
            );

            return;
        }
        if ($result->retryableFailure) {
            // Do not create or finalize a terminal module log here: Laravel
            // will retry this delivery, and `failed()` owns terminalization.
            throw new \RuntimeException('Zoho V2 module synchronization is retryable; see correlation ID.');
        }
        $syncStatus = ZohoModuleRunOutcome::syncStatus($this->batchId, $this->module);
        if ($this->mode === 'reconcile' && $result->reconciliation !== null) {
            $syncStatus = ZohoModuleRunOutcome::recordReconciliation(
                $this->batchId,
                $this->module,
                $result->reconciliation,
            );
        } elseif ($this->mode === 'reconcile'
            && $this->module !== 'quoted_items'
            && $syncStatus !== 'error') {
            throw new \RuntimeException('Zoho V2 reconciliation failed; see correlation ID.');
        }

        if ($syncStatus === 'error') {
            throw new \RuntimeException(
                $this->mode === 'reconcile'
                    ? 'Zoho V2 reconciliation failed; see correlation ID.'
                    : 'Zoho V2 module synchronization failed; see correlation ID.',
            );
        }

        $orchestrator->finalizeBatch($this->batchId);
    }

    public function failed(Throwable $exception): void
    {
        try {
            $batch = ZohoSyncBatch::query()->find($this->batchId);
            if ($batch === null
                || $batch->completed_at !== null
                || ! in_array($batch->status, ['queued', 'running'], true)
                || $batch->mode !== $this->mode
                || ! in_array($this->module, (array) $batch->modules, true)
                || ! hash_equals((string) $batch->correlation_id, $this->correlationId)
                || ! $this->preparedGenerationIsCurrent()) {
                return;
            }
            app(ZohoSyncOrchestrator::class)->terminalizeModule(
                $this->batchId,
                $this->module,
                $this->mode,
                'job_exhausted',
                $this->deliveryToken,
                $this->checkpointGeneration,
            );
        } catch (Throwable) {
            // Framework failed-job evidence remains authoritative even if the
            // database is unavailable during terminalization.
        }
        Log::error('Zoho V2 module sync job exhausted.', [
            'batch_id' => $this->batchId,
            'module' => $this->module,
            'mode' => $this->mode,
            'correlation_id' => $this->correlationId,
            'exception' => $exception::class,
        ]);
    }

    private function preparedGenerationIsCurrent(): bool
    {
        if ($this->checkpointGeneration === null) {
            return true;
        }

        $definition = app(ZohoModuleRegistry::class)->get($this->module);

        return ZohoSyncCheckpoint::query()
            ->where('module', 'v2:'.$definition->key)
            ->where('submodule', $definition->submodule ?? '')
            ->where('sync_batch_id', $this->batchId)
            ->where('correlation_id', $this->correlationId)
            ->where('sync_mode', $this->mode)
            ->where('generation', $this->checkpointGeneration)
            ->whereIn('status', ['queued', 'running', 'retrying'])
            ->exists();
    }
}
