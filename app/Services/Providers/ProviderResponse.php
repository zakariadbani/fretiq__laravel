<?php

namespace App\Services\Providers;

use InvalidArgumentException;

final readonly class ProviderResponse
{
    /** @param array<string, mixed> $data @param array<string, mixed> $meta */
    public function __construct(
        public int $httpStatus,
        public array $data,
        public array $meta = [],
        public ?string $requestId = null,
        public int $durationMs = 0,
        public ?int $retryAfterSeconds = null,
    ) {
        if ($this->httpStatus < 100 || $this->httpStatus > 599) {
            throw new InvalidArgumentException('provider_http_status_invalid');
        }
        if ($this->durationMs < 0) {
            throw new InvalidArgumentException('provider_duration_invalid');
        }
        if ($this->retryAfterSeconds !== null && ($this->retryAfterSeconds < 1 || $this->retryAfterSeconds > 86400)) {
            throw new InvalidArgumentException('provider_retry_after_invalid');
        }
        if ($this->requestId !== null && preg_match('/[\x00-\x1F\x7F]/', $this->requestId) === 1) {
            throw new InvalidArgumentException('provider_request_id_invalid');
        }
    }
}
