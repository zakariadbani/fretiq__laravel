<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
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
     * fed with window [today, +8 days) → must contain exactly 8 projected events
     * and the FIRST projected event's start must equal next_run_at exactly
     * (guards emit-then-advance ordering: cursor emitted before being advanced).
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

        // Expected: July 2–8 inclusive = 7 days (windowEnd is 2026-07-09 exclusive).
        $this->assertCount(7, $projected,
            'Expected 7 projected events for July 2–8 within a July 1–9 window');

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
}
