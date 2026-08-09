<?php

namespace App\Services\Zoho\V2\Contracts;

use App\Services\Zoho\V2\DTO\TransportResult;
use DateTimeInterface;

/** Read-only boundary for the Zoho CRM API. */
interface ZohoTransport
{
    /** @param array<string, scalar|array|null> $query */
    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult;

    /**
     * Perform the sole supported conditional request. The transport owns all
     * headers so callers cannot override authentication or request identity.
     *
     * @param  array<string, scalar|array|null>  $query
     */
    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult;
}
