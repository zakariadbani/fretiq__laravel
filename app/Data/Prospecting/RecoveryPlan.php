<?php

namespace App\Data\Prospecting;

final readonly class RecoveryPlan
{
    /**
     * @param  array<int, array<string, mixed>>  $verificationChanges
     * @param  array<int, array<string, mixed>>  $companyChanges
     * @param  array<int, array<string, mixed>>  $discoveredContacts
     * @param  array<int, array<string, mixed>>  $snapshotItems
     * @param  array<string, int>  $counts
     * @param  array<int|string, string>  $sourceEvidenceHashes
     */
    public function __construct(
        public string $fingerprint,
        public array $verificationChanges,
        public array $companyChanges,
        public array $discoveredContacts,
        public array $snapshotItems,
        public array $counts,
        public array $sourceEvidenceHashes,
    ) {}
}
