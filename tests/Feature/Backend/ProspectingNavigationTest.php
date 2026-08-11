<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectingNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_dashboard_shows_simple_prospecting_navigation_without_provider_activity(): void
    {
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $commercial = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $commercial->assignRole('commercial');

        $this->actingAs($commercial)
            ->get(route('admin.prospecting.index'))
            ->assertOk()
            ->assertSee('Centre de prospection')
            ->assertSee('Importer des entreprises')
            ->assertDontSee('Activité fournisseurs');
    }
}
