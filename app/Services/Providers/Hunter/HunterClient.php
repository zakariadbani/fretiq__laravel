<?php

namespace App\Services\Providers\Hunter;

use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderExecution;
use App\Services\Providers\ProviderRequestException;
use App\Services\Providers\ProviderResponse;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

final class HunterClient
{
    /** @var list<string> */
    private const DISCOVER_KEYS = [
        'query', 'organization', 'similar_to', 'headquarters_location', 'industry',
        'headcount', 'company_type', 'year_founded', 'keywords', 'technology',
        'funding', 'limit', 'offset',
    ];

    /** @var list<string> */
    private const DOMAIN_SEARCH_FILTER_KEYS = [
        'type', 'seniority', 'department', 'decision_maker', 'required_field',
        'verification_status', 'location', 'job_titles', 'aggregations',
    ];

    /** @var list<string> */
    private const TERMINAL_VERIFIER_STATUSES = [
        'valid', 'invalid', 'accept_all', 'webmail', 'disposable', 'unknown',
    ];

    public function __construct(private readonly ProviderCallLedger $ledger) {}

    /** @param array<string, mixed> $payload */
    public function discover(ProviderCallContext $context, array $payload): ProviderExecution
    {
        $this->validateDiscoverPayload($payload);

        return $this->execute($context, 'discover', function () use ($payload): ProviderResponse {
            if ($this->isLocal()) {
                return $this->localDiscover();
            }

            return $this->request('POST', 'discover', $payload, $this->timeout('discover_timeout'));
        });
    }

    public function domainFinder(
        ProviderCallContext $context,
        string $company,
        int $limit = 5,
        bool $perfectMatch = false,
    ): ProviderExecution {
        $company = trim($company);
        if (mb_strlen($company) < 3 || $limit < 1 || $limit > 10) {
            throw new InvalidArgumentException('hunter_domain_finder_parameters_invalid');
        }

        return $this->execute($context, 'domain_finder', function () use ($company, $limit, $perfectMatch): ProviderResponse {
            if ($this->isLocal()) {
                return $this->localDomainFinder($company, $limit, $perfectMatch);
            }

            return $this->request('GET', 'domain-finder', [
                'company' => $company,
                'limit' => $limit,
                'perfect_match' => $perfectMatch,
            ]);
        });
    }

    /** @param array<string, mixed> $filters */
    public function domainSearch(
        ProviderCallContext $context,
        string $domain,
        array $filters = [],
        int $limit = 100,
        int $offset = 0,
        ?int $timeoutSeconds = null,
    ): ProviderExecution {
        $domain = $this->validateDomain($domain);
        $filters = $this->normalizeDomainSearchFilters($filters);
        $timeoutSeconds = $this->optionalTimeout($timeoutSeconds);
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 10000) {
            throw new InvalidArgumentException('hunter_domain_search_pagination_invalid');
        }

        return $this->execute($context, 'domain_search', function () use ($domain, $filters, $limit, $offset, $timeoutSeconds): ProviderResponse {
            if ($this->isLocal()) {
                return $this->localDomainSearch($domain, $limit, $offset);
            }

            $payload = array_merge([
                'domain' => $domain,
                'limit' => $limit,
                'offset' => $offset,
            ], $filters);

            // Hunter requires POST when the structured location filter is used.
            return $this->request(isset($filters['location']) ? 'POST' : 'GET', 'domain-search', $payload, $timeoutSeconds);
        });
    }

    public function companyEnrichment(
        ProviderCallContext $context,
        string $domain,
        ?int $timeoutSeconds = null,
    ): ProviderExecution {
        $domain = $this->validateDomain($domain);
        $timeoutSeconds = $this->optionalTimeout($timeoutSeconds);

        return $this->execute($context, 'company_enrichment', function () use ($domain, $timeoutSeconds): ProviderResponse {
            if ($this->isLocal()) {
                return $this->localCompanyEnrichment($domain);
            }

            return $this->request('GET', 'companies/find', ['domain' => $domain], $timeoutSeconds);
        });
    }

    public function emailFinder(ProviderCallContext $context, string $domain, string $fullName): ProviderExecution
    {
        $domain = $this->validateDomain($domain);
        $fullName = trim($fullName);
        if ($fullName === '' || mb_strlen($fullName) > 255) {
            throw new InvalidArgumentException('hunter_email_finder_name_invalid');
        }

        return $this->execute($context, 'email_finder', function () use ($domain, $fullName): ProviderResponse {
            if ($this->isLocal()) {
                return $this->localEmailFinder($domain, $fullName);
            }

            return $this->request('GET', 'email-finder', [
                'domain' => $domain,
                'full_name' => $fullName,
            ]);
        });
    }

    public function emailVerifier(ProviderCallContext $context, string $email): ProviderExecution
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            throw new InvalidArgumentException('hunter_email_verifier_email_invalid');
        }

        return $this->execute($context, 'email_verifier', function () use ($email): ProviderResponse {
            if ($this->isLocal()) {
                return $this->localEmailVerifier($email);
            }

            return $this->request('GET', 'email-verifier', ['email' => $email], verifier: true);
        });
    }

    public function accountUsage(ProviderCallContext $context): ProviderExecution
    {
        return $this->execute($context, 'account_usage', function (): ProviderResponse {
            if ($this->isLocal()) {
                return new ProviderResponse(200, [
                    'reset_date' => null,
                    'requests' => [],
                ], ['source' => 'local']);
            }

            $response = $this->request('GET', 'usage');

            return new ProviderResponse(
                $response->httpStatus,
                $this->sanitizeUsageData($response->data),
                [],
                $response->requestId,
                $response->durationMs,
                $response->retryAfterSeconds,
            );
        });
    }

    /** @param array<string, mixed> $filters */
    public function usageHistory(ProviderCallContext $context, array $filters = []): ProviderExecution
    {
        $filters = $this->validateUsageHistoryFilters($filters);

        return $this->execute($context, 'usage_history', function () use ($filters): ProviderResponse {
            if ($this->isLocal()) {
                return new ProviderResponse(200, [], [
                    'source' => 'local',
                    'offset' => (int) ($filters['offset'] ?? 0),
                    'limit' => (int) ($filters['limit'] ?? 20),
                ]);
            }

            $response = $this->request('GET', 'usage/history', $filters);

            // Request URLs, IP addresses and user agents are provider audit internals,
            // not prospecting data. Keep the useful usage event but drop that subtree.
            $rows = array_values(array_filter(array_map(
                fn (mixed $row): ?array => is_array($row) ? $this->sanitizeUsageHistoryRow($row) : null,
                $response->data,
            )));

            return new ProviderResponse(
                $response->httpStatus,
                $rows,
                $this->sanitizeUsageHistoryMeta($response->meta),
                $response->requestId,
                $response->durationMs,
                $response->retryAfterSeconds,
            );
        });
    }

    /** @param array<string, mixed> $parameters */
    /** @param Closure(): ProviderResponse $transport */
    private function execute(
        ProviderCallContext $context,
        string $operation,
        Closure $transport,
    ): ProviderExecution {
        if ($this->isLocal() && $context->reservedUnits !== 0.0) {
            $context = new ProviderCallContext(
                $context->idempotencyKey,
                0,
                $context->batchId,
                $context->itemId,
                $context->engine,
            );
        }

        return $this->ledger->execute($context, 'hunter', $operation, $transport);
    }

    private function request(
        string $method,
        string $path,
        array $parameters = [],
        ?int $timeout = null,
        bool $verifier = false,
    ): ProviderResponse {
        $key = trim((string) config('services.hunter.api_key'));
        if ($key === '') {
            throw new ProviderRequestException('hunter_not_configured', false, 503);
        }

        $startedAt = microtime(true);
        $request = Http::withToken($key)
            ->acceptJson()
            ->timeout($timeout ?? $this->timeout('timeout'));
        $response = strtoupper($method) === 'POST'
            ? $request->asJson()->post($this->url($path), $parameters)
            : $request->get($this->url($path), $parameters);
        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        $body = $response->json();
        if (! is_array($body)) {
            return new ProviderResponse(502, [], ['error_code' => 'hunter_invalid_response'], durationMs: $durationMs);
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $requestId = $this->requestId($response);
        $retryAfter = $this->retryAfterSeconds($response);

        if ($verifier && $response->status() !== 202) {
            $specialStatus = $this->verifierSpecialStatus($response->status(), $body);
            if ($specialStatus !== null) {
                $terminalStatus = strtolower((string) ($data['status'] ?? ''));
                if (! in_array($terminalStatus, self::TERMINAL_VERIFIER_STATUSES, true)) {
                    $terminalStatus = 'unknown';
                }
                $data['status'] = $terminalStatus;
                $meta['provider_status'] = (string) $specialStatus;
                $meta['error_code'] = $specialStatus === 222
                    ? 'unexpected_smtp_response'
                    : 'claimed_email';

                // These are terminal logical verification outcomes, not transport
                // failures. A 2xx envelope keeps them settleable by the contact
                // transaction while provider_status retains Hunter's status.
                return new ProviderResponse(200, $data, $meta, $requestId, $durationMs, $retryAfter);
            }
        }

        if ($response->failed()) {
            $meta['error_code'] = $this->errorCode($response->status(), $body);
        }

        return new ProviderResponse(
            $response->status(),
            $data,
            $meta,
            $requestId,
            $durationMs,
            $retryAfter,
        );
    }

    /** @param array<string, mixed> $payload */
    private function validateDiscoverPayload(array $payload): void
    {
        $this->assertNoSensitiveKeys($payload, 'hunter_discover_payload_invalid');
        $this->assertOnlyKeys($payload, self::DISCOVER_KEYS, 'hunter_discover_payload_invalid');
        if ($payload === [] || (isset($payload['query']) && (! is_string($payload['query']) || trim($payload['query']) === ''))) {
            throw new InvalidArgumentException('hunter_discover_payload_invalid');
        }
        if (isset($payload['limit']) && (! is_int($payload['limit']) || $payload['limit'] < 1 || $payload['limit'] > 100)) {
            throw new InvalidArgumentException('hunter_discover_payload_invalid');
        }
        if (isset($payload['offset']) && (! is_int($payload['offset']) || $payload['offset'] < 0 || $payload['offset'] > 10000)) {
            throw new InvalidArgumentException('hunter_discover_payload_invalid');
        }

        foreach (['organization', 'similar_to'] as $filter) {
            if (isset($payload[$filter])) {
                $this->assertArrayFilter($payload[$filter], ['domain', 'name'], 'hunter_discover_payload_invalid');
                foreach ($payload[$filter] as $value) {
                    if (! is_string($value) && ! $this->isScalarList($value)) {
                        throw new InvalidArgumentException('hunter_discover_payload_invalid');
                    }
                }
            }
        }
        foreach (['industry', 'company_type'] as $filter) {
            if (isset($payload[$filter])) {
                $this->assertArrayFilter($payload[$filter], ['include', 'exclude'], 'hunter_discover_payload_invalid');
                foreach ($payload[$filter] as $value) {
                    if (! $this->isScalarList($value)) {
                        throw new InvalidArgumentException('hunter_discover_payload_invalid');
                    }
                }
            }
        }

        if (isset($payload['headcount']) && ! $this->isScalarList($payload['headcount'])) {
            throw new InvalidArgumentException('hunter_discover_payload_invalid');
        }
        if (isset($payload['headquarters_location'])) {
            $this->validateLocationFilter($payload['headquarters_location'], 'hunter_discover_payload_invalid');
        }
        if (isset($payload['year_founded'])) {
            $this->assertArrayFilter($payload['year_founded'], ['include', 'exclude', 'from', 'to'], 'hunter_discover_payload_invalid');
            foreach (['include', 'exclude'] as $list) {
                if (isset($payload['year_founded'][$list]) && ! $this->isScalarList($payload['year_founded'][$list])) {
                    throw new InvalidArgumentException('hunter_discover_payload_invalid');
                }
            }
        }
        foreach (['keywords', 'technology'] as $filter) {
            if (! isset($payload[$filter])) {
                continue;
            }
            $this->assertArrayFilter($payload[$filter], ['include', 'exclude', 'match'], 'hunter_discover_payload_invalid');
            foreach (['include', 'exclude'] as $list) {
                if (isset($payload[$filter][$list]) && ! $this->isScalarList($payload[$filter][$list])) {
                    throw new InvalidArgumentException('hunter_discover_payload_invalid');
                }
            }
            if (isset($payload[$filter]['match']) && ! in_array($payload[$filter]['match'], ['any', 'all'], true)) {
                throw new InvalidArgumentException('hunter_discover_payload_invalid');
            }
        }
        if (isset($payload['funding'])) {
            $this->assertArrayFilter($payload['funding'], ['series', 'amount', 'date'], 'hunter_discover_payload_invalid');
            if (isset($payload['funding']['series']) && ! $this->isScalarList($payload['funding']['series'])) {
                throw new InvalidArgumentException('hunter_discover_payload_invalid');
            }
            foreach (['amount', 'date'] as $range) {
                if (isset($payload['funding'][$range])) {
                    $this->assertArrayFilter($payload['funding'][$range], ['from', 'to'], 'hunter_discover_payload_invalid');
                }
            }
        }
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function normalizeDomainSearchFilters(array $filters): array
    {
        $this->assertNoSensitiveKeys($filters, 'hunter_domain_search_filters_invalid');
        $this->assertOnlyKeys($filters, self::DOMAIN_SEARCH_FILTER_KEYS, 'hunter_domain_search_filters_invalid');
        $normalized = [];
        foreach ($filters as $key => $value) {
            if ($key === 'location') {
                $this->validateLocationFilter($value, 'hunter_domain_search_filters_invalid');
                $normalized[$key] = $value;

                continue;
            }
            if (in_array($key, ['decision_maker', 'aggregations'], true)) {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException('hunter_domain_search_filters_invalid');
                }
                $normalized[$key] = $value;

                continue;
            }
            if (is_array($value)) {
                if (! $this->isScalarList($value)) {
                    throw new InvalidArgumentException('hunter_domain_search_filters_invalid');
                }
                $value = implode(',', array_map(static fn (mixed $part): string => trim((string) $part), $value));
            }
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('hunter_domain_search_filters_invalid');
            }
            $normalized[$key] = trim($value);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function validateUsageHistoryFilters(array $filters): array
    {
        $this->assertNoSensitiveKeys($filters, 'hunter_usage_history_filters_invalid');
        $this->assertOnlyKeys($filters, ['offset', 'limit', 'start_date', 'end_date', 'user_id'], 'hunter_usage_history_filters_invalid');
        if (isset($filters['offset']) && (! is_int($filters['offset']) || $filters['offset'] < 0 || $filters['offset'] > 999)) {
            throw new InvalidArgumentException('hunter_usage_history_filters_invalid');
        }
        if (isset($filters['limit']) && (! is_int($filters['limit']) || $filters['limit'] < 1 || $filters['limit'] > 100)) {
            throw new InvalidArgumentException('hunter_usage_history_filters_invalid');
        }
        if (isset($filters['user_id']) && (! is_int($filters['user_id']) || $filters['user_id'] <= 0)) {
            throw new InvalidArgumentException('hunter_usage_history_filters_invalid');
        }
        foreach (['start_date', 'end_date'] as $date) {
            if (isset($filters[$date]) && (! is_string($filters[$date]) || ! $this->validDate($filters[$date]))) {
                throw new InvalidArgumentException('hunter_usage_history_filters_invalid');
            }
        }

        return $filters;
    }

    private function localDiscover(): ProviderResponse
    {
        $rows = [];
        foreach ($this->fixtures() as $domain => $fixture) {
            if ($domain === '__default__' || ! is_array($fixture)) {
                continue;
            }
            $personal = 0;
            $generic = 0;
            foreach ((array) ($fixture['emails'] ?? []) as $email) {
                if (($email['type'] ?? null) === 'personal') {
                    $personal++;
                } elseif (($email['type'] ?? null) === 'generic') {
                    $generic++;
                }
            }
            $rows[] = [
                'domain' => $domain,
                'organization' => $fixture['organization'] ?? null,
                'emails_count' => ['personal' => $personal, 'generic' => $generic, 'total' => $personal + $generic],
            ];
        }

        return new ProviderResponse(200, $rows, [
            'source' => 'local',
            'offset' => 0,
            'limit' => count($rows),
            'filters' => [],
        ]);
    }

    private function localDomainFinder(string $company, int $limit, bool $perfectMatch): ProviderResponse
    {
        $matches = [];
        foreach ($this->fixtures() as $domain => $fixture) {
            if ($domain === '__default__' || ! is_array($fixture)) {
                continue;
            }
            $name = trim((string) ($fixture['organization'] ?? ''));
            $exact = mb_strtolower($name) === mb_strtolower($company);
            if (($perfectMatch && ! $exact) || (! $exact && ! str_contains(mb_strtolower($name), mb_strtolower($company)))) {
                continue;
            }
            $matches[] = [
                'domain' => $domain,
                'company_name' => $name,
                'email_count' => count((array) ($fixture['emails'] ?? [])),
                'perfect_match' => $exact,
            ];
        }
        $matches = array_slice($matches, 0, $limit);

        return new ProviderResponse(200, $matches, [
            'source' => 'local',
            'limit' => $limit,
        ]);
    }

    private function localDomainSearch(string $domain, int $limit, int $offset): ProviderResponse
    {
        $fixture = $this->fixtureForDomain($domain);
        if ($fixture === null) {
            return new ProviderResponse(200, ['domain' => null, 'emails' => []], [
                'source' => 'local', 'offset' => $offset, 'limit' => $limit,
            ]);
        }
        $fixture['domain'] = $domain;
        $emails = array_values(array_slice((array) ($fixture['emails'] ?? []), $offset, $limit));
        $fixture['emails'] = $emails;

        return new ProviderResponse(200, $fixture, [
            'source' => 'local', 'offset' => $offset, 'limit' => $limit,
        ]);
    }

    private function localCompanyEnrichment(string $domain): ProviderResponse
    {
        $fixture = $this->fixtureForDomain($domain);
        if ($fixture === null) {
            return new ProviderResponse(200, [], ['source' => 'local']);
        }

        return new ProviderResponse(200, [
            'name' => $fixture['organization'] ?? null,
            'domain' => $domain,
            'category' => ['industry' => $fixture['industry'] ?? null],
            'geo' => ['countryCode' => $fixture['country'] ?? null],
        ], ['source' => 'local']);
    }

    private function localEmailFinder(string $domain, string $fullName): ProviderResponse
    {
        $fixture = $this->fixtureForDomain($domain);
        foreach ((array) ($fixture['emails'] ?? []) as $email) {
            if (! is_array($email)) {
                continue;
            }
            $candidate = trim(implode(' ', array_filter([
                $email['first_name'] ?? null,
                $email['last_name'] ?? null,
            ], static fn (mixed $part): bool => is_string($part) && trim($part) !== '')));
            if ($candidate !== '' && mb_strtolower($candidate) === mb_strtolower($fullName)) {
                return new ProviderResponse(200, array_merge($email, ['domain' => $domain]), ['source' => 'local']);
            }
        }

        return new ProviderResponse(200, [], ['source' => 'local']);
    }

    private function localEmailVerifier(string $email): ProviderResponse
    {
        foreach ($this->fixtures() as $fixture) {
            if (! is_array($fixture)) {
                continue;
            }
            foreach ((array) ($fixture['emails'] ?? []) as $candidate) {
                if (! is_array($candidate) || strtolower((string) ($candidate['value'] ?? '')) !== $email) {
                    continue;
                }
                $status = data_get($candidate, 'verification.status');
                if (! in_array($status, self::TERMINAL_VERIFIER_STATUSES, true)) {
                    $status = match (data_get($candidate, 'verification.result')) {
                        'deliverable' => 'valid',
                        'undeliverable' => 'invalid',
                        default => 'unknown',
                    };
                }

                return new ProviderResponse(200, [
                    'email' => $email,
                    'status' => $status,
                    'score' => $candidate['confidence'] ?? null,
                ], ['source' => 'local']);
            }
        }

        return new ProviderResponse(200, ['email' => $email, 'status' => 'unknown'], ['source' => 'local']);
    }

    /** @return array<string, mixed> */
    private function fixtures(): array
    {
        $path = database_path('fixtures/discovery/hunter.json');
        if (! is_file($path)) {
            throw new ProviderRequestException('hunter_fixture_missing', false);
        }
        $fixtures = json_decode((string) file_get_contents($path), true);
        if (! is_array($fixtures)) {
            throw new ProviderRequestException('hunter_fixture_invalid', false);
        }

        return $fixtures;
    }

    /** @return array<string, mixed>|null */
    private function fixtureForDomain(string $domain): ?array
    {
        $fixtures = $this->fixtures();
        $exact = is_array($fixtures[$domain] ?? null);
        $fixture = $exact ? $fixtures[$domain] : ($fixtures['__default__'] ?? null);
        if (! is_array($fixture)) {
            return null;
        }
        if (! $exact) {
            $fixture['organization'] = null;
            $fixture['emails'] = array_map(static function (mixed $email) use ($domain): mixed {
                if (! is_array($email) || ! is_string($email['value'] ?? null)) {
                    return $email;
                }
                $localPart = strstr($email['value'], '@', true);
                if ($localPart !== false && $localPart !== '') {
                    $email['value'] = $localPart.'@'.$domain;
                }

                return $email;
            }, (array) ($fixture['emails'] ?? []));
        }

        return $fixture;
    }

    /** @param array<string, mixed> $body */
    private function verifierSpecialStatus(int $httpStatus, array $body): ?int
    {
        if (in_array($httpStatus, [222, 451], true)) {
            return $httpStatus;
        }

        $error = $this->firstError($body);
        $code = $error['code'] ?? null;
        $id = strtolower((string) ($error['id'] ?? ''));
        if ((int) $code === 222 || in_array(strtolower((string) $code), ['222', 'unexpected_smtp_response'], true)) {
            return 222;
        }
        if ((int) $code === 451 || in_array(strtolower((string) $code), ['451', 'claimed_email'], true) || $id === 'claimed_email') {
            return 451;
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function errorCode(int $httpStatus, array $body): string
    {
        $error = $this->firstError($body);
        $id = strtolower((string) ($error['id'] ?? ''));
        if ($httpStatus === 429) {
            return 'usage_limit';
        }
        if ($id !== '' && preg_match('/^[a-z0-9_:-]{1,64}$/', $id) === 1) {
            if ($id === 'claimed_email') {
                return 'claimed_email';
            }
            if ($httpStatus === 403 && str_contains($id, 'access')) {
                return 'permission_denied';
            }
            if ($httpStatus === 403 && str_contains($id, 'rate')) {
                return 'rate_limit';
            }

            return $id;
        }

        return match (true) {
            $httpStatus === 401 => 'authentication_failed',
            $httpStatus === 403 => 'permission_denied',
            $httpStatus === 429 => 'usage_limit',
            in_array($httpStatus, [400, 422], true) => 'invalid_request',
            $httpStatus === 404 => 'not_found',
            $httpStatus >= 500 => 'provider_unavailable',
            default => 'provider_http_error',
        };
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function firstError(array $body): array
    {
        $errors = $body['errors'] ?? [];
        if (! is_array($errors)) {
            return [];
        }
        if (array_key_exists('code', $errors) || array_key_exists('id', $errors)) {
            return $errors;
        }
        $first = Arr::first($errors);

        return is_array($first) ? $first : [];
    }

    private function requestId(Response $response): ?string
    {
        foreach (['X-Request-ID', 'Request-ID'] as $header) {
            $value = trim((string) $response->header($header));
            if (preg_match('/^[A-Za-z0-9._-]{1,191}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
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

    /** @param array<string, mixed> $payload @param list<string> $allowed */
    private function assertOnlyKeys(array $payload, array $allowed, string $code): void
    {
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new InvalidArgumentException($code);
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function assertNoSensitiveKeys(array $payload, string $code): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), [
                'api_key', 'authorization', 'token', 'password', 'url', 'full_url',
            ], true)) {
                throw new InvalidArgumentException($code);
            }
            if (is_array($value)) {
                $this->assertNoSensitiveKeys($value, $code);
            }
        }
    }

    /** @param list<string> $allowed */
    private function assertArrayFilter(mixed $value, array $allowed, string $code): void
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException($code);
        }
        $this->assertOnlyKeys($value, $allowed, $code);
    }

    private function validateLocationFilter(mixed $value, string $code): void
    {
        $this->assertArrayFilter($value, ['include', 'exclude'], $code);
        foreach (['include', 'exclude'] as $direction) {
            if (! isset($value[$direction]) || ! is_array($value[$direction])) {
                continue;
            }
            foreach ($value[$direction] as $location) {
                $this->assertArrayFilter($location, ['continent', 'business_region', 'country', 'state', 'city'], $code);
            }
        }
    }

    private function isScalarList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $part) {
            if (! is_scalar($part) || trim((string) $part) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function sanitizeUsageData(array $data): array
    {
        $safe = [];
        foreach (['reset_date', 'plan_name'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && $this->isSafeDisplayText($data[$key])) {
                $safe[$key] = $data[$key];
            }
        }

        $requests = is_array($data['requests'] ?? null) ? $data['requests'] : [];
        foreach (['credits', 'searches', 'verifications'] as $category) {
            $metrics = is_array($requests[$category] ?? null) ? $requests[$category] : [];
            foreach (['used', 'available', 'remaining'] as $metric) {
                if (isset($metrics[$metric]) && is_numeric($metrics[$metric])) {
                    $safe['requests'][$category][$metric] = $metrics[$metric] + 0;
                }
            }
        }

        return $safe;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function sanitizeUsageHistoryRow(array $row): array
    {
        $safe = [];
        if (isset($row['id']) && (is_int($row['id']) || (is_string($row['id']) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $row['id']) === 1))) {
            $safe['id'] = $row['id'];
        }
        foreach (['type', 'product', 'source'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $row[$key]) === 1) {
                $safe[$key] = $row[$key];
            }
        }
        if (isset($row['credits']) && is_numeric($row['credits'])) {
            $safe['credits'] = $row['credits'] + 0;
        }
        if (isset($row['made_at']) && is_string($row['made_at']) && preg_match('/^[0-9T:+. Z-]{1,64}$/', $row['made_at']) === 1) {
            $safe['made_at'] = $row['made_at'];
        }

        $madeBy = is_array($row['made_by'] ?? null) ? $row['made_by'] : [];
        if (isset($madeBy['id']) && (is_int($madeBy['id']) || (is_string($madeBy['id']) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $madeBy['id']) === 1))) {
            $safe['made_by'] = ['id' => $madeBy['id']];
        }

        return $safe;
    }

    /** @param array<string, mixed> $meta @return array<string, int> */
    private function sanitizeUsageHistoryMeta(array $meta): array
    {
        $safe = [];
        foreach (['total', 'limit', 'offset'] as $key) {
            if (isset($meta[$key]) && is_numeric($meta[$key])) {
                $safe[$key] = max(0, (int) $meta[$key]);
            }
        }

        return $safe;
    }

    private function isSafeDisplayText(string $value): bool
    {
        return mb_strlen($value) <= 191
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            && ! str_contains($value, '://')
            && ! str_contains($value, '@');
    }

    private function validateDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || mb_strlen($domain) > 253
            || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('hunter_domain_invalid');
        }

        return $domain;
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function isLocal(): bool
    {
        return config('services.hunter.driver', 'local') === 'local';
    }

    private function timeout(string $key): int
    {
        return max(1, min(120, (int) config('services.hunter.'.$key, 20)));
    }

    private function optionalTimeout(?int $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }
        if ($seconds < 1 || $seconds > 120) {
            throw new InvalidArgumentException('hunter_timeout_invalid');
        }

        return $seconds;
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('services.hunter.base_url', 'https://api.hunter.io/v2'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
