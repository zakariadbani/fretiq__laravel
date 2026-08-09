<?php

namespace App\Services\Zoho\V2\Bulk;

final class VerifiedBulkModules
{
    public function allows(string $module): bool
    {
        $modules = config('zoho-v2.bulk.verified_modules', []);
        if (! is_array($modules)) {
            return false;
        }

        return in_array(strtolower($module), array_values(array_filter(
            array_map(
                static fn ($value): ?string => is_string($value) ? strtolower(trim($value)) : null,
                $modules,
            ),
            static fn (?string $value): bool => $value !== null && $value !== '',
        )), true);
    }
}
