<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    use RefreshDatabase;

    private User    $superadmin;
    private Company $company;

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

        // There is no CompanyFactory — create directly via Eloquent.
        $this->company = Company::create([
            'name'   => 'Test Société',
            'source' => 'manual',
        ]);
    }

    /**
     * Contacts index page renders for an authenticated superadmin.
     */
    public function test_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/contacts');

        $response->assertStatus(200);
    }

    /**
     * Contact create form renders for an authenticated superadmin.
     */
    public function test_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/contacts/create');

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
                '/admin/contacts'
                . '?draw=1&start=0&length=10'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    /**
     * Storing a contact creates a DB record and returns HTTP 200.
     *
     * The Crudable::store() trait returns JSON {message, model, redirect} on success.
     * There is no ContactFactory — company_id is obtained from the Company created in setUp().
     */
    public function test_store_creates_contact(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/contacts', [
                'company_id'  => $this->company->id,
                'name'        => 'Jean Dupont',
                'email'       => 'jean@acme.test',
                'status'      => 'new',
                'source'      => 'manual',
                'legal_basis' => 'relationship',
                'email_kind'  => 'role',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('contacts', ['email' => 'jean@acme.test']);
    }
}
