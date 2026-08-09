<?php

namespace App\Services\Zoho\V2\DTO;

final readonly class SyncRunContext
{
    public function __construct(
        public string $runId,
        public string $correlationId,
        public bool $dryRun = false,
    ) {}
}
