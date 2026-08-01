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
use App\Services\Scheduling\BusinessCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
 *
 * Day-level gate (BusinessCalendarService): before the hour gate is even
 * evaluated, the WHOLE tick is skipped when today (in the quota timezone) is
 * a blocked day — a skipped weekend (planification.skip_weekends) or a
 * blackout date (planification.blackout_dates). This reads the exact same
 * quotaTz() as the hour gate and the quota_date comparison above, so the day
 * check, the hour check, and the once-per-quota-day dedup all agree on the
 * same calendar day — no UTC/local-midnight desync. On a blocked day the
 * command exits self::SUCCESS (never FAILURE): a non-zero exit code is
 * mapped to a 'failed' scheduled-task result by
 * AppServiceProvider::boot()'s ScheduledTaskFinished listener, and a
 * deliberately-skipped weekend/holiday must not show up as a broken cron in
 * the observability dashboard.
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
    public function handle(DiscoveryQuotaService $quotaService, BusinessCalendarService $calendar): int
    {
        $currentHour = $quotaService->currentHour();
        $today = $quotaService->today()->toDateString();

        // Day-level gate: skip the whole tick on a blocked day (weekend or
        // blackout date). $quotaService->today() already returns a tz-neutral
        // local midnight matching the quota-timezone calendar date — do NOT
        // run it through setTimezone() again, that would shift it by the
        // UTC/Paris offset and desync it from the hour gate / quota_date above.
        if ($calendar->isBlockedDate($quotaService->today())) {
            $this->info("Auto-discovery tick: skipped — {$today} is a non-working day (weekend or blackout date).");

            return self::SUCCESS;
        }

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
        $skipped = 0;

        foreach ($due as $criteria) {
            try {
                $run = $quotaService->reserveRun($criteria, true);
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
                $markedFailed = DiscoveryRun::failPendingDispatch((int) $run->id, (int) $criteria->id);

                Log::error('[ProspectAutoDiscover] Dispatch failed — run marked failed.', [
                    'criteria_id' => $criteria->id,
                    'run_id' => $run->id,
                    'run_marked_failed' => $markedFailed,
                    'exception_class' => $e::class,
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
