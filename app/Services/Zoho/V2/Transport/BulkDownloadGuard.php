<?php

namespace App\Services\Zoho\V2\Transport;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class BulkDownloadGuard
{
    public function __construct(private readonly int $maxBytes)
    {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('Bulk download byte limit is invalid.');
        }
    }

    public function assertHeaders(ResponseInterface $response): void
    {
        $value = trim($response->getHeaderLine('Content-Length'));
        if ($value !== ''
            && preg_match('/^[0-9]+$/', $value) === 1
            && (int) $value > $this->maxBytes) {
            $this->reject();
        }
    }

    public function assertProgress(
        int|float $downloadTotal,
        int|float $downloadedBytes,
        int|float $uploadTotal,
        int|float $uploadedBytes,
    ): void {
        if ($downloadedBytes > $this->maxBytes
            || ($downloadTotal > 0 && $downloadTotal > $this->maxBytes)) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new RuntimeException('Bulk Read download exceeded the configured archive limit.');
    }
}
