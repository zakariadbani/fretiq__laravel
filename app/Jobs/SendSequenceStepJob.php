<?php

namespace App\Jobs;

use App\Models\SequenceEnrollment;
use App\Services\Campaign\SequenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SendSequenceStepJob — processes one step of a drip sequence enrollment.
 *
 * Idempotency guarantees (queue-idempotency.md §3):
 *   - ShouldBeUnique: only one job per enrollment_id in the queue at any time.
 *   - WithoutOverlapping: prevents two workers executing the same enrollment concurrently.
 *   - SequenceService::sendStep() is idempotent via SequenceStepSend unique(enrollment_id, step_no)
 *     + provider_message_id presence check (crash-after-send safety).
 *   - Retry policy: 5 attempts, exponential backoff.
 */
class SendSequenceStepJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum number of retry attempts before marking as failed. */
    public int $tries = 5;

    /** Job timeout in seconds. */
    public int $timeout = 60;

    public function __construct(
        public readonly int $enrollmentId,
    ) {}

    // ── ShouldBeUnique ─────────────────────────────────────────────────────────

    /**
     * Unique cache key — prevents duplicate jobs for the same enrollment.
     */
    public function uniqueId(): string
    {
        return (string) $this->enrollmentId;
    }

    /**
     * Hold the unique lock for 300 seconds after dispatch.
     */
    public function uniqueFor(): int
    {
        return 300;
    }

    // ── WithoutOverlapping middleware ──────────────────────────────────────────

    /**
     * Prevent concurrent execution of the same enrollment_id.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->enrollmentId))->dontRelease(),
        ];
    }

    // ── Retry policy ───────────────────────────────────────────────────────────

    /**
     * Exponential backoff: 10 s, 30 s, 60 s, 120 s.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * Give up retrying after 6 hours from the first attempt.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(6)->toDateTime();
    }

    // ── Handle ─────────────────────────────────────────────────────────────────

    /**
     * Execute the drip step send.
     *
     * Resolves SequenceService from the container so tests can swap the binding.
     * Returns silently if the enrollment no longer exists (deleted concurrently).
     */
    public function handle(): void
    {
        $enrollment = SequenceEnrollment::find($this->enrollmentId);

        if ($enrollment === null) {
            Log::warning('[SendSequenceStepJob] SequenceEnrollment not found — discarding job.', [
                'enrollment_id' => $this->enrollmentId,
            ]);
            return;
        }

        // Guard against processing an enrollment that was stopped/completed between
        // dispatch and execution (e.g. manual unsubscribe, reply handler).
        if ($enrollment->status !== 'active') {
            Log::info('[SendSequenceStepJob] Enrollment no longer active — skipping.', [
                'enrollment_id' => $this->enrollmentId,
                'status'        => $enrollment->status,
            ]);
            return;
        }

        Log::info('[SendSequenceStepJob] Processing drip step.', [
            'enrollment_id' => $this->enrollmentId,
            'current_step'  => $enrollment->current_step,
        ]);

        app(SequenceService::class)->sendStep($enrollment);

        Log::info('[SendSequenceStepJob] Drip step completed.', [
            'enrollment_id' => $this->enrollmentId,
        ]);
    }
}
