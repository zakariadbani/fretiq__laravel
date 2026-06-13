<?php

namespace Tests\Feature\Backend;

use App\Jobs\SendCampaignJob;
use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\User;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\PlannerService;
use App\Services\Campaign\CampaignService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * CampaignPauseTest — covers the is_active pause switch and one-shot auto-done lifecycle.
 *
 * Tests:
 *   1. dispatchDue skips due run of is_active=false campaign — run stays 'scheduled', no SendCampaignJob dispatched.
 *   2. sendRun on run whose campaign is_active=false → run reverted to 'scheduled', no mail sent.
 *   3. one-shot sendRun completes → campaign status 'done'.
 *   4. re-send from done: scheduleOneShot re-arms 'active'; sendRun → 'done' again.
 *   5. executeSwitch POST flips is_active on recurring campaign; sequence campaign → 403.
 *   6. planner projection excludes is_active=false campaign.
 *   7. AnalyticsService / Dashboard KPI excludes paused (is_active=false).
 */
class CampaignPauseTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        config(['prospecting.cold_send_enabled' => false]);

        Mail::fake();
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeClientContact(string $email = 'jean@acme.test'): Contact
    {
        $co = Company::create([
            'name'                 => 'Acme ' . uniqid(),
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $co->id,
            'email'       => $email,
            'name'        => 'Jean Dupont',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    private function makeOneShotCampaign(bool $isActive = true): Campaign
    {
        $segment  = Segment::create(['name' => 'Seg ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl ' . uniqid(),
            'subject'      => 'Objet test',
            'html_content' => '<p>Bonjour {{contact.name}}</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply_' . uniqid() . '@tcl.test',
        ]);

        return Campaign::create([
            'name'               => 'Campagne OS ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
            'is_active'          => $isActive,
        ]);
    }

    private function makeRecurringCampaign(bool $isActive = true): Campaign
    {
        $segment  = Segment::create(['name' => 'Seg Rec ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl Rec ' . uniqid(),
            'subject'      => 'Objet récurrent',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Rec',
            'email' => 'rec_' . uniqid() . '@tcl.test',
        ]);

        return Campaign::create([
            'name'               => 'Récurrente ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => ['frequency' => 'daily', 'interval' => 1],
            'next_run_at'        => now()->subMinute(),
            'timezone'           => 'Europe/Paris',
            'status'             => 'active',
            'is_active'          => $isActive,
        ]);
    }

    private function makeSequenceCampaign(): Campaign
    {
        $segment  = Segment::create(['name' => 'Seg Seq ' . uniqid(), 'scope' => 'client']);
        $sender   = SenderIdentity::create([
            'name'  => 'TCL Seq',
            'email' => 'seq_' . uniqid() . '@tcl.test',
        ]);
        $sequence = Sequence::create([
            'name'      => 'Seq ' . uniqid(),
            'is_active' => true,
        ]);

        return Campaign::create([
            'name'               => 'Campagne Seq ' . uniqid(),
            'segment_id'         => $segment->id,
            'sender_identity_id' => $sender->id,
            'sequence_id'        => $sequence->id,
            'schedule_type'      => 'sequence',
            'timezone'           => 'Europe/Paris',
            'status'             => 'active',
            'is_active'          => true,
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * 1. dispatchDue skips a due run whose campaign is_active=false.
     *    The run stays 'scheduled'; no SendCampaignJob is dispatched.
     */
    public function test_dispatch_due_skips_paused_campaign_run(): void
    {
        Bus::fake();

        $campaign = $this->makeOneShotCampaign(isActive: false);
        $campaign->update(['status' => 'active']);

        // Create a due run manually (status=scheduled, run_at in the past).
        $run = CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'oneshot-test-' . now()->format('YmdHis'),
            'run_at'         => now()->subMinute(),
            'status'         => 'scheduled',
        ]);

        $service    = app(CampaignService::class);
        $dispatched = $service->dispatchDue();

        $this->assertSame(0, $dispatched, 'No runs should be dispatched for a paused campaign');

        // The run must still be 'scheduled' (not advanced or canceled).
        $this->assertDatabaseHas('campaign_runs', [
            'id'     => $run->id,
            'status' => 'scheduled',
        ]);

        Bus::assertNotDispatched(SendCampaignJob::class);
    }

    /**
     * 2. sendRun on a run whose campaign is_active=false reverts the run to 'scheduled'
     *    and returns without sending any mail.
     */
    public function test_send_run_defers_when_campaign_is_paused(): void
    {
        $campaign = $this->makeOneShotCampaign(isActive: false);
        $campaign->update(['status' => 'active']);

        // The run starts 'scheduled'; claim step will set it to 'sending' before the guard.
        $run = CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'oneshot-test-' . now()->format('YmdHis'),
            'run_at'         => now()->subMinute(),
            'status'         => 'scheduled',
        ]);

        $service = app(CampaignService::class);
        $service->sendRun($run);

        // Run must be reverted to 'scheduled'.
        $run->refresh();
        $this->assertSame('scheduled', $run->status,
            'Run must be reverted to "scheduled" when campaign is_active=false');

        // No mail must be sent.
        Mail::assertNothingSent();
    }

    /**
     * 3. One-shot sendRun completes → campaign status auto-flipped to 'done'.
     */
    public function test_one_shot_send_run_completes_to_done(): void
    {
        $this->makeClientContact();
        $campaign = $this->makeOneShotCampaign(isActive: true);

        $service = app(CampaignService::class);
        $run     = $service->scheduleOneShot($campaign);

        // After scheduleOneShot, campaign status must be 'active'.
        $campaign->refresh();
        $this->assertSame('active', $campaign->status);

        $service->sendRun($run);

        // After sendRun, run must be 'sent'.
        $run->refresh();
        $this->assertSame('sent', $run->status);

        // Campaign must be auto-flipped to 'done'.
        $campaign->refresh();
        $this->assertSame('done', $campaign->status,
            'One-shot campaign must be auto-flipped to "done" after sendRun');
    }

    /**
     * 4. Re-send from done: scheduleOneShot re-arms campaign to 'active', then
     *    sendRun flips it back to 'done'. Lifecycle: done → active → done.
     */
    public function test_rearm_from_done_and_send_again(): void
    {
        $this->makeClientContact();
        $campaign = $this->makeOneShotCampaign(isActive: true);
        $campaign->update(['status' => 'done']);

        $service = app(CampaignService::class);

        // Re-arm: scheduleOneShot sets status='active'.
        $run = $service->scheduleOneShot($campaign);
        $campaign->refresh();
        $this->assertSame('active', $campaign->status,
            'scheduleOneShot must re-arm a done campaign to "active"');

        // Send again → 'done'.
        $service->sendRun($run);
        $campaign->refresh();
        $this->assertSame('done', $campaign->status,
            'Campaign must be "done" again after re-arm and sendRun');
    }

    /**
     * 5a. executeSwitch POST flips is_active on a recurring campaign.
     */
    public function test_execute_switch_flips_is_active_on_recurring_campaign(): void
    {
        $campaign = $this->makeRecurringCampaign(isActive: true);

        $response = $this->actingAs($this->superadmin)
            ->putJson("/admin/campaigns/executeSwitch/{$campaign->id}", [
                'field' => 'is_active',
                'state' => '0',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('campaigns', [
            'id'        => $campaign->id,
            'is_active' => 0,
        ]);
    }

    /**
     * 5b. executeSwitch POST on a sequence campaign with field=is_active → 403 with French message.
     */
    public function test_execute_switch_rejects_is_active_on_sequence_campaign(): void
    {
        $campaign = $this->makeSequenceCampaign();

        $response = $this->actingAs($this->superadmin)
            ->putJson("/admin/campaigns/executeSwitch/{$campaign->id}", [
                'field' => 'is_active',
                'state' => '0',
            ]);

        $response->assertStatus(403);
        $this->assertStringContainsString(
            'séquence',
            strtolower($response->json('msg') ?? ''),
            'The 403 message must mention séquence',
        );

        // is_active must remain unchanged.
        $this->assertDatabaseHas('campaigns', [
            'id'        => $campaign->id,
            'is_active' => 1,
        ]);
    }

    /**
     * 6. Planner projection excludes is_active=false recurring campaign.
     */
    public function test_planner_projection_excludes_paused_campaign(): void
    {
        Carbon::setTestNow('2026-07-01 00:00:00');

        $segment  = Segment::create(['name' => 'Seg Plan ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl Plan ' . uniqid(),
            'subject'      => 'Sujet plan',
            'html_content' => '<p>Hello</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL Plan',
            'email' => 'plan_' . uniqid() . '@tcl.test',
        ]);

        $nextRunAt = Carbon::parse('2026-07-02 12:00:00', 'UTC');
        $campaign  = Campaign::create([
            'name'               => 'Pausée ' . uniqid(),
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => ['frequency' => 'daily', 'interval' => 1],
            'next_run_at'        => $nextRunAt,
            'timezone'           => 'UTC',
            'status'             => 'active',
            'is_active'          => false,
        ]);

        $events = app(PlannerService::class)->runsFeed(
            '2026-07-01T00:00:00+00:00',
            '2026-07-10T00:00:00+00:00',
        );

        $projected = collect($events)->filter(
            fn ($e) => str_starts_with((string) $e['id'], 'projected-' . $campaign->id . '-')
        );

        $this->assertCount(0, $projected,
            'A paused campaign (is_active=false) must produce zero projected events');

        Carbon::setTestNow();
    }

    /**
     * 7. AnalyticsService::dashboardKpis() active_campaigns excludes is_active=false campaigns.
     */
    public function test_kpi_excludes_paused_campaigns(): void
    {
        $segment  = Segment::create(['name' => 'Seg KPI', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Tpl KPI',
            'subject'      => 'KPI subj',
            'html_content' => '<p>Hi</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL KPI',
            'email' => 'kpi@tcl.test',
        ]);

        // Active + is_active=true → must be counted.
        Campaign::create([
            'name'               => 'Running',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
            'status'             => 'active',
            'is_active'          => true,
        ]);

        // Active + is_active=false (paused) → must NOT be counted.
        Campaign::create([
            'name'               => 'Pausée',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now(),
            'timezone'           => 'Europe/Paris',
            'status'             => 'active',
            'is_active'          => false,
        ]);

        $service = app(AnalyticsService::class);
        $kpis    = $service->dashboardKpis();

        $this->assertSame(1, (int) $kpis['active_campaigns'],
            'active_campaigns KPI must count only is_active=true campaigns');
    }
}
