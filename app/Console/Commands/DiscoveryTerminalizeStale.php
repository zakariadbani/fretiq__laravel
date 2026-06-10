<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DiscoveryRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * DiscoveryTerminalizeStale — flip zombie discovery runs to failed.
 *
 * Finds non-terminal runs (pending|running) that are stale per the thresholds
 * defined by DiscoveryRun::isStale():
 *   pending  → created_at older than 60 s  (worker never picked it up)
 *   running  → started_at (or created_at) older than 360 s (job timeout + lock buffer)
 *
 * Each matching run is updated to status='failed' via a guarded UPDATE
 * (whereIn('status', ['pending','running'])) so a concurrently-finishing job
 * cannot be overwritten — if the job set status='completed' between our SELECT
 * and this UPDATE, the UPDATE matches zero rows and is silently skipped.
 *
 * The conditional debit guard in DiscoveryPipelineService ensures that a worker
 * which was terminalized mid-run (and kept executing) will fail to debit on the
 * next iteration and stop gracefully — so no double-spend after terminalizing.
 *
 * Releasing zombie reservations: terminal accounting uses `consumed` (actual
 * spend), so unconsumed reserved credits are released automatically once the run
 * reaches 'failed' status.
 *
 * Scheduled: everyMinute, withoutOverlapping (routes/console.php).
 * Signature: discovery:terminalize-stale
 */
class DiscoveryTerminalizeStale extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'discovery:terminalize-stale';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flip stale (zombie) discovery runs to failed and release their unconsumed credit reservations.';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        try {
            $now       = Carbon::now();
            $flipped   = 0;

            // Find non-terminal runs that match isStale() thresholds.
            // Two separate queries (pending / running) to use the correct reference
            // timestamp and threshold for each status, matching DiscoveryRun::isStale().
            //
            // pending: created_at older than 60 s (job never picked up)
            $stalePendingIds = DiscoveryRun::query()
                ->where('status', 'pending')
                ->whereNull('finished_at')
                ->where('created_at', '<=', $now->copy()->subSeconds(60))
                ->pluck('id');

            // running: started_at (falling back to created_at) older than 360 s
            $staleRunningIds = DiscoveryRun::query()
                ->where('status', 'running')
                ->whereNull('finished_at')
                ->where(function ($q) use ($now) {
                    $q->where(function ($inner) use ($now) {
                        $inner->whereNotNull('started_at')
                              ->where('started_at', '<=', $now->copy()->subSeconds(360));
                    })->orWhere(function ($inner) use ($now) {
                        $inner->whereNull('started_at')
                              ->where('created_at', '<=', $now->copy()->subSeconds(360));
                    });
                })
                ->pluck('id');

            $staleIds = $stalePendingIds->merge($staleRunningIds)->unique()->values();

            foreach ($staleIds as $runId) {
                // Guarded UPDATE — only flips if still non-terminal.
                // If the job completed concurrently, 0 rows are affected and we skip.
                $affected = DiscoveryRun::where('id', $runId)
                    ->whereIn('status', ['pending', 'running'])
                    ->update([
                        'status'      => 'failed',
                        'error'       => 'Run bloqué détecté par le terminalizer (worker indisponible ou job perdu)',
                        'finished_at' => $now,
                    ]);

                if ($affected > 0) {
                    $flipped++;
                    Log::info('[DiscoveryTerminalizeStale] Flipped stale run to failed.', [
                        'run_id' => $runId,
                    ]);
                }
            }

            $this->info("Terminalized {$flipped} stale run(s).");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('discovery:terminalize-stale failed: ' . $e->getMessage());
            Log::error('discovery:terminalize-stale failed', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
