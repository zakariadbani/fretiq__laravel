<?php

namespace App\Services\Discovery;

final readonly class ContactImportResult
{
    /** @param array<string, int> $skipped */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $linked = 0,
        public array $skipped = [],
    ) {}

    /** Contact rows created or enriched; provenance links are reported separately. */
    public function imported(): int
    {
        return $this->created + $this->updated;
    }
}
