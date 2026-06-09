<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Analytics\PlannerService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlannerFeedTest — service-level and HTTP-layer tests for the campaign planner calendar feed.
 *
 * The PlannerService returns FullCalendar-shaped events (id, title, start, color, url).
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

        // Seed a campaign + a scheduled run with run_at = now()
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

    // ── Service-level tests ────────────────────────────────────────────────────

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

    // ── HTTP tests ─────────────────────────────────────────────────────────────

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
}
