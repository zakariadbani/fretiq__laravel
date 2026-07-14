<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered()
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
        $response->assertSee('Vérifier votre adresse e-mail');
        $response->assertSee('Merci pour votre inscription.');
        $response->assertSee('Renvoyer l&#039;e-mail de vérification', false);
        $response->assertSee('Se déconnecter');
        $response->assertDontSee('Verify Email');
        $response->assertDontSee('Resend Verification Email');
        $response->assertDontSee('Log out');
    }

    public function test_email_verification_screen_displays_french_resent_feedback()
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['status' => 'verification-link-sent'])
            ->get('/verify-email');

        $response->assertStatus(200);
        $response->assertSee('Un nouveau lien de vérification a été envoyé');
        $response->assertDontSee('A new verification link has been sent');
    }

    public function test_email_can_be_verified()
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(RouteServiceProvider::HOME.'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash()
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
