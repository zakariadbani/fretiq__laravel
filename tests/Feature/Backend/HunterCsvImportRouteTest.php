<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * HTTP-level coverage for the three Hunter CSV import routes
 * (importForm/importPreview/importStore). tests: absent on this surface
 * (permission gating, file upload, cache-bound payload handoff, bulk
 * writes) is exactly why the $errors view-key collision (500 on every
 * render) and the header_required/country_invalid parse bugs shipped
 * undetected — the pre-existing HunterCsvImportTest only ever called the
 * service directly, never a route (grill BLOCK #6).
 */
class HunterCsvImportRouteTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_IMPORT_PERMISSIONS = ['create companies', 'edit companies', 'create contacts', 'edit contacts'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo('backend.access', 'view companies', ...$permissions);

        return $user;
    }

    public function test_import_form_renders_ok_for_an_authorized_user(): void
    {
        $user = $this->userWithPermissions(self::ALL_IMPORT_PERMISSIONS);

        $this->actingAs($user)
            ->get(route('admin.companies.import_form'))
            ->assertOk()
            ->assertSee('Importer des entreprises et contacts');
    }

    public function test_import_form_403s_without_create_companies_or_create_contacts(): void
    {
        $missingCreateCompanies = $this->userWithPermissions(['edit companies', 'create contacts', 'edit contacts']);
        $missingCreateContacts = $this->userWithPermissions(['create companies', 'edit companies', 'edit contacts']);

        $this->actingAs($missingCreateCompanies)->get(route('admin.companies.import_form'))->assertForbidden();
        $this->actingAs($missingCreateContacts)->get(route('admin.companies.import_form'))->assertForbidden();
    }

    public function test_preview_403s_when_missing_any_of_the_four_gating_permissions(): void
    {
        foreach (self::ALL_IMPORT_PERMISSIONS as $missing) {
            $permissions = array_values(array_diff(self::ALL_IMPORT_PERMISSIONS, [$missing]));
            $user = $this->userWithPermissions($permissions);

            $this->actingAs($user)
                ->post(route('admin.companies.import_preview'), [
                    'companies_csv' => UploadedFile::fake()->createWithContent('companies.csv', "Company,Domain\nAcme,acme-route-test.com\n"),
                ])
                ->assertForbidden();
        }
    }

    public function test_preview_renders_summary_from_real_hunter_headers_without_persisting(): void
    {
        $user = $this->userWithPermissions(self::ALL_IMPORT_PERMISSIONS);

        $companiesCsv = "Company Name,Domain,City,State,Postal Code,Country,Industry,Headcount,Company Type,Tags,Linkedin,Description\n"
            ."Acme Route Test,acme-route-test.com,Casablanca,,20000,Morocco,Manufacturing,11-50,Private,tag1,https://linkedin.com/company/acme,A test company\n";
        $leadsCsv = "First name,Last name,Full name,Job title,Company,Industry,Department,Company size,Company Type,Email address,Confidence score,Company Country,Website,LinkedIn URL,Phone number,Twitter,Notes,Source,Sending status,Date of creation,Date of last update,Date of last activity,Date of last contact,Owner,City,State,Postal code,Country,Verification status,Verification date,Tags\n"
            ."Jane,Doe,Jane Doe,CEO,Acme Route Test,Manufacturing,Sales,11-50,Private,jane@acme-route-test.com,95,Morocco,acme-route-test.com,https://linkedin.com/in/jane,0102030405,@jane,Some note,hunter,Sent,2026-01-01,2026-01-02,2026-01-03,2026-01-04,Owner Name,Casablanca,,20000,Saudi Arabia,valid,2026-08-01,tag1\n";

        $response = $this->actingAs($user)->post(route('admin.companies.import_preview'), [
            'companies_csv' => UploadedFile::fake()->createWithContent('companies.csv', $companiesCsv),
            'contacts_csv' => UploadedFile::fake()->createWithContent('leads.csv', $leadsCsv),
        ]);

        $response->assertOk()
            ->assertViewHas('summary', function (array $summary): bool {
                return $summary['companies']['create'] === 1
                    && $summary['companies']['registrable_domain_conflict'] === 0
                    && $summary['leads']['contacts_total'] === 1;
            })
            ->assertViewHas('parseErrors', [])
            ->assertViewHas('uuid');

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_store_commits_the_previewed_rows_and_forgets_the_cache_token(): void
    {
        $user = $this->userWithPermissions(self::ALL_IMPORT_PERMISSIONS);

        $companiesCsv = "Company Name,Domain,Country\nAcme Store Test,acme-store-test.com,Morocco\n";
        $preview = $this->actingAs($user)->post(route('admin.companies.import_preview'), [
            'companies_csv' => UploadedFile::fake()->createWithContent('companies.csv', $companiesCsv),
        ])->assertOk();

        $uuid = $preview->viewData('uuid');
        $this->assertIsString($uuid);

        $this->actingAs($user)
            ->post(route('admin.companies.import_store'), ['import_uuid' => $uuid])
            ->assertRedirect(route('admin.companies.index'));

        $this->assertDatabaseHas('companies', [
            'domain' => 'acme-store-test.com',
            'name' => 'Acme Store Test',
            'source' => 'hunter',
            'country' => 'MA',
        ]);
        $this->assertNull(Cache::get('hunter_import:'.$uuid));
    }

    public function test_store_without_a_valid_uuid_redirects_with_an_error_and_writes_nothing(): void
    {
        $user = $this->userWithPermissions(self::ALL_IMPORT_PERMISSIONS);

        $this->actingAs($user)
            ->from(route('admin.companies.import_form'))
            ->post(route('admin.companies.import_store'), ['import_uuid' => 'not-a-real-uuid'])
            ->assertRedirect(route('admin.companies.import_form'))
            ->assertSessionHasErrors('import_uuid');

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_store_rejects_another_users_uuid(): void
    {
        $owner = $this->userWithPermissions(self::ALL_IMPORT_PERMISSIONS);
        $other = $this->userWithPermissions(self::ALL_IMPORT_PERMISSIONS);

        $preview = $this->actingAs($owner)->post(route('admin.companies.import_preview'), [
            'companies_csv' => UploadedFile::fake()->createWithContent('companies.csv', "Company Name,Domain\nOwned Co,owned-co-test.com\n"),
        ])->assertOk();
        $uuid = $preview->viewData('uuid');

        $this->actingAs($other)
            ->from(route('admin.companies.import_form'))
            ->post(route('admin.companies.import_store'), ['import_uuid' => $uuid])
            ->assertRedirect(route('admin.companies.import_form'))
            ->assertSessionHasErrors('import_uuid');

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_route_hit_without_backend_access_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $this->actingAs($user)->get(route('admin.companies.import_form'))->assertForbidden();
    }
}
