<?php

namespace App\Services\Zoho;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZohoGetWithRetry — shared GET-with-retry mechanics for RealCrmClient and
 * ZohoCrmTemplatesService ONLY.
 *
 * NOT used by ZohoCampaignsClient — that client is asForm() POST with
 * deliberately no 401/429 retry (unverified Phase-5 side-effecting calls;
 * adding retry there would risk duplicate sends). Do not extend this trait
 * to it.
 *
 * Handles exactly what both callers repeated byte-for-byte:
 *   - 401 → $auth->invalidate('crm') + fresh token + single retry.
 *   - 429 → sleep(min(Retry-After, 5)) + single retry.
 *
 * Callers keep their own 204/304 handling, failed()/exception construction,
 * and JSON decoding — those genuinely differ between the two (RealCrmClient
 * returns ['data' => []] on no-content, ZohoCrmTemplatesService returns []).
 *
 * Log prefix is derived from class_basename($this), which happens to match
 * both callers' existing "[RealCrmClient]" / "[ZohoCrmTemplatesService]"
 * log message prefixes exactly.
 */
trait ZohoGetWithRetry
{
    private function zohoGetWithRetry(
        ZohoAuthService $auth,
        string $url,
        array $query,
        string $context,
        int $timeout,
    ): Response {
        $prefix   = class_basename($this);
        $token    = $auth->getAccessToken('crm');
        $headers  = ['Authorization' => 'Zoho-oauthtoken ' . $token];
        $response = Http::withHeaders($headers)->timeout($timeout)->get($url, $query);

        // ── 401 → refresh token once and retry ───────────────────────────────
        if ($response->status() === 401) {
            Log::channel('zoho')->info('401 — refreshing token and retrying', [
                'source'  => $prefix,
                'context' => $context,
            ]);
            $auth->invalidate('crm');
            $token    = $auth->getAccessToken('crm');
            $headers  = ['Authorization' => 'Zoho-oauthtoken ' . $token];
            $response = Http::withHeaders($headers)->timeout($timeout)->get($url, $query);
        }

        // ── 429 → Retry-After sleep + single retry ───────────────────────────
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 2);
            $sleep      = min($retryAfter, 5);
            Log::channel('zoho')->warning('429 — sleeping then retry', [
                'source'    => $prefix,
                'context'   => $context,
                'sleep_sec' => $sleep,
            ]);
            sleep($sleep);
            $response = Http::withHeaders($headers)->timeout($timeout)->get($url, $query);
        }

        return $response;
    }
}
