<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Seeder;

/**
 * DemoCompaniesSeeder — local/testing only.
 *
 * Seeds ~6 demo companies with stable .seed.test domains and 2 contacts each.
 * Double-guarded:
 *   1. Early-return if APP_ENV is not local or testing (defense-in-depth).
 *   2. Per-domain exists-check for non-fresh reseed idempotency.
 *
 * NEVER seeds in production.
 */
class DemoCompaniesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DemoCompaniesSeeder skipped (env not local/testing).');
            return;
        }

        $companies = [
            ['domain' => 'meridian-transport.seed.test',  'relationship' => 'prospect', 'sector' => 'Transport & Logistique'],
            ['domain' => 'industrie-prevot.seed.test',     'relationship' => 'client',   'sector' => 'Industrie'],
            ['domain' => 'distri-martin.seed.test',        'relationship' => 'prospect', 'sector' => 'Distribution'],
            ['domain' => 'agro-duplessis.seed.test',       'relationship' => 'prospect', 'sector' => 'Agroalimentaire'],
            ['domain' => 'batiment-collin.seed.test',      'relationship' => 'client',   'sector' => 'BTP'],
            ['domain' => 'logistique-renard.seed.test',    'relationship' => 'prospect', 'sector' => 'Transport & Logistique'],
        ];

        foreach ($companies as $attrs) {
            if (Company::where('domain', $attrs['domain'])->exists()) {
                continue;
            }

            $company = Company::factory()->create([
                'domain'       => $attrs['domain'],
                'country'      => 'FR',
                'relationship' => $attrs['relationship'],
                'sector'       => $attrs['sector'],
            ]);

            Contact::factory()->count(2)->create(['company_id' => $company->id]);
        }
    }
}
