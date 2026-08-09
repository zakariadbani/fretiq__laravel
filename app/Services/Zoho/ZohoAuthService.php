<?php

namespace App\Services\Zoho;

use App\Models\ZohoToken;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * @return string A valid Zoho OAuth access token.
     *
     * @throws \RuntimeException On HTTP failure or missing access_token in response.
     */
    public function getAccessToken(string $service = 'crm'): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $service) !== 1) {
            throw new \RuntimeException('Service OAuth Zoho invalide.');
        }

        $token = ZohoToken::query()->firstOrNew(['service' => $service]);

        if ($token->access_token && $token->expires_at && $token->expires_at->isFuture()) {
            return $token->access_token;
        }

        try {
            $lock = Cache::lock('zoho:oauth-refresh:'.$service, 30);
        } catch (Throwable) {
            throw new \RuntimeException("Verrou OAuth Zoho indisponible ({$service}).");
        }

        try {
            return $lock->block(5, function () use ($service): string {
                $token = ZohoToken::query()->firstOrNew(['service' => $service]);

                // Another process may have refreshed while this caller was
                // waiting for the shared lock.
                if ($token->access_token && $token->expires_at && $token->expires_at->isFuture()) {
                    return $token->access_token;
                }

                return $this->refreshAccessToken($service, $token);
            });
        } catch (LockTimeoutException) {
            throw new \RuntimeException("Verrou OAuth Zoho expiré ({$service}).");
        }
    }

    private function refreshAccessToken(string $service, ZohoToken $token): string
    {
        $refreshToken = $token->refresh_token ?: config("services.zoho.{$service}.refresh_token");

        if (! $refreshToken) {
            throw new \RuntimeException("Refresh token Zoho manquant pour le service '{$service}'");
        }

        $accountsUrl = rtrim(
            config('services.zoho.crm.accounts_url', 'https://accounts.zoho.com'),
            '/'
        );

        $response = Http::asForm()->timeout(20)->post(
            $accountsUrl.'/oauth/v2/token',
            [
                'refresh_token' => $refreshToken,
                'client_id' => config("services.zoho.{$service}.client_id"),
                'client_secret' => config("services.zoho.{$service}.client_secret"),
                'grant_type' => 'refresh_token',
            ]
        );

        if ($response->failed()) {
            $code = $this->safeOAuthErrorCode($response->json(), 'oauth_http_'.$response->status());
            throw new \RuntimeException("Échec du rafraîchissement OAuth Zoho ({$service}, statut {$response->status()}, code {$code}).");
        }

        $payload = $response->json();

        if (empty($payload['access_token'])) {
            $code = $this->safeOAuthErrorCode($payload, 'invalid_oauth_response');
            throw new \RuntimeException("Réponse OAuth Zoho invalide ({$service}, code {$code}).");
        }

        $expiresIn = (int) ($payload['expires_in'] ?? $payload['expires_in_sec'] ?? 3600);
        // Zoho sometimes returns milliseconds (> 100 000) — convert to seconds.
        if ($expiresIn > 100_000) {
            $expiresIn = (int) floor($expiresIn / 1000);
        }

        $token->access_token = $payload['access_token'];
        $token->refresh_token = $refreshToken;
        $token->expires_at = now()->addSeconds(max(300, $expiresIn - 60));
        $token->save();

        Log::info("[ZohoAuthService] Token {$service} rafraîchi, expire dans {$expiresIn}s");

        return $token->access_token;
    }

    private function safeOAuthErrorCode(mixed $payload, string $fallback): string
    {
        $code = is_array($payload) ? ($payload['error'] ?? $payload['code'] ?? null) : null;

        return is_string($code) && preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $code) === 1
            ? $code
            : $fallback;
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
