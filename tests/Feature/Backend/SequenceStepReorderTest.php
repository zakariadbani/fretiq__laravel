<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test 13 — §7ter #4 step reorder.
 *
 * Covers:
 *   - move-down swaps step_no with neighbor (both DB rows asserted)
 *   - unique(sequence_id, step_no) is respected (no constraint violation)
 *   - move-up on first step is rejected (rows unchanged)
 *   - permission gate: 403/redirect for an unauthorized user
 */
class SequenceStepReorderTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;
    private User $noPermUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // User with 'edit sequences' permission (via superadmin role)
        $this->editor = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->editor->assignRole('superadmin');

        // User without any sequences permission
        $this->noPermUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        // no role assigned — no permissions
    }

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function makeTemplate(string $name = 'Template'): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name'         => $name,
            'subject'      => 'Sujet test',
            'html_content' => '<p>Contenu</p>',
        ]);
    }

    /**
     * Build a sequence with 3 steps (step_no 1, 2, 3).
     * delay_days: 0, 3, 7 — to verify cumulative logic doesn't affect reorder.
     */
    private function makeThreeStepSequence(): Sequence
    {
        $seq = Sequence::create([
            'name'          => 'Séquence Réordonnancement',
            'is_active'     => true,
            'stop_on_reply' => false,
        ]);

        $tpl1 = $this->makeTemplate('Modèle 1');
        $tpl2 = $this->makeTemplate('Modèle 2');
        $tpl3 = $this->makeTemplate('Modèle 3');

        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 1,
            'delay_days'  => 0,
            'template_id' => $tpl1->id,
            'subject'     => 'Étape 1',
        ]);

        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 2,
            'delay_days'  => 3,
            'template_id' => $tpl2->id,
            'subject'     => 'Étape 2',
        ]);

        SequenceStep::create([
            'sequence_id' => $seq->id,
            'step_no'     => 3,
            'delay_days'  => 7,
            'template_id' => $tpl3->id,
            'subject'     => 'Étape 3',
        ]);

        return $seq;
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Test 13a — move-down swaps step_no with the next neighbor.
     *
     * Starting: step1(step_no=1), step2(step_no=2), step3(step_no=3)
     * Move step1 down → step1 should become step_no=2, step2 should become step_no=1.
     * Step3 is unaffected.
     * The unique(sequence_id, step_no) constraint must not be violated (no exception thrown).
     */
    public function test_move_down_swaps_step_no_with_neighbor(): void
    {
        $seq = $this->makeThreeStepSequence();

        $step1 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 1)->firstOrFail();
        $step2 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 2)->firstOrFail();
        $step3 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 3)->firstOrFail();

        $response = $this->actingAs($this->editor)
            ->post(route('admin.sequences.moveStepDown', [$seq->id, $step1->id]));

        // Should redirect (flash + redirect pattern)
        $response->assertRedirect(route('admin.sequences.view', $seq->id) . '#sequence_steps');

        // step1 must now have step_no = 2
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step1->id,
            'step_no' => 2,
        ]);

        // step2 must now have step_no = 1
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step2->id,
            'step_no' => 1,
        ]);

        // step3 is unchanged
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step3->id,
            'step_no' => 3,
        ]);

        // Confirm unique constraint is intact: exactly 3 rows, no duplicate step_no
        $stepNos = SequenceStep::where('sequence_id', $seq->id)->pluck('step_no')->sort()->values()->toArray();
        $this->assertSame([1, 2, 3], $stepNos, 'step_no values must remain 1,2,3 with no duplicates');
    }

    /**
     * Test 13b — move-up swaps step_no with the previous neighbor.
     *
     * Starting: step1(step_no=1), step2(step_no=2), step3(step_no=3)
     * Move step3 up → step3 should become step_no=2, step2 should become step_no=3.
     * Step1 is unaffected.
     */
    public function test_move_up_swaps_step_no_with_neighbor(): void
    {
        $seq = $this->makeThreeStepSequence();

        $step2 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 2)->firstOrFail();
        $step3 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 3)->firstOrFail();
        $step1 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 1)->firstOrFail();

        $response = $this->actingAs($this->editor)
            ->post(route('admin.sequences.moveStepUp', [$seq->id, $step3->id]));

        $response->assertRedirect(route('admin.sequences.view', $seq->id) . '#sequence_steps');

        // step3 must now have step_no = 2
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step3->id,
            'step_no' => 2,
        ]);

        // step2 must now have step_no = 3
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step2->id,
            'step_no' => 3,
        ]);

        // step1 is unchanged
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step1->id,
            'step_no' => 1,
        ]);

        // Unique constraint intact
        $stepNos = SequenceStep::where('sequence_id', $seq->id)->pluck('step_no')->sort()->values()->toArray();
        $this->assertSame([1, 2, 3], $stepNos);
    }

    /**
     * Test 13c — move-up on the first step is rejected: rows must be unchanged.
     *
     * The controller redirects with a warning flash but does NOT swap any rows.
     */
    public function test_move_up_on_first_step_is_rejected(): void
    {
        $seq = $this->makeThreeStepSequence();

        $step1 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 1)->firstOrFail();

        $response = $this->actingAs($this->editor)
            ->post(route('admin.sequences.moveStepUp', [$seq->id, $step1->id]));

        // Still redirects (gracefully, not 422/500)
        $response->assertRedirect(route('admin.sequences.view', $seq->id) . '#sequence_steps');

        // step1 must still be step_no = 1 (no change)
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step1->id,
            'step_no' => 1,
        ]);

        // All three steps remain at their original positions
        $stepNos = SequenceStep::where('sequence_id', $seq->id)->pluck('step_no')->sort()->values()->toArray();
        $this->assertSame([1, 2, 3], $stepNos);
    }

    /**
     * Test 13d — move-down on the last step is rejected: rows must be unchanged.
     */
    public function test_move_down_on_last_step_is_rejected(): void
    {
        $seq = $this->makeThreeStepSequence();

        $step3 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 3)->firstOrFail();

        $response = $this->actingAs($this->editor)
            ->post(route('admin.sequences.moveStepDown', [$seq->id, $step3->id]));

        $response->assertRedirect(route('admin.sequences.view', $seq->id) . '#sequence_steps');

        // step3 must still be step_no = 3
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step3->id,
            'step_no' => 3,
        ]);

        $stepNos = SequenceStep::where('sequence_id', $seq->id)->pluck('step_no')->sort()->values()->toArray();
        $this->assertSame([1, 2, 3], $stepNos);
    }

    /**
     * Test 13e — permission gate: a user without 'edit sequences' must be rejected.
     *
     * The Spatie permission middleware returns a 403 (or redirects via its handler).
     * We assert the DB rows are unchanged.
     */
    public function test_move_down_requires_edit_sequences_permission(): void
    {
        $seq = $this->makeThreeStepSequence();

        $step1 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 1)->firstOrFail();
        $step2 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 2)->firstOrFail();

        $response = $this->actingAs($this->noPermUser)
            ->post(route('admin.sequences.moveStepDown', [$seq->id, $step1->id]));

        // Must not be a success — expect 403 or a redirect (permission middleware behaviour)
        $this->assertNotEquals(200, $response->status(), 'Unauthorized user must not get 200');

        // Rows must be unchanged
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step1->id,
            'step_no' => 1,
        ]);
        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step2->id,
            'step_no' => 2,
        ]);
    }

    /**
     * Test 13f — permission gate for move-up.
     */
    public function test_move_up_requires_edit_sequences_permission(): void
    {
        $seq = $this->makeThreeStepSequence();

        $step3 = SequenceStep::where('sequence_id', $seq->id)->where('step_no', 3)->firstOrFail();

        $response = $this->actingAs($this->noPermUser)
            ->post(route('admin.sequences.moveStepUp', [$seq->id, $step3->id]));

        $this->assertNotEquals(200, $response->status());

        $this->assertDatabaseHas('sequence_steps', [
            'id'      => $step3->id,
            'step_no' => 3,
        ]);
    }
}
