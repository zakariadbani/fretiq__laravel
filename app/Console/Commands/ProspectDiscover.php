<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\QuotaExhaustedException;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ProspectDiscover — on-demand cold-discovery command.
 *
 * Usage:
 *   php artisan prospect:discover                       # run all active criteria
 *   php artisan prospect:discover --criteria=1          # run criteria id=1
 *   php artisan prospect:discover --criteria=1 --max=5  # cap at 5 provider searches
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
    protected $description = 'Lance le pipeline de prospection (SerpAPI → Recherche de contacts → import entreprises/contacts)';

    /**
     * Execute the console command.
     */
    public function handle(DiscoveryQuotaService $quotaService): int
    {
        $criteriaId = $this->option('criteria');
        $rawMax = $this->option('max');
        $maxOverride = null;

        if ($rawMax !== null) {
            $validatedMax = filter_var($rawMax, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($validatedMax === false) {
                $this->error('--max doit être un entier positif.');

                return self::FAILURE;
            }

            $maxOverride = (int) $validatedMax;
        }

        $driver = config('services.serpapi.driver', 'local');

        if ($driver !== 'local') {
            $this->warn(
                'Real driver active (DISCOVERY_DRIVER='.$driver.'). '.
                'Cette exécution consommera des crédits SerpAPI et du quota contacts.'
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

        $dispatchFailed = false;

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

            // Apply --max override to both the legacy and explicit provider-search
            // reservation fields before dispatching.
            $reservedSearches = (int) ($run->searches_reserved ?? $run->credits_reserved);
            if ($maxOverride !== null && $maxOverride < $reservedSearches) {
                $run->credits_reserved = min((int) $run->credits_reserved, $maxOverride);
                $run->searches_reserved = $maxOverride;
                $run->saveQuietly();
                $this->line("  → --max={$maxOverride} applied; search reservation lowered to {$maxOverride}.");
            }

            // A resumable discovery must execute on the queue: release(5) has no
            // continuation effect when invoked through dispatchSync().
            try {
                RunDiscoveryPipelineJob::dispatch($criteria->id, $run->id);
            } catch (\Throwable $e) {
                $markedFailed = DiscoveryRun::failPendingDispatch((int) $run->id, (int) $criteria->id);

                Log::error('[ProspectDiscover] Discovery dispatch failed.', [
                    'criteria_id' => $criteria->id,
                    'run_id' => $run->id,
                    'run_marked_failed' => $markedFailed,
                    'exception_class' => $e::class,
                ]);

                $this->error("  criteria {$criteria->id}: impossible de mettre le job en file.");
                $dispatchFailed = true;

                continue;
            }

            $this->line(sprintf(
                '     queued run=%d  status=%s  reserved=%d',
                $run->id,
                $run->status,
                $run->credits_reserved ?? 0,
            ));
        }

        $this->info('Discovery jobs queued.');

        return $dispatchFailed ? self::FAILURE : self::SUCCESS;
    }
}
