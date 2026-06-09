<?php

namespace App\Services\Zoho;

/**
 * CrmClientFactory — resolves the correct CrmClient implementation.
 *
 * Selection logic:
 *   config('services.zoho.crm_driver') === 'local'  → LocalCrmClient (fixture, no HTTP)
 *   any other value                                  → RealCrmClient  (live Zoho API)
 *
 * Usage in tests: inject a LocalCrmClient (or a mock) directly into
 * ZohoCrmSyncService's constructor — no factory call needed.
 */
class CrmClientFactory
{
    public static function make(): CrmClient
    {
        $driver = config('services.zoho.crm_driver', 'local');

        if ($driver === 'local') {
            return new LocalCrmClient();
        }

        return new RealCrmClient(new ZohoAuthService());
    }
}
