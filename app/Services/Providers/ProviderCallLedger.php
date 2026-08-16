<?php

namespace App\Services\Providers;

use App\Models\ProviderCall;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProviderCallLedger
{
    private const MAX_ATTEMPTS = 4;

    private const MAX_UNITS = 9999999999.99;

    /** @var list<int> */
    private const RETRY_BACKOFF_SECONDS = [30, 120, 600, 1800];

    /** @var list<string> */
    private const SAFE_METADATA_KEYS = [
        'filters_hash', 'page', 'offset', 'limit', 'error_code', 'retryable', 'retry_after_seconds',
        'status', 'reason', 'provider_status', 'cursor_hash', 'query_hash', 'source', 'nested',
    ];

    /** @param Closure(): ProviderResponse $transport */
    public function execute(ProviderCallContext $context, string $provider, string $operation, Closure $transport): ProviderExecution
    {
        $this->validateOperation($provider, $operation);

        /** @var ProviderExecution $reserved */
        $reserved = DB::transaction(function () use ($context, $provider, $operation): ProviderExecution {
            $call = ProviderCall::query()
                ->where('provider', $provider)
                ->where('operation', $operation)
                ->where('idempotency_key', $context->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($call === null) {
                try {
                    $call = ProviderCall::query()->firstOrCreate([
                        'provider' => $provider,
                        'operation' => $operation,
                        'idempotency_key' => $context->idempotencyKey,
                    ], [
                        'prospect_batch_id' => $context->batchId,
                        'prospect_batch_item_id' => $context->itemId,
                        'engine' => $context->engine,
                        'status' => 'running',
                        'reserved_units' => $context->reservedUnits,
                        'attempt_count' => 1,
                        'started_at' => now(),
                    ]);
                } catch (QueryException $exception) {
                    if (! $this->isUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }

                    $call = ProviderCall::query()
                        ->where('provider', $provider)
                        ->where('operation', $operation)
                        ->where('idempotency_key', $context->idempotencyKey)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                if ($call->wasRecentlyCreated) {
                    return new ProviderExecution($call, null, false);
                }
            }

            $this->assertCompatibleContext($call, $context, $provider, $operation);

            if ($call->status === 'succeeded') {
                return new ProviderExecution($call, null, true);
            }

            if (in_array($call->status, ['running', 'reserved'], true)) {
                throw new ProviderRequestException('provider_call_in_progress', false);
            }

            if (! in_array($call->status, ['pending', 'retryable'], true)) {
                throw new ProviderRequestException('provider_call_not_replayable', false);
            }

            if ($call->retry_at !== null && $call->retry_at->isFuture()) {
                throw new ProviderRequestException('provider_call_retry_not_due', true, null, $call->retry_at->diffInSeconds(now()));
            }

            if ($call->attempt_count >= self::MAX_ATTEMPTS) {
                throw new ProviderRequestException('provider_call_retry_exhausted', false);
            }

            $call->forceFill([
                'status' => 'running',
                'attempt_count' => $call->attempt_count + 1,
                'started_at' => now(),
                'retry_at' => null,
            ])->save();

            return new ProviderExecution($call->fresh(), null, false);
        });

        if ($reserved->replayed) {
            return $reserved;
        }

        try {
            $response = $transport();
            if (! $response instanceof ProviderResponse) {
                throw new ProviderRequestException('provider_transport_invalid_response', false);
            }
            if ($response->httpStatus >= 400) {
                $safeCode = $this->safeCode($response->meta['error_code'] ?? null, 'provider_http_error');
                throw ProviderRequestException::fromHttp($response->httpStatus, $safeCode, $response->retryAfterSeconds, $provider);
            }

            return new ProviderExecution($reserved->call->fresh(), $response, false);
        } catch (ProviderRequestException $exception) {
            throw $this->recordFailure($reserved->call->id, $exception);
        } catch (Throwable) {
            $exception = new ProviderRequestException('provider_transport_failed', false);
            throw $this->recordFailure($reserved->call->id, $exception);
        }
    }

    /** @param array<string, mixed> $metadata */
    public function settle(ProviderExecution $execution, int $resultCount, float $consumedUnits, array $metadata = []): ProviderCall
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('provider_settlement_requires_transaction');
        }
        if ($resultCount < 0 || ! is_finite($consumedUnits) || $consumedUnits < 0 || $consumedUnits > self::MAX_UNITS) {
            throw new \InvalidArgumentException('provider_settlement_values_invalid');
        }

        $consumedUnits = round($consumedUnits, 2);

        $call = ProviderCall::query()->whereKey($execution->call->id)->lockForUpdate()->firstOrFail();
        if ($call->status === 'succeeded') {
            return $call;
        }
        if ($execution->replayed || $execution->response === null || $call->status !== 'running') {
            throw new ProviderRequestException('provider_call_not_settleable', false);
        }

        $response = $execution->response;
        $call->forceFill([
            'status' => 'succeeded',
            'http_status' => $response->httpStatus,
            'duration_ms' => max(0, $response->durationMs),
            'result_count' => $resultCount,
            'consumed_units' => $consumedUnits,
            'provider_request_id' => $this->safeRequestId($response->requestId),
            'metadata' => $this->mergeMetadata($response->meta, $metadata),
            'retry_at' => null,
            'finished_at' => now(),
        ])->save();

        return $call->fresh();
    }

    /** @param array<string, mixed> $metadata */
    public function markPending(ProviderExecution $execution, CarbonInterface $retryAt, array $metadata = []): ProviderCall
    {
        return DB::transaction(function () use ($execution, $retryAt, $metadata): ProviderCall {
            $call = ProviderCall::query()->whereKey($execution->call->id)->lockForUpdate()->firstOrFail();
            if ($call->status === 'pending') {
                return $call;
            }
            if ($execution->replayed || $execution->response === null || $call->status !== 'running') {
                throw new ProviderRequestException('provider_call_not_pending', false);
            }

            $response = $execution->response;
            $seconds = max(1, min(86400, now()->diffInSeconds($retryAt, false)));
            $call->forceFill([
                'status' => 'pending',
                'http_status' => $response->httpStatus,
                'duration_ms' => max(0, $response->durationMs),
                'provider_request_id' => $this->safeRequestId($response->requestId),
                'metadata' => $this->mergeMetadata($response->meta, $metadata),
                'retry_at' => now()->addSeconds($seconds),
                'finished_at' => null,
            ])->save();

            return $call->fresh();
        });
    }

    /**
     * Explicit operator recovery for a job that died with an uncertain remote
     * outcome. The review item records the actor and confirmation; this ledger
     * method stores only safe retry state.
     */
    public function authorizeUncertainRetryForItem(int $itemId): int
    {
        if ($itemId < 1) {
            throw new \InvalidArgumentException('provider_item_invalid');
        }

        return DB::transaction(function () use ($itemId): int {
            $calls = ProviderCall::query()
                ->where('prospect_batch_item_id', $itemId)
                ->where('status', 'running')
                ->lockForUpdate()
                ->get();

            if ($calls->isEmpty()) {
                throw new ProviderRequestException('provider_uncertain_call_missing', false);
            }

            foreach ($calls as $call) {
                if ($call->started_at === null || $call->started_at->gt(now()->subMinutes(2))) {
                    throw new ProviderRequestException('provider_call_still_active', true);
                }
                if ($call->attempt_count >= self::MAX_ATTEMPTS) {
                    throw new ProviderRequestException('provider_call_retry_exhausted', false);
                }

                $call->forceFill([
                    'status' => 'retryable',
                    'metadata' => $this->mergeMetadata($call->metadata ?? [], [
                        'error_code' => 'operator_retry_authorized',
                        'retryable' => true,
                        'source' => 'manual_review',
                    ]),
                    'retry_at' => now(),
                    'finished_at' => null,
                ])->save();
            }

            return $calls->count();
        });
    }

    /**
     * Explicitly reopens a confirmed failed read-only provider call after an
     * operator asks to retry the affected review item.
     */
    public function authorizeKnownFailureRetryForItem(int $itemId, string $itemErrorCode): int
    {
        if ($itemId < 1) {
            throw new \InvalidArgumentException('provider_item_invalid');
        }

        $allowedCodes = $this->allowedFailureCodesFor($itemErrorCode);
        if ($allowedCodes === []) {
            return 0;
        }

        return DB::transaction(function () use ($itemId, $allowedCodes): int {
            $calls = ProviderCall::query()
                ->where('prospect_batch_item_id', $itemId)
                ->where('status', 'failed')
                ->lockForUpdate()
                ->get()
                ->filter(fn (ProviderCall $call): bool => in_array(
                    data_get($call->metadata, 'error_code'),
                    $allowedCodes,
                    true,
                ));

            if ($calls->isNotEmpty() && $calls->every(fn (ProviderCall $call): bool => $call->attempt_count >= self::MAX_ATTEMPTS)) {
                throw new ProviderRequestException('provider_call_retry_exhausted', false);
            }

            $authorized = 0;
            foreach ($calls as $call) {
                if ($call->attempt_count >= self::MAX_ATTEMPTS) {
                    continue;
                }
                $call->forceFill([
                    'status' => 'retryable',
                    'metadata' => $this->mergeMetadata($call->metadata ?? [], [
                        'retryable' => true,
                        'reason' => 'operator_retry_authorized',
                        'source' => 'manual_review',
                    ]),
                    'retry_at' => now(),
                    'finished_at' => null,
                ])->save();
                $authorized++;
            }

            return $authorized;
        });
    }

    /**
     * True when this item has a provider_call still inside its own backoff
     * window. Deliberately item-wide, not scoped to the operation that
     * failed: a bulk drain must never nudge an item back to 'pending' while
     * any of its calls are mid-backoff, or the resulting job just re-hits
     * execute()'s 'provider_call_retry_not_due' guard immediately and burns
     * one of the job's own tries on a guaranteed rejection.
     */
    public function hasOpenRetryWindow(int $itemId): bool
    {
        return ProviderCall::query()
            ->where('prospect_batch_item_id', $itemId)
            ->where('retry_at', '>', now())
            ->exists();
    }

    /**
     * Read-only sibling of authorizeKnownFailureRetryForItem() for preview /
     * reporting only — never authorizes anything. Mirrors that method's
     * "every matching call must be maxed out to be exhausted" rule via the
     * MAX remaining headroom across matching calls (matching its use of
     * every() plus continue-on-maxed-calls in the authorize loop).
     *
     * Null means "not gated by the ledger" (no matching failed call, or an
     * error code the ledger doesn't recognise) — callers should treat that
     * as "proceed", matching authorizeKnownFailureRetryForItem() returning 0
     * without throwing in the same situation.
     */
    public function retryHeadroomForItem(int $itemId, string $itemErrorCode): ?int
    {
        $allowedCodes = $this->allowedFailureCodesFor($itemErrorCode);
        if ($allowedCodes === []) {
            return null;
        }

        $calls = ProviderCall::query()
            ->where('prospect_batch_item_id', $itemId)
            ->where('status', 'failed')
            ->get()
            ->filter(fn (ProviderCall $call): bool => in_array(
                data_get($call->metadata, 'error_code'),
                $allowedCodes,
                true,
            ));

        if ($calls->isEmpty()) {
            return null;
        }

        return (int) $calls->max(fn (ProviderCall $call): int => max(0, self::MAX_ATTEMPTS - $call->attempt_count));
    }

    /** @return list<string> */
    private function allowedFailureCodesFor(string $itemErrorCode): array
    {
        $canonicalCode = $itemErrorCode === 'too_many_requests' ? 'usage_limit' : $itemErrorCode;

        return match ($canonicalCode) {
            'usage_limit' => ['usage_limit', 'too_many_requests'],
            'rate_limit' => ['rate_limit'],
            'pagination_error' => ['pagination_error'],
            'provider_unavailable' => ['provider_unavailable', 'provider_transport_failed', 'provider_http_error'],
            'provider_call_not_replayable' => [
                'usage_limit', 'too_many_requests', 'rate_limit', 'pagination_error',
                'provider_unavailable', 'provider_transport_failed', 'provider_http_error',
            ],
            default => [],
        };
    }

    private function validateOperation(string $provider, string $operation): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,23}$/', $provider) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,47}$/', $operation) !== 1) {
            throw new \InvalidArgumentException('provider_operation_invalid');
        }
    }

    private function assertCompatibleContext(ProviderCall $call, ProviderCallContext $context, string $provider, string $operation): void
    {
        if ($call->provider !== $provider
            || $call->operation !== $operation
            || (int) $call->prospect_batch_id !== (int) $context->batchId
            || (int) $call->prospect_batch_item_id !== (int) $context->itemId
            || $call->engine !== $context->engine
            || (float) $call->reserved_units !== $context->reservedUnits) {
            throw new ProviderRequestException('provider_call_context_mismatch', false);
        }
    }

    private function recordFailure(int $callId, ProviderRequestException $exception): ProviderRequestException
    {
        /** @var array{0: ProviderRequestException, 1: ?ProviderCall} $failure */
        $failure = DB::transaction(function () use ($callId, $exception): array {
            $call = ProviderCall::query()->whereKey($callId)->lockForUpdate()->first();
            if ($call === null || $call->status !== 'running') {
                return [$exception, null];
            }

            $retryAfter = $exception->retryAfterSeconds
                ?? self::RETRY_BACKOFF_SECONDS[min(max($call->attempt_count - 1, 0), count(self::RETRY_BACKOFF_SECONDS) - 1)];
            $retryable = $exception->retryable && $call->attempt_count < self::MAX_ATTEMPTS;
            $call->forceFill([
                'status' => $retryable ? 'retryable' : 'failed',
                'http_status' => $exception->httpStatus,
                'metadata' => $this->mergeMetadata([], [
                    'error_code' => $exception->safeCode,
                    'retryable' => $retryable,
                    'retry_after_seconds' => $retryable ? $retryAfter : null,
                ]),
                'retry_at' => $retryable ? now()->addSeconds($retryAfter) : null,
                'finished_at' => $retryable ? null : now(),
            ])->save();

            return [
                new ProviderRequestException(
                    $exception->safeCode,
                    $retryable,
                    $exception->httpStatus,
                    $retryable ? $retryAfter : null,
                ),
                $call->fresh(),
            ];
        });

        [$effectiveException, $failedCall] = $failure;
        if ($failedCall !== null) {
            Log::warning('provider_call_failed', [
                'provider_call_id' => $failedCall->id,
                'provider' => $failedCall->provider,
                'operation' => $failedCall->operation,
                'prospect_batch_id' => $failedCall->prospect_batch_id,
                'prospect_batch_item_id' => $failedCall->prospect_batch_item_id,
                'status' => $failedCall->status,
                'http_status' => $failedCall->http_status,
                'error_code' => $effectiveException->safeCode,
                'retryable' => $effectiveException->retryable,
                'retry_after_seconds' => $effectiveException->retryAfterSeconds,
                'attempt_count' => $failedCall->attempt_count,
            ]);
        }

        return $effectiveException;
    }

    /** @param array<string, mixed> $responseMetadata @param array<string, mixed> $callerMetadata @return array<string, mixed> */
    private function mergeMetadata(array $responseMetadata, array $callerMetadata): array
    {
        return array_replace_recursive($this->safeMetadata($responseMetadata), $this->safeMetadata($callerMetadata));
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::SAFE_METADATA_KEYS, true)) {
                continue;
            }
            if (in_array($key, ['api_key', 'url', 'full_url', 'raw_response', 'email'], true)) {
                continue;
            }
            $sanitized = $this->safeValue($key, $value);
            if ($sanitized !== null) {
                $safe[$key] = $sanitized;
            }
        }

        return $safe;
    }

    private function safeValue(string $key, mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->safeCode($value, null);
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return null;
        }

        $safe = [];
        foreach (array_slice($value, 0, 20, true) as $key => $nested) {
            if (! is_string($key) || in_array($key, ['api_key', 'url', 'full_url', 'raw_response', 'email'], true)
                || ! in_array($key, self::SAFE_METADATA_KEYS, true)) {
                continue;
            }
            $sanitized = $this->safeValue($key, $nested);
            if ($sanitized !== null) {
                $safe[$key] = $sanitized;
            }
        }

        return $safe;
    }

    private function safeCode(mixed $value, ?string $fallback): ?string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $value = mb_substr($value, 0, 191);

        return preg_match('/^[A-Za-z0-9._:-]+$/', $value) === 1 ? $value : $fallback;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($sqlState === '23505') {
            return true;
        }

        return $sqlState === '23000' && in_array($driverCode, [19, 1062, 1555, 2067], true);
    }

    private function safeRequestId(?string $requestId): ?string
    {
        if ($requestId === null || preg_match('/^[A-Za-z0-9._-]{1,191}$/', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }
}
