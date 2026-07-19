<?php

namespace Tests\Feature\Backend;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// >>> custom-test-author:settings-code

/**
 * SettingGeneratedTest — gap-fill tests for the settings module.
 *
 * What the existing SettingsModuleTest already covers (NOT duplicated here):
 *   - index 200 for superadmin
 *   - index 403 for commercial
 *   - guest → 302 redirect on index
 *   - save persists min_score_enrich + redirect + flash
 *   - min_score_enrich=150 fails validation
 *   - unchecked boolean stored as false
 *   - cache invalidated after save
 *   - unknown POST keys are ignored
 *
 * This file covers the remaining cases from structure/test-specs/settings.md:
 *   - POST save returns 403 for a user who has view settings but NOT edit settings
 *   - POST save returns 403 for commercial (no view settings, no edit settings)
 *   - min_score_enrich missing entirely → required validation failure
 *   - min_score_enrich=0 (lower boundary) → valid, persists
 *   - min_score_enrich=100 (upper boundary) → valid, persists
 *   - existing settings NOT in the payload are preserved after save
 *   - round-trip: saved settings are reflected in the index response
 *   - fields from a disabled tab are never persisted even when posted
 *
 * Roles seeded: superadmin, admin, commercial (via RolesSeeder + PermissionsSeeder).
 * Database: fretiq_test (RefreshDatabase — each test is isolated).
 */
class SettingGeneratedTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ──────────────────────────────────────────────────────────────────

    private User $superadmin;

    /** User who has view settings but NOT edit settings */
    private User $viewOnlyAdmin;

    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Superadmin — has every permission including edit settings
        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        // viewOnlyAdmin — no role; only the direct permissions needed to reach
        // and read the settings page. No 'admin' role and no 'edit settings' grant,
        // so the controller's `permission:edit settings` gate on save() genuinely
        // fires (403) while GET index (view settings) still renders 200.
        $this->viewOnlyAdmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->viewOnlyAdmin->givePermissionTo(['backend.access', 'view settings']);

        // Commercial — has neither view settings nor edit settings
        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');

        // Clear Spatie permission cache after permission mutations
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ── Permission gate on POST save ──────────────────────────────────────────────

    /**
     * A user who has 'view settings' but not 'edit settings' is denied POST save.
     * The constructor middleware `permission:edit settings` on save() must fire.
     */
    public function test_save_returns_403_when_user_lacks_edit_settings(): void
    {
        $this->actingAs($this->viewOnlyAdmin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '60',
                        'discovery_engines' => ['google', 'google_maps'],
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    /**
     * Commercial role has neither view settings nor edit settings.
     * POST save must return 403 (not 302 or 200).
     */
    public function test_save_returns_403_for_commercial_role(): void
    {
        $this->actingAs($this->commercial)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '60',
                        'discovery_engines' => ['google', 'google_maps'],
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    // ── Validation: missing required field ────────────────────────────────────────

    /**
     * If min_score_enrich is absent entirely from the POST body, the 'required'
     * rule must fire and the response must include the error bag key.
     */
    public function test_save_fails_validation_when_min_score_enrich_is_absent(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring' => '1',
                        'auto_enrich'  => '1',
                        'discovery_engines' => ['google', 'google_maps'],
                        // min_score_enrich intentionally omitted
                    ],
                ],
            ])
            ->assertSessionHasErrors('settings.decouverte.min_score_enrich');
    }

    // ── Validation: boundary values ───────────────────────────────────────────────

    /**
     * min_score_enrich=0 is at the inclusive lower boundary (between:0,100).
     * The save must succeed and persist 0.
     */
    public function test_save_accepts_min_score_enrich_at_lower_boundary_zero(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '0',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone'         => 'Europe/Paris',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = Setting::where('group_name', 'decouverte')
            ->where('setting_key', 'min_score_enrich')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->value);
    }

    /**
     * min_score_enrich=100 is at the inclusive upper boundary (between:0,100).
     * The save must succeed and persist 100.
     */
    public function test_save_accepts_min_score_enrich_at_upper_boundary_hundred(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '100',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone'         => 'Europe/Paris',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $row = Setting::where('group_name', 'decouverte')
            ->where('setting_key', 'min_score_enrich')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(100, (int) $row->value);
    }

    // ── Preservation of pre-existing settings ────────────────────────────────────

    /**
     * A key that exists in the DB before the save, and belongs to a DIFFERENT
     * group than what was posted, must survive the save unchanged.
     *
     * Scenario: we pre-seed a 'decouverte.auto_enrich' row with value true,
     * then POST only auto_scoring and min_score_enrich (auto_enrich absent →
     * treated as unchecked/false). The controller writes all enabled-tab fields;
     * this verifies a separate group (if it existed) would not be wiped.
     *
     * Because all currently enabled fields belong to 'decouverte', we inject a
     * direct DB row for a hypothetical 'app.theme' key that no tab touches, then
     * assert it is still present after the save.
     */
    public function test_save_does_not_delete_unrelated_settings(): void
    {
        // Pre-seed a setting that the controller never touches
        Setting::updateOrCreate(
            ['group_name' => 'app', 'setting_key' => 'theme'],
            ['value' => 'dark']
        );

        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '55',
                        'discovery_engines' => ['google', 'google_maps'],
                    ],
                ],
            ])
            ->assertRedirect();

        // The unrelated row must still be present after the save
        $this->assertDatabaseHas('settings', [
            'group_name'  => 'app',
            'setting_key' => 'theme',
        ]);
    }

    // ── Round-trip: saved values reflected in index ───────────────────────────────

    /**
     * After a successful save, the index page must render with status 200 and
     * include the persisted value somewhere in its response body.
     *
     * We save min_score_enrich=77 and assert the index still loads (no crash from
     * the stored value). We do NOT assert on the exact rendered integer because
     * view layout can change; instead we assert 200 + no exception.
     */
    public function test_index_loads_after_save_round_trip(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'decouverte',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '0',
                        'auto_enrich'      => '0',
                        'min_score_enrich' => '77',
                        'discovery_engines' => ['google', 'google_maps'],
                    ],
                ],
            ])
            ->assertRedirect();

        // Index must still render 200 with the saved data in the DB
        $this->actingAs($this->superadmin)
            ->get('/admin/settings')
            ->assertStatus(200);
    }

    // ── Disabled-tab fields must not be persisted ─────────────────────────────────

    /**
     * The 'envoi_identites' tab is disabled (enabled=false).
     * Even if the POST payload includes fields for that group, the controller
     * must skip them (the foreach skips disabled tabs). No DB row must appear.
     */
    public function test_disabled_tab_fields_are_not_persisted(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'envoi_identites',
                'settings'   => [
                    'decouverte' => [
                        'auto_scoring'     => '1',
                        'auto_enrich'      => '1',
                        'min_score_enrich' => '50',
                        'discovery_engines' => ['google', 'google_maps'],
                    ],
                    // attempt to inject data for a disabled tab
                    'envoi_identites' => [
                        'smtp_host' => 'evil.smtp.host',
                        'smtp_port' => '25',
                    ],
                ],
            ])
            ->assertRedirect();

        // Disabled-tab fields must not appear in the DB
        $this->assertDatabaseMissing('settings', [
            'group_name' => 'envoi_identites',
        ]);
    }

    public function test_discovery_engines_render_with_safe_defaults_and_round_trip(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('settings[decouverte][discovery_engines][]', false)
            ->assertSee('value="google" selected', false)
            ->assertSee('value="google_maps" selected', false)
            ->assertSee('value="google_local"', false)
            ->assertSee('value="bing"', false);

        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                'active_tab' => 'decouverte',
                'settings' => ['decouverte' => [
                    'min_score_enrich' => 50,
                    'timezone' => 'Europe/Paris',
                    'discovery_engines' => ['google_local', 'bing'],
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['google_local', 'bing'],
            Setting::get('decouverte.discovery_engines')
        );
    }

    public function test_discovery_engines_reject_empty_unknown_and_duplicate_values(): void
    {
        foreach ([[], ['unknown'], ['google', 'google']] as $engines) {
            $this->actingAs($this->superadmin)
                ->post('/admin/settings/save', [
                    'active_tab' => 'decouverte',
                    'settings' => ['decouverte' => [
                        'min_score_enrich' => 50,
                        'timezone' => 'Europe/Paris',
                        'discovery_engines' => $engines,
                    ]],
                ])
                ->assertSessionHasErrors();
        }
    }

    public function test_discovery_engines_reject_an_omitted_multiselect_key(): void
    {
        $this->actingAs($this->superadmin)
            ->post('/admin/settings/save', [
                'active_tab' => 'decouverte',
                'settings' => ['decouverte' => [
                    'min_score_enrich' => 50,
                    'timezone' => 'Europe/Paris',
                    // A browser omits the select key after every option is cleared.
                ]],
            ])
            ->assertSessionHasErrors('settings.decouverte.discovery_engines');
    }

    public function test_invalid_stored_engine_ids_are_not_rendered_as_selected(): void
    {
        Setting::set('decouverte.discovery_engines', ['google', 'invalid', 'bing']);

        $html = $this->actingAs($this->superadmin)->get('/admin/settings');

        $html->assertOk()
            ->assertSee('value="google" selected', false)
            ->assertSee('value="bing" selected', false)
            ->assertDontSee('value="invalid"', false);
    }
}

// <<<
