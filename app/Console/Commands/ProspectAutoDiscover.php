<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\CriteriaInactiveException;
use App\Exceptions\DiscoveryRunInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\QuotaLockUnavailableException;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ProspectAutoDiscover — hourly scheduler tick for per-criteria auto-discovery.
 *
 * Selects criteria with is_active=true, auto_run=true, run_at_hour set, and
 * run_at_hour <= the current hour in the configured quota timezone
 * (DiscoveryQuotaService::currentHour() — 'decouverte.timezone' setting,
 * default Europe/Paris; Carbon handles DST). The `<=` comparison is a catch-up
 * semantics: if the server/queue was down at the scheduled hour, the next tick
 * still picks it up rather than waiting until the following day.
 *
 * Idempotency: ONE auto-attempt per criteria per calendar day (quota_date,
 * matching the format DiscoveryQuotaService::reserveRun() writes), regardless
 * of outcome (pending/completed/failed). No status filter — a deterministically
 * failing criteria must NOT be retried hourly (that would burn the daily quota
 * pool on partial `consumed` each attempt). The manual "Lancer" button on the
 * criteria page remains available same-day even after a failed auto-attempt.
 *
 * Both the hour gate AND the quota_date comparison read
 * DiscoveryQuotaService — the SAME configured timezone — so a criterion
 * cannot reserve two runs for one quota-day (the previous bug: gate on
 * Europe/Paris hour but compare quota_date in UTC let a low run_at_hour
 * double-fire in the band between UTC midnight and Paris midnight).
 *
 * Reservation + dispatch flow mirrors ProspectCriteriaController::discover():
 * reserveRun() owns the lock+transaction boundary; the job is dispatched only
 * AFTER the reservation commits, and a dispatch failure marks the run 'failed'
 * immediately so no orphan pending row survives without a backing job.
 *
 * Scheduled: hourly, withoutOverlapping (routes/console.php).
 * Signature: prospect:auto-discover
 */
class ProspectAutoDiscover extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'prospect:auto-discover';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch scheduled per-criteria auto-discovery runs due at the current quota-timezone hour.';

    /**
     * Execute the command.
     */
    public function handle(DiscoveryQuotaService $quotaService): int
    {
        $currentHour = $quotaService->currentHour();
        $today       = $quotaService->today()->toDateString();

        $due = ProspectCriteria::query()
            ->where('is_active', true)
            ->where('auto_run', true)
            ->whereNotNull('run_at_hour')
            ->where('run_at_hour', '<=', $currentHour)
            ->whereDoesntHave('discoveryRuns', function ($q) use ($today) {
                $q->where('type', 'discovery')->where('quota_date', $today);
            })
            ->get();

        $dispatched = 0;
        $skipped    = 0;

        foreach ($due as $criteria) {
            try {
                $run = $quotaService->reserveRun($criteria);
            } catch (QuotaExhaustedException $e) {
                $this->warn("  criteria {$criteria->id}: solde épuisé, skipped.");
                $skipped++;
                continue;
            } catch (DiscoveryRunInFlightException|CriteriaInactiveException $e) {
                $this->info("  criteria {$criteria->id}: {$e->getMessage()}");
                $skipped++;
                continue;
            } catch (QuotaLockUnavailableException $e) {
                $this->warn("  criteria {$criteria->id}: quota lock unavailable, skipped.");
                $skipped++;
                continue;
            }

            try {
                RunDiscoveryPipelineJob::dispatch($criteria->id, $run->id);
            } catch (\Throwable $e) {
                DiscoveryRun::where('id', $run->id)->update([
                    'status'      => 'failed',
                    'error'       => Str::limit('Échec de mise en file : ' . $e->getMessage(), 1000),
                    'finished_at' => now(),
                ]);

                Log::error('[ProspectAutoDiscover] Dispatch failed — run marked failed.', [
                    'criteria_id' => $criteria->id,
                    'run_id'      => $run->id,
                    'error'       => $e->getMessage(),
                ]);

                $skipped++;
                continue;
            }

            $dispatched++;
        }

        $this->info("Auto-discovery tick: {$dispatched} dispatched, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
