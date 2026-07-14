<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompaniesTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase does NOT run seeders; seed ACL manually.
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->superadmin->assignRole('superadmin');
    }

    private function makeCompany(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'                 => 'Company ' . uniqid(),
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
            'is_active'            => true,
        ], $overrides));
    }

    private function dataTableParams(array $params = []): array
    {
        return array_replace_recursive([
            'draw'    => 1,
            'start'   => 0,
            'length'  => 10,
            'columns' => [
                ['data' => 'id', 'name' => 'id', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'name', 'name' => 'name', 'searchable' => 'true', 'orderable' => 'true'],
            ],
            'order'   => [['column' => 0, 'dir' => 'asc']],
        ], $params);
    }

    private function getDataTableJson(string $path = '/admin/companies', array $params = [])
    {
        return $this->actingAs($this->superadmin)
            ->call('GET', $path, $this->dataTableParams($params), [], [], [
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                'HTTP_ACCEPT'           => 'application/json',
            ]);
    }

    /**
     * Companies index page renders for an authenticated superadmin.
     */
    public function test_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/companies');

        $response->assertStatus(200);
    }

    /**
     * Company create form renders for an authenticated superadmin.
     */
    public function test_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/companies/create');

        $response->assertStatus(200);
    }

    /**
     * DataTable AJAX endpoint returns a JSON payload that contains 'data'.
     *
     * Yajra requires DataTables wire parameters. We send the minimal set:
     *   draw, start, length  + one column definition to satisfy Yajra's column resolver.
     * The Accept: application/json + X-Requested-With headers make Yajra return JSON
     * instead of rendering the full HTML view.
     */
    public function test_datatable_json(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/companies'
                . '?draw=1&start=0&length=10'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    /**
     * Updating a company persists the new values and returns HTTP 200.
     */
    public function test_update_company(): void
    {
        $c = Company::create([
            'name'                 => 'Old Name SARL',
            'relationship'         => 'prospect',
            'source'               => 'manual',
            'qualification_status' => 'pending',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/companies/' . $c->id, [
                'name'                 => 'ACME Updated',
                'relationship'         => 'client',
                'source'               => 'manual',
                'qualification_status' => 'qualified',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('companies', [
            'id'           => $c->id,
            'name'         => 'ACME Updated',
            'relationship' => 'client',
        ]);
    }

    /**
     * Storing a company creates a DB record and returns HTTP 200.
     *
     * The Crudable::store() trait returns JSON {message, model, redirect} on success.
     */
    public function test_store_creates_company(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/companies', [
                'name'                 => 'ACME SARL',
                'relationship'         => 'prospect',
                'source'               => 'manual',
                'qualification_status' => 'pending',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('companies', ['name' => 'ACME SARL']);
    }

    public function test_rejecting_a_company_hides_it_from_the_default_datatable(): void
    {
        $company = $this->makeCompany();

        $response = $this->actingAs($this->superadmin)
            ->put('/admin/companies/' . $company->id, [
                'name'                 => $company->name,
                'relationship'         => 'prospect',
                'source'               => 'manual',
                'qualification_status' => 'rejected',
            ]);

        $response->assertOk()
            ->assertJsonPath('redirect', route('admin.companies.archive'));
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'qualification_status' => 'rejected']);
        $this->assertNull(Company::find($company->id));

        $this->getDataTableJson()
            ->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data'])
            ->assertJsonCount(0, 'data');
    }

    public function test_archive_datatable_lists_and_filters_rejected_companies(): void
    {
        $matching = $this->makeCompany([
            'name'                 => 'Archive manual match',
            'source'               => 'manual',
            'qualification_status' => 'rejected',
        ]);
        $other = $this->makeCompany([
            'name'                 => 'Archive discovered other',
            'source'               => 'discovered',
            'qualification_status' => 'rejected',
        ]);
        $this->makeCompany(['name' => 'Active manual company']);

        $this->actingAs($this->superadmin)
            ->get('/admin/companies/archive')
            ->assertOk()
            ->assertSee('Rejetées / Archives');

        $response = $this->getDataTableJson('/admin/companies/archive', ['source' => 'manual']);

        $response->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data'])
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonCount(1, 'data')
            ->assertSee($matching->name)
            ->assertDontSee($other->name);
    }

    public function test_archive_datatable_searches_rejected_companies(): void
    {
        $matching = $this->makeCompany([
            'name'                 => 'Archive search target',
            'qualification_status' => 'rejected',
        ]);
        $other = $this->makeCompany([
            'name'                 => 'Archive search other',
            'qualification_status' => 'rejected',
        ]);

        $response = $this->getDataTableJson('/admin/companies/archive', ['search' => ['value' => 'target']]);

        $response->assertOk()
            ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data'])
            ->assertJsonCount(1, 'data')
            ->assertSee($matching->name)
            ->assertDontSee($other->name);
    }

    public function test_edit_form_explains_that_rejected_companies_move_to_archive(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->superadmin)
            ->get('/admin/companies/' . $company->id . '/edit')
            ->assertOk()
            ->assertSee('une entreprise rejetée quitte la liste active', false)
            ->assertSee('Rejetées / Archives', false);
    }
}
