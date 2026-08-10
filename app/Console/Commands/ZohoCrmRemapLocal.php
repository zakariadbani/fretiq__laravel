<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoLocalMirrorRemapper;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

final class ZohoCrmRemapLocal extends Command
{
    protected $signature = 'zoho:crm:remap-local {--module=*} {--chunk=500} {--apply : Persist local typed-field corrections}';

    protected $description = 'Remap local Zoho CRM raw payloads without contacting Zoho';

    public function handle(ZohoLocalMirrorRemapper $remapper, ZohoModuleRegistry $registry): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5000],
        ]);

        if ($chunk === false) {
            $this->error('Chunk must be an integer between 1 and 5000.');

            return self::FAILURE;
        }

        try {
            $modules = $this->modules($registry);
            $totals = ['scanned' => 0, 'changed' => 0, 'unchanged' => 0, 'failed' => 0];

            foreach ($modules as $module) {
                $result = $remapper->remap($module, $chunk, (bool) $this->option('apply'));

                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $result[$key];
                }

                $this->line("{$module}: scanned {$result['scanned']}, changed {$result['changed']}, unchanged {$result['unchanged']}, failed {$result['failed']}");
                foreach ($result['fields'] as $field => $count) {
                    $this->line("  field {$field}: {$count}");
                }
                foreach ($result['errors'] as $error) {
                    $this->warn('  '.$error);
                }
            }

            $this->info(sprintf(
                '%s complete: scanned %d, changed %d, unchanged %d, failed %d.',
                $this->option('apply') ? 'Apply' : 'Dry run',
                ...array_values($totals),
            ));

            return $totals['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function modules(ZohoModuleRegistry $registry): array
    {
        $requested = array_values(array_filter(
            $this->option('module'),
            fn ($value): bool => is_string($value) && trim($value) !== '',
        ));
        $defaults = ['leads', 'accounts', 'contacts', 'deals', 'quotes', 'tasks', 'events', 'calls', 'notes', 'deal_history'];

        if ($requested === []) {
            return $defaults;
        }

        return array_values(array_unique(array_map(function (string $name) use ($registry): string {
            foreach ($registry->all() as $definition) {
                if ($definition->key === $name || strcasecmp($definition->apiName, $name) === 0) {
                    return $definition->key;
                }
            }

            throw new InvalidArgumentException("Unknown Zoho CRM module [{$name}].");
        }, $requested)));
    }
}
