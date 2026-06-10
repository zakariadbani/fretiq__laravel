<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by DiscoveryQuotaService::reserveRun() when the criteria is inactive.
 *
 * Maps to a 422 JSON response in the controller.
 */
class CriteriaInactiveException extends RuntimeException
{
    public function __construct(
        string $message = 'Critère inactif — activez-le avant de lancer la découverte.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
