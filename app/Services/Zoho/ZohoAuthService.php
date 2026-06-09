<?php

namespace App\Services\Zoho;

use App\Models\ZohoToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ZohoAuthService — OAuth token management for Zoho CRM (sprint-2a scope).
 *
 * Empirical verification: 2026-06-07, accounts.zoho.com/oauth/v2/token,
 * grant_type=refresh_token → STATUS 200, returns access_token + expires_in.
 * Campaigns OAuth is Phase 5 and NOT wired here.
 */
class ZohoAuthService
{
    /**
     * Return a valid access token for the given Zoho service.
     *
     * Loads or creates the ZohoToken row keyed on $service.
     * If the stored token is still valid (expires_at in the future), it is
     * returned immediately (no HTTP call).
     * Otherwise a refresh POST is made and the new token is persisted.
     *
     * @param  string  $service  Only 'crm' is supported this sprint (Phase 5 adds 'campaigns').
     * @return string            A valid Zoho OAuth access token.
     *
     * @throws \RuntimeException  On HTTP failure or missing access_token in response.
     */
    public function getAccessToken(string $service = 'crm'): string
    {
        $token = ZohoToken::query()->firstOrNew(['service' => $service]);

        if ($token->access_token && $token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        $refreshToken = $token->refresh_token ?: config("services.zoho.{$service}.refresh_token");

        if (! $refreshToken) {
            throw new \RuntimeException("Refresh token Zoho manquant pour le service '{$service}'");
        }

        $accountsUrl = rtrim(
            config('services.zoho.crm.accounts_url', 'https://accounts.zoho.com'),
            '/'
        );

        $response = Http::asForm()->timeout(20)->post(
            $accountsUrl . '/oauth/v2/token',
            [
                'refresh_token' => $refreshToken,
                'client_id'     => config("services.zoho.{$service}.client_id"),
                'client_secret' => config("services.zoho.{$service}.client_secret"),
                'grant_type'    => 'refresh_token',
            ]
        );

        if ($response->failed()) {
            throw new \RuntimeException(
                "Échec du rafraîchissement OAuth Zoho ({$service}): " . $response->body()
            );
        }

        $payload = $response->json();

        if (empty($payload['access_token'])) {
            throw new \RuntimeException(
                "Réponse OAuth Zoho invalide — access_token absent: " . $response->body()
            );
        }

        $expiresIn = (int) ($payload['expires_in'] ?? $payload['expires_in_sec'] ?? 3600);
        // Zoho sometimes returns milliseconds (> 100 000) — convert to seconds.
        if ($expiresIn > 100_000) {
            $expiresIn = (int) floor($expiresIn / 1000);
        }

        $token->access_token = $payload['access_token'];
        $token->refresh_token = $refreshToken;
        $token->expires_at    = now()->addSeconds(max(300, $expiresIn - 60));
        $token->save();

        Log::info("[ZohoAuthService] Token {$service} rafraîchi, expire dans {$expiresIn}s");

        return $token->access_token;
    }

    /**
     * Force-clear the cached token so the next call to getAccessToken() will
     * trigger a refresh. Used by RealCrmClient on 401 responses.
     */
    public function invalidate(string $service = 'crm'): void
    {
        ZohoToken::query()
            ->where('service', $service)
            ->update(['access_token' => null, 'expires_at' => null]);
    }
}
