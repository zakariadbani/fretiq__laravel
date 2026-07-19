<?php

namespace App\Services\Discovery\Engines;

final class DiscoveryPage
{
    /** @param list<array<string, mixed>> $candidates */
    public function __construct(
        public readonly array $candidates,
        public readonly bool $exhausted,
        public readonly ?int $nextStart,
        /** @var array<string, string> */
        public readonly array $nextParams = [],
    ) {}
}
