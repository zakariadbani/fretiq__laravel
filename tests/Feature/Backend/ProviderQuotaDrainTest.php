<?php

namespace Tests\Feature\Backend;

use App\Jobs\DrainEnrichmentJob;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ProviderQuotaDrainTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        Bus::fake();
    }

    public function test_admin_and_commercial_are_forbidden_from_drain_endpoints(): void
    {
        // Both lack `view provider quota`, so the controller middleware 403s them
        // before any method (and before the drain job could ever dispatch).
        foreach (['admin', 'commercial'] as $role) {
            $user = $this->user($role);

            $this->actingAs($user)
                ->postJson(route('admin.provider-quota.drain-preview'), ['mode' => 'companies'])
                ->assertForbidden();

            $this->actingAs($user)
                ->postJson(route('admin.provider-quota.drain'), ['mode' => 'companies', 'token' => 'x'])
                ->assertForbidden();
        }

        Bus::assertNotDispatched(DrainEnrichmentJob::class);
    }

    public function test_superadmin_can_preview_and_dispatch_a_drain(): void
    {
        $superadmin = $this->user('superadmin');

        $preview = $this->actingAs($superadmin)
            ->postJson(route('admin.provider-quota.drain-preview'), ['mode' => 'companies', 'include_empty' => false])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $token = $preview->json('token');
        $this->assertNotEmpty($token);

        $this->actingAs($superadmin)
            ->postJson(route('admin.provider-quota.drain'), ['mode' => 'companies', 'token' => $token])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Bus::assertDispatched(DrainEnrichmentJob::class, fn (DrainEnrichmentJob $job) => $job->mode === 'companies');
    }

    public function test_drain_rejects_a_missing_or_garbage_token(): void
    {
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->postJson(route('admin.provider-quota.drain'), ['mode' => 'companies'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->actingAs($superadmin)
            ->postJson(route('admin.provider-quota.drain'), ['mode' => 'companies', 'token' => 'not-a-real-token'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Bus::assertNotDispatched(DrainEnrichmentJob::class);
    }

    public function test_verify_contacts_gate_blocks_verify_and_full_but_allows_companies(): void
    {
        // A superadmin-tier operator granted the quota page + company enrichment but
        // NOT `verify contacts`. Exercises the per-action gate that no seeded role can
        // reach (superadmin has everything; admin/commercial lack `view provider quota`).
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        // backend.access gates the whole Backend route group; view provider quota
        // gates the page; enrich companies gates the drain — but NOT verify contacts.
        $user->givePermissionTo('backend.access', 'view provider quota', 'enrich companies');

        // companies preview → 200 (no verify permission required)
        $preview = $this->actingAs($user)
            ->postJson(route('admin.provider-quota.drain-preview'), ['mode' => 'companies'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // companies drain with the signed token → 200
        $this->actingAs($user)
            ->postJson(route('admin.provider-quota.drain'), ['mode' => 'companies', 'token' => $preview->json('token')])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // verify + full → 403 on BOTH endpoints (the gate fires before token checks)
        foreach (['verify', 'full'] as $mode) {
            $this->actingAs($user)
                ->postJson(route('admin.provider-quota.drain-preview'), ['mode' => $mode])
                ->assertForbidden();

            $this->actingAs($user)
                ->postJson(route('admin.provider-quota.drain'), ['mode' => $mode, 'token' => 'x'])
                ->assertForbidden();
        }
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->postJson(route('admin.provider-quota.drain-preview'), ['mode' => 'bogus'])
            ->assertStatus(422);

        $this->actingAs($superadmin)
            ->postJson(route('admin.provider-quota.drain'), ['mode' => 'bogus', 'token' => 'x'])
            ->assertStatus(422);

        Bus::assertNotDispatched(DrainEnrichmentJob::class);
    }
}
