<?php

namespace App\Services\Zoho\V2\DTO;

final readonly class TransportAttempt
{
    public function __construct(
        public int $number,
        public ?int $status,
        public ?int $retryAfterSeconds,
        public ?string $reason = null,
    ) {}
}
