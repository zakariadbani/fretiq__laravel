<?php

namespace Tests\Feature\Backend;

use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProviderActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('backend.access', 'web');
        Permission::findOrCreate('view provider activity', 'web');
        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->givePermissionTo('backend.access');
    }

    public function test_provider_activity_requires_its_dedicated_permission(): void
    {
        $this->actingAs($this->user)
            ->get(route('admin.provider_activity.index'))
            ->assertForbidden();

        $this->user->givePermissionTo('view provider activity');
        $this->actingAs($this->user)
            ->get(route('admin.provider_activity.index'))
            ->assertOk()
            ->assertSee('Activité fournisseurs');
    }

    public function test_provider_activity_never_renders_key_url_raw_payload_or_candidate_email(): void
    {
        $this->user->givePermissionTo('view provider activity');
        ProviderCall::query()->create([
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'engine' => 'hunter',
            'idempotency_key' => str_repeat('a', 64),
            'status' => 'failed',
            'http_status' => 429,
            'reserved_units' => 1,
            'consumed_units' => 0,
            'attempt_count' => 1,
            'metadata' => [
                'error_code' => 'rate_limit',
                'api_key' => 'secret-provider-key',
                'url' => 'https://provider.test/?api_key=secret-provider-key',
                'raw_response' => 'raw-secret-payload',
                'email' => 'candidate@example.test',
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('admin.provider_activity.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('domain_search', $body);
        $this->assertStringContainsString('rate_limit', $body);
        $this->assertStringNotContainsString('secret-provider-key', $body);
        $this->assertStringNotContainsString('raw-secret-payload', $body);
        $this->assertStringNotContainsString('candidate@example.test', $body);
    }
}
