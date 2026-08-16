<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Prospecting\ProspectBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProspectBatchControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'backend.access',
            'view prospect_batches',
            'create prospect_batches',
            'edit prospect_batches',
            'delete prospect_batches',
            'run prospect resolution',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->user->givePermissionTo([
            'backend.access',
            'view prospect_batches',
            'create prospect_batches',
            'edit prospect_batches',
            'delete prospect_batches',
        ]);
        config([
            'services.hunter.driver' => 'local',
            'services.serpapi.driver' => 'local',
            'cache.default' => 'array',
        ]);
    }

    public function test_wizard_renders_four_steps_and_balanced_default(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.create'))
            ->assertOk()
            ->assertSee('Ajouter les entreprises')
            ->assertSee('Choisir la qualité')
            ->assertSee('Vérifier et lancer')
            ->assertSee('Traitement')
            ->assertSee('value="balanced"', false)
            ->assertSee('checked', false);
    }

    public function test_create_renders_the_company_inputs_in_the_current_step(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.create'))
            ->assertOk()
            ->assertSee(
                'class="current flex-column" data-kt-stepper-element="content" data-prospect-step="1"',
                false,
            )
            ->assertSee('id="companies_text"', false)
            ->assertSee('id="companies_csv"', false);
    }

    public function test_store_accepts_copy_paste_or_csv_but_not_both(): void
    {
        $this->actingAs($this->user)
            ->post(route('admin.prospect_batches.store'), [
                'name' => 'Liste copiée',
                'companies_text' => "Entreprise|Pays|Ville|Site\nACME|FR|Paris|acme.fr",
                'quality_preset' => 'balanced',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('prospect_batches', ['name' => 'Liste copiée', 'total_items' => 1]);

        $this->actingAs($this->user)
            ->post(route('admin.prospect_batches.store'), [
                'name' => 'Liste CSV',
                'companies_csv' => UploadedFile::fake()->createWithContent(
                    'companies.csv',
                    "company,country,city,website\nBeta,FR,Lyon,beta.fr",
                ),
                'quality_preset' => 'balanced',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('prospect_batches', ['name' => 'Liste CSV', 'total_items' => 1]);

        $this->actingAs($this->user)
            ->from(route('admin.prospect_batches.create'))
            ->post(route('admin.prospect_batches.store'), [
                'companies_text' => 'Gamma',
                'companies_csv' => UploadedFile::fake()->createWithContent('companies.csv', "company\nDelta"),
                'quality_preset' => 'balanced',
            ])
            ->assertRedirect(route('admin.prospect_batches.create'))
            ->assertSessionHasErrors('companies_text');
    }

    public function test_confirm_requires_fresh_estimate_explicit_checkbox_and_run_permission(): void
    {
        Queue::fake();
        $batch = app(ProspectBatchService::class)->createListBatch($this->user, [[
            'row_number' => 1,
            'original_input' => 'ACME',
            'company_name' => 'ACME',
            'country' => 'FR',
        ]]);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.estimate', $batch), ['quality_preset' => 'balanced'])
            ->assertForbidden();

        $this->user->givePermissionTo('run prospect resolution');
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.estimate', $batch), ['quality_preset' => 'balanced'])
            ->assertOk()
            ->assertJsonStructure(['estimate' => ['items', 'calls', 'reserved_units']]);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.confirm', $batch))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_cost');

        $batch->forceFill(['quality_preset' => 'lean'])->save();
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.confirm', $batch), ['confirm_cost' => true])
            ->assertUnprocessable();

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.estimate', $batch), ['quality_preset' => 'lean'])
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.confirm', $batch), ['confirm_cost' => true])
            ->assertOk()
            ->assertJsonPath('status', 'queued');
    }

    public function test_status_exposes_safe_progress_and_worker_waiting_state(): void
    {
        $batch = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'queued',
            'total_items' => 4,
            'processed_items' => 1,
            'source_options' => ['prompt' => 'secret target'],
        ]);
        ProspectBatch::query()->whereKey($batch->id)->update(['updated_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($this->user)
            ->getJson(route('admin.prospect_batches.status', $batch))
            ->assertOk()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('progress.percent', 25)
            ->assertJsonPath('worker_waiting', true);

        $response->assertJsonMissing(['source_options' => ['prompt' => 'secret target']]);
        $this->assertArrayNotHasKey('source_options', $response->json());
    }

    public function test_batch_datatable_does_not_expose_internal_estimate_or_source_options(): void
    {
        ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'name' => 'Lot visible',
            'source_options' => ['prompt' => 'secret-target-prompt'],
            'estimate' => ['internal' => 'secret-cost-breakdown'],
            'recovery_audit' => ['private' => 'secret-recovery-evidence'],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('admin.prospect_batches.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringContainsString('Lot visible', $body);
        $this->assertStringNotContainsString('secret-target-prompt', $body);
        $this->assertStringNotContainsString('secret-cost-breakdown', $body);
        $this->assertStringNotContainsString('secret-recovery-evidence', $body);
    }

    public function test_batch_crud_boots_through_backend_resource_contract(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.index'))
            ->assertOk()
            ->assertSee('Lots de prospection');
    }

    public function test_mobile_markup_uses_cards_and_sticky_primary_action_without_table_dependency(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.create'))
            ->assertOk()
            ->assertSee('d-md-none', false)
            ->assertSee('sticky-bottom', false)
            ->assertSee('data-mobile-company-preview', false);
    }

    public function test_delete_returns_json_for_a_draft_batch_and_blocks_a_running_one_with_a_french_reason(): void
    {
        $draft = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'status' => 'draft']);

        $this->actingAs($this->user)
            ->deleteJson(route('admin.prospect_batches.delete', $draft))
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Lot supprimé.']);
        $this->assertDatabaseMissing('prospect_batches', ['id' => $draft->id]);

        $running = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'status' => 'running']);

        $this->actingAs($this->user)
            ->deleteJson(route('admin.prospect_batches.delete', $running))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Seuls les lots en brouillon ou annulés peuvent être supprimés.');
        $this->assertDatabaseHas('prospect_batches', ['id' => $running->id]);
    }

    public function test_view_shows_enrichment_outcome_breakdown_scoped_to_this_batch_only(): void
    {
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'status' => 'completed']);
        $otherBatch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'status' => 'completed']);

        $enriched = Company::factory()->create(['enrichment_status' => 'enriched']);
        $lowScoreA = Company::factory()->create(['enrichment_status' => 'skipped_low_score']);
        $lowScoreB = Company::factory()->create(['enrichment_status' => 'skipped_low_score']);
        $otherBatchCompany = Company::factory()->create(['enrichment_status' => 'hunter_empty']);
        $unlinkedItemCompany = null; // item with no company_id must not appear at all

        ProspectBatchItem::factory()->create(['prospect_batch_id' => $batch->id, 'status' => 'promoted', 'company_id' => $enriched->id]);
        ProspectBatchItem::factory()->create(['prospect_batch_id' => $batch->id, 'status' => 'promoted', 'company_id' => $lowScoreA->id]);
        ProspectBatchItem::factory()->create(['prospect_batch_id' => $batch->id, 'status' => 'promoted', 'company_id' => $lowScoreB->id]);
        ProspectBatchItem::factory()->create(['prospect_batch_id' => $batch->id, 'status' => 'pending', 'company_id' => $unlinkedItemCompany]);
        // Belongs to a different batch — scoping by batch (not by criteria) must exclude it.
        ProspectBatchItem::factory()->create(['prospect_batch_id' => $otherBatch->id, 'status' => 'promoted', 'company_id' => $otherBatchCompany->id]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $batch))
            ->assertOk()
            ->assertSee('Résultat de l’enrichissement')
            ->assertSee('1 Enrichi', false)
            ->assertSee('2 Sous le seuil de contacts', false);

        $response->assertDontSee('Aucun email trouvé');

        // The other batch's own view must show only its own company, not the first batch's.
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $otherBatch))
            ->assertOk()
            ->assertSee('1 Aucun email trouvé', false)
            ->assertDontSee('Sous le seuil de contacts');
    }

    public function test_interrupted_discover_batch_banner_shows_remaining_count_and_gates_resume_button_on_permission(): void
    {
        $criteria = ProspectCriteria::query()->create([
            'name' => 'Critère Discover',
            'ai_target' => 'Exportateurs',
            'ai_exclude' => null,
            'sectors' => ['Transport'],
            'countries' => ['FR'],
            'company_sizes' => ['11-50'],
            'daily_limit' => 10,
            'is_active' => true,
        ]);
        $batch = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'source_type' => 'discover',
            'prospect_criteria_id' => $criteria->id,
            'status' => 'review',
            'error' => 'finalization_delayed',
            'cost_confirmed_at' => now(),
            'source_options' => ['prompt_hash' => str_repeat('a', 64)],
            'source_cursor' => [
                'offset' => 100,
                'results' => 197,
                'limit' => 100,
                'exhausted' => false,
                'initialized' => true,
                'filters_hash' => hash('sha256', '[]'),
                'prompt_hash' => str_repeat('a', 64),
            ],
        ]);

        // Without the permission: the interruption is still disclosed, but no button to act on it.
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $batch))
            ->assertOk()
            ->assertSee('interrompue')
            ->assertSee('97 entreprise')
            ->assertDontSee('<button type="button" class="btn btn-sm btn-warning" data-discover-resume-button', false);

        // With the permission: the same banner now offers the resume action.
        $this->user->givePermissionTo('run prospect resolution');
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $batch))
            ->assertOk()
            ->assertSee('<button type="button" class="btn btn-sm btn-warning" data-discover-resume-button', false)
            ->assertSee(route('admin.prospect_batches.resume_discovery', $batch), false);
    }

    public function test_view_does_not_show_interrupted_banner_for_a_batch_still_actively_running(): void
    {
        $batch = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'source_type' => 'discover',
            'status' => 'running',
            'cost_confirmed_at' => now(),
            'source_cursor' => ['offset' => 0, 'results' => null, 'limit' => 100, 'exhausted' => false, 'initialized' => false],
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', $batch))
            ->assertOk()
            ->assertDontSee('interrompue')
            ->assertDontSee('<button type="button" class="btn btn-sm btn-warning" data-discover-resume-button', false);
    }
}
