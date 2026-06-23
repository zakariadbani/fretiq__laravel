<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\DemoCompaniesSeeder;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DemoCompaniesSeederTest — verifies the factory runtime path in DemoCompaniesSeeder.
 *
 * Checks: correct row counts, country and domain invariants, idempotency.
 * Runs on the test DB under RefreshDatabase; does not touch dev data.
 * APP_ENV=testing means the seeder's env-gate passes and rows are seeded.
 */
class DemoCompaniesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_companies_and_contacts(): void
    {
        $this->seed(DemoCompaniesSeeder::class);

        // Row counts
        $this->assertSame(6, Company::count(), 'Expected 6 demo companies.');
        $this->assertSame(12, Contact::count(), 'Expected 12 demo contacts (2 per company).');

        // Every company must be in France
        $this->assertSame(
            0,
            Company::where('country', '!=', 'FR')->count(),
            'Every seeded company must have country = FR.'
        );

        // Every company must use a .seed.test domain
        $this->assertSame(
            6,
            Company::where('domain', 'like', '%.seed.test')->count(),
            'Every seeded company must have a .seed.test domain.'
        );
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        // Running twice must not duplicate rows (per-domain exists-check).
        $this->seed(DemoCompaniesSeeder::class);
        $this->seed(DemoCompaniesSeeder::class);

        $this->assertSame(6, Company::count(), 'Companies must not be duplicated on reseed.');
        $this->assertSame(12, Contact::count(), 'Contacts must not be duplicated on reseed.');
    }
}
