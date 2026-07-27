<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaign\CampaignWaveZohoListSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCampaignWaveZohoListJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}
    public function uniqueId(): string { return (string) $this->runId; }
    public function uniqueFor(): int { return 600; }
    public function middleware(): array { return [(new WithoutOverlapping('zoho-wave-' . $this->runId))->dontRelease()]; }
    public function backoff(): array { return [10, 30, 60, 120]; }

    public function handle(CampaignWaveZohoListSyncService $service): void
    {
        $run = CampaignRun::find($this->runId);
        if ($run === null || ! str_starts_with($run->occurrence_key, 'sequence-wave-')) {
            return;
        }
        $service->sync($run);
        $run->refresh();
        if ($run->status === 'scheduled') {
            SendSequenceWaveStepJob::dispatch($run->id)->delay($run->run_at);
        }
    }

    public function failed(Throwable $exception): void
    {
        CampaignRun::whereKey($this->runId)->update(['status' => 'failed', 'driver_ref' => 'zoho-wave-failed', 'failure_reason' => mb_substr($exception->getMessage(), 0, 500)]);
        Log::error('[SyncCampaignWaveZohoListJob] Zoho wave mirror failed.', ['run_id' => $this->runId, 'exception' => $exception->getMessage()]);
    }
}