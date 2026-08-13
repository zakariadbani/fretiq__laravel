<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Package;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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
                'driver'             => 'local',
            ]
        );
        $this->info("  Campaign         id={$campaign->id}  name={$campaign->name}");

        // ── 5. Sequence + SequenceStep ────────────────────────────────────────

        $sequence = Sequence::firstOrCreate(
            ['name' => 'E2E_FIXTURE Sequence'],
            [
                'is_active'     => false,
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

        // Dedicated recipient-step statistics fixture. Fake Zoho keys exist only
        // to expose the manual sync control; E2E tests must never click it.
        $statsSequence = Sequence::updateOrCreate(
            ['name' => 'E2E_FIXTURE Sequence Statistics'],
            [
                'is_active' => false,
            ]
        );

        $statsStep1 = SequenceStep::updateOrCreate(
            ['sequence_id' => $statsSequence->id, 'step_no' => 1],
            [
                'template_id' => $template->id,
                'delay_days' => 0,
                'subject' => 'Introduction',
            ]
        );
        $statsStep2 = SequenceStep::updateOrCreate(
            ['sequence_id' => $statsSequence->id, 'step_no' => 2],
            [
                'template_id' => $template->id,
                'delay_days' => 3,
                'subject' => 'Relance',
            ]
        );

        $statsCampaign = Campaign::updateOrCreate(
            ['name' => 'E2E_FIXTURE Sequence Statistics Campaign'],
            [
                'segment_id' => $segment->id,
                'template_id' => $template->id,
                'sender_identity_id' => $sender->id,
                'sequence_id' => $statsSequence->id,
                'schedule_type' => 'sequence',
                'driver' => 'zoho',
            ]
        );

        $statsCompany = Company::updateOrCreate(
            ['domain' => 'e2e-sequence-statistics.example.test'],
            [
                'name' => 'E2E_FIXTURE Sequence Statistics Company',
                'country' => 'FR',
                'relationship' => 'prospect',
                'source' => 'manual',
                'qualification_status' => 'qualified',
            ]
        );
        $openedContact = Contact::updateOrCreate(
            ['email' => 'e2e.stats.opened@example.test'],
            [
                'company_id' => $statsCompany->id,
                'name' => 'E2E Opened Contact',
                'source' => 'manual',
                'email_kind' => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'e2e_fixture',
                'email_verification_checked_at' => now(),
            ]
        );
        $unsentContact = Contact::updateOrCreate(
            ['email' => 'e2e.stats.unsent@example.test'],
            [
                'company_id' => $statsCompany->id,
                'name' => 'E2E Unsent Contact',
                'source' => 'manual',
                'email_kind' => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'e2e_fixture',
                'email_verification_checked_at' => now(),
            ]
        );

        $statsRun1 = CampaignRun::updateOrCreate(
            ['campaign_id' => $statsCampaign->id, 'occurrence_key' => 'e2e-step-statistics-1'],
            [
                'sequence_step_id' => $statsStep1->id,
                'run_at' => now()->subDays(5),
                'status' => 'sent',
                'stats_sent' => 2,
                'stats_delivered' => 1,
                'stats_opened' => 1,
                'stats_clicked' => 0,
                'stats_bounced' => 0,
                'zoho_campaign_key' => 'e2e_fake_zoho_campaign_step_1',
                'finished_at' => now()->subDays(5),
            ]
        );
        $statsRun2 = CampaignRun::updateOrCreate(
            ['campaign_id' => $statsCampaign->id, 'occurrence_key' => 'e2e-step-statistics-2'],
            [
                'sequence_step_id' => $statsStep2->id,
                'run_at' => now()->subDays(2),
                'status' => 'sent',
                'stats_sent' => 1,
                'stats_delivered' => 1,
                'stats_opened' => 0,
                'stats_clicked' => 0,
                'stats_bounced' => 0,
                'zoho_campaign_key' => 'e2e_fake_zoho_campaign_step_2',
                'finished_at' => now()->subDays(2),
            ]
        );

        CampaignRecipient::updateOrCreate(
            ['campaign_run_id' => $statsRun1->id, 'contact_id' => $openedContact->id],
            ['status' => 'opened', 'sent_at' => now()->subDays(5), 'opened_at' => now()->subDays(4)]
        );
        CampaignRecipient::updateOrCreate(
            ['campaign_run_id' => $statsRun2->id, 'contact_id' => $openedContact->id],
            ['status' => 'sent', 'sent_at' => now()->subDays(2), 'opened_at' => null]
        );
        CampaignRecipient::updateOrCreate(
            ['campaign_run_id' => $statsRun1->id, 'contact_id' => $unsentContact->id],
            ['status' => 'skipped', 'skip_reason' => 'zoho_unsent', 'sent_at' => null, 'opened_at' => null]
        );
        $this->info("  Statistics campaign id={$statsCampaign->id}  runs=2  contacts=2");

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
                'daily_credits'         => 1,
                'daily_contact_credits' => 1,
                'price_monthly'         => 0.00,
                'is_active'             => false,
                'sort_order'            => 0,
            ]
        );
        $this->info("  Package          id={$package->id}  name={$package->name}");

        $manifestPath = base_path('tests/e2e/.auth/fixtures.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode([
            'senderIdentityId' => $sender->id,
            'segmentId' => $segment->id,
            'templateId' => $template->id,
            'campaignId' => $campaign->id,
            'statsCampaignId' => $statsCampaign->id,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->info('[e2e-seed] Done. All E2E_FIXTURE rows are in place.');

        return self::SUCCESS;
    }
}
