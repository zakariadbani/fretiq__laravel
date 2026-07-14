<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_has_a_native_form(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk()
            ->assertSee('action="'.route('password.email').'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('Envoyer le lien de réinitialisation');
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this->post(route('password.email'), ['email' => $user->email]);

        $response->assertSessionHas('status', __('passwords.sent'));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_has_a_native_form(): void
    {
        $response = $this->get(route('password.reset', [
            'token' => 'reset-token',
            'email' => 'prospect@example.com',
        ]));

        $response->assertOk()
            ->assertSee('action="'.route('password.update').'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('name="token"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('Créer un nouveau mot de passe')
            ->assertDontSee('Terms and conditions');
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('AncienMotDePasse123!'),
        ]);
        $token = Password::createToken($user);

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'MotDePasse123!',
            'password_confirmation' => 'MotDePasse123!',
        ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHas('status', __('passwords.reset'));
        $this->assertTrue(Hash::check('MotDePasse123!', $user->fresh()->password));
    }

    public function test_password_cannot_be_reset_with_an_invalid_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('AncienMotDePasse123!'),
        ]);

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'MotDePasse123!',
            'password_confirmation' => 'MotDePasse123!',
        ]);

        $response->assertSessionHasErrors(['email' => __('passwords.token')]);
        $this->assertTrue(Hash::check('AncienMotDePasse123!', $user->fresh()->password));
    }

    public function test_password_cannot_be_reset_with_an_expired_token(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('AncienMotDePasse123!'),
        ]);
        $token = Password::createToken($user);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        try {
            $response = $this->post(route('password.update'), [
                'token' => $token,
                'email' => $user->email,
                'password' => 'MotDePasse123!',
                'password_confirmation' => 'MotDePasse123!',
            ]);

            $response->assertSessionHasErrors(['email' => __('passwords.token')]);
            $this->assertTrue(Hash::check('AncienMotDePasse123!', $user->fresh()->password));
        } finally {
            $this->travelBack();
        }
    }

    public function test_password_cannot_be_reset_when_confirmation_does_not_match(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('AncienMotDePasse123!'),
        ]);
        $token = Password::createToken($user);

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'MotDePasse123!',
            'password_confirmation' => 'AutreMotDePasse123!',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('AncienMotDePasse123!', $user->fresh()->password));
    }
}
