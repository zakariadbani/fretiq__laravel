<?php

namespace App\Services\Providers;

use InvalidArgumentException;

final readonly class ProviderCallContext
{
    public string $idempotencyKey;

    public float $reservedUnits;

    public ?int $batchId;

    public ?int $itemId;

    public ?string $engine;

    public function __construct(
        string $idempotencyKey,
        float $reservedUnits,
        ?int $batchId = null,
        ?int $itemId = null,
        ?string $engine = null,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('provider_idempotency_key_invalid');
        }

        if (! is_finite($reservedUnits) || $reservedUnits < 0 || $reservedUnits > 9999999999.99) {
            throw new InvalidArgumentException('provider_reserved_units_invalid');
        }

        foreach ([$batchId, $itemId] as $id) {
            if ($id !== null && $id <= 0) {
                throw new InvalidArgumentException('provider_context_id_invalid');
            }
        }

        if ($engine !== null && (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $engine) !== 1)) {
            throw new InvalidArgumentException('provider_engine_invalid');
        }

        $this->idempotencyKey = $idempotencyKey;
        $this->reservedUnits = round($reservedUnits, 2);
        $this->batchId = $batchId;
        $this->itemId = $itemId;
        $this->engine = $engine;
    }
}
