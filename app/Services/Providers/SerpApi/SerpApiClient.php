<?php

namespace App\Services\Providers\SerpApi;

use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderExecution;
use App\Services\Providers\ProviderRequestException;
use App\Services\Providers\ProviderResponse;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class SerpApiClient
{
    /** @var list<string> */
    private const ENGINES = ['google', 'google_maps', 'google_local', 'bing'];

    /** @var array<string, list<string>> */
    private const PARAMETERS = [
        'google' => ['location', 'gl', 'hl', 'num', 'filter'],
        'google_maps' => ['location', 'gl', 'hl', 'll', 'type'],
        'google_local' => ['location', 'gl', 'hl'],
        'bing' => ['location', 'mkt', 'cc', 'setlang'],
    ];

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'api_key', 'apikey', 'authorization', 'token', 'password', 'key',
    ];

    public function __construct(private readonly ProviderCallLedger $ledger) {}

    /** @param array<string, mixed> $parameters */
    public function search(
        ProviderCallContext $context,
        string $engine,
        string $query,
        int $start = 0,
        array $parameters = [],
    ): ProviderExecution {
        return $this->executeSearch($context, $engine, $query, $start, $parameters);
    }

    /**
     * Legacy bridge: the callback reserves the existing DiscoveryRun quota only
     * after the provider ledger has ruled out replay/in-progress/backoff states.
     *
     * @param  array<string, mixed>  $parameters
     * @param  Closure(): bool  $reserve
     */
    public function searchWithReservation(
        ProviderCallContext $context,
        string $engine,
        string $query,
        int $start,
        array $parameters,
        Closure $reserve,
        int $timeoutSeconds = 20,
    ): ProviderExecution {
        return $this->executeSearch($context, $engine, $query, $start, $parameters, $reserve, $timeoutSeconds);
    }

    /** @param array<string, mixed> $parameters @param null|Closure(): bool $reserve */
    private function executeSearch(
        ProviderCallContext $context,
        string $engine,
        string $query,
        int $start,
        array $parameters,
        ?Closure $reserve = null,
        int $timeoutSeconds = 20,
    ): ProviderExecution {
        $engine = $this->validateEngine($engine);
        $query = $this->validateQuery($query);
        $start = $this->validateStart($engine, $start);
        $parameters = $this->normalizeParameters($engine, $parameters);
        $context = $this->contextForEngine($context, $engine);
        $timeoutSeconds = max(1, min(20, $timeoutSeconds));

        return $this->ledger->execute($context, 'serpapi', $engine, function () use ($engine, $query, $start, $parameters, $reserve, $timeoutSeconds): ProviderResponse {
            if ($reserve !== null && $reserve() !== true) {
                throw new ProviderRequestException('serpapi_budget_unavailable', false);
            }

            if ($this->isLocal()) {
                return $this->localSearch($engine);
            }

            $apiKey = $this->apiKey();
            $requestParameters = array_merge(
                ['engine' => $engine, 'q' => $query],
                $parameters,
                $this->pagination($engine, $start),
            );

            $startedAt = microtime(true);
            // The credential exists only at this transport boundary. It is never
            // placed in returned data, metadata, exceptions or application logs.
            $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->get('https://serpapi.com/search.json', array_merge($requestParameters, [
                    'api_key' => $apiKey,
                ]));

            return $this->searchResponse($response, $apiKey, $query, $start, $startedAt);
        });
    }

    public function account(ProviderCallContext $context): ProviderExecution
    {
        $context = $this->contextForEngine($context, 'account');

        return $this->ledger->execute($context, 'serpapi', 'account', function (): ProviderResponse {
            if ($this->isLocal()) {
                return new ProviderResponse(200, $this->sanitizeAccount([]), ['source' => 'local']);
            }

            $apiKey = $this->apiKey();
            $startedAt = microtime(true);
            $response = Http::timeout(15)
                ->acceptJson()
                ->get('https://serpapi.com/account', ['api_key' => $apiKey]);
            $body = $response->json();
            $body = is_array($body) ? $body : [];

            return new ProviderResponse(
                $response->status(),
                $this->sanitizeAccount($body),
                $response->failed() ? ['error_code' => $this->errorCode($response->status())] : [],
                $this->requestId($response),
                $this->durationMs($startedAt),
                $this->retryAfterSeconds($response),
            );
        });
    }

    private function searchResponse(
        Response $response,
        string $apiKey,
        string $query,
        int $start,
        float $startedAt,
    ): ProviderResponse {
        $body = $response->json();
        if (! is_array($body)) {
            return new ProviderResponse(502, [], [
                'error_code' => 'serpapi_invalid_response',
                'query_hash' => hash('sha256', $query),
                'offset' => $start,
            ], durationMs: $this->durationMs($startedAt));
        }

        $data = $this->sanitizeSearchPayload($body, $apiKey);
        $meta = [
            'source' => 'serpapi',
            'query_hash' => hash('sha256', $query),
            'offset' => $start,
        ];
        if ($response->failed()) {
            $meta['error_code'] = $this->errorCode($response->status());
        }

        $searchId = is_string(data_get($data, 'search_metadata.id'))
            ? data_get($data, 'search_metadata.id')
            : null;

        return new ProviderResponse(
            $response->status(),
            $data,
            $meta,
            $this->requestId($response, $searchId),
            $this->durationMs($startedAt),
            $this->retryAfterSeconds($response),
        );
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function sanitizeSearchPayload(array $payload, string $apiKey): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower($key);
            if ($normalizedKey === 'search_parameters' || in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if ($normalizedKey === 'search_metadata') {
                $metadata = is_array($value) ? $value : [];
                $safeMetadata = [];
                foreach (['id', 'status'] as $metadataKey) {
                    $candidate = $metadata[$metadataKey] ?? null;
                    if (is_string($candidate) && $this->safeIdentifierOrStatus($candidate)) {
                        $safeMetadata[$metadataKey] = $candidate;
                    }
                }
                $safe[$key] = $safeMetadata;

                continue;
            }

            $safe[$key] = $this->stripCredentials($value, $apiKey);
        }

        return $safe;
    }

    private function stripCredentials(mixed $value, string $apiKey): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $nested) {
                if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                    continue;
                }
                $safe[$key] = $this->stripCredentials($nested, $apiKey);
            }

            return $safe;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (str_contains($value, '://')) {
            $value = $this->stripCredentialQuery($value);
        }

        return $apiKey === '' ? $value : str_replace($apiKey, '[redacted]', $value);
    }

    private function stripCredentialQuery(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $query = [];
        if (is_string($parts['query'] ?? null)) {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                    unset($query[$key]);
                }
            }
        }

        $sanitized = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $sanitized .= ':'.(int) $parts['port'];
        }
        $sanitized .= $parts['path'] ?? '';
        if ($query !== []) {
            $sanitized .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if (isset($parts['fragment'])) {
            $sanitized .= '#'.$parts['fragment'];
        }

        return $sanitized;
    }

    /** @param array<string, mixed> $body @return array<string, int|string|null> */
    private function sanitizeAccount(array $body): array
    {
        $safe = [];
        foreach (['plan_searches_left', 'total_searches_left', 'this_month_usage', 'searches_per_month'] as $key) {
            if (isset($body[$key]) && is_numeric($body[$key])) {
                $safe[$key] = max(0, (int) $body[$key]);
            }
        }

        $planName = $body['plan_name'] ?? null;
        if (is_string($planName) && mb_strlen($planName) <= 191
            && preg_match('/[\x00-\x1F\x7F]/', $planName) !== 1
            && ! str_contains($planName, '://')
            && ! str_contains($planName, '@')) {
            $safe['plan_name'] = $planName;
        }

        return $safe;
    }

    /** @param array<string, mixed> $parameters @return array<string, int|string> */
    private function normalizeParameters(string $engine, array $parameters): array
    {
        $safe = [];

        foreach ($parameters as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('serpapi_search_parameters_invalid');
            }

            $normalizedKey = strtolower($key);
            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)
                || $normalizedKey === 'async'
                || in_array($normalizedKey, ['url', 'full_url', 'next', 'pagination'], true)) {
                throw new InvalidArgumentException('serpapi_search_parameters_invalid');
            }

            if ($normalizedKey === 'no_cache') {
                if ($value !== false && $value !== 0) {
                    throw new InvalidArgumentException('serpapi_search_parameters_invalid');
                }

                continue;
            }

            if (! in_array($normalizedKey, self::PARAMETERS[$engine], true)) {
                throw new InvalidArgumentException('serpapi_search_parameters_invalid');
            }

            $safe[$normalizedKey] = match ($normalizedKey) {
                'location' => $this->location($value),
                'gl', 'hl', 'cc', 'setlang' => $this->languageCode($value),
                'mkt' => $this->marketCode($value),
                'll' => $this->mapCoordinates($value),
                'type' => $value === 'search'
                    ? 'search'
                    : throw new InvalidArgumentException('serpapi_search_parameters_invalid'),
                'num' => is_int($value) && $value >= 1 && $value <= 100
                    ? $value
                    : throw new InvalidArgumentException('serpapi_search_parameters_invalid'),
                'filter' => is_int($value) && in_array($value, [0, 1], true)
                    ? $value
                    : throw new InvalidArgumentException('serpapi_search_parameters_invalid'),
            };
        }

        return $safe;
    }

    private function validateEngine(string $engine): string
    {
        if (! in_array($engine, self::ENGINES, true)) {
            throw new InvalidArgumentException('serpapi_engine_invalid');
        }

        return $engine;
    }

    private function validateQuery(string $query): string
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $query) === 1
            || str_contains($query, '://')) {
            throw new InvalidArgumentException('serpapi_query_invalid');
        }

        return $query;
    }

    private function validateStart(string $engine, int $start): int
    {
        $valid = $start >= 0 && $start <= 10000;
        if ($engine === 'google') {
            $valid = $valid && $start % 10 === 0;
        } elseif (in_array($engine, ['google_maps', 'google_local'], true)) {
            $valid = $valid && $start % 20 === 0;
        }

        if (! $valid) {
            throw new InvalidArgumentException('serpapi_pagination_invalid');
        }

        return $start;
    }

    /** @return array<string, int> */
    private function pagination(string $engine, int $start): array
    {
        if ($engine === 'bing') {
            return ['first' => $start + 1];
        }

        return $start > 0 ? ['start' => $start] : [];
    }

    private function location(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('serpapi_search_parameters_invalid');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || str_contains($value, '://')) {
            throw new InvalidArgumentException('serpapi_search_parameters_invalid');
        }

        return $value;
    }

    private function languageCode(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^[a-z]{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('serpapi_search_parameters_invalid');
        }

        return $value;
    }

    private function marketCode(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^([a-z]{2})-([a-z]{2})$/i', $value, $matches) !== 1) {
            throw new InvalidArgumentException('serpapi_search_parameters_invalid');
        }

        return strtolower($matches[1]).'-'.strtoupper($matches[2]);
    }

    private function mapCoordinates(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^@(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?),(\d+(?:\.\d+)?)(z|m)$/', $value, $matches) !== 1
            || abs((float) $matches[1]) > 90
            || abs((float) $matches[2]) > 180
            || (float) $matches[3] <= 0) {
            throw new InvalidArgumentException('serpapi_search_parameters_invalid');
        }

        return $value;
    }

    private function contextForEngine(ProviderCallContext $context, string $engine): ProviderCallContext
    {
        if ($context->engine !== null && $context->engine !== $engine) {
            throw new InvalidArgumentException('serpapi_context_engine_mismatch');
        }

        $reservedUnits = $this->isLocal() ? 0.0 : $context->reservedUnits;
        if ($context->engine === $engine && $context->reservedUnits === $reservedUnits) {
            return $context;
        }

        return new ProviderCallContext(
            $context->idempotencyKey,
            $reservedUnits,
            $context->batchId,
            $context->itemId,
            $engine,
        );
    }

    private function apiKey(): string
    {
        $key = trim((string) config('services.serpapi.api_key'));
        if ($key === '') {
            throw new ProviderRequestException('serpapi_not_configured', false, 503);
        }

        return $key;
    }

    private function requestId(Response $response, ?string $fallback = null): ?string
    {
        foreach (['X-Request-ID', 'Request-ID'] as $header) {
            $value = trim((string) $response->header($header));
            if ($this->safeIdentifier($value)) {
                return $value;
            }
        }

        return is_string($fallback) && $this->safeIdentifier($fallback) ? $fallback : null;
    }

    private function safeIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,191}$/', $value) === 1;
    }

    private function safeIdentifierOrStatus(string $value): bool
    {
        return mb_strlen($value) <= 191
            && preg_match('/^[A-Za-z0-9 ._:-]+$/', $value) === 1;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return max(1, min(86400, (int) $value));
        }
        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp <= time()) {
            return null;
        }

        return min(86400, $timestamp - time());
    }

    private function errorCode(int $status): string
    {
        return match (true) {
            $status === 401 => 'authentication_failed',
            $status === 403 => 'permission_denied',
            $status === 429 => 'rate_limit',
            in_array($status, [400, 422], true) => 'invalid_request',
            $status === 404 => 'not_found',
            $status >= 500 => 'provider_unavailable',
            default => 'provider_http_error',
        };
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function isLocal(): bool
    {
        return config('services.serpapi.driver', 'local') === 'local';
    }

    private function localSearch(string $engine): ProviderResponse
    {
        $file = in_array($engine, ['google_maps', 'google_local'], true)
            ? 'serpapi_maps.json'
            : 'serpapi.json';
        $path = database_path('fixtures/discovery/'.$file);
        $fixture = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $fixture = is_array($fixture) ? $fixture : [];

        if (in_array($engine, ['google_maps', 'google_local'], true)) {
            $data = is_array($fixture['local_results'] ?? null)
                ? $fixture
                : ['local_results' => array_values($fixture)];
        } else {
            $data = is_array($fixture['organic_results'] ?? null)
                ? $fixture
                : ['organic_results' => array_values($fixture)];
        }

        return new ProviderResponse(200, $data, ['source' => 'local']);
    }
}
