<?php

namespace App\Services\Zoho\V2\Bulk;

final readonly class BulkDownload
{
    public function __construct(
        public string $path,
        public int $attempts,
        public int $bytes,
    ) {}
}
