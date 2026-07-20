<?php

namespace App\Services\Discovery;

final readonly class DiscoveryCollectionResult
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function __construct(
        public array $candidates,
        public bool $terminal,
    ) {}
}
