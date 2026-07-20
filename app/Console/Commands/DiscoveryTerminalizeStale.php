<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DiscoveryRun;
use App\Services\Discovery\DiscoveryClaimFinalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DiscoveryTerminalizeStale — flip zombie discovery runs to failed.
 *
 * Finds non-terminal runs (pending|running) that are stale per the thresholds
 * defined by DiscoveryRun::isStale():
 *   pending  → created_at older than 24 h (job lost beyond daily queue latency)
 *   running  → updated_at heartbeat older than 660 s (job timeout + lock buffer)
 *
 * Each matching run is updated to status='failed' via a guarded UPDATE that
 * repeats both the active status and the original timestamp cutoff. A concurrent
 * completion or heartbeat therefore makes the UPDATE match zero rows.
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
            $now = Carbon::now();
            $pendingCutoff = $now->copy()->subSeconds(DiscoveryRun::PENDING_STALE_AFTER_SECONDS);
            $runningCutoff = $now->copy()->subSeconds(DiscoveryRun::RUNNING_STALE_AFTER_SECONDS);
            $flipped = 0;

            // Find non-terminal runs that match isStale() thresholds.
            // Two separate queries (pending / running) to use the correct reference
            // timestamp and threshold for each status, matching DiscoveryRun::isStale().
            //
            // pending: created_at older than 24 h (job lost beyond queue latency)
            $stalePendingIds = DiscoveryRun::query()
                ->where('status', 'pending')
                ->whereNull('finished_at')
                ->where('created_at', '<=', $pendingCutoff)
                ->pluck('id');

            // running: updated_at is the heartbeat. The started_at/created_at
            // fallback only covers legacy rows whose updated_at is null.
            $staleRunningIds = DiscoveryRun::query()
                ->where('status', 'running')
                ->whereNull('finished_at')
                ->where(function ($q) use ($runningCutoff) {
                    $q->where('updated_at', '<=', $runningCutoff)
                        ->orWhere(function ($legacy) use ($runningCutoff) {
                            $legacy->whereNull('updated_at')
                                ->where(function ($reference) use ($runningCutoff) {
                                    $reference->where('started_at', '<=', $runningCutoff)
                                        ->orWhere(function ($created) use ($runningCutoff) {
                                            $created->whereNull('started_at')
                                                ->where('created_at', '<=', $runningCutoff);
                                        });
                                });
                        });
                })
                ->pluck('id');

            foreach ($stalePendingIds as $runId) {
                $affected = DB::transaction(function () use ($runId, $pendingCutoff, $now): int {
                    $updated = DiscoveryRun::where('id', $runId)
                        ->where('status', 'pending')
                        ->whereNull('finished_at')
                        ->where('created_at', '<=', $pendingCutoff)
                        ->update([
                            'status' => 'failed',
                            'error' => 'Run bloqué détecté par le terminalizer (worker indisponible ou job perdu)',
                            'finished_at' => $now,
                        ]);

                    if ($updated === 1) {
                        app(DiscoveryClaimFinalizer::class)->releaseForTerminalRun((int) $runId);
                    }

                    return $updated;
                });

                if ($affected > 0) {
                    $flipped++;
                    Log::info('[DiscoveryTerminalizeStale] Flipped stale run to failed.', [
                        'run_id' => $runId,
                    ]);
                }
            }

            foreach ($staleRunningIds as $runId) {
                // Repeat the heartbeat cutoff atomically. If a worker refreshed
                // updated_at after our SELECT, this UPDATE affects zero rows.
                $affected = DB::transaction(function () use ($runId, $runningCutoff, $now): int {
                    $updated = DiscoveryRun::where('id', $runId)
                        ->where('status', 'running')
                        ->whereNull('finished_at')
                        ->where(function ($q) use ($runningCutoff) {
                            $q->where('updated_at', '<=', $runningCutoff)
                                ->orWhere(function ($legacy) use ($runningCutoff) {
                                    $legacy->whereNull('updated_at')
                                        ->where(function ($reference) use ($runningCutoff) {
                                            $reference->where('started_at', '<=', $runningCutoff)
                                                ->orWhere(function ($created) use ($runningCutoff) {
                                                    $created->whereNull('started_at')
                                                        ->where('created_at', '<=', $runningCutoff);
                                                });
                                        });
                                });
                        })
                        ->update([
                            'status' => 'failed',
                            'error' => 'Run bloqué détecté par le terminalizer (worker indisponible ou job perdu)',
                            'finished_at' => $now,
                        ]);

                    if ($updated === 1) {
                        app(DiscoveryClaimFinalizer::class)->releaseForTerminalRun((int) $runId);
                    }

                    return $updated;
                });

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
            $this->error('discovery:terminalize-stale failed: '.$e->getMessage());
            Log::error('discovery:terminalize-stale failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return self::FAILURE;
        }
    }
}
