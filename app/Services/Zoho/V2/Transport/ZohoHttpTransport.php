<?php

namespace App\Services\Zoho\V2\Transport;

use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\TransportAttempt;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\ZohoAuthService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GET-only Zoho CRM transport. It deliberately has no generic write method so
 * the V2 mirror cannot become a CRM write path by accident.
 */
final class ZohoHttpTransport implements ZohoTransport
{
    public function __construct(
        private readonly ZohoAuthService $auth,
        private readonly ?Sleeper $sleeper = null,
        private readonly int $maxRetries = 3,
        private readonly int $timeoutSeconds = 20,
        private readonly ?ZohoApiThrottle $throttle = null,
    ) {}

    public function baseUrl(): string
    {
        $configured = rtrim((string) config('services.zoho.crm.api_url', 'https://www.zohoapis.com/crm/v8'), '/');
        $origin = preg_replace('#/crm/v\d+$#i', '', $configured) ?: $configured;

        return rtrim($origin, '/').'/crm/v8';
    }

    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        return $this->requestGet($path, $query, $correlationId);
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        $httpDate = DateTimeImmutable::createFromInterface($since)
            ->setTimezone(new DateTimeZone('GMT'))
            ->format('D, d M Y H:i:s \\G\\M\\T');

        return $this->requestGet($path, $query, $correlationId, $httpDate);
    }

    /** @param array<string, scalar|array|null> $query */
    private function requestGet(string $path, array $query, ?string $correlationId, ?string $ifModifiedSince = null): TransportResult
    {
        if (! str_starts_with($path, '/') || str_contains($path, '://') || str_contains($path, '?')) {
            throw new RuntimeException('Zoho transport only accepts relative read-only paths without query strings.');
        }

        $correlationId ??= (string) str()->uuid();
        $attempts = [];
        $retries = 0;
        $refreshedAfterUnauthorized = false;

        while (true) {
            try {
                $headers = [
                    // Zoho CRM rejects the conventional Bearer scheme.
                    'Authorization' => 'Zoho-oauthtoken '.$this->auth->getAccessToken('crm'),
                    'X-Correlation-ID' => $correlationId,
                ];

                if ($ifModifiedSince !== null) {
                    $headers['If-Modified-Since'] = $ifModifiedSince;
                }

                $response = $this->throttle()->execute(fn (): Response => Http::acceptJson()
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders($headers)
                    ->get($this->baseUrl().$path, $query));
            } catch (ZohoThrottleUnavailableException) {
                return $this->failure(0, $correlationId, $attempts, 'throttle_unavailable');
            } catch (ConnectionException $exception) {
                $attempts[] = new TransportAttempt(count($attempts) + 1, null, null, 'network');

                if ($retries++ >= $this->maxRetries) {
                    return $this->failure(0, $correlationId, $attempts, 'network_error');
                }

                $this->sleepBeforeRetry($retries);

                continue;
            }

            $status = $response->status();
            $retryAfter = $this->retryAfterSeconds($response);
            $attempts[] = new TransportAttempt(count($attempts) + 1, $status, $retryAfter, $this->attemptReason($status));

            if ($status === 401 && ! $refreshedAfterUnauthorized) {
                $this->auth->invalidate('crm');
                $refreshedAfterUnauthorized = true;

                continue;
            }

            if (($status === 429 || $status >= 500) && $retries++ < $this->maxRetries) {
                $this->sleepBeforeRetry($retries, $retryAfter);

                continue;
            }

            return $this->resultFromResponse($response, $correlationId, $attempts);
        }
    }

    /** @param list<TransportAttempt> $attempts */
    private function failure(int $status, string $correlationId, array $attempts, string $errorCode): TransportResult
    {
        return new TransportResult($status, [], [], [], $correlationId, $attempts, $errorCode);
    }

    /** @param list<TransportAttempt> $attempts */
    private function resultFromResponse(Response $response, string $correlationId, array $attempts): TransportResult
    {
        $decoded = $response->json();
        $emptySuccess = in_array($response->status(), [204, 304], true);
        $payloadValid = $emptySuccess || is_array($decoded);
        $payload = is_array($decoded) ? $decoded : [];
        $httpSuccess = $response->successful() || $response->status() === 304;
        $success = $httpSuccess && $payloadValid;
        $errorCode = match (true) {
            ! $httpSuccess => $this->safeZohoErrorCode($payload, $response->status()),
            ! $payloadValid => 'malformed_success_response',
            default => null,
        };

        return new TransportResult(
            $response->status(),
            $success && is_array($payload['data'] ?? null) ? $payload['data'] : [],
            $success && is_array($payload['info'] ?? null) ? $payload['info'] : [],
            [
                'x-ratelimit-remaining' => $this->safeHeader($response->header('X-RateLimit-Remaining')),
                'retry-after' => $this->safeHeader($response->header('Retry-After')),
                'x-request-id' => $this->safeHeader($response->header('X-Request-Id') ?? $response->header('request_id')),
                'x-crm-api-info' => $this->safeHeader($response->header('X-CRM-API-Info')),
            ],
            $correlationId,
            $attempts,
            $errorCode,
            $success ? $payload : [],
        );
    }

    /** Return only a bounded machine-readable code; Zoho's body can contain PII. */
    private function safeZohoErrorCode(array $payload, int $status): string
    {
        $code = $payload['code'] ?? null;

        if (is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $code) === 1) {
            return $code;
        }

        return 'http_'.$status;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? min(30, max(0, (int) $value)) : null;
    }

    /** Keep bounded, single-line telemetry headers only; never retain arbitrary response headers. */
    private function safeHeader(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 512 || preg_match('/[\r\n\x00]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function sleepBeforeRetry(int $retry, ?int $retryAfter = null): void
    {
        $milliseconds = $retryAfter === null
            ? min(30_000, (int) (250 * (2 ** ($retry - 1))) + random_int(0, 250))
            : $retryAfter * 1000;

        ($this->sleeper ?? new NativeSleeper)->sleepMilliseconds($milliseconds);
    }

    private function attemptReason(int $status): ?string
    {
        return match (true) {
            $status === 401 => 'unauthorized',
            $status === 429 => 'throttled',
            $status >= 500 => 'server_error',
            $status >= 400 => 'permanent_client_error',
            default => null,
        };
    }

    private function throttle(): ZohoApiThrottle
    {
        return $this->throttle ?? new ZohoApiThrottle($this->sleeper);
    }
}
