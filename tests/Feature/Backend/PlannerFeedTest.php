<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\Analytics\PlannerService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlannerFeedTest — service-level and HTTP-layer tests for the campaign planner calendar feed.
 *
 * The PlannerService returns FullCalendar-shaped events (id, title, start, color, url).
 * Covers both materialised CampaignRun rows and virtual "projected" recurring occurrences.
 * The PlannerController gating is `view campaigns` — commercial satisfies this.
 * We use superadmin throughout for simplicity (has all permissions).
 */
class PlannerFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private Campaign $campaign;
    private CampaignRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        // Seed a one-shot campaign + a scheduled run with run_at = now()
        $segment  = Segment::create(['name' => 'Planner Seg', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Planner Template',
            'subject'      => 'Planner Subject',
            'html_content' => '<p>Hello planner</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Planner',
            'email' => 'planner@tcl.test',
        ]);

        $this->campaign = Campaign::create([
            'name'               => 'Campagne Planner',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
        ]);

        $this->run = CampaignRun::create([
            'campaign_id'    => $this->campaign->id,
            'occurrence_key' => 'one_shot_' . now()->format('Y-m-d'),
            'run_at'         => now(),
            'status'         => 'scheduled',
        ]);
    }

    private function makeEligibleClientContact(): void
    {
        $company = Company::create([
            'name' => 'Planner client '.uniqid(),
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        Contact::create([
            'company_id' => $company->id,
            'name' => 'Planner contact',
            'email' => uniqid('planner_').'@test.test',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    /**
     * Create a recurring campaign whose next_run_at is a specific UTC Carbon.
     *
     * @param  Carbon       $nextRunAt   UTC
     * @param  string|null  $until       ISO-8601 date string for the until boundary, or null
     * @param  string       $frequency   'daily' | 'weekly' | 'monthly'
     * @param  bool         $isActive    false = paused; default true
     */
    private function makeRecurring(
        Carbon $nextRunAt,
        ?string $until    = null,
        string $frequency = 'daily',
        bool $isActive    = true,
    ): Campaign {
        $segment  = Segment::create(['name' => 'Rec Seg ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Rec Template ' . uniqid(),
            'subject'      => 'Rec Subject',
            'html_content' => '<p>Hello</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Rec',
            'email' => 'rec' . uniqid() . '@tcl.test',
        ]);

        $recurrence = ['frequency' => $frequency, 'interval' => 1];
        if ($until !== null) {
            $recurrence['until'] = $until;
        }

        return Campaign::create([
            'name'               => 'Récurrente ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => $recurrence,
            'next_run_at'        => $nextRunAt,
            'timezone'           => 'UTC',
            'is_active'          => $isActive,
        ]);
    }


    private function makePacedSequence(Carbon $nextRunAt, array $overrides = []): Campaign
    {
        $sequence = Sequence::create([
            'name' => 'Sequence planner ' . uniqid(),
            'is_active' => true,
            'stop_on_reply' => false,
        ]);
        $template = CampaignTemplate::create([
            'name' => 'Sequence template ' . uniqid(),
            'subject' => 'Sequence subject',
            'html_content' => '<p>Sequence</p>',
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 1,
            'delay_days' => 0,
            'template_id' => $template->id,
        ]);
        SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no' => 2,
            'delay_days' => 1,
            'template_id' => $template->id,
        ]);
        $segment = Segment::create(['name' => 'Sequence segment ' . uniqid(), 'scope' => 'client']);
        $sender = SenderIdentity::create([
            'name' => 'Sequence sender ' . uniqid(),
            'email' => uniqid('sequence_') . '@tcl.test',
        ]);

        return Campaign::create(array_merge([
            'name' => 'Sequence campaign ' . uniqid(),
            'segment_id' => $segment->id,
            'sequence_id' => $sequence->id,
            'sender_identity_id' => $sender->id,
            'schedule_type' => 'sequence',
            'sequence_enrollment_mode' => 'paced',
            'daily_company_limit' => 12,
            'next_run_at' => $nextRunAt,
            'timezone' => 'UTC',
            'is_active' => true,
            'sequence_auto_enroll_enabled' => true,
        ], $overrides));
    }
    // ── Existing service-level tests (must stay green) ─────────────────────────

    /**
     * PlannerService::runsFeed() includes an event shaped as a FullCalendar event
     * with the required keys and correct title for the seeded campaign.
     */
    public function test_runs_feed_service_includes_seeded_event(): void
    {
        $events = app(PlannerService::class)->runsFeed();

        $this->assertIsArray($events, 'runsFeed() must return an array');
        $this->assertNotEmpty($events, 'runsFeed() must include the seeded run');

        // Find the event for our run
        $event = collect($events)->firstWhere('id', (string) $this->run->id);

        $this->assertNotNull($event,
            "runsFeed() must include an event with id='{$this->run->id}'");

        // Required FullCalendar keys
        foreach (['id', 'title', 'start', 'color', 'url'] as $key) {
            $this->assertArrayHasKey($key, $event,
                "runsFeed() event must contain key '{$key}'");
        }

        $this->assertSame($this->campaign->name, $event['title'],
            'event title must match the campaign name');

        $this->assertNotEmpty($event['start'],
            'event start must be a non-empty ISO 8601 string');

        $this->assertNotEmpty($event['url'],
            'event url must not be empty for a run with a campaign');
    }

    /**
     * runsFeed() with a date range that excludes the seeded run returns an empty array.
     */
    public function test_runs_feed_service_empty_outside_range(): void
    {
        // Use a past range (yesterday only)
        $start = now()->subDays(2)->toDateString();
        $end   = now()->subDay()->toDateString();

        $events = app(PlannerService::class)->runsFeed($start, $end);

        $ids = array_column($events, 'id');
        $this->assertNotContains((string) $this->run->id, $ids,
            'A run_at=now() event must NOT appear in a past-only date range');
    }


    public function test_planner_projects_active_auto_discovery_criteria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 13:00:00', 'Europe/Paris'));

        $active = ProspectCriteria::create([
            'name' => "D\u{00E9}couverte planner",
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 12,
        ]);
        ProspectCriteria::create([
            'name' => "D\u{00E9}couverte inactive",
            'daily_limit' => 10,
            'is_active' => false,
            'auto_run' => true,
            'run_at_hour' => 12,
        ]);
        ProspectCriteria::create([
            'name' => "D\u{00E9}couverte manuelle",
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => false,
            'run_at_hour' => 12,
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+02:00',
            '2026-07-30T00:00:00+02:00',
            'Europe/Paris',
        ))->where('extendedProps.eventKind', 'discovery-projection')->values();

        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (array $event): bool => $event['title'] === "D\u{00E9}couverte \u{00B7} ".$active->name));
        $this->assertTrue($events->every(fn (array $event): bool => $event['url'] === route('admin.prospect_criteria.view', $active->id)));
        $this->assertSame(
            ['2026-07-28 12:00', '2026-07-29 12:00'],
            $events->map(fn (array $event): string => Carbon::parse($event['start'])
                ->setTimezone('Europe/Paris')
                ->format('Y-m-d H:i'))
                ->all(),
        );

        Carbon::setTestNow();
    }

    public function test_planner_includes_materialized_discovery_runs_from_past_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 08:00:00', 'Europe/Paris'));

        $criteria = ProspectCriteria::create([
            'name' => "D\u{00E9}couverte historique",
            'daily_limit' => 10,
            'is_active' => true,
        ]);
        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'completed',
            'started_at' => '2026-07-28 06:00:00',
            'finished_at' => '2026-07-28 06:01:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-30 08:00:00', 'Europe/Paris'));

        $event = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+02:00',
            '2026-07-29T00:00:00+02:00',
            'Europe/Paris',
        ))->firstWhere('id', 'discovery-run-'.$run->id);

        $this->assertNotNull($event);
        $this->assertSame("D\u{00E9}couverte \u{00B7} {$criteria->name}", $event['title']);
        $this->assertSame('discovery-run', $event['extendedProps']['eventKind']);
        $this->assertSame("Termin\u{00E9}e", $event['extendedProps']['statusLabel']);
        $this->assertSame('2026-07-28', Carbon::parse($event['start'])->setTimezone('Europe/Paris')->toDateString());

        Carbon::setTestNow();
    }

    public function test_materialized_discovery_run_replaces_same_day_projection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 13:00:00', 'Europe/Paris'));

        $criteria = ProspectCriteria::create([
            'name' => "D\u{00E9}couverte sans doublon",
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 12,
        ]);
        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'completed',
            'started_at' => '2026-07-28 10:00:00',
            'finished_at' => '2026-07-28 10:01:00',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+02:00',
            '2026-07-29T00:00:00+02:00',
            'Europe/Paris',
        ))->filter(
            fn (array $event): bool => str_starts_with($event['extendedProps']['eventKind'] ?? '', 'discovery-'),
        )->values();

        $this->assertSame(
            ['discovery-run-'.$run->id],
            $events->pluck('id')->all(),
        );

        Carbon::setTestNow();
    }

    public function test_discovery_projection_dedup_uses_pinned_quota_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 13:00:00', 'Europe/Paris'));

        $criteria = ProspectCriteria::create([
            'name' => "D\u{00E9}couverte quota",
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 12,
        ]);
        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'completed',
            'quota_date' => '2026-07-27',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+02:00',
            '2026-07-29T00:00:00+02:00',
            'Europe/Paris',
        ))->filter(
            fn (array $event): bool => str_contains($event['id'], 'discovery'),
        );

        $this->assertCount(2, $events);
        $this->assertTrue($events->pluck('id')->contains('discovery-run-'.$run->id));
        $this->assertTrue($events->pluck('id')->contains('projected-discovery-'.$criteria->id.'-20260728100000'));

        Carbon::setTestNow();
    }

    public function test_discovery_projection_dedup_finds_quota_date_outside_display_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 13:00:00', 'Europe/Paris'));

        $criteria = ProspectCriteria::create([
            'name' => "D\u{00E9}couverte quota hors plage",
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 12,
        ]);
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'completed',
            'quota_date' => '2026-07-28',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-28 13:00:00', 'Europe/Paris'));

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+02:00',
            '2026-07-29T00:00:00+02:00',
            'Europe/Paris',
        ))->filter(
            fn (array $event): bool => str_starts_with($event['extendedProps']['eventKind'] ?? '', 'discovery-'),
        );

        $this->assertCount(0, $events);

        Carbon::setTestNow();
    }

    public function test_planner_skips_discovery_run_without_criteria(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 08:00:00', 'Europe/Paris'));

        $run = DiscoveryRun::create([
            'prospect_criteria_id' => null,
            'type' => 'discovery',
            'status' => 'pending',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+02:00',
            '2026-07-29T00:00:00+02:00',
            'Europe/Paris',
        ));

        $this->assertNull($events->firstWhere('id', 'discovery-run-'.$run->id));

        Carbon::setTestNow();
    }

    public function test_auto_discovery_projection_restores_wall_clock_hour_after_dst_gap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-28 00:00:00', 'Europe/Paris'));

        // European DST spring-forward always falls on a Sunday, so this
        // window (2026-03-28 Sat → 2026-03-30 Mon) structurally crosses a
        // weekend — that's inherent to testing the DST gap itself, not
        // something this test is meant to cover. Disable the weekend-skip
        // logic here so the DST wall-clock-restoration assertion below stays
        // 100% intact; weekend behavior is covered by dedicated tests
        // elsewhere (CampaignSchedulerServiceTest).
        Setting::set('planification.skip_weekends', false);

        ProspectCriteria::create([
            'name' => 'DST discovery',
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 2,
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-03-28T00:00:00+01:00',
            '2026-03-31T00:00:00+02:00',
            'Europe/Paris',
        ))->where('extendedProps.eventKind', 'discovery-projection')->values();

        $this->assertSame(
            ['2026-03-28 02:00', '2026-03-29 03:00', '2026-03-30 02:00'],
            $events->map(fn (array $event): string => Carbon::parse($event['start'])
                ->setTimezone('Europe/Paris')
                ->format('Y-m-d H:i'))
                ->all(),
        );

        Carbon::setTestNow();
    }

    public function test_planner_feed_hides_discovery_without_criteria_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 08:00:00', 'Europe/Paris'));

        $criteria = ProspectCriteria::create([
            'name' => 'Hidden discovery',
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 12,
        ]);
        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'completed',
            'started_at' => '2026-07-28 06:00:00',
            'finished_at' => '2026-07-28 06:01:00',
        ]);

        $campaignOnlyUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $campaignOnlyUser->givePermissionTo(['backend.access', 'view campaigns']);

        $events = $this->actingAs($campaignOnlyUser)
            ->getJson('/admin/planner/feed?start=2026-07-28T00:00:00%2B02:00&end=2026-07-30T00:00:00%2B02:00')
            ->assertOk()
            ->json();

        $this->assertNull(collect($events)->firstWhere('extendedProps.eventKind', 'discovery-projection'));
        $this->assertNull(collect($events)->firstWhere('id', 'discovery-run-'.$run->id));

        $this->get('/admin/planner')
            ->assertOk()
            ->assertDontSee('D&eacute;couverte automatique', false);

        Carbon::setTestNow();
    }

    /**
     * Auto-discovery projection must skip BOTH weekends and blackout dates:
     * window 2026-07-03 (Fri) .. 2026-07-09 (Thu) with 2026-07-08 (Wed)
     * blacked out → expected days are Fri 3, Mon 6, Tue 7, Thu 9 (Sat 4,
     * Sun 5 skipped by the weekend rule; Wed 8 skipped by the blackout).
     */
    public function test_discovery_projection_skips_weekends_and_blackout_dates(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');
        Setting::set('planification.blackout_dates', '2026-07-08');

        ProspectCriteria::create([
            'name' => "D\u{00E9}couverte calendrier",
            'daily_limit' => 10,
            'is_active' => true,
            'auto_run' => true,
            'run_at_hour' => 9,
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-03T00:00:00+00:00',
            '2026-07-10T00:00:00+00:00',
        ))->where('extendedProps.eventKind', 'discovery-projection')->values();

        $dates = $events->map(fn (array $e): string => Carbon::parse($e['start'])->utc()->toDateString())->all();

        $this->assertSame(['2026-07-03', '2026-07-06', '2026-07-07', '2026-07-09'], $dates);

        Setting::set('planification.blackout_dates', '');
        Carbon::setTestNow();
    }

    // ── Existing HTTP tests (must stay green) ──────────────────────────────────

    /**
     * GET /admin/planner returns 200 for a superadmin.
     */
    public function test_planner_index_renders_for_superadmin(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/planner')
            ->assertStatus(200);
    }


    public function test_planner_defaults_to_week_view_with_readable_short_events(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/planner')
            ->assertOk()
            ->assertSee("initialView: mobileViewport.matches ? 'listDay' : 'timeGridWeek'", false)
            ->assertSee('eventShortHeight: 60', false);
    }

    public function test_planner_heading_uses_default_timezone_without_repeating_page_title(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/planner')
            ->assertOk()
            ->assertSee('data-timezone="Europe/Paris"', false)
            ->assertDontSee('<h2 class="card-title fw-bold">Planning des campagnes</h2>', false);
    }

    public function test_planner_heading_uses_configured_timezone(): void
    {
        Setting::set('decouverte.timezone', 'UTC');

        $this->actingAs($this->superadmin)
            ->get('/admin/planner')
            ->assertOk()
            ->assertSee('data-timezone="UTC"', false)
            ->assertSee('(UTC)');
    }

    public function test_planner_calendar_and_modal_use_configured_timezone(): void
    {
        $content = $this->actingAs($this->superadmin)
            ->get('/admin/planner')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/new\s+FullCalendar\.Calendar\(\s*calendarEl\s*,\s*\{(?:(?!eventClick\s*:)[\s\S])*?\btimeZone\s*:\s*plannerTimezone/',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/new\s+Date\(\s*info\.event\.startStr\s*\)\.toLocaleString\(\s*\'fr-FR\'\s*,\s*\{(?=[^}]*\btimeZone\s*:\s*plannerTimezone)[^}]*\}\s*\)/',
            $content,
        );
    }

    /**
     * index() must pass plannerSkipWeekends (bool) and plannerBlackout (a
     * flat list of Y-m-d strings, weekends excluded when skip_weekends is
     * on) to the view, and the rendered Blade must wire dayCellClassNames
     * into the FullCalendar config so blocked days get greyed out.
     */
    public function test_planner_index_exposes_skip_weekends_and_blackout_to_view(): void
    {
        Carbon::setTestNow('2026-08-01 00:00:00'); // window covers 2026-12-25 (Friday)
        Setting::set('decouverte.timezone', 'Europe/Paris');
        Setting::set('planification.skip_weekends', true);
        Setting::set('planification.blackout_dates', '12-25');

        $response = $this->actingAs($this->superadmin)->get('/admin/planner');

        $response->assertOk();
        $response->assertViewHas('plannerSkipWeekends', true);
        $response->assertViewHas('plannerBlackout', function ($blackout) {
            if (! is_array($blackout) || ! in_array('2026-12-25', $blackout, true)) {
                return false;
            }

            // skip_weekends=true → no plain Saturday/Sunday should ever be
            // shipped in the payload; the JS derives those from arg.dow.
            foreach ($blackout as $date) {
                if (Carbon::createFromFormat('Y-m-d', $date)->isWeekend()) {
                    return false;
                }
            }

            return true;
        });

        $response->assertSee('dayCellClassNames', false);
        $response->assertSee('plannerSkipWeekends', false);
        $response->assertSee('plannerBlackout', false);

        Setting::set('planification.blackout_dates', '');
        Carbon::setTestNow();
    }

    /**
     * GET /admin/planner/feed returns a 200 JSON array containing the seeded event.
     */
    public function test_planner_feed_returns_json_with_event(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->getJson('/admin/planner/feed');

        $response->assertStatus(200);
        $response->assertJsonIsArray();

        // The response must contain an array entry whose 'id' matches our run
        $events = $response->json();

        $event = collect($events)->firstWhere('id', (string) $this->run->id);

        $this->assertNotNull($event,
            "The feed JSON must include the seeded run with id='{$this->run->id}'");

        $this->assertSame($this->campaign->name, $event['title'],
            'The feed event title must match the campaign name');
    }

    public function test_planner_feed_converts_event_start_to_configured_timezone(): void
    {
        Setting::set('decouverte.timezone', 'Europe/Paris');
        $this->run->update(['run_at' => Carbon::parse('2026-07-01 09:00:00', 'UTC')]);

        $event = collect($this->actingAs($this->superadmin)
            ->getJson('/admin/planner/feed')
            ->assertOk()
            ->json())
            ->firstWhere('id', (string) $this->run->id);

        $this->assertSame('2026-07-01T11:00:00+02:00', $event['start']);
    }

    public function test_planner_feed_uses_configured_timezone_for_offsetless_bounds(): void
    {
        Setting::set('decouverte.timezone', 'Europe/Paris');
        $this->run->update(['run_at' => Carbon::parse('2026-06-30 22:00:00', 'UTC')]);

        $events = collect($this->actingAs($this->superadmin)
            ->getJson('/admin/planner/feed?start=2026-07-01T00:00:00&end=2026-07-01T01:00:00&timeZone=Europe%2FParis')
            ->assertOk()
            ->json());

        $this->assertNotNull($events->firstWhere('id', (string) $this->run->id));
    }

    public function test_offsetless_planner_bounds_respect_spring_forward_day(): void
    {
        $this->run->update(['run_at' => Carbon::parse('2026-03-28 23:00:00', 'UTC')]);
        $exclusiveEnd = CampaignRun::create([
            'campaign_id' => $this->campaign->id,
            'occurrence_key' => 'spring-forward-exclusive-end',
            'run_at' => Carbon::parse('2026-03-29 22:00:00', 'UTC'),
            'status' => 'scheduled',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-03-29T00:00:00',
            '2026-03-30T00:00:00',
            'Europe/Paris',
        ));

        $this->assertNotNull($events->firstWhere('id', (string) $this->run->id));
        $this->assertNull($events->firstWhere('id', (string) $exclusiveEnd->id));
    }

    /**
     * The feed accepts start/end query params and still returns valid JSON.
     */
    public function test_planner_feed_accepts_date_range_params(): void
    {
        $start = now()->subDays(1)->toDateString();
        $end   = now()->addDays(2)->toDateString();

        $this->actingAs($this->superadmin)
            ->getJson("/admin/planner/feed?start={$start}&end={$end}")
            ->assertStatus(200)
            ->assertJsonIsArray();
    }

    // ── Projection tests ───────────────────────────────────────────────────────

    /**
     * Daily recurring campaign (next_run_at = tomorrow 12:00 UTC, until +10 days, scheduled)
     * fed with window [today, +8 days) → must contain exactly 5 projected events
     * (July 4 Sat and July 5 Sun are skipped by the daily business-calendar
     * skip-loop in CampaignSchedulerService::computeNextRun(), which this
     * projection reuses directly — see PlannerService.php:239) and the FIRST
     * projected event's start must equal next_run_at exactly (guards
     * emit-then-advance ordering: cursor emitted before being advanced).
     */
    public function test_projection_daily_within_window(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        $nextRunAt = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-07-11', 'daily');

        $windowStart = '2026-07-01T00:00:00+00:00';
        $windowEnd   = '2026-07-09T00:00:00+00:00'; // exclusive → 8 days [2, 3, 4, 5, 6, 7, 8]

        $events = app(PlannerService::class)->runsFeed($windowStart, $windowEnd);

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        // Expected: July 2, 3, 6, 7, 8 = 5 days (July 4 Sat and July 5 Sun are
        // skipped; windowEnd is 2026-07-09 exclusive).
        $this->assertCount(5, $projected,
            'Expected 5 projected events for July 2–8 within a July 1–9 window (weekend skipped)');

        // First projected event must equal next_run_at (emit-then-advance guard).
        $firstStart = Carbon::parse($projected->first()['start'])->utc();
        $this->assertTrue(
            $firstStart->eq($nextRunAt),
            "First projected event ({$firstStart->toIso8601String()}) must equal next_run_at ({$nextRunAt->toIso8601String()})"
        );

        // All projected events must have status='projected' and color '#7239ea' (info).
        foreach ($projected as $event) {
            $this->assertSame('projected', $event['extendedProps']['status']);
            $this->assertSame('#7239ea', $event['color']);
        }

        Carbon::setTestNow();
    }

    /**
     * The 'until' boundary in recurrence is respected: no projected events appear
     * after the until date.
     */
    public function test_projection_until_respected(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        $nextRunAt = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        // until = 2026-07-05 → occurrences July 2, 3, 4, 5 (endOfDay) only.
        $campaign = $this->makeRecurring($nextRunAt, '2026-07-05', 'daily');

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-01T00:00:00+00:00',
            '2026-07-15T00:00:00+00:00',
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        );

        // July 2, 3, 4, 5 → 4 occurrences (5 is within endOfDay boundary).
        $this->assertLessThanOrEqual(4, $projected->count(),
            'No projected events must appear after the until date (2026-07-05)');

        foreach ($projected as $event) {
            $start = Carbon::parse($event['start'])->utc();
            $this->assertTrue(
                $start->lte(Carbon::parse('2026-07-05')->endOfDay()->utc()),
                "Projected event at {$start->toIso8601String()} is past the until date"
            );
        }

        Carbon::setTestNow();
    }

    /**
     * Far-window traversal: feed for a 1-month window ~11 months ahead of next_run_at
     * (within until ~13 months out) must still return projected events.
     * Guards against traversal-cap starvation swallowing the entire distant window.
     */
    public function test_projection_far_window_returns_events(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        // next_run_at = tomorrow; until ~13 months out.
        $nextRunAt = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2027-08-01', 'daily');

        // Window ~11 months ahead: June 2027.
        $events = app(PlannerService::class)->runsFeed(
            '2027-06-01T00:00:00+00:00',
            '2027-07-01T00:00:00+00:00',
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        );

        $this->assertGreaterThan(0, $projected->count(),
            'Projected events must appear in a distant window (traversal cap must not starve it)');

        Carbon::setTestNow();
    }

    /**
     * Dedup: a materialised CampaignRun at the same timestamp as next_run_at must
     * NOT produce a duplicate projected event for that timestamp.
     * The real run IS still present (id = integer, not "projected-…").
     */
    public function test_projection_dedup_with_real_run(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        $nextRunAt = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-07-10', 'daily');

        // Materialise a real run at exactly next_run_at.
        CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'rec-' . $nextRunAt->format('YmdHis'),
            'run_at'         => $nextRunAt,
            'status'         => 'scheduled',
        ]);

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-01T00:00:00+00:00',
            '2026-07-10T00:00:00+00:00',
        );

        $atNextRunAt = collect($events)->filter(function ($e) use ($nextRunAt) {
            return Carbon::parse($e['start'])->utc()->eq($nextRunAt);
        });

        // Only ONE event at that timestamp: the real run (not a projected duplicate).
        $this->assertCount(1, $atNextRunAt,
            'Exactly one event at next_run_at — the real run, no projected duplicate');

        $this->assertFalse(
            str_starts_with((string) $atNextRunAt->first()['id'], 'projected-'),
            'The event at next_run_at must be the real run (integer id), not projected'
        );

        Carbon::setTestNow();
    }

    /**
     * Tz-offset window params: FullCalendar sends ISO strings with tz offsets
     * (e.g. 2026-07-01T00:00:00+02:00 = 2026-06-30T22:00:00Z).
     * An occurrence at 2026-06-30T22:00:00Z IS inside the window [22:00Z, …)
     * and must appear; a comparison against the raw string without UTC parsing
     * would incorrectly exclude it.
     */
    public function test_projection_tz_offset_window_params(): void
    {
        Carbon::setTestNow('2026-06-30 21:00:00');

        // next_run_at = 2026-06-30T22:00:00Z = 2026-07-01T00:00:00+02:00
        $nextRunAt = Carbon::parse('2026-06-30 22:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-08-01', 'daily');

        // Window start = 2026-07-01T00:00:00+02:00 = 2026-06-30T22:00:00Z
        $events = app(PlannerService::class)->runsFeed(
            '2026-07-01T00:00:00+02:00',
            '2026-08-01T00:00:00+02:00',
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        );

        $this->assertGreaterThan(0, $projected->count(),
            'Occurrence at 2026-06-30T22:00Z must be included when window start is 2026-07-01T00:00+02:00');

        // No exception was thrown — tz-offset parsing handled.
        Carbon::setTestNow();
    }

    /**
     * A draft recurring campaign must NOT appear in the projection.
     * Only is_active=true campaigns are projected.
     */
    public function test_projection_draft_campaign_excluded(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        $nextRunAt = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        // is_active=false (paused) — must not be projected.
        $campaign  = $this->makeRecurring($nextRunAt, '2026-07-10', 'daily', false);

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-01T00:00:00+00:00',
            '2026-07-10T00:00:00+00:00',
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        );

        $this->assertCount(0, $projected,
            'Paused campaign (is_active=false) must produce zero projected events');

        Carbon::setTestNow();
    }

    // ── Business-calendar projection (chunk 4+5: skip vs shift) ────────────────
    //
    // These exercise the daily-frequency skip loop that CampaignSchedulerService
    // ::computeNextRun() gained in this chunk, via the planner projection loop
    // that reuses it directly (PlannerService.php:239). Weekly/monthly shifting
    // is NOT wired into the planner projection loop yet (that requires a
    // dedicated PlannerService change tracked separately) — those scenarios are
    // covered against CampaignSchedulerService directly in
    // tests/Feature/Backend/CampaignSchedulerServiceTest.php instead.

    /**
     * Anti-pile-up guard: a daily campaign anchored on a Friday must SKIP
     * Saturday and Sunday entirely (no projected event on either date), and
     * Monday — the day both blocked anchors would otherwise collapse onto if
     * shifted instead of skipped — must carry exactly ONE event.
     */
    public function test_projection_daily_recurring_skips_weekend_and_monday_has_exactly_one_event(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        // Friday anchor.
        $nextRunAt = Carbon::parse('2026-07-03 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-07-15', 'daily');

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-03T00:00:00+00:00',
            '2026-07-10T00:00:00+00:00', // exclusive → Jul 3–9
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        $dates = $projected->map(fn (array $e): string => Carbon::parse($e['start'])->utc()->toDateString())->all();

        $this->assertNotContains('2026-07-04', $dates, 'Saturday must be skipped, not shifted');
        $this->assertNotContains('2026-07-05', $dates, 'Sunday must be skipped, not shifted');
        $this->assertSame(
            1,
            count(array_filter($dates, fn (string $d): bool => $d === '2026-07-06')),
            'Monday must carry exactly ONE event — no pile-up from the skipped Sat+Sun anchors'
        );
        $this->assertSame(['2026-07-03', '2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09'], $dates);

        Carbon::setTestNow();
    }

    /**
     * A blackout date inside the projection window must have zero events, even
     * on weekdays where the weekend-skip rule doesn't apply.
     */
    public function test_projection_daily_recurring_skips_blackout_date(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');
        Setting::set('planification.blackout_dates', '2026-07-08');

        // Monday anchor — 2026-07-06 through 2026-07-09 are all weekdays, so
        // this isolates the blackout-date rule from the weekend rule.
        $nextRunAt = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-07-15', 'daily');

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-06T00:00:00+00:00',
            '2026-07-10T00:00:00+00:00', // exclusive → Jul 6–9
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        $dates = $projected->map(fn (array $e): string => Carbon::parse($e['start'])->utc()->toDateString())->all();

        $this->assertNotContains('2026-07-08', $dates, 'The blackout date must have no projected event');
        $this->assertSame(['2026-07-06', '2026-07-07', '2026-07-09'], $dates);

        Setting::set('planification.blackout_dates', '');
        Carbon::setTestNow();
    }

    /**
     * The off-switch: with planification.skip_weekends=false, projection
     * reverts to the pre-chunk-4 behaviour of one event per calendar day,
     * weekends included.
     */
    public function test_projection_daily_recurring_reverts_to_all_days_when_skip_weekends_disabled(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');
        Setting::set('planification.skip_weekends', false);

        $nextRunAt = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-07-11', 'daily');

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-01T00:00:00+00:00',
            '2026-07-09T00:00:00+00:00',
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        );

        $this->assertCount(7, $projected,
            'With skip_weekends=false, all 7 days (July 2–8) must project, weekend included');

        Setting::set('planification.skip_weekends', true);
        Carbon::setTestNow();
    }

    // ── Business-calendar projection (chunk 8: shift vs skip, dedup on shifted run_at) ──

    /**
     * The very first cursor of a daily recurring campaign is the RAW,
     * not-yet-normalised `next_run_at` anchor straight from the DB — it may
     * itself sit on a blocked day (e.g. an admin picked a Saturday before the
     * scheduler ever ticked). Guards that the outer isBlocked() check in the
     * projection loop is reachable (not dead code): without it, this would
     * wrongly project an event ON the Saturday.
     */
    public function test_projection_daily_recurring_skips_blocked_first_anchor(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        // Saturday anchor, never advanced by computeNextRun() yet.
        $nextRunAt = Carbon::parse('2026-07-04 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-07-15', 'daily');

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-04T00:00:00+00:00',
            '2026-07-08T00:00:00+00:00', // exclusive → Jul 4–7
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        $dates = $projected->map(fn (array $e): string => Carbon::parse($e['start'])->utc()->toDateString())->all();

        $this->assertNotContains('2026-07-04', $dates, 'Blocked Saturday anchor must not project an event');
        $this->assertNotContains('2026-07-05', $dates, 'Sunday must not project an event');
        $this->assertSame(['2026-07-06', '2026-07-07'], $dates);

        Carbon::setTestNow();
    }

    /**
     * A weekly recurring campaign anchored on a Saturday must SHIFT to the
     * following Monday, not skip entirely — a daily-style skip would mean a
     * Saturday-anchored weekly campaign silently never projects, ever.
     */
    public function test_projection_weekly_recurring_shifts_saturday_anchor_to_monday(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        // Saturday anchor.
        $nextRunAt = Carbon::parse('2026-07-04 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-09-01', 'weekly');

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-04T00:00:00+00:00',
            '2026-07-08T00:00:00+00:00', // exclusive → Jul 4–7
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        $this->assertCount(1, $projected, 'Exactly one projected occurrence in the window');
        $start = Carbon::parse($projected->first()['start'])->utc();
        $this->assertSame('2026-07-06 12:00:00', $start->toDateTimeString(), 'Shifted to Monday, wall time preserved');
        $this->assertSame(
            'projected-' . $campaign->id . '-20260706120000',
            $projected->first()['id'],
            'Event id must be keyed on the SHIFTED timestamp, not the raw Saturday anchor',
        );

        Carbon::setTestNow();
    }

    /**
     * A monthly recurring campaign anchored on a weekend day-of-month shifts
     * that occurrence forward, but the FOLLOWING month's occurrence must keep
     * the ORIGINAL day-of-month (anti-drift) — computeNextRun() always
     * advances from the unshifted anchor, never from the shifted value.
     * 2026-08-01 is a Saturday (shifts to Monday 2026-08-03); 2026-09-01 is a
     * Tuesday (no shift needed). A drifted implementation would instead
     * advance from 2026-08-03 and land on 2026-09-03.
     */
    public function test_projection_monthly_recurring_shifts_without_date_drift(): void
    {
        Carbon::setTestNow('2026-07-15 00:00:00');

        $nextRunAt = Carbon::parse('2026-08-01 12:00:00', 'UTC'); // Saturday
        $campaign  = $this->makeRecurring($nextRunAt, '2026-10-01', 'monthly');

        $events = app(PlannerService::class)->runsFeed(
            '2026-08-01T00:00:00+00:00',
            '2026-09-02T00:00:00+00:00',
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        $dates = $projected->map(fn (array $e): string => Carbon::parse($e['start'])->utc()->toDateString())->all();

        $this->assertSame(
            ['2026-08-03', '2026-09-01'],
            $dates,
            'Aug occurrence shifts Sat→Mon; Sep occurrence keeps day-of-month=1 (no drift from the shifted Aug date)',
        );

        Carbon::setTestNow();
    }

    /**
     * REGRESSION: the emit-window gate must test $emitAt (the shifted value),
     * not the unshifted $cursor. A weekly campaign anchored on a Sunday that
     * sits just BEFORE $windowStart shifts forward to the following Monday,
     * which IS inside the window — CampaignSchedulerService::generateDueRuns()
     * will materialise a real run on that Monday, so the projection must show
     * it too. Gating on the unshifted $cursor (Sunday, before the window)
     * would silently drop the occurrence — and the loop does not self-correct:
     * the next iteration advances a full week further, so the occurrence is
     * lost, not deferred.
     */
    public function test_projection_weekly_cursor_before_window_shifts_into_window_is_emitted(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        // Sunday anchor, one second before windowStart below.
        $nextRunAt = Carbon::parse('2026-07-05 23:59:59', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-09-01', 'weekly');

        // windowStart sits just after the Sunday anchor — the unshifted
        // cursor (Sunday 23:59:59) is BEFORE windowStart, but the shifted
        // occurrence (Monday 2026-07-06, wall time preserved) is inside it.
        $windowStart = '2026-07-06T00:00:00+00:00';
        $windowEnd   = '2026-07-13T00:00:00+00:00';

        $events = app(PlannerService::class)->runsFeed($windowStart, $windowEnd);

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        $this->assertCount(1, $projected,
            'The Sunday-anchored occurrence must be emitted exactly once, on its shifted day');

        $start = Carbon::parse($projected->first()['start'])->utc();
        $this->assertSame('2026-07-06 23:59:59', $start->toDateTimeString(),
            'Shifted to Monday, wall time preserved');
        $this->assertSame(
            'projected-' . $campaign->id . '-20260706235959',
            $projected->first()['id'],
            'Event id must be keyed on the SHIFTED timestamp',
        );

        Carbon::setTestNow();
    }

    /**
     * MIRROR REGRESSION: an occurrence whose $cursor sits inside the window
     * but whose shift pushes $emitAt to or past $windowEnd must NOT be
     * rendered — it belongs to a later page than the one the user asked for.
     */
    public function test_projection_weekly_cursor_shifting_past_window_end_is_not_emitted(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        // Saturday anchor — shifts to the following Monday.
        $nextRunAt = Carbon::parse('2026-07-11 12:00:00', 'UTC');
        $campaign  = $this->makeRecurring($nextRunAt, '2026-09-01', 'weekly');

        // windowEnd sits between the unshifted Saturday cursor and its
        // shifted Monday target: cursor (Jul 11) < windowEnd (Jul 13), but
        // emitAt (Jul 13, Monday) >= windowEnd.
        $windowStart = '2026-07-06T00:00:00+00:00';
        $windowEnd   = '2026-07-13T00:00:00+00:00';

        $events = app(PlannerService::class)->runsFeed($windowStart, $windowEnd);

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        )->values();

        $this->assertCount(0, $projected,
            'An occurrence whose shifted target lands at/past windowEnd must not be emitted on this page');

        Carbon::setTestNow();
    }

    /**
     * MUST-HAVE regression guard: a materialised CampaignRun whose run_at was
     * SHIFTED by the scheduler (weekly/monthly Sat/Sun anchor) must dedup
     * against its own projection. $realRunKeys is keyed on the real run's
     * (shifted) run_at; if the projection still deduped on the unshifted
     * cursor, this would render the same occurrence TWICE — the real run
     * plus a phantom "projected-" duplicate at the wrong (unshifted) time.
     */
    public function test_projection_dedups_against_materialized_run_with_shifted_run_at(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        // Saturday anchor; the scheduler would have shifted the materialised
        // run to the following Monday while keying occurrence_key on the
        // unshifted anchor (see CampaignSchedulerService::generateDueRuns()).
        $anchor = Carbon::parse('2026-07-04 12:00:00', 'UTC');
        $shiftedRunAt = Carbon::parse('2026-07-06 12:00:00', 'UTC');
        $campaign = $this->makeRecurring($anchor, '2026-09-01', 'weekly');

        CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'rec-' . $anchor->format('YmdHis'),
            'run_at'         => $shiftedRunAt,
            'status'         => 'scheduled',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-04T00:00:00+00:00',
            '2026-07-08T00:00:00+00:00',
        ));

        $atShiftedTime = $events->filter(
            fn (array $e): bool => Carbon::parse($e['start'])->utc()->eq($shiftedRunAt)
        );

        $this->assertCount(1, $atShiftedTime, 'Exactly one event at the shifted Monday timestamp — no phantom duplicate');
        $this->assertFalse(
            str_starts_with((string) $atShiftedTime->first()['id'], 'projected-'),
            'The single event must be the real (materialised) run, not a projection',
        );
        $this->assertNull(
            $events->firstWhere('id', 'projected-' . $campaign->id . '-' . $anchor->format('YmdHis')),
            'No projection must ever be keyed on the unshifted Saturday anchor timestamp',
        );

        Carbon::setTestNow();
    }


    public function test_active_paced_sequence_projects_only_its_next_wave(): void
    {
        $nextRunAt = Carbon::parse('2026-07-28 09:00:00', 'UTC');
        $campaign = $this->makePacedSequence($nextRunAt);
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sequence-wave-000003',
            'run_at' => Carbon::parse('2026-07-20 09:00:00', 'UTC'),
            'status' => 'sent',
        ]);

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-27T00:00:00+00:00',
            '2026-07-30T00:00:00+00:00',
        );
        $projected = collect($events)->where('extendedProps.eventKind', 'sequence-wave-projection')->values();

        $this->assertCount(1, $projected);
        $this->assertSame(4, $projected[0]['extendedProps']['waveNumber']);
        $this->assertSame(1, $projected[0]['extendedProps']['stepNumber']);
        $this->assertSame(12, $projected[0]['extendedProps']['companyLimit']);
        $this->assertTrue($projected[0]['extendedProps']['launchable']);
    }

    /**
     * A paced-sequence cursor sitting on a blocked day (Sat/Sun) must project
     * on the shifted allowed day, exactly ONCE — not on the raw Saturday, not
     * twice. Mirrors PacedSequenceEnrollmentService::evaluateDue(), which
     * normalises the same blocked cursor to the next allowed local day before
     * ever evaluating it as due.
     */
    public function test_paced_sequence_blocked_cursor_projects_on_shifted_day_exactly_once(): void
    {
        // Saturday.
        $nextRunAt = Carbon::parse('2026-08-01 09:00:00', 'UTC');
        $campaign = $this->makePacedSequence($nextRunAt);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-08-01T00:00:00+00:00',
            '2026-08-05T00:00:00+00:00',
        ));
        $projected = $events->where('extendedProps.eventKind', 'sequence-wave-projection')->values();

        $this->assertCount(1, $projected, 'Exactly one projected wave — not on Saturday, not duplicated');

        $start = Carbon::parse($projected[0]['start'])->utc();
        $this->assertSame('2026-08-03 09:00:00', $start->toDateTimeString(), 'Shifted to Monday, wall time preserved');
        $this->assertSame('projected-sequence-' . $campaign->id . '-20260803090000', $projected[0]['id']);

        // Not present at the raw Saturday timestamp.
        $this->assertNull($events->firstWhere('id', 'projected-sequence-' . $campaign->id . '-20260801090000'));
    }

    public function test_inactive_paced_sequence_is_gray_and_not_launchable_but_stays_in_range_only(): void
    {
        $campaign = $this->makePacedSequence(
            Carbon::parse('2026-07-28 09:00:00', 'UTC'),
            ['is_active' => false],
        );

        $inside = app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+00:00',
            '2026-07-29T00:00:00+00:00',
        );
        $event = collect($inside)->firstWhere('id', 'projected-sequence-' . $campaign->id . '-20260728090000');

        $this->assertNotNull($event);
        $this->assertFalse($event['extendedProps']['launchable']);
        $this->assertSame('secondary', $event['extendedProps']['statusColor']);
        $this->assertSame("Inactive \u{2014} ne sera pas lanc\u{00E9}e", $event['extendedProps']['statusLabel']);

        $outside = app(PlannerService::class)->runsFeed(
            '2026-07-29T00:00:00+00:00',
            '2026-07-30T00:00:00+00:00',
        );
        $this->assertNull(collect($outside)->firstWhere('id', 'projected-sequence-' . $campaign->id . '-20260728090000'));
    }


    public function test_paced_sequence_missing_required_configuration_is_not_launchable(): void
    {
        $nextRunAt = Carbon::parse('2026-07-28 09:00:00', 'UTC');
        $campaign = $this->makePacedSequence($nextRunAt);
        $campaign->sequence->steps()->delete();

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+00:00',
            '2026-07-29T00:00:00+00:00',
        );
        $event = collect($events)->firstWhere(
            'id',
            'projected-sequence-' . $campaign->id . '-20260728090000',
        );

        $this->assertNotNull($event);
        $this->assertFalse($event['extendedProps']['launchable']);
        $this->assertNull($event['extendedProps']['stepNumber']);
        $this->assertSame('secondary', $event['extendedProps']['statusColor']);
    }
    public function test_materialized_sequence_waves_expose_numeric_step_and_legacy_metadata(): void
    {
        $campaign = $this->makePacedSequence(Carbon::parse('2026-08-01 09:00:00', 'UTC'));
        $stepTwo = $campaign->sequence->steps()->where('step_no', 2)->firstOrFail();
        $base = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sequence-wave-000007',
            'run_at' => '2026-07-25 09:00:00',
            'status' => 'sent',
        ]);
        $child = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $stepTwo->id,
            'occurrence_key' => 'sequence-wave-000007-step-002',
            'run_at' => '2026-07-26 09:00:00',
            'status' => 'scheduled',
        ]);
        $legacy = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sequence-wave-legacy-contact-1',
            'run_at' => '2026-07-27 09:00:00',
            'status' => 'sent',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed());
        $baseEvent = $events->firstWhere('id', (string) $base->id);
        $childEvent = $events->firstWhere('id', (string) $child->id);
        $legacyEvent = $events->firstWhere('id', (string) $legacy->id);

        $this->assertSame(7, $baseEvent['extendedProps']['waveNumber']);
        $this->assertSame(1, $baseEvent['extendedProps']['stepNumber']);
        $this->assertStringContainsString("Vague 7 \u{00B7} \u{00C9}tape 1", $baseEvent['title']);
        $this->assertSame(2, $childEvent['extendedProps']['stepNumber']);
        $this->assertStringContainsString("Vague 7 \u{00B7} \u{00C9}tape 2", $childEvent['title']);
        $this->assertNull($legacyEvent['extendedProps']['waveNumber']);
        $this->assertNotSame(0, $legacyEvent['extendedProps']['waveNumber']);
    }

    public function test_follow_up_at_next_run_timestamp_does_not_suppress_projected_base_wave(): void
    {
        $nextRunAt = Carbon::parse('2026-07-28 09:00:00', 'UTC');
        $campaign = $this->makePacedSequence($nextRunAt);
        $stepTwo = $campaign->sequence->steps()->where('step_no', 2)->firstOrFail();
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'sequence_step_id' => $stepTwo->id,
            'occurrence_key' => 'sequence-wave-000002-step-002',
            'run_at' => $nextRunAt,
            'status' => 'scheduled',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+00:00',
            '2026-07-29T00:00:00+00:00',
        ));

        $this->assertNotNull($events->firstWhere('id', 'projected-sequence-' . $campaign->id . '-20260728090000'));
        $this->assertCount(2, $events->filter(fn (array $event): bool => Carbon::parse($event['start'])->utc()->eq($nextRunAt)));
    }

    public function test_materialized_base_wave_at_next_run_timestamp_suppresses_projection(): void
    {
        $nextRunAt = Carbon::parse('2026-07-28 09:00:00', 'UTC');
        $campaign = $this->makePacedSequence($nextRunAt);
        CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'sequence-wave-000002',
            'run_at' => $nextRunAt,
            'status' => 'scheduled',
        ]);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-07-28T00:00:00+00:00',
            '2026-07-29T00:00:00+00:00',
        ));

        $this->assertNull($events->firstWhere('id', 'projected-sequence-' . $campaign->id . '-20260728090000'));
    }
    // ── Controller tests — schedule() recurring branch ─────────────────────────

    /**
     * POST /admin/campaigns/{id}/schedule on a recurring campaign (with next_run_at set):
     * - must NOT create any CampaignRun rows,
     * - must set campaign is_active = true,
     * - must return JSON with 'message', 'text', 'redirect' keys.
     */
    public function test_schedule_recurring_activates_without_creating_run(): void
    {
        $nextRunAt = now()->addDay()->utc();
        $campaign  = $this->makeRecurring($nextRunAt, '2027-01-01', 'daily', false);
        $this->makeEligibleClientContact();

        $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/schedule", [
                '_token' => csrf_token(),
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['message', 'text', 'redirect'])
            ->assertJsonPath('message', 'success');

        // Zero CampaignRun rows for this campaign.
        $this->assertDatabaseMissing('campaign_runs', ['campaign_id' => $campaign->id]);

        // is_active updated to true (sole live gate).
        $this->assertDatabaseHas('campaigns', [
            'id'        => $campaign->id,
            'is_active' => 1,
        ]);
    }

    /**
     * POST /admin/campaigns/{id}/schedule on a recurring campaign with next_run_at = null
     * must return HTTP 422 with a 'message' key carrying a French sentence (not 'error').
     */
    public function test_schedule_recurring_without_next_run_at_returns_422(): void
    {
        $campaign = $this->makeRecurring(now()->addDay()->utc(), null, 'daily');
        $this->makeEligibleClientContact();
        // Nullify next_run_at to simulate unconfigured recurrence.
        $campaign->update(['next_run_at' => null]);

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/campaigns/{$campaign->id}/schedule", [
                '_token' => csrf_token(),
            ]);

        $response->assertStatus(422);

        $message = $response->json('message');
        $this->assertNotSame('error', $message,
            'The 422 message key must carry the French sentence, not the literal word "error"');
        $this->assertStringContainsString('Définissez', $message,
            "422 message must contain 'Définissez'");
    }

    public function test_feed_excludes_exact_e2e_fixture_campaigns_and_projections(): void
    {
        $this->campaign->update(['name' => 'E2E_FIXTURE Materialized campaign']);
        $recurring = $this->makeRecurring(Carbon::parse('2026-08-06 09:00:00', 'UTC'));
        $recurring->update(['name' => 'E2E_FIXTURE Recurring campaign']);
        $sequence = $this->makePacedSequence(Carbon::parse('2026-08-06 10:00:00', 'UTC'));
        $sequence->update(['name' => 'E2E_FIXTURE Sequence campaign']);

        $events = collect(app(PlannerService::class)->runsFeed(
            '2026-08-05T00:00:00+00:00',
            '2026-08-07T00:00:00+00:00',
        ));

        $this->assertFalse($events->contains(fn (array $event): bool => str_starts_with($event['title'], 'E2E_FIXTURE ')));
    }
}
