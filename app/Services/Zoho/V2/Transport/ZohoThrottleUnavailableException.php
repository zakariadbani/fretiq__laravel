<?php

namespace App\Services\Zoho\V2\Transport;

use RuntimeException;

/** A deliberately context-free failure: request metadata must never be exposed. */
final class ZohoThrottleUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('zoho_throttle_unavailable');
    }
}
