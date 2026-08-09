<?php

namespace App\Services\Zoho\V2\Transport;

use App\Services\Zoho\V2\Bulk\BulkDownload;
use App\Services\Zoho\V2\Bulk\BulkTransportResponse;
use App\Services\Zoho\ZohoAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Read-only client restricted to the live-verified Zoho Bulk Read routes. */
class ZohoBulkReadTransport
{
    public function __construct(
        private readonly ZohoAuthService $auth,
        private readonly ?Sleeper $sleeper = null,
        private readonly int $maxRetries = 3,
        private readonly int $timeoutSeconds = 30,
        private readonly ?ZohoApiThrottle $throttle = null,
        private readonly ?ZohoApiThrottle $downloadThrottle = null,
    ) {}

    public function create(string $moduleApiName, ?string $pageToken, string $correlationId): BulkTransportResponse
    {
        if (preg_match('/^[A-Za-z0-9_]{1,100}$/', $moduleApiName) !== 1) {
            throw new RuntimeException('Bulk Read module name is invalid.');
        }
        if ($pageToken !== null && ($pageToken === '' || strlen($pageToken) > 512)) {
            throw new RuntimeException('Bulk Read page token is invalid.');
        }

        if ($pageToken !== null) {
            // Zoho's official Bulk Read continuation shape is nested under
            // query. This organization has no >200k export with which to
            // empirically produce a token yet, so keep this path fail-safe.
            $body = ['query' => ['page_token' => $pageToken]];
        } else {
            $body = [
                'query' => [
                    'module' => ['api_name' => $moduleApiName],
                    'fields' => ['Created_Time'],
                ],
            ];
        }

        return $this->requestJson('post', '/read', $body, $correlationId, true);
    }

    public function status(string $jobId, string $correlationId): BulkTransportResponse
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $jobId) !== 1) {
            throw new RuntimeException('Bulk Read job identifier is invalid.');
        }

        return $this->requestJson('get', '/read/'.rawurlencode($jobId), null, $correlationId);
    }

    public function downloadToTempFile(string $url, string $correlationId): BulkDownload
    {
        $approvedUrl = $this->approvedDownloadUrl($url);
        $path = tempnam(sys_get_temp_dir(), 'zoho-bulk-');
        if ($path === false) {
            throw new RuntimeException('Cannot prepare a Bulk Read download.');
        }

        try {
            [$response, $attempts] = $this->attempt('get', $approvedUrl, null, $correlationId, false, $path);
            if (! $response->successful()) {
                throw ZohoBulkReadTransportException::fromResponse($response, $attempts);
            }

            $bytes = filesize($path);
            if (! is_int($bytes) || $bytes < 1) {
                throw ZohoBulkReadTransportException::downloadFailed($attempts, 200);
            }
            if ($bytes > $this->maxArchiveBytes()) {
                throw ZohoBulkReadTransportException::downloadFailed($attempts, 200);
            }

            return new BulkDownload($path, $attempts, $bytes);
        } catch (Throwable $exception) {
            @unlink($path);
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException('Bulk Read download failed.');
        }
    }

    /** @param array<string,mixed>|null $body */
    private function requestJson(
        string $method,
        string $path,
        ?array $body,
        string $correlationId,
        bool $isBulkPost = false,
    ): BulkTransportResponse {
        if (($method === 'post' && ! $isBulkPost)
            || preg_match('#^/read(?:/[A-Za-z0-9._-]+)?$#', $path) !== 1) {
            throw new RuntimeException('Bulk transport only permits verified Bulk Read paths.');
        }

        [$response, $attempts] = $this->attempt(
            $method,
            $this->baseUrl().$path,
            $body,
            $correlationId,
            true,
        );
        if (! $response->successful()) {
            throw ZohoBulkReadTransportException::fromResponse($response, $attempts);
        }

        $payload = $response->json();

        return new BulkTransportResponse(
            is_array($payload) ? $payload : [],
            $response->status(),
            $attempts,
        );
    }

    /**
     * @param  array<string,mixed>|null  $body
     * @return array{Response,int}
     */
    private function attempt(
        string $method,
        string $url,
        ?array $body,
        string $correlationId,
        bool $json,
        ?string $sink = null,
    ): array {
        $retries = 0;
        $attempts = 0;
        $refreshedAfterUnauthorized = false;
        $deliveryStartedAt = microtime(true);

        while (true) {
            try {
                $headers = [
                    'Authorization' => 'Zoho-oauthtoken '.$this->auth->getAccessToken('crm'),
                    'X-Correlation-ID' => $this->safeCorrelationId($correlationId),
                ];
                $attempt = function () use ($method, $url, $body, $headers, $json, $sink, &$attempts): Response {
                    // Count only an actual HTTP attempt. Local shared-throttle
                    // admission failures never consume remote API capacity.
                    $attempts++;
                    $request = Http::timeout($json ? $this->timeoutSeconds : max(60, $this->timeoutSeconds))
                        ->withHeaders($headers);
                    if ($json) {
                        $request = $request->acceptJson();
                    }
                    if ($sink !== null) {
                        $guard = new BulkDownloadGuard($this->maxArchiveBytes());
                        $request = $request->withOptions([
                            'sink' => $sink,
                            'allow_redirects' => false,
                            'on_headers' => [$guard, 'assertHeaders'],
                            'progress' => [$guard, 'assertProgress'],
                        ]);
                    }

                    return $method === 'post'
                        ? $request->post($url, $body ?? [])
                        : $request->get($url);
                };
                $response = $sink === null
                    ? $this->throttle()->execute($attempt)
                    : $this->downloadThrottle()->execute(
                        fn (): Response => $this->throttle()->execute($attempt),
                    );
            } catch (ZohoThrottleUnavailableException) {
                throw ZohoBulkReadTransportException::deferred(
                    $this->capacityDeferralSeconds(),
                    $attempts,
                );
            } catch (ConnectionException) {
                if ($retries++ >= max(0, $this->maxRetries)) {
                    if ($sink !== null) {
                        throw ZohoBulkReadTransportException::downloadFailed($attempts);
                    }

                    throw new RuntimeException('Bulk Read transport unavailable.');
                }

                $this->sleepBeforeRetry($retries);

                continue;
            } catch (ZohoBulkReadTransportException $exception) {
                throw $exception;
            } catch (Throwable) {
                if ($sink !== null) {
                    throw ZohoBulkReadTransportException::downloadFailed($attempts);
                }

                throw new RuntimeException('Bulk Read transport unavailable.');
            }

            if ($response->status() === 401 && ! $refreshedAfterUnauthorized) {
                $this->auth->invalidate('crm');
                $refreshedAfterUnauthorized = true;

                continue;
            }

            $retryAfter = $this->retryAfterSeconds($response);
            if ($response->status() === 429
                && $retryAfter !== null
                && $retryAfter > min(
                    $this->maxInlineRetryAfterSeconds(),
                    $this->remainingInlineBudgetSeconds($deliveryStartedAt),
                )) {
                throw ZohoBulkReadTransportException::deferred(
                    $retryAfter,
                    $attempts,
                    $response->status(),
                );
            }

            if (($response->status() === 429 || $response->status() >= 500)
                && $retries++ < max(0, $this->maxRetries)) {
                $this->sleepBeforeRetry($retries, $retryAfter);

                continue;
            }

            return [$response, $attempts];
        }
    }

    private function approvedDownloadUrl(string $url): string
    {
        $parts = parse_url($url);
        $base = parse_url($this->baseUrl());
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $approvedHost = is_array($base) ? strtolower((string) ($base['host'] ?? '')) : '';
        $approvedPort = is_array($base) ? (int) ($base['port'] ?? 443) : 0;
        $isRelative = is_array($parts)
            && ! isset($parts['scheme'])
            && ! isset($parts['host'])
            && ! isset($parts['port']);
        $isApprovedAbsolute = is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === $approvedHost
            && (int) ($parts['port'] ?? 443) === $approvedPort;

        if ((! $isRelative && ! $isApprovedAbsolute)
            || $approvedHost === ''
            || ! is_array($base)
            || strtolower((string) ($base['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('#^/crm/bulk/v8/read/[A-Za-z0-9._-]+/result$#', $path) !== 1) {
            throw new RuntimeException('Bulk Read download URL was not an approved Zoho endpoint.');
        }

        $origin = 'https://'.$approvedHost;
        if ($approvedPort !== 443) {
            $origin .= ':'.$approvedPort;
        }

        return $origin.$path;
    }

    private function baseUrl(): string
    {
        $configured = rtrim((string) config('services.zoho.crm.api_url', 'https://www.zohoapis.com/crm/v8'), '/');
        $origin = preg_replace('#/crm/v\d+$#i', '', $configured) ?: $configured;

        return rtrim($origin, '/').'/crm/bulk/v8';
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));
        if (preg_match('/^[0-9]+$/', $value) === 1) {
            return min($this->maxRetryAfterSeconds(), (int) $value);
        }

        $timestamp = strtotime($value);
        if ($value === '' || $timestamp === false) {
            return null;
        }

        return min(
            $this->maxRetryAfterSeconds(),
            max(0, $timestamp - now()->timestamp),
        );
    }

    private function sleepBeforeRetry(int $retry, ?int $retryAfter = null): void
    {
        $milliseconds = $retryAfter === null
            ? min(30_000, (250 * (2 ** ($retry - 1))) + random_int(0, 250))
            : $retryAfter * 1000;

        ($this->sleeper ?? new NativeSleeper)->sleepMilliseconds($milliseconds);
    }

    private function safeCorrelationId(string $correlationId): string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $correlationId) === 1
            ? $correlationId
            : 'redacted-correlation';
    }

    private function maxArchiveBytes(): int
    {
        $configured = config('zoho-v2.bulk.max_archive_bytes', 64 * 1024 * 1024);

        return is_int($configured) && $configured > 0 ? $configured : 64 * 1024 * 1024;
    }

    private function maxRetryAfterSeconds(): int
    {
        $configured = config('zoho-v2.bulk.max_retry_after_seconds', 3_600);

        return is_int($configured) ? min(43_200, max(1, $configured)) : 3_600;
    }

    private function maxInlineRetryAfterSeconds(): int
    {
        $configured = config('zoho-v2.bulk.max_inline_retry_after_seconds', 30);
        $deliveryTimeout = max(60, (int) config('zoho-v2.bulk.delivery_timeout_seconds', 900));
        $safeMaximum = max(0, min(600, $deliveryTimeout - max(60, $this->timeoutSeconds) - 30));

        return is_int($configured) ? min($safeMaximum, max(0, $configured)) : min(30, $safeMaximum);
    }

    private function capacityDeferralSeconds(): int
    {
        $configured = config('zoho-v2.bulk.capacity_deferral_seconds', 60);

        return is_int($configured) ? min(3_600, max(1, $configured)) : 60;
    }

    private function remainingInlineBudgetSeconds(float $deliveryStartedAt): int
    {
        $deliveryTimeout = max(60, (int) config('zoho-v2.bulk.delivery_timeout_seconds', 900));
        $elapsed = max(0, (int) ceil(microtime(true) - $deliveryStartedAt));

        return max(0, $deliveryTimeout - $elapsed - max(60, $this->timeoutSeconds) - 30);
    }

    private function throttle(): ZohoApiThrottle
    {
        return $this->throttle ?? new ZohoApiThrottle($this->sleeper);
    }

    private function downloadThrottle(): ZohoApiThrottle
    {
        if ($this->downloadThrottle !== null) {
            return $this->downloadThrottle;
        }

        $configured = config('zoho-v2.bulk.downloads_per_minute', 10);
        $perMinute = is_int($configured) ? min(100, max(1, $configured)) : 10;

        return new ZohoApiThrottle(
            $this->sleeper,
            'bulk-download',
            $perMinute,
            0,
        );
    }
}
