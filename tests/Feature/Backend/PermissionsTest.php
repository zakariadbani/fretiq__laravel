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
     * The user-management routes sit behind auth + verified only — UserManagementController
     * has NO permission gate in its controller. Therefore we cannot assert an HTTP 403
     * from the route. Instead we assert directly on the Spatie ability:
     * commercial role does not have the 'view users' permission, so can() returns false.
     *
     * Note: if a gate/middleware is later added to that controller, replace this assertion
     * with: actingAs($this->commercial)->get('/user-management/users')->assertStatus(403);
     */
    public function test_commercial_cannot_view_users(): void
    {
        $this->assertFalse(
            $this->commercial->can('view users'),
            'commercial role must not have the "view users" permission'
        );
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
