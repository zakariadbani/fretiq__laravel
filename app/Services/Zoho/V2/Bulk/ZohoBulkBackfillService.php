<?php

namespace App\Services\Zoho\V2\Bulk;

use App\Models\Zoho\ZohoBulkReadJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\Zoho\ZohoSyncWorkItem;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\Registry\ModuleDefinition;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoRecordIngestor;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use App\Services\Zoho\V2\Transport\ZohoBulkReadTransport;
use App\Services\Zoho\V2\Transport\ZohoBulkReadTransportException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Durable, one-short-step-per-delivery Bulk Read state machine. */
class ZohoBulkBackfillService
{
    private const ROOT_PAGE_KEY = 'root';

    private const TERMINAL_PAGE_STATUSES = ['complete', 'partial', 'superseded'];

    public function __construct(
        private readonly ZohoBulkReadTransport $transport,
        private readonly ZohoModuleRegistry $registry,
        private readonly BulkCsvIdParser $parser,
        private readonly ZohoTransport $records,
        private readonly ZohoRecordIngestor $ingestor,
        private readonly ZohoSyncOrchestrator $orchestrator,
        private readonly ?VerifiedBulkModules $verifiedModules = null,
    ) {}

    public function step(
        int $batchId,
        string $module,
        string $correlationId,
        ?int $requestedPageId = null,
        ?string $deliveryOwner = null,
        ?string $runOwner = null,
        int $deliveryGeneration = 0,
    ): BulkBackfillStep {
        if (! config('zoho-v2.features.sync_enabled', false)
            || ! config('zoho-v2.features.bulk_backfill_enabled', false)) {
            return BulkBackfillStep::complete($requestedPageId);
        }

        $batch = ZohoSyncBatch::query()->findOrFail($batchId);
        if (! hash_equals((string) $batch->correlation_id, $correlationId)
            || ! in_array($module, (array) $batch->modules, true)) {
            throw new RuntimeException('Bulk Read run context is invalid.');
        }

        $definition = $this->registry->get($module);
        if (! $definition->bulkReadSupported
            || $definition->activationGated
            || ! ($this->verifiedModules ?? new VerifiedBulkModules)->allows($module)) {
            throw new RuntimeException('Bulk Read is not supported for this module.');
        }

        $terminal = $this->terminalDeliveryResult($batch, $definition, $requestedPageId);
        if ($terminal !== null) {
            return $terminal;
        }

        $root = $this->rootPage($batch, $definition, $this->safeRunOwner($runOwner));
        $moduleLease = $this->acquireModuleLease($root, $batch);
        if ($moduleLease === null) {
            // A different unfinished standard/Bulk delivery owns this module
            // outbox even if it has intentionally released its short lease.
            // Do not create an endless Bulk redispatch chain for a batch that
            // can no longer own the checkpoint.
            $this->supersedeForeignOutboxRoot($root, $batch);
            $this->orchestrator->terminalizeModule($batch->id, $module, 'backfill', 'dispatch_failed');

            return BulkBackfillStep::failed($requestedPageId ?? $root->id);
        }
        if (! $moduleLease) {
            return BulkBackfillStep::redispatch($requestedPageId ?? $root->id, $this->lockRetrySeconds());
        }

        $page = $this->selectPage($batch, $definition, $requestedPageId);
        if ($page === null) {
            return $this->finalizeIfComplete($batch, $module, (string) $root->module_lease_owner, $root->id);
        }

        if ($page->status === 'terminalizing') {
            return BulkBackfillStep::redispatch($page->id, $this->lockRetrySeconds());
        }

        if ($page->retry_after?->isFuture()) {
            return BulkBackfillStep::redispatch($page->id, $this->secondsUntil($page->retry_after));
        }

        $pageLease = $this->claimPageLease(
            $page->id,
            $this->safeDeliveryOwner($deliveryOwner),
            $root,
            max(0, $deliveryGeneration),
        );
        if ($pageLease === null) {
            return BulkBackfillStep::redispatch($page->id, $this->lockRetrySeconds());
        }

        [$page, $leaseOwner] = $pageLease;
        if (! $this->renewModuleLease($root, $batch)) {
            $this->releasePageLease($page, $leaseOwner);

            return BulkBackfillStep::redispatch($page->id, $this->lockRetrySeconds());
        }

        if (in_array($page->status, ['queued', 'polling', 'retry_wait'], true)) {
            return $this->advanceExport($page, $leaseOwner, $root, $batch, $definition);
        }

        return $this->processWork($page, $leaseOwner, $root, $batch, $definition);
    }

    private function rootPage(
        ZohoSyncBatch $batch,
        ModuleDefinition $definition,
        ?string $runOwner,
    ): ZohoBulkReadJob {
        return DB::transaction(function () use ($batch, $definition, $runOwner): ZohoBulkReadJob {
            $lockedBatch = ZohoSyncBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $watermark = $lockedBatch->started_at ?? now();
            $lockedBatch->update([
                'status' => 'running',
                'started_at' => $watermark,
            ]);
            $root = ZohoBulkReadJob::query()->where([
                'sync_batch_id' => $batch->id,
                'module' => $definition->key,
                'submodule' => $this->submodule($definition),
                'page_key' => self::ROOT_PAGE_KEY,
            ])->lockForUpdate()->first();

            if ($root === null) {
                $root = ZohoBulkReadJob::query()->create([
                    'sync_batch_id' => $batch->id,
                    'module' => $definition->key,
                    'submodule' => $this->submodule($definition),
                    'page_key' => self::ROOT_PAGE_KEY,
                    'page_token' => null,
                    'correlation_id' => $batch->correlation_id,
                    'status' => 'queued',
                    'module_lease_owner' => $runOwner ?? (string) Str::uuid(),
                    'delivery_generation' => 0,
                    'started_at' => $watermark,
                    'watermark_at' => $watermark,
                    'counters' => $this->emptyPageCounters(),
                ]);
            } elseif (! is_string($root->module_lease_owner) || $root->module_lease_owner === '') {
                $root->update(['module_lease_owner' => $runOwner ?? (string) Str::uuid()]);
            }

            return $root->fresh();
        });
    }

    private function terminalDeliveryResult(
        ZohoSyncBatch $batch,
        ModuleDefinition $definition,
        ?int $requestedPageId,
    ): ?BulkBackfillStep {
        $pages = ZohoBulkReadJob::query()
            ->where('sync_batch_id', $batch->id)
            ->where('module', $definition->key)
            ->where('submodule', $this->submodule($definition));
        if (! (clone $pages)->exists()
            || (clone $pages)->whereNotIn('status', self::TERMINAL_PAGE_STATUSES)->exists()) {
            return null;
        }

        $log = ZohoSyncLog::query()
            ->where('sync_batch_id', $batch->id)
            ->where('module', $definition->key)
            ->where('submodule', $this->submodule($definition))
            ->where('mode', 'backfill')
            ->latest('id')
            ->first();
        if ($log === null) {
            return null;
        }

        // A worker can die after committing the module log but before the
        // aggregate batch finalizer returns. A late delivery repairs only
        // that aggregate state and never reacquires the module checkpoint.
        $this->orchestrator->finalizeBatch($batch->id);
        $pageId = $requestedPageId ?? (clone $pages)->orderBy('id')->value('id');

        return $log->status === 'error'
            ? BulkBackfillStep::failed(is_numeric($pageId) ? (int) $pageId : null)
            : BulkBackfillStep::complete(is_numeric($pageId) ? (int) $pageId : null);
    }

    /**
     * The checkpoint lease is the cross-engine module lock. Standard sync
     * already claims this same v2:{module} row, so Bulk and Records API jobs
     * cannot hydrate one module concurrently even though their queue job
     * classes use different uniqueness namespaces.
     */
    /**
     * @return true acquired, false temporarily busy, null foreign unfinished outbox
     */
    private function acquireModuleLease(ZohoBulkReadJob $root, ZohoSyncBatch $batch): ?bool
    {
        return DB::transaction(function () use ($root, $batch): ?bool {
            $checkpoint = ZohoSyncCheckpoint::query()
                ->where('module', 'v2:'.$root->module)
                ->where('submodule', $root->submodule)
                ->lockForUpdate()
                ->first();
            if ($checkpoint === null) {
                $checkpoint = ZohoSyncCheckpoint::query()->create([
                    'module' => 'v2:'.$root->module,
                    'submodule' => $root->submodule,
                    'sync_mode' => 'backfill',
                    'status' => 'idle',
                ]);
                $checkpoint->refresh();
            }

            // Lease-free queued/retrying checkpoints are still durable outbox
            // ownership. Only a completed historical batch may hand its
            // checkpoint to this Bulk run.
            if ($checkpoint->sync_batch_id !== null && (int) $checkpoint->sync_batch_id !== (int) $batch->id) {
                $ownerBatch = ZohoSyncBatch::query()->lockForUpdate()->find($checkpoint->sync_batch_id);
                if ($ownerBatch !== null && $ownerBatch->completed_at === null) {
                    return null;
                }
            }

            $owner = (string) $root->module_lease_owner;
            if ($checkpoint->lease_expires_at?->isFuture()
                && ! hash_equals((string) $checkpoint->lease_owner, $owner)) {
                return false;
            }

            $watermark = $root->watermark_at ?? $root->started_at ?? $batch->started_at;
            $preserveNewer = $this->checkpointIsNewer($checkpoint, $batch, $watermark);
            $values = [
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'heartbeat_at' => now(),
            ];
            if (! $preserveNewer) {
                $values += [
                    'sync_mode' => 'backfill',
                    'status' => 'running',
                    'correlation_id' => $batch->correlation_id,
                ];
            }
            $values['sync_batch_id'] = $batch->id;
            $checkpoint->update($values);

            return true;
        });
    }

    private function renewModuleLease(ZohoBulkReadJob $root, ZohoSyncBatch $batch): bool
    {
        return DB::transaction(function () use ($root, $batch): bool {
            $checkpoint = ZohoSyncCheckpoint::query()
                ->where('module', 'v2:'.$root->module)
                ->where('submodule', $root->submodule)
                ->lockForUpdate()
                ->first();
            if ($checkpoint === null
                || ! hash_equals((string) $checkpoint->lease_owner, (string) $root->module_lease_owner)) {
                return false;
            }

            $watermark = $root->watermark_at ?? $root->started_at ?? $batch->started_at;
            $values = [
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'heartbeat_at' => now(),
            ];
            if (! $this->checkpointIsNewer($checkpoint, $batch, $watermark)) {
                $values['correlation_id'] = $batch->correlation_id;
            }
            $checkpoint->update($values);

            return true;
        });
    }

    private function supersedeForeignOutboxRoot(ZohoBulkReadJob $root, ZohoSyncBatch $batch): void
    {
        DB::transaction(function () use ($root, $batch): void {
            ZohoBulkReadJob::query()->whereKey($root->id)
                ->where('sync_batch_id', $batch->id)
                ->where('page_key', self::ROOT_PAGE_KEY)
                ->whereNotIn('status', self::TERMINAL_PAGE_STATUSES)
                ->update([
                    'status' => 'superseded',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'completed_at' => now(),
                    'error_summary' => 'Bulk delivery could not acquire the module outbox.',
                ]);
        });
    }

    private function selectPage(
        ZohoSyncBatch $batch,
        ModuleDefinition $definition,
        ?int $requestedPageId,
    ): ?ZohoBulkReadJob {
        $query = ZohoBulkReadJob::query()
            ->where('sync_batch_id', $batch->id)
            ->where('module', $definition->key)
            ->where('submodule', $this->submodule($definition))
            ->whereNotIn('status', self::TERMINAL_PAGE_STATUSES);

        // Continuation tokens expire quickly. Finish staging every export
        // before hydrating any staged record IDs.
        $exports = (clone $query)->whereIn('status', ['queued', 'polling', 'retry_wait']);
        if ($requestedPageId !== null) {
            $requestedExport = (clone $exports)->whereKey($requestedPageId)->first();
            if ($requestedExport !== null) {
                return $requestedExport;
            }
        }
        $nextExport = $exports->orderBy('id')->first();
        if ($nextExport !== null) {
            return $nextExport;
        }

        if ($requestedPageId !== null) {
            $requested = (clone $query)->whereKey($requestedPageId)->first();
            if ($requested !== null) {
                return $requested;
            }
        }

        return $query->orderBy('id')->first();
    }

    /** @return array{ZohoBulkReadJob,string}|null */
    private function claimPageLease(
        int $pageId,
        string $owner,
        ZohoBulkReadJob $root,
        int $deliveryGeneration,
    ): ?array {
        return DB::transaction(function () use ($pageId, $owner, $root, $deliveryGeneration): ?array {
            $lockedRoot = ZohoBulkReadJob::query()->lockForUpdate()->findOrFail($root->id);
            $page = ZohoBulkReadJob::query()->lockForUpdate()->findOrFail($pageId);
            if (in_array($page->status, self::TERMINAL_PAGE_STATUSES, true)
                || $page->lease_expires_at?->isFuture()) {
                return null;
            }

            $page->update([
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'heartbeat_at' => now(),
                'delivery_generation' => max((int) $page->delivery_generation, $deliveryGeneration),
            ]);
            if ((int) $lockedRoot->delivery_generation < $deliveryGeneration) {
                $lockedRoot->update(['delivery_generation' => $deliveryGeneration]);
            }

            return [$page->fresh(), $owner];
        });
    }

    private function advanceExport(
        ZohoBulkReadJob $page,
        string $leaseOwner,
        ZohoBulkReadJob $root,
        ZohoSyncBatch $batch,
        ModuleDefinition $definition,
    ): BulkBackfillStep {
        $counters = $this->pageCounters($page);

        try {
            if (! is_string($page->zoho_job_id) || $page->zoho_job_id === '') {
                $created = $this->transport->create($definition->apiName, $page->page_token, $page->correlation_id);
                $counters['api_requests'] += $created->attempts;
                $remoteId = data_get($created->payload, 'data.0.details.id') ?? data_get($created->payload, 'data.0.id');
                if (! is_string($remoteId) || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $remoteId) !== 1) {
                    throw new RuntimeException('Bulk Read creation did not return a job identifier.');
                }

                $this->conditionalPageUpdate($page, $leaseOwner, [
                    'zoho_job_id' => $remoteId,
                    'status' => 'polling',
                    'retry_after' => now()->addSeconds($this->pollDelaySeconds()),
                    'counters' => $counters,
                    'error_summary' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => now(),
                ]);

                return BulkBackfillStep::redispatch($page->id, $this->pollDelaySeconds());
            }

            $status = $this->transport->status($page->zoho_job_id, $page->correlation_id);
            $counters['api_requests'] += $status->attempts;
            $state = strtoupper((string) (data_get($status->payload, 'data.0.state')
                ?? data_get($status->payload, 'data.0.status')
                ?? ''));
            if (in_array($state, ['ADDED', 'QUEUED', 'IN_PROGRESS', 'IN PROGRESS'], true)) {
                $this->conditionalPageUpdate($page, $leaseOwner, [
                    'status' => 'polling',
                    'retry_after' => now()->addSeconds($this->pollDelaySeconds()),
                    'counters' => $counters,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => now(),
                ]);

                return BulkBackfillStep::redispatch($page->id, $this->pollDelaySeconds());
            }
            if ($state !== 'COMPLETED') {
                throw new RuntimeException('Bulk Read export did not complete.');
            }

            [$url, $reported, $nextToken] = $this->completedResult($status->payload);

            $download = $this->transport->downloadToTempFile($url, $page->correlation_id);
            $counters['api_requests'] += $download->attempts;
            $parsed = 0;
            try {
                foreach ($this->parser->idChunks($download->path, $this->stagingBatchSize()) as $ids) {
                    if (! $this->heartbeatPage($page, $leaseOwner)
                        || ! $this->renewModuleLease($root, $batch)) {
                        throw new RuntimeException('Bulk Read lease ownership was lost.');
                    }
                    $this->insertWorkItems($page, $ids);
                    $parsed += count($ids);
                }
            } finally {
                @unlink($download->path);
            }
            if ($parsed !== $reported) {
                throw new RuntimeException('Bulk Read result count did not match the streamed CSV.');
            }

            DB::transaction(function () use (
                $page,
                $leaseOwner,
                $root,
                $nextToken,
                $reported,
                $parsed,
                &$counters,
            ): void {
                $staged = ZohoSyncWorkItem::query()
                    ->where('zoho_bulk_read_job_id', $page->id)
                    ->count();
                if ($staged !== $reported) {
                    throw new RuntimeException('Bulk Read result contained duplicate record identifiers.');
                }

                if (is_string($nextToken)) {
                    if (ZohoBulkReadJob::query()
                        ->where('sync_batch_id', $page->sync_batch_id)
                        ->where('module', $page->module)
                        ->where('submodule', $page->submodule)
                        ->where('page_token', $nextToken)
                        ->where('status', '!=', 'superseded')
                        ->exists()) {
                        throw new RuntimeException('Bulk Read continuation token cycle detected.');
                    }
                    $continuation = ZohoBulkReadJob::query()->firstOrNew([
                        'sync_batch_id' => $page->sync_batch_id,
                        'module' => $page->module,
                        'submodule' => $page->submodule,
                        'page_key' => hash('sha256', $nextToken),
                    ]);
                    if (! $continuation->exists || $continuation->status === 'superseded') {
                        $continuationCounters = $continuation->exists
                            ? $this->pageCounters($continuation)
                            : $this->emptyPageCounters();
                        $continuationCounters['staged'] = 0;
                        $continuationCounters['reported'] = 0;
                        $continuationCounters['parsed'] = 0;
                        $continuationCounters['export_failed'] = 0;
                        $continuation->fill([
                            'page_token' => $nextToken,
                            'correlation_id' => $page->correlation_id,
                            'status' => 'queued',
                            'zoho_job_id' => null,
                            'module_lease_owner' => $root->module_lease_owner,
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'retry_after' => null,
                            'attempts' => 0,
                            'started_at' => $root->started_at,
                            'watermark_at' => $root->watermark_at,
                            'completed_at' => null,
                            'counters' => $continuationCounters,
                        ])->save();
                    }
                }

                $counters['reported'] = $reported;
                $counters['parsed'] = $parsed;
                $counters['staged'] = $staged;
                $this->conditionalPageUpdate($page, $leaseOwner, [
                    'status' => 'staged',
                    'retry_after' => null,
                    'counters' => $counters,
                    'error_summary' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => now(),
                ]);
                $this->resolveExportFailure($page);
            });

            return BulkBackfillStep::redispatch($page->id);
        } catch (ZohoBulkReadTransportException $exception) {
            $counters['api_requests'] += $exception->attempts;
            if ($exception->reason === ZohoBulkReadTransportException::CAPACITY_DEFERRED) {
                return $this->deferExport(
                    $page,
                    $leaseOwner,
                    $counters,
                    $exception->retryAfterSeconds ?? $this->lockRetrySeconds(),
                );
            }
            if ($page->page_token !== null
                && in_array($exception->reason, [
                    ZohoBulkReadTransportException::TOKEN_EXPIRED,
                    ZohoBulkReadTransportException::TOKEN_INVALID,
                ], true)) {
                $restart = $this->restartExpiredContinuation(
                    $page,
                    $leaseOwner,
                    $root,
                    $batch,
                    $counters,
                );
                if ($restart !== null) {
                    return $restart;
                }
            }

            return $this->recordExportFailure($page, $leaseOwner, $root, $batch, $counters);
        } catch (Throwable) {
            return $this->recordExportFailure($page, $leaseOwner, $root, $batch, $counters);
        }
    }

    /** @return array{string,int,?string} */
    private function completedResult(array $payload): array
    {
        $result = data_get($payload, 'data.0.result');
        if (! is_array($result)) {
            throw new RuntimeException('Bulk Read completion metadata was invalid.');
        }

        $url = $result['download_url'] ?? null;
        $reported = $result['count'] ?? null;
        $more = $result['more_records'] ?? null;
        $nextToken = $result['next_page_token'] ?? null;
        if (! is_string($url) || $url === ''
            || ! is_int($reported)
            || $reported < 0
            || $reported > $this->maxRecordsPerExport()
            || ! is_bool($more)
            || ($nextToken !== null
                && (! is_string($nextToken) || $nextToken === '' || strlen($nextToken) > 512))
            || ($more && ! is_string($nextToken))
            || (! $more && $nextToken !== null)) {
            throw new RuntimeException('Bulk Read completion metadata was inconsistent.');
        }

        return [$url, $reported, $nextToken];
    }

    private function restartExpiredContinuation(
        ZohoBulkReadJob $page,
        string $leaseOwner,
        ZohoBulkReadJob $root,
        ZohoSyncBatch $batch,
        array $counters,
    ): ?BulkBackfillStep {
        return DB::transaction(function () use (
            $page,
            $leaseOwner,
            $root,
            $batch,
            $counters,
        ): ?BulkBackfillStep {
            $lockedPage = ZohoBulkReadJob::query()
                ->whereKey($page->id)
                ->where('lease_owner', $leaseOwner)
                ->lockForUpdate()
                ->first();
            $lockedRoot = ZohoBulkReadJob::query()->lockForUpdate()->find($root->id);
            if ($lockedPage === null || $lockedRoot === null
                || (int) data_get($lockedRoot->counters, 'token_restarts', 0) >= 1) {
                return null;
            }

            $pageIds = ZohoBulkReadJob::query()
                ->where('sync_batch_id', $batch->id)
                ->where('module', $page->module)
                ->where('submodule', $page->submodule)
                ->pluck('id');
            if (ZohoSyncWorkItem::query()
                ->whereIn('zoho_bulk_read_job_id', $pageIds)
                ->whereIn('status', ['processing', 'completed', 'quarantined'])
                ->exists()) {
                return null;
            }

            $lockedPage->update(['counters' => $counters]);
            $this->upsertFailure(
                $this->exportFailureKey($lockedPage),
                $lockedPage,
                null,
                'bulk_export',
                ['bulk_job_id' => $lockedPage->id, 'page_key' => $lockedPage->page_key],
            );
            ZohoSyncWorkItem::query()->whereIn('zoho_bulk_read_job_id', $pageIds)->delete();
            ZohoBulkReadJob::query()
                ->whereIn('id', $pageIds)
                ->where('id', '!=', $lockedRoot->id)
                ->whereNotIn('status', self::TERMINAL_PAGE_STATUSES)
                ->update([
                    'status' => 'superseded',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'retry_after' => null,
                    'completed_at' => now(),
                    'heartbeat_at' => now(),
                ]);

            $rootCounters = $this->pageCounters($lockedRoot);
            $rootCounters['staged'] = 0;
            $rootCounters['reported'] = 0;
            $rootCounters['parsed'] = 0;
            $rootCounters['token_restarts']++;
            $lockedRoot->update([
                'zoho_job_id' => null,
                'page_token' => null,
                'status' => 'queued',
                'attempts' => 0,
                'retry_after' => null,
                'counters' => $rootCounters,
                'error_summary' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'completed_at' => null,
                'heartbeat_at' => now(),
            ]);

            return BulkBackfillStep::redispatch($lockedRoot->id);
        });
    }

    private function processWork(
        ZohoBulkReadJob $page,
        string $pageLeaseOwner,
        ZohoBulkReadJob $root,
        ZohoSyncBatch $batch,
        ModuleDefinition $definition,
    ): BulkBackfillStep {
        $this->terminalizeExpiredMaxAttemptClaims($page);
        $workLease = (string) Str::uuid();
        $items = $this->claimWork($page, $workLease);
        foreach ($items as $item) {
            $apiRequests = 0;
            try {
                $result = $this->records->get(
                    '/'.$definition->apiName.'/'.rawurlencode($item->zoho_id),
                    $definition->recordQuery,
                    $page->correlation_id,
                );
                $apiRequests = $result->apiRequestCount();
                if ($result->errorCode === 'throttle_unavailable') {
                    $this->deferClaimedWork($items, $workLease, $this->capacityDeferralSeconds());
                    $this->releasePageLease($page, $pageLeaseOwner);

                    return BulkBackfillStep::redispatch($page->id, $this->capacityDeferralSeconds());
                }
                $record = data_get($result->root('data'), '0');
                if (! $result->successful() || ! is_array($record)) {
                    throw new RuntimeException('Specific record hydration failed.');
                }
                DB::transaction(function () use (
                    $definition,
                    $record,
                    $batch,
                    $item,
                    $workLease,
                    $apiRequests,
                    $page,
                ): void {
                    $locked = ZohoSyncWorkItem::query()
                        ->whereKey($item->id)
                        ->where('lease_owner', $workLease)
                        ->lockForUpdate()
                        ->first();
                    if ($locked === null) {
                        throw new RuntimeException('Bulk work lease ownership was lost.');
                    }

                    $counts = $this->ingestor->ingest($definition, $record, $batch);
                    $locked->update([
                        'status' => 'completed',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'retry_after' => null,
                        'processed_at' => now(),
                        'heartbeat_at' => now(),
                        'records_created' => $counts['created'],
                        'records_updated' => $counts['updated'],
                        'records_unchanged' => $counts['unchanged'],
                        'api_requests' => ((int) $locked->api_requests) + $apiRequests,
                    ]);
                    $this->resolveRecordFailure($page->module, $page->submodule, $locked->zoho_id);
                });
            } catch (Throwable) {
                $this->quarantineWork($page, $item, $workLease, $apiRequests);
            }

            if (! $this->heartbeatPage($page, $pageLeaseOwner)
                || ! $this->renewModuleLease($root, $batch)) {
                $this->releasePageLease($page, $pageLeaseOwner);

                return BulkBackfillStep::redispatch($page->id, $this->lockRetrySeconds());
            }
        }

        $this->releasePageLease($page, $pageLeaseOwner);

        return $this->nextPageOrFinalize($page, $root, $batch);
    }

    private function terminalizeExpiredMaxAttemptClaims(ZohoBulkReadJob $page): void
    {
        DB::transaction(function () use ($page): void {
            $items = ZohoSyncWorkItem::query()
                ->where('zoho_bulk_read_job_id', $page->id)
                ->where('status', 'processing')
                ->where('attempts', '>=', $this->maxAttempts())
                ->where(fn (Builder $stale): Builder => $stale
                    ->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now()))
                ->lockForUpdate()
                ->limit($this->workBatchSize())
                ->get();

            foreach ($items as $item) {
                $item->update([
                    'status' => 'quarantined',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'retry_after' => null,
                    'heartbeat_at' => now(),
                ]);
                $this->upsertFailure(
                    $this->recordFailureKey($page->module, $page->submodule, $item->zoho_id),
                    $page,
                    $item->zoho_id,
                    'record',
                    ['bulk_job_id' => $page->id, 'work_item_id' => $item->id],
                );
            }
        });
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,ZohoSyncWorkItem> */
    private function claimWork(ZohoBulkReadJob $page, string $leaseOwner): mixed
    {
        return DB::transaction(function () use ($page, $leaseOwner) {
            $items = ZohoSyncWorkItem::query()
                ->where('zoho_bulk_read_job_id', $page->id)
                ->where('attempts', '<', $this->maxAttempts())
                ->where(function (Builder $query): void {
                    $query->where('status', 'queued')
                        ->orWhere(function (Builder $retry): void {
                            $retry->where('status', 'retry_wait')
                                ->where(fn (Builder $due) => $due->whereNull('retry_after')->orWhere('retry_after', '<=', now()));
                        })
                        ->orWhere(function (Builder $stale): void {
                            $stale->where('status', 'processing')
                                ->where(fn (Builder $expired): Builder => $expired
                                    ->whereNull('lease_expires_at')
                                    ->orWhere('lease_expires_at', '<=', now()));
                        });
                })
                ->lockForUpdate()
                ->limit($this->workBatchSize())
                ->get();

            foreach ($items as $item) {
                $item->update([
                    'status' => 'processing',
                    'lease_owner' => $leaseOwner,
                    'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                    'heartbeat_at' => now(),
                    'attempts' => ((int) $item->attempts) + 1,
                ]);
            }

            return $items->map(fn (ZohoSyncWorkItem $item) => $item->fresh());
        });
    }

    private function nextPageOrFinalize(
        ZohoBulkReadJob $page,
        ZohoBulkReadJob $root,
        ZohoSyncBatch $batch,
    ): BulkBackfillStep {
        $pending = ZohoSyncWorkItem::query()
            ->where('zoho_bulk_read_job_id', $page->id)
            ->whereIn('status', ['queued', 'processing', 'retry_wait'])
            ->exists();
        if ($pending) {
            $retryAt = ZohoSyncWorkItem::query()
                ->where('zoho_bulk_read_job_id', $page->id)
                ->where(function (Builder $query): void {
                    $query->where(fn (Builder $retry): Builder => $retry
                        ->where('status', 'retry_wait')
                        ->whereNotNull('retry_after'))
                        ->orWhere(fn (Builder $processing): Builder => $processing
                            ->where('status', 'processing')
                            ->whereNotNull('lease_expires_at'));
                })
                ->selectRaw('MIN(COALESCE(retry_after, lease_expires_at)) as due_at')
                ->value('due_at');

            return BulkBackfillStep::redispatch(
                $page->id,
                $retryAt ? $this->secondsUntil($retryAt) : $this->lockRetrySeconds(),
            );
        }

        $partial = ZohoSyncWorkItem::query()
            ->where('zoho_bulk_read_job_id', $page->id)
            ->where('status', 'quarantined')
            ->exists()
            || (int) data_get($page->fresh()->counters, 'export_failed', 0) > 0;
        $page->update([
            'status' => $partial ? 'partial' : 'complete',
            'completed_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $next = ZohoBulkReadJob::query()
            ->where('sync_batch_id', $batch->id)
            ->where('module', $page->module)
            ->where('submodule', $page->submodule)
            ->whereNotIn('status', self::TERMINAL_PAGE_STATUSES)
            ->orderBy('id')
            ->first();
        if ($next !== null) {
            return BulkBackfillStep::redispatch($next->id);
        }

        return $this->finalizeIfComplete($batch, $page->module, (string) $root->module_lease_owner, $page->id);
    }

    private function recordExportFailure(
        ZohoBulkReadJob $page,
        string $leaseOwner,
        ZohoBulkReadJob $root,
        ZohoSyncBatch $batch,
        array $counters,
    ): BulkBackfillStep {
        $terminal = false;
        DB::transaction(function () use ($page, $leaseOwner, &$counters, &$terminal): void {
            $locked = ZohoBulkReadJob::query()->whereKey($page->id)->where('lease_owner', $leaseOwner)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $attempts = ((int) $locked->attempts) + 1;
            $terminal = $attempts >= $this->maxAttempts();
            if ($terminal) {
                $counters['export_failed'] = 1;
            }
            $hasWork = ZohoSyncWorkItem::query()->where('zoho_bulk_read_job_id', $page->id)->exists();
            $locked->update([
                'status' => $terminal ? ($hasWork ? 'staged' : 'partial') : 'retry_wait',
                'attempts' => $attempts,
                'retry_after' => $terminal ? null : now()->addSeconds($this->failureRetrySeconds()),
                'counters' => $counters,
                'error_summary' => 'Bulk Read export failed; see correlation ID.',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
                'completed_at' => $terminal && ! $hasWork ? now() : null,
            ]);
            $this->upsertFailure(
                $this->exportFailureKey($locked),
                $locked,
                null,
                'bulk_export',
                ['bulk_job_id' => $locked->id, 'page_key' => $locked->page_key],
            );
        });

        if (! $terminal) {
            return BulkBackfillStep::redispatch($page->id, $this->failureRetrySeconds());
        }

        if (ZohoSyncWorkItem::query()->where('zoho_bulk_read_job_id', $page->id)->exists()) {
            return BulkBackfillStep::redispatch($page->id);
        }

        return $this->finalizeIfComplete($batch, $page->module, (string) $root->module_lease_owner, $page->id);
    }

    /** @param array<string,int> $counters */
    private function deferExport(
        ZohoBulkReadJob $page,
        string $leaseOwner,
        array $counters,
        int $delaySeconds,
    ): BulkBackfillStep {
        $delaySeconds = max(1, min(43_200, $delaySeconds));
        $status = is_string($page->zoho_job_id) && $page->zoho_job_id !== ''
            ? 'polling'
            : 'queued';
        $this->conditionalPageUpdate($page, $leaseOwner, [
            'status' => $status,
            'retry_after' => now()->addSeconds($delaySeconds),
            'counters' => $counters,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => now(),
        ]);

        return BulkBackfillStep::redispatch($page->id, $delaySeconds);
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int,ZohoSyncWorkItem> $items */
    private function deferClaimedWork(mixed $items, string $leaseOwner, int $delaySeconds): void
    {
        $ids = $items->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids, $leaseOwner, $delaySeconds): void {
            $locked = ZohoSyncWorkItem::query()
                ->whereIn('id', $ids)
                ->where('lease_owner', $leaseOwner)
                ->lockForUpdate()
                ->get();
            foreach ($locked as $item) {
                $item->update([
                    'status' => 'retry_wait',
                    // Admission never reached Zoho and must not consume the
                    // durable record-attempt budget claimed before the call.
                    'attempts' => max(0, ((int) $item->attempts) - 1),
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'retry_after' => now()->addSeconds(max(1, min(3_600, $delaySeconds))),
                    'heartbeat_at' => now(),
                ]);
            }
        });
    }

    private function quarantineWork(
        ZohoBulkReadJob $page,
        ZohoSyncWorkItem $item,
        string $leaseOwner,
        int $apiRequests,
    ): void {
        DB::transaction(function () use ($page, $item, $leaseOwner, $apiRequests): void {
            $locked = ZohoSyncWorkItem::query()->whereKey($item->id)->where('lease_owner', $leaseOwner)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            $terminal = (int) $locked->attempts >= $this->maxAttempts();
            $locked->update([
                'status' => $terminal ? 'quarantined' : 'retry_wait',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'retry_after' => $terminal ? null : now()->addSeconds($this->failureRetrySeconds()),
                'heartbeat_at' => now(),
                'api_requests' => ((int) $locked->api_requests) + $apiRequests,
            ]);
            $this->upsertFailure(
                $this->recordFailureKey($page->module, $page->submodule, $locked->zoho_id),
                $page,
                $locked->zoho_id,
                'record',
                ['bulk_job_id' => $page->id, 'work_item_id' => $locked->id],
            );
        });
    }

    /** @param list<string> $ids */
    private function insertWorkItems(ZohoBulkReadJob $page, array $ids): void
    {
        $now = now();
        $rows = array_map(static fn (string $id): array => [
            'zoho_bulk_read_job_id' => $page->id,
            'module' => $page->module,
            'zoho_id' => $id,
            'status' => 'queued',
            'correlation_id' => $page->correlation_id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids);

        foreach (array_chunk($rows, $this->stagingBatchSize()) as $chunk) {
            DB::table('zoho_sync_work_items')->insertOrIgnore($chunk);
        }
    }

    private function finalizeIfComplete(
        ZohoSyncBatch $batch,
        string $module,
        string $moduleLeaseOwner,
        int $lastPageId,
    ): BulkBackfillStep {
        $root = ZohoBulkReadJob::query()
            ->where('sync_batch_id', $batch->id)
            ->where('module', $module)
            ->where('page_key', self::ROOT_PAGE_KEY)
            ->firstOrFail();
        $submodule = (string) $root->submodule;
        $pagesQuery = ZohoBulkReadJob::query()
            ->where('sync_batch_id', $batch->id)
            ->where('module', $module)
            ->where('submodule', $submodule);

        if ((clone $pagesQuery)->whereNotIn('status', self::TERMINAL_PAGE_STATUSES)->exists()) {
            $next = (clone $pagesQuery)
                ->whereNotIn('status', self::TERMINAL_PAGE_STATUSES)
                ->orderByRaw("CASE WHEN status IN ('queued','polling','retry_wait') THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->firstOrFail();

            return BulkBackfillStep::redispatch($next->id);
        }

        $status = DB::transaction(function () use (
            $batch,
            $module,
            $submodule,
            $moduleLeaseOwner,
            $root,
        ): string {
            $checkpoint = ZohoSyncCheckpoint::query()
                ->where('module', 'v2:'.$module)
                ->where('submodule', $submodule)
                ->lockForUpdate()
                ->firstOrFail();
            if (! hash_equals((string) $checkpoint->lease_owner, $moduleLeaseOwner)) {
                return 'waiting';
            }

            $pages = ZohoBulkReadJob::query()
                ->where('sync_batch_id', $batch->id)
                ->where('module', $module)
                ->where('submodule', $submodule)
                ->lockForUpdate()
                ->get();
            $pageIds = $pages->pluck('id');
            $workQuery = ZohoSyncWorkItem::query()->whereIn('zoho_bulk_read_job_id', $pageIds);
            if ((clone $workQuery)->whereIn('status', ['queued', 'processing', 'retry_wait'])->exists()) {
                return 'waiting';
            }

            // The exported ID set can contain hundreds of thousands of rows.
            // Aggregate it in SQL; never hydrate the work table into PHP at
            // module finalization.
            $workAggregate = (clone $workQuery)->selectRaw(implode(', ', [
                'COUNT(*) as seen',
                "SUM(CASE WHEN status = 'quarantined' THEN 1 ELSE 0 END) as quarantined",
                'COALESCE(SUM(records_created), 0) as created',
                'COALESCE(SUM(records_updated), 0) as updated',
                'COALESCE(SUM(records_unchanged), 0) as unchanged_count',
                'COALESCE(SUM(api_requests), 0) as api_requests',
            ]))->first();
            $seen = (int) ($workAggregate?->seen ?? 0);
            $quarantined = (int) ($workAggregate?->quarantined ?? 0);
            $created = (int) ($workAggregate?->created ?? 0);
            $updated = (int) ($workAggregate?->updated ?? 0);
            $unchanged = (int) ($workAggregate?->unchanged_count ?? 0);
            $recordApiRequests = (int) ($workAggregate?->api_requests ?? 0);
            $pageAggregate = DB::table('zoho_bulk_read_jobs')
                ->where('sync_batch_id', $batch->id)
                ->where('module', $module)
                ->where('submodule', $submodule)
                ->selectRaw(implode(', ', [
                    'COUNT(*) as page_count',
                    "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(counters, '$.api_requests')) AS UNSIGNED)), 0) as api_requests",
                    "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(counters, '$.export_failed')) AS UNSIGNED)), 0) as export_failures",
                    "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(counters, '$.reported')) AS UNSIGNED)), 0) as reported",
                    "COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(counters, '$.parsed')) AS UNSIGNED)), 0) as parsed",
                    'MIN(started_at) as first_started_at',
                ]))
                ->first();
            $exportApiRequests = (int) ($pageAggregate->api_requests ?? 0);
            $exportFailures = (int) ($pageAggregate->export_failures ?? 0);
            if ($exportFailures === 0) {
                // A complete, authorized backfill supersedes earlier export
                // failures for this exact module/submodule boundary.
                ZohoSyncFailure::query()
                    ->where('module', $module)
                    ->where('submodule', $submodule)
                    ->where('failure_kind', 'bulk_export')
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => now(), 'retry_after' => null]);
            }
            $unresolvedExports = ZohoSyncFailure::query()
                ->where('sync_batch_id', $batch->id)
                ->where('module', $module)
                ->where('submodule', $submodule)
                ->where('failure_kind', 'bulk_export')
                ->whereNull('resolved_at')
                ->count();
            $unresolvedRecords = ZohoSyncFailure::query()
                ->where('sync_batch_id', $batch->id)
                ->where('module', $module)
                ->where('submodule', $submodule)
                ->where('failure_kind', 'record')
                ->whereNull('resolved_at')
                ->count();
            $semanticStatus = ($exportFailures > 0 || $unresolvedExports > 0)
                ? 'error'
                : (($quarantined > 0 || $unresolvedRecords > 0) ? 'partial' : 'success');

            foreach ($pages as $page) {
                if ($page->status === 'superseded') {
                    continue;
                }
                $pagePartial = $semanticStatus === 'error'
                    || ZohoSyncWorkItem::query()->where('zoho_bulk_read_job_id', $page->id)->where('status', 'quarantined')->exists();
                $page->update([
                    'status' => $pagePartial ? 'partial' : 'complete',
                    'completed_at' => $page->completed_at ?? now(),
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ]);
            }

            $counters = [
                'seen' => $seen,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'quarantined' => $quarantined,
                'api_requests' => $recordApiRequests + $exportApiRequests,
            ];
            $watermark = $root->watermark_at ?? $root->started_at ?? $batch->started_at ?? now();
            $newerCheckpoint = $this->checkpointIsNewer($checkpoint, $batch, $watermark);
            $checkpointValues = [
                'lease_owner' => null,
                'lease_expires_at' => null,
            ];
            if (! $newerCheckpoint) {
                $checkpointValues += [
                    'sync_mode' => 'backfill',
                    'status' => $semanticStatus === 'success' ? 'completed' : ($semanticStatus === 'partial' ? 'partial' : 'failed'),
                    'cursor_at' => $watermark,
                    'cursor_modified_time' => $watermark,
                    'cursor_page_token' => null,
                    'heartbeat_at' => now(),
                    'completed_at' => now(),
                    'counters' => $counters,
                    'correlation_id' => $batch->correlation_id,
                ];
            }
            $checkpoint->update($checkpointValues);

            $existing = ZohoSyncLog::query()
                ->where('sync_batch_id', $batch->id)
                ->where('module', $module)
                ->where('submodule', $submodule)
                ->where('mode', 'backfill')
                ->lockForUpdate()
                ->first();
            $startedAt = $pageAggregate->first_started_at !== null
                ? \Illuminate\Support\Carbon::parse($pageAggregate->first_started_at)
                : ($batch->started_at ?? now());
            $logValues = [
                'module' => $module,
                'submodule' => $submodule,
                'mode' => 'backfill',
                'sync_batch_id' => $batch->id,
                'correlation_id' => $batch->correlation_id,
                'synced_at' => now(),
                'records_seen' => $seen,
                'records_synced' => $created + $updated + $unchanged,
                'records_created' => $created,
                'records_updated' => $updated,
                'records_unchanged' => $unchanged,
                'records_quarantined' => $quarantined,
                'status' => $semanticStatus,
                'error' => $semanticStatus === 'success' ? null : 'Bulk backfill degraded; see correlation ID.',
                'duration_ms' => $startedAt->diffInMilliseconds(now()),
                'cursor_at' => $watermark,
                'api_requests' => $recordApiRequests + $exportApiRequests,
                'telemetry' => [
                    'bulk' => [
                        'pages' => (int) ($pageAggregate->page_count ?? 0),
                        'export_failures' => $exportFailures,
                        'reported' => (int) ($pageAggregate->reported ?? 0),
                        'parsed' => (int) ($pageAggregate->parsed ?? 0),
                    ],
                ],
            ];
            if ($existing === null) {
                ZohoSyncLog::query()->create($logValues);
            } else {
                $existing->update($logValues);
            }

            return $semanticStatus;
        });

        if ($status === 'waiting') {
            return BulkBackfillStep::redispatch($lastPageId, $this->lockRetrySeconds());
        }

        $this->orchestrator->finalizeBatch($batch->id);

        return $status === 'error'
            ? BulkBackfillStep::failed($lastPageId)
            : BulkBackfillStep::complete($lastPageId);
    }

    /** @param array<string,mixed> $values */
    private function conditionalPageUpdate(ZohoBulkReadJob $page, string $leaseOwner, array $values): void
    {
        if (ZohoBulkReadJob::query()
            ->whereKey($page->id)
            ->where('lease_owner', $leaseOwner)
            ->update($values) !== 1) {
            throw new RuntimeException('Bulk Read page lease ownership was lost.');
        }
    }

    private function heartbeatPage(ZohoBulkReadJob $page, string $leaseOwner): bool
    {
        $query = ZohoBulkReadJob::query()
            ->whereKey($page->id)
            ->where('lease_owner', $leaseOwner);
        $query->update([
            'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
            'heartbeat_at' => now(),
        ]);

        return (clone $query)->exists();
    }

    private function releasePageLease(ZohoBulkReadJob $page, string $leaseOwner): void
    {
        ZohoBulkReadJob::query()
            ->whereKey($page->id)
            ->where('lease_owner', $leaseOwner)
            ->update([
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
            ]);
    }

    private function upsertFailure(
        string $failureKey,
        ZohoBulkReadJob $page,
        ?string $zohoId,
        string $kind,
        array $context,
    ): void {
        $failure = ZohoSyncFailure::query()->where('failure_key', $failureKey)->lockForUpdate()->first();
        $values = [
            'sync_batch_id' => $page->sync_batch_id,
            'module' => $page->module,
            'submodule' => $page->submodule,
            'zoho_id' => $zohoId,
            'failure_kind' => $kind,
            'correlation_id' => $page->correlation_id,
            'error_summary' => $kind === 'bulk_export'
                ? 'Bulk Read export failed; see correlation ID.'
                : 'Record synchronization failed; see correlation ID.',
            'context' => $context,
            'retry_after' => now()->addSeconds($this->failureRetrySeconds()),
            'resolved_at' => null,
        ];
        if ($failure === null) {
            ZohoSyncFailure::query()->create($values + [
                'failure_key' => $failureKey,
                'attempts' => 1,
            ]);

            return;
        }

        $failure->update($values + ['attempts' => ((int) $failure->attempts) + 1]);
    }

    private function resolveExportFailure(ZohoBulkReadJob $page): void
    {
        ZohoSyncFailure::query()
            ->where('failure_key', $this->exportFailureKey($page))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'retry_after' => null]);
    }

    private function resolveRecordFailure(string $module, string $submodule, string $zohoId): void
    {
        ZohoSyncFailure::query()
            ->where('failure_key', $this->recordFailureKey($module, $submodule, $zohoId))
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'retry_after' => null]);
    }

    private function exportFailureKey(ZohoBulkReadJob $page): string
    {
        return hash('sha256', implode('|', [
            'bulk_export',
            $page->sync_batch_id,
            $page->module,
            $page->submodule,
            $page->page_key,
        ]));
    }

    private function recordFailureKey(string $module, string $submodule, string $zohoId): string
    {
        return hash('sha256', implode('|', [$module, $submodule, $zohoId, 'record']));
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

    /** @return array{staged:int,reported:int,parsed:int,api_requests:int,export_failed:int,token_restarts:int} */
    private function pageCounters(ZohoBulkReadJob $page): array
    {
        $stored = is_array($page->counters) ? $page->counters : [];

        return [
            'staged' => max(0, (int) ($stored['staged'] ?? 0)),
            'reported' => max(0, (int) ($stored['reported'] ?? 0)),
            'parsed' => max(0, (int) ($stored['parsed'] ?? 0)),
            'api_requests' => max(0, (int) ($stored['api_requests'] ?? 0)),
            'export_failed' => max(0, (int) ($stored['export_failed'] ?? 0)),
            'token_restarts' => max(0, (int) ($stored['token_restarts'] ?? 0)),
        ];
    }

    private function submodule(ModuleDefinition $definition): string
    {
        return $definition->submodule ?? '';
    }

    private function maxRecordsPerExport(): int
    {
        return max(1, min(200_000, (int) config('zoho-v2.bulk.max_records_per_export', 200_000)));
    }

    private function safeDeliveryOwner(?string $deliveryOwner): string
    {
        return is_string($deliveryOwner)
            && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $deliveryOwner) === 1
                ? $deliveryOwner
                : (string) Str::uuid();
    }

    private function safeRunOwner(?string $runOwner): ?string
    {
        return is_string($runOwner)
            && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $runOwner) === 1
                ? $runOwner
                : null;
    }

    private function checkpointIsNewer(
        ZohoSyncCheckpoint $checkpoint,
        ZohoSyncBatch $batch,
        mixed $watermark,
    ): bool {
        if ($checkpoint->correlation_id === null
            || hash_equals((string) $checkpoint->correlation_id, (string) $batch->correlation_id)
            || $checkpoint->cursor_at === null
            || $watermark === null) {
            return false;
        }

        return $checkpoint->cursor_at->greaterThanOrEqualTo($watermark);
    }

    private function secondsUntil(mixed $date): int
    {
        return max(1, now()->diffInSeconds($date, false));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('zoho-v2.retry.max_attempts', 5));
    }

    private function leaseSeconds(): int
    {
        return max(60, (int) config('zoho-v2.bulk.lease_seconds', 1_200));
    }

    private function workBatchSize(): int
    {
        return max(1, min(1_000, (int) config('zoho-v2.bulk.work_batch_size', 100)));
    }

    private function stagingBatchSize(): int
    {
        return max(1, min(5_000, (int) config('zoho-v2.bulk.staging_batch_size', 1_000)));
    }

    private function pollDelaySeconds(): int
    {
        return max(1, (int) config('zoho-v2.bulk.poll_delay_seconds', 30));
    }

    private function lockRetrySeconds(): int
    {
        return max(1, (int) config('zoho-v2.bulk.lock_retry_seconds', 30));
    }

    private function failureRetrySeconds(): int
    {
        return max(1, (int) config('zoho-v2.bulk.failure_retry_seconds', 300));
    }

    private function capacityDeferralSeconds(): int
    {
        return max(1, min(3_600, (int) config('zoho-v2.bulk.capacity_deferral_seconds', 60)));
    }
}
