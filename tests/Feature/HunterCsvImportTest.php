<?php

namespace Tests\Feature;

use App\Jobs\StartContactEmailVerificationJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectCriteria;
use App\Services\Prospecting\HunterCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class HunterCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function companyRow(array $overrides = []): array
    {
        return array_merge([
            'row_number' => 1,
            'company_name' => 'Acme Corp',
            'domain_host' => 'acme.test',
            'domain_registrable' => 'acme.test',
            'sector' => 'Manufacturing',
            'description' => 'A test company',
            'country' => 'FR',
            'estimated_size' => '11-50',
        ], $overrides);
    }

    private function leadRow(array $overrides = []): array
    {
        return array_merge([
            'row_number' => 1,
            'email' => 'jane@acme.test',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'position' => 'CEO',
            'phone' => '0102030405',
            'verification_status' => 'valid',
            'verification_date' => '2026-08-01T00:00:00Z',
            'company_name' => 'Acme Corp',
            'domain_host' => 'acme.test',
            'domain_registrable' => 'acme.test',
            'sector' => 'Manufacturing',
            'estimated_size' => '11-50',
            'country' => 'FR',
        ], $overrides);
    }

    public function test_it_creates_a_new_company_and_attaches_its_contact(): void
    {
        $stats = app(HunterCsvImportService::class)->commit([$this->companyRow()], [$this->leadRow()]);

        $this->assertSame(1, $stats['companies_created']);
        $this->assertSame(0, $stats['companies_merged']);
        $this->assertSame(1, $stats['contacts_created']);
        $this->assertDatabaseHas('companies', [
            'domain' => 'acme.test',
            'name' => 'Acme Corp',
            'source' => 'hunter',
            'relationship' => 'prospect',
            'qualification_status' => 'pending',
        ]);
        $this->assertDatabaseHas('contacts', [
            'email' => 'jane@acme.test',
            'source' => 'hunter',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
        ]);
    }

    public function test_it_only_fills_blank_fields_on_merge_and_never_touches_lifecycle_fields(): void
    {
        $criteria = ProspectCriteria::query()->create(['name' => 'Fixture criteria']);
        $existing = Company::factory()->create([
            'domain' => 'acme.test',
            'registrable_domain' => 'acme.test',
            'name' => 'Acme Existing',
            'sector' => null,
            'description' => null,
            'country' => null,
            'estimated_size' => null,
            'relationship' => 'client',
            'source' => 'zoho',
            'qualification_status' => 'qualified',
            'criteria_id' => $criteria->id,
        ]);

        $stats = app(HunterCsvImportService::class)->commit([$this->companyRow()], []);

        $this->assertSame(0, $stats['companies_created']);
        $this->assertSame(1, $stats['companies_merged']);

        $existing->refresh();
        $this->assertSame('Acme Existing', $existing->name);
        $this->assertNotNull($existing->sector);
        $this->assertSame('A test company', $existing->description);
        $this->assertSame('FR', $existing->country);
        $this->assertSame('11-50', $existing->estimated_size);
        // Never touched by the importer:
        $this->assertSame('client', $existing->relationship);
        $this->assertSame('zoho', $existing->source);
        $this->assertSame('qualified', $existing->qualification_status);
        $this->assertSame($criteria->id, $existing->criteria_id);
    }

    public function test_it_creates_a_stub_company_for_leads_with_no_matching_company_and_derives_name_from_domain_when_blank(): void
    {
        $stats = app(HunterCsvImportService::class)->commit([], [
            $this->leadRow([
                'domain_host' => 'stubco.test',
                'domain_registrable' => 'stubco.test',
                'email' => 'lead@stubco.test',
                'company_name' => null,
            ]),
        ]);

        $this->assertSame(1, $stats['stub_companies_created']);
        $this->assertSame(1, $stats['contacts_created']);
        $this->assertDatabaseHas('companies', [
            'domain' => 'stubco.test',
            'name' => 'Stubco',
            'source' => 'hunter',
        ]);
    }

    public function test_it_skips_tombstoned_contacts(): void
    {
        $company = Company::factory()->create(['domain' => 'tomb.test', 'registrable_domain' => 'tomb.test']);
        Contact::factory()->for($company)->create(['email' => 'ghost@tomb.test'])->delete();

        $stats = app(HunterCsvImportService::class)->commit([], [
            $this->leadRow(['domain_host' => 'tomb.test', 'domain_registrable' => 'tomb.test', 'email' => 'ghost@tomb.test']),
        ]);

        $this->assertSame(0, $stats['contacts_created']);
        $this->assertSame(1, $stats['contacts_skipped']['tombstoned'] ?? 0);
    }

    public function test_it_skips_leads_whose_email_is_globally_owned_by_another_company(): void
    {
        $owner = Company::factory()->create(['domain' => 'owner.test', 'registrable_domain' => 'owner.test']);
        Contact::factory()->for($owner)->create(['email' => 'shared@owned.test']);

        $stats = app(HunterCsvImportService::class)->commit([], [
            $this->leadRow(['domain_host' => 'b.test', 'domain_registrable' => 'b.test', 'email' => 'shared@owned.test', 'company_name' => 'B Co']),
        ]);

        $this->assertSame(0, $stats['contacts_created']);
        $this->assertSame(1, $stats['contacts_skipped']['owned_by_another_company'] ?? 0);
    }

    public function test_it_skips_leads_with_invalid_verification_status_and_creates_no_stub(): void
    {
        $stats = app(HunterCsvImportService::class)->commit([], [
            $this->leadRow(['domain_host' => 'inv.test', 'domain_registrable' => 'inv.test', 'email' => 'bad@inv.test', 'verification_status' => 'invalid']),
        ]);

        $this->assertSame(0, $stats['contacts_created']);
        $this->assertSame(0, $stats['stub_companies_created']);
        $this->assertSame(1, $stats['contacts_skipped']['invalid_verification'] ?? 0);
        $this->assertDatabaseMissing('companies', ['domain' => 'inv.test']);
    }

    public function test_it_skips_platform_domains_during_parsing(): void
    {
        $importer = app(HunterCsvImportService::class);
        $csv = "Company,Domain\nSocial Corp,https://www.linkedin.com/company/social-corp\n";
        $file = UploadedFile::fake()->createWithContent('companies.csv', $csv);

        $result = $importer->parseCompanies($file);

        $this->assertSame([], $result['rows']);
        $this->assertSame('platform_domain', $result['errors'][0]['code']);
    }

    public function test_it_flags_registrable_domain_conflicts_and_skips_them(): void
    {
        Company::factory()->create(['domain' => 'fr.multi.test', 'registrable_domain' => 'multi.test']);
        Company::factory()->create(['domain' => 'us.multi.test', 'registrable_domain' => 'multi.test']);

        $stats = app(HunterCsvImportService::class)->commit(
            [$this->companyRow(['domain_host' => 'multi.test', 'domain_registrable' => 'multi.test'])],
            [$this->leadRow(['domain_host' => 'multi.test', 'domain_registrable' => 'multi.test', 'email' => 'x@multi.test'])],
        );

        $this->assertSame(0, $stats['companies_created']);
        $this->assertSame(0, $stats['companies_merged']);
        $this->assertSame(1, $stats['companies_skipped_conflict']);
        $this->assertSame(0, $stats['stub_companies_created']);
        $this->assertSame(0, $stats['contacts_created']);
        $this->assertSame(1, $stats['contacts_skipped']['registrable_domain_conflict'] ?? 0);
    }

    public function test_preview_and_commit_agree_when_a_candidates_registrable_equals_another_candidates_exact_host(): void
    {
        // acme.test already exists. The batch also carries a subdomain whose
        // registrable is acme.test — resolveManyReadOnly() used to diff the
        // registrables set against the exact-matched *host* keys (same value
        // space by coincidence: both are the string "acme.test"), which
        // wrongly emptied the fallback query and made this row resolve to
        // "create" in preview while commit() (which re-resolves per row)
        // correctly resolved it to "merge".
        Company::factory()->create(['domain' => 'acme.test', 'registrable_domain' => 'acme.test']);

        $companyRows = [
            $this->companyRow(['domain_host' => 'acme.test', 'domain_registrable' => 'acme.test']),
            $this->companyRow([
                'row_number' => 2,
                'company_name' => 'Acme Careers',
                'domain_host' => 'careers.acme.test',
                'domain_registrable' => 'acme.test',
            ]),
        ];

        $summary = app(HunterCsvImportService::class)->preview($companyRows, []);

        $this->assertSame(0, $summary['companies']['create']);
        $this->assertSame(2, $summary['companies']['merge']);

        $stats = app(HunterCsvImportService::class)->commit($companyRows, []);

        $this->assertSame(0, $stats['companies_created']);
        $this->assertSame(2, $stats['companies_merged']);
    }

    public function test_verification_evidence_suppresses_the_paid_verify_job_but_a_blank_status_still_queues_it(): void
    {
        Bus::fake();
        config()->set('prospecting.email_verification_enabled_default', true);

        app(HunterCsvImportService::class)->commit([], [
            $this->leadRow(['domain_host' => 'evid.test', 'domain_registrable' => 'evid.test', 'email' => 'valid@evid.test', 'verification_status' => 'valid']),
            $this->leadRow(['domain_host' => 'evid2.test', 'domain_registrable' => 'evid2.test', 'email' => 'pending@evid2.test', 'verification_status' => null]),
        ]);

        $verifiedId = Contact::where('email', 'valid@evid.test')->value('id');
        $pendingId = Contact::where('email', 'pending@evid2.test')->value('id');

        Bus::assertNotDispatched(StartContactEmailVerificationJob::class, fn ($job): bool => $job->contactId === $verifiedId);
        Bus::assertDispatched(StartContactEmailVerificationJob::class, fn ($job): bool => $job->contactId === $pendingId);
    }

    /**
     * Regression net for the real Hunter "companies" export header — the
     * exact column list Hunter ships (not our own alias vocabulary). Also
     * pins Morocco → MA through the shared CountryResolver (grill BLOCK #3).
     */
    public function test_it_parses_the_real_hunter_companies_export_header(): void
    {
        $csv = "Company Name,Domain,City,State,Postal Code,Country,Industry,Headcount,Company Type,Tags,Linkedin,Description\n"
            ."Acme Import Test,acme-import-test.com,Casablanca,,20000,Morocco,Manufacturing,11-50,Private,tag1,https://linkedin.com/company/acme,A test company\n";
        $file = UploadedFile::fake()->createWithContent('companies-export.csv', $csv);

        $result = app(HunterCsvImportService::class)->parseCompanies($file);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('acme-import-test.com', $result['rows'][0]['domain_host']);
        $this->assertSame('MA', $result['rows'][0]['country']);
    }

    /**
     * Regression net for the real Hunter "leads" export header, verbatim.
     * Also pins the company-country fix (grill BLOCK #4): "Company Country"
     * (Morocco) must resolve the company's country — the contact's own
     * personal "Country" column (Saudi Arabia) must never leak into it.
     */
    public function test_it_parses_the_real_hunter_leads_export_header(): void
    {
        $header = "First name,Last name,Full name,Job title,Company,Industry,Department,Company size,Company Type,Email address,Confidence score,Company Country,Website,LinkedIn URL,Phone number,Twitter,Notes,Source,Sending status,Date of creation,Date of last update,Date of last activity,Date of last contact,Owner,City,State,Postal code,Country,Verification status,Verification date,Tags\n";
        $row = "Jane,Doe,Jane Doe,CEO,Acme Import Test,Manufacturing,Sales,11-50,Private,jane@acme-import-test.com,95,Morocco,acme-import-test.com,https://linkedin.com/in/jane,0102030405,@jane,Some note,hunter,Sent,2026-01-01,2026-01-02,2026-01-03,2026-01-04,Owner Name,Casablanca,,20000,Saudi Arabia,valid,2026-08-01,tag1\n";
        $file = UploadedFile::fake()->createWithContent('leads-export.csv', $header.$row);

        $result = app(HunterCsvImportService::class)->parseLeads($file);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['rows']);
        $lead = $result['rows'][0];
        $this->assertSame('jane@acme-import-test.com', $lead['email']);
        $this->assertSame('acme-import-test.com', $lead['domain_host']);
        $this->assertSame('MA', $lead['country']);
    }

    public function test_second_run_of_the_same_rows_is_a_pure_no_op(): void
    {
        $companyRows = [$this->companyRow()];
        $leadRows = [$this->leadRow()];
        $importer = app(HunterCsvImportService::class);

        $importer->commit($companyRows, $leadRows);
        $second = $importer->commit($companyRows, $leadRows);

        $this->assertSame(0, $second['companies_created']);
        $this->assertSame(1, $second['companies_merged']);
        $this->assertSame(0, $second['contacts_created']);
        $this->assertSame(1, Company::query()->where('domain', 'acme.test')->count());
        $this->assertSame(1, Contact::query()->where('email', 'jane@acme.test')->count());
    }
}
