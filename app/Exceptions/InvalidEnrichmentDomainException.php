<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidEnrichmentDomainException extends RuntimeException
{
    public const MISSING = 'missing';

    public const BLOCKED = 'blocked';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid enrichment domain: {$reason}");
    }
}
