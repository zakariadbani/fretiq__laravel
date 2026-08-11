<?php

namespace App\Services\Providers;

use RuntimeException;

final class ProviderRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($safeCode);
    }

    public static function fromHttp(
        int $httpStatus,
        string $safeCode = 'provider_request_failed',
        ?int $retryAfterSeconds = null,
        ?string $provider = null,
    ): self {
        $retryable = in_array($httpStatus, [408, 425, 429], true) || $httpStatus >= 500
            || ($httpStatus === 403 && $provider === 'hunter' && $safeCode === 'rate_limit');

        return new self($safeCode, $retryable, $httpStatus, self::normalizeRetryAfter($retryAfterSeconds));
    }

    public static function normalizeRetryAfter(?int $seconds): ?int
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return min($seconds, 86400);
    }
}
