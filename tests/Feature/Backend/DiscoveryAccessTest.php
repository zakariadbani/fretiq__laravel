<?php

namespace Tests\Feature\Backend;

use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\ProspectCriteria;
use App\Models\User;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DiscoveryAccessTest — HTTP-layer permission enforcement for discovery routes.
 *
 * ACL matrix (from PermissionsSeeder):
 *   commercial → has 'run discovery' + view/create/edit prospect_criteria → can trigger, can NOT delete
 *   superadmin → all permissions                                          → can delete
 *
 * Queue::fake() is used here so the job is captured without being executed.
 * This is the ONLY test file that uses Queue::fake().
 */
class DiscoveryAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

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
     * Commercial role has 'run discovery' → POST discover must succeed (not 403)
     * and must dispatch RunDiscoveryPipelineJob onto the queue.
     */
    public function test_commercial_can_trigger_discovery(): void
    {
        Queue::fake();

        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Commercial Test',
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->commercial)
            ->post("/admin/prospect_criteria/{$criteria->id}/discover");

        // Must NOT be 403 — commercial has 'run discovery' permission
        $this->assertNotEquals(
            403,
            $response->status(),
            'Commercial role must NOT get 403 on the discover endpoint (has run discovery permission)'
        );

        // The controller must have dispatched the pipeline job
        Queue::assertPushed(RunDiscoveryPipelineJob::class);
    }

    /**
     * Commercial role does NOT have 'delete prospect_criteria' → DELETE must return 403.
     */
    public function test_commercial_cannot_delete_criteria(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère à ne pas supprimer',
            'sectors'     => ['logistique'],
            'countries'   => ['France'],
            'daily_limit' => 5,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->commercial)
            ->delete("/admin/prospect_criteria/{$criteria->id}");

        $response->assertStatus(403);
    }

    /**
     * Superadmin has 'delete prospect_criteria' → DELETE must succeed (200 or redirect).
     * Positive control to ensure the delete route works for privileged users.
     */
    public function test_superadmin_can_delete_criteria(): void
    {
        $criteria = ProspectCriteria::create([
            'name'        => 'Critère Superadmin Suppression',
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 20,
            'is_active'   => true,
        ]);

        $response = $this->actingAs($this->superadmin)
            ->delete("/admin/prospect_criteria/{$criteria->id}");

        // Crudable::delete() returns JSON {message, redirect} on success → 200
        // or may redirect → 302. Either is acceptable.
        $this->assertContains(
            $response->status(),
            [200, 302],
            "Superadmin DELETE must succeed (got {$response->status()})"
        );
    }
}
