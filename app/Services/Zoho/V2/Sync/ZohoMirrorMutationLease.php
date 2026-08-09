<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Sync;

final readonly class ZohoMirrorMutationLease
{
    public function __construct(
        public int $checkpointId,
        public int $generation,
        public string $owner,
    ) {}
}
