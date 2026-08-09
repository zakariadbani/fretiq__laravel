<?php

namespace App\Services\Zoho\V2\DTO;

final readonly class SyncResult
{
    /**
     * @param  array<string, int>  $counters
     * @param  list<string>  $warnings
     * @param  list<string>  $failures
     * @param  array<string, mixed>|null  $reconciliation
     */
    public function __construct(
        public array $counters = [],
        public ?string $cursor = null,
        public ?string $cursorId = null,
        public array $warnings = [],
        public array $failures = [],
        public bool $leaseConflict = false,
        public ?int $retryAfterSeconds = null,
        public ?array $reconciliation = null,
        public bool $continuationRequired = false,
        public bool $ignoredDelivery = false,
        public bool $retryableFailure = false,
    ) {}
}
