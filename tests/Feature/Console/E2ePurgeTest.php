<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E2ePurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_statistics_fixture_cleanup_is_exact_and_idempotent(): void
    {
        $fixtureCompany = Company::create([
            'name' => 'E2E_FIXTURE Sequence Statistics Company',
            'domain' => 'e2e-sequence-statistics.example.test',
            'country' => 'FR',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'qualified',
        ]);
        $keptCompany = Company::create([
            'name' => 'Similar company that must remain',
            'domain' => 'keep-e2e-sequence-statistics.example.test',
            'country' => 'FR',
            'relationship' => 'prospect',
            'source' => 'manual',
            'qualification_status' => 'qualified',
        ]);

        $fixtureContacts = collect([
            'e2e.stats.opened@example.test',
            'e2e.stats.unsent@example.test',
        ])->map(fn (string $email) => Contact::create([
            'company_id' => $fixtureCompany->id,
            'name' => $email,
            'email' => $email,
            'source' => 'manual',
            'status' => 'contacted',
            'legal_basis' => 'legitimate_interest',
            'email_kind' => 'role',
        ]));
        $keptContact = Contact::create([
            'company_id' => $keptCompany->id,
            'name' => 'Similar contact',
            'email' => 'e2e.stats.opened+keep@example.test',
            'source' => 'manual',
            'status' => 'contacted',
            'legal_basis' => 'legitimate_interest',
            'email_kind' => 'role',
        ]);

        $this->artisan('fretiq:e2e-purge')->assertExitCode(0);
        $this->artisan('fretiq:e2e-purge')->assertExitCode(0);

        foreach ($fixtureContacts as $contact) {
            $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
        }
        $this->assertDatabaseMissing('companies', ['id' => $fixtureCompany->id]);
        $this->assertDatabaseHas('contacts', ['id' => $keptContact->id]);
        $this->assertDatabaseHas('companies', ['id' => $keptCompany->id]);
    }
}

