<?php

namespace Tests\Feature\Backend;

use App\Jobs\Zoho\RunZohoBulkBackfillJob;
use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Models\User;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncLog;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ZohoV2BulkDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_backfill_reaches_the_same_bulk_dispatch_policy_as_the_cli(): void
    {
        config()->set('zoho-v2.bulk.verified_modules', ['accounts']);
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('admin');
        Bus::fake();

        $this->actingAs($admin)
            ->post('/admin/zoho/v2/backfill', ['module' => 'accounts'])
            ->assertRedirect()
            ->assertSessionHas('success');

        Bus::assertDispatched(RunZohoBulkBackfillJob::class, fn (RunZohoBulkBackfillJob $job): bool => $job->module === 'accounts');
        Bus::assertNotDispatched(RunZohoModuleSyncJob::class);
    }

    public function test_manual_backfill_uses_standard_sync_when_the_module_is_not_verified_for_bulk(): void
    {
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('admin');
        Bus::fake();

        $this->actingAs($admin)
            ->post('/admin/zoho/v2/backfill', ['module' => 'accounts'])
            ->assertRedirect();

        Bus::assertDispatched(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->module === 'accounts'
            && $job->mode === 'backfill');
        Bus::assertNotDispatched(RunZohoBulkBackfillJob::class);
    }

    public function test_manual_dispatch_failure_terminalizes_every_undispatched_module_without_leaking_the_exception(): void
    {
        config()->set('queue.connections.zoho.driver', 'unsupported-zoho-test-driver');
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post('/admin/zoho/v2/sync')
            ->assertRedirect()
            ->assertSessionHas('error', 'La synchronisation V2 n\'a pas pu être planifiée. Consultez les identifiants de corrélation.');

        $batch = ZohoSyncBatch::query()->latest('id')->firstOrFail();
        $this->assertSame('error', $batch->status);
        $this->assertNotNull($batch->completed_at);
        $this->assertSame(
            count((array) $batch->modules),
            ZohoSyncLog::query()
                ->where('sync_batch_id', $batch->id)
                ->where('status', 'error')
                ->count(),
        );
        $this->assertDatabaseMissing('zoho_sync_logs', [
            'sync_batch_id' => $batch->id,
            'error' => 'unsupported-zoho-test-driver',
        ]);
    }
}
