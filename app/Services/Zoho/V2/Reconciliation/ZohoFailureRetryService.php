<?php

namespace App\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoBulkReadJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\Zoho\ZohoSyncWorkItem;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Sync\ZohoMirrorMutationGuard;
use App\Services\Zoho\V2\Sync\ZohoMirrorMutationLease;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ZohoFailureRetryService
{
    private const BULK_RETRY_MULTIPLIER = 2;

    private ZohoMirrorMutationGuard $mutationGuard;

    public function __construct(
        private FailedRecordHydrator $hydrator,
        ?ZohoMirrorMutationGuard $mutationGuard = null,
    ) {
        $this->mutationGuard = $mutationGuard ?? app(ZohoMirrorMutationGuard::class);
    }

    /** @return array{attempted:int,resolved:int,deferred:int} */
    public function retry(?string $moduleKey = null, int $limit = 100): array
    {
        $maxAttempts = min(1_000, max(1, (int) config('zoho-v2.retry.max_attempts', 5)));
        $batchSize = min(1_000, max(1, (int) config('zoho-v2.retry.batch_size', $limit)));
        $limit = min(max(1, $limit), $batchSize);
        $attempted = 0;
        $resolved = 0;
        $deferred = 0;

        $query = ZohoSyncFailure::query()
            ->whereNull('resolved_at')
            ->where('failure_kind', 'record')
            ->whereNotNull('zoho_id')
            ->where('module', '!=', 'quoted_items')
            // A linked Bulk work item gets a second, still-bounded manual
            // retry window after its internal delivery attempts are exhausted.
            ->where('attempts', '<', $maxAttempts * self::BULK_RETRY_MULTIPLIER)
            ->where(fn ($due) => $due
                ->whereNull('retry_after')
                ->orWhere('retry_after', '<=', now()));
        if ($moduleKey !== null) {
            $query->where('module', $moduleKey);
        }

        $chunkSize = min(500, max(50, $limit * 2));
        $query->chunkById($chunkSize, function (Collection $failures) use (
            &$attempted,
            &$resolved,
            &$deferred,
            $limit,
            $maxAttempts,
        ): bool {
            foreach ($failures as $candidate) {
                if ($attempted >= $limit) {
                    return false;
                }

                $lease = $this->mutationGuard->claim(
                    (string) $candidate->module,
                    'failure-retry',
                );
                if ($lease === null) {
                    $deferred++;

                    continue;
                }

                try {
                    $claim = $this->claim((int) $candidate->id, $maxAttempts);
                    if ($claim === null) {
                        continue;
                    }

                    [$failure] = $claim;
                    $attempted++;
                    try {
                        $hydration = $this->hydrator->fetch(
                            $failure->module,
                            $failure->zoho_id,
                            (int) ($failure->sync_batch_id ?? 0),
                            (string) ($failure->correlation_id ?? 'retry-'.$failure->id),
                        );
                    } catch (Throwable) {
                        $hydration = FailedRecordHydrationResult::failed();
                    }

                    if ($hydration->successful) {
                        $persistenceFailed = false;
                        try {
                            $finished = $this->finishClaim(
                                (int) $failure->id,
                                (int) $failure->attempts,
                                $hydration,
                                $this->backoffSeconds((int) $failure->attempts),
                                $lease,
                                true,
                            );
                        } catch (Throwable) {
                            $persistenceFailed = true;
                            // A failed persistence transaction rolled back mirror,
                            // work, failure, counters and health together. Only
                            // the prior durable claim remains and is safely deferred.
                            try {
                                $finished = $this->finishClaim(
                                    (int) $failure->id,
                                    (int) $failure->attempts,
                                    FailedRecordHydrationResult::failed($hydration->apiRequests),
                                    $this->backoffSeconds((int) $failure->attempts),
                                    $lease,
                                );
                            } catch (Throwable) {
                                // Keep the durable five-minute claim. A later
                                // delivery retries it without aborting this batch.
                                $finished = false;
                                $deferred++;
                            }
                        }
                        if ($finished) {
                            if ($persistenceFailed) {
                                $deferred++;
                            } else {
                                $resolved++;
                            }
                        }

                        continue;
                    }

                    $seconds = $this->backoffSeconds((int) $failure->attempts);
                    try {
                        $deferred += $this->finishClaim(
                            (int) $failure->id,
                            (int) $failure->attempts,
                            $hydration,
                            $seconds,
                            $lease,
                        ) ? 1 : 0;
                    } catch (Throwable) {
                        // Ownership may have moved while the remote fetch was
                        // in flight. Keep only the earlier durable claim; the
                        // stale delivery must not update retry/Bulk health.
                        $deferred++;
                    }
                } finally {
                    $this->mutationGuard->release($lease);
                }
            }

            return $attempted < $limit;
        });

        return compact('attempted', 'resolved', 'deferred');
    }

    /** @return array{ZohoSyncFailure}|null */
    private function claim(int $id, int $maxAttempts): ?array
    {
        return DB::transaction(function () use ($id, $maxAttempts): ?array {
            $failure = ZohoSyncFailure::query()->lockForUpdate()->find($id);
            if ($failure === null
                || $failure->resolved_at !== null
                || $failure->retry_after?->isFuture()
                || (int) $failure->attempts >= $this->retryCeiling($failure, $maxAttempts)) {
                return null;
            }

            $leaseUntil = now()->addMinutes(5)->startOfSecond();
            $failure->update([
                'attempts' => ((int) $failure->attempts) + 1,
                'retry_after' => $leaseUntil,
            ]);

            return [$failure->fresh()];
        });
    }

    private function retryCeiling(ZohoSyncFailure $failure, int $maxAttempts): int
    {
        return $this->linkedBulkContextExists($failure)
            ? $maxAttempts * self::BULK_RETRY_MULTIPLIER
            : $maxAttempts;
    }

    private function backoffSeconds(int $attempts): int
    {
        $backoff = array_values((array) config('zoho-v2.retry.backoff_seconds', [60, 300, 900, 1800]));

        return max(1, (int) ($backoff[min($attempts, max(0, count($backoff) - 1))] ?? 3600));
    }

    private function finishClaim(
        int $id,
        int $attempts,
        FailedRecordHydrationResult $hydration,
        int $backoffSeconds,
        ZohoMirrorMutationLease $lease,
        bool $persist = false,
    ): bool {
        return DB::transaction(function () use ($id, $attempts, $hydration, $backoffSeconds, $lease, $persist): bool {
            // Always acquire the module fence before failure/page/work locks.
            // This uniform order also covers failed fetches, which never call
            // hydrator persistence but still mutate retry and Bulk health.
            $this->mutationGuard->assertOwned($lease);
            $failure = ZohoSyncFailure::query()->lockForUpdate()->find($id);
            if ($failure === null
                || $failure->resolved_at !== null
                || (int) $failure->attempts !== $attempts) {
                return false;
            }

            $persisted = $hydration;
            if ($persist && $hydration->successful) {
                $persisted = $this->hydrator->persist(
                    (string) $failure->module,
                    (int) ($failure->sync_batch_id ?? 0),
                    $hydration,
                );
            }

            if (! $persisted->successful) {
                $failure->update(['retry_after' => now()->addSeconds($backoffSeconds)]);
                $this->recordLinkedBulkAttempt($failure->fresh(), $persisted);

                return true;
            }

            $failure->update(['resolved_at' => now(), 'retry_after' => null]);
            $this->recordLinkedBulkAttempt($failure->fresh(), $persisted);

            return true;
        });
    }

    private function linkedBulkContextExists(ZohoSyncFailure $failure): bool
    {
        $context = $this->bulkContext($failure);
        if ($context === null) {
            return false;
        }

        [$bulkJobId, $workItemId] = $context;
        $page = ZohoBulkReadJob::query()
            ->whereKey($bulkJobId)
            ->where('sync_batch_id', $failure->sync_batch_id)
            ->where('module', $failure->module)
            ->where('submodule', $failure->submodule ?? '')
            ->first(['id']);
        if ($page === null) {
            return false;
        }

        return ZohoSyncWorkItem::query()
            ->whereKey($workItemId)
            ->where('zoho_bulk_read_job_id', $page->id)
            ->where('module', $failure->module)
            ->where('zoho_id', $failure->zoho_id)
            ->where('status', 'quarantined')
            ->exists();
    }

    private function recordLinkedBulkAttempt(
        ZohoSyncFailure $failure,
        FailedRecordHydrationResult $hydration,
    ): void {
        $context = $this->bulkContext($failure);
        if ($context === null) {
            return;
        }

        [$bulkJobId, $workItemId] = $context;
        $page = ZohoBulkReadJob::query()
            ->whereKey($bulkJobId)
            ->where('sync_batch_id', $failure->sync_batch_id)
            ->where('module', $failure->module)
            ->where('submodule', $failure->submodule ?? '')
            ->lockForUpdate()
            ->first();
        if ($page === null) {
            return;
        }

        $work = ZohoSyncWorkItem::query()
            ->whereKey($workItemId)
            ->where('zoho_bulk_read_job_id', $page->id)
            ->where('module', $failure->module)
            ->where('zoho_id', $failure->zoho_id)
            ->lockForUpdate()
            ->first();
        if ($work === null || ! in_array($work->status, ['quarantined', 'completed'], true)) {
            return;
        }

        if ($work->status === 'quarantined') {
            $work->update([
                'status' => $hydration->successful ? 'completed' : 'quarantined',
                'attempts' => ((int) $work->attempts) + 1,
                'api_requests' => ((int) $work->api_requests) + $hydration->apiRequests,
                'records_created' => ((int) $work->records_created) + $hydration->created,
                'records_updated' => ((int) $work->records_updated) + $hydration->updated,
                'records_unchanged' => ((int) $work->records_unchanged) + $hydration->unchanged,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'retry_after' => null,
                'heartbeat_at' => now(),
                'processed_at' => $hydration->successful ? now() : $work->processed_at,
            ]);
        }

        $this->recomputeBulkHealth($failure, $page);
    }

    private function recomputeBulkHealth(ZohoSyncFailure $failure, ZohoBulkReadJob $recoveredPage): void
    {
        $pages = ZohoBulkReadJob::query()
            ->where('sync_batch_id', $recoveredPage->sync_batch_id)
            ->where('module', $recoveredPage->module)
            ->where('submodule', $recoveredPage->submodule)
            ->lockForUpdate()
            ->get();
        $pageIds = $pages->pluck('id');
        $work = ZohoSyncWorkItem::query()->whereIn('zoho_bulk_read_job_id', $pageIds);
        $unresolvedExportFailures = ZohoSyncFailure::query()
            ->where('sync_batch_id', $recoveredPage->sync_batch_id)
            ->where('module', $recoveredPage->module)
            ->where('submodule', $recoveredPage->submodule)
            ->where('failure_kind', 'bulk_export')
            ->whereNull('resolved_at')
            ->count();

        foreach ($pages as $page) {
            $hasPending = ZohoSyncWorkItem::query()
                ->where('zoho_bulk_read_job_id', $page->id)
                ->whereIn('status', ['queued', 'processing', 'retry_wait'])
                ->exists();
            $hasQuarantine = ZohoSyncWorkItem::query()
                ->where('zoho_bulk_read_job_id', $page->id)
                ->where('status', 'quarantined')
                ->exists();
            $exportFailed = (int) data_get($page->counters, 'export_failed', 0) > 0;
            $page->update([
                'status' => ($hasPending || $hasQuarantine || $exportFailed || $unresolvedExportFailures > 0)
                    ? 'partial'
                    : 'complete',
                'completed_at' => $page->completed_at ?? now(),
            ]);
        }

        $seen = (clone $work)->count();
        $quarantined = (clone $work)->where('status', 'quarantined')->count();
        $pending = (clone $work)->whereIn('status', ['queued', 'processing', 'retry_wait'])->count();
        $created = (int) (clone $work)->sum('records_created');
        $updated = (int) (clone $work)->sum('records_updated');
        $unchanged = (int) (clone $work)->sum('records_unchanged');
        $recordApiRequests = (int) (clone $work)->sum('api_requests');
        $exportApiRequests = (int) $pages->sum(
            fn (ZohoBulkReadJob $page): int => (int) data_get($page->counters, 'api_requests', 0),
        );
        $exportFailures = (int) $pages->sum(
            fn (ZohoBulkReadJob $page): int => (int) data_get($page->counters, 'export_failed', 0),
        );
        $unresolvedRecordFailures = ZohoSyncFailure::query()
            ->where('sync_batch_id', $recoveredPage->sync_batch_id)
            ->where('module', $recoveredPage->module)
            ->where('submodule', $recoveredPage->submodule)
            ->where('failure_kind', 'record')
            ->whereNull('resolved_at')
            ->count();
        $status = match (true) {
            $exportFailures > 0, $unresolvedExportFailures > 0 => 'error',
            $pending > 0, $quarantined > 0, $unresolvedRecordFailures > 0 => 'partial',
            default => 'success',
        };
        $counters = [
            'seen' => $seen,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'quarantined' => $quarantined,
            'api_requests' => $recordApiRequests + $exportApiRequests,
        ];

        $log = ZohoSyncLog::query()
            ->where('sync_batch_id', $recoveredPage->sync_batch_id)
            ->where('module', $recoveredPage->module)
            ->where('submodule', $recoveredPage->submodule)
            ->where('mode', 'backfill')
            ->lockForUpdate()
            ->latest('id')
            ->first();
        if ($log === null) {
            return;
        }

        $telemetry = is_array($log->telemetry) ? $log->telemetry : [];
        $telemetry['bulk'] = [
            'pages' => $pages->count(),
            'export_failures' => $exportFailures,
        ];
        $log->update([
            'records_seen' => $seen,
            'records_synced' => $created + $updated + $unchanged,
            'records_created' => $created,
            'records_updated' => $updated,
            'records_unchanged' => $unchanged,
            'records_quarantined' => $quarantined,
            'status' => $status,
            'error' => $status === 'success' ? null : 'Bulk backfill degraded; see correlation ID.',
            'api_requests' => $recordApiRequests + $exportApiRequests,
            'telemetry' => $telemetry,
        ]);

        $checkpoint = ZohoSyncCheckpoint::query()
            ->where('module', 'v2:'.$recoveredPage->module)
            ->where('submodule', $recoveredPage->submodule)
            ->lockForUpdate()
            ->first();
        if ($checkpoint !== null
            && hash_equals((string) $recoveredPage->correlation_id, (string) $checkpoint->correlation_id)) {
            $checkpoint->update([
                'status' => $status === 'success' ? 'completed' : ($status === 'partial' ? 'partial' : 'failed'),
                'heartbeat_at' => now(),
                'completed_at' => now(),
                'counters' => $counters,
            ]);
        }

        $this->recomputeBatchHealth((int) $recoveredPage->sync_batch_id);
    }

    private function recomputeBatchHealth(int $batchId): void
    {
        $batch = ZohoSyncBatch::query()->lockForUpdate()->find($batchId);
        if ($batch === null) {
            return;
        }

        $expected = array_values(array_unique(array_filter(
            (array) $batch->modules,
            static fn ($module): bool => is_string($module) && $module !== '',
        )));
        $logs = ZohoSyncLog::query()
            ->where('sync_batch_id', $batchId)
            ->latest('id')
            ->get()
            ->unique(fn (ZohoSyncLog $log): string => $log->module.'|'.$log->submodule)
            ->values();
        $actualModules = $logs
            ->pluck('module')
            ->unique()
            ->values()
            ->all();
        if ($expected === [] || array_diff($expected, $actualModules) !== []) {
            return;
        }

        $statuses = $logs->pluck('status');
        $status = $statuses->contains('error')
            ? 'error'
            : ($statuses->contains('partial') ? 'partial' : 'success');
        $counterKeys = [
            'records_seen',
            'records_created',
            'records_updated',
            'records_unchanged',
            'records_quarantined',
            'api_requests',
        ];
        $counters = [];
        foreach ($counterKeys as $key) {
            $counters[$key] = (int) $logs->sum($key);
        }

        $batch->update([
            'status' => $status,
            'completed_at' => now(),
            'counters' => $counters,
        ]);
    }

    /** @return array{int,int}|null */
    private function bulkContext(ZohoSyncFailure $failure): ?array
    {
        $context = is_array($failure->context) ? $failure->context : [];
        $bulkJobId = $this->boundedIdentifier($context['bulk_job_id'] ?? null);
        $workItemId = $this->boundedIdentifier($context['work_item_id'] ?? null);
        if ($bulkJobId === null
            || $workItemId === null
            || $this->boundedDatabaseIdentifier($failure->sync_batch_id) === null
            || ! is_string($failure->module)
            || strlen($failure->module) > 64
            || ! is_string($failure->zoho_id)
            || $failure->zoho_id === ''
            || strlen($failure->zoho_id) > 100) {
            return null;
        }

        return [$bulkJobId, $workItemId];
    }

    private function boundedIdentifier(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function boundedDatabaseIdentifier(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value)
            || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1
            || strlen($value) === 19 && strcmp($value, (string) PHP_INT_MAX) > 0) {
            return null;
        }

        return (int) $value;
    }
}
