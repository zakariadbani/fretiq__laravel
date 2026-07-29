<?php

namespace Tests\Feature\Backend;

use App\Crud\ViewConfigs\SenderIdentityViewConfig;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Inbox\InboxImapService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SenderIdentityImapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->assignRole('superadmin');
    }

    public function test_imap_password_is_encrypted_and_blank_update_preserves_it(): void
    {
        $identity = $this->identity(['imap_password' => 'secret-password']);

        $this->assertNotSame('secret-password', DB::table('sender_identities')->where('id', $identity->id)->value('imap_password'));
        $this->assertSame('secret-password', $identity->fresh()->imap_password);

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'imap_password' => '',
        ])->assertOk();

        $this->assertSame('secret-password', $identity->fresh()->imap_password);
    }

    public function test_connection_endpoint_validates_identity_and_configuration(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('admin.sender_identities.testImap', 999999))
            ->assertNotFound();

        $identity = $this->identity();
        $this->actingAs($this->user)
            ->postJson(route('admin.sender_identities.testImap', $identity))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_connection_endpoint_uses_current_form_values_and_resets_health(): void
    {
        $identity = $this->identity([
            'imap_host' => 'old.example.test',
            'imap_username' => 'old-user@example.test',
            'imap_password' => 'old-password',
        ]);
        SenderIdentity::whereKey($identity->id)->update([
            'consecutive_poll_failures' => 2,
            'last_poll_error' => 'old error',
        ]);
        $fake = Mockery::mock(InboxImapService::class);
        $fake->shouldReceive('testConnection')->once()->withArgs(fn (SenderIdentity $value) =>
            $value->is($identity)
            && $value->imap_host === 'new.example.test'
            && $value->imap_username === 'new-user@example.test'
            && $value->imap_password === 'new-password'
        );
        $this->app->instance(InboxImapService::class, $fake);

        $this->actingAs($this->user)
            ->postJson(route('admin.sender_identities.testImap', $identity), [
                'imap_host' => 'new.example.test',
                'imap_port' => 993,
                'imap_username' => 'new-user@example.test',
                'imap_password' => 'new-password',
                'imap_encryption' => 'ssl',
                'imap_validate_cert' => true,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $identity->refresh();
        $this->assertSame(0, $identity->consecutive_poll_failures);
        $this->assertNull($identity->last_poll_error);
    }

    public function test_connection_errors_are_generic_and_credentials_are_redacted_in_debug(): void
    {
        $identity = $this->identity([
            'imap_host' => 'imap.example.test',
            'imap_username' => 'user@example.test',
            'imap_password' => 'secret-password',
        ]);
        $fake = Mockery::mock(InboxImapService::class);
        $fake->shouldReceive('testConnection')->twice()->andThrow(new RuntimeException('Login failed: secret-password'));
        $fake->shouldReceive('redact')->twice()->andReturn('Login failed: [redacted]');
        $this->app->instance(InboxImapService::class, $fake);

        config(['app.debug' => false]);
        $this->actingAs($this->user)
            ->postJson(route('admin.sender_identities.testImap', $identity))
            ->assertUnprocessable()
            ->assertJsonMissing(['message' => 'Login failed: secret-password']);

        config(['app.debug' => true]);
        $response = $this->actingAs($this->user)
            ->postJson(route('admin.sender_identities.testImap', $identity))
            ->assertUnprocessable();
        $this->assertStringContainsString('[redacted]', $response->json('message'));
        $this->assertStringNotContainsString('secret-password', $response->json('message'));
    }

    public function test_changed_connection_cannot_reuse_the_saved_password(): void
    {
        $identity = $this->identity([
            'imap_host' => 'old.example.test',
            'imap_username' => 'user@example.test',
            'imap_password' => 'saved-password',
            'imap_enabled' => true,
        ]);

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'imap_host' => 'attacker.example.test',
            'imap_port' => 993,
            'imap_username' => $identity->imap_username,
            'imap_password' => '',
            'imap_encryption' => 'ssl',
            'imap_validate_cert' => true,
            'imap_enabled' => true,
        ])->assertStatus(406)->assertJsonValidationErrors('imap_password');

        $this->assertSame('old.example.test', $identity->fresh()->imap_host);
        $this->assertSame('saved-password', $identity->fresh()->imap_password);

        $this->actingAs($this->user)->postJson(route('admin.sender_identities.testImap', $identity), [
            'imap_host' => 'attacker.example.test',
            'imap_port' => 993,
            'imap_username' => $identity->imap_username,
            'imap_password' => '',
            'imap_encryption' => 'ssl',
            'imap_validate_cert' => true,
        ])->assertUnprocessable();
    }

    public function test_connection_endpoint_requires_edit_permission(): void
    {
        $viewer = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $viewer->givePermissionTo('backend.access');
        $identity = $this->identity([
            'imap_host' => 'imap.example.test',
            'imap_username' => 'user@example.test',
            'imap_password' => 'password',
        ]);

        $this->actingAs($viewer)
            ->postJson(route('admin.sender_identities.testImap', $identity))
            ->assertForbidden();
    }

    public function test_imap_tab_is_edit_only_and_targets_the_rendered_pane(): void
    {
        $identity = $this->identity();
        $editTabs = collect(SenderIdentityViewConfig::make($identity)['tabs']);
        $createTabs = collect(SenderIdentityViewConfig::make(null)['tabs']);

        $this->assertSame('edit', $editTabs->firstWhere('key', 'imap')['mode']);
        $this->assertNull($createTabs->firstWhere('key', 'imap'));
        $this->actingAs($this->user)
            ->get(route('admin.sender_identities.edit', $identity))
            ->assertOk()
            ->assertSee('href="#sender_imap"', false)
            ->assertSee('id="sender_imap"', false);
    }

    private function identity(array $attributes = []): SenderIdentity
    {
        return SenderIdentity::create(array_merge([
            'name' => 'IMAP sender',
            'email' => 'sender@example.test',
            'is_active' => true,
        ], $attributes));
    }
}
