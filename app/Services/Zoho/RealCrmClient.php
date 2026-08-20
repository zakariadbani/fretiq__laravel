<?php

namespace App\Services\Zoho;

use App\Support\ApiLog;

/**
 * RealCrmClient — live Zoho CRM API client.
 *
 * Handles:
 *   - Bearer-token injection via ZohoAuthService.
 *   - 401 → single token refresh + retry.
 *   - 429 → Retry-After sleep (capped at 5 s) + single retry.
 *   - 204 / 304 → empty data array (no-op pages).
 *
 * Empirical verification: 2026-06-07, GET /Accounts + /Contacts → STATUS 200.
 */
class RealCrmClient implements CrmClient
{
    use ZohoGetWithRetry;

    public function __construct(
        private readonly ZohoAuthService $auth,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $module, array $query = []): array
    {
        $apiUrl = rtrim(config('services.zoho.crm.api_url', 'https://www.zohoapis.com/crm/v2'), '/');
        $url    = $apiUrl . '/' . ltrim($module, '/');

        $response = $this->zohoGetWithRetry($this->auth, $url, $query, $module, 30);

        // ── No-content responses — treat as empty page ───────────────────────
        if (in_array($response->status(), [204, 304], true)) {
            return ['data' => []];
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "[RealCrmClient] HTTP {$response->status()} on GET {$module}: " . ApiLog::excerpt($response->body(), 300)
            );
        }

        return $response->json() ?? [];
    }
}
