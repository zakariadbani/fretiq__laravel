<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Demande;
use App\Models\Sequence;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level CRUD tests for sequences and demandes.
 *
 * Covers:
 *  - index + create page renders
 *  - store + assertDatabaseHas
 *  - addStep auto-numbering
 *  - datatable JSON endpoint
 *  - ACL gating: commercial cannot delete sequences; commercial CAN create demandes
 */
class SequenceDemandeCrudTest extends TestCase
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

    // ── Sequences ─────────────────────────────────────────────────────────────

    public function test_sequences_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sequences');

        $response->assertStatus(200);
    }

    public function test_sequences_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sequences/create');

        $response->assertStatus(200);
    }

    public function test_sequences_datatable_json(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sequences', [
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept'           => 'application/json',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_store_sequence(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/sequences', [
                'name'          => 'Ma Séquence Test',
                'is_active'     => '1',
                'stop_on_reply' => '1',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('sequences', [
            'name' => 'Ma Séquence Test',
        ]);
    }

    /**
     * addStep: POST /sequences/{id}/steps creates a SequenceStep with auto-incremented step_no.
     */
    public function test_add_step_to_sequence(): void
    {
        $sequence = Sequence::create([
            'name'          => 'Seq Add Step',
            'is_active'     => true,
            'stop_on_reply' => false,
        ]);

        $template = CampaignTemplate::create([
            'name'         => 'Tpl Step',
            'subject'      => 'Step Subject',
            'html_content' => '<p>Hello</p>',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/sequences/{$sequence->id}/steps", [
                'delay_days'  => 2,
                'template_id' => $template->id,
                'subject'     => 'Mon Étape 1',
            ]);

        // The controller redirects back to the view page
        $response->assertRedirect();

        $this->assertDatabaseHas('sequence_steps', [
            'sequence_id' => $sequence->id,
            'step_no'     => 1,
            'delay_days'  => 2,
            'template_id' => $template->id,
            'subject'     => 'Mon Étape 1',
        ]);
    }

    /**
     * Adding a step when step_no=1 already exists auto-assigns step_no=2.
     * A pre-existing SequenceStep row is created directly (no HTTP call) so
     * we test addStep with a clean, isolated single HTTP request.
     */
    public function test_add_second_step_auto_numbers(): void
    {
        $sequence = Sequence::create([
            'name'          => 'Seq Auto Step',
            'is_active'     => true,
            'stop_on_reply' => false,
        ]);

        $template = CampaignTemplate::create([
            'name'         => 'Tpl Auto',
            'subject'      => 'Auto',
            'html_content' => '<p>Auto</p>',
        ]);

        // Seed step 1 directly so max(step_no)=1
        \App\Models\SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $template->id,
            'subject'     => 'Étape 1',
        ]);

        // HTTP call for step 2 — should get step_no = max(1)+1 = 2
        $this->actingAs($this->superadmin)
            ->post("/admin/sequences/{$sequence->id}/steps", [
                'delay_days'  => 3,
                'template_id' => $template->id,
                'subject'     => 'Étape 2',
            ]);

        $this->assertDatabaseHas('sequence_steps', [
            'sequence_id' => $sequence->id,
            'step_no'     => 2,
            'delay_days'  => 3,
        ]);
    }

    /**
     * Commercial cannot delete a sequence (403).
     */
    public function test_commercial_cannot_delete_sequence(): void
    {
        $sequence = Sequence::create([
            'name'          => 'Seq No Delete',
            'is_active'     => false,
            'stop_on_reply' => false,
        ]);

        $response = $this->actingAs($this->commercial)
            ->delete("/admin/sequences/{$sequence->id}");

        $response->assertStatus(403);
    }

    // ── Demandes ──────────────────────────────────────────────────────────────

    public function test_demandes_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/demandes');

        $response->assertStatus(200);
    }

    public function test_demandes_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/demandes/create');

        $response->assertStatus(200);
    }

    public function test_store_demande(): void
    {
        $co = Company::create([
            'name'                 => 'Acme',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id'  => $co->id,
            'email'       => 'j@acme.test',
            'name'        => 'J',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/demandes', [
                'contact_id'  => $contact->id,
                'kind'        => 'reply',
                'status'      => 'pending',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('demandes', [
            'contact_id' => $contact->id,
            'kind'       => 'reply',
            'status'     => 'pending',
        ]);
    }

    /**
     * Commercial CAN create demandes — the permission is granted to commercial role.
     */
    public function test_commercial_can_create_demandes(): void
    {
        $co = Company::create([
            'name'                 => 'Acme Commercial',
            'relationship'         => 'client',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);
        $contact = Contact::create([
            'company_id'  => $co->id,
            'email'       => 'jc@acme.test',
            'name'        => 'JC',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);

        $response = $this->actingAs($this->commercial)
            ->post('/admin/demandes', [
                'contact_id'  => $contact->id,
                'kind'        => 'manual',
                'status'      => 'pending',
            ]);

        // Should not be 403
        $response->assertStatus(200);

        $this->assertDatabaseHas('demandes', [
            'contact_id' => $contact->id,
            'kind'       => 'manual',
        ]);
    }
}
