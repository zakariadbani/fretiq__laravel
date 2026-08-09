<?php

namespace App\Services\Zoho\V2\Access;

use Illuminate\Database\Eloquent\Builder;

final readonly class ZohoPortfolioScopeResult
{
    public function __construct(
        public Builder $query,
        public bool $mappingRequired = false,
        public ?string $zohoUserId = null,
    ) {}
}
