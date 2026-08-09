<?php

namespace App\Services\Zoho\V2\Transport;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class ZohoBulkReadTransportException extends RuntimeException
{
    public const TOKEN_EXPIRED = 'token_expired';

    public const TOKEN_INVALID = 'token_invalid';

    public const REQUEST_FAILED = 'request_failed';

    public const CAPACITY_DEFERRED = 'capacity_deferred';

    public const DOWNLOAD_FAILED = 'download_failed';

    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus,
        public readonly int $attempts,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function deferred(int $retryAfterSeconds, int $attempts = 0, int $httpStatus = 0): self
    {
        return new self(
            self::CAPACITY_DEFERRED,
            max(0, $httpStatus),
            max(0, $attempts),
            'Bulk Read request deferred.',
            max(1, min(43_200, $retryAfterSeconds)),
        );
    }

    public static function downloadFailed(int $attempts, int $httpStatus = 0): self
    {
        return new self(
            self::DOWNLOAD_FAILED,
            max(0, $httpStatus),
            max(0, $attempts),
            'Bulk Read download failed.',
        );
    }

    public static function fromResponse(Response $response, int $attempts): self
    {
        $payload = $response->json();
        $code = strtoupper((string) (data_get($payload, 'data.0.code') ?? data_get($payload, 'code') ?? ''));

        return match ($code) {
            'EXPIRED_PAGE_TOKEN' => new self(
                self::TOKEN_EXPIRED,
                $response->status(),
                $attempts,
                'Bulk Read continuation token expired.',
            ),
            'INVALID_PAGE_TOKEN' => new self(
                self::TOKEN_INVALID,
                $response->status(),
                $attempts,
                'Bulk Read continuation token is invalid.',
            ),
            default => new self(
                self::REQUEST_FAILED,
                $response->status(),
                $attempts,
                'Bulk Read request failed.',
            ),
        };
    }
}
