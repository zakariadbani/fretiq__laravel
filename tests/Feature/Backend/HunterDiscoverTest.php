<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class HunterDiscoverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->assignRole('superadmin');
        config(['services.hunter.driver' => 'local']);
    }

    public function test_preview_validates_permission_input_and_active_state(): void
    {
        $criteria = $this->criteria();
        $withoutPermission = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $withoutPermission->givePermissionTo(['backend.access', 'view prospect_criteria']);

        $this->actingAs($withoutPermission)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])->assertForbidden();
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => ' ', 'exclude' => ' '])->assertStatus(422);
        $lock = Cache::lock('hunter-discover:'.sha1($this->user->id.':'.$criteria->id), 30);
        $lock->get();
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])->assertStatus(423);
        $lock->release();
        $criteria->update(['is_active' => false]);
        $this->actingAs($this->user)->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])->assertStatus(422);
    }

    public function test_preview_rejects_array_input_before_calling_provider(): void
    {
        $criteria = $this->criteria();
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key']);
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => ['not', 'a', 'string']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        Http::assertNothingSent();
    }

    public function test_view_prefills_exact_ai_target_and_exclude_escaped(): void
    {
        $criteria = $this->criteria(['ai_target' => 'Target <script>', 'ai_exclude' => 'Exclude & rivals']);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_criteria.view', $criteria));

        $response->assertOk();
        $response->assertSee('Target &lt;script&gt;', false);
        $response->assertSee('Exclude &amp; rivals', false);
        $response->assertSee('Vérifier la cible');
        $response->assertSee('Continuer vers la confirmation');
        $response->assertSee('ne consomme aucun crédit');
        $response->assertDontSee('Entreprises proposées');
    }

    public function test_draft_lock_prevents_duplicate_batch_creation(): void
    {
        $criteria = $this->criteria();
        $payload = ['target' => 'transport', 'exclude' => ''];
        $lock = Cache::lock('hunter-discover-draft:'.sha1(
            $this->user->id.':'.$criteria->id.':transport:',
        ), 30);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->user)
                ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), $payload)
                ->assertStatus(423);
            $this->assertDatabaseCount('prospect_batches', 0);
        } finally {
            $lock->release();
        }

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'draft');
        $this->assertDatabaseCount('prospect_batches', 1);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_preview_is_local_and_does_not_annotate_or_mutate_existing_companies(): void
    {
        $criteria = $this->criteria();
        $other = $this->criteria();
        Company::create(['criteria_id' => $criteria->id, 'domain' => 'geodis.com', 'name' => 'Keep', 'qualification_status' => 'qualified']);
        Company::create(['criteria_id' => $criteria->id, 'domain' => 'clasquin.com', 'name' => 'Rejected', 'qualification_status' => 'rejected']);
        Company::create(['criteria_id' => $other->id, 'domain' => 'bolloretransport.com', 'name' => 'Other']);
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key']);
        Http::preventStrayRequests();

        $response = $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_preview', $criteria), ['target' => 'transport'])
            ->assertOk()
            ->assertJsonMissingPath('companies')
            ->assertJsonPath('draft.source_type', 'discover');

        $this->assertStringContainsString('Cible: transport.', $response->json('prompt'));
        $this->assertDatabaseCount('companies', 3);
        $this->assertDatabaseCount('prospect_batches', 0);
        Http::assertNothingSent();
    }

    public function test_import_creates_only_a_draft_and_preserves_existing_company_profiles(): void
    {
        $criteria = $this->criteria();
        $other = $this->criteria();
        $rejected = Company::create(['criteria_id' => $criteria->id, 'domain' => 'rejected.test', 'name' => 'Preserve', 'sector' => 'Special', 'qualification_status' => 'rejected']);
        $existingOther = Company::create(['criteria_id' => $other->id, 'domain' => 'other.test', 'name' => 'Other']);

        $response = $this->actingAs($this->user)->postJson(
            route('admin.prospect_criteria.hunter_discover_import', $criteria),
            ['target' => 'Exportateurs', 'exclude' => 'Concurrents', 'quality_preset' => 'balanced'],
        );

        $response->assertCreated()->assertJsonPath('status', 'draft');
        $this->assertDatabaseHas('prospect_batches', [
            'id' => $response->json('batch_id'),
            'source_type' => 'discover',
            'status' => 'draft',
            'created_by' => $this->user->id,
            'prospect_criteria_id' => $criteria->id,
        ]);
        $this->assertDatabaseCount('companies', 2);
        $this->assertSame('Preserve', $rejected->fresh()->name);
        $this->assertSame('Special', $rejected->fresh()->sector);
        $this->assertSame('rejected', $rejected->fresh()->qualification_status);
        $this->assertSame('Other', $existingOther->fresh()->name);
    }

    public function test_import_requires_permission_active_criteria_and_scalar_targeting(): void
    {
        $criteria = $this->criteria();
        $withoutPermission = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $withoutPermission->givePermissionTo(['backend.access', 'view prospect_criteria']);

        $this->actingAs($withoutPermission)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['target' => 'Transport'])
            ->assertForbidden();
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['target' => ['invalid']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['target' => ' ', 'exclude' => ' '])
            ->assertStatus(422);

        $criteria->update(['is_active' => false]);
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), ['target' => 'Transport'])
            ->assertStatus(422);
        $this->assertDatabaseCount('prospect_batches', 0);
    }

    private function criteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge(['name' => 'Hunter '.Str::random(8), 'sectors' => ['Transport'], 'countries' => ['FR'], 'company_sizes' => ['11-50'], 'daily_limit' => 10, 'is_active' => true], $overrides));
    }

}
