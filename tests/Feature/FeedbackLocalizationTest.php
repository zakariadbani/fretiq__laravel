<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeedbackLocalizationTest extends TestCase
{
    public function test_login_validation_uses_french_summary_and_field_messages(): void
    {
        $this->from(route('login'))
            ->post(route('login'), [])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'email' => 'Le champ adresse e-mail est obligatoire.',
                'password' => 'Le champ mot de passe est obligatoire.',
            ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Veuillez corriger les champs signalés.')
            ->assertSeeText('Le champ adresse e-mail est obligatoire.')
            ->assertSee('role="alert"', false);

        $this->assertSame('Ces identifiants ne correspondent pas à nos enregistrements.', __('auth.failed'));
    }

    public function test_success_feedback_is_announced_without_auto_dismissal(): void
    {
        $this->withSession(['success' => 'Enregistrement créé avec succès.'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="fr"', false)
            ->assertSeeText('Enregistrement créé avec succès.')
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false);
    }

    public function test_authorization_and_empty_state_messages_are_french(): void
    {
        Route::get('/__tests/forbidden-feedback', static fn () => abort(403));

        $this->get('/__tests/forbidden-feedback')
            ->assertForbidden()
            ->assertSeeText('Accès refusé');

        $this->assertSame('Aucune donnée disponible dans le tableau.', __('datatables.emptyTable'));
        $this->assertSame('Aucun résultat ne correspond à votre recherche.', __('datatables.zeroRecords'));
    }
}
