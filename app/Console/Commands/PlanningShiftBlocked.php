<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\SequenceEnrollment;
use App\Services\Scheduling\BusinessCalendarService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * PlanningShiftBlocked — one-time cosmetic backfill for pre-existing rows that
 * were scheduled before BusinessCalendarService (weekend/blackout skipping)
 * was wired into the scheduling paths.
 *
 * Modeled line-for-line on ReclassifyContactEmailKind: --dry-run preview,
 * chunkById(500) iteration, skip-if-unchanged guard, a $this->table() tally,
 * and the "DRY RUN — remove --dry-run to apply" footer.
 *
 * WHY A COMMAND, NOT A MIGRATION: a data migration re-runs on every fresh
 * `migrate` forever, and its down() cannot honestly restore the original
 * timestamps. This is an operator-triggered, idempotent, re-runnable tool —
 * exactly what an artisan command is for.
 *
 * WHY THIS IS COSMETIC, NOT LOAD-BEARING: CampaignService::dispatchDue() and
 * SequenceService::processDue() (see plan §4) already hold-gate any row that
 * is still sitting on a blocked day, so nothing actually fires on a weekend
 * even before this command runs. All this command fixes is the *display* of
 * stale rows on the planner calendar (the exact symptom originally reported)
 * — hence "shift-blocked", not "fix-blocked".
 *
 * SCOPE — three tables, three columns, deliberately narrow:
 *
 *   campaigns.next_run_at        WHERE is_active = 1
 *                                   AND next_run_at IS NOT NULL
 *                                   AND schedule_type IN ('recurring','paced','sequence')
 *     tz: $campaign->scheduleTimezone() — never null, the model defaults it
 *     to 'Europe/Paris' on save when blank.
 *
 *   campaign_runs.run_at         WHERE status IN ('scheduled','prepared')
 *                                   AND run_at >= now()
 *     tz: the parent campaign's timezone (via resolveTimezone(), which
 *     falls back to decouverte.timezone for an orphaned row — campaign_id
 *     null, or a missing parent — rather than crashing).
 *
 *   sequence_enrollments.next_send_at   WHERE status = 'active'
 *                                          AND next_send_at IS NOT NULL
 *     tz: same resolveTimezone() fallback chain as above.
 *
 * STATUS CHOICES — justified, not widened:
 *   - 'scheduled' and 'prepared' are the ONLY campaign_runs statuses that can
 *     still fire: CampaignService::dispatchDue() selects only 'scheduled';
 *     SequenceWaveService::recover() selects ['prepared','scheduled','sending'].
 *   - 'sending' is EXCLUDED on purpose — a run that is mid-flight must never
 *     have its run_at moved out from under it while a worker is reading it.
 *   - 'sent' / 'failed' / 'canceled' are EXCLUDED on purpose — that is
 *     history. Rewriting a completed run's run_at falsifies the audit trail
 *     and every KPI query that joins on it.
 *   - the `run_at >= now()` filter is a second, independent guard against
 *     ever touching a past run, on top of the status filter.
 *
 * DO NOT TOUCH (out of scope, deliberately):
 *   - campaigns.scheduled_at — the one-shot send date, hand-picked by the
 *     user. Silently moving it would be presumptuous.
 *   - prospect_criteria.run_at_hour — an hour-of-day integer, not a date;
 *     BusinessCalendarService has nothing to say about it.
 *
 * CORRECTNESS — never a single bulk SQL UPDATE:
 *   Every row is iterated via chunkById(500), its timezone resolved
 *   PER ROW, shifted with BusinessCalendarService::shiftToAllowed()
 *   (setTimezone → addDay() in local wall time → ->utc(), never
 *   addHours(24), which drifts across a DST boundary), and skipped
 *   when the result is the SAME instant (Carbon::equalTo(), not ==).
 *   That skip-if-unchanged guard is what makes re-running this command
 *   after the admin adds a new holiday a no-op for every row already
 *   shifted — idempotent by construction, not by accident.
 *
 * campaign_runs and sequence_enrollments eager-load their `campaign`
 * relation per chunk (->with('campaign')) so resolving 500 timezones costs
 * one extra query per chunk, not 500.
 *
 * REQUIRES EXPLICIT USER GO before running against a real database. This
 * command is written and tested (against the test database only) but is
 * NOT executed as part of the build that introduced it.
 *
 * Signature: planning:shift-blocked {--dry-run} {--only=all|campaigns|runs|enrollments}
 */
class PlanningShiftBlocked extends Command
{
    private const CHUNK_SIZE = 500;

    private const VALID_ONLY = ['all', 'campaigns', 'runs', 'enrollments'];

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'planning:shift-blocked
                            {--dry-run : Preview changes without writing to the database}
                            {--only=all : Restrict to one table: all|campaigns|runs|enrollments}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Décale next_run_at / run_at / next_send_at des lignes existantes tombant sur un jour non ouvré (week-end ou date noire configurée) vers le prochain jour autorisé.";

    /**
     * Execute the command.
     */
    public function handle(BusinessCalendarService $calendar): int
    {
        $only = (string) $this->option('only');

        if (! in_array($only, self::VALID_ONLY, true)) {
            $this->error("Option --only invalide : « {$only} ». Valeurs acceptées : all, campaigns, runs, enrollments.");

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no writes');
        }

        $rows = [];

        if ($only === 'all' || $only === 'campaigns') {
            $rows[] = $this->shiftCampaigns($calendar, $dryRun);
        }

        if ($only === 'all' || $only === 'runs') {
            $rows[] = $this->shiftCampaignRuns($calendar, $dryRun);
        }

        if ($only === 'all' || $only === 'enrollments') {
            $rows[] = $this->shiftEnrollments($calendar, $dryRun);
        }

        $this->table(['Table', 'Examined', 'Shifted', 'Unchanged'], $rows);

        if ($dryRun) {
            $this->warn('DRY RUN — no writes performed. Remove --dry-run to apply.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int}
     */
    private function shiftCampaigns(BusinessCalendarService $calendar, bool $dryRun): array
    {
        $examined = 0;
        $shifted = 0;

        Campaign::query()
            ->where('is_active', 1)
            ->whereNotNull('next_run_at')
            ->whereIn('schedule_type', ['recurring', 'paced', 'sequence'])
            ->chunkById(self::CHUNK_SIZE, function ($campaigns) use ($dryRun, &$examined, &$shifted, $calendar) {
                foreach ($campaigns as $campaign) {
                    $examined++;

                    $tz = $campaign->scheduleTimezone();
                    $shiftedInstant = $calendar->shiftToAllowed($campaign->next_run_at, $tz);

                    if ($shiftedInstant->equalTo($campaign->next_run_at)) {
                        continue;
                    }

                    $shifted++;

                    if (! $dryRun) {
                        $campaign->next_run_at = $shiftedInstant;
                        $campaign->save();
                    }
                }
            });

        return ['campaigns.next_run_at', $examined, $shifted, $examined - $shifted];
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int}
     */
    private function shiftCampaignRuns(BusinessCalendarService $calendar, bool $dryRun): array
    {
        $examined = 0;
        $shifted = 0;
        $now = Carbon::now();

        CampaignRun::query()
            ->with('campaign')
            ->whereIn('status', ['scheduled', 'prepared'])
            ->where('run_at', '>=', $now)
            ->chunkById(self::CHUNK_SIZE, function ($runs) use ($dryRun, &$examined, &$shifted, $calendar) {
                foreach ($runs as $run) {
                    $examined++;

                    $tz = $calendar->resolveTimezone($run->campaign);
                    $shiftedInstant = $calendar->shiftToAllowed($run->run_at, $tz);

                    if ($shiftedInstant->equalTo($run->run_at)) {
                        continue;
                    }

                    $shifted++;

                    if (! $dryRun) {
                        $run->run_at = $shiftedInstant;
                        $run->save();
                    }
                }
            });

        return ['campaign_runs.run_at', $examined, $shifted, $examined - $shifted];
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: int}
     */
    private function shiftEnrollments(BusinessCalendarService $calendar, bool $dryRun): array
    {
        $examined = 0;
        $shifted = 0;

        SequenceEnrollment::query()
            ->with('campaign')
            ->where('status', 'active')
            ->whereNotNull('next_send_at')
            ->chunkById(self::CHUNK_SIZE, function ($enrollments) use ($dryRun, &$examined, &$shifted, $calendar) {
                foreach ($enrollments as $enrollment) {
                    $examined++;

                    $tz = $calendar->resolveTimezone($enrollment->campaign);
                    $shiftedInstant = $calendar->shiftToAllowed($enrollment->next_send_at, $tz);

                    if ($shiftedInstant->equalTo($enrollment->next_send_at)) {
                        continue;
                    }

                    $shifted++;

                    if (! $dryRun) {
                        $enrollment->next_send_at = $shiftedInstant;
                        $enrollment->save();
                    }
                }
            });

        return ['sequence_enrollments.next_send_at', $examined, $shifted, $examined - $shifted];
    }
}
