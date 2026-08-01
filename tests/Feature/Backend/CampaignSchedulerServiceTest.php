<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Services\Campaign\CampaignSchedulerService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business-calendar behaviour of CampaignSchedulerService::generateDueRuns()
 * and ::computeNextRun() — the frequency-dependent skip-vs-shift rules added
 * in the Planification (skip weekends / blackout dates) chunk.
 *
 * Scenarios that need PlannerService's projection loop to also SHIFT
 * weekly/monthly occurrences (not just skip daily ones) are deliberately
 * NOT tested here against the planner feed — that wiring lands in a later
 * chunk. These tests exercise CampaignSchedulerService directly instead, per
 * the plan note: "write the test against CampaignSchedulerService directly".
 */
class CampaignSchedulerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeCampaign(Carbon $nextRunAt, string $frequency, ?string $until = null): Campaign
    {
        $segment  = Segment::create(['name' => 'Scheduler Seg ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Scheduler Template ' . uniqid(),
            'subject'      => 'Sujet',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Scheduler',
            'email' => 'scheduler' . uniqid() . '@tcl.test',
        ]);

        $recurrence = ['frequency' => $frequency, 'interval' => 1];
        if ($until !== null) {
            $recurrence['until'] = $until;
        }

        return Campaign::create([
            'name'               => 'Campagne Scheduler ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => $recurrence,
            'next_run_at'        => $nextRunAt,
            'timezone'           => 'UTC',
            'is_active'          => true,
        ]);
    }

    /**
     * Anti-pile-up guard at the DB-row level (complements the planner-feed
     * test of the same scenario): a daily campaign anchored on a Friday, due
     * on Friday, is materialised once; the cursor self-heals past Sat+Sun in
     * that SAME tick (computeNextRun()'s internal skip loop), so ticking again
     * on Saturday and Sunday finds the campaign not-yet-due and is a no-op.
     * Exactly one CampaignRun row must exist afterwards, and no QueryException
     * — the occurrence_key derived from the (never-shifted) anchor must never
     * collide across the three ticks.
     */
    public function test_daily_occurrence_key_never_collides_across_ticks_spanning_a_skipped_weekend(): void
    {
        $friday = Carbon::parse('2026-07-17 09:00:00', 'UTC'); // Friday
        $campaign = $this->makeCampaign($friday, 'daily', '2026-08-01');
        $service = app(CampaignSchedulerService::class);

        Carbon::setTestNow($friday->copy()->addMinutes(30));
        $tick1 = $service->generateDueRuns();

        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00', 'UTC')); // Saturday
        $tick2 = $service->generateDueRuns();

        Carbon::setTestNow(Carbon::parse('2026-07-19 10:00:00', 'UTC')); // Sunday
        $tick3 = $service->generateDueRuns();

        $this->assertSame(1, $tick1, 'Friday tick materialises exactly one run');
        $this->assertSame(0, $tick2, 'Saturday tick is a no-op — cursor already self-healed past it');
        $this->assertSame(0, $tick3, 'Sunday tick is a no-op — cursor already self-healed past it');

        $this->assertSame(
            1,
            CampaignRun::where('campaign_id', $campaign->id)->count(),
            'Exactly ONE run row must exist after ticking across Fri/Sat/Sun — no occurrence_key collision, no QueryException'
        );

        $campaign->refresh();
        $this->assertSame(
            '2026-07-20 09:00:00',
            $campaign->next_run_at->format('Y-m-d H:i:s'),
            'Cursor lands on Monday, wall time preserved'
        );
    }

    /**
     * Regression guard for the silent-death bug: a weekly campaign anchored on
     * a Saturday must NOT be skipped like a daily one (which would mean it
     * never fires again) — it must SHIFT to the next allowed day instead,
     * still materialising a CampaignRun.
     */
    public function test_weekly_recurring_anchored_on_saturday_still_creates_a_shifted_run(): void
    {
        $saturday = Carbon::parse('2026-07-18 10:00:00', 'UTC'); // Saturday
        $campaign = $this->makeCampaign($saturday, 'weekly', '2026-12-01');
        $service  = app(CampaignSchedulerService::class);

        Carbon::setTestNow($saturday->copy()->addMinutes(30));
        $count = $service->generateDueRuns();

        $this->assertSame(1, $count, 'A weekly occurrence anchored on a blocked day must still materialise (shifted), not be silently dropped');

        $run = CampaignRun::where('campaign_id', $campaign->id)
            ->where('occurrence_key', 'rec-' . $saturday->format('YmdHis'))
            ->first();

        $this->assertNotNull($run, 'occurrence_key is keyed off the UNSHIFTED anchor');
        $this->assertSame(
            '2026-07-20 10:00:00',
            $run->run_at->format('Y-m-d H:i:s'),
            'run_at is the SHIFTED value — Monday, wall time preserved'
        );

        $campaign->refresh();
        $this->assertSame(
            '2026-07-25 10:00:00',
            $campaign->next_run_at->format('Y-m-d H:i:s'),
            'Cursor advances one week from the UNSHIFTED anchor (still a Saturday) — shifting is re-evaluated every occurrence, not baked into the cursor'
        );
    }

    /**
     * Anti-drift guard: a monthly campaign anchored on a weekend day-of-month
     * has its DELIVERY shifted to the next allowed day, but the CURSOR always
     * advances from the unshifted anchor — so the following month's occurrence
     * still lands on the original day-of-month, not on the shifted date.
     */
    public function test_monthly_recurring_on_weekend_shifts_delivery_but_preserves_day_of_month_across_cycles(): void
    {
        $augustFirst = Carbon::parse('2026-08-01 10:00:00', 'UTC'); // Saturday
        $campaign = $this->makeCampaign($augustFirst, 'monthly', '2027-06-01');
        $service  = app(CampaignSchedulerService::class);

        Carbon::setTestNow($augustFirst->copy()->addMinutes(30));
        $count = $service->generateDueRuns();

        $this->assertSame(1, $count);
        $run = CampaignRun::where('campaign_id', $campaign->id)
            ->where('occurrence_key', 'rec-' . $augustFirst->format('YmdHis'))
            ->firstOrFail();
        $this->assertSame('2026-08-03 10:00:00', $run->run_at->format('Y-m-d H:i:s'), 'August 1 (Sat) shifts delivery to Monday August 3');

        $campaign->refresh();
        $this->assertSame(
            '2026-09-01 10:00:00',
            $campaign->next_run_at->format('Y-m-d H:i:s'),
            'Cursor advances to September 1 — day-of-month 1 preserved, NOT dragged to the shifted August 3'
        );

        // Second cycle: September 1, 2026 is a Tuesday (not blocked) — no shift
        // needed, and day-of-month must still be 1 for October.
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:30:00', 'UTC'));
        $count2 = $service->generateDueRuns();

        $this->assertSame(1, $count2);
        $campaign->refresh();
        $this->assertSame(
            '2026-10-01 10:00:00',
            $campaign->next_run_at->format('Y-m-d H:i:s'),
            'Day-of-month remains 1 after a second cycle — no cumulative drift from the first shift'
        );
    }

    /**
     * A blackout date (not a weekend) must produce no run row for a daily
     * campaign anchored directly on it, and the cursor must skip past it.
     */
    public function test_daily_recurring_skips_a_blackout_date_with_no_run_row(): void
    {
        Setting::set('planification.blackout_dates', '2026-07-08'); // a Wednesday

        $blackoutAnchor = Carbon::parse('2026-07-08 09:00:00', 'UTC');
        $campaign = $this->makeCampaign($blackoutAnchor, 'daily', '2026-08-01');
        $service  = app(CampaignSchedulerService::class);

        Carbon::setTestNow($blackoutAnchor->copy()->addMinutes(30));
        $count = $service->generateDueRuns();

        $this->assertSame(0, $count, 'The blackout date must produce no run');
        $this->assertSame(0, CampaignRun::where('campaign_id', $campaign->id)->count());

        $campaign->refresh();
        $this->assertSame(
            '2026-07-09',
            $campaign->next_run_at->toDateString(),
            'Cursor advances past the single blackout day to the next allowed date'
        );

        Setting::set('planification.blackout_dates', '');
    }
}
