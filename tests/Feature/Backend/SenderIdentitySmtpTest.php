<?php

namespace Tests\Feature\Backend;

use App\Crud\ViewConfigs\SenderIdentityViewConfig;
use App\Mail\SmtpConnectionTestMailable;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Mail\RequiredTlsEsmtpTransport;
use App\Services\Mail\SmtpMailRouter;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class SenderIdentitySmtpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        config(['mail.smtp_mode' => 'mailpit', 'mail.smtp_sent_copy.enabled' => false]);
        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->assignRole('superadmin');
    }

    public function test_smtp_password_is_encrypted_hidden_and_blank_update_preserves_it(): void
    {
        $identity = $this->identity(['smtp_password' => 'secret-password']);

        $this->assertNotSame('secret-password', DB::table('sender_identities')->where('id', $identity->id)->value('smtp_password'));
        $this->assertSame('secret-password', $identity->fresh()->smtp_password);
        $this->assertArrayNotHasKey('smtp_password', $identity->fresh()->toArray());

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'smtp_password' => '',
        ])->assertOk();

        $this->assertSame('secret-password', $identity->fresh()->smtp_password);
    }

    public function test_changed_smtp_connection_requires_a_new_password(): void
    {
        $identity = $this->identity([
            'smtp_enabled' => true,
            'smtp_host' => 'old.example.test',
            'smtp_username' => 'sender@example.test',
            'smtp_password' => 'saved-password',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'smtp_enabled' => true,
            'smtp_host' => 'new.example.test',
            'smtp_port' => 587,
            'smtp_username' => $identity->smtp_username,
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ])->assertStatus(406)->assertJsonValidationErrors('smtp_password');

        $this->assertSame('old.example.test', $identity->fresh()->smtp_host);
    }

    public function test_reenabling_an_identity_with_changed_smtp_connection_requires_a_new_password(): void
    {
        $identity = $this->identity([
            'is_active' => true,
            'smtp_enabled' => false,
            'smtp_host' => 'disabled-old.example.test',
            'smtp_username' => 'sender@example.test',
            'smtp_password' => 'saved-password',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'is_active' => true,
            'smtp_enabled' => true,
            'smtp_host' => 'reenabled-new.example.test',
            'smtp_port' => 587,
            'smtp_username' => $identity->smtp_username,
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ])->assertStatus(406)->assertJsonValidationErrors('smtp_password');

        $identity->refresh();
        $this->assertFalse($identity->smtp_enabled);
        $this->assertSame('disabled-old.example.test', $identity->smtp_host);
        $this->assertSame('saved-password', $identity->smtp_password);
    }

    public function test_changing_a_disabled_smtp_connection_clears_the_saved_password_before_later_enablement(): void
    {
        $identity = $this->identity([
            'smtp_enabled' => false,
            'smtp_host' => 'disabled-old.example.test',
            'smtp_username' => 'sender@example.test',
            'smtp_password' => 'saved-password',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'smtp_enabled' => false,
            'smtp_host' => 'disabled-new.example.test',
            'smtp_port' => 587,
            'smtp_username' => $identity->smtp_username,
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ])->assertOk();

        $identity->refresh();
        $this->assertSame('disabled-new.example.test', $identity->smtp_host);
        $this->assertNull($identity->smtp_password);

        app('router')->getRoutes()->getByName('admin.sender_identities.update')->flushController();
        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'smtp_enabled' => true,
            'smtp_host' => $identity->smtp_host,
            'smtp_port' => $identity->smtp_port,
            'smtp_username' => $identity->smtp_username,
            'smtp_password' => '',
            'smtp_encryption' => $identity->smtp_encryption,
            'smtp_hourly_limit' => $identity->smtp_hourly_limit,
            'smtp_daily_limit' => $identity->smtp_daily_limit,
        ])->assertStatus(406)->assertJsonValidationErrors('smtp_password');

        $this->assertFalse($identity->fresh()->smtp_enabled);
    }

    public function test_smtp_update_locks_the_sender_inside_its_own_transaction_before_persistence(): void
    {
        $identity = $this->identity([
            'smtp_enabled' => false,
            'smtp_host' => 'atomic-old.example.test',
            'smtp_username' => 'sender@example.test',
            'smtp_password' => 'saved-password',
        ]);
        $baselineTransactionLevel = DB::transactionLevel();
        $transactionLevelDuringUpdate = null;
        $queries = [];

        SenderIdentity::updating(function () use (&$transactionLevelDuringUpdate): void {
            $transactionLevelDuringUpdate = DB::transactionLevel();
        });
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'smtp_enabled' => false,
            'smtp_host' => 'atomic-new.example.test',
            'smtp_port' => 587,
            'smtp_username' => $identity->smtp_username,
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ])->assertOk();

        $this->assertGreaterThan($baselineTransactionLevel, $transactionLevelDuringUpdate);
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'sender_identities') && str_contains($sql, 'for update'),
        ));
        $this->assertNull($identity->fresh()->smtp_password);
    }

    public function test_partial_connection_update_cannot_bypass_the_new_password_requirement(): void
    {
        $identity = $this->identity([
            'smtp_enabled' => true,
            'smtp_host' => 'old-partial.example.test',
            'smtp_username' => 'sender@example.test',
            'smtp_password' => 'saved-password',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'smtp_host' => 'crafted-partial.example.test',
            'smtp_password' => '',
        ])->assertStatus(406)->assertJsonValidationErrors('smtp_password');

        $identity->refresh();
        $this->assertSame('old-partial.example.test', $identity->smtp_host);
        $this->assertSame('saved-password', $identity->smtp_password);
        $this->assertTrue($identity->smtp_enabled);
    }

    public function test_smtp_tab_is_edit_only_and_uses_the_registered_tab_pane(): void
    {
        $identity = $this->identity();
        $this->assertSame('edit', collect(SenderIdentityViewConfig::make($identity)['tabs'])->firstWhere('key', 'smtp')['mode']);
        $this->assertNull(collect(SenderIdentityViewConfig::make(null)['tabs'])->firstWhere('key', 'smtp'));

        $this->actingAs($this->user)->get(route('admin.sender_identities.edit', $identity))
            ->assertOk()
            ->assertSee('href="#sender_smtp"', false)
            ->assertSee('id="sender_smtp"', false)
            ->assertSee('data-smtp-status="mailpit"', false)
            ->assertSee('Mailpit — capture locale active.', false);
    }

    public function test_smtp_tab_exposes_all_read_only_router_statuses_without_credentials(): void
    {
        $incomplete = $this->identity(['smtp_password' => 'never-render-this-password']);
        $ready = $this->identity([
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.ready.test',
            'smtp_username' => 'ready-user',
            'smtp_password' => 'another-hidden-password',
        ]);

        config(['mail.smtp_mode' => 'sender_identity']);
        $this->actingAs($this->user)->get(route('admin.sender_identities.edit', $ready))
            ->assertOk()
            ->assertSee('data-smtp-status="sender-identity-ready"', false)
            ->assertSee('Un test peut envoyer un véritable email externe, y compris depuis l’environnement local.', false)
            ->assertDontSee('another-hidden-password', false);

        $this->actingAs($this->user)->get(route('admin.sender_identities.edit', $incomplete))
            ->assertOk()
            ->assertSee('data-smtp-status="sender-identity-incomplete"', false)
            ->assertDontSee('never-render-this-password', false);

        config(['mail.smtp_mode' => null]);
        $this->actingAs($this->user)->get(route('admin.sender_identities.edit', $incomplete))
            ->assertOk()
            ->assertSee('data-smtp-status="invalid-mode"', false);
    }

    public function test_sender_identity_stores_independent_hourly_and_daily_caps(): void
    {
        $a = $this->identity(['email' => 'a@example.test', 'smtp_hourly_limit' => 3, 'smtp_daily_limit' => 15]);
        $b = $this->identity(['email' => 'b@example.test', 'smtp_hourly_limit' => 8, 'smtp_daily_limit' => 40]);

        $this->assertSame([3, 15], [$a->smtp_hourly_limit, $a->smtp_daily_limit]);
        $this->assertSame([8, 40], [$b->smtp_hourly_limit, $b->smtp_daily_limit]);
    }

    public function test_mailpit_mode_ignores_identity_credentials_and_routes_to_mailpit_mailer(): void
    {
        Mail::fake();
        config(['mail.smtp_mode' => 'mailpit']);
        $identity = $this->identity([
            'smtp_enabled' => true,
            'smtp_host' => 'must-not-be-used.example.test',
            'smtp_username' => 'secret-user',
            'smtp_password' => 'secret-pass',
        ]);

        app(SmtpMailRouter::class)->send(
            $identity,
            'receiver@example.test',
            new SmtpConnectionTestMailable($identity),
        );

        Mail::assertSent(SmtpConnectionTestMailable::class, fn ($mail) => $mail->hasTo('receiver@example.test'));
    }

    public function test_required_tls_transport_refuses_authentication_before_encryption(): void
    {
        $this->assertTrue(class_exists(RequiredTlsEsmtpTransport::class));

        $transport = new RequiredTlsEsmtpTransport('smtp.example.test', 587, false);

        $this->expectException(\Symfony\Component\Mailer\Exception\TransportException::class);
        $transport->executeCommand("AUTH LOGIN\r\n", [235]);
    }

    public function test_required_tls_transport_records_a_successful_starttls_upgrade(): void
    {
        $this->assertTrue(method_exists(RequiredTlsEsmtpTransport::class, 'markStartTlsAccepted'));
        $transport = new class('smtp.example.test', 587, false) extends RequiredTlsEsmtpTransport {
            public function simulateSuccessfulStartTls(): void
            {
                $this->markStartTlsAccepted();
            }

            public function encryptionReady(): bool
            {
                return $this->hasEncryptedChannel();
            }
        };

        $this->assertFalse($transport->encryptionReady());
        $transport->simulateSuccessfulStartTls();
        $this->assertTrue($transport->encryptionReady());
    }

    public function test_enabled_smtp_identity_rejects_unencrypted_transport_configuration(): void
    {
        $identity = $this->identity([
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'sender',
            'smtp_password' => 'secret',
        ]);

        $this->actingAs($this->user)->putJson(route('admin.sender_identities.update', $identity), [
            'name' => $identity->name,
            'email' => $identity->email,
            'is_active' => true,
            'smtp_enabled' => true,
            'smtp_host' => $identity->smtp_host,
            'smtp_port' => 25,
            'smtp_username' => $identity->smtp_username,
            'smtp_password' => '',
            'smtp_encryption' => 'none',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ])->assertStatus(406)->assertJsonValidationErrors('smtp_encryption');

        $this->assertSame('tls', $identity->fresh()->smtp_encryption);
    }

    public function test_sender_identity_mode_builds_a_fresh_mailer_for_each_identity_in_local(): void
    {
        config(['mail.smtp_mode' => 'sender_identity']);
        $a = $this->identity(['email' => 'a@example.test', 'smtp_enabled' => true, 'smtp_host' => 'smtp-a.test', 'smtp_username' => 'a', 'smtp_password' => 'pa']);
        $b = $this->identity(['email' => 'b@example.test', 'smtp_enabled' => true, 'smtp_host' => 'smtp-b.test', 'smtp_username' => 'b', 'smtp_password' => 'pb']);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('setSymfonyTransport')->twice();
        $mailer->shouldReceive('to')->twice()->andReturnSelf();
        $mailer->shouldReceive('send')->twice();
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->with(Mockery::on(fn (array $config) => $config['host'] === 'smtp-a.test'))->andReturn($mailer);
        $manager->shouldReceive('build')->once()->with(Mockery::on(fn (array $config) => $config['host'] === 'smtp-b.test'))->andReturn($mailer);
        $this->app->instance(MailManager::class, $manager);

        $service = app(SmtpMailRouter::class);
        $service->send($a, 'receiver@example.test', new SmtpConnectionTestMailable($a));
        $service->send($b, 'receiver@example.test', new SmtpConnectionTestMailable($b));
    }

    public function test_sender_identity_mode_installs_a_fail_closed_starttls_transport_in_local(): void
    {
        config(['mail.smtp_mode' => 'sender_identity']);
        $identity = $this->identity([
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'sender',
            'smtp_password' => 'secret',
            'smtp_encryption' => 'tls',
        ]);
        $mailer = Mockery::mock(Mailer::class);
        $installedTransport = null;
        $mailer->shouldReceive('setSymfonyTransport')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($transport) use (&$installedTransport): void {
                $installedTransport = $transport;
            });
        $mailer->shouldReceive('to')->once()->andReturnSelf();
        $mailer->shouldReceive('send')->once();
        $manager = Mockery::mock(MailManager::class);
        $manager->shouldReceive('build')->once()->andReturn($mailer);
        $this->app->instance(MailManager::class, $manager);

        app(SmtpMailRouter::class)->send(
            $identity,
            'receiver@example.test',
            new SmtpConnectionTestMailable($identity),
        );

        $this->assertInstanceOf(RequiredTlsEsmtpTransport::class, $installedTransport);
    }

    public function test_smtp_test_sends_a_real_mailable_to_the_requested_recipient(): void
    {
        Mail::fake();
        $identity = $this->identity();

        $this->actingAs($this->user)->postJson(route('admin.sender_identities.testSmtp', $identity), [
            'receiver_email' => 'receiver@example.test',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('recipient', 'receiver@example.test')
            ->assertJsonPath('sender.email', $identity->email)
            ->assertJsonPath('mode', 'mailpit')
            ->assertJsonPath('transport', 'Mailpit');

        Mail::assertSent(SmtpConnectionTestMailable::class, fn ($mail) => $mail->hasTo('receiver@example.test'));
    }

    public function test_smtp_test_requires_edit_sender_identity_and_send_campaigns_permissions(): void
    {
        $identity = $this->identity();
        $viewer = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $viewer->givePermissionTo(['backend.access', 'edit sender_identities']);

        $this->actingAs($viewer)->postJson(route('admin.sender_identities.testSmtp', $identity), [
            'receiver_email' => 'receiver@example.test',
        ])->assertForbidden();
    }

    public function test_smtp_test_rejects_incomplete_configuration_and_never_exposes_credentials(): void
    {
        config(['mail.smtp_mode' => 'sender_identity']);
        $identity = $this->identity(['smtp_enabled' => true, 'smtp_password' => 'never-show-me']);

        $response = $this->actingAs($this->user)->postJson(route('admin.sender_identities.testSmtp', $identity), [
            'receiver_email' => 'receiver@example.test',
        ])->assertUnprocessable();

        $this->assertStringNotContainsString('never-show-me', $response->getContent());
    }

    public function test_smtp_test_reports_an_invalid_global_mode_without_sending_or_exposing_credentials(): void
    {
        Mail::fake();
        config(['mail.smtp_mode' => 'invalid']);
        $identity = $this->identity(['smtp_password' => 'never-show-invalid-mode-secret']);

        $response = $this->actingAs($this->user)->postJson(route('admin.sender_identities.testSmtp', $identity), [
            'receiver_email' => 'receiver@example.test',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Configuration SMTP invalide : SMTP_MODE doit être mailpit ou sender_identity.');

        Mail::assertNothingSent();
        $this->assertStringNotContainsString('never-show-invalid-mode-secret', $response->getContent());
    }

    private function identity(array $attributes = []): SenderIdentity
    {
        return SenderIdentity::create(array_merge([
            'name' => 'SMTP sender ' . uniqid(),
            'email' => uniqid('sender') . '@example.test',
            'is_active' => true,
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_hourly_limit' => 10,
            'smtp_daily_limit' => 50,
        ], $attributes));
    }
}
