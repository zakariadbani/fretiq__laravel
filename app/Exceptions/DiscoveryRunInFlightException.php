<?php

namespace App\Exceptions;

use App\Models\DiscoveryRun;
use RuntimeException;

/**
 * Thrown by DiscoveryQuotaService::reserveRun() when an in-flight run already
 * exists for the criteria (pending|running and not stale).
 *
 * Carries the existing run so the controller can echo run_id and status back
 * in the 409 JSON response — matching the exact shape the JS panel expects.
 */
class DiscoveryRunInFlightException extends RuntimeException
{
    public function __construct(
        public readonly DiscoveryRun $existingRun,
        string $message = 'Une découverte est déjà en cours.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
