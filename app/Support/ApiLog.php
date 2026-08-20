<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * ApiLog — small static helper for the 'api' log channel and ad-hoc named logs.
 *
 * Modeled on the ladiesmall `Tools::custom_log()` on-the-fly `Log::build()` pattern,
 * upgraded with daily rotation and structured context arrays.
 *
 * @see App\Listeners\LogOutgoingHttpCall — the only intended caller of call().
 */
class ApiLog
{
    /**
     * Secret query-param keys stripped by redactUrl(). Aligned with
     * SerpApiClient::SENSITIVE_KEYS plus the additional Zoho OAuth param names.
     */
    private const SECRET_QUERY_KEYS = [
        'api_key', 'apikey', 'key', 'token', 'access_token', 'refresh_token',
        'client_secret', 'authtoken',
    ];

    /**
     * Log one external API call on the 'api' channel.
     *
     * @param  array<string, mixed>  $context
     */
    public static function call(string $provider, array $context, string $level = 'info'): void
    {
        $ok = $context['ok'] ?? true;
        $resolvedLevel = $level !== 'info' ? $level : ($ok === false ? 'warning' : 'info');

        Log::channel('api')->log($resolvedLevel, $provider, $context);
    }

    /**
     * Write to an arbitrary daily-rotated log file, created on demand.
     *
     * @param  array<string, mixed>  $context
     */
    public static function file(string $name, string $msg, array $context = []): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $name)) {
            throw new InvalidArgumentException("ApiLog::file() invalid log name: {$name}");
        }

        Log::build([
            'driver' => 'daily',
            'path' => storage_path("logs/{$name}.log"),
            'days' => 14,
        ])->info($msg, $context);
    }

    /**
     * Split a URL into host/path/query, stripping known secret query keys
     * (case-insensitive) while keeping the rest — pagination, module, engine,
     * etc. are real debugging signal. Same approach as SerpApiClient::stripCredentials().
     *
     * @return array{host: string, path: string, query: array<string, mixed>}
     */
    public static function redactUrl(string $url): array
    {
        $parts = parse_url($url);

        $query = [];
        if (is_string($parts['query'] ?? null)) {
            parse_str($parts['query'], $query);
            foreach ($query as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), self::SECRET_QUERY_KEYS, true)) {
                    unset($query[$key]);
                }
            }
        }

        return [
            'host' => $parts['host'] ?? '',
            'path' => $parts['path'] ?? '',
            'query' => $query,
        ];
    }

    /**
     * Truncate a body to $limit chars, masking email-shaped substrings first
     * (truncation alone does not redact PII that may sit in the first N chars).
     */
    public static function excerpt(?string $body, int $limit = 500): ?string
    {
        if ($body === null) {
            return null;
        }

        $masked = self::maskEmails($body);

        return mb_substr($masked, 0, $limit);
    }

    /** Mask email-shaped substrings as `a***@domain.tld`. */
    public static function maskEmails(string $text): string
    {
        return preg_replace_callback(
            '/[a-zA-Z0-9._%+-]+@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/',
            fn (array $m) => mb_substr($m[0], 0, 1) . '***@' . $m[1],
            $text,
        ) ?? $text;
    }
}
