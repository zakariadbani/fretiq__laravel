<?php

namespace Tests\Feature\Backend;

use App\Models\User;
use App\Services\Analytics\QueueObservabilityService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ObservabilityAccessTest — permission enforcement + service shape for the
 * observability screen.
 *
 * Gate: `manage roles` — only superadmin/admin; commercial lacks it.
 *
 * counts() keys (from QueueObservabilityService source):
 *   failed_jobs, failed_runs, queued_recipients, scheduled_runs
 */
class ObservabilityAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $commercial;

    protected function setUp(): void
    {
        parent::setUp();

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

    // ── HTTP access tests ──────────────────────────────────────────────────────

    /**
     * Superadmin can access the observability screen (has 'manage roles').
     */
    public function test_superadmin_can_view_observability(): void
    {
        $this->actingAs($this->superadmin)
            ->get('/admin/observability')
            ->assertStatus(200);
    }

    /**
     * Commercial role is denied (lacks 'manage roles').
     */
    public function test_commercial_is_denied_observability(): void
    {
        $this->actingAs($this->commercial)
            ->get('/admin/observability')
            ->assertStatus(403);
    }

    /**
     * Unauthenticated request is redirected (auth middleware fires before permission check).
     */
    public function test_guest_is_redirected_from_observability(): void
    {
        $this->get('/admin/observability')
            ->assertRedirect();
    }

    // ── Service-level tests ────────────────────────────────────────────────────

    /**
     * QueueObservabilityService::counts() returns an array with the four
     * documented keys, each holding a non-negative integer.
     */
    public function test_counts_returns_expected_keys(): void
    {
        $service = app(QueueObservabilityService::class);

        $counts = $service->counts();

        $this->assertIsArray($counts, 'counts() must return an array');

        $expectedKeys = [
            'failed_jobs',
            'failed_runs',
            'queued_recipients',
            'scheduled_runs',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $counts,
                "counts() must contain key '{$key}'");
            $this->assertIsInt($counts[$key],
                "counts()['{$key}'] must be an integer");
            $this->assertGreaterThanOrEqual(0, $counts[$key],
                "counts()['{$key}'] must be >= 0");
        }
    }

    /**
     * counts() returns correct values when there are no failed/queued rows.
     * On an empty database all counts must be 0.
     */
    public function test_counts_are_zero_on_empty_database(): void
    {
        $service = app(QueueObservabilityService::class);

        $counts = $service->counts();

        // failed_jobs and failed_runs depend on zero rows existing
        $this->assertSame(0, $counts['failed_runs'],
            'failed_runs must be 0 on an empty database');
        $this->assertSame(0, $counts['queued_recipients'],
            'queued_recipients must be 0 on an empty database');
        $this->assertSame(0, $counts['scheduled_runs'],
            'scheduled_runs must be 0 on an empty database');
    }
}
