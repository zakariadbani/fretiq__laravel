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
 * Service-level tests for CampaignSchedulerService::generateDueRuns()
 * and ::computeNextRun().
 */
class RecurringCampaignTest extends TestCase
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

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeTemplate(): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => 'Template Recurring',
            'subject'      => 'Objet récurrent',
            'html_content' => '<p>Bonjour {{contact.name}}</p>',
        ]);
    }

    private function makeSender(): SenderIdentity
    {
        return SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);
    }

    private function makeRecurringCampaign(
        ?Carbon $nextRunAt = null,
        bool $isActive = true,
    ): Campaign {
        $segment  = Segment::create(['name' => 'Clients', 'scope' => 'client']);
        $template = $this->makeTemplate();
        $sender   = $this->makeSender();

        return Campaign::create([
            'name'               => 'Campagne Récurrente',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => ['frequency' => 'daily'],
            'next_run_at'        => $nextRunAt ?? now()->subMinute(),
            'timezone'           => 'Europe/Paris',
            'is_active'          => $isActive,
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * A recurring campaign with next_run_at in the past generates exactly one
     * CampaignRun (status=scheduled) and advances next_run_at into the future.
     */
    public function test_generate_due_runs_creates_run_and_advances_next_run_at(): void
    {
        // Pinned to a Tuesday (in UTC — see note below) so the chunk-4
        // daily-skip weekend logic doesn't make this `now()`-based anchor
        // flake whenever the suite happens to run on a Saturday/Sunday (no
        // run row would be created that tick).
        //
        // Pinned in UTC, NOT a local tz like 'Europe/Paris': Carbon::setTestNow()
        // with a non-UTC mock silently changes the DEFAULT timezone that
        // createFromFormat() (used by Eloquent's 'datetime' cast on every
        // model retrieval) falls back to when no explicit tz is given —
        // corrupting every retrieved timestamp by the mock's UTC offset.
        Carbon::setTestNow(Carbon::parse('2026-07-21 09:00:00', 'UTC'));

        $campaign = $this->makeRecurringCampaign(now()->subMinute());

        $service = app(CampaignSchedulerService::class);
        $count   = $service->generateDueRuns();

        $this->assertSame(1, $count, 'Expected exactly 1 run to be generated');

        $this->assertDatabaseHas('campaign_runs', [
            'campaign_id' => $campaign->id,
            'status'      => 'scheduled',
        ]);

        $campaign->refresh();

        $this->assertTrue(
            $campaign->next_run_at->isFuture(),
            'next_run_at must be advanced to a future timestamp',
        );
    }

    /**
     * Calling generateDueRuns() a second time immediately after the first call
     * must not create an additional CampaignRun — the next_run_at is now in the
     * future, so no campaign qualifies.
     */
    public function test_generate_is_idempotent(): void
    {
        // Pinned to a Tuesday in UTC for the same reason as the previous test — see comment there.
        Carbon::setTestNow(Carbon::parse('2026-07-21 09:00:00', 'UTC'));

        $campaign = $this->makeRecurringCampaign(now()->subMinute());

        $service = app(CampaignSchedulerService::class);
        $service->generateDueRuns();

        $runCountAfterFirst = CampaignRun::where('campaign_id', $campaign->id)->count();

        // Second call — next_run_at was advanced to the future, so 0 generated.
        $second = $service->generateDueRuns();

        $runCountAfterSecond = CampaignRun::where('campaign_id', $campaign->id)->count();

        $this->assertSame(0, $second, 'Second call should return 0 (no due campaign)');
        $this->assertSame(
            $runCountAfterFirst,
            $runCountAfterSecond,
            'Run count must not increase on the second call',
        );
    }

    /**
     * A recurring campaign with is_active=false must be skipped entirely by the
     * scheduler, even when next_run_at is in the past and status='active'.
     * The cursor is frozen — next_run_at is NOT advanced.
     */
    public function test_paused_campaign_skipped(): void
    {
        $campaign = $this->makeRecurringCampaign(
            nextRunAt: now()->subMinute(),
            isActive: false,
        );

        $originalNextRunAt = $campaign->next_run_at->copy();

        $service = app(CampaignSchedulerService::class);
        $count   = $service->generateDueRuns();

        $this->assertSame(0, $count, 'Paused campaign (is_active=false) must generate 0 runs');

        $this->assertDatabaseMissing('campaign_runs', [
            'campaign_id' => $campaign->id,
        ]);

        // Cursor must NOT be advanced — the scheduler skips paused campaigns entirely.
        $campaign->refresh();
        $this->assertTrue(
            $campaign->next_run_at->eq($originalNextRunAt),
            'next_run_at must remain frozen when the campaign is paused',
        );
    }

    /**
     * computeNextRun with frequency='daily' must add exactly 1 day in the
     * given timezone (DST-aware).
     */
    public function test_compute_next_run_daily(): void
    {
        $service = app(CampaignSchedulerService::class);

        $from = Carbon::parse('2026-06-10 09:00:00', 'Europe/Paris');

        $next = $service->computeNextRun(
            ['frequency' => 'daily'],
            $from,
            'Europe/Paris',
        );

        $this->assertNotNull($next);

        // Convert back to Europe/Paris for assertion so DST is handled correctly.
        $nextParis = $next->setTimezone('Europe/Paris');

        $this->assertSame('2026-06-11', $nextParis->toDateString());
        $this->assertSame('09:00', $nextParis->format('H:i'));
    }

    /**
     * DST spring-forward 2026-03-29 (Europe/Paris clocks move 02:00 → 03:00).
     *
     * A daily 10:00 Europe/Paris send on 2026-03-28 must land at 10:00 local
     * on 2026-03-29 — not 11:00 (which Carbon::parse($utc, $tz) would produce
     * by ignoring the tz arg on an already-Carbon instance).
     * The returned value must be a UTC Carbon.
     */
    public function test_compute_next_run_dst_spring_forward(): void
    {
        // European DST spring-forward always falls on a Sunday, so the day
        // before (2026-03-28) is structurally a Saturday — this test crosses
        // a weekend by construction. Disable the chunk-4 weekend-skip logic
        // here so the DST assertion below stays 100% intact; the weekend
        // behavior itself is covered separately (CampaignSchedulerServiceTest).
        Setting::set('planification.skip_weekends', false);

        $service = app(CampaignSchedulerService::class);

        // 10:00 Paris on 2026-03-28 = 09:00 UTC (CET, UTC+1).
        $from = Carbon::parse('2026-03-28 09:00:00', 'UTC');

        $next = $service->computeNextRun(
            ['frequency' => 'daily'],
            $from,
            'Europe/Paris',
        );

        $this->assertNotNull($next);
        $this->assertSame('UTC', $next->timezoneName, 'computeNextRun must return a UTC Carbon');

        // 10:00 Paris on 2026-03-29 = 08:00 UTC (CEST, UTC+2 after spring forward).
        $nextParis = $next->copy()->setTimezone('Europe/Paris');
        $this->assertSame('10:00', $nextParis->format('H:i'),
            'Wall-clock time must remain 10:00 Paris across spring-forward');
        $this->assertSame('2026-03-29', $nextParis->toDateString());
    }

    /**
     * DST fall-back 2026-10-25 (Europe/Paris clocks move 03:00 → 02:00).
     *
     * A daily 10:00 Europe/Paris send on 2026-10-24 must land at 10:00 local
     * on 2026-10-25, not drift to 09:00 or 11:00.
     * The returned value must be a UTC Carbon.
     */
    public function test_compute_next_run_dst_fall_back(): void
    {
        // European DST fall-back also always falls on a Sunday, so the day
        // before (2026-10-24) is structurally a Saturday — same reasoning as
        // the spring-forward test above.
        Setting::set('planification.skip_weekends', false);

        $service = app(CampaignSchedulerService::class);

        // 10:00 Paris on 2026-10-24 = 08:00 UTC (CEST, UTC+2).
        $from = Carbon::parse('2026-10-24 08:00:00', 'UTC');

        $next = $service->computeNextRun(
            ['frequency' => 'daily'],
            $from,
            'Europe/Paris',
        );

        $this->assertNotNull($next);
        $this->assertSame('UTC', $next->timezoneName, 'computeNextRun must return a UTC Carbon');

        // 10:00 Paris on 2026-10-25 = 09:00 UTC (CET, UTC+1 after fall-back).
        $nextParis = $next->copy()->setTimezone('Europe/Paris');
        $this->assertSame('10:00', $nextParis->format('H:i'),
            'Wall-clock time must remain 10:00 Paris across fall-back');
        $this->assertSame('2026-10-25', $nextParis->toDateString());
    }
}
