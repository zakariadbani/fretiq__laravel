<?php

namespace Tests\Feature\Console;

use App\Models\Contact;
use App\Models\User;
use App\Models\Zoho\ZohoContact;
use App\Models\Zoho\ZohoMarketingLink;
use App\Models\Zoho\ZohoUser;
use App\Models\Zoho\ZohoUserMapping;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoV2NightlyIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_nightly_follow_up_is_completion_driven_instead_of_clock_based(): void
    {
        $events = collect(app(Schedule::class)->events());
        $inventory = $events->first(fn ($event): bool => str_contains((string) $event->command, 'zoho:crm:inventory'));
        $identity = $events->first(fn ($event): bool => str_contains((string) $event->command, 'zoho:crm:link-identities'));
        $retry = $events->first(fn ($event): bool => str_contains((string) $event->command, 'zoho:crm:retry-failures'));

        $this->assertNull($inventory);
        $this->assertNull($identity);
        $this->assertNull($retry);
    }

    public function test_identity_command_links_without_zoho_users(): void
    {
        Contact::factory()->create(['email' => 'nightly@example.test']);
        ZohoContact::query()->create([
            'zoho_id' => 'nightly-contact',
            'email' => 'nightly@example.test',
            'normalized_email' => 'nightly@example.test',
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'nightly-contact'),
        ]);

        $this->artisan('zoho:crm:link-identities')
            ->expectsOutputToContain('1 created')
            ->assertExitCode(0);

        $link = ZohoMarketingLink::query()->sole();
        $this->assertSame('non_causal', $link->audit_metadata['attribution']);
        $this->assertStringStartsWith('v1:', $link->match_key_hash);
    }

    public function test_identity_command_also_auto_maps_unique_mirrored_user_emails(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test', 'is_active' => true]);
        ZohoUser::query()->create([
            'zoho_id' => 'owner-zoho-id',
            'email' => 'owner@example.test',
            'normalized_email' => 'owner@example.test',
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'owner-zoho-id'),
        ]);

        $this->artisan('zoho:crm:link-identities')
            ->expectsOutputToContain('1 mapped')
            ->assertExitCode(0);

        $mapping = ZohoUserMapping::query()->sole();
        $this->assertSame($user->id, $mapping->fretiq_user_id);
        $this->assertTrue($mapping->is_confirmed);
        $this->assertSame('exact_email', $mapping->match_method);
    }
}
