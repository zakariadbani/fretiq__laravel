<?php

namespace App\Services\Discovery\Engines;

interface DiscoveryEngineAdapter
{
    public function id(): string;

    public function label(): string;

    public function pageSize(): int;

    /** @return array<string, int|string> */
    public function params(string $query, int $start, array $cursorParams = []): array;

    /** @return array<string, string> */
    public function sanitizeCursorParams(array $cursorParams): array;

    /** @param array<string, mixed> $payload */
    public function parse(array $payload, string $query): DiscoveryPage;
}
