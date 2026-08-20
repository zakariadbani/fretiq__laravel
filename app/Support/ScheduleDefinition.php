<?php

namespace App\Support;

use App\Jobs\Zoho\RecoverZohoBulkTerminalizationsJob;
use App\Jobs\Zoho\RecoverZohoStandardWorkJob;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;

class ScheduleDefinition
{
    /**
     * Single source of truth for the live Laravel schedule. Called both from
     * routes/console.php (real singleton, console/scheduler context) and from
     * QueueObservabilityService (fresh throwaway instance, web context) so the
     * observability "Tâches planifiées" table always reflects reality.
     */
    public static function define(Schedule $schedule): void
    {
        $automationEnabled = self::automationEnabledGate();
        $zohoAutoSyncEnabled = self::zohoAutoSyncEnabledGate();
        $zohoNightlyReconciliationEnabled = self::zohoNightlyReconciliationEnabledGate();

        // Independent heartbeat: proves the OS cron reached Laravel even when business automations are paused.
        $schedule->call(fn () => Setting::set(
            'observability.scheduler.last_tick_at',
            now()->utc()->toIso8601String(),
        ))->name('observability_scheduler_heartbeat')->everyMinute();

        /*
        |--------------------------------------------------------------------------
        | Campaign dispatch scheduler
        |--------------------------------------------------------------------------
        | Runs every minute. withoutOverlapping() prevents a second instance from
        | starting if the previous invocation is still running (e.g. large list).
        */

        $schedule->command('campaigns:dispatch-due')
            ->name('campaigns_dispatch_due')
            ->everyMinute()
            ->withoutOverlapping()
            ->when($automationEnabled('campaigns_dispatch_due'));

        /*
        |--------------------------------------------------------------------------
        | Sprint-3b automation scheduler entries
        |--------------------------------------------------------------------------
        | campaigns:generate-runs  — materialise CampaignRun rows for recurring campaigns.
        | sequences:process        — dispatch SendSequenceStepJob for due enrollments.
        | Both run every minute with withoutOverlapping() so a slow tick never stacks.
        */

        $schedule->command('campaigns:generate-runs')
            ->name('campaigns_generate_runs')
            ->everyMinute()
            ->withoutOverlapping()
            ->when($automationEnabled('campaigns_generate_runs'));

        $schedule->command('sequences:process')
            ->name('sequences_process')
            ->everyMinute()
            ->withoutOverlapping()
            ->when($automationEnabled('sequences_process'));

        $schedule->command('smtp:dispatch-reservations')
            ->name('smtp_dispatch_reservations')
            ->everyMinute()
            ->withoutOverlapping()
            ->when($automationEnabled('smtp_dispatch_reservations'));

        $schedule->command('campaigns:sync-sequence-enrollments')
            ->name('campaigns_sync_sequence_enrollments')
            ->everyMinute()
            ->withoutOverlapping()
            ->when($automationEnabled('campaigns_sync_sequence_enrollments'));

        /*
        |--------------------------------------------------------------------------
        | Sprint-5: Campaign stats sync
        |--------------------------------------------------------------------------
        | Refreshes run statistics from Zoho Campaigns API (for zoho-backed runs)
        | or recomputes from campaign_recipients (for local-driver runs).
        | Runs hourly. withoutOverlapping() prevents concurrent syncs.
        |
        | UNVERIFIED: The Zoho path calls ZohoCampaignsClient::getCampaignReport().
        | That API call has not been live-tinker-confirmed. The local path is safe.
        */

        $schedule->command('campaign:sync-stats')
            ->name('campaign_sync_stats')
            ->hourly()
            ->withoutOverlapping()
            ->when($automationEnabled('campaign_sync_stats'));

        /*
        |--------------------------------------------------------------------------
        | Discovery stale-run terminalizer
        |--------------------------------------------------------------------------
        | Flips zombie discovery runs (pending/running but stale) to failed and
        | releases their unconsumed credit reservations. Runs every minute.
        | withoutOverlapping() prevents a second instance if the previous tick
        | is still iterating over a large backlog.
        */

        $schedule->command('discovery:terminalize-stale')
            ->name('discovery_terminalize_stale')
            ->everyMinute()
            ->withoutOverlapping()
            ->when($automationEnabled('discovery_terminalize_stale'));

        /*
        |--------------------------------------------------------------------------
        | Per-criteria auto-discovery scheduler
        |--------------------------------------------------------------------------
        | Dispatches scheduled discovery runs for criteria with auto_run=true whose
        | run_at_hour (Europe/Paris) is due. Runs hourly. withoutOverlapping()
        | prevents a slow tick from stacking with the next hour's. Idempotency is
        | handled by the same-day discovery run guard in prospect:auto-discover.
        */

        $schedule->command('prospect:auto-discover')
            ->name('prospect_auto_discover')
            ->hourly()
            ->withoutOverlapping()
            ->when($automationEnabled('prospect_auto_discover'));

        $schedule->command('inbox:poll')
            ->name('inbox_poll')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->when($automationEnabled('inbox_poll'));

        /*
        |--------------------------------------------------------------------------
        | Zoho CRM V2 mirror scheduler (disabled by default)
        |--------------------------------------------------------------------------
        | The delta cadence comes from Paramètres > Zoho and defaults to hourly at
        | :10. Nightly reconciliation runs independently at 02:30 Europe/Paris.
        | Both remain disabled by default and honor the global automation switch.
        */
        ZohoSyncFrequency::apply(
            $schedule->command('zoho:crm:sync --mode=delta --trigger=scheduled')
                ->name('zoho_crm_sync_delta'),
            Setting::get('zoho.sync_frequency', 'hourly')
        )
            ->timezone((string) config('zoho-v2.reporting_timezone', 'Europe/Paris'))
            ->withoutOverlapping()
            ->onOneServer()
            ->when($zohoAutoSyncEnabled);

        $schedule->command('zoho:crm:sync --mode=reconcile --trigger=scheduled')
            ->name('zoho_crm_sync_reconcile')
            ->dailyAt('02:30')
            ->timezone((string) config('zoho-v2.reporting_timezone', 'Europe/Paris'))
            ->withoutOverlapping()
            ->onOneServer()
            ->when($zohoNightlyReconciliationEnabled);

        $schedule->job(new RecoverZohoBulkTerminalizationsJob)
            ->name('zoho_bulk_terminalization_recovery')
            ->everyFiveMinutes()
            ->timezone((string) config('zoho-v2.reporting_timezone', 'Europe/Paris'))
            ->withoutOverlapping()
            ->onOneServer()
            ->when(fn (): bool => (bool) Setting::get('automatisation.cron_enabled', true));

        // The checkpoint and post-reconciliation rows are durable outboxes. This
        // sweep closes the queue-dispatch crash window without bypassing the global
        // automation switch or the per-module lease fence.
        $schedule->job(new RecoverZohoStandardWorkJob)
            ->name('zoho_standard_work_recovery')
            ->everyFiveMinutes()
            ->timezone((string) config('zoho-v2.reporting_timezone', 'Europe/Paris'))
            ->withoutOverlapping()
            ->onOneServer()
            ->when(fn (): bool => (bool) Setting::get('automatisation.cron_enabled', true));

        // Failure retry, metadata inventory, and deterministic identity linking are
        // dispatched from successful/partial reconciliation batch completion. They
        // are intentionally not clock-based because the 02:30 batch may run for
        // several hours while Quotes are swept in resumable chunks.
    }

    private static function automationEnabledGate(): \Closure
    {
        return fn (string $job): \Closure => fn (): bool => (bool) Setting::get('automatisation.cron_enabled', true)
            && (bool) Setting::get("automatisation.{$job}", true);
    }

    private static function zohoAutoSyncEnabledGate(): \Closure
    {
        return fn (): bool => (bool) Setting::get('automatisation.cron_enabled', true)
            && (bool) Setting::get('zoho.auto_sync_enabled', false);
    }

    private static function zohoNightlyReconciliationEnabledGate(): \Closure
    {
        return fn (): bool => (bool) Setting::get('automatisation.cron_enabled', true)
            && (bool) Setting::get('zoho.nightly_reconciliation_enabled', false);
    }
}
