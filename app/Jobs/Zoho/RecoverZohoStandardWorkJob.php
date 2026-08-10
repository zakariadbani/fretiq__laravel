<?php

declare(strict_types=1);

namespace App\Jobs\Zoho;

use App\Services\Zoho\V2\Sync\ZohoStandardRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecoverZohoStandardWorkJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onConnection((string) config('zoho-v2.queue_connection', 'zoho'));
        $this->onQueue((string) config('zoho-v2.queue', 'zoho'));
    }

    public function uniqueId(): string
    {
        return 'standard-work-recovery';
    }

    public function uniqueFor(): int
    {
        return max(60, (int) config('zoho-v2.module.lease_seconds', 1500));
    }

    public function handle(ZohoStandardRecoveryService $recovery): void
    {
        $recovery->recover();
    }
}
