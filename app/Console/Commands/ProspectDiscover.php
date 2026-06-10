<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\QuotaExhaustedException;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\ProspectCriteria;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Console\Command;

/**
 * ProspectDiscover — on-demand cold-discovery command.
 *
 * Usage:
 *   php artisan prospect:discover                       # run all active criteria
 *   php artisan prospect:discover --criteria=1          # run criteria id=1
 *   php artisan prospect:discover --criteria=1 --max=5  # cap at 5 domains
 *
 * All runs go through DiscoveryQuotaService::reserveRun() — no unmetered path.
 * Criteria at 0 remaining are skipped with a warning.
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
                            {--max=      : Override the daily_limit cap for this run (further capped by quota)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the cold-discovery pipeline (SerpAPI → Hunter → Company/Contact upsert)';

    /**
     * Execute the console command.
     */
    public function handle(DiscoveryQuotaService $quotaService): int
    {
        $criteriaId  = $this->option('criteria');
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

        foreach ($list as $criteria) {
            $this->line(sprintf(
                '  → [%d] %s  (daily_limit=%s)',
                $criteria->id,
                $criteria->name,
                $criteria->daily_limit ?? 'default:20'
            ));

            // Reserve a run via the quota service (single entry point — metered path).
            try {
                $run = $quotaService->reserveRun($criteria);
            } catch (QuotaExhaustedException $e) {
                $this->warn("  criteria {$criteria->id}: solde épuisé, skipped.");
                continue;
            }

            // Apply --max override: if provided and lower than credits_reserved,
            // lower the run's reservation before dispatching.
            if ($maxOverride !== null && $maxOverride < $run->credits_reserved) {
                $run->credits_reserved = $maxOverride;
                $run->saveQuietly();
                $this->line("  → --max={$maxOverride} applied; credits_reserved lowered to {$maxOverride}.");
            }

            // Reuse job logic (status transitions, counts, failure handling) by
            // dispatching synchronously. dispatchSync executes handle() in-process.
            RunDiscoveryPipelineJob::dispatchSync($criteria->id, $run->id);

            $run->refresh();

            $this->line(sprintf(
                '     status=%s  companies=%d  contacts=%d  skipped=%d  consumed=%d',
                $run->status,
                $run->companies_count ?? 0,
                $run->contacts_count  ?? 0,
                $run->skipped_count   ?? 0,
                $run->consumed        ?? 0,
            ));
        }

        $this->info('Discovery complete.');

        return self::SUCCESS;
    }
}
