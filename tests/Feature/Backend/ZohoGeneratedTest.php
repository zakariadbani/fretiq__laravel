<?php

namespace Tests\Feature\Backend;

// >>> custom-test-author:zoho-code

use App\Models\User;
use App\Services\Zoho\ZohoCrmSyncService;
use App\Services\Zoho\ZohoCrmTemplatesService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ZohoGeneratedTest — HTTP-layer tests for ZohoController covering the gaps
 * not reached by ZohoAccessTest.
 *
 * Permission gates:
 *   index          → 'view zoho'
 *   sync           → 'sync zoho'
 *   syncTemplates  → 'create campaign_templates'
 *
 * Response shapes:
 *   index          → 200 view
 *   sync           → 302 redirect to admin.zoho.index + flash
 *   syncTemplates  → 302 redirect to admin.zoho.index + flash
 *
 * Services mocked via $this->app->bind():
 *   ZohoCrmSyncService     → anonymous class returning void (sync path)
 *   ZohoCrmTemplatesService → anonymous class returning canned import result
 *
 * Http::fake() is registered as a safety net to block any stray outbound calls.
 */
class ZohoGeneratedTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Safety net: no outbound HTTP is ever allowed in this suite.
        Http::preventStrayRequests();

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

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Create a user with only backend.access — no zoho-specific permissions.
     */
    private function makeBackendOnlyUser(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $user->givePermissionTo('backend.access');

        return $user;
    }

    /**
     * Bind ZohoCrmSyncService to a no-op stub.
     *
     * @param  callable|null  $onSync  Optional side-effect callback — receives (?string $module).
     */
    private function bindSyncServiceStub(?callable $onSync = null): void
    {
        $this->app->bind(ZohoCrmSyncService::class, function () use ($onSync) {
            return new class ($onSync) {
                public function __construct(private readonly mixed $onSync) {}

                public function sync(?string $module = null): void
                {
                    if ($this->onSync !== null) {
                        ($this->onSync)($module);
                    }
                }
            };
        });
    }

    /**
     * Bind ZohoCrmTemplatesService to a stub returning canned import counts.
     *
     * @param  array{imported: int, updated: int, skipped: int}|null  $result
     * @param  \Throwable|null  $throw  If given, the stub throws instead.
     */
    private function bindTemplatesServiceStub(
        ?array $result = null,
        ?\Throwable $throw = null,
    ): void {
        $result ??= ['imported' => 3, 'updated' => 1, 'skipped' => 0];

        $this->app->bind(ZohoCrmTemplatesService::class, function () use ($result, $throw) {
            return new class ($result, $throw) {
                public function __construct(
                    private readonly array $result,
                    private readonly mixed $throw,
                ) {}

                public function import(): array
                {
                    if ($this->throw !== null) {
                        throw $this->throw;
                    }

                    return $this->result;
                }
            };
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // index — GET /admin/zoho
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Guest is redirected to login (302 → /login).
     */
    public function test_index_guest_redirects_to_login(): void
    {
        $this->get('/admin/zoho')
            ->assertStatus(302)
            ->assertRedirect('/login');
    }

    /**
     * User with 'view zoho' (superadmin) gets HTTP 200.
     */
    public function test_index_superadmin_returns_200(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/zoho')
            ->assertStatus(200)
            // 'Driver CRM' and 'Driver Campaigns' are static blade strings rendered
            // by the controller itself, independent of any mocked service.
            ->assertSee('Driver CRM', false)
            ->assertSee('Driver Campaigns', false)
            // 'Token OAuth' is the heading of the OAuth card, always present.
            ->assertSee('Token OAuth', false);
    }

    /**
     * User without 'view zoho' permission gets 403.
     */
    public function test_index_without_view_zoho_permission_returns_403(): void
    {
        $user = $this->makeBackendOnlyUser();

        $this->actingAs($user)
            ->get('/admin/zoho')
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // sync — POST /admin/zoho/sync
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Guest POST to sync redirects to login.
     */
    public function test_sync_guest_redirects_to_login(): void
    {
        $this->post('/admin/zoho/sync')
            ->assertStatus(302)
            ->assertRedirect('/login');
    }

    /**
     * User without 'sync zoho' (backend.access only) gets 403.
     */
    public function test_sync_without_sync_zoho_permission_returns_403(): void
    {
        $user = $this->makeBackendOnlyUser();

        $this->actingAs($user)
            ->post('/admin/zoho/sync')
            ->assertStatus(403);
    }

    /**
     * Superadmin sync (no module param) — full Accounts+Contacts sync.
     * Asserts: stub was called (module=null), redirect to index, success flash.
     */
    public function test_sync_superadmin_no_module_redirects_with_success_flash(): void
    {
        $calledWith = null;
        $this->bindSyncServiceStub(function (?string $m) use (&$calledWith) {
            $calledWith = $m ?? '__null__';
        });

        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync')
            ->assertStatus(302)
            ->assertRedirect(route('admin.zoho.index'));

        $this->assertSame('__null__', $calledWith, 'sync() must be called with module=null when no param given');

        // Verify the flash key is 'success' (follow redirect and check session)
        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync')
            ->assertSessionHas('success');
    }

    /**
     * Superadmin sync with module=Accounts — restricted to Accounts only.
     * Asserts: stub called with 'Accounts', flash label says 'Accounts'.
     */
    public function test_sync_with_module_accounts_passes_module_to_service(): void
    {
        $calledWith = null;
        $this->bindSyncServiceStub(function (?string $m) use (&$calledWith) {
            $calledWith = $m;
        });

        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync', ['module' => 'Accounts'])
            ->assertStatus(302)
            ->assertRedirect(route('admin.zoho.index'))
            ->assertSessionHas('success');

        $this->assertSame('Accounts', $calledWith, 'sync() must receive module=Accounts');
    }

    /**
     * Superadmin sync with module=Contacts.
     */
    public function test_sync_with_module_contacts_passes_module_to_service(): void
    {
        $calledWith = null;
        $this->bindSyncServiceStub(function (?string $m) use (&$calledWith) {
            $calledWith = $m;
        });

        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync', ['module' => 'Contacts'])
            ->assertStatus(302)
            ->assertRedirect(route('admin.zoho.index'))
            ->assertSessionHas('success');

        $this->assertSame('Contacts', $calledWith);
    }

    /**
     * Sync with invalid module value fails validation (422).
     */
    public function test_sync_with_invalid_module_fails_validation(): void
    {
        $this->bindSyncServiceStub();

        $this->actingAs($this->superadmin)
            ->postJson('/admin/zoho/sync', ['module' => 'InvalidModule'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['module']);
    }

    /**
     * When ZohoCrmSyncService::sync() throws, the controller catches the exception,
     * flashes an error message, and still redirects (no 500).
     */
    public function test_sync_service_exception_flashes_error_and_redirects(): void
    {
        $this->app->bind(ZohoCrmSyncService::class, function () {
            return new class {
                public function sync(?string $module = null): void
                {
                    throw new \RuntimeException('Zoho API timeout');
                }
            };
        });

        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync')
            ->assertStatus(302)
            ->assertRedirect(route('admin.zoho.index'))
            ->assertSessionHas('error');
    }

    /**
     * The error flash message contains the exception message (truncated to 200 chars).
     */
    public function test_sync_service_exception_flash_contains_error_text(): void
    {
        $this->app->bind(ZohoCrmSyncService::class, function () {
            return new class {
                public function sync(?string $module = null): void
                {
                    throw new \RuntimeException('Connection refused by Zoho');
                }
            };
        });

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync');

        $response->assertSessionHas('error');

        $flash = session('error');
        $this->assertStringContainsString('Connection refused by Zoho', $flash);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // syncTemplates — POST /admin/zoho/sync-templates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Guest POST to syncTemplates redirects to login.
     */
    public function test_sync_templates_guest_redirects_to_login(): void
    {
        $this->post('/admin/zoho/sync-templates')
            ->assertStatus(302)
            ->assertRedirect('/login');
    }

    /**
     * User without 'create campaign_templates' permission gets 403.
     */
    public function test_sync_templates_without_permission_returns_403(): void
    {
        $user = $this->makeBackendOnlyUser();

        $this->actingAs($user)
            ->post('/admin/zoho/sync-templates')
            ->assertStatus(403);
    }

    /**
     * A user lacking 'create campaign_templates' → 403.
     *
     * NOTE: the `commercial` role INTENTIONALLY HAS 'create campaign_templates'
     * (campaign_templates is a prospection entity; commercial gets view/create/edit
     * on prospection entities), so commercial is NOT a valid negative actor here.
     * Use a bare backend.access-only user instead.
     */
    public function test_sync_templates_403_for_user_without_create_campaign_templates(): void
    {
        $user = $this->makeBackendOnlyUser();

        // Mock the templates service so no live call happens even if the gate passed.
        $this->bindTemplatesServiceStub();

        $this->actingAs($user)
            ->post('/admin/zoho/sync-templates')
            ->assertStatus(403);
    }

    /**
     * Superadmin syncTemplates — service returns canned result.
     * Asserts: redirect to admin.zoho.index + success flash with import counts.
     */
    public function test_sync_templates_superadmin_redirects_with_success_flash(): void
    {
        $this->bindTemplatesServiceStub(['imported' => 3, 'updated' => 1, 'skipped' => 0]);

        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync-templates')
            ->assertStatus(302)
            ->assertRedirect(route('admin.zoho.index'))
            ->assertSessionHas('success');
    }

    /**
     * The success flash message includes the import/update/skipped counts from the service.
     */
    public function test_sync_templates_flash_contains_import_counts(): void
    {
        $this->bindTemplatesServiceStub(['imported' => 5, 'updated' => 2, 'skipped' => 1]);

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync-templates');

        $response->assertSessionHas('success');

        $flash = session('success');
        $this->assertStringContainsString('5', $flash, 'Flash must mention imported count (5)');
        $this->assertStringContainsString('2', $flash, 'Flash must mention updated count (2)');
        $this->assertStringContainsString('1', $flash, 'Flash must mention skipped count (1)');
    }

    /**
     * When ZohoCrmTemplatesService::import() throws, the controller catches it,
     * flashes an error, and redirects (no 500).
     */
    public function test_sync_templates_service_exception_flashes_error_and_redirects(): void
    {
        $this->bindTemplatesServiceStub(throw: new \RuntimeException('Zoho token expired'));

        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync-templates')
            ->assertStatus(302)
            ->assertRedirect(route('admin.zoho.index'))
            ->assertSessionHas('error');
    }

    /**
     * The error flash for syncTemplates includes the exception message.
     */
    public function test_sync_templates_exception_flash_contains_error_text(): void
    {
        $this->bindTemplatesServiceStub(throw: new \RuntimeException('Zoho token expired'));

        $response = $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync-templates');

        $response->assertSessionHas('error');

        $flash = session('error');
        $this->assertStringContainsString('Zoho token expired', $flash);
    }

    /**
     * Service stub is actually called when syncTemplates runs (not a no-op path).
     */
    public function test_sync_templates_service_import_is_invoked(): void
    {
        $called = false;

        // Use a mutable container object so we can track the call without
        // passing a reference to an anonymous class constructor (PHP does not
        // support that syntax).
        $tracker = new \stdClass();
        $tracker->called = false;

        $this->app->bind(ZohoCrmTemplatesService::class, function () use ($tracker) {
            return new class ($tracker) {
                public function __construct(private readonly \stdClass $tracker) {}

                public function import(): array
                {
                    $this->tracker->called = true;

                    return ['imported' => 0, 'updated' => 0, 'skipped' => 0];
                }
            };
        });

        $this->actingAs($this->superadmin)
            ->post('/admin/zoho/sync-templates');

        $this->assertTrue($tracker->called, 'ZohoCrmTemplatesService::import() must be called by syncTemplates action');
    }
}

// <<<
