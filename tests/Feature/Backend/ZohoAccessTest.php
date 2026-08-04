<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\User;
use App\Services\Zoho\CrmClient;
use App\Services\Zoho\LocalCrmClient;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZohoAccessTest — HTTP-layer permission enforcement on Zoho routes.
 *
 * ACL matrix:
 *   superadmin → has 'view zoho' + 'sync zoho'  → 200 on index, redirect on sync
 *   commercial → does NOT have those permissions → 403 on both
 *
 * The sync controller runs ZohoCrmSyncService synchronously via app().
 * config('services.zoho.crm_driver') defaults to 'local' (no ZOHO_CRM_DRIVER in phpunit.xml),
 * so the container resolves LocalCrmClient → fixtures are used; no HTTP calls are made.
 */
class ZohoAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase does NOT run seeders; seed ACL manually.
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        config(['services.zoho.crm_driver' => 'local']);

        // Bind the CrmClient interface to LocalCrmClient so that
        // app(ZohoCrmSyncService::class) resolves without HTTP calls.
        $this->app->bind(CrmClient::class, LocalCrmClient::class);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    /**
     * Superadmin can view the Zoho status page (has 'view zoho' permission).
     */
    public function test_admin_can_view_zoho_status(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/zoho');

        $response->assertStatus(200);
    }

    /**
     * Commercial role cannot view the Zoho status page (lacks 'view zoho').
     */
    public function test_commercial_cannot_view_zoho(): void
    {
        $response = $this->actingAs($this->commercial)
            ->get('/admin/zoho');

        $response->assertStatus(403);
    }

    /**
     * Commercial role cannot trigger a sync (lacks 'sync zoho').
     */
    public function test_commercial_cannot_trigger_sync(): void
    {
        $response = $this->actingAs($this->commercial)
            ->post('/admin/zoho/sync');

        $response->assertStatus(403);
    }

    /**
     * Superadmin can trigger a sync (has 'sync zoho').
     *
     * The controller redirects back to admin.zoho.index on success (HTTP 302).
     * Because crm_driver is 'local' (default), the sync runs against the fixture
     * data — afterwards at least one zoho company must exist.
     */
    public function test_admin_can_trigger_sync(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync');

        // ZohoController::sync() always redirects back after the sync.
        $response->assertStatus(302);

        // The sync ran synchronously against fixtures, so companies must exist.
        $this->assertTrue(
            Company::where('source', 'zoho')->exists(),
            'A full sync against fixtures must create at least one zoho company'
        );
    }
}
