<?php

namespace App\Services\Discovery;

use ArrayAccess;
use LogicException;

/**
 * Immutable outcome for one resumable pipeline attempt.
 *
 * ArrayAccess intentionally exposes only the legacy statistics keys so existing
 * command/tests can migrate gradually without losing the typed lifecycle state.
 *
 * @implements ArrayAccess<string, int>
 */
final class DiscoveryPipelineResult implements ArrayAccess
{
    public readonly bool $needsContinuation;

    /**
     * @param  array{companies:int,contacts:int,skipped:int,low_score:int,new:int,contacts_consumed:int,excluded:int}  $stats
     */
    public function __construct(
        public readonly array $stats,
        public readonly bool $collectionComplete,
        public readonly bool $snapshotDrained,
    ) {
        $this->needsContinuation = ! ($collectionComplete && $snapshotDrained);
    }

    public function isComplete(): bool
    {
        return ! $this->needsContinuation;
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            ...$this->stats,
            'collection_complete' => $this->collectionComplete,
            'snapshot_drained' => $this->snapshotDrained,
            'needs_continuation' => $this->needsContinuation,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->stats);
    }

    public function offsetGet(mixed $offset): ?int
    {
        return is_string($offset) ? ($this->stats[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('DiscoveryPipelineResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('DiscoveryPipelineResult is immutable.');
    }
}
