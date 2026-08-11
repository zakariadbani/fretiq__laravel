<?php

namespace Tests\Feature\Backend;

use App\Jobs\VerifyContactEmailJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ProviderCall;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ContactVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->contact = Contact::factory()->for(Company::factory()->state(['relationship' => 'prospect']))->create([
            'email' => 'verification@example.test',
        ]);
        config()->set('services.hunter.api_key', 'test-key');
        config()->set('services.hunter.driver', 'live');
        config()->set('prospecting.email_verification_ttl_days', 90);
        Permission::findOrCreate('verify contacts', 'web');
        Permission::findOrCreate('backend.access', 'web');
        $this->user->givePermissionTo('backend.access');
    }

    public function test_verify_requires_permission_and_explicit_cost_confirmation(): void
    {
        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact))
            ->assertForbidden();

        $this->user->givePermissionTo('verify contacts');
        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact), ['client_token' => (string) Str::uuid()])
            ->assertSessionHasErrors('confirm_provider_cost');
    }

    public function test_verifier_persists_status_source_and_checked_at_then_settles_ledger(): void
    {
        $this->user->givePermissionTo('verify contacts');
        Http::fake(['*email-verifier*' => Http::response(['data' => ['status' => 'valid']], 200)]);

        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact), [
                'confirm_provider_cost' => '1',
                'client_token' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.contacts.view', $this->contact));

        $this->assertDatabaseHas('contacts', [
            'id' => $this->contact->id,
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
        ]);
        $call = ProviderCall::query()->sole();
        $this->assertSame('succeeded', $call->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $call->idempotency_key);
        $this->assertArrayNotHasKey('email', $call->metadata ?? []);
    }

    public function test_202_then_200_polls_same_logical_call_once(): void
    {
        Bus::fake();
        $this->user->givePermissionTo('verify contacts');
        Http::fakeSequence()
            ->push(['data' => []], 202)
            ->push(['data' => ['status' => 'valid']], 200);

        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact), [
                'confirm_provider_cost' => '1',
                'client_token' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $call = ProviderCall::query()->sole();
        $this->assertSame('pending', $call->status);
        Bus::assertDispatched(VerifyContactEmailJob::class, fn (VerifyContactEmailJob $job): bool => $job->afterCommit === true);

        $this->travel(1)->minutes();
        app(\App\Services\Discovery\ContactVerificationService::class)->poll($this->contact->id, $call->idempotency_key);
        $this->assertSame(1, ProviderCall::count());
        $this->assertDatabaseHas('provider_calls', ['id' => $call->id, 'status' => 'succeeded']);
    }

    public function test_local_verifier_never_consumes_provider_units_or_sends_http(): void
    {
        $this->user->givePermissionTo('verify contacts');
        config()->set('services.hunter.driver', 'local');
        Http::preventStrayRequests();

        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact), [
                'confirm_provider_cost' => '1',
            ])
            ->assertRedirect();

        $call = ProviderCall::query()->sole();
        $this->assertSame('succeeded', $call->status);
        $this->assertSame('0.00', (string) $call->reserved_units);
        $this->assertSame('0.00', (string) $call->consumed_units);
        Http::assertNothingSent();
    }

    public function test_manual_approval_makes_unknown_contact_eligible(): void
    {
        $this->user->givePermissionTo('verify contacts');

        $this->actingAs($this->user)
            ->post(route('admin.contacts.approve-email', $this->contact))
            ->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'id' => $this->contact->id,
            'email_verification_status' => 'unknown',
            'email_verification_source' => 'manual',
        ]);
    }

    public function test_claimed_verifier_result_suppresses_and_invalidates_the_contact(): void
    {
        $this->user->givePermissionTo('verify contacts');
        Http::fake(['*email-verifier*' => Http::response([
            'data' => [],
            'errors' => [['code' => 'claimed_email']],
        ], 451)]);

        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact), [
                'confirm_provider_cost' => '1',
                'client_token' => (string) Str::uuid(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contacts', ['id' => $this->contact->id, 'email_verification_status' => 'invalid']);
        $this->assertDatabaseHas('suppressions', ['contact_id' => $this->contact->id, 'reason' => 'claimed', 'source' => 'hunter']);
        $this->assertSame(1, Suppression::count());
    }

    public function test_fresh_evidence_makes_a_normal_recheck_a_no_op(): void
    {
        $this->user->givePermissionTo('verify contacts');
        $this->contact->update([
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ]);
        Http::preventStrayRequests();

        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact), ['confirm_provider_cost' => '1'])
            ->assertRedirect();

        $this->assertSame(0, ProviderCall::count());
    }

    public function test_forced_recheck_requires_a_client_uuid(): void
    {
        $this->user->givePermissionTo('verify contacts');

        $this->actingAs($this->user)
            ->post(route('admin.contacts.verify-email', $this->contact), [
                'confirm_provider_cost' => '1',
                'force' => '1',
            ])
            ->assertSessionHasErrors('client_token');
    }

    public function test_fresh_contact_action_is_an_explicit_forced_recheck(): void
    {
        Permission::findOrCreate('view contacts', 'web');
        $this->user->givePermissionTo(['view contacts', 'verify contacts']);
        $this->contact->update([
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.contacts.view', $this->contact))
            ->assertOk()
            ->assertSee('Revérifier')
            ->assertSee('name="force"', false)
            ->assertSee('name="client_token"', false);
    }

    public function test_repeated_pending_polls_finish_as_unknown_instead_of_remaining_pending(): void
    {
        Bus::fake();
        $this->user->givePermissionTo('verify contacts');
        Http::fakeSequence()
            ->push(['data' => []], 202)
            ->push(['data' => []], 202)
            ->push(['data' => []], 202)
            ->push(['data' => []], 202);

        $this->actingAs($this->user)->post(route('admin.contacts.verify-email', $this->contact), [
            'confirm_provider_cost' => '1',
        ])->assertRedirect();
        $call = ProviderCall::query()->sole();

        foreach ([1, 15, 60] as $minutes) {
            $this->travel($minutes)->minutes();
            app(\App\Services\Discovery\ContactVerificationService::class)->poll($this->contact->id, $call->idempotency_key);
        }

        $this->assertDatabaseHas('contacts', ['id' => $this->contact->id, 'email_verification_status' => 'unknown']);
        $this->assertDatabaseHas('provider_calls', ['id' => $call->id, 'status' => 'succeeded']);
    }

    public function test_contact_view_shows_localized_accept_all_evidence_and_warning(): void
    {
        Permission::findOrCreate('view contacts', 'web');
        $this->user->givePermissionTo('view contacts');
        $checkedAt = now()->startOfMinute();
        $this->contact->update([
            'email_verification_status' => 'accept_all',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => $checkedAt,
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.contacts.view', $this->contact))
            ->assertOk()
            ->assertSee('Accept-all')
            ->assertSee('Hunter Verifier')
            ->assertSee($checkedAt->format('d/m/Y H:i'))
            ->assertSee('envoi autorisé uniquement si le retour de livraison est opérationnel');
    }

    public function test_generic_contact_update_cannot_forge_verification_evidence(): void
    {
        Permission::findOrCreate('edit contacts', 'web');
        $this->user->givePermissionTo('edit contacts');

        $this->actingAs($this->user)
            ->putJson(route('admin.contacts.update', $this->contact), [
                'company_id' => $this->contact->company_id,
                'name' => $this->contact->name,
                'email' => $this->contact->email,
                'source' => 'manual',
                'status' => 'new',
                'legal_basis' => 'relationship',
                'email_kind' => 'role',
                'email_verification_status' => 'valid',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
            ])
            ->assertOk();

        $this->contact->refresh();
        $this->assertNull($this->contact->email_verification_status);
        $this->assertNull($this->contact->email_verification_source);
        $this->assertNull($this->contact->email_verification_checked_at);
    }
}
