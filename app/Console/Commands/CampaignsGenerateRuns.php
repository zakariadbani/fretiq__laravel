<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Campaign\CampaignSchedulerService;
use Illuminate\Console\Command;

/**
 * CampaignsGenerateRuns — materialise CampaignRun rows for recurring campaigns.
 *
 * Runs every minute via the scheduler (routes/console.php) with withoutOverlapping().
 * Calls CampaignSchedulerService::generateDueRuns() which is idempotent via the
 * DB UNIQUE(campaign_id, occurrence_key) constraint.
 *
 * Signature: campaigns:generate-runs
 */
class CampaignsGenerateRuns extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'campaigns:generate-runs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Materialise CampaignRun rows for recurring campaigns whose next_run_at is due.';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $count = app(CampaignSchedulerService::class)->generateDueRuns();

        $this->info("{$count} exécution(s) de campagne récurrente générée(s).");

        return Command::SUCCESS;
    }
}
