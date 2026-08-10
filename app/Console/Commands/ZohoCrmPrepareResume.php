<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\V2\Sync\ZohoBatchResumePreparer;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

final class ZohoCrmPrepareResume extends Command
{
    protected $signature = 'zoho:crm:prepare-resume {batch} {--apply : Persist the local resume preparation}';

    protected $description = 'Inspect or prepare an interrupted Zoho CRM Sync Tout batch for durable local resume';

    public function handle(ZohoBatchResumePreparer $preparer): int
    {
        $batchId = filter_var($this->argument('batch'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($batchId === false) {
            $this->error('Batch must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $result = $preparer->prepare($batchId, (bool) $this->option('apply'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Completed modules (%d): %s',
            count($result['completed_modules']),
            implode(', ', $result['completed_modules']),
        ));
        $this->line(sprintf(
            'Incomplete modules (%d): %s',
            count($result['incomplete_modules']),
            implode(', ', $result['incomplete_modules']),
        ));
        foreach ($result['modules'] as $module => $counts) {
            $this->line(sprintf(
                '%s: mirror seeds %d, unresolved record failures %d, resulting completed %d, queued %d',
                $module,
                $counts['mirror_seeds'],
                $counts['unresolved_failures'],
                $counts['completed'],
                $counts['queued'],
            ));
        }
        $this->line(sprintf(
            'Resulting work items: %d completed, %d queued',
            $result['completed'],
            $result['queued'],
        ));

        if (! $this->option('apply')) {
            $this->info('Dry run only; no changes were written. Use --apply to persist this preparation.');

            return self::SUCCESS;
        }
        if ($result['already_prepared']) {
            $this->info("Batch {$batchId} was already prepared and remains paused; no changes were written.");

            return self::SUCCESS;
        }

        $this->info("Batch {$batchId} prepared and left paused; no jobs were dispatched.");

        return self::SUCCESS;
    }
}
