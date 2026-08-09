<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\V2\Inventory\ZohoInventoryService;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ZohoCrmInventory extends Command
{
    protected $signature = 'zoho:crm:inventory {--module=* : Restrict inventory to one or more registry module keys}';

    protected $description = 'Inventory readable Zoho CRM modules and field metadata (read-only)';

    public function handle(): int
    {
        /** @var ZohoInventoryService $inventory */
        $inventory = app(ZohoInventoryService::class);
        try {
            $modules = $this->resolveModules(
                app(ZohoModuleRegistry::class),
                array_values(array_filter($this->option('module'))),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $report = $inventory->inventory($modules ?: null);
        $this->info(sprintf(
            'Zoho CRM inventory completed: %d module(s), %d failed.',
            $report->discoveredModules,
            $report->failedModules,
        ));

        return $report->failedModules > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<string> $requested @return list<string> */
    private function resolveModules(ZohoModuleRegistry $registry, array $requested): array
    {
        $resolved = [];
        foreach ($requested as $name) {
            $definition = collect($registry->all())->first(
                fn ($definition): bool => $definition->key === $name || strcasecmp($definition->apiName, $name) === 0,
            );
            if ($definition === null) {
                throw new InvalidArgumentException("Unknown Zoho CRM module [{$name}].");
            }
            $resolved[] = $definition->key;
        }

        return array_values(array_unique($resolved));
    }
}
