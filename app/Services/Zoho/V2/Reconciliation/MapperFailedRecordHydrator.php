<?php

namespace App\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoSyncBatch;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoRecordIngestor;

final class MapperFailedRecordHydrator implements FailedRecordHydrator
{
    public function __construct(
        private ZohoTransport $transport,
        private ZohoModuleRegistry $registry,
        private ZohoRecordIngestor $ingestor,
    ) {}

    public function fetch(
        string $moduleKey,
        string $zohoId,
        int $batchId,
        string $correlationId,
    ): FailedRecordHydrationResult {
        // Quote items have no safe standalone identity/context. Their parent quote owns retries.
        if ($moduleKey === 'quoted_items') {
            return FailedRecordHydrationResult::failed();
        }

        $d = $this->registry->get($moduleKey);
        $response = $this->transport->get(
            '/'.$d->apiName.'/'.rawurlencode($zohoId),
            $d->recordQuery,
            $correlationId,
        );
        $record = data_get($response->root('data'), '0');
        if (! $response->successful() || ! is_array($record)) {
            return FailedRecordHydrationResult::failed($response->apiRequestCount());
        }
        $returnedId = $record['id'] ?? null;
        if (! is_scalar($returnedId)) {
            return FailedRecordHydrationResult::failed($response->apiRequestCount());
        }
        $returnedId = (string) $returnedId;
        // Zoho IDs are bounded strings in every mirrored table. Do not let a
        // malformed response turn a retry for one failure into a write for a
        // different CRM record.
        if (trim($returnedId) === '' || strlen($returnedId) > 100 || ! hash_equals($zohoId, $returnedId)) {
            return FailedRecordHydrationResult::failed($response->apiRequestCount());
        }

        return FailedRecordHydrationResult::fetched($record, $response->apiRequestCount());
    }

    public function persist(
        string $moduleKey,
        int $batchId,
        FailedRecordHydrationResult $fetched,
    ): FailedRecordHydrationResult {
        if (! $fetched->successful || ! is_array($fetched->record)) {
            return FailedRecordHydrationResult::failed($fetched->apiRequests);
        }

        $definition = $this->registry->get($moduleKey);
        // Retry may outlive a manually-pruned batch; mirror rows retain the
        // historic numeric reference and do not require a foreign key.
        $batch = ZohoSyncBatch::find($batchId)
            ?? tap(new ZohoSyncBatch, fn (ZohoSyncBatch $model) => $model->id = $batchId);
        $counts = $this->ingestor->ingest($definition, $fetched->record, $batch);

        return FailedRecordHydrationResult::persisted($counts, $fetched->apiRequests);
    }
}
