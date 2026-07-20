<?php

namespace App\Services\Discovery;

/**
 * Absolute deadline shared by every phase of one discovery-job attempt.
 *
 * The finalization reserve is deliberately removed up-front. Provider calls and
 * candidate work stop at workDeadlineAt(), leaving the queue attempt enough time
 * to persist its heartbeat and either release itself or terminalize the run.
 */
final class DiscoveryExecutionDeadline
{
    private readonly float $workDeadlineAt;

    public function __construct(
        private readonly float $startedAt,
        int $budgetSeconds,
        int $finalizationReserveSeconds = 10,
    ) {
        $workSeconds = max(0, $budgetSeconds - max(0, $finalizationReserveSeconds));
        $this->workDeadlineAt = $startedAt + $workSeconds;
    }

    public function workDeadlineAt(): float
    {
        return $this->workDeadlineAt;
    }

    public function remaining(?float $now = null): float
    {
        return max(0.0, $this->workDeadlineAt - ($now ?? microtime(true)));
    }

    public function isExhausted(?float $now = null): bool
    {
        return $this->remaining($now) <= 0.0;
    }

    public function canStartExternalCall(?float $now = null): bool
    {
        return $this->remaining($now) >= 1.0;
    }

    /**
     * Return a safe whole-second timeout, or null when no external call may start.
     */
    public function timeout(int $preferredSeconds, ?float $now = null): ?int
    {
        $remaining = $this->remaining($now);

        if ($remaining < 1.0) {
            return null;
        }

        return max(1, min(max(1, $preferredSeconds), (int) floor($remaining)));
    }
}
