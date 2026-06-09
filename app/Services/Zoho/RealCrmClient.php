<?php

namespace App\Services\Zoho;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly ZohoAuthService $auth,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $module, array $query = []): array
    {
        $apiUrl  = rtrim(config('services.zoho.crm.api_url', 'https://www.zohoapis.com/crm/v2'), '/');
        $url     = $apiUrl . '/' . ltrim($module, '/');
        $token   = $this->auth->getAccessToken('crm');
        $headers = ['Authorization' => 'Zoho-oauthtoken ' . $token];

        $response = Http::withHeaders($headers)->timeout(30)->get($url, $query);

        // ── 401 → refresh token once and retry ───────────────────────────────
        if ($response->status() === 401) {
            Log::info("[RealCrmClient] 401 on {$module} — refreshing token and retrying");
            $this->auth->invalidate('crm');
            $token    = $this->auth->getAccessToken('crm');
            $headers  = ['Authorization' => 'Zoho-oauthtoken ' . $token];
            $response = Http::withHeaders($headers)->timeout(30)->get($url, $query);
        }

        // ── 429 → Retry-After sleep + single retry ───────────────────────────
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 2);
            $sleep      = min($retryAfter, 5);
            Log::warning("[RealCrmClient] 429 on {$module} — sleeping {$sleep}s then retry");
            sleep($sleep);
            $response = Http::withHeaders($headers)->timeout(30)->get($url, $query);
        }

        // ── No-content responses — treat as empty page ───────────────────────
        if (in_array($response->status(), [204, 304], true)) {
            return ['data' => []];
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "[RealCrmClient] HTTP {$response->status()} on GET {$module}: " . $response->body()
            );
        }

        return $response->json() ?? [];
    }
}
