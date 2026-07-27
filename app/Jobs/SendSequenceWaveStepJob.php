<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CampaignRun;
use App\Services\Campaign\SequenceWaveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendSequenceWaveStepJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('campaigns');
    }
    public function uniqueId(): string { return (string) $this->runId; }
    public function uniqueFor(): int { return 600; }
    public function middleware(): array { return [(new WithoutOverlapping('zoho-wave-send-' . $this->runId))->releaseAfter(30)]; }
    public function backoff(): array { return [10, 30, 60, 120]; }

    public function handle(SequenceWaveService $service): void
    {
        $run = CampaignRun::find($this->runId);
        if ($run === null || $run->sequence_step_id === null) {
            return;
        }
        if ($run->status === 'failed' && $run->driver_ref === 'zoho-send-uncertain') {
            throw new \RuntimeException('Envoi Zoho incertain : reconciliation manuelle requise avant retry.');
        }
        if ($run->status === 'failed') {
            if ($run->zoho_list_key) {
                $run->update(['status' => 'scheduled', 'failure_reason' => null]);
            } else {
                $run->update(['status' => 'prepared', 'failure_reason' => null]);
                SyncCampaignWaveZohoListJob::dispatch($run->id);
                return;
            }
        }
        if ($run->run_at->isFuture()) {
            $this->release(max(1, now()->diffInSeconds($run->run_at)));
            return;
        }
        $service->send($run);
    }

    public function failed(Throwable $exception): void
    {
        CampaignRun::whereKey($this->runId)->whereNotIn('status', ['sent', 'canceled'])->update([
            'status' => 'failed',
            'failure_reason' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }
}
