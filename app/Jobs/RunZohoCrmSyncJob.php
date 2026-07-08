<?php

namespace App\Jobs;

use App\Services\Zoho\CrmClientFactory;
use App\Services\Zoho\ZohoCrmSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunZohoCrmSyncJob — queued job that triggers ZohoCrmSyncService::sync().
 *
 * Dispatched by:
 *   - `zoho:sync-clients --queue` console command.
 *   - Future: a "Synchroniser" button on the Zoho screen (controller dispatch).
 *
 * Queue: default (database driver in fretiq).
 * Retry policy: 3 attempts, exponential backoff (60s, 120s).
 */
class RunZohoCrmSyncJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Maximum number of retry attempts before marking as failed. */
    public int $tries = 3;

    /**
     * Seconds the uniqueness lock is held before auto-releasing, so a crashed
     * job never blocks future syncs indefinitely. Covers the ~100 s full pull
     * with wide margin.
     *
     * @var int
     */
    public int $uniqueFor = 3600;

    /**
     * Max seconds the job may run before the worker force-kills it. The full
     * CRM pull (Accounts + Contacts) runs ~104s; 300s gives wide margin and
     * overrides the worker's default 60s --timeout on Linux (pcntl-enforced).
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Uniqueness key — one in-flight sync per module (or one global "all" sync).
     */
    public function uniqueId(): string
    {
        return $this->module ?? 'all';
    }

    public function __construct(
        private readonly ?string $module = null,
    ) {}

    /**
     * Calculate the backoff delay in seconds for each attempt.
     *
     * @return array<int>
     */
    public function backoff(): array
    {
        return [60, 120];
    }

    /**
     * Execute the sync job.
     *
     * Resolves ZohoCrmSyncService from the container so tests can swap the
     * binding. Falls back to CrmClientFactory::make() if no binding exists.
     */
    public function handle(): void
    {
        Log::info('[RunZohoCrmSyncJob] Starting sync', ['module' => $this->module ?? 'all']);

        /** @var ZohoCrmSyncService $service */
        $service = app()->has(ZohoCrmSyncService::class)
            ? app(ZohoCrmSyncService::class)
            : new ZohoCrmSyncService(CrmClientFactory::make());

        $service->sync($this->module);

        Log::info('[RunZohoCrmSyncJob] Sync completed', ['module' => $this->module ?? 'all']);
    }
}
