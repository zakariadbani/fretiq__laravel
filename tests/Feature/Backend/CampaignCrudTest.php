<?php

namespace Tests\Feature\Backend;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Suppression;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignCrudTest extends TestCase
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
    }

    // ── Campaigns ─────────────────────────────────────────────────────────────

    public function test_campaigns_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaigns');

        $response->assertStatus(200);
    }

    public function test_campaigns_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaigns/create');

        $response->assertStatus(200);
    }

    public function test_store_campaign(): void
    {
        $segment  = Segment::create(['name' => 'Clients Test', 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Template Test',
            'subject'      => 'Objet test',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL France',
            'email' => 'noreply@tcl.test',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaigns', [
                'name'               => 'Campagne Sprint-3a',
                'segment_id'         => $segment->id,
                'template_id'        => $template->id,
                'sender_identity_id' => $sender->id,
                'schedule_type'      => 'one_shot',
                'timezone'           => 'Europe/Paris',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('campaigns', [
            'name'               => 'Campagne Sprint-3a',
            'segment_id'         => $segment->id,
            'template_id'        => $template->id,
            'sender_identity_id' => $sender->id,
            'schedule_type'      => 'one_shot',
            'timezone'           => 'Europe/Paris',
        ]);
    }

    // ── CampaignTemplates ─────────────────────────────────────────────────────

    public function test_campaign_templates_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates');

        $response->assertStatus(200);
    }

    public function test_campaign_templates_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaign_templates/create');

        $response->assertStatus(200);
    }

    public function test_store_campaign_template(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/campaign_templates', [
                'name'         => 'Template Bienvenue',
                'subject'      => 'Bienvenue chez TCL',
                'html_content' => '<p>Bonjour {{contact.name}},</p>',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('campaign_templates', [
            'name'    => 'Template Bienvenue',
            'subject' => 'Bienvenue chez TCL',
        ]);
    }

    // ── SenderIdentities ──────────────────────────────────────────────────────

    public function test_sender_identities_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sender_identities');

        $response->assertStatus(200);
    }

    public function test_sender_identities_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sender_identities/create');

        $response->assertStatus(200);
    }

    public function test_store_sender_identity(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/sender_identities', [
                'name'  => 'TCL Prospection',
                'email' => 'prospection@tcl.test',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('sender_identities', [
            'name'  => 'TCL Prospection',
            'email' => 'prospection@tcl.test',
        ]);
    }

    /**
     * Single-default enforcement: when a second SenderIdentity is stored with
     * is_default=1, the first one must be demoted (is_default=0).
     *
     * The enforcement happens in SenderIdentityController::afterSave().
     * We create the first identity via model (bypassing the container reuse
     * issue in the test process), then trigger afterSave via a single HTTP store.
     *
     * Note: within a single PHPUnit test, successive calls to the SAME route
     * may reuse the resolved model instance from the app container. Creating
     * the first identity directly avoids this and focuses the HTTP assertion
     * on the afterSave demote logic.
     */
    public function test_single_default_sender_identity_enforced(): void
    {
        // Create the first identity as default — directly via model
        $first = SenderIdentity::create([
            'name'       => 'Identité A',
            'email'      => 'a@tcl.test',
            'is_default' => true,
        ]);

        $this->assertTrue((bool) $first->is_default, 'Pre-condition: first identity should start as default');

        // Now POST a second identity with is_default=1 — this triggers afterSave
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/sender_identities', [
                'name'       => 'Identité B',
                'email'      => 'b@tcl.test',
                'is_default' => '1',
            ]);

        $response->assertStatus(200);

        $second = SenderIdentity::where('email', 'b@tcl.test')->firstOrFail();

        // Only the last one should be default after afterSave demotes the first
        $this->assertTrue((bool) $second->fresh()->is_default, 'Second identity should be default');
        $this->assertFalse((bool) $first->fresh()->is_default, 'First identity should have been demoted');
    }

    // ── Suppressions ──────────────────────────────────────────────────────────

    public function test_suppressions_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/suppressions');

        $response->assertStatus(200);
    }

    public function test_suppressions_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/suppressions/create');

        $response->assertStatus(200);
    }

    public function test_store_suppression(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/suppressions', [
                'email'  => 'bounce@example.test',
                'reason' => 'hard_bounce',
                'source' => 'campaign',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('suppressions', [
            'email'  => 'bounce@example.test',
            'reason' => 'hard_bounce',
            'source' => 'campaign',
        ]);
    }
}
