<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by DiscoveryQuotaService::reserveRun() when GET_LOCK returns 0 or NULL.
 *
 * Fail-closed: never proceed without the serialization lock.
 * Maps to a retryable 409 JSON response in the controller.
 */
class QuotaLockUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'Quota lock unavailable — retry in a moment.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
