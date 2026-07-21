<?php

namespace Tests\Feature\Backend;

use App\Models\Segment;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SegmentManualModeFormTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    public function test_create_form_shows_manual_control_and_save_first_guidance_to_edit_capable_user(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/admin/segments/create');

        $response->assertOk();
        $response->assertSee('name="is_manual"', false);
        $response->assertSee('data-segment-manual-create-guidance', false);
        $response->assertSee('Enregistrez le segment pour sélectionner ses contacts.');
    }

    /** @dataProvider manualSubmitActions */
    public function test_new_manual_segment_redirects_to_contacts_pane(string $action): void
    {
        $response = $this->actingAs($this->superadmin)->post('/admin/segments', [
            'name' => "Manuel {$action}",
            'scope' => 'client',
            'is_manual' => '1',
            $action => '1',
        ]);

        $segment = Segment::where('name', "Manuel {$action}")->firstOrFail();
        $response->assertOk()->assertJsonPath(
            'redirect',
            route('admin.segments.edit', $segment) . '#segment_contacts'
        );
    }

    public static function manualSubmitActions(): array
    {
        return [['save'], ['saveandcontinue']];
    }

    /** @dataProvider dynamicSubmitActions */
    public function test_dynamic_create_redirects_remain_unchanged(string $action): void
    {
        $response = $this->actingAs($this->superadmin)->post('/admin/segments', [
            'name' => "Dynamique {$action}", 'scope' => 'client', 'is_manual' => '0', $action => '1',
        ]);
        $segment = Segment::where('name', "Dynamique {$action}")->firstOrFail();
        $expected = $action === 'save'
            ? route('admin.segments.index')
            : route('admin.segments.edit', $segment);

        $response->assertOk()->assertJsonPath('redirect', $expected);
    }

    public static function dynamicSubmitActions(): array
    {
        return [['save'], ['saveandcontinue']];
    }

    public function test_create_only_user_cannot_see_or_forge_manual_mode(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(Permission::findByName('backend.access'));
        $user->givePermissionTo(Permission::findByName('create segments'));

        $this->actingAs($user)->get('/admin/segments/create')
            ->assertOk()
            ->assertDontSee('data-segment-mode="manual"', false);

        $this->actingAs($user)->post('/admin/segments', [
            'name' => 'Forged manual', 'scope' => 'client', 'is_manual' => '1', 'save' => '1',
        ])->assertForbidden();

        $this->assertDatabaseMissing('segments', ['name' => 'Forged manual']);
    }
}
