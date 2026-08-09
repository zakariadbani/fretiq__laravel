<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoSyncFailure;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\Registry\ModuleDefinition;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Support\Facades\DB;

final class ZohoReconciliationService
{
    public function __construct(
        private readonly ZohoDeletedRecordsReconciler $deleted,
        private readonly ZohoTransport $transport,
        private readonly ZohoModuleRegistry $registry,
        private readonly ZohoDataQualityMetrics $metrics,
    ) {}

    /**
     * @param  (callable(string): array{successful:bool,created:int,updated:int,unchanged:int,quarantined:int,api_requests:int})|null  $hydrateRecord
     * @param  (callable(): bool)|null  $heartbeat
     * @param  (callable(string,array<string,mixed>): bool)|null  $recordProgress
     * @param  (callable(): bool)|null  $mutationFence
     * @return array<string, mixed>
     */
    public function reconcile(
        string $moduleKey,
        int $batchId,
        string $correlationId,
        ?callable $hydrateRecord = null,
        ?callable $heartbeat = null,
        ?string $resumeAfterZohoId = null,
        ?int $maxHydrations = null,
        ?callable $recordProgress = null,
        ?array $previousDeleted = null,
        ?callable $mutationFence = null,
    ): array {
        $definition = $this->registry->get($moduleKey);
        $deleted = $previousDeleted ?? $this->deleted->reconcile(
            $moduleKey,
            $batchId,
            $correlationId,
            $heartbeat,
            $mutationFence,
        );
        $apiRequests = (int) ($deleted['api_requests'] ?? 0);

        if (! $deleted['complete']) {
            return $this->incompleteResult(
                $moduleKey,
                $deleted,
                (int) $deleted['status'],
                0,
                $apiRequests,
            );
        }

        $remote = [];
        $token = null;
        $status = 204;
        $pages = 0;

        do {
            if (! $this->heartbeat($heartbeat)) {
                return $this->incompleteResult($moduleKey, $deleted, 0, $pages, $apiRequests);
            }

            $query = array_replace(['fields' => 'id', 'per_page' => 200], $definition->listQuery);
            if ($token !== null) {
                $query['page_token'] = $token;
            }

            $response = $this->transport->get('/'.$definition->apiName, $query, $correlationId);
            $status = $response->status;
            $apiRequests += $response->apiRequestCount();

            if (! $response->successful()) {
                $this->recordScanFailure($moduleKey, $batchId, $correlationId, $status, $pages + 1, $mutationFence);

                return $this->incompleteResult($moduleKey, $deleted, $status, $pages, $apiRequests);
            }

            $parsed = $this->parseActivePage($response);
            if ($parsed === null) {
                $this->recordScanFailure($moduleKey, $batchId, $correlationId, $status, $pages + 1, $mutationFence);

                return $this->incompleteResult($moduleKey, $deleted, $status, $pages, $apiRequests);
            }

            [$ids, $token] = $parsed;
            foreach ($ids as $id) {
                $remote[$id] = true;
            }
            $pages++;
        } while ($token !== null);

        if (! $this->resolveFailure($moduleKey, 'reconciliation_scan', $mutationFence)) {
            return $this->incompleteResult($moduleKey, $deleted, $status, $pages, $apiRequests);
        }

        $remoteIds = array_keys($remote);
        sort($remoteIds, SORT_STRING);
        $localIds = $this->currentIds($definition);
        $initialMissing = array_values(array_diff($remoteIds, $localIds));
        // Quotes in this organization omit Modified_Time. The live-verified
        // safe fallback is therefore a complete nightly specific-record sweep.
        $hydrationTargets = $moduleKey === 'quotes' ? $remoteIds : $initialMissing;
        if ($moduleKey === 'quotes' && $resumeAfterZohoId !== null) {
            $hydrationTargets = array_values(array_filter(
                $hydrationTargets,
                static fn (string $id): bool => strcmp($id, $resumeAfterZohoId) > 0,
            ));
        }
        $remainingHydrations = count($hydrationTargets);
        if ($moduleKey === 'quotes' && $maxHydrations !== null) {
            $hydrationTargets = array_slice($hydrationTargets, 0, max(1, $maxHydrations));
        }
        $continuationRequired = count($hydrationTargets) < $remainingHydrations;
        $nextCursorZohoId = $continuationRequired && $hydrationTargets !== []
            ? $hydrationTargets[array_key_last($hydrationTargets)]
            : null;
        $hydration = $this->emptyHydrationCounters();

        if ($hydrateRecord !== null) {
            foreach ($hydrationTargets as $id) {
                if (! $this->heartbeat($heartbeat)) {
                    return $this->incompleteResult(
                        $moduleKey,
                        $deleted,
                        0,
                        $pages,
                        $apiRequests + $hydration['api_requests'],
                        $hydration,
                    );
                }

                $result = $hydrateRecord($id);
                foreach (array_keys($hydration) as $counter) {
                    if ($counter === 'attempted') {
                        continue;
                    }
                    $hydration[$counter] += max(0, (int) ($result[$counter] ?? 0));
                }
                $hydration['attempted']++;
                if (($result['successful'] ?? false) !== true) {
                    $hydration['failed']++;
                }
                if ($recordProgress !== null && $recordProgress($id, $result) !== true) {
                    return $this->incompleteResult(
                        $moduleKey,
                        $deleted,
                        0,
                        $pages,
                        $apiRequests + $hydration['api_requests'],
                        $hydration,
                    );
                }
            }
        }

        $apiRequests += $hydration['api_requests'];
        $this->refreshLastSeen($definition, $remoteIds, $mutationFence);

        $localIds = $this->currentIds($definition);
        $missing = array_values(array_diff($remoteIds, $localIds));
        $extra = array_values(array_diff($localIds, $remoteIds));
        $repaired = max(0, count($initialMissing) - count($missing));
        if ($continuationRequired) {
            return [
                'deleted' => $deleted,
                'remote_count' => count($remoteIds),
                'local_count' => count($localIds),
                'missing_count' => count($missing),
                'extra_count' => count($extra),
                'repaired_count' => $repaired,
                'swept_count' => count($hydrationTargets),
                'hydration' => $hydration,
                'status' => 'running',
                'complete' => true,
                'degraded' => ($deleted['degraded'] ?? false)
                    || $hydration['failed'] > 0
                    || $hydration['quarantined'] > 0,
                'continuation_required' => true,
                'next_cursor_zoho_id' => $nextCursorZohoId,
                'pages' => $pages,
                'http_status' => $status,
                'api_requests' => $apiRequests,
                'quality' => $this->metrics->forModule($moduleKey),
            ];
        }
        $degraded = ($deleted['degraded'] ?? false)
            || $missing !== []
            || $extra !== []
            || $hydration['failed'] > 0
            || $hydration['quarantined'] > 0;

        if ($degraded) {
            if (! $this->mutateFailure($mutationFence, static function () use ($moduleKey, $batchId, $correlationId, $remoteIds, $localIds, $missing, $extra, $hydration): void {
                ZohoSyncFailure::updateOrCreate(['failure_key' => hash('sha256', 'reconciliation|'.$moduleKey)], [
                    'sync_batch_id' => $batchId,
                    'module' => $moduleKey,
                    'failure_kind' => 'reconciliation',
                    'correlation_id' => $correlationId,
                    'error_summary' => 'Remote/local active identifier discrepancy.',
                    'context' => [
                        'remote_count' => count($remoteIds),
                        'local_count' => count($localIds),
                        'missing_count' => count($missing),
                        'extra_count' => count($extra),
                        'hydration_failed_count' => $hydration['failed'],
                        'hydration_quarantined_count' => $hydration['quarantined'],
                    ],
                    'resolved_at' => null,
                ]);
            })) {
                return $this->incompleteResult($moduleKey, $deleted, $status, $pages, $apiRequests, $hydration);
            }
        } else {
            if (! $this->resolveFailure($moduleKey, 'reconciliation', $mutationFence)) {
                return $this->incompleteResult($moduleKey, $deleted, $status, $pages, $apiRequests, $hydration);
            }
        }

        return [
            'deleted' => $deleted,
            'remote_count' => count($remoteIds),
            'local_count' => count($localIds),
            'missing_count' => count($missing),
            'extra_count' => count($extra),
            'repaired_count' => $repaired,
            'swept_count' => $moduleKey === 'quotes' ? count($hydrationTargets) : 0,
            'hydration' => $hydration,
            'status' => $degraded ? 'degraded' : 'healthy',
            'complete' => true,
            'degraded' => $degraded,
            'continuation_required' => false,
            'next_cursor_zoho_id' => null,
            'pages' => $pages,
            'http_status' => $status,
            'api_requests' => $apiRequests,
            'quality' => $this->metrics->forModule($moduleKey),
        ];
    }

    /**
     * @param  array<string, mixed>  $deleted
     * @param  array<string, int>|null  $hydration
     * @return array<string, mixed>
     */
    private function incompleteResult(
        string $moduleKey,
        array $deleted,
        int $httpStatus,
        int $pages,
        int $apiRequests,
        ?array $hydration = null,
    ): array {
        return [
            'deleted' => $deleted,
            'remote_count' => null,
            'local_count' => null,
            'missing_count' => null,
            'extra_count' => null,
            'repaired_count' => 0,
            'swept_count' => 0,
            'hydration' => $hydration ?? $this->emptyHydrationCounters(),
            'status' => 'degraded',
            'complete' => false,
            'degraded' => true,
            'continuation_required' => false,
            'next_cursor_zoho_id' => null,
            'pages' => $pages,
            'http_status' => $httpStatus,
            'api_requests' => $apiRequests,
            'quality' => $this->metrics->forModule($moduleKey),
        ];
    }

    /** @return list<string> */
    private function currentIds(ModuleDefinition $definition): array
    {
        $query = $definition->modelClass::current();
        if ($definition->activityType !== null) {
            $query->where('activity_type', $definition->activityType);
        }

        return $query
            ->pluck($definition->key === 'quoted_items' ? 'zoho_line_item_id' : 'zoho_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    /** @param list<string> $remoteIds */
    private function refreshLastSeen(
        ModuleDefinition $definition,
        array $remoteIds,
        ?callable $mutationFence,
    ): void {
        $identityColumn = $definition->key === 'quoted_items' ? 'zoho_line_item_id' : 'zoho_id';
        foreach (array_chunk($remoteIds, 500) as $ids) {
            DB::transaction(function () use (
                $definition,
                $identityColumn,
                $ids,
                $mutationFence,
            ): void {
                if ($mutationFence !== null && $mutationFence() !== true) {
                    throw new ZohoMirrorMutationLeaseLostException(
                        'The reconciliation mirror mutation fence was reclaimed.',
                    );
                }

                $query = $definition->modelClass::current()->whereIn($identityColumn, $ids);
                if ($definition->activityType !== null) {
                    $query->where('activity_type', $definition->activityType);
                }
                $query->update(['last_seen_at' => now()]);
            });
        }
    }

    private function recordScanFailure(
        string $module,
        int $batchId,
        string $correlationId,
        int $status,
        int $page,
        ?callable $mutationFence,
    ): bool {
        return $this->mutateFailure($mutationFence, static function () use ($module, $batchId, $correlationId, $status, $page): void {
            ZohoSyncFailure::updateOrCreate(
                ['failure_key' => hash('sha256', 'reconciliation_scan|'.$module)],
                [
                    'sync_batch_id' => $batchId,
                    'module' => $module,
                    'failure_kind' => 'reconciliation_scan',
                    'correlation_id' => $correlationId,
                    'error_summary' => 'Active-record scan did not complete.',
                    'context' => ['status' => $status, 'failed_page' => $page],
                    'resolved_at' => null,
                ],
            );
        });
    }

    /** @return array{0:list<string>,1:?string}|null */
    private function parseActivePage(mixed $response): ?array
    {
        if ($response->status === 204) {
            return [[], null];
        }

        $data = $response->root('data');
        $rootInfo = $response->root('info');
        if ($response->status !== 200
            || ! is_array($data)
            || ! array_is_list($data)
            || ($rootInfo !== null && ! is_array($rootInfo))) {
            return null;
        }

        $ids = [];
        foreach ($data as $row) {
            if (! is_array($row)
                || ! isset($row['id'])
                || ! is_scalar($row['id'])
                || (string) $row['id'] === '') {
                return null;
            }
            $ids[] = (string) $row['id'];
        }

        $info = $response->info ?: ($rootInfo ?? []);
        $more = $info['more_records'] ?? null;
        $token = $info['next_page_token'] ?? null;
        if (! is_bool($more)
            || ($token !== null && (! is_string($token) || $token === '' || strlen($token) > 1024))) {
            return null;
        }
        if (($more === true && $token === null) || ($more === false && $token !== null)) {
            return null;
        }

        return [$ids, $token];
    }

    /** @return array{attempted:int,failed:int,created:int,updated:int,unchanged:int,quarantined:int,api_requests:int} */
    private function emptyHydrationCounters(): array
    {
        return [
            'attempted' => 0,
            'failed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'quarantined' => 0,
            'api_requests' => 0,
        ];
    }

    /** @param (callable(): bool)|null $heartbeat */
    private function heartbeat(?callable $heartbeat): bool
    {
        return $heartbeat === null || $heartbeat() === true;
    }

    private function resolveFailure(string $module, string $kind, ?callable $mutationFence = null): bool
    {
        return $this->mutateFailure($mutationFence, static function () use ($module, $kind): void {
            ZohoSyncFailure::where('failure_key', hash('sha256', $kind.'|'.$module))
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'retry_after' => null]);
        });
    }

    private function mutateFailure(?callable $mutationFence, callable $mutation): bool
    {
        return DB::transaction(static function () use ($mutationFence, $mutation): bool {
            if ($mutationFence !== null && $mutationFence() !== true) {
                return false;
            }
            $mutation();

            return true;
        });
    }
}
