<?php

declare(strict_types=1);

namespace App\Jobs\Zoho;

use App\Models\Zoho\ZohoSyncBatch;
use App\Services\Zoho\V2\PostReconciliation\ZohoPostReconciliationProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class RunZohoPostReconciliationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public readonly string $retryDeadline;

    /** Immutable identity survives queue serialization and hard worker kills. */
    public readonly string $deliveryToken;

    public function __construct(public readonly int $batchId, ?string $retryDeadline = null, ?string $deliveryToken = null)
    {
        $this->tries = max(1, (int) config('zoho-v2.retry.max_attempts', 5));
        $this->timeout = max(60, (int) config('zoho-v2.module.delivery_timeout_seconds', 1200));
        $this->retryDeadline = $retryDeadline
            ?? now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12))->toIso8601String();
        $this->deliveryToken = $deliveryToken ?? 'post:'.Str::uuid();
        $this->onConnection((string) config('zoho-v2.queue_connection', 'zoho'));
        $this->onQueue((string) config('zoho-v2.queue', 'zoho'));
    }

    public function uniqueId(): string
    {
        return 'post-reconciliation:'.$this->batchId;
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('zoho-v2.module.lease_seconds', 1500));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('zoho-v2.retry.backoff_seconds', [60, 300, 900, 1800]);
    }

    public function retryUntil(): \DateTime
    {
        return CarbonImmutable::parse($this->retryDeadline)->toDateTime();
    }

    public function handle(ZohoPostReconciliationProcessor $processor): void
    {
        $owner = $this->deliveryToken;
        $claimedAttempt = DB::transaction(function () use ($owner): ?int {
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($this->batchId);
            if ($batch === null || $batch->completed_at === null
                || ! in_array($batch->status, ['success', 'partial'], true)
                || $batch->post_reconciliation_status === 'completed'
                || (int) $batch->post_reconciliation_attempts >= max(1, (int) config('zoho-v2.retry.max_attempts', 5))
                || ($batch->post_reconciliation_lease_expires_at?->isFuture() && $batch->post_reconciliation_lease_owner !== $owner)) {
                return null;
            }
            $batch->update([
                'post_reconciliation_status' => 'running',
                'post_reconciliation_lease_owner' => $owner,
                'post_reconciliation_lease_expires_at' => now()->addSeconds(max(60, $this->timeout + 30)),
                'post_reconciliation_started_at' => $batch->post_reconciliation_started_at ?? now(),
                'post_reconciliation_attempts' => ((int) $batch->post_reconciliation_attempts) + 1,
                'post_reconciliation_error' => null,
                'post_reconciliation_retry_not_before' => null,
                'post_reconciliation_retry_deadline_at' => $batch->post_reconciliation_retry_deadline_at
                    ?? CarbonImmutable::parse($this->retryDeadline),
            ]);

            return (int) $batch->post_reconciliation_attempts;
        });
        if ($claimedAttempt === null) {
            return;
        }

        try {
            $processor->process($this->batchId);
            ZohoSyncBatch::query()->whereKey($this->batchId)->where('post_reconciliation_lease_owner', $owner)
                ->where('post_reconciliation_attempts', $claimedAttempt)->update([
                    'post_reconciliation_status' => 'completed', 'post_reconciliation_lease_owner' => null,
                    'post_reconciliation_lease_expires_at' => null, 'post_reconciliation_completed_at' => now(),
                ]);
        } catch (Throwable $exception) {
            ZohoSyncBatch::query()->whereKey($this->batchId)->where('post_reconciliation_lease_owner', $owner)
                ->where('post_reconciliation_attempts', $claimedAttempt)->update([
                    // Laravel retains this same serialized delivery for its
                    // backoff chain. Persist its not-before boundary so the
                    // independent recovery sweeper cannot clone that chain.
                    'post_reconciliation_status' => 'retrying', 'post_reconciliation_lease_owner' => $owner,
                    'post_reconciliation_lease_expires_at' => null,
                    'post_reconciliation_retry_not_before' => now()->addSeconds($this->backoffForAttempt($claimedAttempt)),
                    'post_reconciliation_error' => 'Post-reconciliation processing failed.',
                ]);
            // Queue failure payloads retain exception text and stack traces.
            // Never propagate a vendor/processor exception because it can
            // contain CRM PII or SQL diagnostics. Durable batch state above
            // keeps the actionable retry evidence without raw details.
            throw new \RuntimeException('Zoho V2 post-reconciliation is retryable; see correlation ID.');
        }
    }

    public function failed(Throwable $exception): void
    {
        $query = ZohoSyncBatch::query()->whereKey($this->batchId)
            ->where('post_reconciliation_status', '!=', 'completed')
            ->where('post_reconciliation_lease_owner', $this->deliveryToken);
        $query->update([
            'post_reconciliation_status' => 'failed', 'post_reconciliation_lease_owner' => null,
            'post_reconciliation_lease_expires_at' => null,
            'post_reconciliation_error' => 'Post-reconciliation processing failed.',
            'post_reconciliation_retry_not_before' => null,
        ]);
        Log::channel('zoho')->error('Zoho V2 post-reconciliation job exhausted.', [
            'batch_id' => $this->batchId,
            'exception' => $exception::class,
        ]);
    }

    private function backoffForAttempt(int $attempt): int
    {
        $backoff = $this->backoff();

        return max(1, (int) ($backoff[min(max(0, $attempt - 1), max(0, count($backoff) - 1))] ?? 60));
    }
}
