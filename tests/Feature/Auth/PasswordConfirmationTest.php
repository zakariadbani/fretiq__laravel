<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_has_a_native_form(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk()
            ->assertSee('action="'.route('password.confirm').'"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('name="password"', false)
            ->assertSee('type="submit"', false)
            ->assertSee('Confirmez votre mot de passe')
            ->assertSee('Cette zone est sécurisée. Confirmez votre mot de passe pour continuer.');
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['url.intended' => route('dashboard')])
            ->post(route('password.confirm'), ['password' => 'password']);

        $response->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_password_is_not_confirmed_with_an_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('password.confirm'), [
            'password' => 'invalid-password',
        ]);

        $response->assertSessionHasErrors(['password' => __('auth.password')])
            ->assertSessionMissing('auth.password_confirmed_at');
    }
}
