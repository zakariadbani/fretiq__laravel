<?php

namespace Tests\Feature\Backend;

use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingService;
use Carbon\Carbon;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlanningSettingsTest — the "Planification" settings tab (skip_weekends +
 * blackout_dates + the read-only blackout_preview).
 *
 * Modelled on SettingsModuleTest — same auth setup, same save()/validation
 * conventions. No behaviour change to any scheduling path in this chunk; this
 * only exercises the settings UI + persistence + validation.
 */
class PlanningSettingsTest extends TestCase
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

    // ── Renders ──────────────────────────────────────────────────────────────────

    public function test_planification_tab_renders(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings');

        $response->assertOk()
            ->assertSee('Planification')
            ->assertSee('setting_planification_skip_weekends', false)
            ->assertSee('setting_planification_blackout_dates', false);
    }

    // ── Save → persist + redirect ───────────────────────────────────────────────

    public function test_save_persists_both_keys_and_redirects_to_planification_tab(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'planification',
                'settings'   => [
                    'planification' => [
                        'skip_weekends'  => '1',
                        'blackout_dates' => "2026-12-25\n12-25 # Noël\n01-01",
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('admin.settings.index').'#kt_tab_planification')
            ->assertSessionHas('success');

        $this->assertTrue((bool) Setting::get('planification.skip_weekends'));
        $this->assertSame(
            "2026-12-25\n12-25 # Noël\n01-01",
            Setting::get('planification.blackout_dates')
        );
    }

    // ── Planning-only save must not clobber decouverte ──────────────────────────

    public function test_planification_only_save_does_not_clobber_decouverte(): void
    {
        Setting::set('decouverte.min_score_enrich', 75);

        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                'active_tab' => 'planification',
                'settings'   => [
                    'planification' => [
                        'skip_weekends'  => '0',
                        'blackout_dates' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.settings.index').'#kt_tab_planification')
            ->assertSessionHas('success');

        $this->assertSame(75, Setting::get('decouverte.min_score_enrich'));
        $this->assertFalse((bool) Setting::get('planification.skip_weekends'));
    }

    // ── Malformed blackout line ⇒ 422 naming the bad line ───────────────────────

    public function test_malformed_blackout_line_fails_validation_naming_the_bad_line(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'planification',
                'settings'   => [
                    'planification' => [
                        'skip_weekends'  => '1',
                        'blackout_dates' => "2026-12-25\nnot-a-date\n13-40",
                    ],
                ],
            ])
            ->assertSessionHasErrors('settings.planification.blackout_dates');

        $errors = session('errors')->get('settings.planification.blackout_dates');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not-a-date', $errors[0]);
        $this->assertStringContainsString('13-40', $errors[0]);

        // Nothing must have been persisted from the rejected submission.
        $this->assertDatabaseMissing('settings', ['group_name' => 'planification']);
    }

    // ── Unchecked boolean → stored false (hidden-0 fallback) ────────────────────

    public function test_unchecked_skip_weekends_stores_false(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'planification',
                'settings'   => [
                    'planification' => [
                        // skip_weekends intentionally absent → unchecked
                        'blackout_dates' => '',
                    ],
                ],
            ]);

        $row = Setting::where('group_name', 'planification')
            ->where('setting_key', 'skip_weekends')
            ->first();

        $this->assertNotNull($row, 'skip_weekends row must exist after save');
        $this->assertFalse((bool) $row->value, 'skip_weekends must be false when unchecked');

        /** @var SettingService $svc */
        $svc = app(SettingService::class);
        $svc->clearCache();

        $this->assertFalse((bool) Setting::get('planification.skip_weekends'));
    }

    // ── blackout_preview is display-only ────────────────────────────────────────

    public function test_blackout_preview_is_never_written_to_the_settings_table(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'planification',
                'settings'   => [
                    'planification' => [
                        'skip_weekends'    => '1',
                        'blackout_dates'   => '',
                        'blackout_preview' => 'should never persist',
                    ],
                ],
            ]);

        $this->assertDatabaseMissing('settings', [
            'group_name'  => 'planification',
            'setting_key' => 'blackout_preview',
        ]);
    }

    // ── blackout_preview shows blackout entries, not upcoming weekends ─────────

    public function test_blackout_preview_shows_the_configured_date_and_not_upcoming_weekends(): void
    {
        // Pinned in UTC per this module's Carbon gotcha: setTestNow() with a
        // non-UTC timezone mock silently corrupts every 'datetime'-cast Eloquent
        // read during the mock by the UTC offset.
        Carbon::setTestNow(Carbon::create(2026, 1, 1, 8, 0, 0, 'UTC'));

        // 2026-01-01 is a Thursday (Europe/Paris, the default calendar tz);
        // 2026-01-03/04 is the very next weekend — with skip_weekends on, those
        // are the two dates blockedDatesFor() would surface first if the preview
        // were still wired to the "every non-working day" method. The blackout
        // entry itself (2026-01-08, also a Thursday) must be what shows instead.
        $this->actingAs($this->admin)
            ->post('/admin/settings/save', [
                '_token'     => csrf_token(),
                'active_tab' => 'planification',
                'settings'   => [
                    'planification' => [
                        'skip_weekends'  => '1',
                        'blackout_dates' => '2026-01-08',
                    ],
                ],
            ])
            ->assertSessionHas('success');

        $response = $this->actingAs($this->admin)->get('/admin/settings');

        $response->assertOk()
            ->assertSee('jeu. 8 janv. 2026')
            ->assertDontSee('sam. 3 janv. 2026')
            ->assertDontSee('dim. 4 janv. 2026');
    }
}
