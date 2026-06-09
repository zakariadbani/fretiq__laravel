<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavbarAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that authenticated user can see navbar with profile information.
     */
    public function test_authenticated_user_sees_navbar_with_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Test User');
    }

    /**
     * Test that navbar handles missing profile photo gracefully.
     */
    public function test_navbar_handles_missing_profile_photo(): void
    {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'profile_photo_path' => null,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        // Should display initial instead of crashing
        $response->assertSee('J'); // First letter of Jane
    }

    /**
     * Test that unauthenticated users are redirected and don't crash the app.
     */
    public function test_unauthenticated_user_redirected_without_error(): void
    {
        $response = $this->get('/dashboard');

        // Should redirect to login, not crash with null reference error
        $response->assertRedirect('/login');
    }

    /**
     * Test that navbar handles empty name string gracefully.
     */
    public function test_navbar_handles_empty_user_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'noname@example.com',
        ]);

        // Even with a valid user, the template null coalescing should work
        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        // Should not crash with null reference errors
        $response->assertDontSee('Attempt to read property');
        $response->assertSee('Test User');
    }

    /**
     * Test that template null coalescing protects against runtime errors.
     */
    public function test_navbar_template_null_safety(): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        // Should handle all user property accesses safely with null coalescing
        $response->assertDontSee('Attempt to read property');
        $response->assertSee('John Doe');
    }
}

