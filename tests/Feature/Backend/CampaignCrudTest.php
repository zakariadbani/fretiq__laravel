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

    private function makeCampaignFixtures(): array
    {
        $segment = Segment::create(['name' => 'Clients Test ' . uniqid(), 'scope' => 'client']);
        $template = CampaignTemplate::create([
            'name'         => 'Template Test ' . uniqid(),
            'subject'      => 'Objet test',
            'html_content' => '<p>Bonjour</p>',
        ]);
        $sender = SenderIdentity::create([
            'name'  => 'TCL France ' . uniqid(),
            'email' => 'noreply_' . uniqid() . '@tcl.test',
        ]);

        return compact('segment', 'template', 'sender');
    }

    private function makeRecurringCampaign(bool $isActive = false, mixed $nextRunAt = null): Campaign
    {
        $fixtures = $this->makeCampaignFixtures();

        return Campaign::create([
            'name'               => 'Campagne récurrente ' . uniqid(),
            'segment_id'         => $fixtures['segment']->id,
            'template_id'        => $fixtures['template']->id,
            'sender_identity_id' => $fixtures['sender']->id,
            'schedule_type'      => 'recurring',
            'recurrence'         => ['frequency' => 'daily', 'interval' => 1],
            'next_run_at'        => $nextRunAt,
            'timezone'           => 'Europe/Paris',
            'is_active'          => $isActive,
        ]);
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

    public function test_campaign_form_renders_template_preview_popover_data(): void
    {
        CampaignTemplate::create([
            'name'         => 'Modèle Port Maritime',
            'subject'      => 'Besoin transport maritime',
            'preview_text' => 'Une opportunité logistique ciblée.',
            'html_content' => '<h1>Bonjour {{contact.name}}</h1><p>Voici notre offre.</p>',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/campaigns/create');

        $response->assertStatus(200);
        $response->assertSee('template-preview-button', false);
        $response->assertSee('campaignTemplatePreviews', false);
        $response->assertSee('Aperçu du modèle d\'email', false);
        $response->assertSee('Modèle Port Maritime', false);
        $response->assertSee('Besoin transport maritime', false);
        $response->assertSee('preview_text', false);
        $response->assertSee('campaign-template-preview-popover', false);
    }

    public function test_campaign_edit_uses_form_status_switch_instead_of_header_ajax_toggle(): void
    {
        $campaign = $this->makeRecurringCampaign(isActive: false);

        $response = $this->actingAs($this->superadmin)
            ->get("/admin/campaigns/{$campaign->id}/edit");

        $response->assertStatus(200);
        $body = $response->getContent();

        $this->assertStringContainsString('id="is_active_toggle"', $body);
        $this->assertStringContainsString('class="required fw-semibold fs-6 mb-2">Premier envoi</label>', $body);
        $this->assertStringContainsString('Obligatoire pour activer une campagne récurrente.', $body);
        $this->assertStringNotContainsString('status-toggle', $body,
            'Edit page must not render the hero AJAX status toggle; status is saved with the form.');
    }

    public function test_recurring_campaign_cannot_be_reactivated_without_next_run_at(): void
    {
        $campaign = $this->makeRecurringCampaign(isActive: false, nextRunAt: null);

        $response = $this->actingAs($this->superadmin)
            ->putJson("/admin/campaigns/{$campaign->id}", [
                'name'               => $campaign->name,
                'segment_id'         => $campaign->segment_id,
                'template_id'        => $campaign->template_id,
                'sender_identity_id' => $campaign->sender_identity_id,
                'schedule_type'      => 'recurring',
                'recurrence_frequency' => 'daily',
                'recurrence_interval'  => 1,
                'next_run_at'        => '',
                'timezone'           => 'Europe/Paris',
                'is_active'          => '1',
            ]);

        $response->assertStatus(406)
            ->assertJsonValidationErrors(['next_run_at']);

        $message = $response->json('errors.next_run_at.0') ?? '';
        $this->assertStringContainsString('Premier envoi', $message);
        $this->assertStringNotContainsString('attribute.next_run_at', $message);

        $this->assertDatabaseHas('campaigns', [
            'id'        => $campaign->id,
            'is_active' => 0,
        ]);
    }

    public function test_inactive_recurring_campaign_can_be_saved_without_next_run_at(): void
    {
        $campaign = $this->makeRecurringCampaign(isActive: false, nextRunAt: null);

        $response = $this->actingAs($this->superadmin)
            ->putJson("/admin/campaigns/{$campaign->id}", [
                'name'                 => $campaign->name . ' modifiée',
                'segment_id'           => $campaign->segment_id,
                'template_id'          => $campaign->template_id,
                'sender_identity_id'   => $campaign->sender_identity_id,
                'schedule_type'        => 'recurring',
                'recurrence_frequency' => 'daily',
                'recurrence_interval'  => 1,
                'next_run_at'          => '',
                'timezone'             => 'Europe/Paris',
                'is_active'            => '0',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('campaigns', [
            'id'        => $campaign->id,
            'name'      => $campaign->name . ' modifiée',
            'is_active' => 0,
        ]);
    }

    public function test_recurring_campaign_can_be_reactivated_with_next_run_at(): void
    {
        $campaign = $this->makeRecurringCampaign(isActive: false, nextRunAt: null);
        $nextRunAt = now()->addDay()->format('Y-m-d H:i');

        $response = $this->actingAs($this->superadmin)
            ->putJson("/admin/campaigns/{$campaign->id}", [
                'name'                 => $campaign->name,
                'segment_id'           => $campaign->segment_id,
                'template_id'          => $campaign->template_id,
                'sender_identity_id'   => $campaign->sender_identity_id,
                'schedule_type'        => 'recurring',
                'recurrence_frequency' => 'daily',
                'recurrence_interval'  => 1,
                'next_run_at'          => $nextRunAt,
                'timezone'             => 'Europe/Paris',
                'is_active'            => '1',
            ]);

        $response->assertStatus(200);

        $campaign->refresh();
        $this->assertTrue((bool) $campaign->is_active);
        $this->assertNotNull($campaign->next_run_at);
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
