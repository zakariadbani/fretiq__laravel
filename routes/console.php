<?php

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

/*
|--------------------------------------------------------------------------
| Sprint 1 scheduler stub
|--------------------------------------------------------------------------
| Real campaign/sequence commands arrive in Phase 3.
| This placeholder proves scheduler wiring is in place.
| Example cadence the engine will use (no-op placeholder for now):
*/

Schedule::command('inspire')->hourly();

/*
|--------------------------------------------------------------------------
| Campaign dispatch scheduler
|--------------------------------------------------------------------------
| Runs every minute. withoutOverlapping() prevents a second instance from
| starting if the previous invocation is still running (e.g. large list).
*/

Schedule::command('campaigns:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping();

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
    ->withoutOverlapping();

Schedule::command('sequences:process')
    ->everyMinute()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Sprint-5: Campaign stats sync
|--------------------------------------------------------------------------
| Refreshes run statistics from Zoho Campaigns API (for zoho-backed runs)
| or recomputes from campaign_recipients (for local-driver runs).
| Runs every 15 minutes. withoutOverlapping() prevents concurrent syncs.
|
| UNVERIFIED: The Zoho path calls ZohoCampaignsClient::getCampaignReport().
| That API call has not been live-tinker-confirmed. The local path is safe.
*/

Schedule::command('campaign:sync-stats')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
