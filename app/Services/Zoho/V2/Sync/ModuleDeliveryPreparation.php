<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Sync;

use InvalidArgumentException;

/** Immutable result of atomically preparing one standard queue delivery. */
final readonly class ModuleDeliveryPreparation
{
    private function __construct(
        public string $outcome,
        public ?int $generation = null,
    ) {}

    public static function prepared(int $generation): self
    {
        if ($generation < 1) {
            throw new InvalidArgumentException('A prepared Zoho delivery requires a positive generation.');
        }

        return new self('prepared', $generation);
    }

    public static function ignored(): self
    {
        return new self('ignored');
    }

    public static function conflict(): self
    {
        return new self('conflict');
    }

    public function isPrepared(): bool
    {
        return $this->outcome === 'prepared';
    }

    public function isIgnored(): bool
    {
        return $this->outcome === 'ignored';
    }

    public function isConflict(): bool
    {
        return $this->outcome === 'conflict';
    }
}
