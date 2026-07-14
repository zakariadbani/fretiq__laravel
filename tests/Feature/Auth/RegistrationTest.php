<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_explains_signup_is_disabled()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertSee('Inscription désactivée');
        $response->assertSee('Contacter l’administrateur');
    }

    public function test_public_registration_post_is_disabled()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertStatus(405);
        $this->assertSame(0, User::count());
    }
}
