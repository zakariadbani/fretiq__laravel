<?php

namespace App\Jobs\Zoho;

use App\Services\Zoho\V2\Bulk\BulkBackfillStep;
use App\Services\Zoho\V2\Bulk\ZohoBulkBackfillService;
use App\Services\Zoho\V2\Bulk\ZohoBulkDeliveryHandoff;
use App\Services\Zoho\V2\Bulk\ZohoBulkRunTerminator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RunZohoBulkBackfillJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public readonly string $retryDeadline;

    public readonly string $runOwner;

    public readonly string $deliveryOwner;

    public readonly int $deliveryGeneration;

    public function __construct(
        public readonly int $batchId,
        public readonly string $module,
        public readonly string $correlationId,
        public readonly ?int $bulkJobId = null,
        ?string $retryDeadline = null,
        ?string $runOwner = null,
        ?string $deliveryOwner = null,
        int $deliveryGeneration = 0,
    ) {
        $this->tries = max(1, (int) config('zoho-v2.retry.max_attempts', 5));
        $this->timeout = max(60, (int) config('zoho-v2.bulk.delivery_timeout_seconds', 900));
        $this->retryDeadline = $retryDeadline
            ?? now()->addHours($this->runHorizonHours())->toIso8601String();
        $this->runOwner = $this->safeOwner($runOwner) ?? $this->deterministicRunOwner();
        $this->deliveryOwner = $this->safeOwner($deliveryOwner) ?? (string) Str::uuid();
        $this->deliveryGeneration = max(0, $deliveryGeneration);
        $this->onConnection((string) config('zoho-v2.queue_connection', 'zoho'));
        $this->onQueue((string) config('zoho-v2.queue', 'zoho'));
    }

    public function uniqueId(): string
    {
        return $this->batchId.':'.$this->module;
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('zoho-v2.bulk.lease_seconds', 1_500));
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

    public function handle(): void
    {
        if (! config('zoho-v2.features.sync_enabled', false)
            || ! config('zoho-v2.features.bulk_backfill_enabled', false)) {
            $this->terminate('kill_switch');

            return;
        }

        /** @var ZohoBulkBackfillService $service */
        $service = app(ZohoBulkBackfillService::class);
        $step = $service->step(
            $this->batchId,
            $this->module,
            $this->correlationId,
            $this->bulkJobId,
            $this->deliveryOwner,
            $this->runOwner,
            $this->deliveryGeneration,
        );

        if ($step->action === BulkBackfillStep::REDISPATCH) {
            app(ZohoBulkDeliveryHandoff::class)->reserveAndDispatch($this, $step);

            return;
        }

        if ($step->action === BulkBackfillStep::FAILED) {
            throw new RuntimeException('Zoho V2 Bulk backfill failed; see correlation ID.');
        }
    }

    public function failed(Throwable $exception): void
    {
        // Framework failed-job evidence is intentionally left untouched.
        try {
            $this->terminate('delivery_exhausted');
        } finally {
            Log::error('Zoho V2 Bulk backfill job exhausted.', [
                'batch_id' => $this->batchId,
                'module' => $this->module,
                'correlation_id' => $this->correlationId,
                'exception' => $exception::class,
            ]);
        }
    }

    private function terminate(string $reason): void
    {
        try {
            app(ZohoBulkRunTerminator::class)->terminate(
                $this->batchId,
                $this->module,
                $this->correlationId,
                $this->runOwner,
                (string) $this->deliveryOwner,
                $this->deliveryGeneration,
                $reason,
            );
        } catch (Throwable $exception) {
            Log::error('Zoho V2 Bulk backfill terminalization failed.', [
                'batch_id' => $this->batchId,
                'module' => $this->module,
                'correlation_id' => $this->correlationId,
                'exception' => $exception::class,
            ]);

            // Failed-job payloads retain exception chains and rendered stack
            // traces. Keep only a safe wrapper; the class-only log above is
            // enough for operations without leaking provider/SQL details.
            throw new RuntimeException('Zoho V2 Bulk terminalization failed; recovery remains pending.');
        }
    }

    public function continuation(?int $bulkJobId): self
    {
        return new self(
            $this->batchId,
            $this->module,
            $this->correlationId,
            $bulkJobId,
            $this->retryDeadline,
            $this->runOwner,
            null,
            $this->deliveryGeneration + 1,
        );
    }

    private function runHorizonHours(): int
    {
        $maxRecords = max(1, min(200_000, (int) config('zoho-v2.bulk.max_records_per_export', 200_000)));
        $requestsPerMinute = max(1, (int) config('zoho-v2.throttle.requests_per_minute', 90));
        $rateLimitedHours = (int) ceil(($maxRecords + 1) / $requestsPerMinute / 60);
        $configured = max(1, (int) config('zoho-v2.bulk.run_horizon_hours', 72));

        return max($configured, $rateLimitedHours + 12);
    }

    private function deterministicRunOwner(): string
    {
        $appKey = (string) config('app.key', 'fretiq-v2-local-key');

        return hash_hmac(
            'sha256',
            implode('|', [$this->batchId, $this->module, $this->correlationId]),
            $appKey,
        );
    }

    private function safeOwner(?string $owner): ?string
    {
        return is_string($owner) && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $owner) === 1
            ? $owner
            : null;
    }
}
