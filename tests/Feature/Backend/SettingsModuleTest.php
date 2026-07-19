<?php

namespace Tests\Feature\Backend;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * SettingsModuleTest — HTTP access, persistence, validation, and cache behaviour
 * for the settings module.
 *
 * Roles seeded: superadmin, commercial.
 * Permissions seeded via PermissionsSeeder.
 * Database: fretiq_test (RefreshDatabase wraps each test in a transaction / migration).
 */
class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->admin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    // ── HTTP access ───────────────────────────────────────────────────────────────

    /**
     * Authenticated superadmin can view the settings page.
     */
    public function test_admin_can_view_settings(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/settings')
            ->assertStatus(200);
    }

    /**
     * Commercial role lacks 'view settings' → 403.
     */
    public function test_commercial_is_denied_settings(): void
    {
        $this->actingAs($this->commercial)
            ->get('/admin/settings')
            ->assertStatus(403);
    }

    /**
     * Unauthenticated request is redirected (auth middleware fires first).
     */
    public function test_guest_is_redirected_from_settings(): void
    {
        $this->get('/admin/settings')
            ->assertRedirect();
    }

    // ── Save → persist + redirect + flash ────────────────────────────────────────

    /**
     * POST save persists nested settings input, redirects, and flashes success.
     */
    public function test_save_persists_and_redirects(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '75',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone'         => 'Europe/Paris',
                    ],
                ],
            ]);

        // Should redirect (302) to settings index
        $response->assertRedirect();

        // Value must be persisted
        $this->assertDatabaseHas('settings', [
            'group_name'  => 'decouverte',
            'setting_key' => 'min_score_enrich',
        ]);

        $row = \App\Models\Setting::where('group_name', 'decouverte')
            ->where('setting_key', 'min_score_enrich')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(75, $row->value);

        // Follow redirect and check flash message
        $response->assertSessionHas('success');
    }

    // ── Validation error ─────────────────────────────────────────────────────────

    /**
     * min_score_enrich=150 is out of range → validation error, no persist.
     */
    public function test_min_score_enrich_out_of_range_fails_validation(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '0',
                        'min_score_enrich' => '150',
                        'discovery_engines' => ['google', 'google_maps'],
                    ],
                ],
            ])
            ->assertSessionHasErrors('settings.decouverte.min_score_enrich');
    }

    // ── Boolean: unchecked → stored false ────────────────────────────────────────

    /**
     * When a boolean checkbox is unchecked it is absent from POST.
     * The controller must store false (not null / absent).
     */
    public function test_unchecked_boolean_is_stored_as_false(): void
    {
        // Post WITHOUT auto_scoring key (simulates unchecked checkbox)
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        // auto_scoring intentionally absent → unchecked
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '50',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone'         => 'Europe/Paris',
                    ],
                ],
            ]);

        // Verify DB stores false (JSON null or 0 cast)
        $row = \App\Models\Setting::where('group_name', 'decouverte')
            ->where('setting_key', 'auto_scoring')
            ->first();

        $this->assertNotNull($row, 'auto_scoring row must exist after save');
        $this->assertFalse((bool) $row->value, 'auto_scoring must be false when unchecked');

        // And Setting::get must return false
        /** @var SettingService $svc */
        $svc = app(SettingService::class);
        $svc->clearCache();

        $this->assertFalse((bool) Setting::get('decouverte.auto_scoring'));
    }

    // ── Cache invalidation ────────────────────────────────────────────────────────

    /**
     * Setting::get reflects the new value immediately after a save (cache cleared).
     */
    public function test_cache_is_invalidated_after_save(): void
    {
        // Warm the cache with an initial value
        Setting::updateOrCreate(
            ['group_name' => 'decouverte', 'setting_key' => 'min_score_enrich'],
            ['value' => 30]
        );
        Cache::forget('app_settings');

        /** @var SettingService $svc */
        $svc = app(SettingService::class);
        $before = $svc->get('decouverte.min_score_enrich');
        $this->assertSame(30, (int) $before, 'Initial value should be 30');

        // Save new value via HTTP
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '90',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone'         => 'Europe/Paris',
                    ],
                ],
            ]);

        // Re-resolve service (memo reset by clearCache inside controller)
        $svc2 = app(SettingService::class);
        $svc2->clearCache();  // ensure memo cleared for this test process
        $after = $svc2->get('decouverte.min_score_enrich');

        $this->assertSame(90, (int) $after, 'Cache must reflect new value after save');
    }

    // ── Unknown keys ignored ──────────────────────────────────────────────────────

    /**
     * Unknown keys in POST (not defined in $tabs) must be silently ignored —
     * no exception, no DB row for the unknown key.
     */
    public function test_unknown_post_keys_are_ignored(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '50',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone'         => 'Europe/Paris',
                        'unknown_evil_key' => 'should_be_ignored',
                    ],
                    'hacker_group' => [
                        'inject' => 'payload',
                    ],
                ],
            ])
            ->assertRedirect();  // 302 = no crash

        // The unknown key must NOT appear in the DB
        $this->assertDatabaseMissing('settings', [
            'setting_key' => 'unknown_evil_key',
        ]);
        $this->assertDatabaseMissing('settings', [
            'group_name' => 'hacker_group',
        ]);
    }
}
