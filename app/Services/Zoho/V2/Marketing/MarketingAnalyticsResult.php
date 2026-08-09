<?php

namespace App\Services\Zoho\V2\Marketing;

final readonly class MarketingAnalyticsResult
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
