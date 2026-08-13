<?php

namespace Tests\Feature\Backend;

use App\Jobs\StartContactEmailVerificationJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmailVerificationAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('prospecting.email_verification_enabled_default', true);
        Permission::findOrCreate('backend.access', 'web');
        Permission::findOrCreate('edit settings', 'web');
        Permission::findOrCreate('verify contacts', 'web');
    }

    public function test_create_and_email_change_queue_unique_first_verification_and_reset_old_evidence(): void
    {
        Bus::fake();
        $company = Company::factory()->create();
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'first@example.test',
        ]);

        Bus::assertDispatched(StartContactEmailVerificationJob::class, function ($job) use ($contact): bool {
            return $job->contactId === $contact->id
                && $job->emailHash === hash('sha256', 'first@example.test');
        });

        $contact->forceFill([
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now(),
        ])->save();
        $contact->update(['email' => 'second@example.test']);

        $fresh = $contact->fresh();
        $this->assertNull($fresh->email_verification_status);
        $this->assertNull($fresh->email_verification_source);
        $this->assertNull($fresh->email_verification_checked_at);
        Bus::assertDispatched(StartContactEmailVerificationJob::class, fn ($job): bool => $job->contactId === $contact->id
            && $job->emailHash === hash('sha256', 'second@example.test'));
    }

    public function test_imported_evidence_is_not_automatically_reverified(): void
    {
        Bus::fake();
        Contact::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now()->subYears(3),
        ]);

        Bus::assertNotDispatched(StartContactEmailVerificationJob::class);
    }

    public function test_disabled_setting_blocks_creation_and_individual_provider_transport(): void
    {
        Bus::fake();
        Http::fake();
        Setting::set('delivrabilite.email_verification_enabled', false);
        $contact = Contact::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'email' => 'disabled@example.test',
        ]);
        Bus::assertNotDispatched(StartContactEmailVerificationJob::class);

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(['backend.access', 'verify contacts']);
        $this->actingAs($user)->post(route('admin.contacts.verify-email', $contact), [
            'confirm_provider_cost' => true,
        ])->assertRedirect(route('admin.contacts.view', $contact))
            ->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_estimate_and_run_require_both_permissions_and_never_call_provider(): void
    {
        Bus::fake();
        Http::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(['backend.access', 'edit settings']);
        Setting::set('delivrabilite.email_verification_enabled', false);

        Contact::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'email' => 'eligible@example.test',
        ]);
        Contact::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'email' => 'imported@example.test',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'import',
            'email_verification_checked_at' => now(),
        ]);
        $suppressed = Contact::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'email' => 'suppressed@example.test',
        ]);
        Suppression::create(['contact_id' => $suppressed->id, 'email' => $suppressed->email, 'reason' => 'manual', 'source' => 'manual']);
        Setting::set('delivrabilite.email_verification_enabled', true);

        $this->actingAs($user)->getJson(route('admin.settings.email-verification.estimate'))->assertForbidden();
        $user->givePermissionTo('verify contacts');
        $estimate = $this->actingAs($user)->getJson(route('admin.settings.email-verification.estimate'))
            ->assertOk()
            ->assertJsonPath('eligible', 1)
            ->assertJsonPath('exclusions.already_verified', 1)
            ->assertJsonPath('exclusions.suppressed', 1)
            ->json();

        $this->actingAs($user)->postJson(route('admin.settings.email-verification.run'), [
            'confirm' => true,
            'expected_count' => $estimate['eligible'],
        ])->assertOk()->assertJsonPath('queued', 1);

        Bus::assertDispatchedTimes(StartContactEmailVerificationJob::class, 1);
        Http::assertNothingSent();
    }
}
