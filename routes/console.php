<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$automationEnabled = fn (string $job): \Closure => fn (): bool => (bool) Setting::get('automatisation.cron_enabled', true)
    && (bool) Setting::get("automatisation.{$job}", true);

// Independent heartbeat: proves the OS cron reached Laravel even when business automations are paused.
Schedule::call(fn () => Setting::set(
    'observability.scheduler.last_tick_at',
    now()->utc()->toIso8601String(),
))->name('observability:scheduler-heartbeat')->everyMinute();

/*
|--------------------------------------------------------------------------
| Campaign dispatch scheduler
|--------------------------------------------------------------------------
| Runs every minute. withoutOverlapping() prevents a second instance from
| starting if the previous invocation is still running (e.g. large list).
*/

Schedule::command('campaigns:dispatch-due')
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

Schedule::command('campaigns:generate-runs')
    ->everyMinute()
    ->withoutOverlapping()
    ->when($automationEnabled('campaigns_generate_runs'));

Schedule::command('sequences:process')
    ->everyMinute()
    ->withoutOverlapping()
    ->when($automationEnabled('sequences_process'));

Schedule::command('campaigns:sync-sequence-enrollments')
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

Schedule::command('campaign:sync-stats')
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

Schedule::command('discovery:terminalize-stale')
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

Schedule::command('prospect:auto-discover')
    ->hourly()
    ->withoutOverlapping()
    ->when($automationEnabled('prospect_auto_discover'));

Schedule::command('inbox:poll')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when($automationEnabled('inbox_poll'));
