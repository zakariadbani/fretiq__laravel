<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncCampaignRecipientEventsJob;
use App\Jobs\SyncCampaignStatsJob;
use App\Jobs\SyncMailjetEventsJob;
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
 * Registered in the scheduler (routes/console.php): hourly, withoutOverlapping.
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

        $query = CampaignRun::query()->eligibleForStatsSync();

        if ($zohoOnly) {
            $query->whereNotNull('zoho_campaign_key');
        } elseif ($localOnly) {
            $query->whereNull('zoho_campaign_key');
        }

        // Use lazy() for memory-safe iteration over potentially large result sets.
        // cursor() cannot batch eager loads (it hydrates one row/query at a time),
        // so accessing $run->campaign inside the loop below was a true N+1 — lazy()
        // chunks internally and honours with(), so the eager load stays batched.
        // Only the columns used in the loop body are selected; campaign.delivery_channel
        // is needed to route mailjet runs (which never carry a zoho_campaign_key) to
        // SyncMailjetEventsJob instead of the Zoho recipient-event sync.
        $count = 0;
        foreach ($query->select('id', 'zoho_campaign_key', 'campaign_id')->with('campaign:id,delivery_channel,driver')->lazy() as $run) {
            SyncCampaignStatsJob::dispatch($run->id);
            if (filled($run->zoho_campaign_key)) {
                SyncCampaignRecipientEventsJob::dispatch($run->id);
            } elseif ($run->campaign?->effectiveDeliveryChannel() === 'mailjet') {
                SyncMailjetEventsJob::dispatch($run->id);
            }
            $count++;
        }

        if ($count === 0) {
            $this->info('Aucun run à synchroniser.');
        } else {
            $this->info("Dispatché {$count} job(s) de synchronisation.");
        }

        // ── Sequence-campaign lifecycle sweep ─────────────────────────────────
        // Atomic conditional UPDATE: non-continuous sequence campaigns that are
        // live (is_active=1), have at least one enrollment, and have NO remaining
        // active OR paused enrollments → set is_active=0 (campaign is drained).
        // Continuous campaigns keep their active state between daily batches so
        // newly eligible companies can be enrolled on a future business day.
        //
        // Paused enrollments (SequenceEnrollment.status='paused') are resumable,
        // so they block closure just like active ones. Only terminal statuses
        // (completed/stopped) are safe to ignore here.
        //
        // Single SQL statement — no read-then-write — so a concurrent launchSequence
        // (which inserts active enrollments before setting is_active=1) cannot
        // race this into a wrong closure.
        //
        // Eventual-consistent: ≤60 min lag before a fully-drained sequence
        // campaign reaches is_active=0. Re-launch sets is_active=1.
        $closed = DB::update("
            UPDATE campaigns
            SET is_active = 0
            WHERE schedule_type = 'sequence'
              AND is_active = 1
              AND sequence_auto_enroll_enabled = 0
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
