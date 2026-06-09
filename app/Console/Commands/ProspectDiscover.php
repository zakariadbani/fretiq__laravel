<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryPipelineService;
use Illuminate\Console\Command;

/**
 * ProspectDiscover — on-demand cold-discovery command.
 *
 * Usage:
 *   php artisan prospect:discover                       # run all active criteria
 *   php artisan prospect:discover --criteria=1          # run criteria id=1
 *   php artisan prospect:discover --criteria=1 --max=5  # cap at 5 domains
 *
 * NOTE: When the real driver (DISCOVERY_DRIVER != local) is active, this command
 * burns SerpAPI + Hunter API credits for every run. The local driver uses fixtures
 * and has no cost. Driver is controlled by env DISCOVERY_DRIVER (default 'local').
 */
class ProspectDiscover extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prospect:discover
                            {--criteria= : Run a single criteria by its ID (omit for all active)}
                            {--max=      : Override the daily_limit cap for this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the cold-discovery pipeline (SerpAPI → Hunter → Company/Contact upsert)';

    /**
     * Execute the console command.
     */
    public function handle(DiscoveryPipelineService $pipeline): int
    {
        $criteriaId = $this->option('criteria');
        $maxOverride = $this->option('max') ? (int) $this->option('max') : null;

        $driver = config('services.serpapi.driver', 'local');

        if ($driver !== 'local') {
            $this->warn(
                'Real driver active (DISCOVERY_DRIVER=' . $driver . '). ' .
                'This run WILL consume SerpAPI and Hunter API credits.'
            );
        }

        // ── Resolve criteria list ─────────────────────────────────────────────

        if ($criteriaId !== null) {
            $list = ProspectCriteria::where('id', (int) $criteriaId)->get();

            if ($list->isEmpty()) {
                $this->error("ProspectCriteria #{$criteriaId} not found.");
                return self::FAILURE;
            }
        } else {
            $list = ProspectCriteria::where('is_active', true)->get();

            if ($list->isEmpty()) {
                $this->warn('No active ProspectCriteria found. Nothing to do.');
                return self::SUCCESS;
            }
        }

        // ── Run pipeline per criteria ─────────────────────────────────────────

        $this->info(sprintf('Running discovery for %d criteria set(s)…', $list->count()));

        $totalCompanies = 0;
        $totalContacts  = 0;
        $totalSkipped   = 0;

        foreach ($list as $criteria) {
            $this->line(sprintf('  → [%d] %s  (daily_limit=%s)', $criteria->id, $criteria->name, $criteria->daily_limit ?? 'default:20'));

            // Clone the Eloquent model when --max overrides daily_limit so the
            // persisted record is never mutated. DiscoveryPipelineService::run()
            // has no override parameter (signature: run(ProspectCriteria): array),
            // so the clone approach is required.
            $effectiveCriteria = $criteria;
            if ($maxOverride !== null) {
                $effectiveCriteria = clone $criteria;
                $effectiveCriteria->daily_limit = $maxOverride;
            }

            $stats = $pipeline->run($effectiveCriteria);

            $this->line(sprintf(
                '     companies=%d  contacts=%d  skipped=%d',
                $stats['companies'],
                $stats['contacts'],
                $stats['skipped'],
            ));

            $totalCompanies += $stats['companies'];
            $totalContacts  += $stats['contacts'];
            $totalSkipped   += $stats['skipped'];
        }

        $this->info(sprintf(
            'Discovery complete — companies=%d  contacts=%d  skipped=%d',
            $totalCompanies,
            $totalContacts,
            $totalSkipped,
        ));

        return self::SUCCESS;
    }
}
