<?php

namespace App\Services\Zoho\V2\Bulk;

final readonly class BulkTransportResponse
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public array $payload,
        public int $status,
        public int $attempts,
    ) {}
}
