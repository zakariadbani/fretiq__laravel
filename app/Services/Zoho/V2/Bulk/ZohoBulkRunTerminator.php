<?php

namespace App\Services\Zoho\V2\Bulk;

use App\Models\Zoho\ZohoBulkReadJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\Zoho\ZohoSyncWorkItem;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Idempotently closes abandoned Bulk runs without deleting queue evidence. */
class ZohoBulkRunTerminator
{
    private const REASONS = ['kill_switch', 'delivery_exhausted'];

    public function __construct(
        private readonly ZohoModuleRegistry $registry,
        private readonly ZohoSyncOrchestrator $orchestrator,
        private readonly ?Closure $afterChunkCommitted = null,
    ) {}

    public function terminate(
        int $batchId,
        string $module,
        string $correlationId,
        string $runOwner,
        string $deliveryOwner,
        int $deliveryGeneration,
        string $reason,
    ): bool {
        if (! in_array($reason, self::REASONS, true)
            || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $runOwner) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $deliveryOwner) !== 1) {
            return false;
        }

        $submodule = $this->registry->get($module)->submodule ?? '';
        $state = $this->beginTerminalization(
            $batchId,
            $module,
            $submodule,
            $correlationId,
            $runOwner,
            $deliveryOwner,
            max(0, $deliveryGeneration),
            $reason,
        );
        if ($state === false) {
            return false;
        }
        if ($state['already_terminal']) {
            $this->orchestrator->finalizeBatch($batchId);

            return true;
        }

        while (($processed = $this->terminalizeNextChunk(
            $batchId,
            $module,
            $submodule,
            $correlationId,
            $runOwner,
            $deliveryOwner,
            max(0, $deliveryGeneration),
        )) > 0) {
            if ($this->afterChunkCommitted !== null) {
                ($this->afterChunkCommitted)($processed);
            }
        }

        $terminated = $this->finishTerminalization(
            $batchId,
            $module,
            $submodule,
            $correlationId,
            $runOwner,
            $deliveryOwner,
            max(0, $deliveryGeneration),
            $reason,
        );
        if ($terminated) {
            $this->orchestrator->finalizeBatch($batchId);
        }

        return $terminated;
    }

    /**
     * Persist a durable recovery marker before touching any work row. The
     * marker and each later chunk commit independently, so a worker death can
     * never roll an arbitrarily large terminalization back to "running".
     *
     * @return array{already_terminal:bool}|false
     */
    private function beginTerminalization(
        int $batchId,
        string $module,
        string $submodule,
        string $correlationId,
        string $runOwner,
        string $deliveryOwner,
        int $deliveryGeneration,
        string $reason,
    ): array|false {
        return DB::transaction(function () use (
            $batchId,
            $module,
            $submodule,
            $correlationId,
            $runOwner,
            $deliveryOwner,
            $deliveryGeneration,
            $reason,
        ): array|false {
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($batchId);
            if ($batch === null
                || ! hash_equals((string) $batch->correlation_id, $correlationId)
                || ! in_array($module, (array) $batch->modules, true)) {
                return false;
            }

            $root = $this->root($batchId, $module, $submodule)->lockForUpdate()->first();
            if (! $root instanceof ZohoBulkReadJob) {
                $startedAt = $batch->started_at ?? now();
                $root = ZohoBulkReadJob::query()->create([
                    'sync_batch_id' => $batchId,
                    'module' => $module,
                    'submodule' => $submodule,
                    'page_key' => 'root',
                    'correlation_id' => $correlationId,
                    'status' => 'queued',
                    'module_lease_owner' => $runOwner,
                    'delivery_generation' => $deliveryGeneration,
                    'started_at' => $startedAt,
                    'watermark_at' => $startedAt,
                    'counters' => $this->emptyPageCounters(),
                ]);
            }

            // Atomic child handoff reserves its generation before the parent
            // returns. An ancestor's delayed failed() callback must be a no-op.
            if (! hash_equals((string) $root->module_lease_owner, $runOwner)
                || (int) $root->delivery_generation > $deliveryGeneration) {
                return false;
            }

            $existingLog = $this->moduleLog($batchId, $module, $submodule)->lockForUpdate()->first();
            if ($existingLog !== null
                && ! $this->pages($batchId, $module, $submodule)
                    ->whereNotIn('status', ['complete', 'partial', 'superseded'])
                    ->exists()) {
                return ['already_terminal' => true];
            }

            $recovering = $this->pages($batchId, $module, $submodule)
                ->where('status', 'terminalizing')
                ->exists();
            if ($recovering && $root->lease_expires_at?->isFuture()
                && ! hash_equals((string) $root->lease_owner, $deliveryOwner)) {
                return false;
            }
            if (! $recovering && $this->pages($batchId, $module, $submodule)
                ->where('lease_expires_at', '>', now())
                ->where('lease_owner', '!=', $deliveryOwner)
                ->exists()) {
                return false;
            }

            if (! $recovering && $this->pendingWork($batchId, $module, $submodule)
                ->where('status', 'processing')
                ->where('lease_expires_at', '>', now())
                ->where('lease_owner', '!=', $deliveryOwner)
                ->exists()) {
                return false;
            }

            $startedAt = $batch->started_at ?? now();
            $batch->update(['status' => 'running', 'started_at' => $startedAt]);
            $this->pages($batchId, $module, $submodule)
                ->where('status', '!=', 'superseded')
                ->update([
                    'status' => 'terminalizing',
                    'error_summary' => 'Bulk backfill stopped; recovery is pending.',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'retry_after' => now()->addSeconds($this->terminalizationRetrySeconds()),
                    'heartbeat_at' => now(),
                    'completed_at' => null,
                ]);
            $storedReason = in_array($root->terminalization_reason, self::REASONS, true)
                ? $root->terminalization_reason
                : $reason;
            $allowedSources = ['feature_gate', 'framework_failed_job'];
            $source = data_get($root->terminalization_context, 'source');
            if (! in_array($source, $allowedSources, true)) {
                $source = $storedReason === 'kill_switch' ? 'feature_gate' : 'framework_failed_job';
            }
            $root->refresh()->update([
                'terminalization_reason' => $storedReason,
                'terminalization_context' => [
                    'version' => 1,
                    'source' => $source,
                    'delivery_generation' => (int) $root->delivery_generation,
                ],
                'lease_owner' => $deliveryOwner,
                'lease_expires_at' => now()->addSeconds($this->terminalizationLeaseSeconds()),
                'retry_after' => now()->addSeconds($this->terminalizationRetrySeconds()),
                'heartbeat_at' => now(),
            ]);

            return ['already_terminal' => false];
        });
    }

    /** Terminalize at most one configured chunk in its own transaction. */
    private function terminalizeNextChunk(
        int $batchId,
        string $module,
        string $submodule,
        string $correlationId,
        string $runOwner,
        string $deliveryOwner,
        int $deliveryGeneration,
    ): int {
        return DB::transaction(function () use (
            $batchId,
            $module,
            $submodule,
            $correlationId,
            $runOwner,
            $deliveryOwner,
            $deliveryGeneration,
        ): int {
            $root = $this->root($batchId, $module, $submodule)->lockForUpdate()->first();
            if (! $this->ownsTerminalization(
                $root,
                $correlationId,
                $runOwner,
                $deliveryOwner,
                $deliveryGeneration,
            )) {
                return 0;
            }

            $workItems = $this->pendingWork($batchId, $module, $submodule)
                ->orderBy('zoho_sync_work_items.id')
                ->limit($this->terminationChunkSize())
                ->lockForUpdate()
                ->get();
            if ($workItems->isEmpty()) {
                return 0;
            }

            $pagesById = ZohoBulkReadJob::query()
                ->whereIn('id', $workItems->pluck('zoho_bulk_read_job_id')->unique())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($workItems as $work) {
                $work->update([
                    'status' => 'quarantined',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'retry_after' => null,
                    'heartbeat_at' => now(),
                ]);
                $page = $pagesById->get($work->zoho_bulk_read_job_id);
                if ($page instanceof ZohoBulkReadJob) {
                    $this->upsertRecordFailure($page, $work);
                }
            }

            $root->update([
                'heartbeat_at' => now(),
                'lease_expires_at' => now()->addSeconds($this->terminalizationLeaseSeconds()),
                'retry_after' => now()->addSeconds($this->terminalizationRetrySeconds()),
            ]);

            return $workItems->count();
        });
    }

    /** Commit the one module log/checkpoint only after no pending work remains. */
    private function finishTerminalization(
        int $batchId,
        string $module,
        string $submodule,
        string $correlationId,
        string $runOwner,
        string $deliveryOwner,
        int $deliveryGeneration,
        string $reason,
    ): bool {
        return DB::transaction(function () use (
            $batchId,
            $module,
            $submodule,
            $correlationId,
            $runOwner,
            $deliveryOwner,
            $deliveryGeneration,
            $reason,
        ): bool {
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($batchId);
            $root = $this->root($batchId, $module, $submodule)->lockForUpdate()->first();
            if ($batch === null
                || ! hash_equals((string) $batch->correlation_id, $correlationId)
                || ! $this->ownsTerminalization(
                    $root,
                    $correlationId,
                    $runOwner,
                    $deliveryOwner,
                    $deliveryGeneration,
                )
                || $this->pendingWork($batchId, $module, $submodule)->exists()) {
                return false;
            }

            $counters = is_array($root->counters) ? $root->counters : $this->emptyPageCounters();
            $counters['export_failed'] = max(1, (int) ($counters['export_failed'] ?? 0));
            $root->update(['counters' => $counters]);
            $this->pages($batchId, $module, $submodule)
                ->where('status', '!=', 'superseded')
                ->update([
                    'status' => 'partial',
                    'error_summary' => 'Bulk backfill stopped; see correlation ID.',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'retry_after' => null,
                    'heartbeat_at' => now(),
                    'completed_at' => now(),
                ]);
            $root->refresh();
            $this->upsertExportFailure($root);

            $counts = $this->aggregate($batchId, $module, $submodule);
            $checkpoint = ZohoSyncCheckpoint::query()
                ->where('module', 'v2:'.$module)
                ->where('submodule', $submodule)
                ->lockForUpdate()
                ->first();
            if ($checkpoint === null) {
                $checkpoint = ZohoSyncCheckpoint::query()->create([
                    'module' => 'v2:'.$module,
                    'submodule' => $submodule,
                    'sync_mode' => 'backfill',
                    'status' => 'idle',
                ]);
            }
            if ($checkpoint->correlation_id === null
                || hash_equals((string) $checkpoint->correlation_id, $correlationId)) {
                $checkpoint->update([
                    'sync_mode' => 'backfill',
                    'status' => 'failed',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => now(),
                    'completed_at' => now(),
                    'counters' => $counts,
                    'correlation_id' => $correlationId,
                ]);
            }

            $startedAt = $batch->started_at ?? $root->started_at ?? now();
            $values = [
                'module' => $module,
                'submodule' => $submodule,
                'mode' => 'backfill',
                'sync_batch_id' => $batchId,
                'correlation_id' => $correlationId,
                'synced_at' => now(),
                'records_seen' => $counts['seen'],
                'records_synced' => $counts['created'] + $counts['updated'] + $counts['unchanged'],
                'records_created' => $counts['created'],
                'records_updated' => $counts['updated'],
                'records_unchanged' => $counts['unchanged'],
                'records_quarantined' => $counts['quarantined'],
                'status' => 'error',
                'error' => 'Bulk backfill stopped; see correlation ID.',
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
                'cursor_at' => $root->watermark_at ?? $startedAt,
                'api_requests' => $counts['api_requests'],
                'telemetry' => ['bulk' => ['terminalized' => true, 'reason' => $reason]],
            ];
            $log = $this->moduleLog($batchId, $module, $submodule)->lockForUpdate()->first();
            $log === null ? ZohoSyncLog::query()->create($values) : $log->update($values);

            return true;
        });
    }

    /** @return array{seen:int,created:int,updated:int,unchanged:int,quarantined:int,api_requests:int} */
    private function aggregate(int $batchId, string $module, string $submodule): array
    {
        $work = $this->allWork($batchId, $module, $submodule);
        $pageApiRequests = 0;
        $this->pages($batchId, $module, $submodule)
            ->select(['id', 'counters'])
            ->orderBy('id')
            ->chunkById($this->terminationChunkSize(), function ($pages) use (&$pageApiRequests): void {
                foreach ($pages as $page) {
                    $pageApiRequests += (int) data_get($page->counters, 'api_requests', 0);
                }
            });

        return [
            'seen' => (clone $work)->count(),
            'created' => (int) (clone $work)->sum('records_created'),
            'updated' => (int) (clone $work)->sum('records_updated'),
            'unchanged' => (int) (clone $work)->sum('records_unchanged'),
            'quarantined' => (clone $work)->where('status', 'quarantined')->count(),
            'api_requests' => (int) (clone $work)->sum('api_requests') + $pageApiRequests,
        ];
    }

    private function upsertRecordFailure(ZohoBulkReadJob $page, ZohoSyncWorkItem $work): void
    {
        $key = hash('sha256', implode('|', [$page->module, $page->submodule, $work->zoho_id, 'record']));
        $this->upsertFailure($key, $page, 'record', $work->zoho_id, [
            'bulk_job_id' => $page->id,
            'work_item_id' => $work->id,
        ]);
    }

    private function upsertExportFailure(ZohoBulkReadJob $page): void
    {
        $key = hash('sha256', implode('|', [
            'bulk_export',
            $page->sync_batch_id,
            $page->module,
            $page->submodule,
            $page->page_key,
        ]));
        $this->upsertFailure($key, $page, 'bulk_export', null, [
            'bulk_job_id' => $page->id,
            'page_key' => $page->page_key,
        ]);
    }

    /** @param array<string,int|string> $context */
    private function upsertFailure(
        string $key,
        ZohoBulkReadJob $page,
        string $kind,
        ?string $zohoId,
        array $context,
    ): void {
        $failure = ZohoSyncFailure::query()->where('failure_key', $key)->lockForUpdate()->first();
        $values = [
            'sync_batch_id' => $page->sync_batch_id,
            'module' => $page->module,
            'submodule' => $page->submodule,
            'zoho_id' => $zohoId,
            'failure_kind' => $kind,
            'correlation_id' => $page->correlation_id,
            'error_summary' => 'Bulk backfill stopped; see correlation ID.',
            'context' => $context,
            'retry_after' => null,
            'resolved_at' => null,
        ];
        if ($failure === null) {
            ZohoSyncFailure::query()->create($values + ['failure_key' => $key, 'attempts' => 1]);

            return;
        }

        // Recovery is idempotent: reprocessing an already-committed chunk must
        // not fabricate an extra remote attempt in historical telemetry.
        $failure->update($values + ['attempts' => max(1, (int) $failure->attempts)]);
    }

    private function ownsTerminalization(
        ?ZohoBulkReadJob $root,
        string $correlationId,
        string $runOwner,
        string $deliveryOwner,
        int $deliveryGeneration,
    ): bool {
        return $root instanceof ZohoBulkReadJob
            && $root->status === 'terminalizing'
            && hash_equals((string) $root->correlation_id, $correlationId)
            && hash_equals((string) $root->module_lease_owner, $runOwner)
            && hash_equals((string) $root->lease_owner, $deliveryOwner)
            && $root->lease_expires_at?->isFuture()
            && (int) $root->delivery_generation <= $deliveryGeneration;
    }

    /** @return Builder<ZohoBulkReadJob> */
    private function pages(int $batchId, string $module, string $submodule): Builder
    {
        return ZohoBulkReadJob::query()
            ->where('sync_batch_id', $batchId)
            ->where('module', $module)
            ->where('submodule', $submodule);
    }

    /** @return Builder<ZohoBulkReadJob> */
    private function root(int $batchId, string $module, string $submodule): Builder
    {
        return $this->pages($batchId, $module, $submodule)->where('page_key', 'root');
    }

    /** @return Builder<ZohoSyncWorkItem> */
    private function allWork(int $batchId, string $module, string $submodule): Builder
    {
        return ZohoSyncWorkItem::query()->whereIn(
            'zoho_bulk_read_job_id',
            $this->pages($batchId, $module, $submodule)->select('id'),
        );
    }

    /** @return Builder<ZohoSyncWorkItem> */
    private function pendingWork(int $batchId, string $module, string $submodule): Builder
    {
        return $this->allWork($batchId, $module, $submodule)
            ->whereIn('status', ['queued', 'processing', 'retry_wait']);
    }

    /** @return Builder<ZohoSyncLog> */
    private function moduleLog(int $batchId, string $module, string $submodule): Builder
    {
        return ZohoSyncLog::query()
            ->where('sync_batch_id', $batchId)
            ->where('module', $module)
            ->where('submodule', $submodule)
            ->where('mode', 'backfill');
    }

    /** @return array{staged:int,reported:int,parsed:int,api_requests:int,export_failed:int,token_restarts:int} */
    private function emptyPageCounters(): array
    {
        return [
            'staged' => 0,
            'reported' => 0,
            'parsed' => 0,
            'api_requests' => 0,
            'export_failed' => 0,
            'token_restarts' => 0,
        ];
    }

    private function terminationChunkSize(): int
    {
        return max(1, min(1_000, (int) config('zoho-v2.bulk.termination_chunk_size', 500)));
    }

    private function terminalizationRetrySeconds(): int
    {
        return max(60, min(3_600, (int) config('zoho-v2.bulk.failure_retry_seconds', 300)));
    }

    private function terminalizationLeaseSeconds(): int
    {
        return max(60, (int) config('zoho-v2.bulk.lease_seconds', 1_500));
    }
}
