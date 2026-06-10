<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the daily discovery credit balance is exhausted.
 *
 * Carries a user-facing French message suitable for returning directly
 * in a 422 JSON response.
 */
class QuotaExhaustedException extends RuntimeException
{
    public function __construct(
        string $message = 'Solde du jour épuisé — recharge demain à minuit.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
