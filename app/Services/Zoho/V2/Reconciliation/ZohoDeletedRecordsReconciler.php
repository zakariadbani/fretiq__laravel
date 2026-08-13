<?php

namespace App\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoSyncFailure;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ZohoDeletedRecordsReconciler
{
    public function __construct(private ZohoTransport $transport, private ZohoModuleRegistry $registry) {}

    /**
     * @param  (callable(): bool)|null  $heartbeat
     * @return array{seen:int,tombstoned:int,quarantined:int,api_requests:int,pages:int,status:int,complete:bool,degraded:bool}
     */
    public function reconcile(
        string $moduleKey,
        int $batchId,
        string $correlationId,
        ?callable $heartbeat = null,
        ?callable $mutationFence = null,
    ): array {
        $definition = $this->registry->get($moduleKey);
        $page = 1;
        $seen = $tombstoned = $quarantined = $apiRequests = 0;
        $status = 204;
        do {
            if ($heartbeat !== null && $heartbeat() !== true) {
                return ['seen' => $seen, 'tombstoned' => $tombstoned, 'quarantined' => $quarantined, 'api_requests' => $apiRequests, 'pages' => $page - 1, 'status' => 0, 'complete' => false, 'degraded' => true];
            }
            $response = $this->transport->get('/'.$definition->apiName.'/deleted', ['type' => 'all', 'page' => $page, 'per_page' => 200], $correlationId);
            $apiRequests += $response->apiRequestCount();
            $status = $response->status;
            if (! $response->successful()) {
                $this->recordScanFailure($moduleKey, $batchId, $correlationId, $status, $page, $mutationFence);

                return ['seen' => $seen, 'tombstoned' => $tombstoned, 'quarantined' => $quarantined, 'api_requests' => $apiRequests, 'pages' => $page - 1, 'status' => $status, 'complete' => false, 'degraded' => true];
            }
            $records = $this->parseDeletedPage($response);
            if ($records === null) {
                $this->recordScanFailure($moduleKey, $batchId, $correlationId, $status, $page, $mutationFence);

                return ['seen' => $seen, 'tombstoned' => $tombstoned, 'quarantined' => $quarantined, 'api_requests' => $apiRequests, 'pages' => $page - 1, 'status' => $status, 'complete' => false, 'degraded' => true];
            }
            $seen += count($records);
            foreach ($records as $index => $record) {
                try {
                    [$id, $when, $deletionType] = $this->normalizeDeletionRecord($record);
                    $tombstoned += $this->tombstone(
                        $definition,
                        $id,
                        $when,
                        $deletionType,
                        $batchId,
                        $mutationFence,
                    );
                    if (! $this->resolveRecordFailure($moduleKey, $id, $mutationFence)) {
                        return $this->leaseLostResult($seen, $tombstoned, $quarantined, $apiRequests, $page, $status);
                    }
                } catch (ZohoMirrorMutationLeaseLostException) {
                    return $this->leaseLostResult($seen, $tombstoned, $quarantined, $apiRequests, $page, $status);
                } catch (InvalidArgumentException) {
                    if (! $this->recordRecordFailure($moduleKey, $batchId, $correlationId, $record, $page, $index, $mutationFence)) {
                        return $this->leaseLostResult($seen, $tombstoned, $quarantined, $apiRequests, $page, $status);
                    }
                    $quarantined++;
                }
            }
            // Numeric Deleted Records continuation was live-verified on 2026-08-09.
            $more = $response->status === 204
                ? false
                : ($response->info['more_records'] ?? data_get($response->root('info'), 'more_records'));
            if (! is_bool($more)) {
                $this->recordScanFailure($moduleKey, $batchId, $correlationId, $status, $page, $mutationFence);

                return ['seen' => $seen, 'tombstoned' => $tombstoned, 'quarantined' => $quarantined, 'api_requests' => $apiRequests, 'pages' => $page - 1, 'status' => $status, 'complete' => false, 'degraded' => true];
            }
            $page++;
        } while ($more);
        if (! $this->resolveScanFailure($moduleKey, $mutationFence)) {
            return $this->leaseLostResult($seen, $tombstoned, $quarantined, $apiRequests, $page, $status);
        }

        return ['seen' => $seen, 'tombstoned' => $tombstoned, 'quarantined' => $quarantined, 'api_requests' => $apiRequests, 'pages' => $page - 1, 'status' => $status, 'complete' => true, 'degraded' => $quarantined > 0];
    }

    private function tombstone(
        object $definition,
        string $id,
        CarbonImmutable $when,
        string $deletionType,
        int $batchId,
        ?callable $mutationFence,
    ): int {
        return DB::transaction(function () use (
            $definition,
            $id,
            $when,
            $deletionType,
            $batchId,
            $mutationFence,
        ): int {
            if ($mutationFence !== null && $mutationFence() !== true) {
                throw new ZohoMirrorMutationLeaseLostException(
                    'The deleted-record mirror mutation fence was reclaimed.',
                );
            }

            $query = $definition->modelClass::query();
            if ($definition->activityType) {
                $query->where('activity_type', $definition->activityType);
            }
            if ($definition->key === 'quoted_items') {
                $query->where('zoho_line_item_id', $id);
            } else {
                $query->where('zoho_id', $id);
            }
            $record = $query->lockForUpdate()->first();
            if ($record === null) {
                return 0;
            }
            $wasCurrent = $record->zoho_deleted_at === null;
            $updates = ['sync_batch_id' => $batchId, 'last_synced_at' => now()];
            if ($wasCurrent || $this->deletionRank($deletionType) >= $this->deletionRank((string) $record->zoho_deletion_type)) {
                $updates['zoho_deleted_at'] = $when;
                $updates['zoho_deletion_type'] = $deletionType;
            }
            $record->update($updates);

            if ($definition->key === 'quotes') {
                \App\Models\Zoho\ZohoQuoteItem::query()
                    ->where('zoho_quote_id', $id)
                    ->whereNull('zoho_deleted_at')
                    ->update([
                        'zoho_deleted_at' => $when,
                        'zoho_deletion_type' => 'parent_quote_deleted',
                        'sync_batch_id' => $batchId,
                        'last_synced_at' => now(),
                    ]);
            }

            return $wasCurrent ? 1 : 0;
        });
    }

    /** @return array{0:string,1:CarbonImmutable,2:string} */
    private function normalizeDeletionRecord(mixed $record): array
    {
        if (! is_array($record) || ! isset($record['id']) || ! is_scalar($record['id']) || (string) $record['id'] === '') {
            throw new InvalidArgumentException('Deleted-record identity is invalid.');
        }
        $id = (string) $record['id'];
        $rawTime = $record['deleted_time'] ?? $record['Deleted_Time'] ?? null;
        try {
            $when = $rawTime === null ? CarbonImmutable::now() : CarbonImmutable::parse((string) $rawTime)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException('Deleted-record time is invalid.');
        }
        $rawType = $record['type'] ?? $record['deleted_type'] ?? 'deleted';
        if (! is_string($rawType) || preg_match('/^[a-z][a-z0-9_]{0,31}$/i', $rawType) !== 1) {
            throw new InvalidArgumentException('Deleted-record type is invalid.');
        }

        return [$id, $when, strtolower($rawType)];
    }

    private function deletionRank(string $type): int
    {
        return match (strtolower($type)) {
            'permanent' => 30,
            'recycle' => 20,
            'deleted' => 10,
            default => 5,
        };
    }

    private function recordScanFailure(string $module, int $batchId, string $correlationId, int $status, int $page, ?callable $mutationFence): bool
    {
        return $this->mutateFailure($mutationFence, static function () use ($module, $batchId, $correlationId, $status, $page): void {
            ZohoSyncFailure::updateOrCreate(
                ['failure_key' => hash('sha256', 'deletion_scan|'.$module)],
                ['sync_batch_id' => $batchId, 'module' => $module, 'failure_kind' => 'deletion_scan', 'correlation_id' => $correlationId, 'error_summary' => 'Deleted-record scan did not complete.', 'context' => ['status' => $status, 'failed_page' => $page], 'resolved_at' => null],
            );
        });
    }

    private function recordRecordFailure(string $module, int $batchId, string $correlationId, mixed $record, int $page, int $index, ?callable $mutationFence): bool
    {
        $id = is_array($record) && isset($record['id']) && is_scalar($record['id']) && (string) $record['id'] !== '' ? (string) $record['id'] : null;
        $identity = $id ?? 'page:'.$page.':row:'.$index;
        $key = hash('sha256', 'deletion_record|'.$module.'|'.$identity);

        return $this->mutateFailure($mutationFence, static function () use ($module, $batchId, $correlationId, $id, $page, $index, $key): void {
            $failure = ZohoSyncFailure::query()->where('failure_key', $key)->lockForUpdate()->first();
            $values = [
                'sync_batch_id' => $batchId, 'module' => $module, 'zoho_id' => $id,
                'failure_kind' => 'deletion_record', 'correlation_id' => $correlationId,
                'error_summary' => 'Deleted-record metadata was invalid.',
                'context' => ['page' => $page, 'row' => $index], 'resolved_at' => null,
            ];
            if ($failure) {
                $failure->update($values + ['attempts' => ((int) $failure->attempts) + 1]);
            } else {
                ZohoSyncFailure::query()->create($values + ['failure_key' => $key, 'attempts' => 1]);
            }
        });
    }

    private function resolveRecordFailure(string $module, string $id, ?callable $mutationFence): bool
    {
        return $this->mutateFailure($mutationFence, static function () use ($module, $id): void {
            ZohoSyncFailure::query()
                ->where('failure_key', hash('sha256', 'deletion_record|'.$module.'|'.$id))
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'retry_after' => null]);
        });
    }

    /** @return list<array<string,mixed>>|null */
    private function parseDeletedPage(mixed $response): ?array
    {
        if ($response->status === 204) {
            return [];
        }
        $data = $response->root('data');
        $info = $response->root('info');
        if ($response->status !== 200 || ! is_array($data) || ! array_is_list($data) || ($info !== null && ! is_array($info))) {
            return null;
        }

        return $data;
    }

    private function resolveScanFailure(string $module, ?callable $mutationFence): bool
    {
        return $this->mutateFailure($mutationFence, static function () use ($module): void {
            ZohoSyncFailure::where('failure_key', hash('sha256', 'deletion_scan|'.$module))->whereNull('resolved_at')->update(['resolved_at' => now(), 'retry_after' => null]);
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

    /** @return array{seen:int,tombstoned:int,quarantined:int,api_requests:int,pages:int,status:int,complete:bool,degraded:bool,lease_lost:bool} */
    private function leaseLostResult(int $seen, int $tombstoned, int $quarantined, int $apiRequests, int $page, int $status): array
    {
        return ['seen' => $seen, 'tombstoned' => $tombstoned, 'quarantined' => $quarantined, 'api_requests' => $apiRequests, 'pages' => max(0, $page - 1), 'status' => $status, 'complete' => false, 'degraded' => true, 'lease_lost' => true];
    }
}
