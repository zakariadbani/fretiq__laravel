<?php

namespace Tests\Feature\Backend;

use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Jobs\Zoho\RunZohoPostReconciliationJob;
use App\Models\User;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Carbon\CarbonInterface;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ZohoV2ManualResumeControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_first_sync_all_click_dispatches_once_and_an_active_second_click_is_a_no_op(): void
    {
        Queue::fake();
        $modules = $this->syncAllModules();

        $this->actingAs($this->admin)->post(route('admin.zoho.v2.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $batch = ZohoSyncBatch::query()->sole();
        $this->assertSame($modules, $batch->modules);
        Queue::assertPushed(RunZohoModuleSyncJob::class, count($modules));

        $this->actingAs($this->admin)->post(route('admin.zoho.v2.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, ZohoSyncBatch::query()->count());
        Queue::assertPushed(RunZohoModuleSyncJob::class, count($modules));
    }

    public function test_sync_all_resumes_the_same_batch_identity_and_dispatches_only_incomplete_modules(): void
    {
        Queue::fake();
        $batch = $this->batch(['status' => 'paused', 'paused_at' => now(), 'pause_reason' => 'arrêt manuel']);
        $requestedAt = $batch->requested_at->toIso8601String();
        foreach ($this->syncAllModules() as $module) {
            if ($module !== 'contacts') {
                $this->log($batch, $module, 'success');
            }
        }

        $this->actingAs($this->admin)->post(route('admin.zoho.v2.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(1, ZohoSyncBatch::query()->count());
        $this->assertSame('running', $batch->status);
        $this->assertSame($requestedAt, $batch->requested_at->toIso8601String());
        $this->assertSame(1, $batch->resume_count);
        $this->assertSame('arrêt manuel', $batch->pause_reason);
        Queue::assertPushed(RunZohoModuleSyncJob::class, 1);
        Queue::assertPushed(
            RunZohoModuleSyncJob::class,
            fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $batch->id
                && $job->correlationId === $batch->correlation_id
                && $job->module === 'contacts',
        );
    }

    public function test_zero_work_resume_finalizes_without_dispatching_a_module(): void
    {
        Queue::fake();
        $batch = $this->batch(['status' => 'paused', 'paused_at' => now()]);
        foreach ($this->syncAllModules() as $module) {
            $this->log($batch, $module, 'success');
        }

        $this->actingAs($this->admin)->post(route('admin.zoho.v2.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame('success', $batch->status);
        $this->assertNotNull($batch->completed_at);
        Queue::assertNotPushed(RunZohoModuleSyncJob::class);
        Queue::assertPushed(RunZohoPostReconciliationJob::class, 1);
    }

    public function test_pause_route_requires_sync_permission(): void
    {
        $batch = $this->batch(['status' => 'running']);
        $viewer = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $viewer->assignRole('commercial');
        $viewer->givePermissionTo('view zoho');

        $this->actingAs($viewer)->post(route('admin.zoho.v2.pause'), ['batch_id' => $batch->id])
            ->assertForbidden();

        $this->assertSame('running', $batch->fresh()->status);
    }

    public function test_pause_route_fences_an_eligible_sync_all_batch_and_preserves_terminal_evidence(): void
    {
        $batch = $this->batch(['status' => 'running']);
        $running = $this->checkpoint($batch, 'accounts', ['status' => 'running', 'generation' => 4, 'lease_owner' => 'worker-a', 'lease_expires_at' => now()->addMinutes(5)]);
        $completed = $this->checkpoint($batch, 'contacts', ['status' => 'completed', 'generation' => 7, 'completed_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.zoho.v2.pause'), [
            'batch_id' => $batch->id,
            'reason' => "  vérification\nmanuelle  ",
        ])->assertRedirect()->assertSessionHas('success');

        $batch->refresh();
        $running->refresh();
        $completed->refresh();
        $this->assertSame('paused', $batch->status);
        $this->assertSame('vérification manuelle', $batch->pause_reason);
        $this->assertInstanceOf(CarbonInterface::class, $batch->paused_at);
        $this->assertSame('paused', $running->status);
        $this->assertSame(5, $running->generation);
        $this->assertNull($running->lease_owner);
        $this->assertNull($running->lease_expires_at);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(8, $completed->generation);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_pause_route_validates_input_and_rejects_an_ineligible_batch_safely(): void
    {
        $this->actingAs($this->admin)->post(route('admin.zoho.v2.pause'), [
            'reason' => str_repeat('x', 256),
        ])->assertSessionHasErrors(['batch_id', 'reason']);

        $batch = $this->batch(['status' => 'running', 'modules' => ['accounts']]);
        $this->actingAs($this->admin)->post(route('admin.zoho.v2.pause'), ['batch_id' => $batch->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('running', $batch->fresh()->status);
    }

    public function test_per_module_delta_is_rejected_while_a_paused_sync_all_batch_owns_it(): void
    {
        Queue::fake();
        $batch = $this->batch(['status' => 'paused', 'paused_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.zoho.v2.sync'), ['module' => 'accounts'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, ZohoSyncBatch::query()->count());
        $this->assertSame('paused', $batch->fresh()->status);
        Queue::assertNotPushed(RunZohoModuleSyncJob::class);
    }

    public function test_resume_dispatch_failure_terminalizes_only_the_incomplete_modules(): void
    {
        config()->set('queue.connections.zoho.driver', 'unsupported-zoho-test-driver');
        $batch = $this->batch(['status' => 'paused', 'paused_at' => now()]);
        foreach ($this->syncAllModules() as $module) {
            if ($module !== 'contacts') {
                $this->log($batch, $module, 'success');
            }
        }

        $this->actingAs($this->admin)->post(route('admin.zoho.v2.sync'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $batch->refresh();
        $this->assertSame('error', $batch->status);
        $this->assertNotNull($batch->completed_at);
        $this->assertSame(1, ZohoSyncLog::query()->where('sync_batch_id', $batch->id)->where('status', 'error')->count());
        $this->assertDatabaseHas('zoho_sync_logs', [
            'sync_batch_id' => $batch->id,
            'module' => 'contacts',
            'status' => 'error',
        ]);
        $this->assertDatabaseHas('zoho_sync_logs', [
            'sync_batch_id' => $batch->id,
            'module' => 'accounts',
            'status' => 'success',
        ]);
    }

    public function test_sync_batch_casts_pause_and_resume_audit_fields(): void
    {
        $batch = $this->batch([
            'status' => 'paused',
            'paused_at' => now(),
            'resumed_at' => now(),
            'resume_count' => '2',
            'resume_metadata' => ['prepared_from' => 5],
        ])->fresh();

        $this->assertInstanceOf(CarbonInterface::class, $batch->paused_at);
        $this->assertInstanceOf(CarbonInterface::class, $batch->resumed_at);
        $this->assertSame(2, $batch->resume_count);
        $this->assertSame(['prepared_from' => 5], $batch->resume_metadata);
    }

    /** @return list<string> */
    private function syncAllModules(): array
    {
        return array_keys(array_filter(
            app(ZohoModuleRegistry::class)->all(),
            fn ($definition): bool => ! $definition->activationGated && $definition->key !== 'quoted_items',
        ));
    }

    /** @param array<string,mixed> $overrides */
    private function batch(array $overrides = []): ZohoSyncBatch
    {
        return ZohoSyncBatch::query()->create(array_replace([
            'correlation_id' => 'manual-'.str()->uuid(),
            'mode' => 'delta',
            'trigger' => 'manual',
            'status' => 'queued',
            'modules' => $this->syncAllModules(),
            'requested_at' => now()->subHour(),
        ], $overrides));
    }

    private function log(ZohoSyncBatch $batch, string $module, string $status): ZohoSyncLog
    {
        $definition = app(ZohoModuleRegistry::class)->get($module);

        return ZohoSyncLog::query()->create([
            'module' => $module,
            'submodule' => $definition->submodule ?? '',
            'mode' => 'delta',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'status' => $status,
            'synced_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function checkpoint(ZohoSyncBatch $batch, string $module, array $overrides = []): ZohoSyncCheckpoint
    {
        $definition = app(ZohoModuleRegistry::class)->get($module);

        return ZohoSyncCheckpoint::query()->create(array_replace([
            'module' => 'v2:'.$module,
            'submodule' => $definition->submodule ?? '',
            'sync_mode' => 'delta',
            'sync_batch_id' => $batch->id,
            'correlation_id' => $batch->correlation_id,
            'status' => 'queued',
            'generation' => 0,
        ], $overrides));
    }
}
