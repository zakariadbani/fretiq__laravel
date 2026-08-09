<?php

declare(strict_types=1);

namespace App\Jobs\Zoho;

use App\Models\ZohoSyncLog;
use Illuminate\Support\Facades\DB;

/** Bridges sanitized sync/reconciliation results into durable operations health. */
final class ZohoModuleRunOutcome
{
    public static function syncStatus(int $batchId, string $module): string
    {
        $status = ZohoSyncLog::query()
            ->where('sync_batch_id', $batchId)
            ->where('module', $module)
            ->latest('id')
            ->value('status');

        return in_array($status, ['success', 'partial', 'error'], true) ? $status : 'error';
    }

    /**
     * @param  array<string,mixed>  $aggregate  Sanitized reconciliation counters/statuses only.
     * @return 'success'|'partial'|'error'
     */
    public static function recordReconciliation(int $batchId, string $module, array $aggregate): string
    {
        return DB::transaction(function () use ($batchId, $module, $aggregate): string {
            $log = ZohoSyncLog::query()
                ->where('sync_batch_id', $batchId)
                ->where('module', $module)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($log === null) {
                return 'error';
            }

            $reconciliation = self::normalizedReconciliationTelemetry($aggregate);
            $semanticStatus = $reconciliation['status'];
            $semanticStatusValid = $reconciliation['status_valid'];
            $complete = $reconciliation['complete'];
            $httpStatus = $reconciliation['http_status'];
            $deletedStatus = $reconciliation['deleted_status'];
            $remoteCount = $reconciliation['remote_count'];
            $localCount = $reconciliation['local_count'];
            $missingCount = $reconciliation['missing_count'];
            $extraCount = $reconciliation['extra_count'];
            $repairedCount = $reconciliation['repaired_count'];
            $sweptCount = $reconciliation['swept_count'];
            $pages = $reconciliation['pages'];
            $apiRequests = $reconciliation['api_requests'];
            $tombstoned = $reconciliation['tombstoned'];
            $hydration = $reconciliation['hydration'];
            $transportFailed = ! $complete
                || ! $semanticStatusValid
                || ! self::successfulStatus($httpStatus)
                || ! self::successfulStatus($deletedStatus);

            $status = match (true) {
                $log->status === 'error', $transportFailed => 'error',
                $log->status === 'partial', $semanticStatus === 'degraded' => 'partial',
                default => 'success',
            };

            $telemetry = is_array($log->telemetry) ? $log->telemetry : [];
            $previousReconciliationRequests = max(0, (int) data_get($telemetry, 'reconciliation.api_requests', 0));
            unset($reconciliation['status_valid']);
            $telemetry['reconciliation'] = $reconciliation;

            $error = $log->error;
            if ($transportFailed) {
                $error = 'Reconciliation transport failed; see correlation ID.';
            } elseif ($semanticStatus === 'degraded' && $error === null) {
                $error = 'Remote/local reconciliation discrepancy; see correlation ID.';
            }

            $log->update([
                'status' => $status,
                'error' => $error,
                'records_deleted' => max((int) $log->records_deleted, $tombstoned),
                'api_requests' => ((int) $log->api_requests) + max(0, $apiRequests - $previousReconciliationRequests),
                'telemetry' => $telemetry,
            ]);

            return $status;
        });
    }

    private static function successfulStatus(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /** @return array<string, mixed> Sanitized aggregate fields only. */
    public static function normalizedReconciliationTelemetry(array $aggregate): array
    {
        $reportedStatus = is_string($aggregate['status'] ?? null) ? $aggregate['status'] : null;
        $status = in_array($reportedStatus, ['healthy', 'degraded'], true) ? $reportedStatus : 'degraded';
        $reportedHydration = is_array($aggregate['hydration'] ?? null) ? $aggregate['hydration'] : [];
        $hydration = [];
        foreach (['attempted', 'failed', 'created', 'updated', 'unchanged', 'quarantined', 'api_requests'] as $counter) {
            $hydration[$counter] = max(0, (int) ($reportedHydration[$counter] ?? 0));
        }

        return [
            'status' => $status,
            'status_valid' => $reportedStatus === $status,
            'complete' => ($aggregate['complete'] ?? false) === true,
            'http_status' => max(0, (int) ($aggregate['http_status'] ?? 0)),
            'remote_count' => is_numeric($aggregate['remote_count'] ?? null) ? max(0, (int) $aggregate['remote_count']) : null,
            'local_count' => is_numeric($aggregate['local_count'] ?? null) ? max(0, (int) $aggregate['local_count']) : null,
            'missing_count' => is_numeric($aggregate['missing_count'] ?? null) ? max(0, (int) $aggregate['missing_count']) : null,
            'extra_count' => is_numeric($aggregate['extra_count'] ?? null) ? max(0, (int) $aggregate['extra_count']) : null,
            'repaired_count' => max(0, (int) ($aggregate['repaired_count'] ?? 0)),
            'swept_count' => max(0, (int) ($aggregate['swept_count'] ?? 0)),
            'pages' => max(0, (int) ($aggregate['pages'] ?? 0)),
            'api_requests' => max(0, (int) ($aggregate['api_requests'] ?? 0)),
            'hydration' => $hydration,
            'deleted_status' => max(0, (int) data_get($aggregate, 'deleted.status', 0)),
            'tombstoned' => max(0, (int) data_get($aggregate, 'deleted.tombstoned', 0)),
        ];
    }
}
