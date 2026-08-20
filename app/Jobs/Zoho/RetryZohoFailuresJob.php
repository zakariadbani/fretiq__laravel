<?php

declare(strict_types=1);

namespace App\Jobs\Zoho;

use App\Services\Zoho\V2\Reconciliation\ZohoFailureRetryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RetryZohoFailuresJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly ?string $module, public readonly int $limit)
    {
        $this->tries = (int) config('zoho-v2.retry.max_attempts', 5);
        $this->timeout = max(60, min(
            900,
            ((int) config('queue.connections.zoho.retry_after', 1260)) - 60,
        ));
        $this->onConnection((string) config('zoho-v2.queue_connection', 'zoho'));
        $this->onQueue((string) config('zoho-v2.queue', 'zoho'));
    }

    public function uniqueId(): string
    {
        return 'retry:'.($this->module ?? 'all');
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('zoho-v2.bulk.lease_seconds', 1500));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('zoho-v2.retry.backoff_seconds', [60, 300, 900, 1800]);
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12))->toDateTime();
    }

    public function handle(): void
    {
        app(ZohoFailureRetryService::class)->retry($this->module, $this->limit);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('zoho')->error('Zoho V2 failure retry job exhausted.', ['module' => $this->module, 'exception' => $exception::class]);
    }
}
