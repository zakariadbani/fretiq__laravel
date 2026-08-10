<?php

namespace App\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoSyncBatch;

final readonly class ManualSyncDecision
{
    /** @param list<string> $modulesToDispatch */
    public function __construct(
        public string $outcome,
        public ZohoSyncBatch $batch,
        public array $modulesToDispatch,
        public bool $needsFinalization = false,
    ) {}
}
