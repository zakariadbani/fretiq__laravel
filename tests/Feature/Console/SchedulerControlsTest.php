<?php

namespace Tests\Feature\Console;

use App\Models\Setting;
use App\Services\Settings\SettingService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerControlsTest extends TestCase
{
    use RefreshDatabase;

    private const JOBS = [
        'campaigns:dispatch-due' => 'campaigns_dispatch_due',
        'campaigns:generate-runs' => 'campaigns_generate_runs',
        'sequences:process' => 'sequences_process',
        'campaigns:sync-sequence-enrollments' => 'campaigns_sync_sequence_enrollments',
        'campaign:sync-stats' => 'campaign_sync_stats',
        'inbox:poll' => 'inbox_poll',
        'discovery:terminalize-stale' => 'discovery_terminalize_stale',
        'prospect:auto-discover' => 'prospect_auto_discover',
    ];

    protected function tearDown(): void
    {
        app(SettingService::class)->clearCache();

        parent::tearDown();
    }

    public function test_all_business_commands_are_enabled_by_default_and_inspire_is_not_scheduled(): void
    {
        foreach (array_keys(self::JOBS) as $command) {
            $this->assertTrue($this->event($command)->filtersPass($this->app));
        }

        $this->assertNull($this->findEvent('inspire'));
    }

    public function test_campaign_stats_sync_runs_hourly(): void
    {
        $this->assertSame('0 * * * *', $this->event('campaign:sync-stats')->expression);
    }

    public function test_global_switch_disables_every_scheduled_command(): void
    {
        Setting::set('automatisation.cron_enabled', false);

        foreach (array_keys(self::JOBS) as $command) {
            $this->assertFalse($this->event($command)->filtersPass($this->app));
        }
    }

    public function test_each_job_switch_disables_only_its_own_command(): void
    {
        foreach (self::JOBS as $command => $settingKey) {
            Setting::set("automatisation.{$settingKey}", false);

            foreach (array_keys(self::JOBS) as $candidate) {
                $this->assertSame(
                    $candidate !== $command,
                    $this->event($candidate)->filtersPass($this->app),
                    "Unexpected scheduler state for {$candidate} while {$command} is disabled."
                );
            }

            Setting::query()
                ->where('group_name', 'automatisation')
                ->where('setting_key', $settingKey)
                ->delete();
            app(SettingService::class)->clearCache();
        }
    }

    public function test_global_switch_does_not_block_manual_command_execution(): void
    {
        Setting::set('automatisation.cron_enabled', false);

        $this->artisan('prospect:auto-discover')->assertExitCode(0);
    }

    public function test_zoho_delta_scheduler_uses_the_saved_frequency_and_automation_settings(): void
    {
        $delta = $this->event('zoho:crm:sync --mode=delta');
        $reconciliation = $this->event('zoho:crm:sync --mode=reconcile');

        $this->assertSame('10 * * * *', $delta->expression);
        $this->assertSame('Europe/Paris', $delta->timezone);
        $this->assertFalse($delta->filtersPass($this->app));
        $this->assertFalse($reconciliation->filtersPass($this->app));

        Setting::set('zoho.auto_sync_enabled', true);
        $this->assertTrue($delta->filtersPass($this->app));
        $this->assertFalse($reconciliation->filtersPass($this->app));
    }

    public function test_zoho_reconciliation_is_controlled_independently_at_0230(): void
    {
        $reconciliation = $this->event('zoho:crm:sync --mode=reconcile');
        $delta = $this->event('zoho:crm:sync --mode=delta');

        $this->assertSame('30 2 * * *', $reconciliation->expression);
        $this->assertSame('Europe/Paris', $reconciliation->timezone);
        $this->assertFalse($reconciliation->filtersPass($this->app));

        Setting::set('zoho.nightly_reconciliation_enabled', true);
        $this->assertTrue($reconciliation->filtersPass($this->app));
        $this->assertFalse($delta->filtersPass($this->app));
    }

    public function test_global_switch_blocks_all_zoho_schedules_while_recovery_ignores_the_two_zoho_toggles(): void
    {
        $delta = $this->event('zoho:crm:sync --mode=delta');
        $reconciliation = $this->event('zoho:crm:sync --mode=reconcile');
        $standardRecovery = $this->eventByDescription('zoho:v2:standard-work-recovery');
        $bulkRecovery = $this->eventByDescription('zoho:v2:bulk-terminalization-recovery');

        $this->assertSame('Europe/Paris', $standardRecovery->timezone);
        $this->assertSame('Europe/Paris', $bulkRecovery->timezone);
        $this->assertTrue($standardRecovery->filtersPass($this->app));
        $this->assertTrue($bulkRecovery->filtersPass($this->app));

        Setting::set('zoho.auto_sync_enabled', true);
        Setting::set('zoho.nightly_reconciliation_enabled', true);
        Setting::set('automatisation.cron_enabled', false);

        $this->assertFalse($delta->filtersPass($this->app));
        $this->assertFalse($reconciliation->filtersPass($this->app));
        $this->assertFalse($standardRecovery->filtersPass($this->app));
        $this->assertFalse($bulkRecovery->filtersPass($this->app));
    }

    private function event(string $command): Event
    {
        $event = $this->findEvent($command);

        $this->assertNotNull($event, "Scheduled command {$command} was not registered.");

        return $event;
    }

    private function findEvent(string $command): ?Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', $command));
    }

    private function eventByDescription(string $description): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => $event->description === $description);

        $this->assertNotNull($event, "Scheduled event {$description} was not registered.");

        return $event;
    }
}
