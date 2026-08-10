<?php

namespace Tests\Unit\Services\Zoho\V2\Bulk;

use App\Jobs\Zoho\RunZohoBulkBackfillJob;
use App\Jobs\Zoho\RunZohoModuleSyncJob;
use App\Models\Zoho\ZohoSyncBatch;
use App\Services\Zoho\V2\Bulk\ZohoModuleDispatcher;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ZohoModuleDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_uses_bulk_only_when_verified_and_supported(): void
    {
        config()->set('zoho-v2.bulk.verified_modules', ['accounts']);
        Bus::fake();
        $dispatcher = new ZohoModuleDispatcher(app(ZohoModuleRegistry::class));

        $dispatcher->dispatch(10, 'accounts', 'backfill', 'bulk-policy');
        $notes = $this->batch(['notes'], 'backfill', 'standard-policy');
        $delta = $this->batch(['accounts'], 'delta', 'delta-policy');
        $dispatcher->dispatch($notes->id, 'notes', 'backfill', 'standard-policy');
        $dispatcher->dispatch($delta->id, 'accounts', 'delta', 'delta-policy');

        Bus::assertDispatchedTimes(RunZohoBulkBackfillJob::class, 1);
        Bus::assertDispatched(RunZohoBulkBackfillJob::class, fn (RunZohoBulkBackfillJob $job): bool => $job->batchId === 10);
        Bus::assertDispatchedTimes(RunZohoModuleSyncJob::class, 2);
    }

    public function test_empty_verified_module_allowlist_uses_standard_dispatch(): void
    {
        config()->set('zoho-v2.bulk.verified_modules', []);
        Bus::fake();
        $dispatcher = new ZohoModuleDispatcher(app(ZohoModuleRegistry::class));

        $this->assertFalse($dispatcher->usesBulk('accounts', 'backfill'));
        $batch = $this->batch(['accounts'], 'backfill', 'disabled-policy');
        $dispatcher->dispatch($batch->id, 'accounts', 'backfill', 'disabled-policy');

        Bus::assertNotDispatched(RunZohoBulkBackfillJob::class);
        Bus::assertDispatched(RunZohoModuleSyncJob::class);
    }

    public function test_leads_backfill_uses_records_after_live_bulk_conversion_coverage_was_incomplete(): void
    {
        Bus::fake();
        $dispatcher = new ZohoModuleDispatcher(app(ZohoModuleRegistry::class));

        $this->assertFalse($dispatcher->usesBulk('leads', 'backfill'));
        $batch = $this->batch(['leads'], 'backfill', 'converted-both-policy');
        $dispatcher->dispatch($batch->id, 'leads', 'backfill', 'converted-both-policy');

        Bus::assertNotDispatched(RunZohoBulkBackfillJob::class);
        Bus::assertDispatched(
            RunZohoModuleSyncJob::class,
            fn (RunZohoModuleSyncJob $job): bool => $job->batchId === $batch->id
                && $job->module === 'leads'
                && $job->mode === 'backfill',
        );
    }

    public function test_registry_capability_without_live_module_verification_stays_on_records(): void
    {
        config()->set('zoho-v2.bulk.verified_modules', []);
        Bus::fake();
        $dispatcher = new ZohoModuleDispatcher(app(ZohoModuleRegistry::class));

        $this->assertFalse($dispatcher->usesBulk('accounts', 'backfill'));
        $batch = $this->batch(['accounts'], 'backfill', 'unverified-bulk-policy');
        $dispatcher->dispatch($batch->id, 'accounts', 'backfill', 'unverified-bulk-policy');

        Bus::assertNotDispatched(RunZohoBulkBackfillJob::class);
        Bus::assertDispatched(RunZohoModuleSyncJob::class);
    }

    /** @param list<string> $modules */
    private function batch(array $modules, string $mode, string $correlationId): ZohoSyncBatch
    {
        return ZohoSyncBatch::query()->create([
            'correlation_id' => $correlationId, 'mode' => $mode, 'trigger' => 'test',
            'status' => 'queued', 'modules' => $modules, 'requested_at' => now(),
        ]);
    }
}
