<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    private User    $superadmin;
    private User    $commercial;

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

        $this->commercial = User::factory()->create([
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);
        $this->commercial->assignRole('commercial');
    }

    /**
     * Commercial role CANNOT delete a company (Spatie middleware blocks at HTTP layer).
     *
     * CompanyController applies permission:delete companies to the delete action.
     * Commercial only has view/create/edit; it never receives delete companies.
     * The middleware aborts with 403 before the controller method runs.
     */
    public function test_commercial_cannot_delete_company(): void
    {
        // There is no CompanyFactory — create directly.
        $company = Company::create([
            'name'   => 'Société à supprimer',
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->commercial)
            ->delete('/admin/companies/' . $company->id);

        $response->assertStatus(403);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Société à supprimer']);
    }

    /**
     * Commercial role CANNOT access user management.
     *
     * /admin/users is behind auth + verified + permission:backend.access (group gate)
     * + permission:view users (per-action gate in UserController constructor).
     * Commercial has backend.access but NOT view users — returns 403.
     */
    public function test_commercial_cannot_view_users(): void
    {
        // Ability assertion
        $this->assertFalse(
            $this->commercial->can('view users'),
            'commercial role must not have the "view users" permission'
        );

        // HTTP assertion: the per-action middleware returns 403
        $response = $this->actingAs($this->commercial)
            ->get('/admin/users');

        $response->assertStatus(403);
    }

    /**
     * Admin (superadmin) can load the /admin/users index without error.
     *
     * This exercises the full server-render path including GlobalDataTable::html()
     * which calls $model->getName() — previously threw if User lacked that method.
     */
    public function test_admin_can_load_users_index(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get(route('admin.users.index'));

        $response->assertOk();
    }

    /**
     * DataTable ajax-draw returns JSON with a 'data' key.
     *
     * Simulates the XHR Yajra sends after the page renders the table shell.
     */
    public function test_admin_users_datatable_ajax_draw_returns_json(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept'           => 'application/json',
            ])
            ->get(route('admin.users.index', [
                'draw'                    => 1,
                'start'                   => 0,
                'length'                  => 10,
                'columns[0][data]'        => 'user',
                'columns[0][searchable]'  => 'true',
                'columns[0][orderable]'   => 'true',
                'search[value]'           => '',
                'search[regex]'           => 'false',
                'order[0][column]'        => 0,
                'order[0][dir]'           => 'asc',
            ]));

        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }

    /**
     * Superadmin CAN delete a company (positive control).
     *
     * The Datatableable::delete() trait returns JSON {success: true} with HTTP 200.
     */
    public function test_superadmin_can_delete_company(): void
    {
        $company = Company::create([
            'name'   => 'Société superadmin delete',
            'source' => 'manual',
        ]);

        $response = $this->actingAs($this->superadmin)
            ->delete('/admin/companies/' . $company->id);

        $response->assertStatus(200);
    }
}
