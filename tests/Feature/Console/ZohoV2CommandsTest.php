<?php

namespace Tests\Feature\Console;

use App\Jobs\Zoho\RetryZohoFailuresJob;
use App\Jobs\Zoho\RunZohoBulkBackfillJob;
use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\V2\Inventory\ZohoInventoryService;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ZohoV2CommandsTest extends TestCase
{
    public function test_sync_command_refuses_when_v2_sync_is_disabled(): void
    {
        $this->artisan('zoho:crm:sync', ['module' => 'accounts'])
            ->expectsOutputToContain('disabled')
            ->assertExitCode(1);
    }

    public function test_sync_command_rejects_an_unknown_module_before_dispatching(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);

        $this->artisan('zoho:crm:sync', ['module' => 'not-a-module'])
            ->expectsOutputToContain('Unknown Zoho CRM module')
            ->assertExitCode(1);
    }

    public function test_sync_command_dispatches_each_selected_module_to_the_zoho_queue(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        Bus::fake();

        $batch = new ZohoSyncBatch;
        $batch->id = 91;
        $batch->correlation_id = 'safe-test-correlation';
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->once()->with(['accounts'], 'delta', 'manual', null)->andReturn($batch);
        $orchestrator->shouldReceive('prepareModuleDelivery')->once()
            ->with(91, 'accounts', 'delta', 'safe-test-correlation', null)
            ->andReturn(1);
        $orchestrator->shouldReceive('finalizeBatch')->never();
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $this->artisan('zoho:crm:sync', ['module' => 'Accounts', '--mode' => 'delta'])
            ->assertExitCode(0);

        Bus::assertDispatched(RunZohoModuleSyncJob::class, fn (RunZohoModuleSyncJob $job): bool => $job->batchId === 91
            && $job->module === 'accounts'
            && $job->mode === 'delta'
            && $job->correlationId === 'safe-test-correlation'
            && $job->queue === 'zoho');
    }

    public function test_backfill_command_uses_bulk_only_when_enabled_and_supported(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        config()->set('zoho-v2.features.bulk_backfill_enabled', true);
        config()->set('zoho-v2.bulk.verified_modules', ['accounts']);
        Bus::fake();

        $batch = new ZohoSyncBatch;
        $batch->id = 93;
        $batch->correlation_id = 'bulk-command-correlation';
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->once()->with(['accounts'], 'backfill', 'manual', null)->andReturn($batch);
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $this->artisan('zoho:crm:sync', ['module' => 'Accounts', '--mode' => 'backfill'])
            ->assertExitCode(0);

        Bus::assertDispatched(RunZohoBulkBackfillJob::class, fn (RunZohoBulkBackfillJob $job): bool => $job->batchId === 93
            && $job->module === 'accounts'
            && $job->correlationId === 'bulk-command-correlation'
            && $job->queue === 'zoho');
        Bus::assertNotDispatched(RunZohoModuleSyncJob::class);
    }

    public function test_now_bulk_backfill_is_rejected_before_a_batch_is_created(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        config()->set('zoho-v2.features.bulk_backfill_enabled', true);
        config()->set('zoho-v2.bulk.verified_modules', ['accounts']);
        Bus::fake();

        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->never();
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $this->artisan('zoho:crm:sync', [
            'module' => 'Accounts', '--mode' => 'backfill', '--now' => true,
        ])->expectsOutputToContain('asynchronous')
            ->assertExitCode(1);

        Bus::assertNothingDispatched();
    }

    public function test_sync_command_records_the_validated_scheduled_trigger(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        Bus::fake();

        $batch = new ZohoSyncBatch;
        $batch->id = 92;
        $batch->correlation_id = 'scheduled-correlation';
        $orchestrator = Mockery::mock(ZohoSyncOrchestrator::class);
        $orchestrator->shouldReceive('createBatch')->once()->with(['accounts'], 'delta', 'scheduled', null)->andReturn($batch);
        $orchestrator->shouldReceive('prepareModuleDelivery')->once()
            ->with(92, 'accounts', 'delta', 'scheduled-correlation', null)
            ->andReturn(1);
        $this->app->instance(ZohoSyncOrchestrator::class, $orchestrator);

        $this->artisan('zoho:crm:sync', [
            'module' => 'accounts',
            '--mode' => 'delta',
            '--trigger' => 'scheduled',
        ])->assertExitCode(0);
    }

    public function test_sync_command_rejects_an_unknown_trigger(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);

        $this->artisan('zoho:crm:sync', ['module' => 'accounts', '--trigger' => 'browser'])
            ->expectsOutputToContain('Invalid trigger')
            ->assertExitCode(1);
    }

    public function test_sync_command_rejects_standalone_quote_items(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        Bus::fake();

        $this->artisan('zoho:crm:sync', ['module' => 'Quoted_Items'])
            ->expectsOutputToContain('nested Quotes aggregates')
            ->assertExitCode(1);

        Bus::assertNothingDispatched();
    }

    public function test_v2_schedule_uses_the_required_expressions_and_reporting_timezone(): void
    {
        $events = collect(app(Schedule::class)->events());
        $delta = $events->first(fn ($event): bool => str_contains((string) $event->command, 'zoho:crm:sync --mode=delta'));
        $reconcile = $events->first(fn ($event): bool => str_contains((string) $event->command, 'zoho:crm:sync --mode=reconcile'));

        $this->assertNotNull($delta);
        $this->assertNotNull($reconcile);
        $this->assertSame('10 * * * *', $delta->expression);
        $this->assertSame('30 2 * * *', $reconcile->expression);
        $this->assertSame('Europe/Paris', $delta->timezone);
        $this->assertSame('Europe/Paris', $reconcile->timezone);
        $this->assertStringContainsString('--trigger=scheduled', (string) $delta->command);
        $this->assertStringContainsString('--trigger=scheduled', (string) $reconcile->command);
    }

    public function test_inventory_command_reports_the_inventory_report_totals(): void
    {
        $transport = new InventorySummaryTransport;
        $this->app->instance(
            ZohoInventoryService::class,
            new ZohoInventoryService($transport, app(ZohoModuleRegistry::class)),
        );

        $this->artisan('zoho:crm:inventory', ['--module' => ['users']])
            ->expectsOutputToContain('1 module(s), 0 failed')
            ->assertExitCode(0);
    }

    public function test_inventory_command_accepts_a_zoho_api_module_name(): void
    {
        $transport = new InventorySummaryTransport;
        $this->app->instance(
            ZohoInventoryService::class,
            new ZohoInventoryService($transport, app(ZohoModuleRegistry::class)),
        );

        $this->artisan('zoho:crm:inventory', ['--module' => ['Users']])
            ->expectsOutputToContain('1 module(s), 0 failed')
            ->assertExitCode(0);
    }

    public function test_retry_command_normalizes_api_name_and_dispatches_a_full_retry_batch(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        Bus::fake();

        $this->artisan('zoho:crm:retry-failures', ['module' => 'Accounts'])
            ->assertExitCode(0);

        Bus::assertDispatched(RetryZohoFailuresJob::class, fn (RetryZohoFailuresJob $job): bool => $job->module === 'accounts'
            && $job->limit === 100
            && $job->tries === 5
            && $job->queue === 'zoho');
    }

    public function test_retry_command_rejects_standalone_quote_items(): void
    {
        config()->set('zoho-v2.features.sync_enabled', true);
        Bus::fake();

        $this->artisan('zoho:crm:retry-failures', ['module' => 'Quoted_Items'])
            ->expectsOutputToContain('nested Quotes aggregates')
            ->assertExitCode(1);

        Bus::assertNothingDispatched();
    }
}

final class InventorySummaryTransport implements ZohoTransport
{
    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        $payload = $path === '/settings/modules'
            ? ['modules' => [['api_name' => 'Users']]]
            : [];

        return new TransportResult(200, [], [], [], $correlationId ?? 'inventory-summary', [], null, $payload);
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        return $this->get($path, $query, $correlationId);
    }
}
