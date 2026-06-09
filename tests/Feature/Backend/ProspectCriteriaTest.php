<?php

namespace Tests\Feature\Backend;

use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProspectCriteriaTest — CRUD HTTP-layer tests for the prospect criteria module.
 *
 * Mirrors CompaniesTest pattern: superadmin via factory, ACL seeded manually.
 * Controller uses Crudable + Datatableable traits (same as all backend modules).
 */
class ProspectCriteriaTest extends TestCase
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

    /**
     * Prospect criteria index page renders 200 for superadmin.
     */
    public function test_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria');

        $response->assertStatus(200);
    }

    /**
     * Prospect criteria create form renders 200 for superadmin.
     */
    public function test_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/prospect_criteria/create');

        $response->assertStatus(200);
    }

    /**
     * DataTable AJAX endpoint returns JSON with a 'data' key.
     */
    public function test_datatable_json(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/prospect_criteria'
                . '?draw=1&start=0&length=10'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    /**
     * Storing a criteria via POST with comma-separated sectors string creates the DB
     * record and casts the comma string to a proper PHP array.
     *
     * The controller's beforeSave() splits comma strings → arrays before persistence.
     */
    public function test_store_creates_criteria_with_array_cast(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/prospect_criteria', [
                'name'        => 'Test Critère Transport',
                'sectors'     => 'transport,logistique',
                'countries'   => 'France',
                'daily_limit' => 10,
                'is_active'   => 1,
            ]);

        $response->assertStatus(200);

        // Verify the record exists in the DB
        $this->assertDatabaseHas('prospect_criteria', ['name' => 'Test Critère Transport']);

        // Load the freshly created model and confirm the cast is an array
        $criteria = ProspectCriteria::where('name', 'Test Critère Transport')->firstOrFail();

        $this->assertIsArray($criteria->sectors, 'sectors must be cast to an array by the model');
        $this->assertSame(['transport', 'logistique'], $criteria->sectors);

        $this->assertIsArray($criteria->countries, 'countries must be cast to an array by the model');
        $this->assertContains('France', $criteria->countries);
    }
}
