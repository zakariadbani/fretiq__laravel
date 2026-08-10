<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Zoho\RetryZohoFailuresJob;
use App\Services\Zoho\V2\Reconciliation\ZohoFailureRetryService;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ZohoCrmRetryFailures extends Command
{
    protected $signature = 'zoho:crm:retry-failures {module? : Optional V2 registry module key} {--now : Execute now}';

    protected $description = 'Retry quarantined read-only Zoho CRM V2 records';

    public function handle(): int
    {
        $module = $this->argument('module');
        if ($module !== null) {
            try {
                $definition = $this->resolveModule(app(ZohoModuleRegistry::class), (string) $module);
                if ($definition->activationGated) {
                    throw new InvalidArgumentException("Zoho CRM module [{$module}] is activation-gated: {$definition->activationNote}");
                }
                if ($definition->key === 'quoted_items') {
                    throw new InvalidArgumentException('Quoted items are synchronized only as nested Quotes aggregates.');
                }
                $module = $definition->key;
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }
        $limit = max(1, (int) config('zoho-v2.retry.batch_size', 100));
        if (! $this->option('now')) {
            RetryZohoFailuresJob::dispatch($module, $limit)->onQueue((string) config('zoho-v2.queue', 'zoho'));
            $this->info('Zoho CRM V2 failure retry job dispatched.');

            return self::SUCCESS;
        }

        /** @var ZohoFailureRetryService $retryService */
        $retryService = app(ZohoFailureRetryService::class);
        $result = $retryService->retry($module, $limit);
        $this->info(sprintf('Zoho CRM V2 failure retry completed: %d attempted, %d resolved, %d deferred.', $result['attempted'], $result['resolved'], $result['deferred']));

        return self::SUCCESS;
    }

    private function resolveModule(ZohoModuleRegistry $registry, string $requested): object
    {
        foreach ($registry->all() as $definition) {
            if ($definition->key === $requested || strcasecmp($definition->apiName, $requested) === 0) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown Zoho CRM module [{$requested}].");
    }
}
