<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Package;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Console\Command;

/**
 * E2eSeed — creates a minimal fixture graph for Playwright e2e tests.
 *
 * Every row is prefixed with "E2E_FIXTURE" so fretiq:e2e-purge can
 * target them precisely without touching real data.
 *
 * The command is IDEMPOTENT — re-running it produces no duplicate rows
 * (firstOrCreate on the name/email key). Safe to run before each CI
 * test cycle.
 *
 * Usage:
 *   php artisan fretiq:e2e-seed
 *
 * WARNING: This mutates the live database. Run only in dev/test
 * environments. Never run in production.
 */
class E2eSeed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fretiq:e2e-seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed a minimal E2E_FIXTURE graph (idempotent) for Playwright tests. Dev/test only.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('[e2e-seed] Creating E2E_FIXTURE rows (idempotent via firstOrCreate)…');

        // ── 1. SenderIdentity ─────────────────────────────────────────────────

        $sender = SenderIdentity::firstOrCreate(
            ['email' => 'e2e_fixture_sender@example.test'],
            [
                'name'      => 'E2E_FIXTURE Sender',
                'is_active' => true,
            ]
        );
        $this->info("  SenderIdentity  id={$sender->id}  name={$sender->name}");

        // ── 2. Segment ────────────────────────────────────────────────────────

        $segment = Segment::firstOrCreate(
            ['name' => 'E2E_FIXTURE Segment'],
            [
                // 'prospect' is a valid scope (see segments migration: prospect|client|mixed)
                'scope'  => 'prospect',
                'filter' => null,
            ]
        );
        $this->info("  Segment         id={$segment->id}  name={$segment->name}");

        // ── 3. CampaignTemplate ───────────────────────────────────────────────

        $template = CampaignTemplate::firstOrCreate(
            ['name' => 'E2E_FIXTURE Template'],
            [
                'subject'      => 'E2E_FIXTURE Test Subject',
                'html_content' => '<p>E2E_FIXTURE test email body.</p>',
            ]
        );
        $this->info("  CampaignTemplate id={$template->id}  name={$template->name}");

        // ── 4. Campaign ───────────────────────────────────────────────────────
        // campaigns.segment_id, template_id, sender_identity_id are NOT NULL (no DB default).
        // schedule_type defaults to 'one_shot'; status defaults to 'draft'; driver to 'local'.

        $campaign = Campaign::firstOrCreate(
            ['name' => 'E2E_FIXTURE Campaign'],
            [
                'segment_id'         => $segment->id,
                'template_id'        => $template->id,
                'sender_identity_id' => $sender->id,
                'schedule_type'      => 'one_shot',
                'status'             => 'draft',
                'driver'             => 'local',
            ]
        );
        $this->info("  Campaign         id={$campaign->id}  name={$campaign->name}");

        // ── 5. Sequence + SequenceStep ────────────────────────────────────────

        $sequence = Sequence::firstOrCreate(
            ['name' => 'E2E_FIXTURE Sequence'],
            [
                'is_active'     => false,
                'stop_on_reply' => false,
            ]
        );
        $this->info("  Sequence         id={$sequence->id}  name={$sequence->name}");

        $step = SequenceStep::firstOrCreate(
            [
                'sequence_id' => $sequence->id,
                'step_no'     => 1,
            ],
            [
                'template_id' => $template->id,
                'delay_days'  => 0,
            ]
        );
        $this->info("  SequenceStep     id={$step->id}  sequence_id={$step->sequence_id}  step_no={$step->step_no}");

        // ── 6. ProspectCriteria ───────────────────────────────────────────────

        $criteria = ProspectCriteria::firstOrCreate(
            ['name' => 'E2E_FIXTURE Criteria'],
            [
                'is_active'   => false,
                'daily_limit' => 1,
            ]
        );
        $this->info("  ProspectCriteria id={$criteria->id}  name={$criteria->name}");

        // ── 7. Package ────────────────────────────────────────────────────────

        $package = Package::firstOrCreate(
            ['name' => 'E2E_FIXTURE Package'],
            [
                'daily_credits' => 1,
                'price_monthly' => 0.00,
                'is_active'     => false,
                'sort_order'    => 0,
            ]
        );
        $this->info("  Package          id={$package->id}  name={$package->name}");

        $this->info('[e2e-seed] Done. All E2E_FIXTURE rows are in place.');

        return self::SUCCESS;
    }
}
