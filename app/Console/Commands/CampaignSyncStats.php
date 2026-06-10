<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCampaignStatsJob;
use App\Models\CampaignRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        } else {
            $this->info("Dispatché {$count} job(s) de synchronisation.");
        }

        // ── Sequence-campaign lifecycle sweep ─────────────────────────────────
        // Atomic conditional UPDATE: sequence-type campaigns that are 'active',
        // have at least one enrollment, and have NO remaining active OR paused
        // enrollments → transition to 'done'.
        //
        // Paused enrollments are resumable (user can un-pause), so they block
        // closure just like active ones. Only terminal statuses (completed/stopped)
        // are safe to ignore here.
        //
        // Single SQL statement — no read-then-write — so a concurrent launchSequence
        // (which inserts active enrollments before setting status='active') cannot
        // race this into a wrong 'done'.
        //
        // Eventual-consistent: ≤15 min lag before a fully-drained sequence
        // campaign reaches 'done'. Re-launch from 'done' re-activates (§3.4).
        $closed = DB::update("
            UPDATE campaigns
            SET status = 'done'
            WHERE schedule_type = 'sequence'
              AND status = 'active'
              AND EXISTS (
                  SELECT 1 FROM sequence_enrollments se
                  WHERE se.campaign_id = campaigns.id
              )
              AND NOT EXISTS (
                  SELECT 1 FROM sequence_enrollments se2
                  WHERE se2.campaign_id = campaigns.id
                    AND se2.status IN ('active', 'paused')
              )
        ");

        $this->info("Séquence-campagnes lifecycle sweep effectué ({$closed} campagne(s) clôturée(s)).");

        return Command::SUCCESS;
    }
}
