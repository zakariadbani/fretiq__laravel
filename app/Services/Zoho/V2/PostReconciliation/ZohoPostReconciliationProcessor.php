<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\PostReconciliation;

interface ZohoPostReconciliationProcessor
{
    /** @return array<string, int> Sanitized counters only. */
    public function process(int $batchId): array;
}
