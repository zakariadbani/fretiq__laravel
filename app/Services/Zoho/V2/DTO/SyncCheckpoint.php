<?php

namespace App\Services\Zoho\V2\DTO;

final readonly class SyncCheckpoint
{
    public function __construct(
        public ?string $cursor = null,
        public ?string $cursorId = null,
        public ?string $pageToken = null,
        public ?string $watermark = null,
    ) {}
}
