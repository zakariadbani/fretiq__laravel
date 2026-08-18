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
            'is_active' => true,
        ]);
        $this->admin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
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

    public function test_admin_settings_page_hides_disabled_tab_nav_and_panes_by_identifier(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings');

        $response->assertOk()
            ->assertSee('nav-decouverte-tab', false)
            ->assertSee('kt_tab_decouverte', false)
            ->assertDontSee('nav-envoi_identites-tab', false)
            ->assertDontSee('kt_tab_envoi_identites', false)
            ->assertDontSee('nav-envoi_permissions-tab', false)
            ->assertDontSee('kt_tab_envoi_permissions', false)
            ->assertDontSee('nav-conformite-tab', false)
            ->assertDontSee('kt_tab_conformite', false);
    }

    public function test_zoho_settings_tab_renders_the_safe_automation_defaults(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('kt_tab_zoho', false)
            ->assertSee('settings[zoho][auto_sync_enabled]', false)
            ->assertSee('settings[zoho][sync_frequency]', false)
            ->assertSee('settings[zoho][nightly_reconciliation_enabled]', false)
            ->assertDontSee('Zoho &amp; Intégrations', false)
            ->assertSee('hourly', false);
    }

    public function test_editor_can_persist_zoho_automation_settings(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token' => csrf_token(),
                'active_tab' => 'zoho',
                'settings' => [
                    'zoho' => [
                        'auto_sync_enabled' => '1',
                        'sync_frequency' => 'every_30_minutes',
                        'nightly_reconciliation_enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect('/admin/settings#kt_tab_zoho');

        $this->assertTrue((bool) Setting::get('zoho.auto_sync_enabled', false));
        $this->assertSame('every_30_minutes', Setting::get('zoho.sync_frequency'));
        $this->assertTrue((bool) Setting::get('zoho.nightly_reconciliation_enabled', false));
    }

    public function test_unsupported_zoho_sync_frequency_is_rejected_without_changing_existing_value(): void
    {
        Setting::set('zoho.sync_frequency', 'hourly');

        $this->actingAs($this->admin)
            ->from('/admin/settings#kt_tab_zoho')
            ->post('/admin/settings/save', [
                '_token' => csrf_token(),
                'active_tab' => 'zoho',
                'settings' => ['zoho' => ['sync_frequency' => 'instant']],
            ])
            ->assertSessionHasErrors('settings.zoho.sync_frequency');

        $this->assertSame('hourly', Setting::get('zoho.sync_frequency'));
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
                '_token' => csrf_token(),
                'active_tab' => 'decouverte',
                'settings' => [
                    'decouverte' => [
                        'auto_scoring' => '1',
                        'auto_enrich' => '1',
                        'min_score_enrich' => '75',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone' => 'Europe/Paris',
                    ],
                ],
            ]);

        // Should redirect (302) to settings index
        $response->assertRedirect();

        // Value must be persisted
        $this->assertDatabaseHas('settings', [
            'group_name' => 'decouverte',
            'setting_key' => 'min_score_enrich',
        ]);

        $row = \App\Models\Setting::where('group_name', 'decouverte')
            ->where('setting_key', 'min_score_enrich')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(75, $row->value);

        // Follow redirect and check flash message
        $response->assertSessionHas('success');

        // A partial settings request must not create or disable another group.
        $this->assertDatabaseMissing('settings', ['group_name' => 'automatisation']);
    }

    // ── Validation error ─────────────────────────────────────────────────────────

    /**
     * min_score_enrich=150 is out of range → validation error, no persist.
     */
    public function test_min_score_enrich_out_of_range_fails_validation(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token' => csrf_token(),
                'active_tab' => 'decouverte',
                'settings' => [
                    'decouverte' => [
                        'auto_scoring' => '1',
                        'auto_enrich' => '0',
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
                '_token' => csrf_token(),
                'active_tab' => 'decouverte',
                'settings' => [
                    'decouverte' => [
                        // auto_scoring intentionally absent → unchecked
                        'auto_enrich' => '1',
                        'min_score_enrich' => '50',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone' => 'Europe/Paris',
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
                '_token' => csrf_token(),
                'active_tab' => 'decouverte',
                'settings' => [
                    'decouverte' => [
                        'auto_scoring' => '1',
                        'auto_enrich' => '1',
                        'min_score_enrich' => '90',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone' => 'Europe/Paris',
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
                '_token' => csrf_token(),
                'active_tab' => 'decouverte',
                'settings' => [
                    'decouverte' => [
                        'auto_scoring' => '1',
                        'auto_enrich' => '1',
                        'min_score_enrich' => '50',
                        'discovery_engines' => ['google', 'google_maps'],
                        'timezone' => 'Europe/Paris',
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

    public function test_automation_tab_renders_all_scheduler_switches(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings');

        $response->assertOk()
            ->assertSee('Automatisations')
            ->assertSee('setting_automatisation_cron_enabled', false)
            ->assertSee('setting_automatisation_campaigns_dispatch_due', false)
            ->assertSee('setting_automatisation_campaigns_generate_runs', false)
            ->assertSee('setting_automatisation_sequences_process', false)
            ->assertSee('setting_automatisation_campaigns_sync_sequence_enrollments', false)
            ->assertSee('setting_automatisation_campaign_sync_stats', false)
            ->assertSee('setting_automatisation_discovery_terminalize_stale', false)
            ->assertSee('setting_automatisation_prospect_auto_discover', false)
            ->assertSee('setting_automatisation_inbox_poll', false);
    }

    public function test_automation_only_save_persists_switches_without_touching_discovery(): void
    {
        Setting::set('decouverte.min_score_enrich', 75);

        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                'active_tab' => 'automatisation',
                'settings' => [
                    'automatisation' => [
                        'cron_enabled' => '0',
                        'campaigns_dispatch_due' => '1',
                        'campaigns_generate_runs' => '0',
                        'sequences_process' => '1',
                        'campaigns_sync_sequence_enrollments' => '0',
                        'campaign_sync_stats' => '1',
                        'discovery_terminalize_stale' => '0',
                        'prospect_auto_discover' => '1',
                        'inbox_poll' => '0',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.settings.index').'#kt_tab_automatisation')
            ->assertSessionHas('success');

        $this->assertSame(75, Setting::get('decouverte.min_score_enrich'));
        $this->assertFalse((bool) Setting::get('automatisation.cron_enabled'));
        $this->assertTrue((bool) Setting::get('automatisation.campaigns_dispatch_due'));
        $this->assertFalse((bool) Setting::get('automatisation.campaigns_generate_runs'));
        $this->assertTrue((bool) Setting::get('automatisation.sequences_process'));
        $this->assertFalse((bool) Setting::get('automatisation.campaigns_sync_sequence_enrollments'));
        $this->assertTrue((bool) Setting::get('automatisation.campaign_sync_stats'));
        $this->assertFalse((bool) Setting::get('automatisation.discovery_terminalize_stale'));
        $this->assertFalse((bool) Setting::get('automatisation.inbox_poll'));
        $this->assertTrue((bool) Setting::get('automatisation.prospect_auto_discover'));
    }
}
