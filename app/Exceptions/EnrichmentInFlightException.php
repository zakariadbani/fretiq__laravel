<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by DiscoveryQuotaService::reserveManualEnrichment() when a manual
 * enrichment run is already in progress for the same company (running and not stale).
 *
 * Maps to a retryable 409 JSON response in the controller.
 */
class EnrichmentInFlightException extends RuntimeException
{
    public function __construct(
        string $message = 'Un enrichissement est déjà en cours pour cette entreprise.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
