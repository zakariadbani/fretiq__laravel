<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// >>> custom-test-author:planner-code

/**
 * PlannerGeneratedTest — gap-fill tests for the planner module.
 *
 * Existing coverage in PlannerFeedTest (DO NOT duplicate):
 *   - index 200 for superadmin
 *   - feed 200 JSON with seeded event (id, title, start, color, url, extendedProps)
 *   - feed accepts start/end query params
 *   - Service: runsFeed() correct FullCalendar shape, range exclusion
 *   - Projection: daily window, until boundary, far-window, dedup, tz-offset, draft excluded
 *   - Controller: schedule recurring active + 422 when next_run_at null
 *
 * This file adds the uncovered surface:
 *   - index 403 without `view campaigns` permission
 *   - index guest → 302 redirect to login
 *   - feed 403 without `view campaigns` permission
 *   - feed guest → 302 redirect to login
 *   - commercial role is allowed on index (has `view campaigns`)
 *   - commercial role is allowed on feed (has `view campaigns`)
 *   - feed with no events in DB returns an empty JSON array
 *   - feed date range excludes events outside the window (HTTP-layer assertion)
 *   - feed JSON event shape contains required FullCalendar keys
 */
class PlannerGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $noPermUser;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesSeeder::class);
        $this->seed(PermissionsSeeder::class);

        // Superadmin: all permissions
        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        // User with no roles/permissions
        $this->noPermUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);

        // Commercial: has `view campaigns`, no admin rights
        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Seed one CampaignRun with run_at at the given Carbon/datetime.
     */
    private function seedRunAt(string $runAt): CampaignRun
    {
        $segment  = Segment::create(['name' => 'Seg-' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl-' . uniqid(),
            'subject'      => 'Subj',
            'html_content' => '<p>x</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'Sender-' . uniqid(),
            'email' => 'sender' . uniqid() . '@tcl.test',
        ]);

        $campaign = Campaign::create([
            'name'               => 'Campagne-' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => $runAt,
            'timezone'           => 'UTC',
        ]);

        return CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'one_shot_' . uniqid(),
            'run_at'         => $runAt,
            'status'         => 'scheduled',
        ]);
    }

    // ── index permission gate ─────────────────────────────────────────────────

    /**
     * GET /admin/planner returns 403 for an authenticated user who has no `view campaigns`.
     */
    public function test_planner_index_403_without_view_campaigns_permission(): void
    {
        $this->actingAs($this->noPermUser)
            ->get('/admin/planner')
            ->assertStatus(403);
    }

    /**
     * GET /admin/planner redirects guests (unauthenticated) to login (302).
     */
    public function test_planner_index_redirects_guest_to_login(): void
    {
        $this->get('/admin/planner')
            ->assertStatus(302)
            ->assertRedirectContains('login');
    }

    // ── feed permission gate ──────────────────────────────────────────────────

    /**
     * GET /admin/planner/feed returns 403 for an authenticated user who has no `view campaigns`.
     */
    public function test_planner_feed_403_without_view_campaigns_permission(): void
    {
        $this->actingAs($this->noPermUser)
            ->getJson('/admin/planner/feed')
            ->assertStatus(403);
    }

    /**
     * GET /admin/planner/feed redirects guests (unauthenticated) to login (302).
     *
     * Plain get() is used here (not getJson) because the route is not under an
     * API prefix — the middleware redirects without inspecting Accept.
     */
    public function test_planner_feed_redirects_guest_to_login(): void
    {
        $this->get('/admin/planner/feed')
            ->assertStatus(302)
            ->assertRedirectContains('login');
    }

    // ── commercial role access ────────────────────────────────────────────────

    /**
     * Commercial role has `view campaigns` and must receive 200 on the planner index.
     */
    public function test_planner_index_200_for_commercial_role(): void
    {
        $this->actingAs($this->commercial)
            ->get('/admin/planner')
            ->assertStatus(200);
    }

    /**
     * Commercial role has `view campaigns` and must receive a 200 JSON array on the feed.
     */
    public function test_planner_feed_200_for_commercial_role(): void
    {
        $this->actingAs($this->commercial)
            ->getJson('/admin/planner/feed')
            ->assertStatus(200)
            ->assertJsonIsArray();
    }

    // ── feed content ──────────────────────────────────────────────────────────

    /**
     * Feed with no CampaignRun rows returns an empty JSON array (not null, not object).
     */
    public function test_planner_feed_returns_empty_array_when_no_events(): void
    {
        // No runs seeded in this test.
        $this->actingAs($this->superadmin)
            ->getJson('/admin/planner/feed')
            ->assertStatus(200)
            ->assertExactJson([]);
    }

    /**
     * Feed JSON event shape contains the required FullCalendar keys:
     * id, title, start, color, url, extendedProps.
     *
     * Asserts the shape of a single event returned for a seeded run within the window.
     */
    public function test_planner_feed_event_shape_has_required_fullcalendar_keys(): void
    {
        $run = $this->seedRunAt(now()->toDateTimeString());

        $start = now()->subDay()->toDateString();
        $end   = now()->addDay()->toDateString();

        $response = $this->actingAs($this->superadmin)
            ->getJson("/admin/planner/feed?start={$start}&end={$end}");

        $response->assertStatus(200)->assertJsonIsArray();

        $events = $response->json();
        $this->assertNotEmpty($events, 'Feed must return at least the seeded event');

        // Find our run in the array
        $event = collect($events)->firstWhere('id', (string) $run->id);
        $this->assertNotNull($event, "Feed must include event with id='{$run->id}'");

        // Required FullCalendar keys
        foreach (['id', 'title', 'start', 'color', 'url'] as $key) {
            $this->assertArrayHasKey($key, $event,
                "FullCalendar event must contain key '{$key}'");
        }

        // extendedProps with status sub-keys
        $this->assertArrayHasKey('extendedProps', $event,
            "FullCalendar event must contain 'extendedProps'");
        foreach (['status', 'statusLabel', 'statusColor'] as $prop) {
            $this->assertArrayHasKey($prop, $event['extendedProps'],
                "extendedProps must contain '{$prop}'");
        }

        // start must be a non-empty ISO 8601 string
        $this->assertNotEmpty($event['start'],
            'event start must be a non-empty ISO 8601 string');

        // color must be a hex string
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $event['color'],
            'event color must be a hex color string (#rrggbb)');
    }

    /**
     * Feed with a date range that excludes the seeded run returns an empty array (HTTP layer).
     *
     * Mirrors the service-level test in PlannerFeedTest but verified over HTTP so that
     * the query-param → service wiring is exercised end-to-end.
     */
    public function test_planner_feed_date_range_excludes_run_outside_window(): void
    {
        // Seed a run at now().
        $run = $this->seedRunAt(now()->toDateTimeString());

        // Request a past-only window (yesterday only — exclusive end = today).
        $start = now()->subDays(2)->toDateString();
        $end   = now()->subDay()->toDateString();

        $response = $this->actingAs($this->superadmin)
            ->getJson("/admin/planner/feed?start={$start}&end={$end}");

        $response->assertStatus(200)->assertJsonIsArray();

        $ids = array_column($response->json(), 'id');
        $this->assertNotContains((string) $run->id, $ids,
            'A run_at=now() event must NOT appear in a past-only date range');
    }

    /**
     * Feed with a date range that includes the seeded run returns it (HTTP layer).
     *
     * Verifies the window wiring works in the inclusive direction.
     */
    public function test_planner_feed_date_range_includes_run_within_window(): void
    {
        $run = $this->seedRunAt(now()->toDateTimeString());

        $start = now()->subDay()->toDateString();
        $end   = now()->addDay()->toDateString();

        $response = $this->actingAs($this->superadmin)
            ->getJson("/admin/planner/feed?start={$start}&end={$end}");

        $response->assertStatus(200);

        $ids = array_column($response->json(), 'id');
        $this->assertContains((string) $run->id, $ids,
            'A run_at=now() event must appear in a window that spans today');
    }
}

// <<<
