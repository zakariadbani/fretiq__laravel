<?php

namespace App\Services\Discovery;

final readonly class CanonicalDomain
{
    public function __construct(
        public string $host,
        public string $registrableDomain,
        public bool $isPlatform,
    ) {}
}
