<?php

namespace App\Services\Zoho\V2\Bulk;

use App\Jobs\Zoho\RunZohoBulkBackfillJob;
use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;

/** One dispatch policy shared by console and HTTP operations entry points. */
final class ZohoModuleDispatcher
{
    public function __construct(
        private readonly ZohoModuleRegistry $registry,
        private readonly ?VerifiedBulkModules $verifiedModules = null,
        private readonly ?ZohoSyncOrchestrator $orchestrator = null,
    ) {}

    public function usesBulk(string $module, string $mode): bool
    {
        $definition = $this->registry->get($module);

        return $mode === 'backfill'
            && (bool) config('zoho-v2.features.bulk_backfill_enabled', false)
            && $definition->bulkReadSupported
            && ($this->verifiedModules ?? new VerifiedBulkModules)->allows($module)
            && ! $definition->activationGated;
    }

    public function dispatch(
        int $batchId,
        string $module,
        string $mode,
        string $correlationId,
        ?string $retryDeadline = null,
        int $delaySeconds = 0,
    ): void {
        $queue = (string) config('zoho-v2.queue', 'zoho');
        if ($this->usesBulk($module, $mode)) {
            RunZohoBulkBackfillJob::dispatch($batchId, $module, $correlationId)->delay(now()->addSeconds(max(0, $delaySeconds)))->onQueue($queue);

            return;
        }

        // Unit callers without a persisted batch retain the lightweight
        // dispatch contract; real deliveries first receive a durable outbox
        // generation, which fences late framework failed() callbacks.
        $generation = ($this->orchestrator ?? app(ZohoSyncOrchestrator::class))
            ->prepareModuleDelivery($batchId, $module, $mode, $correlationId, $retryDeadline);
        if ($generation === null) {
            // The durable outbox was not claimed (completed/invalid batch or
            // a foreign live/lease-free unfinished generation). Never enqueue
            // an unfenced fallback or leave the requesting batch orphaned.
            ($this->orchestrator ?? app(ZohoSyncOrchestrator::class))
                ->terminalizeModule($batchId, $module, $mode, 'dispatch_failed');

            return;
        }
        $this->dispatchPrepared($batchId, $module, $mode, $correlationId, $retryDeadline, $generation, $delaySeconds);
    }

    /**
     * Dispatch an already-persisted standard outbox generation.
     *
     * Recovery must use this path: Laravel may suppress the enqueue while an
     * older queued delivery still holds its uniqueness lock. Re-preparing in
     * that window would invalidate the original delivery with no replacement.
     */
    public function dispatchPrepared(
        int $batchId,
        string $module,
        string $mode,
        string $correlationId,
        ?string $retryDeadline,
        int $generation,
        int $delaySeconds = 0,
    ): void {
        $queue = (string) config('zoho-v2.queue', 'zoho');
        RunZohoModuleSyncJob::dispatch($batchId, $module, $mode, $correlationId, $retryDeadline, null, $generation)
            ->delay(now()->addSeconds(max(0, $delaySeconds)))
            ->onQueue($queue);
    }
}
