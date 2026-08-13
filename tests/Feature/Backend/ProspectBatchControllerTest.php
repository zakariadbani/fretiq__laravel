<?php

namespace Tests\Feature\Backend;

use App\Models\ProspectBatch;
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
}
