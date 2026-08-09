<?php

namespace App\Services\Zoho\V2\Bulk;

final readonly class BulkBackfillStep
{
    public const REDISPATCH = 'redispatch';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    public function __construct(
        public string $action,
        public ?int $bulkJobId = null,
        public int $delaySeconds = 0,
    ) {}

    public static function redispatch(int $bulkJobId, int $delaySeconds = 0): self
    {
        return new self(self::REDISPATCH, $bulkJobId, max(0, $delaySeconds));
    }

    public static function complete(?int $bulkJobId = null): self
    {
        return new self(self::COMPLETE, $bulkJobId);
    }

    public static function failed(?int $bulkJobId = null): self
    {
        return new self(self::FAILED, $bulkJobId);
    }
}
