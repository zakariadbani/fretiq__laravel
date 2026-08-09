<?php

namespace App\Services\Zoho\V2\Reconciliation;

final readonly class FailedRecordHydrationResult
{
    public function __construct(
        public bool $successful,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $apiRequests = 0,
        /** @var array<string,mixed>|null */
        public ?array $record = null,
    ) {}

    /** @param array{created:int,updated:int,unchanged:int} $counts */
    public static function persisted(array $counts, int $apiRequests): self
    {
        return new self(
            true,
            max(0, $counts['created']),
            max(0, $counts['updated']),
            max(0, $counts['unchanged']),
            max(0, $apiRequests),
        );
    }

    public static function failed(int $apiRequests = 0): self
    {
        return new self(false, apiRequests: max(0, $apiRequests));
    }

    /** @param array<string,mixed> $record */
    public static function fetched(array $record, int $apiRequests): self
    {
        return new self(
            true,
            apiRequests: max(0, $apiRequests),
            record: $record,
        );
    }
}
