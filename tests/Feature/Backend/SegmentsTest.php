<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SegmentsTest — CRUD HTTP surface for the /admin/segments resource.
 *
 * Pattern mirrors CompaniesTest / PermissionsTest.
 * The Crudable::store() trait returns JSON {message, model, redirect} on success (HTTP 200).
 * The SegmentController::beforeSave() hook json_decodes the textarea filter string to an array
 * before passing it to the model validator (nullable|array rule).
 */
class SegmentsTest extends TestCase
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
     * Segments index page renders for an authenticated superadmin.
     */
    public function test_index_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/segments');

        $response->assertStatus(200);
    }

    /**
     * Segment create form renders for an authenticated superadmin.
     */
    public function test_create_renders(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/admin/segments/create');

        $response->assertStatus(200);
    }

    /**
     * DataTable AJAX endpoint returns a JSON payload that contains 'data'.
     *
     * Yajra requires DataTables wire parameters. We send the minimal set
     * identical to CompaniesTest to satisfy Yajra's column resolver.
     */
    public function test_datatable_json(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(
                '/admin/segments'
                . '?draw=1&start=0&length=10'
                . '&columns[0][data]=id&columns[0][name]=id'
                . '&order[0][column]=0&order[0][dir]=asc',
                ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']
            );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
    }

    /**
     * Storing a segment creates a DB record and returns HTTP 200.
     *
     * The filter is posted as a JSON string; SegmentController::beforeSave()
     * json_decodes it to an array before the Eloquent cast and validator see it.
     */
    public function test_store_creates_segment(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->post('/admin/segments', [
                'name'   => 'Clients France',
                'scope'  => 'client',
                'filter' => '{"country":"FR"}',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('segments', [
            'name'  => 'Clients France',
            'scope' => 'client',
        ]);
    }
}
