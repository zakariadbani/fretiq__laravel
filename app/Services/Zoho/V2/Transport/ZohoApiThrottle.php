<?php

namespace App\Services\Zoho\V2\Transport;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A cache-backed admission gate shared by every Zoho HTTP client process.
 *
 * `execute` acquires a short-lived concurrency lock and reserves one request
 * from the current minute before invoking the callback. The lock is released
 * in a finally block, so callers must perform only one HTTP attempt inside
 * the callback; retry delays always happen outside this gate.
 */
final class ZohoApiThrottle
{
    private const PREFIX = 'zoho:v2:api-throttle:';

    public function __construct(
        private readonly ?Sleeper $sleeper = null,
        private readonly string $bucket = 'global',
        private readonly ?int $requestsPerMinute = null,
        private readonly ?int $maxConcurrentRequests = null,
    ) {
        if (preg_match('/^[a-z0-9-]{1,32}$/', $this->bucket) !== 1) {
            throw new \InvalidArgumentException('Zoho throttle bucket is invalid.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $attempt  Exactly one remote HTTP attempt.
     * @return T
     *
     * @throws ZohoThrottleUnavailableException When shared admission cannot be safely obtained.
     */
    public function execute(Closure $attempt): mixed
    {
        $concurrency = $this->positiveConfig('max_concurrent_requests');
        $perMinute = $this->positiveConfig('requests_per_minute');

        // A zero/invalid value explicitly disables that individual bound.
        if ($concurrency === null && $perMinute === null) {
            return $attempt();
        }

        $deadline = microtime(true) + ($this->acquireTimeoutMilliseconds() / 1000);
        $polls = 0;

        do {
            $slot = $concurrency === null ? null : $this->acquireSlot($concurrency);

            if ($concurrency !== null && $slot === null) {
                if (! $this->canRetryAdmission($deadline, ++$polls)) {
                    break;
                }

                $this->pauseAdmission();

                continue;
            }

            try {
                if ($perMinute !== null && ! $this->reserveMinute($perMinute)) {
                    if (! $this->canRetryAdmission($deadline, ++$polls)) {
                        break;
                    }

                    $this->pauseAdmission();

                    continue;
                }

                return $attempt();
            } finally {
                $slot?->release();
            }
        } while (true);

        throw new ZohoThrottleUnavailableException;
    }

    private function positiveConfig(string $key): ?int
    {
        $value = match ($key) {
            'requests_per_minute' => $this->requestsPerMinute
                ?? config('zoho-v2.throttle.requests_per_minute'),
            'max_concurrent_requests' => $this->maxConcurrentRequests
                ?? config('zoho-v2.throttle.max_concurrent_requests'),
            default => null,
        };

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function acquireSlot(int $limit): ?Lock
    {
        $ttlSeconds = $this->lockTtlSeconds();

        try {
            for ($slot = 1; $slot <= $limit; $slot++) {
                $lock = Cache::lock($this->prefix().'slot:'.$slot, $ttlSeconds);

                if ($lock->get()) {
                    return $lock;
                }
            }
        } catch (Throwable) {
            // Some cache stores cannot provide atomic locks. Do not fall back
            // to an unbounded local counter: that would defeat queue safety.
            return null;
        }

        return null;
    }

    private function reserveMinute(int $limit): bool
    {
        $window = intdiv(time(), 60);
        $key = $this->prefix().'minute:'.$window;
        $lock = null;
        $acquired = false;

        try {
            // File and database cache stores do not guarantee an atomic
            // add+increment sequence. Serialize the complete reservation,
            // separately from the longer-lived HTTP concurrency slot.
            $lock = Cache::lock($this->prefix().'minute-lock:'.$window, 5);
            $acquired = $lock->get();
            if (! $acquired) {
                return false;
            }

            $count = Cache::get($key, 0);
            if (! is_int($count) || $count >= $limit) {
                return false;
            }

            return Cache::put($key, $count + 1, 61) === true;
        } catch (Throwable) {
            // Rate reservation must be shared and atomic. Failing closed is
            // safer than silently making each queue worker independently hot.
            return false;
        } finally {
            if ($acquired) {
                $lock?->release();
            }
        }
    }

    private function acquireTimeoutMilliseconds(): int
    {
        $configured = config('zoho-v2.throttle.acquire_timeout_milliseconds', 5_000);

        return is_int($configured) ? min(30_000, max(0, $configured)) : 5_000;
    }

    private function pollMilliseconds(): int
    {
        $configured = config('zoho-v2.throttle.poll_milliseconds', 100);

        return is_int($configured) ? min(1_000, max(1, $configured)) : 100;
    }

    private function canRetryAdmission(float $deadline, int $polls): bool
    {
        $maximumPolls = (int) ceil($this->acquireTimeoutMilliseconds() / $this->pollMilliseconds());

        return $polls <= $maximumPolls && microtime(true) < $deadline;
    }

    private function pauseAdmission(): void
    {
        ($this->sleeper ?? new NativeSleeper)->sleepMilliseconds($this->pollMilliseconds());
    }

    private function lockTtlSeconds(): int
    {
        $configured = config('zoho-v2.throttle.lock_ttl_seconds', 120);

        return is_int($configured) ? min(300, max(1, $configured)) : 120;
    }

    private function prefix(): string
    {
        return self::PREFIX.$this->bucket.':';
    }
}
