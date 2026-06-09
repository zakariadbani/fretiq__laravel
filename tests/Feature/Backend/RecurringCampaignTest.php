<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
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
        string $status = 'active',
        ?Carbon $nextRunAt = null,
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
            'status'             => $status,
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * A recurring campaign with next_run_at in the past generates exactly one
     * CampaignRun (status=scheduled) and advances next_run_at into the future.
     */
    public function test_generate_due_runs_creates_run_and_advances_next_run_at(): void
    {
        $campaign = $this->makeRecurringCampaign('active', now()->subMinute());

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
        $campaign = $this->makeRecurringCampaign('active', now()->subMinute());

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
     * A recurring campaign with status='paused' must be skipped entirely,
     * even when next_run_at is in the past.
     */
    public function test_paused_campaign_skipped(): void
    {
        $campaign = $this->makeRecurringCampaign('paused', now()->subMinute());

        $service = app(CampaignSchedulerService::class);
        $count   = $service->generateDueRuns();

        $this->assertSame(0, $count, 'Paused campaign must generate 0 runs');

        $this->assertDatabaseMissing('campaign_runs', [
            'campaign_id' => $campaign->id,
        ]);
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
}
