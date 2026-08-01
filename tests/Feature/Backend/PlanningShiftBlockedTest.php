<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * PlanningShiftBlockedTest — `planning:shift-blocked`.
 *
 * Runs the command against the TEST database only (RefreshDatabase). Per the
 * command's own docblock and the project rules, this command must NEVER be
 * run against a real database without explicit user authorisation — this
 * test suite is the one sanctioned execution path.
 *
 * Carbon convention: every Carbon::setTestNow() call below is pinned in UTC.
 * A non-UTC mock (e.g. 'Europe/Paris') silently changes the DEFAULT timezone
 * that createFromFormat() falls back to, corrupting every Eloquent
 * 'datetime'-cast retrieval during the mock by the UTC offset — see
 * SequenceProcessTest for the same rule applied elsewhere in this suite.
 */
class PlanningShiftBlockedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic baseline: skip_weekends on, no blackout dates, unless
        // a test overrides one or both.
        Setting::set('planification.skip_weekends', true);
        Setting::set('planification.blackout_dates', '');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────

    private function makeCampaign(array $overrides = []): Campaign
    {
        $segment = Segment::create(['name' => 'Seg ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl ' . uniqid(),
            'subject'      => 'Sujet',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'Sender ' . uniqid(),
            'email' => uniqid() . '@tcl.test',
        ]);

        return Campaign::create(array_merge([
            'name'               => 'Campagne ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'is_active'          => true,
            'timezone'           => 'UTC',
        ], $overrides));
    }

    private function makeCampaignRun(?Campaign $campaign, array $overrides = []): CampaignRun
    {
        return CampaignRun::create(array_merge([
            'campaign_id'    => $campaign?->id,
            'occurrence_key' => 'run-' . uniqid(),
            'run_at'         => Carbon::now(),
            'status'         => 'scheduled',
        ], $overrides));
    }

    private function makeSequenceEnrollment(?Campaign $campaign, array $overrides = []): SequenceEnrollment
    {
        $sequence = Sequence::create(['name' => 'Seq ' . uniqid(), 'is_active' => true, 'stop_on_reply' => false]);

        $company = Company::create([
            'name'                 => 'Acme ' . uniqid(),
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id'  => $company->id,
            'email'       => uniqid() . '@acme.test',
            'name'        => 'Contact',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        return SequenceEnrollment::create(array_merge([
            'sequence_id'  => $sequence->id,
            'contact_id'   => $contact->id,
            'campaign_id'  => $campaign?->id,
            'current_step' => 1,
            'status'       => 'active',
            'next_send_at' => Carbon::now(),
        ], $overrides));
    }

    /**
     * Run the command via Artisan::call and return [exitCode, output].
     *
     * Passes an explicit BufferedOutput as the third argument rather than
     * relying on Artisan::output() (which reads Illuminate\Console\
     * Application::$lastOutput via a one-shot BufferedOutput::fetch()).
     * RefreshDatabase's own migrate:fresh bootstrap runs through
     * $this->artisan(...) with a mocked OutputStyle and then calls
     * Kernel::setArtisan(null) on the very first test, which left
     * Artisan::output() returning an empty string for that first call in
     * the class. Owning the buffer directly sidesteps that shared state
     * entirely.
     *
     * @param array<string, mixed> $params
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $params = []): array
    {
        // Defensive: when this is the very first test in the whole suite to
        // need a real migrate:fresh, RefreshDatabase runs it through
        // Illuminate\Testing\PendingCommand::mockConsoleOutput(), which binds
        // a partial Mockery OutputStyle (built around ITS OWN input/output)
        // into the container. That binding ignores the constructor
        // parameters passed to any later `make(OutputStyle::class, [...])`
        // call, so every subsequent Illuminate\Console\Command::run() —
        // including ours — would silently write to that stale buffer
        // instead of the BufferedOutput we pass to Artisan::call() below.
        // Clearing it first guarantees a fresh OutputStyle wraps OUR buffer.
        $this->app->offsetUnset(OutputStyle::class);

        $output = new BufferedOutput();
        $exit = Artisan::call('planning:shift-blocked', $params, $output);

        return [$exit, $output->fetch()];
    }

    /**
     * Extract [examined, shifted, unchanged] from the printed tally table for
     * a given row label, e.g. 'campaigns.next_run_at'.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function tallyFor(string $output, string $rowLabel): array
    {
        $pattern = '/\|\s*' . preg_quote($rowLabel, '/') . '\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|/';
        $this->assertMatchesRegularExpression($pattern, $output, "Row '{$rowLabel}' not found in command output:\n{$output}");
        preg_match($pattern, $output, $m);

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    // ── campaigns.next_run_at ────────────────────────────────────────────────────

    public function test_weekend_next_run_at_shifts_to_monday_at_the_same_local_wall_time(): void
    {
        $campaign = $this->makeCampaign([
            'timezone'    => 'UTC',
            'next_run_at' => Carbon::parse('2026-08-01 09:00:00', 'UTC'), // Saturday
        ]);

        [$exit, $output] = $this->runCommand(['--only' => 'campaigns']);

        $this->assertSame(0, $exit);
        $this->assertSame([1, 1, 0], $this->tallyFor($output, 'campaigns.next_run_at'));

        $campaign->refresh();
        $this->assertSame('2026-08-03 09:00:00', $campaign->next_run_at->format('Y-m-d H:i:s')); // Monday, same wall time
    }

    public function test_blackout_date_row_shifts_too(): void
    {
        Setting::set('planification.skip_weekends', false);
        Setting::set('planification.blackout_dates', '2026-08-05'); // Wednesday

        $campaign = $this->makeCampaign([
            'timezone'    => 'UTC',
            'next_run_at' => Carbon::parse('2026-08-05 09:00:00', 'UTC'),
        ]);

        [$exit, $output] = $this->runCommand(['--only' => 'campaigns']);

        $this->assertSame(0, $exit);
        $this->assertSame([1, 1, 0], $this->tallyFor($output, 'campaigns.next_run_at'));

        $campaign->refresh();
        $this->assertSame('2026-08-06 09:00:00', $campaign->next_run_at->format('Y-m-d H:i:s')); // Thursday
    }

    public function test_already_allowed_row_is_left_byte_identical_and_stays_so_on_a_second_run(): void
    {
        $campaign = $this->makeCampaign([
            'timezone'    => 'UTC',
            'next_run_at' => Carbon::parse('2026-08-04 09:00:00', 'UTC'), // Tuesday, already allowed
        ]);
        $originalRaw = $campaign->getRawOriginal('next_run_at');

        [$exit1, $output1] = $this->runCommand(['--only' => 'campaigns']);
        $this->assertSame(0, $exit1);
        $this->assertSame([1, 0, 1], $this->tallyFor($output1, 'campaigns.next_run_at'));

        $campaign->refresh();
        $this->assertSame($originalRaw, $campaign->getRawOriginal('next_run_at'));

        // Idempotence: running it again must report the same "unchanged" outcome.
        [$exit2, $output2] = $this->runCommand(['--only' => 'campaigns']);
        $this->assertSame(0, $exit2);
        $this->assertSame([1, 0, 1], $this->tallyFor($output2, 'campaigns.next_run_at'));

        $campaign->refresh();
        $this->assertSame($originalRaw, $campaign->getRawOriginal('next_run_at'));
    }

    public function test_dry_run_writes_nothing_but_reports_the_same_counts_it_would_have_applied(): void
    {
        $dryCampaign = $this->makeCampaign([
            'timezone'    => 'UTC',
            'next_run_at' => Carbon::parse('2026-08-01 09:00:00', 'UTC'), // Saturday
        ]);
        $originalRaw = $dryCampaign->getRawOriginal('next_run_at');

        [$exit, $output] = $this->runCommand(['--only' => 'campaigns', '--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertSame([1, 1, 0], $this->tallyFor($output, 'campaigns.next_run_at'));

        // Zero writes: the row is untouched.
        $dryCampaign->refresh();
        $this->assertSame($originalRaw, $dryCampaign->getRawOriginal('next_run_at'));

        // Same scenario, real run: identical counts, but this time it writes.
        $realCampaign = $this->makeCampaign([
            'timezone'    => 'UTC',
            'next_run_at' => Carbon::parse('2026-08-01 09:00:00', 'UTC'),
        ]);

        [$exitReal, $outputReal] = $this->runCommand(['--only' => 'campaigns']);
        $this->assertSame(0, $exitReal);
        // Both the dry-run row and the real row are still Saturday-scoped at
        // this point (the dry-run never wrote), so both campaigns are
        // examined and shifted here — assert the per-run tally shape matches.
        [$examined, $shifted, $unchanged] = $this->tallyFor($outputReal, 'campaigns.next_run_at');
        $this->assertSame(2, $examined); // dryCampaign (still Saturday) + realCampaign
        $this->assertSame(2, $shifted);
        $this->assertSame(0, $unchanged);

        $realCampaign->refresh();
        $this->assertSame('2026-08-03 09:00:00', $realCampaign->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_non_utc_campaign_timezone_shifts_correctly_across_a_dst_boundary(): void
    {
        // Mirrors BusinessCalendarServiceTest's DST scenario: Friday is
        // force-blocked via the blackout list so the walk crosses the EU
        // spring-forward transition on Sunday 2026-03-29.
        Setting::set('planification.skip_weekends', true);
        Setting::set('planification.blackout_dates', '2026-03-27'); // Friday

        $campaign = $this->makeCampaign([
            'timezone'    => 'Europe/Paris',
            'next_run_at' => Carbon::parse('2026-03-27 08:00:00', 'UTC'), // 09:00 CET
        ]);

        [$exit, $output] = $this->runCommand(['--only' => 'campaigns']);

        $this->assertSame(0, $exit);
        $this->assertSame([1, 1, 0], $this->tallyFor($output, 'campaigns.next_run_at'));

        $campaign->refresh();
        // Same local wall time (09:00) preserved across the DST jump, so the
        // UTC instant moves from 08:00 (CET, UTC+1) to 07:00 (CEST, UTC+2).
        $this->assertSame('2026-03-30 07:00:00', $campaign->next_run_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-30 09:00', $campaign->next_run_at->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
    }

    // ── campaign_runs.run_at ─────────────────────────────────────────────────────

    public function test_campaign_runs_sending_sent_and_past_rows_are_not_touched(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC')); // Monday "now"

        $campaign = $this->makeCampaign(['timezone' => 'UTC']);

        $sendingRun = $this->makeCampaignRun($campaign, [
            'status' => 'sending',
            'run_at' => Carbon::parse('2026-08-15 09:00:00', 'UTC'), // future Saturday
        ]);
        $sentRun = $this->makeCampaignRun($campaign, [
            'status' => 'sent',
            'run_at' => Carbon::parse('2026-08-15 09:00:00', 'UTC'), // future Saturday
        ]);
        $pastRun = $this->makeCampaignRun($campaign, [
            'status' => 'scheduled',
            'run_at' => Carbon::parse('2026-08-01 09:00:00', 'UTC'), // past Saturday (before "now")
        ]);
        $dueRun = $this->makeCampaignRun($campaign, [
            'status' => 'scheduled',
            'run_at' => Carbon::parse('2026-08-15 09:00:00', 'UTC'), // future Saturday — the one that SHOULD shift
        ]);

        $sendingRaw = $sendingRun->getRawOriginal('run_at');
        $sentRaw = $sentRun->getRawOriginal('run_at');
        $pastRaw = $pastRun->getRawOriginal('run_at');

        [$exit, $output] = $this->runCommand(['--only' => 'runs']);

        $this->assertSame(0, $exit);
        // Only $dueRun matches the WHERE clause (status scheduled/prepared AND run_at >= now).
        $this->assertSame([1, 1, 0], $this->tallyFor($output, 'campaign_runs.run_at'));

        $sendingRun->refresh();
        $sentRun->refresh();
        $pastRun->refresh();
        $dueRun->refresh();

        $this->assertSame($sendingRaw, $sendingRun->getRawOriginal('run_at'));
        $this->assertSame($sentRaw, $sentRun->getRawOriginal('run_at'));
        $this->assertSame($pastRaw, $pastRun->getRawOriginal('run_at'));
        $this->assertSame('2026-08-17 09:00:00', $dueRun->run_at->format('Y-m-d H:i:s')); // shifted to Monday
    }

    // ── --only scoping ───────────────────────────────────────────────────────────

    public function test_only_campaigns_touches_only_the_campaigns_table(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00', 'UTC')); // Monday "now"

        $campaign = $this->makeCampaign([
            'timezone'    => 'UTC',
            'next_run_at' => Carbon::parse('2026-08-15 09:00:00', 'UTC'), // future Saturday
        ]);
        $run = $this->makeCampaignRun($campaign, [
            'status' => 'scheduled',
            'run_at' => Carbon::parse('2026-08-15 09:00:00', 'UTC'), // future Saturday
        ]);
        $enrollment = $this->makeSequenceEnrollment($campaign, [
            'next_send_at' => Carbon::parse('2026-08-15 09:00:00', 'UTC'), // future Saturday
        ]);

        $runRaw = $run->getRawOriginal('run_at');
        $enrollmentRaw = $enrollment->getRawOriginal('next_send_at');

        [$exit, $output] = $this->runCommand(['--only' => 'campaigns']);

        $this->assertSame(0, $exit);
        $this->assertSame([1, 1, 0], $this->tallyFor($output, 'campaigns.next_run_at'));
        $this->assertStringNotContainsString('campaign_runs.run_at', $output);
        $this->assertStringNotContainsString('sequence_enrollments.next_send_at', $output);

        $campaign->refresh();
        $run->refresh();
        $enrollment->refresh();

        $this->assertSame('2026-08-17 09:00:00', $campaign->next_run_at->format('Y-m-d H:i:s')); // shifted
        $this->assertSame($runRaw, $run->getRawOriginal('run_at')); // untouched
        $this->assertSame($enrollmentRaw, $enrollment->getRawOriginal('next_send_at')); // untouched

        // Confirm the other two DO shift once they're back in scope — proves
        // the previous non-shift was --only scoping, not a bug.
        [$exitAll, $outputAll] = $this->runCommand(['--only' => 'all']);
        $this->assertSame(0, $exitAll);
        $this->assertSame([1, 0, 1], $this->tallyFor($outputAll, 'campaigns.next_run_at')); // still matches the WHERE, but already on Monday now — unchanged
        $this->assertSame([1, 1, 0], $this->tallyFor($outputAll, 'campaign_runs.run_at'));
        $this->assertSame([1, 1, 0], $this->tallyFor($outputAll, 'sequence_enrollments.next_send_at'));

        $run->refresh();
        $enrollment->refresh();
        $this->assertSame('2026-08-17 09:00:00', $run->run_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 09:00:00', $enrollment->next_send_at->format('Y-m-d H:i:s'));
    }

    // ── sequence_enrollments.next_send_at ────────────────────────────────────────

    public function test_enrollment_with_null_campaign_falls_back_to_decouverte_timezone(): void
    {
        Setting::set('decouverte.timezone', 'America/New_York');

        $enrollment = $this->makeSequenceEnrollment(null, [
            'next_send_at' => Carbon::parse('2026-08-15 15:00:00', 'UTC'), // Saturday 11:00 EDT (UTC-4)
        ]);

        [$exit, $output] = $this->runCommand(['--only' => 'enrollments']);

        $this->assertSame(0, $exit);
        $this->assertSame([1, 1, 0], $this->tallyFor($output, 'sequence_enrollments.next_send_at'));

        $enrollment->refresh();
        // Monday 11:00 EDT (still UTC-4 in August, no DST edge here) = 15:00 UTC.
        $this->assertSame('2026-08-17 15:00:00', $enrollment->next_send_at->format('Y-m-d H:i:s'));
    }
}
