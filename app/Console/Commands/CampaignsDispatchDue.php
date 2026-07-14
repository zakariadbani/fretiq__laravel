<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Campaign\CampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * CampaignsDispatchDue — find due CampaignRuns and dispatch SendCampaignJob.
 *
 * Runs every minute via the scheduler (routes/console.php).
 * Uses withoutOverlapping() at the scheduler level to prevent concurrent
 * dispatchers when the command takes longer than one minute.
 *
 * Signature: campaigns:dispatch-due
 */
class CampaignsDispatchDue extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'campaigns:dispatch-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch SendCampaignJob for all campaign runs that are due (status=scheduled, run_at<=now).';

    public function __construct(private CampaignService $campaignService)
    {
        parent::__construct();
    }

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        try {
            $count = $this->campaignService->dispatchDue();

            Setting::set('campaign_scheduler.commands.dispatch_due.last_success_at', now()->utc()->toIso8601String());

            $this->info("Dispatched {$count} due run(s).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('campaigns:dispatch-due failed: ' . $e->getMessage());
            Log::error('campaigns:dispatch-due failed', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
