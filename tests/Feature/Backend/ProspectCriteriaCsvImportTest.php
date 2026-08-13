<?php

namespace Tests\Feature\Backend;

use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProspectCriteriaCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $allowed;

    private User $denied;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        Http::preventStrayRequests();
        Queue::fake();

        $this->allowed = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->allowed->givePermissionTo('backend.access', 'view prospect_criteria', 'create prospect_criteria');
        $this->denied = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->denied->givePermissionTo('backend.access', 'view prospect_criteria');
    }

    public function test_import_page_and_index_button_require_create_permission(): void
    {
        $this->actingAs($this->allowed)
            ->get(route('admin.prospect_criteria.index'))
            ->assertOk()
            ->assertSee('Importer CSV');

        $this->actingAs($this->denied)
            ->get(route('admin.prospect_criteria.import_form'))
            ->assertForbidden();
    }

    public function test_preview_normalizes_rows_without_persisting_criteria(): void
    {
        $response = $this->actingAs($this->allowed)->post(route('admin.prospect_criteria.import_preview'), [
            'csv' => UploadedFile::fake()->createWithContent('criteres.csv', $this->csv('Nouveau critère;Transport cible;Exclure concurrence;Logistique|Logistique;MA|FR;11-50;Directeur;12;false;off;;8;65;false')),
        ]);

        $response->assertOk()
            ->assertSee('Nouveau critère')
            ->assertSee('Transport cible')
            ->assertSee('Exclure concurrence')
            ->assertSee('Logistique')
            ->assertSee('MA | FR')
            ->assertSee('11-50')
            ->assertSee('Directeur')
            ->assertSee('12')
            ->assertSee('8')
            ->assertSee('65')
            ->assertSee('Inactif')
            ->assertSee('Automatisations désactivées')
            ->assertViewHas('importToken');
        $this->assertDatabaseCount('prospect_criteria', 0);
        Queue::assertNothingPushed();
    }

    public function test_confirmation_persists_only_safe_inactive_values(): void
    {
        $preview = $this->actingAs($this->allowed)->post(route('admin.prospect_criteria.import_preview'), [
            'csv' => UploadedFile::fake()->createWithContent('criteres.csv', $this->csv('Critère importé;Transport;Concurrents;Logistique;MA;11-50;Direction;12;false;false;;8;65;false')),
        ])->assertOk();

        $this->actingAs($this->allowed)
            ->post(route('admin.prospect_criteria.import_store'), ['import_token' => $preview->viewData('importToken')])
            ->assertRedirect(route('admin.prospect_criteria.index'));

        $this->assertDatabaseHas('prospect_criteria', [
            'name' => 'Critère importé',
            'daily_limit' => 12,
            'contact_limit' => 8,
            'min_score_enrich' => 65,
            'is_active' => false,
            'auto_run' => false,
            'auto_enrich' => false,
            'ai_queries' => null,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_confirmation_rechecks_existing_names_atomically(): void
    {
        $preview = $this->actingAs($this->allowed)->post(route('admin.prospect_criteria.import_preview'), [
            'csv' => UploadedFile::fake()->createWithContent('criteres.csv', $this->csv("Fresh;Cible;;;;;;;false;false;;; ;false\nCollision;Cible;;;;;;;false;false;;; ;false")),
        ])->assertOk();
        ProspectCriteria::create(['name' => 'collision', 'is_active' => false]);

        $this->actingAs($this->allowed)
            ->from(route('admin.prospect_criteria.import_form'))
            ->post(route('admin.prospect_criteria.import_store'), ['import_token' => $preview->viewData('importToken')])
            ->assertRedirect(route('admin.prospect_criteria.import_form'))
            ->assertSessionHasErrors('csv');

        $this->assertDatabaseCount('prospect_criteria', 1);
        $this->assertDatabaseMissing('prospect_criteria', ['name' => 'Fresh']);
    }

    public function test_tampered_or_expired_tokens_fail_without_writes(): void
    {
        $this->actingAs($this->allowed)
            ->from(route('admin.prospect_criteria.import_form'))
            ->post(route('admin.prospect_criteria.import_store'), ['import_token' => 'not-a-valid-token'])
            ->assertRedirect(route('admin.prospect_criteria.import_form'))
            ->assertSessionHasErrors('csv');

        $expired = Crypt::encryptString(json_encode([
            'schema' => 1,
            'user_id' => $this->allowed->id,
            'issued_at' => now()->subMinutes(31)->timestamp,
            'rows' => [],
        ], JSON_THROW_ON_ERROR));
        $this->actingAs($this->allowed)
            ->from(route('admin.prospect_criteria.import_form'))
            ->post(route('admin.prospect_criteria.import_store'), ['import_token' => $expired])
            ->assertRedirect(route('admin.prospect_criteria.import_form'))
            ->assertSessionHasErrors('csv');

        $this->assertDatabaseCount('prospect_criteria', 0);
    }

    public function test_token_cannot_be_confirmed_by_another_user(): void
    {
        $preview = $this->actingAs($this->allowed)->post(route('admin.prospect_criteria.import_preview'), [
            'csv' => UploadedFile::fake()->createWithContent('criteres.csv', $this->csv('Privé;Cible;;;;;;;false;false;;; ;false')),
        ])->assertOk();

        $otherAuthorizedUser = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $otherAuthorizedUser->givePermissionTo('backend.access', 'view prospect_criteria', 'create prospect_criteria');

        $this->actingAs($otherAuthorizedUser)
            ->from(route('admin.prospect_criteria.import_form'))
            ->post(route('admin.prospect_criteria.import_store'), ['import_token' => $preview->viewData('importToken')])
            ->assertRedirect(route('admin.prospect_criteria.import_form'))
            ->assertSessionHasErrors('csv');

        $this->assertDatabaseCount('prospect_criteria', 0);
    }

    public function test_preview_accepts_a_generic_octet_stream_csv(): void
    {
        $file = UploadedFile::fake()
            ->createWithContent('criteres.csv', $this->csv('Générique;Cible;;;;;;;false;false;;; ;false'))
            ->mimeType('application/octet-stream');

        $this->actingAs($this->allowed)
            ->post(route('admin.prospect_criteria.import_preview'), ['csv' => $file])
            ->assertOk()
            ->assertSee('Générique');
    }

    public function test_template_is_safe_header_only_csv(): void
    {
        $this->actingAs($this->allowed)
            ->get(route('admin.prospect_criteria.import_template'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('name;ai_target;ai_exclude', false);
    }

    public function test_manual_store_rejects_a_case_insensitive_duplicate_name(): void
    {
        ProspectCriteria::create(['name' => 'Critère Unique', 'is_active' => false]);

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.store'), ['name' => 'critère unique'])
            ->assertStatus(406)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('prospect_criteria', 1);
    }

    public function test_update_allows_its_current_name_case_insensitively(): void
    {
        $this->allowed->givePermissionTo('edit prospect_criteria');
        $current = ProspectCriteria::create(['name' => 'Critère actuel', 'is_active' => false]);

        $this->actingAs($this->allowed)
            ->putJson(route('admin.prospect_criteria.update', $current), ['name' => 'CRITÈRE ACTUEL'])
            ->assertOk();
    }

    public function test_update_rejects_another_rows_case_insensitive_name(): void
    {
        $this->allowed->givePermissionTo('edit prospect_criteria');
        $current = ProspectCriteria::create(['name' => 'Critère actuel', 'is_active' => false]);
        ProspectCriteria::create(['name' => 'Critère réservé', 'is_active' => false]);

        $this->actingAs($this->allowed)
            ->putJson(route('admin.prospect_criteria.update', $current), ['name' => 'critère réservé'])
            ->assertStatus(406)
            ->assertJsonValidationErrors('name');
    }

    private function csv(string $row): string
    {
        return "name;ai_target;ai_exclude;sectors;countries;company_sizes;target_positions;daily_limit;is_active;auto_run;run_at_hour;contact_limit;min_score_enrich;auto_enrich\n{$row}\n";
    }
}
