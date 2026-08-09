<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Zoho\ZohoModuleRunOutcome;
use App\Models\Zoho\ZohoSyncBatch;
use App\Services\Zoho\V2\Bulk\ZohoModuleDispatcher;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoSyncOrchestrator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

final class ZohoCrmSync extends Command
{
    protected $signature = 'zoho:crm:sync
                            {module? : A V2 registry module key or Zoho API name}
                            {--mode=delta : delta, backfill, or reconcile}
                            {--trigger=manual : Internal batch trigger: manual or scheduled}
                            {--now : Run sequentially in this process instead of dispatching}';

    protected $description = 'Dispatch or run read-only Zoho CRM V2 mirror synchronization';

    public function handle(): int
    {
        if (! config('zoho-v2.features.sync_enabled', false)) {
            $this->error('Zoho CRM V2 sync is disabled by feature flag.');

            return self::FAILURE;
        }

        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['delta', 'backfill', 'reconcile'], true)) {
            $this->error('Invalid mode. Allowed: delta, backfill, reconcile.');

            return self::FAILURE;
        }

        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, ['manual', 'scheduled'], true)) {
            $this->error('Invalid trigger. Allowed: manual, scheduled.');

            return self::FAILURE;
        }
        if ($trigger === 'scheduled'
            && $mode === 'delta'
            && ZohoSyncBatch::query()
                ->where('mode', 'reconcile')
                ->whereIn('status', ['queued', 'running'])
                ->whereNull('completed_at')
                ->exists()) {
            $this->info('Zoho CRM V2 delta skipped: nightly reconciliation is still active.');

            return self::SUCCESS;
        }
        if ($trigger === 'scheduled'
            && ZohoSyncBatch::query()->where('mode', $mode)->whereIn('status', ['queued', 'running'])->whereNull('completed_at')->exists()) {
            $this->info("Zoho CRM V2 {$mode} skipped: an equivalent scheduled batch is still active.");

            return self::SUCCESS;
        }

        /** @var ZohoModuleRegistry $registry */
        $registry = app(ZohoModuleRegistry::class);
        try {
            $modules = $this->resolveModules($registry, $this->argument('module'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        /** @var ZohoModuleDispatcher $dispatcher */
        $dispatcher = app(ZohoModuleDispatcher::class);
        if ($this->option('now') && collect($modules)->contains(
            fn (string $module): bool => $dispatcher->usesBulk($module, $mode),
        )) {
            $this->error('Bulk backfill is asynchronous; omit --now.');

            return self::FAILURE;
        }

        /** @var ZohoSyncOrchestrator $orchestrator */
        $orchestrator = app(ZohoSyncOrchestrator::class);
        $batch = $orchestrator->createBatch($modules, $mode, $trigger, null);
        $batchId = (int) $batch->id;
        $correlationId = (string) $batch->correlation_id;
        $fatal = false;
        $partial = false;

        foreach ($modules as $index => $module) {
            if ($this->option('now')) {
                do {
                    $result = $orchestrator->runModule($batchId, $module, $mode, $correlationId);
                    if ($result->leaseConflict || $result->retryableFailure
                        || ($result->continuationRequired && $result->retryAfterSeconds !== null)) {
                        $orchestrator->terminalizeModule($batchId, $module, $mode, 'inline_failed');
                        $fatal = true;
                        break;
                    }
                } while ($result->continuationRequired);

                $syncStatus = ZohoModuleRunOutcome::syncStatus($batchId, $module);
                if ($syncStatus === 'error') {
                    $fatal = true;

                    continue;
                }
                if ($mode === 'reconcile' && $result->reconciliation !== null) {
                    $reconciliationStatus = ZohoModuleRunOutcome::recordReconciliation(
                        $batchId,
                        $module,
                        $result->reconciliation,
                    );
                    $fatal = $fatal || $reconciliationStatus === 'error';
                    $partial = $partial || $reconciliationStatus === 'partial';
                }
                $partial = $partial || $syncStatus === 'partial';

                continue;
            }

            try {
                $dispatcher->dispatch($batchId, $module, $mode, $correlationId);
            } catch (Throwable) {
                foreach (array_slice($modules, $index) as $undispatched) {
                    $orchestrator->terminalizeModule($batchId, $undispatched, $mode, 'dispatch_failed');
                }
                $this->error("Zoho CRM V2 {$mode} batch {$batchId} could not dispatch every module.");

                return self::FAILURE;
            }
        }

        if ($this->option('now')) {
            $orchestrator->finalizeBatch($batchId);
            if ($fatal) {
                $this->error("Zoho CRM V2 {$mode} batch {$batchId} failed; see correlation ID.");

                return self::FAILURE;
            }
            if ($partial) {
                $this->warn("Zoho CRM V2 {$mode} batch {$batchId} completed with partial health.");

                return self::SUCCESS;
            }
        }
        $this->info(sprintf('Zoho CRM V2 %s batch %d started for %d module(s).', $mode, $batchId, count($modules)));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function resolveModules(ZohoModuleRegistry $registry, ?string $requested): array
    {
        if ($requested === null) {
            return array_values(array_map(
                static fn ($definition): string => $definition->key,
                array_filter($registry->all(), static fn ($definition): bool => ! $definition->activationGated
                    && $definition->key !== 'quoted_items'),
            ));
        }

        foreach ($registry->all() as $definition) {
            if ($definition->key === $requested || strcasecmp($definition->apiName, $requested) === 0) {
                if ($definition->activationGated) {
                    throw new InvalidArgumentException("Zoho CRM module [{$requested}] is activation-gated: {$definition->activationNote}");
                }
                if ($definition->key === 'quoted_items') {
                    throw new InvalidArgumentException('Quoted items are synchronized only as nested Quotes aggregates.');
                }

                return [$definition->key];
            }
        }

        throw new InvalidArgumentException("Unknown Zoho CRM module [{$requested}].");
    }
}
