<?php

namespace App\Data\Prospecting;

use App\Models\ProspectBatch;

final readonly class RecoveryResult
{
    /** @param array<string, int> $counts */
    public function __construct(
        public ProspectBatch $batch,
        public array $counts,
        public bool $reused,
    ) {}
}
