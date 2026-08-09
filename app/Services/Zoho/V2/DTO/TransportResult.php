<?php

namespace App\Services\Zoho\V2\DTO;

/** A sanitized API response. It intentionally never contains an OAuth token or raw error body. */
final readonly class TransportResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $info
     * @param  array<string, string|null>  $headers
     * @param  list<TransportAttempt>  $attempts
     * @param  array<string, mixed>  $payload  Complete successful response only. It may contain CRM data and must never be logged.
     */
    public function __construct(
        public int $status,
        public array $data,
        public array $info,
        public array $headers,
        public string $correlationId,
        public array $attempts,
        public ?string $errorCode = null,
        public array $payload = [],
    ) {}

    public function successful(): bool
    {
        return $this->errorCode === null
            && (($this->status >= 200 && $this->status < 300) || $this->notModified());
    }

    public function notModified(): bool
    {
        return $this->status === 304;
    }

    /**
     * Return a top-level value from a successful Zoho response (for example
     * `data`, `modules`, `fields`, `layouts`, `related_lists`, or `users`).
     * Error response bodies are intentionally never retained.
     */
    public function root(string $key): mixed
    {
        return $this->payload[$key] ?? null;
    }

    public function apiRequestCount(): int
    {
        if ($this->attempts !== []) {
            return count($this->attempts);
        }

        // A local admission failure never reached Zoho. Test doubles and
        // manually constructed successful results otherwise represent one
        // transport attempt when they omit attempt telemetry.
        return $this->errorCode === 'throttle_unavailable' ? 0 : 1;
    }
}
