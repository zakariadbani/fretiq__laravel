<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression net for a Blade ParseError that only surfaces at render time:
 * a literal merge-tag list embedded as {{ '...{{...}}...' }} in
 * import.blade.php cut Blade's non-greedy {{ }} matcher short, producing
 * invalid compiled PHP — `php artisan view:cache` never syntax-checks the
 * compiled output, so only an actual render (this test, or a browser hit)
 * catches it.
 */
class CampaignTemplateImportFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    public function test_authorized_user_gets_200_on_import_form(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole('superadmin');

        $this->actingAs($user)
            ->get(route('admin.campaign_templates.import_form'))
            ->assertOk()
            ->assertSee('Importer des modèles');
    }
}
