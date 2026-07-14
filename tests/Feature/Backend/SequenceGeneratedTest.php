<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// >>> custom-test-author:sequences-code

/**
 * SequenceGeneratedTest — gap-fill test slice for the sequences module.
 *
 * Existing coverage (DO NOT duplicate):
 *   SequenceDemandeCrudTest:  index 200, create 200, datatable JSON, store happy-path,
 *                             addStep step_no=1 and auto-number to 2,
 *                             commercial cannot delete (403).
 *   SequenceStepReorderTest:  moveStepDown swaps, moveStepUp swaps,
 *                             moveUp on first rejected, moveDown on last rejected,
 *                             permission gate for both reorder directions.
 *   SequenceProcessTest:      service-level enroll / sendStep / complete /
 *                             idempotent / stopForReply.
 *   CampaignSequenceLaunchTest: enrollment wiring via campaign launch.
 *
 * This file covers the uncovered surface:
 *   - index 403 for user without view sequences permission
 *   - index guest redirect (302)
 *   - view 200 for existing sequence
 *   - edit 200 for existing sequence
 *   - store validation failure: missing required `name` → 406
 *   - update happy-path → 200 JSON {message:"success"} + assertDatabaseHas
 *   - update validation failure: missing `name` → 406
 *   - delete happy-path → 200 JSON {success:true} + assertDatabaseMissing
 *   - executeSwitch sets is_active → 1 (active)
 *   - executeSwitch sets is_active → 0 (inactive)
 *   - executeSwitch sets stop_on_reply → 1
 *   - executeSwitch sets stop_on_reply → 0
 *   - executeSwitch rejects unlisted field → 403
 *   - addStep validation failure: missing template_id → 422 ($request->validate())
 *   - deleteStep removes the row + assertDatabaseMissing
 *   - deleteStep 403 without edit sequences permission
 *   - pauseEnrollment sets status='paused' + assertDatabaseHas
 *   - resumeEnrollment sets status='active' + assertDatabaseHas
 *   - stopEnrollment sets status='stopped' + stopped_reason='manual' + assertDatabaseHas
 *   - enrollment control 403 without edit sequences permission
 */
class SequenceGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase does NOT run seeders; seed ACL manually.
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        Queue::fake();

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSequence(array $overrides = []): Sequence
    {
        return Sequence::create(array_merge([
            'name'          => 'Séquence Test ' . uniqid(),
            'is_active'     => true,
            'stop_on_reply' => false,
        ], $overrides));
    }

    private function makeTemplate(string $name = null): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => $name ?? 'Modèle ' . uniqid(),
            'subject'      => 'Sujet test',
            'html_content' => '<p>Contenu</p>',
        ]);
    }

    private function makeStep(Sequence $sequence, int $stepNo = 1, array $overrides = []): SequenceStep
    {
        $template = $this->makeTemplate();
        return SequenceStep::create(array_merge([
            'sequence_id' => $sequence->id,
            'step_no'     => $stepNo,
            'delay_days'  => 0,
            'template_id' => $template->id,
            'subject'     => 'Étape ' . $stepNo,
        ], $overrides));
    }

    /**
     * Build a Contact row (requires a Company parent).
     * SequenceEnrollment.contact_id FK requires a real contacts row.
     */
    private function makeContact(): Contact
    {
        $company = Company::create([
            'name'                 => 'Société ' . uniqid(),
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id'  => $company->id,
            'email'       => 'contact_' . uniqid() . '@example.com',
            'name'        => 'Contact Test',
            'status'      => 'new',
            'source'      => 'manual',
            'legal_basis' => 'relationship',
            'email_kind'  => 'role',
        ]);
    }

    /**
     * Build a SequenceEnrollment in the given status for the given sequence/contact.
     */
    private function makeEnrollment(Sequence $sequence, Contact $contact, string $status = 'active'): SequenceEnrollment
    {
        return SequenceEnrollment::create([
            'sequence_id'    => $sequence->id,
            'contact_id'     => $contact->id,
            'current_step'   => 0,
            'status'         => $status,
            'stopped_reason' => null,
        ]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    /**
     * Unauthenticated request to sequences index must redirect (302 to login).
     */
    public function test_index_redirects_for_guest(): void
    {
        $response = $this->get('/admin/sequences');

        $response->assertRedirect();
    }

    /**
     * A user with backend.access but WITHOUT `view sequences` must receive 403.
     */
    public function test_index_403_for_user_without_view_permission(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->givePermissionTo('backend.access');

        $response = $this->actingAs($user)
            ->get('/admin/sequences');

        $response->assertStatus(403);
    }

    // ── View / Edit pages ─────────────────────────────────────────────────────

    /**
     * GET /admin/sequences/{id} returns 200 for an existing sequence.
     */
    public function test_view_renders_for_existing_sequence(): void
    {
        $sequence = $this->makeSequence();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sequences/' . $sequence->id);

        $response->assertStatus(200);
    }

    /**
     * GET /admin/sequences/{id}/edit returns 200 for an existing sequence.
     */
    public function test_edit_renders_for_existing_sequence(): void
    {
        $sequence = $this->makeSequence();

        $response = $this->actingAs($this->superadmin)
            ->get('/admin/sequences/' . $sequence->id . '/edit');

        $response->assertStatus(200);
    }

    // ── Store validation ──────────────────────────────────────────────────────

    /**
     * Store must return 406 when `name` is missing.
     *
     * Sequence.rules(): name => 'required|string|max:255'
     * Crudable trait calls $model->validator() and returns JSON 406 on failure.
     */
    public function test_store_validation_fails_when_name_missing(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->postJson('/admin/sequences', [
                // name intentionally omitted
                'is_active'     => '1',
                'stop_on_reply' => '0',
            ]);

        $response->assertStatus(406);
        $response->assertJsonStructure(['message', 'errors' => ['name']]);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    // ── Update happy-path ─────────────────────────────────────────────────────

    /**
     * PUT /admin/sequences/{id} with valid data mutates the row and returns JSON 200.
     */
    public function test_update_mutates_sequence(): void
    {
        $sequence = $this->makeSequence(['name' => 'Nom Original']);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/' . $sequence->id, [
                'name'          => 'Nom Mis à Jour',
                'is_active'     => '1',
                'stop_on_reply' => '0',
            ]);

        // Crudable returns JSON 200 with message:"success" (not an HTTP 3xx).
        $response->assertStatus(200);
        $response->assertJson(['message' => 'success']);
        $this->assertDatabaseHas('sequences', [
            'id'   => $sequence->id,
            'name' => 'Nom Mis à Jour',
        ]);
    }

    // SCR-52 boolean form regression coverage.
    public function test_update_persists_unchecked_boolean_fields(): void
    {
        $sequence = $this->makeSequence([
            'is_active'     => true,
            'stop_on_reply' => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/' . $sequence->id, [
                'name'          => $sequence->name,
                'is_active'     => '0',
                'stop_on_reply' => '0',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('sequences', [
            'id'            => $sequence->id,
            'is_active'     => 0,
            'stop_on_reply' => 0,
        ]);
    }

    public function test_update_keeps_checked_boolean_fields_true(): void
    {
        $sequence = $this->makeSequence([
            'is_active'     => false,
            'stop_on_reply' => false,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/' . $sequence->id, [
                'name'          => $sequence->name,
                'is_active'     => '1',
                'stop_on_reply' => '1',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('sequences', [
            'id'            => $sequence->id,
            'is_active'     => 1,
            'stop_on_reply' => 1,
        ]);
    }

    public function test_edit_form_places_hidden_boolean_inputs_before_checkboxes(): void
    {
        $sequence = $this->makeSequence();

        $html = $this->actingAs($this->superadmin)
            ->get('/admin/sequences/' . $sequence->id . '/edit')
            ->assertStatus(200)
            ->getContent();

        $isActiveHidden = strpos($html, 'type="hidden" name="is_active" value="0"');
        $isActiveCheckbox = strpos($html, 'name="is_active"', $isActiveHidden + 1);
        $stopOnReplyHidden = strpos($html, 'type="hidden" name="stop_on_reply" value="0"');
        $stopOnReplyCheckbox = strpos($html, 'name="stop_on_reply"', $stopOnReplyHidden + 1);

        $this->assertNotFalse($isActiveHidden);
        $this->assertNotFalse($isActiveCheckbox);
        $this->assertLessThan($isActiveCheckbox, $isActiveHidden);
        $this->assertNotFalse($stopOnReplyHidden);
        $this->assertNotFalse($stopOnReplyCheckbox);
        $this->assertLessThan($stopOnReplyCheckbox, $stopOnReplyHidden);
    }

    public function test_edit_form_uses_external_step_action_forms(): void
    {
        $sequence = $this->makeSequence();
        $step1 = $this->makeStep($sequence, 1);
        $step2 = $this->makeStep($sequence, 2);

        $html = $this->actingAs($this->superadmin)
            ->get('/admin/sequences/' . $sequence->id . '/edit')
            ->assertStatus(200)
            ->getContent();

        $this->assertMatchesRegularExpression('/<button[^>]+form="sequence_step_move_down_' . $step1->id . '"/s', $html);
        $this->assertMatchesRegularExpression('/<form[^>]+id="sequence_step_move_down_' . $step1->id . '"[^>]+action="' . preg_quote(route('admin.sequences.moveStepDown', [$sequence->id, $step1->id]), '/') . '"/s', $html);

        $this->assertMatchesRegularExpression('/<button[^>]+form="sequence_step_move_up_' . $step2->id . '"/s', $html);
        $this->assertMatchesRegularExpression('/<form[^>]+id="sequence_step_move_up_' . $step2->id . '"[^>]+action="' . preg_quote(route('admin.sequences.moveStepUp', [$sequence->id, $step2->id]), '/') . '"/s', $html);

        $this->assertMatchesRegularExpression('/<button[^>]+form="sequence_step_delete_' . $step1->id . '"/s', $html);
        $this->assertMatchesRegularExpression('/<form[^>]+id="sequence_step_delete_' . $step1->id . '"[^>]+action="' . preg_quote(route('admin.sequences.deleteStep', [$sequence->id, $step1->id]), '/') . '"/s', $html);
    }

    /**
     * PUT /admin/sequences/{id} with missing name must return 406.
     *
     * Validation goes through Crudable → model::validator() → 406.
     */
    public function test_update_validation_fails_when_name_missing(): void
    {
        $sequence = $this->makeSequence();

        $response = $this->actingAs($this->superadmin)
            ->putJson('/admin/sequences/' . $sequence->id, [
                // name intentionally omitted
                'is_active' => '1',
            ]);

        $response->assertStatus(406);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/sequences/{id} removes the row and returns JSON {success:true}.
     */
    public function test_delete_removes_sequence(): void
    {
        $sequence = $this->makeSequence();
        $id       = $sequence->id;

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/sequences/' . $id);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('sequences', ['id' => $id]);
    }

    // ── executeSwitch ─────────────────────────────────────────────────────────

    /**
     * SequenceController::$toggleableFields = ['is_active', 'stop_on_reply'].
     *
     * PUT /admin/sequences/executeSwitch/{id} field=is_active state='1' sets active.
     */
    public function test_execute_switch_sets_is_active_on(): void
    {
        $sequence = $this->makeSequence(['is_active' => 0]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/executeSwitch/' . $sequence->id, [
                'field' => 'is_active',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('sequences', ['id' => $sequence->id, 'is_active' => 1]);
    }

    /**
     * PUT /admin/sequences/executeSwitch/{id} field=is_active state='0' sets inactive.
     *
     * Isolated: own fresh sequence, opposite initial state.
     */
    public function test_execute_switch_sets_is_active_off(): void
    {
        $sequence = $this->makeSequence(['is_active' => 1]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/executeSwitch/' . $sequence->id, [
                'field' => 'is_active',
                'state' => '0',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('sequences', ['id' => $sequence->id, 'is_active' => 0]);
    }

    /**
     * PUT /admin/sequences/executeSwitch/{id} field=stop_on_reply state='1' sets to true.
     */
    public function test_execute_switch_sets_stop_on_reply_on(): void
    {
        $sequence = $this->makeSequence(['stop_on_reply' => 0]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/executeSwitch/' . $sequence->id, [
                'field' => 'stop_on_reply',
                'state' => '1',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('sequences', ['id' => $sequence->id, 'stop_on_reply' => 1]);
    }

    /**
     * PUT /admin/sequences/executeSwitch/{id} field=stop_on_reply state='0' sets to false.
     */
    public function test_execute_switch_sets_stop_on_reply_off(): void
    {
        $sequence = $this->makeSequence(['stop_on_reply' => 1]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/executeSwitch/' . $sequence->id, [
                'field' => 'stop_on_reply',
                'state' => '0',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertDatabaseHas('sequences', ['id' => $sequence->id, 'stop_on_reply' => 0]);
    }

    /**
     * executeSwitch must return 403 when a field not in $toggleableFields is requested.
     *
     * $toggleableFields = ['is_active', 'stop_on_reply'] — 'name' is off-whitelist.
     */
    public function test_execute_switch_rejects_unlisted_field(): void
    {
        $sequence = $this->makeSequence();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/sequences/executeSwitch/' . $sequence->id, [
                'field' => 'name',  // not in toggleableFields
                'state' => '1',
            ]);

        $response->assertStatus(403);
    }

    // ── addStep validation failure ────────────────────────────────────────────

    /**
     * addStep uses $request->validate() (not Crudable validator) → 422 on failure.
     *
     * Rules: delay_days=required|integer|min:0, template_id=required|integer|exists:..., subject=nullable.
     * Omitting template_id must return 422 with errors.template_id.
     */
    public function test_add_step_validation_fails_when_template_id_missing(): void
    {
        $sequence = $this->makeSequence();

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/sequences/{$sequence->id}/steps", [
                'delay_days' => 2,
                // template_id intentionally omitted
                'subject'    => 'Étape sans modèle',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('template_id', $response->json('errors'));
    }

    /**
     * addStep validation: missing delay_days → 422.
     */
    public function test_add_step_validation_fails_when_delay_days_missing(): void
    {
        $sequence = $this->makeSequence();
        $template = $this->makeTemplate();

        $response = $this->actingAs($this->superadmin)
            ->postJson("/admin/sequences/{$sequence->id}/steps", [
                // delay_days intentionally omitted
                'template_id' => $template->id,
                'subject'     => 'Étape sans délai',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('delay_days', $response->json('errors'));
    }

    // ── deleteStep ────────────────────────────────────────────────────────────

    /**
     * DELETE /admin/sequences/{id}/steps/{stepId} removes the row and redirects.
     *
     * deleteStep uses redirect()->back() — it is not a JSON endpoint.
     */
    public function test_delete_step_removes_the_row(): void
    {
        $sequence = $this->makeSequence();
        $step     = $this->makeStep($sequence, stepNo: 1);
        $stepId   = $step->id;

        $response = $this->actingAs($this->superadmin)
            ->delete("/admin/sequences/{$sequence->id}/steps/{$stepId}");

        // Controller redirects back — not a JSON 200 response.
        $response->assertRedirect();
        $this->assertDatabaseMissing('sequence_steps', ['id' => $stepId]);
    }

    /**
     * DELETE /admin/sequences/{id}/steps/{stepId} returns 403 without edit sequences permission.
     *
     * Commercial has view/create sequences but NOT edit sequences — so step deletion is blocked.
     */
    public function test_delete_step_403_without_edit_permission(): void
    {
        $sequence = $this->makeSequence();
        $step     = $this->makeStep($sequence, stepNo: 1);

        // Build a user who only has backend.access (no edit sequences)
        $noEditUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $noEditUser->givePermissionTo('backend.access');

        $response = $this->actingAs($noEditUser)
            ->delete("/admin/sequences/{$sequence->id}/steps/{$step->id}");

        $response->assertStatus(403);
        // The step must still exist.
        $this->assertDatabaseHas('sequence_steps', ['id' => $step->id]);
    }

    // ── Enrollment control ────────────────────────────────────────────────────

    /**
     * POST /sequences/{id}/enrollments/{enrId}/pause sets status='paused'.
     *
     * Real enrollment statuses: 'active', 'paused', 'stopped', 'completed'
     * (from controller source — pauseEnrollment writes 'paused').
     */
    public function test_pause_enrollment_sets_status_paused(): void
    {
        $sequence   = $this->makeSequence();
        $contact    = $this->makeContact();
        $enrollment = $this->makeEnrollment($sequence, $contact, status: 'active');

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/sequences/{$sequence->id}/enrollments/{$enrollment->id}/pause");

        // Controller does redirect()->back() — not JSON.
        $response->assertRedirect();
        $this->assertDatabaseHas('sequence_enrollments', [
            'id'     => $enrollment->id,
            'status' => 'paused',
        ]);
    }

    /**
     * POST /sequences/{id}/enrollments/{enrId}/resume sets status='active'.
     *
     * resumeEnrollment writes status='active' and clears stopped_reason.
     */
    public function test_resume_enrollment_sets_status_active(): void
    {
        $sequence   = $this->makeSequence();
        $contact    = $this->makeContact();
        $enrollment = $this->makeEnrollment($sequence, $contact, status: 'paused');

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/sequences/{$sequence->id}/enrollments/{$enrollment->id}/resume");

        $response->assertRedirect();
        $this->assertDatabaseHas('sequence_enrollments', [
            'id'             => $enrollment->id,
            'status'         => 'active',
            'stopped_reason' => null,
        ]);
    }

    /**
     * POST /sequences/{id}/enrollments/{enrId}/stop sets status='stopped' and stopped_reason='manual'.
     *
     * stopEnrollment writes status='stopped' and stopped_reason='manual'.
     */
    public function test_stop_enrollment_sets_status_stopped(): void
    {
        $sequence   = $this->makeSequence();
        $contact    = $this->makeContact();
        $enrollment = $this->makeEnrollment($sequence, $contact, status: 'active');

        $response = $this->actingAs($this->superadmin)
            ->post("/admin/sequences/{$sequence->id}/enrollments/{$enrollment->id}/stop");

        $response->assertRedirect();
        $this->assertDatabaseHas('sequence_enrollments', [
            'id'             => $enrollment->id,
            'status'         => 'stopped',
            'stopped_reason' => 'manual',
        ]);
    }

    /**
     * Enrollment control actions require `edit sequences` permission.
     *
     * A user without that permission must receive 403 on pauseEnrollment.
     */
    public function test_pause_enrollment_403_without_edit_permission(): void
    {
        $sequence   = $this->makeSequence();
        $contact    = $this->makeContact();
        $enrollment = $this->makeEnrollment($sequence, $contact, status: 'active');

        $noEditUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $noEditUser->givePermissionTo('backend.access');

        $response = $this->actingAs($noEditUser)
            ->post("/admin/sequences/{$sequence->id}/enrollments/{$enrollment->id}/pause");

        $response->assertStatus(403);
        // Status must remain unchanged.
        $this->assertDatabaseHas('sequence_enrollments', [
            'id'     => $enrollment->id,
            'status' => 'active',
        ]);
    }

    /**
     * resumeEnrollment requires `edit sequences` permission — 403 without it.
     */
    public function test_resume_enrollment_403_without_edit_permission(): void
    {
        $sequence   = $this->makeSequence();
        $contact    = $this->makeContact();
        $enrollment = $this->makeEnrollment($sequence, $contact, status: 'paused');

        $noEditUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $noEditUser->givePermissionTo('backend.access');

        $response = $this->actingAs($noEditUser)
            ->post("/admin/sequences/{$sequence->id}/enrollments/{$enrollment->id}/resume");

        $response->assertStatus(403);
        $this->assertDatabaseHas('sequence_enrollments', [
            'id'     => $enrollment->id,
            'status' => 'paused',
        ]);
    }

    /**
     * stopEnrollment requires `edit sequences` permission — 403 without it.
     */
    public function test_stop_enrollment_403_without_edit_permission(): void
    {
        $sequence   = $this->makeSequence();
        $contact    = $this->makeContact();
        $enrollment = $this->makeEnrollment($sequence, $contact, status: 'active');

        $noEditUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $noEditUser->givePermissionTo('backend.access');

        $response = $this->actingAs($noEditUser)
            ->post("/admin/sequences/{$sequence->id}/enrollments/{$enrollment->id}/stop");

        $response->assertStatus(403);
        $this->assertDatabaseHas('sequence_enrollments', [
            'id'     => $enrollment->id,
            'status' => 'active',
        ]);
    }
}

// <<<
