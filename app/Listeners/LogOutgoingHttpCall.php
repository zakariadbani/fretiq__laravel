<?php

namespace App\Listeners;

use App\Support\ApiLog;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Throwable;

/**
 * LogOutgoingHttpCall — logs every outgoing Laravel HTTP client call on the
 * 'api' channel, without touching any of the ~15 call sites. Covers both
 * ResponseReceived (any status) and ConnectionFailed (no response at all).
 *
 * Registered for both events in EventServiceProvider — Laravel dispatches each
 * event to a listener class via its handle() method, so one class per event
 * type keeps the mapping obvious.
 */
class LogOutgoingHttpCall
{
    /** Host → provider tag, suffix-aware (real hosts are www.zohoapis.com, api.hunter.io, …). */
    private const PROVIDER_HOSTS = [
        'serpapi.com' => 'serpapi',
        'hunter.io' => 'hunter',
        'zohoapis.com' => 'zoho_crm',
        'campaigns.zoho.com' => 'zoho_campaigns',
        'accounts.zoho.com' => 'zoho_auth',
        'generativelanguage.googleapis.com' => 'gemini',
    ];

    public function handleResponseReceived(ResponseReceived $event): void
    {
        try {
            $request = $event->request;
            $response = $event->response;
            $redacted = ApiLog::redactUrl($request->url());
            $provider = $this->resolveProvider($redacted['host']);

            $stats = $response->handlerStats();
            $durationMs = isset($stats['total_time']) ? (float) $stats['total_time'] * 1000 : null;

            $ok = $response->successful() || $response->redirect();

            $context = [
                'method' => $request->method(),
                'host' => $redacted['host'],
                'path' => $redacted['path'],
                'query' => $redacted['query'],
                'status' => $response->status(),
                'duration_ms' => $durationMs,
                'ok' => $ok,
            ];

            if (! $ok && $provider !== 'web') {
                // Response bodies are never logged for provider 'web' — arbitrary
                // third-party data (see HomepageSnapshotService.php invariant).
                $context['body_excerpt'] = ApiLog::excerpt($response->body(), 500);
            }

            ApiLog::call($provider, $context);
        } catch (Throwable $e) {
            // A logging failure must never break the HTTP call.
        }
    }

    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        try {
            $request = $event->request;
            $redacted = ApiLog::redactUrl($request->url());
            $provider = $this->resolveProvider($redacted['host']);

            // Never log getException()->getMessage() — Guzzle can embed the full
            // URI, including query secrets, in the exception message.
            ApiLog::call($provider, [
                'method' => $request->method(),
                'host' => $redacted['host'],
                'path' => $redacted['path'],
                'query' => $redacted['query'],
                'ok' => false,
                'exception' => get_class($event->exception),
            ], 'error');
        } catch (Throwable $e) {
            // A logging failure must never break the HTTP call.
        }
    }

    private function resolveProvider(string $host): string
    {
        foreach (self::PROVIDER_HOSTS as $domain => $provider) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $provider;
            }
        }

        return 'web';
    }
}
