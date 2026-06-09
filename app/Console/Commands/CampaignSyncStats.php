<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCampaignStatsJob;
use App\Models\CampaignRun;
use Illuminate\Console\Command;

/**
 * CampaignSyncStats — dispatch SyncCampaignStatsJob for all sent campaign runs.
 *
 * Targets runs with status='sent' (both Zoho and local-driver runs).
 * Zoho runs (zoho_campaign_key set) will call the Zoho Campaigns API — UNVERIFIED.
 * Local runs will recompute stats from campaign_recipients.
 *
 * Registered in the scheduler (routes/console.php): every 15 minutes, withoutOverlapping.
 *
 * Signature: campaign:sync-stats
 */
class CampaignSyncStats extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'campaign:sync-stats
                            {--zoho-only : Sync only Zoho-backed runs (with zoho_campaign_key)}
                            {--local-only : Sync only local-driver runs (without zoho_campaign_key)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronise les statistiques des campagnes envoyées (Zoho API + récapitulatif local).';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $zohoOnly  = (bool) $this->option('zoho-only');
        $localOnly = (bool) $this->option('local-only');

        if ($zohoOnly && $localOnly) {
            $this->error('--zoho-only and --local-only are mutually exclusive. Use at most one.');
            return self::FAILURE;
        }

        $query = CampaignRun::where('status', 'sent');

        if ($zohoOnly) {
            $query->whereNotNull('zoho_campaign_key');
        } elseif ($localOnly) {
            $query->whereNull('zoho_campaign_key');
        }

        // Use cursor() for memory-safe iteration over potentially large result sets.
        // Only id is needed in the loop body; select it explicitly to avoid hydrating all columns.
        $count = 0;
        foreach ($query->select('id')->cursor() as $run) {
            SyncCampaignStatsJob::dispatch($run->id);
            $count++;
        }

        if ($count === 0) {
            $this->info('Aucun run à synchroniser.');
            return Command::SUCCESS;
        }

        $this->info("Dispatché {$count} job(s) de synchronisation.");

        return Command::SUCCESS;
    }
}
