<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Package;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E2ePurge — deletes ONLY E2E_FIXTURE-prefixed rows created by fretiq:e2e-seed.
 *
 * Deletion order is CHILD → PARENT to respect RESTRICT foreign keys:
 *
 *   campaigns             (references segments, campaign_templates, sender_identities)
 *   sequence_steps        (child of sequences via CASCADE — purged via parent sequences)
 *   sequences
 *   prospect_criteria
 *   packages
 *   segments
 *   campaign_templates    (referenced by campaigns + sequence_steps — safe after both gone)
 *   sender_identities     (referenced by campaigns — safe after campaigns gone)
 *
 * Notes:
 *   - sequence_steps rows are deleted automatically when the parent sequence is
 *     deleted (CASCADE on sequence_id). The purge therefore does NOT need a
 *     separate sequence_steps DELETE — deleting the E2E_FIXTURE Sequence row
 *     cascades to its steps.
 *   - package_assignments (child of packages) are deleted via the packages CASCADE
 *     only if the FK is set to CASCADE; otherwise a separate targeted delete on
 *     package_assignments belonging to fixture packages runs first.
 *
 * WARNING: This mutates the live database. Run only in dev/test environments.
 * Never run in production.
 */
class E2ePurge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fretiq:e2e-purge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all E2E_FIXTURE rows created by fretiq:e2e-seed. Dev/test only.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('[e2e-purge] Deleting E2E_FIXTURE rows (child → parent order)…');

        DB::transaction(function () {

            // ── 1. Campaigns (references segments, campaign_templates, sender_identities) ──

            $campaignCount = Campaign::where('name', 'like', 'E2E_FIXTURE%')->count();
            Campaign::where('name', 'like', 'E2E_FIXTURE%')->delete();
            $this->info("  campaigns            deleted={$campaignCount}");

            $fixtureContactEmails = [
                'e2e.stats.opened@example.test',
                'e2e.stats.unsent@example.test',
            ];
            $contactCount = Contact::withTrashed()->whereIn('email', $fixtureContactEmails)->count();
            Contact::withTrashed()->whereIn('email', $fixtureContactEmails)->forceDelete();
            $this->info("  contacts             deleted={$contactCount}");

            $companyCount = Company::where('domain', 'e2e-sequence-statistics.example.test')->count();
            Company::where('domain', 'e2e-sequence-statistics.example.test')->delete();
            $this->info("  companies            deleted={$companyCount}");

            // ── 2. Sequence steps (child of sequences, CASCADE) ───────────────────────────
            // sequence_steps.sequence_id → sequences CASCADE — deleting fixture sequences
            // below will cascade and remove their steps automatically.
            // We still report an explicit count for transparency.

            $fixtureSequenceIds = Sequence::where('name', 'like', 'E2E_FIXTURE%')->pluck('id');
            $stepCount = 0;
            if ($fixtureSequenceIds->isNotEmpty()) {
                $stepCount = DB::table('sequence_steps')
                    ->whereIn('sequence_id', $fixtureSequenceIds)
                    ->count();
            }

            // ── 3. Sequences (CASCADE deletes their steps) ────────────────────────────────

            $sequenceCount = Sequence::where('name', 'like', 'E2E_FIXTURE%')->count();
            Sequence::where('name', 'like', 'E2E_FIXTURE%')->delete();
            $this->info("  sequences            deleted={$sequenceCount}");
            $this->info("  sequence_steps       deleted={$stepCount} (via CASCADE)");

            // ── 4. ProspectCriteria ───────────────────────────────────────────────────────

            $criteriaCount = ProspectCriteria::where('name', 'like', 'E2E_FIXTURE%')->count();
            ProspectCriteria::where('name', 'like', 'E2E_FIXTURE%')->delete();
            $this->info("  prospect_criteria    deleted={$criteriaCount}");

            // ── 5. Packages (delete package_assignments for fixture packages first) ────────

            $fixturePackageIds = \App\Models\Package::where('name', 'like', 'E2E_FIXTURE%')->pluck('id');
            $assignmentCount = 0;
            if ($fixturePackageIds->isNotEmpty()) {
                $assignmentCount = DB::table('package_assignments')
                    ->whereIn('package_id', $fixturePackageIds)
                    ->count();
                DB::table('package_assignments')
                    ->whereIn('package_id', $fixturePackageIds)
                    ->delete();
            }
            $this->info("  package_assignments  deleted={$assignmentCount} (children of fixture packages)");

            $packageCount = Package::where('name', 'like', 'E2E_FIXTURE%')->count();
            Package::where('name', 'like', 'E2E_FIXTURE%')->delete();
            $this->info("  packages             deleted={$packageCount}");

            // ── 6. Segments ───────────────────────────────────────────────────────────────

            $segmentCount = Segment::where('name', 'like', 'E2E_FIXTURE%')->count();
            Segment::where('name', 'like', 'E2E_FIXTURE%')->delete();
            $this->info("  segments             deleted={$segmentCount}");

            // ── 7. CampaignTemplates (safe now: campaigns + sequence_steps are gone) ──────

            $templateCount = \App\Models\CampaignTemplate::where('name', 'like', 'E2E_FIXTURE%')->count();
            CampaignTemplate::where('name', 'like', 'E2E_FIXTURE%')->delete();
            $this->info("  campaign_templates   deleted={$templateCount}");

            // ── 8. SenderIdentities (safe now: campaigns are gone) ────────────────────────

            $senderCount = \App\Models\SenderIdentity::where('name', 'like', 'E2E_FIXTURE%')->count();
            SenderIdentity::where('name', 'like', 'E2E_FIXTURE%')->delete();
            $this->info("  sender_identities    deleted={$senderCount}");
        });

        $this->info('[e2e-purge] Done. All E2E_FIXTURE rows removed.');

        return self::SUCCESS;
    }
}
