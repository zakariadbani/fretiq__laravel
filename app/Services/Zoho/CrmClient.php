<?php

namespace App\Services\Zoho;

/**
 * CrmClient — contract for the Zoho CRM HTTP abstraction.
 *
 * Implementations:
 *   RealCrmClient  — live HTTP calls to the Zoho CRM API.
 *   LocalCrmClient — fixture-backed stub; no HTTP; used in local dev + unit tests.
 *
 * Resolved via CrmClientFactory::make() based on config('services.zoho.crm_driver').
 */
interface CrmClient
{
    /**
     * Fetch records from a Zoho CRM module.
     *
     * @param  string  $module  Module path as it appears in the Zoho API URL (e.g. 'Accounts').
     * @param  array   $query   Query-string parameters (fields, per_page, page, …).
     * @return array            Decoded JSON response body.
     *
     * @throws \RuntimeException  On unrecoverable HTTP errors.
     */
    public function get(string $module, array $query = []): array;
}
