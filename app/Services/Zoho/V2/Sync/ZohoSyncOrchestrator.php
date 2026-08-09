<?php

namespace App\Services\Zoho\V2\Sync;

use App\Jobs\Zoho\RunZohoPostReconciliationJob;
use App\Jobs\Zoho\ZohoModuleRunOutcome;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\SyncResult;
use App\Services\Zoho\V2\Mappers\ZohoMapperResolver;
use App\Services\Zoho\V2\Reconciliation\ZohoReconciliationService;
use App\Services\Zoho\V2\Registry\ModuleDefinition;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Transport\ZohoThrottleUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Database-first, read-only CRM mirroring coordinator.  The checkpoint is
 * intentionally written only after a page transaction commits: retrying a
 * crashed page is therefore harmless because rows are keyed by Zoho IDs.
 */
class ZohoSyncOrchestrator
{
    private const MODES = ['delta', 'backfill', 'reconcile'];

    public function __construct(
        private readonly ZohoTransport $transport,
        private readonly ZohoModuleRegistry $registry,
        private readonly ZohoMapperResolver $mappers,
        private readonly ZohoRecordIngestor $ingestor,
        private readonly ZohoReconciliationService $reconciliation,
    ) {}

    /** @param list<string> $moduleKeys */
    public function createBatch(array $moduleKeys, string $mode, string $trigger = 'manual', ?int $requestedById = null): ZohoSyncBatch
    {
        $this->validateMode($mode);
        $moduleKeys = array_values(array_unique($moduleKeys));
        foreach ($moduleKeys as $key) {
            $this->registry->get($key);
        }

        return ZohoSyncBatch::create([
            'correlation_id' => (string) Str::uuid(), 'mode' => $mode, 'trigger' => $trigger,
            'status' => 'queued', 'requested_by_id' => $requestedById, 'modules' => array_values($moduleKeys),
            'requested_at' => now(),
        ]);
    }

    public function runModule(int $batchId, string $moduleKey, string $mode, string $workerId, ?int $deliveryGeneration = null): SyncResult
    {
        $this->validateMode($mode);
        $definition = $this->registry->get($moduleKey);
        $batch = ZohoSyncBatch::query()->findOrFail($batchId);
        $started = CarbonImmutable::now();
        $counters = $this->emptyCounters();

        if ($batch->mode !== $mode
            || ! in_array($definition->key, (array) $batch->modules, true)
            || $batch->completed_at !== null) {
            return new SyncResult(
                $counters,
                warnings: ['The sync batch does not accept this module delivery.'],
                ignoredDelivery: true,
            );
        }
        if ($definition->activationGated) {
            return $this->gatedResult($batch, $definition, $mode, $counters, $started);
        }
        if ($definition->key === 'quoted_items') {
            return $this->quoteItemsOwnedResult($batch, $definition, $mode, $counters, $started);
        }

        // A job identifier is not a lease: retries may deliberately reuse it.
        // The queued delivery token is immutable across Laravel retries. It
        // is also the terminalization fence for a late failed() callback.
        $leaseOwner = $workerId;
        $checkpoint = $this->claimCheckpoint($definition, $mode, $leaseOwner, $batch->correlation_id, $batch->id, $deliveryGeneration);
        if ($checkpoint === null) {
            return new SyncResult(
                $counters,
                warnings: ['A sync lease is already active for this module.'],
                leaseConflict: true,
                retryAfterSeconds: $this->leaseConflictDelay($definition),
            );
        }
        $counters = $this->resumeCounters($checkpoint->counters);
        $continuingReconciliation = $mode === 'reconcile'
            && $checkpoint->reconcile_correlation_id === $batch->correlation_id
            && $checkpoint->reconcile_cursor_zoho_id !== null;

        // The batch request time is the immutable delta watermark. Observation and
        // mirror timestamps still use the actual delivery time below.
        $watermarkAt = CarbonImmutable::parse($batch->requested_at ?? $batch->started_at ?? $started);
        $runAt = CarbonImmutable::now();
        $cursor = $checkpoint->cursor_at?->toIso8601String();
        $pageToken = $checkpoint->cursor_page_token;
        $restartCount = 0;
        $warning = [];

        try {
            $batch->update(['status' => 'running', 'started_at' => $batch->started_at ?? $started]);
            // One Records page per delivery keeps the work bounded.  The
            // opaque token remains durable until every ID on that page has
            // either been mirrored or quarantined.
            if (! $continuingReconciliation) {
                $page = $this->listPage($definition, $mode, $checkpoint, $pageToken, $batch->correlation_id);
                $counters['api_requests'] += $page->apiRequestCount();
                if ($page->notModified()) {
                    $pageToken = null;
                } else {
                    if (! $page->successful()) {
                        if ($this->isExpiredPageToken($page) && $pageToken && $restartCount++ === 0) {
                            $pageToken = null;
                            $checkpoint->cursor_page_token = null;
                            $checkpoint->page_last_zoho_id = null;
                            $warning[] = 'Page token expired; restarted from durable overlap watermark.';
                            if (! $this->savePageCheckpoint($checkpoint, $leaseOwner, null, $runAt, $counters)) {
                                throw new \RuntimeException('Sync lease ownership was lost.');
                            }
                            if (! $this->releasePageContinuation($checkpoint, $leaseOwner, $counters)) {
                                throw new \RuntimeException('Sync lease ownership was lost.');
                            }

                            return new SyncResult($counters, $cursor, warnings: $warning, continuationRequired: true);
                        }
                        throw new \RuntimeException('Zoho list request failed: '.($page->errorCode ?? 'unknown'));
                    }

                    [$ids, $nextPageToken] = $this->parseRecordListPage($page);
                    $resumeAfter = $checkpoint->page_last_zoho_id;
                    if ($resumeAfter !== null) {
                        $position = array_search($resumeAfter, $ids, true);
                        if ($position === false) {
                            // Page tokens are opaque and pages can drift while a
                            // delivery is retried.  Keeping a marker that no
                            // longer exists would make every retry fail forever.
                            // Drop only the in-page progress marker and restart
                            // the page; idempotent ingestion makes re-reading its
                            // earlier IDs safe.
                            $checkpoint->page_last_zoho_id = null;
                            $warning[] = 'Page record progress marker was absent; restarted the current page safely.';
                            if (! $this->updateLease($checkpoint, $leaseOwner, [
                                'page_last_zoho_id' => null,
                                'heartbeat_at' => now(),
                                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                                'counters' => $counters,
                            ])) {
                                throw new \RuntimeException('Sync lease ownership was lost.');
                            }
                            if (! $this->releasePageContinuation($checkpoint, $leaseOwner, $counters)) {
                                throw new \RuntimeException('Sync lease ownership was lost.');
                            }

                            return new SyncResult($counters, $cursor, warnings: $warning, continuationRequired: true);
                        }
                        $ids = array_slice($ids, $position + 1);
                    }
                    foreach ($ids as $id) {
                        if (! $this->heartbeat($checkpoint, $leaseOwner, $counters)) {
                            throw new \RuntimeException('Sync lease ownership was lost.');
                        }
                        try {
                            $record = $this->transport->get(
                                '/'.$definition->apiName.'/'.rawurlencode($id),
                                $definition->recordQuery,
                                $batch->correlation_id,
                            );
                            $counters['api_requests'] += $record->apiRequestCount();
                            if (! $record->successful() || $record->notModified()) {
                                if ($record->errorCode === 'throttle_unavailable') {
                                    throw new ZohoThrottleUnavailableException;
                                }
                                throw new \RuntimeException('Zoho record request failed: '.($record->errorCode ?? 'empty'));
                            }
                            $payload = collect((array) $record->root('data'))->first();
                            if (! is_array($payload)
                                || ! isset($payload['id'])
                                || ! is_scalar($payload['id'])
                                || ! hash_equals($id, (string) $payload['id'])) {
                                throw new \RuntimeException('Zoho record response did not contain a record payload.');
                            }
                            $this->persistRecord($definition, $payload, $batch, $runAt, $counters, $checkpoint, $leaseOwner);
                            $this->resolveRecordFailure($definition, $id, $checkpoint, $leaseOwner, $batch->id);
                        } catch (ZohoLeaseLostException $e) {
                            throw $e;
                        } catch (ZohoThrottleUnavailableException $e) {
                            if (! $this->updateLease($checkpoint, $leaseOwner, [
                                'status' => 'retrying', 'lease_owner' => null, 'lease_expires_at' => null,
                                'heartbeat_at' => now(), 'counters' => $counters,
                            ])) {
                                throw new \RuntimeException('Sync lease ownership was lost.');
                            }

                            return new SyncResult(
                                $counters,
                                $cursor,
                                warnings: $warning,
                                retryAfterSeconds: max(
                                    1,
                                    (int) config('zoho-v2.module.capacity_deferral_seconds', 60),
                                ),
                                continuationRequired: true,
                            );
                        } catch (Throwable $e) {
                            $this->quarantine($batch, $definition, $id, $e, $checkpoint, $leaseOwner);
                            $counters['quarantined']++;
                        }
                        $counters['seen']++;
                        if (! $this->updateLease($checkpoint, $leaseOwner, [
                            'page_last_zoho_id' => $id,
                            'heartbeat_at' => now(),
                            'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                            'counters' => $counters,
                        ])) {
                            throw new \RuntimeException('Sync lease ownership was lost.');
                        }
                    }

                    $pageToken = $nextPageToken;
                    if (! $this->savePageCheckpoint($checkpoint, $leaseOwner, $pageToken, $runAt, $counters)) {
                        throw new \RuntimeException('Sync lease ownership was lost.');
                    }
                    if ($pageToken !== null) {
                        if (! $this->releasePageContinuation($checkpoint, $leaseOwner, $counters)) {
                            throw new \RuntimeException('Sync lease ownership was lost.');
                        }

                        return new SyncResult($counters, $cursor, warnings: $warning, continuationRequired: true);
                    }
                }
            }

            $reconciliation = null;
            if ($mode === 'reconcile') {
                $previousDeleted = $continuingReconciliation
                    && (int) ($counters['reconciliation_deleted_complete'] ?? 0) === 1
                        ? $this->savedDeletedSummary($counters)
                        : null;
                $reconciliationRequestsBefore = max(
                    0,
                    (int) ($counters['reconciliation_api_requests'] ?? 0),
                );
                $hydrationRequestsBefore = max(
                    0,
                    (int) ($counters['reconciliation_hydration_api_requests'] ?? 0),
                );
                $reconciliation = $this->reconciliation->reconcile(
                    moduleKey: $definition->key,
                    batchId: $batch->id,
                    correlationId: $batch->correlation_id,
                    hydrateRecord: fn (string $id): array => $this->hydrateReconciliationRecord(
                        $definition,
                        $id,
                        $batch,
                        $runAt,
                        $checkpoint,
                        $leaseOwner,
                    ),
                    heartbeat: fn (): bool => $this->heartbeat($checkpoint, $leaseOwner, $counters),
                    resumeAfterZohoId: $checkpoint->reconcile_cursor_zoho_id,
                    maxHydrations: $definition->key === 'quotes'
                        ? max(1, (int) config('zoho-v2.reconciliation.quote_chunk_size', 500))
                        : null,
                    recordProgress: function (string $id, array $result) use (
                        &$counters,
                        $checkpoint,
                        $leaseOwner,
                        $definition,
                    ): bool {
                        $this->accumulateReconciliationHydration($counters, $result, $definition->key === 'quotes');

                        return $this->updateLease($checkpoint, $leaseOwner, [
                            'reconcile_cursor_zoho_id' => $id,
                            'heartbeat_at' => now(),
                            'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                            'counters' => $counters,
                        ]);
                    },
                    previousDeleted: $previousDeleted,
                    mutationFence: fn (): bool => $this->holdsFence($checkpoint, $leaseOwner, $batch->id),
                );
                $reportedHydrationRequests = max(
                    0,
                    (int) data_get($reconciliation, 'hydration.api_requests', 0),
                );
                $reusedDeletionRequests = $previousDeleted === null
                    ? 0
                    : max(0, (int) ($previousDeleted['api_requests'] ?? 0));
                $currentNonHydrationRequests = max(
                    0,
                    (int) ($reconciliation['api_requests'] ?? 0)
                        - $reportedHydrationRequests
                        - $reusedDeletionRequests,
                );
                $currentHydrationRequests = max(
                    0,
                    (int) ($counters['reconciliation_hydration_api_requests'] ?? 0)
                        - $hydrationRequestsBefore,
                );
                $counters['reconciliation_api_requests'] = $reconciliationRequestsBefore
                    + $currentNonHydrationRequests
                    + $currentHydrationRequests;
                $counters['reconciliation_repaired'] = max(
                    0,
                    (int) ($counters['reconciliation_repaired'] ?? 0),
                ) + max(0, (int) ($reconciliation['repaired_count'] ?? 0));
                $this->rememberDeletedSummary($counters, (array) ($reconciliation['deleted'] ?? []));
                $reconciliation = $this->cumulativeReconciliation($reconciliation, $counters);

                if (($reconciliation['continuation_required'] ?? false) === true) {
                    if (! $this->updateLease($checkpoint, $leaseOwner, [
                        'status' => 'running',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'heartbeat_at' => now(),
                        'completed_at' => null,
                        'cursor_page_token' => null,
                        'counters' => $counters,
                    ])) {
                        throw new \RuntimeException('Sync lease ownership was lost.');
                    }

                    return new SyncResult(
                        counters: $counters,
                        cursor: $cursor,
                        warnings: $warning,
                        reconciliation: $reconciliation,
                        continuationRequired: true,
                    );
                }
            }

            $reconciliationComplete = $reconciliation === null
                || ($reconciliation['complete'] ?? false) === true;
            if (! $reconciliationComplete) {
                // An incomplete deletion/reconciliation pass is retryable,
                // never a terminal module result.  Otherwise a sibling module
                // could finalize this batch while this delivery is still
                // recoverable.
                $this->updateLease($checkpoint, $leaseOwner, [
                    'status' => 'retrying',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => now(),
                    'retry_count' => ((int) $checkpoint->retry_count) + 1,
                    'counters' => $counters,
                ]);

                return new SyncResult(
                    $counters,
                    $cursor,
                    warnings: $warning,
                    failures: ['Reconciliation did not complete; retry is safe.'],
                    reconciliation: $reconciliation,
                    retryableFailure: true,
                );
            }
            $reconciliationDegraded = $reconciliation !== null
                && ($reconciliation['degraded'] ?? true) === true;
            $unresolved = $this->hasUnresolvedFailures($definition);
            $status = match (true) {
                ! $reconciliationComplete => 'error',
                $counters['quarantined'] > 0, $unresolved, $reconciliationDegraded => 'partial',
                default => 'success',
            };
            $checkpointStatus = match ($status) {
                'error' => 'failed',
                'partial' => 'partial',
                default => 'completed',
            };
            // Cursor is an enumeration-start watermark, never a continuation
            // start timestamp.  It must not move backwards after a newer run.
            $checkpointCursor = $checkpoint->cursor_at === null || $watermarkAt->greaterThan($checkpoint->cursor_at)
                ? $watermarkAt
                : $checkpoint->cursor_at;
            $terminalized = DB::transaction(function () use ($checkpoint, $leaseOwner, $checkpointStatus, $checkpointCursor, $counters, $batch, $definition, $mode, $status, $started, $runAt, $reconciliation): bool {
                // The fenced terminal checkpoint and matching module log are
                // one commit. A crash cannot let batch finalization observe
                // one without the other.
                if (! $this->updateLease($checkpoint, $leaseOwner, [
                    'status' => $checkpointStatus, 'lease_owner' => null, 'lease_expires_at' => null, 'heartbeat_at' => now(),
                    'completed_at' => now(), 'cursor_at' => $checkpointCursor, 'cursor_modified_time' => $checkpointCursor,
                    'cursor_page_token' => null, 'page_last_zoho_id' => null,
                    'reconcile_cursor_zoho_id' => null, 'reconcile_correlation_id' => null,
                    'reconcile_started_at' => null, 'counters' => $counters,
                ])) {
                    return false;
                }
                $telemetry = $mode === 'reconcile' && $reconciliation !== null
                    ? ['reconciliation' => ZohoModuleRunOutcome::normalizedReconciliationTelemetry($reconciliation)]
                    : [];
                unset($telemetry['reconciliation']['status_valid']);
                $this->log($batch, $definition, $mode, $status, $counters, $started, $runAt,
                    $status === 'error' ? 'Reconciliation did not complete; see correlation ID.' : null,
                    $telemetry);

                return true;
            });
            if (! $terminalized) {
                throw new \RuntimeException('Sync lease ownership was lost.');
            }

            return new SyncResult(
                $counters,
                $cursor,
                warnings: $warning,
                failures: $counters['quarantined'] > 0 ? ['One or more records were quarantined; retry is required.'] : [],
                reconciliation: $reconciliation,
            );
        } catch (Throwable $e) {
            // Queue retries are non-terminal.  `failed()` is the only path
            // that turns a retryable delivery into a terminal module result.
            $this->updateLease($checkpoint, $leaseOwner, ['status' => 'retrying', 'lease_owner' => null, 'lease_expires_at' => null, 'heartbeat_at' => now(), 'retry_count' => ((int) $checkpoint->retry_count) + 1, 'counters' => $counters]);

            return new SyncResult(
                $counters,
                $cursor,
                warnings: $warning,
                failures: ['Module request failed; retry is safe.'],
                retryableFailure: true,
            );
        }
    }

    public function finalizeBatch(int $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $batch = ZohoSyncBatch::query()->lockForUpdate()->findOrFail($batchId);
            if ($batch->completed_at !== null) {
                return;
            }
            $expected = collect(array_values(array_unique((array) $batch->modules)))->map(function (string $key): string {
                $definition = $this->registry->get($key);

                return $definition->key.'|'.($definition->submodule ?? '');
            })->values();
            $logs = ZohoSyncLog::query()->where('sync_batch_id', $batchId)->orderByDesc('id')->get()
                ->unique(fn (ZohoSyncLog $log) => $log->module.'|'.$log->submodule)->values();
            $actual = $logs->map(fn (ZohoSyncLog $log) => $log->module.'|'.$log->submodule);
            if ($logs->count() < $expected->count() || $actual->diff($expected)->isNotEmpty() || $expected->diff($actual)->isNotEmpty()) {
                return;
            }
            if ($logs->contains(fn (ZohoSyncLog $log): bool => ! in_array($log->status, ['success', 'partial', 'error'], true))) {
                return;
            }
            $statuses = $logs->pluck('status');
            $status = $statuses->contains('error') ? 'error' : ($statuses->contains('partial') ? 'partial' : 'success');
            // Identity mappings are deterministic local post-processing for
            // every completed healthy V2 batch. Reconcile adds inventory and
            // failure retries in the processor itself.
            $eligibleForPost = in_array($status, ['success', 'partial'], true)
                && config('zoho-v2.features.sync_enabled', false);
            $batch->update([
                'status' => $status,
                'completed_at' => now(),
                'counters' => $this->sumCounters($logs->all()),
                'post_reconciliation_status' => $eligibleForPost ? 'pending' : $batch->post_reconciliation_status,
                'post_reconciliation_retry_not_before' => $eligibleForPost ? null : $batch->post_reconciliation_retry_not_before,
                'post_reconciliation_retry_deadline_at' => $eligibleForPost
                    ? ($batch->post_reconciliation_retry_deadline_at ?? now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12)))
                    : $batch->post_reconciliation_retry_deadline_at,
            ]);

            if ($eligibleForPost) {
                // The database queue uses this same transaction/connection
                // with after_commit disabled. If enqueue throws, the batch
                // terminal state and its outbox marker roll back together.
                $batch->refresh();
                RunZohoPostReconciliationJob::dispatch($batchId, $batch->post_reconciliation_retry_deadline_at?->toIso8601String())
                    ->onConnection((string) config('zoho-v2.queue_connection', 'zoho'))
                    ->onQueue((string) config('zoho-v2.queue', 'zoho'));
            }
        });
    }

    public function terminalizeModule(int $batchId, string $moduleKey, string $mode, string $reason, ?string $deliveryToken = null, ?int $deliveryGeneration = null): void
    {
        $messages = [
            'disabled' => 'Synchronization disabled before execution.',
            'job_exhausted' => 'Queue job exhausted before completion.',
            'reconciliation_missing' => 'Reconciliation result was missing; retry is required.',
            'dispatch_failed' => 'Synchronization dispatch failed before execution.',
            'inline_failed' => 'Inline synchronization could not complete; retry asynchronously.',
        ];
        $message = $messages[$reason] ?? 'Module synchronization stopped before completion.';
        $definition = $this->registry->get($moduleKey);

        DB::transaction(function () use ($batchId, $definition, $mode, $message, $deliveryToken, $deliveryGeneration): void {
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($batchId);
            if ($batch === null || ($batch->completed_at !== null && in_array($batch->status, ['success', 'partial', 'error'], true))) {
                return;
            }
            if ($batch->mode !== $mode || ! in_array($definition->key, (array) $batch->modules, true)) {
                return;
            }

            $checkpoint = ZohoSyncCheckpoint::query()
                ->where('module', $this->checkpointModule($definition))
                ->where('submodule', $definition->submodule ?? '')
                ->where('correlation_id', $batch->correlation_id)
                ->lockForUpdate()
                ->first();
            if ($deliveryToken !== null && $checkpoint !== null
                && $checkpoint->lease_expires_at?->isFuture()
                && $checkpoint->lease_owner !== null
                && ! hash_equals((string) $checkpoint->lease_owner, $deliveryToken)) {
                return;
            }
            // A continuation deliberately releases its lease before the next
            // delivery is queued. Its failed callback must still be fenced by
            // the immutable checkpoint generation, not merely a live lease.
            if ($deliveryGeneration !== null && $checkpoint !== null
                && (int) $checkpoint->generation !== $deliveryGeneration) {
                return;
            }

            $log = ZohoSyncLog::query()
                ->where('sync_batch_id', $batchId)
                ->where('module', $definition->key)
                ->where('submodule', $definition->submodule ?? '')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($log !== null
                && hash_equals((string) $log->correlation_id, (string) $batch->correlation_id)
                && in_array($log->status, ['success', 'partial'], true)) {
                // A late failed callback must never overwrite an already
                // terminal successful delivery for this exact run.
                return;
            }
            $values = [
                'mode' => $mode,
                'correlation_id' => $batch->correlation_id,
                'synced_at' => now(),
                'status' => 'error',
                'error' => $message,
            ];
            if ($log === null) {
                ZohoSyncLog::query()->create($values + [
                    'module' => $definition->key,
                    'submodule' => $definition->submodule ?? '',
                    'sync_batch_id' => $batchId,
                    'records_synced' => 0,
                    'records_seen' => 0,
                    'records_created' => 0,
                    'records_updated' => 0,
                    'records_unchanged' => 0,
                    'records_quarantined' => 0,
                    'api_requests' => 0,
                    'telemetry' => [],
                ]);
            } else {
                $log->update($values);
            }
            $batch->update(['error_summary' => $message]);

            if ($checkpoint !== null) {
                $checkpoint->update([
                    'status' => 'failed',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => now(),
                    'completed_at' => now(),
                ]);
            }
        });

        $this->finalizeBatch($batchId);
    }

    private function listPage(ModuleDefinition $definition, string $mode, ZohoSyncCheckpoint $checkpoint, ?string $token, string $correlationId): mixed
    {
        $query = array_replace(['fields' => 'id', 'per_page' => 200], $definition->listQuery);
        if ($token) {
            $query['page_token'] = $token;
        }
        $path = '/'.$definition->apiName;

        if (in_array($mode, ['delta', 'reconcile'], true)
            && $definition->fetchStrategy === 'records_if_modified_since'
            && $checkpoint->cursor_at !== null) {
            $since = CarbonImmutable::parse($checkpoint->cursor_at)
                ->subMinutes((int) config('zoho-v2.overlap_minutes', 15));

            return $this->transport->getIfModifiedSince($path, $since, $query, $correlationId);
        }

        return $this->transport->get($path, $query, $correlationId);
    }

    /** @return array{0:list<string>,1:?string} */
    private function parseRecordListPage(mixed $result): array
    {
        if ($result->status === 204) {
            return [[], null];
        }

        $data = $result->root('data');
        $rootInfo = $result->root('info');
        if ($result->status !== 200 || ! is_array($data) || ! array_is_list($data)
            || ($rootInfo !== null && ! is_array($rootInfo))) {
            throw new \RuntimeException('Zoho list response shape was invalid.');
        }

        $ids = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_scalar($row['id']) || (string) $row['id'] === '') {
                throw new \RuntimeException('Zoho list response contained an invalid record identity.');
            }
            $ids[] = (string) $row['id'];
        }

        $info = $result->info ?: ($rootInfo ?? []);
        $more = $info['more_records'] ?? null;
        if (! is_bool($more)) {
            throw new \RuntimeException('Zoho list continuation metadata was invalid.');
        }
        $token = $info['next_page_token'] ?? null;
        if ($token !== null && (! is_string($token) || $token === '' || strlen($token) > 1024)) {
            throw new \RuntimeException('Zoho list continuation token was invalid.');
        }
        if ($more === true && $token === null) {
            throw new \RuntimeException('Zoho list response omitted its continuation token.');
        }
        if ($more === false && $token !== null) {
            throw new \RuntimeException('Zoho list response had inconsistent continuation metadata.');
        }

        return [$ids, $token];
    }

    /**
     * Create the durable standard-delivery outbox before a queue dispatch.
     * The returned generation is serialized into that exact queue message.
     */
    public function prepareModuleDelivery(int $batchId, string $moduleKey, string $mode, string $correlationId, ?string $retryDeadline = null): ?int
    {
        $this->validateMode($mode);
        $definition = $this->registry->get($moduleKey);

        return DB::transaction(function () use ($batchId, $definition, $mode, $correlationId, $retryDeadline): ?int {
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($batchId);
            if ($batch === null || $batch->completed_at !== null || $batch->mode !== $mode
                || ! hash_equals((string) $batch->correlation_id, $correlationId)
                || ! in_array($definition->key, (array) $batch->modules, true)) {
                return null;
            }
            $checkpoint = ZohoSyncCheckpoint::query()
                ->where('module', $this->checkpointModule($definition))
                ->where('submodule', $definition->submodule ?? '')
                ->lockForUpdate()->first();
            // A queued/retrying delivery may deliberately have no lease while
            // Laravel waits for capacity or its normal backoff. It still owns
            // this checkpoint until its batch reaches a terminal state. A
            // newer manual/scheduled batch must not steal that durable outbox.
            if ($checkpoint !== null && $checkpoint->sync_batch_id !== null
                && (int) $checkpoint->sync_batch_id !== $batchId) {
                $ownerBatch = ZohoSyncBatch::query()->lockForUpdate()->find($checkpoint->sync_batch_id);
                if ($ownerBatch !== null && $ownerBatch->completed_at === null) {
                    return null;
                }
            }
            if ($checkpoint !== null && $checkpoint->lease_expires_at?->isFuture()
                && ($checkpoint->sync_batch_id !== $batchId || $checkpoint->correlation_id !== $correlationId)) {
                return null;
            }
            if ($checkpoint === null) {
                $checkpoint = ZohoSyncCheckpoint::query()->create([
                    'module' => $this->checkpointModule($definition), 'submodule' => $definition->submodule ?? '',
                    'sync_mode' => $mode, 'status' => 'idle', 'counters' => [],
                ]);
            }
            $deadline = $retryDeadline
                ?? ($checkpoint->sync_batch_id === $batchId && $checkpoint->correlation_id === $correlationId
                    ? $checkpoint->delivery_retry_deadline_at?->toIso8601String()
                    : null)
                ?? now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12))->toIso8601String();
            $checkpoint->update([
                'sync_mode' => $mode, 'status' => 'queued', 'lease_owner' => null, 'lease_expires_at' => null,
                'heartbeat_at' => now(), 'sync_batch_id' => $batchId, 'correlation_id' => $correlationId,
                'generation' => ((int) $checkpoint->generation) + 1,
                'delivery_retry_deadline_at' => $deadline,
                'completed_at' => null,
            ]);
            $batch->update(['status' => 'running', 'started_at' => $batch->started_at ?? now()]);

            return (int) $checkpoint->generation;
        });
    }

    private function claimCheckpoint(ModuleDefinition $definition, string $mode, string $workerId, string $correlationId, ?int $batchId = null, ?int $deliveryGeneration = null): ?ZohoSyncCheckpoint
    {
        return DB::transaction(function () use ($definition, $mode, $workerId, $correlationId, $batchId, $deliveryGeneration): ?ZohoSyncCheckpoint {
            $checkpointModule = $this->checkpointModule($definition);
            $queryFingerprint = $definition->queryFingerprint($mode);
            $checkpoint = ZohoSyncCheckpoint::query()->where('module', $checkpointModule)->where('submodule', $definition->submodule ?? '')->lockForUpdate()->first();
            if (! $checkpoint) {
                try {
                    $checkpoint = ZohoSyncCheckpoint::create(['module' => $checkpointModule, 'submodule' => $definition->submodule ?? '', 'sync_mode' => $mode, 'page_query_fingerprint' => $queryFingerprint, 'status' => 'idle']);
                } catch (UniqueConstraintViolationException) {
                    $checkpoint = ZohoSyncCheckpoint::query()->where('module', $checkpointModule)->where('submodule', $definition->submodule ?? '')->lockForUpdate()->first();
                }
                if ($checkpoint === null) {
                    return null;
                }
                $checkpoint->refresh();
            }
            if ($checkpoint->lease_expires_at?->isFuture()) {
                return null;
            }
            if ($deliveryGeneration !== null && ((int) $checkpoint->generation !== $deliveryGeneration
                || (int) $checkpoint->sync_batch_id !== $batchId
                || $checkpoint->correlation_id !== $correlationId)) {
                return null;
            }
            if ($batchId !== null && $checkpoint->sync_batch_id !== null
                && (int) $checkpoint->sync_batch_id !== $batchId) {
                // A queued/retrying outbox deliberately has no active lease
                // between deliveries, but it remains owned until its batch
                // is terminal. Inline `--now` calls bypass the dispatcher,
                // so they must enforce this same cross-batch fence here.
                $ownerBatch = ZohoSyncBatch::query()->lockForUpdate()->find($checkpoint->sync_batch_id);
                if ($ownerBatch === null || $ownerBatch->completed_at === null) {
                    return null;
                }
            }
            $resumeCompatible = $checkpoint->sync_mode === $mode
                && hash_equals((string) $checkpoint->page_query_fingerprint, $queryFingerprint)
                && ($batchId === null || (int) $checkpoint->sync_batch_id === $batchId)
                && $checkpoint->correlation_id === $correlationId;
            $resumeReconciliation = $mode === 'reconcile'
                && $checkpoint->reconcile_correlation_id === $correlationId;
            $checkpoint->update([
                'sync_mode' => $mode,
                'page_query_fingerprint' => $queryFingerprint,
                'cursor_page_token' => $resumeCompatible ? $checkpoint->cursor_page_token : null,
                'page_last_zoho_id' => $resumeCompatible ? $checkpoint->page_last_zoho_id : null,
                'page_token_expires_at' => $resumeCompatible ? $checkpoint->page_token_expires_at : null,
                'status' => 'running',
                'lease_owner' => $workerId,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'heartbeat_at' => now(),
                'correlation_id' => $correlationId,
                'sync_batch_id' => $batchId,
                'generation' => $deliveryGeneration ?? ((int) $checkpoint->generation) + 1,
                'counters' => ($resumeCompatible || $resumeReconciliation)
                    ? $checkpoint->counters
                    : $this->emptyCounters(),
                'reconcile_cursor_zoho_id' => $resumeReconciliation
                    ? $checkpoint->reconcile_cursor_zoho_id
                    : null,
                'reconcile_correlation_id' => $mode === 'reconcile' ? $correlationId : null,
                'reconcile_started_at' => $mode === 'reconcile'
                    ? ($resumeReconciliation ? $checkpoint->reconcile_started_at : now())
                    : null,
            ]);

            return $checkpoint->fresh();
        });
    }

    /** @param array<string,int> $counters */
    private function persistRecord(ModuleDefinition $definition, array $payload, ZohoSyncBatch $batch, CarbonImmutable $runAt, array &$counters, ZohoSyncCheckpoint $checkpoint, string $leaseOwner): void
    {
        DB::transaction(function () use ($definition, $payload, $batch, $runAt, &$counters, $checkpoint, $leaseOwner): void {
            if (! $this->holdsFence($checkpoint, $leaseOwner, $batch->id)) {
                throw new ZohoLeaseLostException('The sync lease was reclaimed before persistence.');
            }
            foreach ($this->ingestor->ingest($definition, $payload, $batch, $runAt) as $key => $value) {
                $counters[$key] += $value;
            }
        });
    }

    /** @return array{successful:bool,created:int,updated:int,unchanged:int,quarantined:int,api_requests:int} */
    private function hydrateReconciliationRecord(
        ModuleDefinition $definition,
        string $zohoId,
        ZohoSyncBatch $batch,
        CarbonImmutable $runAt,
        ZohoSyncCheckpoint $checkpoint,
        string $leaseOwner,
    ): array {
        $counters = $this->emptyCounters();

        try {
            $record = $this->transport->get(
                '/'.$definition->apiName.'/'.rawurlencode($zohoId),
                $definition->recordQuery,
                $batch->correlation_id,
            );
            $counters['api_requests'] += $record->apiRequestCount();
            if (! $record->successful() || $record->notModified()) {
                if ($record->errorCode === 'throttle_unavailable') {
                    throw new ZohoThrottleUnavailableException;
                }
                throw new \RuntimeException('Zoho reconciliation record request failed.');
            }

            $payload = collect((array) $record->root('data'))->first();
            if (! is_array($payload)
                || ! isset($payload['id'])
                || ! is_scalar($payload['id'])
                || ! hash_equals($zohoId, (string) $payload['id'])) {
                throw new \RuntimeException('Zoho reconciliation record response was invalid.');
            }

            $this->persistRecord($definition, $payload, $batch, $runAt, $counters, $checkpoint, $leaseOwner);
            $this->resolveRecordFailure($definition, $zohoId, $checkpoint, $leaseOwner, $batch->id);

            return $this->reconciliationHydrationResult(true, $counters);
        } catch (ZohoLeaseLostException|ZohoThrottleUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->quarantine($batch, $definition, $zohoId, $exception, $checkpoint, $leaseOwner);
            $counters['quarantined']++;

            return $this->reconciliationHydrationResult(false, $counters);
        }
    }

    /**
     * @param  array<string, int>  $counters
     * @return array{successful:bool,created:int,updated:int,unchanged:int,quarantined:int,api_requests:int}
     */
    private function reconciliationHydrationResult(bool $successful, array $counters): array
    {
        return [
            'successful' => $successful,
            'created' => max(0, (int) ($counters['created'] ?? 0)),
            'updated' => max(0, (int) ($counters['updated'] ?? 0)),
            'unchanged' => max(0, (int) ($counters['unchanged'] ?? 0)),
            'quarantined' => max(0, (int) ($counters['quarantined'] ?? 0)),
            'api_requests' => max(0, (int) ($counters['api_requests'] ?? 0)),
        ];
    }

    /** @param array<string,int> $counters */
    private function savePageCheckpoint(ZohoSyncCheckpoint $checkpoint, string $leaseOwner, ?string $token, CarbonImmutable $runAt, array $counters): bool
    {
        return $this->updateLease($checkpoint, $leaseOwner, ['cursor_page_token' => $token, 'page_last_zoho_id' => null, 'page_token_expires_at' => $token ? now()->addMinutes(15) : null, 'heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds()), 'counters' => $counters]);
    }

    /** @param array<string,int> $counters */
    private function releasePageContinuation(ZohoSyncCheckpoint $checkpoint, string $leaseOwner, array $counters): bool
    {
        return $this->updateLease($checkpoint, $leaseOwner, [
            'status' => 'running',
            'lease_owner' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => now(),
            'completed_at' => null,
            'counters' => $counters,
        ]);
    }

    private function quarantine(ZohoSyncBatch $batch, ModuleDefinition $definition, string $zohoId, Throwable $e, ZohoSyncCheckpoint $checkpoint, string $leaseOwner): void
    {
        $key = hash('sha256', implode('|', [$definition->key, $definition->submodule ?? '', $zohoId, 'record']));
        DB::transaction(function () use ($batch, $definition, $zohoId, $e, $key, $checkpoint, $leaseOwner): void {
            if (! $this->holdsFence($checkpoint, $leaseOwner, $batch->id)) {
                throw new ZohoLeaseLostException('The synchronization lease was reclaimed.');
            }
            $failure = ZohoSyncFailure::query()->where('failure_key', $key)->lockForUpdate()->first();
            $values = ['sync_batch_id' => $batch->id, 'module' => $definition->key, 'submodule' => $definition->submodule ?? '', 'zoho_id' => $zohoId, 'failure_kind' => 'record', 'correlation_id' => $batch->correlation_id, 'error_summary' => 'Record synchronization failed.', 'context' => ['exception' => class_basename($e), 'correlation_id' => $batch->correlation_id], 'retry_after' => now()->addMinutes(5), 'resolved_at' => null];
            if ($failure) {
                $failure->update($values + ['attempts' => ((int) $failure->attempts) + 1]);
            } else {
                ZohoSyncFailure::query()->create($values + ['failure_key' => $key, 'attempts' => 1]);
            }
        });
    }

    private function resolveRecordFailure(ModuleDefinition $definition, string $zohoId, ZohoSyncCheckpoint $checkpoint, string $leaseOwner, int $batchId): void
    {
        $key = hash('sha256', implode('|', [
            $definition->key,
            $definition->submodule ?? '',
            $zohoId,
            'record',
        ]));
        DB::transaction(function () use ($checkpoint, $leaseOwner, $batchId, $key): void {
            // Keep the ownership predicate and the failure resolution inside
            // one transaction. A worker reclaimed between separate queries
            // must not resolve the successor's durable quarantine record.
            if (! $this->holdsFence($checkpoint, $leaseOwner, $batchId)) {
                throw new ZohoLeaseLostException('The synchronization lease was reclaimed.');
            }
            ZohoSyncFailure::query()
                ->where('failure_key', $key)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->update(['resolved_at' => now(), 'retry_after' => null]);
        });
    }

    /** @param array<string,int> $counters */
    private function log(ZohoSyncBatch $batch, ModuleDefinition $definition, string $mode, string $status, array $counters, CarbonImmutable $started, CarbonImmutable $cursor, ?string $error, array $telemetry = []): void
    {
        // Reconciliation API calls and tombstones are not ingestion counters,
        // but operations health indexes them on the terminal module log. The
        // canonical telemetry is committed in this same transaction; the
        // later job adapter sees it and therefore adds no duplicate delta.
        $reconciliationApiRequests = max(0, (int) data_get($telemetry, 'reconciliation.api_requests', 0));
        $tombstoned = max(0, (int) data_get($telemetry, 'reconciliation.tombstoned', 0));
        ZohoSyncLog::create(['module' => $definition->key, 'submodule' => $definition->submodule ?? '', 'mode' => $mode, 'sync_batch_id' => $batch->id, 'correlation_id' => $batch->correlation_id, 'synced_at' => now(), 'records_synced' => $counters['created'] + $counters['updated'] + $counters['unchanged'], 'records_seen' => $counters['seen'], 'records_created' => $counters['created'], 'records_updated' => $counters['updated'], 'records_unchanged' => $counters['unchanged'], 'records_deleted' => $tombstoned, 'records_quarantined' => $counters['quarantined'], 'status' => $status, 'error' => $error, 'duration_ms' => $started->diffInMilliseconds(now()), 'cursor_at' => $cursor, 'api_requests' => max(0, (int) $counters['api_requests']) + $reconciliationApiRequests, 'telemetry' => $telemetry]);
    }

    /** @param array<string,int> $counters */
    private function gatedResult(ZohoSyncBatch $batch, ModuleDefinition $definition, string $mode, array $counters, CarbonImmutable $started): SyncResult
    {
        $this->log($batch, $definition, $mode, 'error', $counters, $started, $started, 'Activation required: '.$this->safe((string) $definition->activationNote));

        return new SyncResult(
            $counters,
            warnings: ['This module is activation-gated. Grant its documented read-only scope before retrying.'],
            failures: ['Module activation is required before synchronization.'],
        );
    }

    /** @param array<string,int> $counters */
    private function quoteItemsOwnedResult(ZohoSyncBatch $batch, ModuleDefinition $definition, string $mode, array $counters, CarbonImmutable $started): SyncResult
    {
        $this->log($batch, $definition, $mode, 'success', $counters, $started, $started, null);

        return new SyncResult($counters, warnings: ['Quote items are synchronized atomically with their parent quotes.']);
    }

    private function checkpointModule(ModuleDefinition $definition): string
    {
        return 'v2:'.$definition->key;
    }

    /** Conditional writes prevent a reclaimed stale worker from moving another worker's cursor. */
    private function updateLease(ZohoSyncCheckpoint $checkpoint, string $leaseOwner, array $values): bool
    {
        $owned = ZohoSyncCheckpoint::query()
            ->whereKey($checkpoint->id)
            ->where('sync_batch_id', $checkpoint->sync_batch_id)
            ->where('generation', $checkpoint->generation)
            ->where('lease_owner', $leaseOwner)
            ->where('lease_expires_at', '>', now());
        if ((clone $owned)->update($values) === 1) {
            return true;
        }

        // MySQL reports zero affected rows when a same-second heartbeat writes
        // identical values. Recheck ownership so that a harmless no-op is not
        // mistaken for a reclaimed lease.
        return $owned->exists();
    }

    /**
     * Lock and verify the complete lease generation in the same transaction as
     * mirror persistence. A reclaimed worker can therefore never commit a row
     * after a newer generation has taken ownership of the module checkpoint.
     */
    private function holdsFence(ZohoSyncCheckpoint $checkpoint, string $leaseOwner, int $batchId): bool
    {
        return ZohoSyncCheckpoint::query()
            ->whereKey($checkpoint->id)
            ->where('sync_batch_id', $batchId)
            ->where('generation', $checkpoint->generation)
            ->where('lease_owner', $leaseOwner)
            ->where('lease_expires_at', '>', now())
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    /** @param array<string,int> $counters */
    private function heartbeat(ZohoSyncCheckpoint $checkpoint, string $leaseOwner, array $counters): bool
    {
        return $this->updateLease($checkpoint, $leaseOwner, ['heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds()), 'counters' => $counters]);
    }

    private function hasUnresolvedFailures(ModuleDefinition $definition): bool
    {
        return ZohoSyncFailure::query()->where('module', $definition->key)->where('submodule', $definition->submodule ?? '')->whereNull('resolved_at')->exists();
    }

    /** @param array<string, int> $counters @param array<string, mixed> $result */
    private function accumulateReconciliationHydration(array &$counters, array $result, bool $quoteSweep): void
    {
        $successful = ($result['successful'] ?? false) === true;
        $counters['seen']++;
        $counters['reconciliation_attempted'] = ((int) ($counters['reconciliation_attempted'] ?? 0)) + 1;
        if (! $successful) {
            $counters['reconciliation_failed'] = ((int) ($counters['reconciliation_failed'] ?? 0)) + 1;
        }
        if ($quoteSweep) {
            $counters['reconciliation_swept'] = ((int) ($counters['reconciliation_swept'] ?? 0)) + 1;
        }

        foreach (['created', 'updated', 'unchanged', 'quarantined'] as $counter) {
            $value = max(0, (int) ($result[$counter] ?? 0));
            $counters[$counter] += $value;
            $special = 'reconciliation_'.$counter;
            $counters[$special] = ((int) ($counters[$special] ?? 0)) + $value;
        }
        $apiRequests = max(0, (int) ($result['api_requests'] ?? 0));
        $counters['reconciliation_api_requests'] = ((int) ($counters['reconciliation_api_requests'] ?? 0))
            + $apiRequests;
        $counters['reconciliation_hydration_api_requests'] = ((int) ($counters['reconciliation_hydration_api_requests'] ?? 0))
            + $apiRequests;
    }

    /** @param array<string, mixed> $aggregate @param array<string, int> $counters @return array<string, mixed> */
    private function cumulativeReconciliation(array $aggregate, array $counters): array
    {
        $aggregate['api_requests'] = max(0, (int) ($counters['reconciliation_api_requests'] ?? 0));
        $aggregate['repaired_count'] = max(0, (int) ($counters['reconciliation_repaired'] ?? 0));
        $aggregate['swept_count'] = max(0, (int) ($counters['reconciliation_swept'] ?? 0));
        $aggregate['deleted'] = $this->savedDeletedSummary($counters);
        $aggregate['hydration'] = [
            'attempted' => max(0, (int) ($counters['reconciliation_attempted'] ?? 0)),
            'failed' => max(0, (int) ($counters['reconciliation_failed'] ?? 0)),
            'created' => max(0, (int) ($counters['reconciliation_created'] ?? 0)),
            'updated' => max(0, (int) ($counters['reconciliation_updated'] ?? 0)),
            'unchanged' => max(0, (int) ($counters['reconciliation_unchanged'] ?? 0)),
            'quarantined' => max(0, (int) ($counters['reconciliation_quarantined'] ?? 0)),
            'api_requests' => max(0, (int) ($counters['reconciliation_hydration_api_requests'] ?? 0)),
        ];

        return $aggregate;
    }

    /** @param array<string, mixed>|null $stored @return array<string, int> */
    private function resumeCounters(?array $stored): array
    {
        $counters = $this->emptyCounters();
        foreach ([
            'reconciliation_api_requests',
            'reconciliation_repaired',
            'reconciliation_swept',
            'reconciliation_attempted',
            'reconciliation_failed',
            'reconciliation_created',
            'reconciliation_updated',
            'reconciliation_unchanged',
            'reconciliation_quarantined',
            'reconciliation_hydration_api_requests',
            'reconciliation_deleted_seen',
            'reconciliation_deleted_tombstoned',
            'reconciliation_deleted_quarantined',
            'reconciliation_deleted_api_requests',
            'reconciliation_deleted_pages',
            'reconciliation_deleted_status',
            'reconciliation_deleted_complete',
            'reconciliation_deleted_degraded',
        ] as $counter) {
            $counters[$counter] = 0;
        }
        foreach ($counters as $counter => $default) {
            $counters[$counter] = max(0, (int) ($stored[$counter] ?? $default));
        }

        return $counters;
    }

    /** @param array<string,int> $counters @param array<string,mixed> $deleted */
    private function rememberDeletedSummary(array &$counters, array $deleted): void
    {
        foreach (['seen', 'tombstoned', 'quarantined', 'api_requests', 'pages', 'status'] as $key) {
            $counters['reconciliation_deleted_'.$key] = max(0, (int) ($deleted[$key] ?? 0));
        }
        $counters['reconciliation_deleted_complete'] = ($deleted['complete'] ?? false) === true ? 1 : 0;
        $counters['reconciliation_deleted_degraded'] = ($deleted['degraded'] ?? true) === true ? 1 : 0;
    }

    /** @param array<string,int> $counters @return array<string,int|bool> */
    private function savedDeletedSummary(array $counters): array
    {
        return [
            'seen' => max(0, (int) ($counters['reconciliation_deleted_seen'] ?? 0)),
            'tombstoned' => max(0, (int) ($counters['reconciliation_deleted_tombstoned'] ?? 0)),
            'quarantined' => max(0, (int) ($counters['reconciliation_deleted_quarantined'] ?? 0)),
            'api_requests' => max(0, (int) ($counters['reconciliation_deleted_api_requests'] ?? 0)),
            'pages' => max(0, (int) ($counters['reconciliation_deleted_pages'] ?? 0)),
            'status' => max(0, (int) ($counters['reconciliation_deleted_status'] ?? 0)),
            'complete' => (int) ($counters['reconciliation_deleted_complete'] ?? 0) === 1,
            'degraded' => (int) ($counters['reconciliation_deleted_degraded'] ?? 1) === 1,
        ];
    }

    private function leaseSeconds(): int
    {
        return max(60, (int) config('zoho-v2.module.lease_seconds', 1500));
    }

    private function leaseConflictDelay(ModuleDefinition $definition): int
    {
        $expiresAt = ZohoSyncCheckpoint::query()
            ->where('module', $this->checkpointModule($definition))
            ->where('submodule', $definition->submodule ?? '')
            ->value('lease_expires_at');
        $seconds = $expiresAt === null ? 1 : max(1, now()->diffInSeconds(CarbonImmutable::parse($expiresAt), false) + 1);

        return min(
            max(1, (int) config('zoho-v2.module.lease_conflict_max_delay_seconds', 3600)),
            $seconds,
        );
    }

    private function validateMode(string $mode): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('Unsupported Zoho sync mode.');
        }
    }

    /** @return array<string,int> */
    private function emptyCounters(): array
    {
        return ['seen' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'quarantined' => 0, 'api_requests' => 0];
    }

    private function isExpiredPageToken(mixed $result): bool
    {
        return in_array($result->status, [400, 401, 410], true) && in_array(strtolower((string) $result->errorCode), ['expired_page_token', 'invalid_page_token', 'invalid_token', 'token_bound_data_mismatch'], true);
    }

    private function safe(string $value): string
    {
        return Str::limit(preg_replace('/[\r\n]+/', ' ', $value) ?? 'sync failure', 500);
    }

    /** @param list<ZohoSyncLog> $logs @return array<string,int> */
    private function sumCounters(array $logs): array
    {
        $keys = ['records_seen', 'records_created', 'records_updated', 'records_unchanged', 'records_quarantined', 'api_requests'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (int) collect($logs)->sum($key);
        }

        return $out;
    }
}
