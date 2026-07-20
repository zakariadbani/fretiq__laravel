<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ACL / access-control tests for campaign scheduling and sending.
 *
 * Rules under test:
 *   - `send campaigns` is NOT granted to `commercial`.
 *   - `send campaigns` IS granted to `superadmin`.
 *   - `create campaigns` IS granted to `commercial`.
 */
class CampaignSendAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeCampaign(): Campaign
    {
        $segment = Segment::create(['name' => 'Clients', 'scope' => 'client']);

        $template = CampaignTemplate::create([
            'name'         => 'Template ACL Test',
            'subject'      => 'Sujet',
            'html_content' => '<p>Hello</p>',
        ]);

        $sender = SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);

        return Campaign::create([
            'name'               => 'Campagne ACL',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'scheduled_at'       => now()->addHour(),
            'timezone'           => 'Europe/Paris',
        ]);
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * A commercial user MUST NOT be able to call /admin/campaigns/{id}/send.
     * The `permission:send campaigns` middleware should reject with 403.
     */
    public function test_commercial_cannot_send(): void
    {
        Mail::fake();

        $campaign = $this->makeCampaign();

        $sendResponse = $this->actingAs($this->commercial)
            ->post("/admin/campaigns/{$campaign->id}/send");

        $sendResponse->assertStatus(403);
    }

    /**
     * A commercial user MUST NOT be able to call /admin/campaigns/{id}/schedule.
     */
    public function test_commercial_cannot_schedule(): void
    {
        $campaign = $this->makeCampaign();

        $scheduleResponse = $this->actingAs($this->commercial)
            ->post("/admin/campaigns/{$campaign->id}/schedule");

        $scheduleResponse->assertStatus(403);
    }

    /**
     * A superadmin CAN call /admin/campaigns/{id}/schedule.
     * The response must be 200 (JSON) or a 302 redirect, and a CampaignRun
     * with status='scheduled' must exist in the database.
     */
    public function test_admin_can_schedule(): void
    {
        $campaign = $this->makeCampaign();
        $company = Company::create([
            'name' => 'Client ACL',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);
        Contact::create([
            'company_id' => $company->id,
            'email' => 'acl-client@example.test',
            'name' => 'Client ACL',
            'status' => 'new',
            'source' => 'manual',
            'legal_basis' => 'relationship',
            'email_kind' => 'role',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/campaigns/{$campaign->id}/schedule");

        $this->assertContains(
            $response->status(),
            [200, 302],
            "Schedule endpoint should return 200 or 302, got {$response->status()}",
        );

        $this->assertDatabaseHas('campaign_runs', [
            'campaign_id' => $campaign->id,
            'status'      => 'scheduled',
        ]);
    }

    /**
     * A commercial user HAS `create campaigns` permission, so GET /admin/campaigns/create
     * must return 200.
     */
    public function test_commercial_can_create_campaign(): void
    {
        $response = $this->actingAs($this->commercial)
            ->get('/admin/campaigns/create');

        $response->assertStatus(200);
    }

    /**
     * A commercial user HAS `view campaigns` permission, so GET /admin/campaigns
     * (index) must return 200.
     */
    public function test_commercial_can_view_campaigns_index(): void
    {
        $response = $this->actingAs($this->commercial)
            ->get('/admin/campaigns');

        $response->assertStatus(200);
    }
}
