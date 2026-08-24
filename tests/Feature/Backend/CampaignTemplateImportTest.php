<?php

namespace Tests\Feature\Backend;

use App\Models\CampaignTemplate;
use App\Models\User;
use App\Services\Campaign\TemplateBuilder\SectionCatalog;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Feature coverage for the bi-format (HTML + JSON/builder) import
 * preview→confirm flow on CampaignTemplateController — the seam grill
 * flagged as having zero test coverage. See
 * tests/Unit/Services/Campaign/CampaignTemplateBuilderImporterTest.php and
 * CampaignTemplateHtmlImporterTest.php for importer-level unit coverage;
 * this file only exercises what the controller does with the merged rows
 * (extension routing, cross-format collision guard, token round-trip).
 */
class CampaignTemplateImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    private function actingUser(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function builderJson(string $name, string $subject = 'Sujet builder'): string
    {
        return json_encode([
            'name' => $name,
            'subject' => $subject,
            'preview_text' => 'Un aperçu de test.',
            'builder_state' => SectionCatalog::defaultState(),
        ], JSON_THROW_ON_ERROR);
    }

    private function htmlContent(string $name, string $subject = 'Sujet HTML'): string
    {
        return "<!--\nNom : {$name}\nObjet : {$subject}\n-->\n<p>Bonjour {{contact.first_name}}.</p>";
    }

    public function test_preview_accepts_a_real_json_upload_and_shows_the_builder_badge(): void
    {
        $response = $this->actingAs($this->actingUser())->post(route('admin.campaign_templates.import_preview'), [
            'html_files' => [
                UploadedFile::fake()->createWithContent('maquette.json', $this->builderJson('Modèle maquette')),
            ],
        ]);

        $response->assertOk()
            ->assertSee('Modèle maquette')
            ->assertSee('Maquette (builder)')
            ->assertViewHas('importToken');
        $this->assertDatabaseCount('campaign_templates', 0);
    }

    public function test_extensions_rule_accepts_json_and_rejects_txt(): void
    {
        $user = $this->actingUser();

        $this->actingAs($user)->post(route('admin.campaign_templates.import_preview'), [
            'html_files' => [
                UploadedFile::fake()->createWithContent('maquette.json', $this->builderJson('Extension JSON')),
            ],
        ])->assertOk();

        $this->actingAs($user)->post(route('admin.campaign_templates.import_preview'), [
            'html_files' => [
                UploadedFile::fake()->createWithContent('notes.txt', 'plain text, not a template'),
            ],
        ])->assertSessionHasErrors('html_files.0');
    }

    public function test_mixed_html_and_json_batch_merges_into_one_preview(): void
    {
        $response = $this->actingAs($this->actingUser())->post(route('admin.campaign_templates.import_preview'), [
            'html_files' => [
                UploadedFile::fake()->createWithContent('classique.html', $this->htmlContent('Modèle HTML')),
                UploadedFile::fake()->createWithContent('maquette.json', $this->builderJson('Modèle maquette mixte')),
            ],
        ]);

        $response->assertOk();
        $rows = $response->viewData('rows');
        $this->assertCount(2, $rows);
        $this->assertSame(['html', 'builder'], array_column($rows, 'mode'));
    }

    public function test_round_trip_token_creates_a_template_with_builder_state_in_db(): void
    {
        $user = $this->actingUser();

        $preview = $this->actingAs($user)->post(route('admin.campaign_templates.import_preview'), [
            'html_files' => [
                UploadedFile::fake()->createWithContent('maquette.json', $this->builderJson('Modèle round-trip')),
            ],
        ])->assertOk();

        $this->actingAs($user)->post(route('admin.campaign_templates.import_store'), [
            'import_token' => $preview->viewData('importToken'),
        ])->assertRedirect(route('admin.campaign_templates.index'));

        $template = CampaignTemplate::where('name', 'Modèle round-trip')->firstOrFail();
        $this->assertNotNull($template->builder_state);
        $this->assertSame(SectionCatalog::defaultState()['header_variant'], $template->builder_state['header_variant']);
    }

    public function test_cross_format_name_collision_is_rejected(): void
    {
        $response = $this->actingAs($this->actingUser())->post(route('admin.campaign_templates.import_preview'), [
            'html_files' => [
                UploadedFile::fake()->createWithContent('classique.html', $this->htmlContent('Modèle en double')),
                UploadedFile::fake()->createWithContent('maquette.json', $this->builderJson('Modèle en double')),
            ],
        ]);

        $response->assertSessionHasErrors('json');
        $errors = session('errors')->get('json');
        $this->assertNotEmpty(array_filter($errors, fn ($message) => str_contains($message, 'Nom en double')));
        $this->assertDatabaseCount('campaign_templates', 0);
    }
}
