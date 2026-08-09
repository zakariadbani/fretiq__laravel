<?php

namespace App\Services\Zoho\V2\Marketing;

use App\Models\User;

/** Input boundary for the dashboard. The service normalizes all values before querying. */
final readonly class MarketingAnalyticsQuery
{
    /** @param array<string, mixed> $filters */
    public function __construct(public ?User $user, public MarketingPeriod $period, public array $filters = []) {}

    /** @return array<string, scalar|null> */
    public function normalizedFilters(): array
    {
        $allowed = ['commercial', 'source', 'campaign', 'country', 'sector', 'transport', 'client_type', 'lead_source', 'currency'];
        $normal = [];
        foreach ($allowed as $key) {
            $value = $this->filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normal[$key] = trim((string) $value);
            }
        }

        ksort($normal);

        return $normal;
    }
}
