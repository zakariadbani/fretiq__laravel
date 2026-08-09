<?php

namespace App\Services\Zoho\V2\Reconciliation;

interface FailedRecordHydrator
{
    public function fetch(
        string $moduleKey,
        string $zohoId,
        int $batchId,
        string $correlationId,
    ): FailedRecordHydrationResult;

    /** Persist a previously fetched result. This method performs no HTTP. */
    public function persist(
        string $moduleKey,
        int $batchId,
        FailedRecordHydrationResult $fetched,
    ): FailedRecordHydrationResult;
}
