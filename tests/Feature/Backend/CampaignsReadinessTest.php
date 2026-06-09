<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Services\Campaign\CampaignsClient;
use App\Services\Campaign\LocalCampaignsDriver;
use App\Services\Zoho\CampaignsReadinessService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CampaignsReadinessTest — tests for the preflight checklist service and Zoho admin screen.
 *
 * No live HTTP calls — the service only reads config values and APP_URL.
 */
class CampaignsReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Bind CrmClient to Local so ZohoController resolves without HTTP calls.
        $this->app->bind(
            \App\Services\Zoho\CrmClient::class,
            \App\Services\Zoho\LocalCrmClient::class,
        );
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Default config (driver=local, no OAuth, localhost APP_URL, cold disabled)
     * must return ready=false with the expected individual item statuses.
     */
    public function test_default_config_returns_not_ready(): void
    {
        // Enforce default / dev conditions
        config([
            'services.zoho.driver'                   => 'local',
            'services.zoho.campaigns.refresh_token'  => null,
            'prospecting.cold_send_enabled'           => false,
            'prospecting.spf_dkim_dmarc_configured'  => false,
            'prospecting.bounce_handling_configured'  => false,
            'app.url'                                 => 'http://localhost:8000',
        ]);

        $result = app(CampaignsReadinessService::class)->check();

        // Top-level structure
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('ready', $result);
        $this->assertArrayHasKey('driver', $result);

        // Overall must not be ready
        $this->assertFalse($result['ready'], 'Readiness must be false under default dev config');

        $items = $result['items'];

        // Required keys present
        $this->assertArrayHasKey('oauth_configured', $items);
        $this->assertArrayHasKey('driver_zoho', $items);
        $this->assertArrayHasKey('public_app_url', $items);

        // Specific items must be false
        $this->assertFalse(
            $items['oauth_configured']['status'],
            'oauth_configured must be false when refresh_token is blank'
        );

        $this->assertFalse(
            $items['driver_zoho']['status'],
            'driver_zoho must be false when driver=local'
        );

        $this->assertFalse(
            $items['public_app_url']['status'],
            'public_app_url must be false when APP_URL is localhost'
        );
    }

    /**
     * The Zoho admin screen renders (HTTP 200) for a superadmin and contains
     * some readiness-related content — the readiness card is present.
     */
    public function test_zoho_admin_screen_renders_for_superadmin(): void
    {
        $superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $superadmin->assignRole('superadmin');

        $response = $this->actingAs($superadmin)->get('/admin/zoho');

        $response->assertStatus(200);

        // The readiness checklist card should be rendered somewhere on the page.
        // The view outputs the items from CampaignsReadinessService::check().
        // We assert the page contains at least some readiness-related text.
        $response->assertSee('OAuth', false);
    }
}
