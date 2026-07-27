<?php

namespace App\Services\Analytics;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Setting;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueObservabilityService
{
    private const TASKS = [
        'campaigns_dispatch_due' => ['command' => 'campaigns:dispatch-due', 'expression' => '* * * * *'],
        'campaigns_generate_runs' => ['command' => 'campaigns:generate-runs', 'expression' => '* * * * *'],
        'sequences_process' => ['command' => 'sequences:process', 'expression' => '* * * * *'],
        'campaigns_sync_sequence_enrollments' => ['command' => 'campaigns:sync-sequence-enrollments', 'expression' => '* * * * *'],
        'campaign_sync_stats' => ['command' => 'campaign:sync-stats', 'expression' => '*/15 * * * *'],
        'discovery_terminalize_stale' => ['command' => 'discovery:terminalize-stale', 'expression' => '* * * * *'],
        'prospect_auto_discover' => ['command' => 'prospect:auto-discover', 'expression' => '0 * * * *'],
    ];

    public function __construct(private readonly Schedule $schedule) {}

    /** @return Collection<int, object> */
    public function activeJobs(int $limit = 50): Collection
    {
        if (config('queue.default') !== 'database') {
            return collect();
        }

        $now = now()->timestamp;

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->latest('id')->limit($limit)
            ->get(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])
            ->map(function (object $row) use ($now): object {
                $payload = json_decode((string) $row->payload, true);
                $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;
                $row->name = is_string($displayName) && $displayName !== '' ? class_basename($displayName) : "Job #{$row->id}";
                $row->state = $row->reserved_at !== null ? 'reserved' : ((int) $row->available_at > $now ? 'delayed' : 'waiting');
                $row->created_at_date = Carbon::createFromTimestamp((int) $row->created_at);
                $row->available_at_date = Carbon::createFromTimestamp((int) $row->available_at);

                return $row;
            });
    }

    /** @return Collection<int, object> */
    public function failedJobs(int $limit = 50): Collection
    {
        return DB::table(config('queue.failed.table', 'failed_jobs'))
            ->latest('failed_at')->limit($limit)
            ->get(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(function (object $row): object {
                $payload = json_decode((string) $row->payload, true);
                $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;
                $row->name = is_string($displayName) && $displayName !== '' ? class_basename($displayName) : "Job échoué #{$row->id}";
                $row->exception = $this->firstLine((string) ($row->exception ?? ''));

                return $row;
            });
    }

    /** @return Collection<int, CampaignRun> */
    public function failedRuns(): Collection
    {
        return CampaignRun::where('status', 'failed')->with('campaign')->orderByDesc('run_at')->get();
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $jobsTable = config('queue.connections.database.table', 'jobs');
        $databaseQueue = config('queue.default') === 'database';

        return [
            'waiting_jobs' => $databaseQueue ? DB::table($jobsTable)->whereNull('reserved_at')->count() : 0,
            'reserved_jobs' => $databaseQueue ? DB::table($jobsTable)->whereNotNull('reserved_at')->count() : 0,
            'failed_jobs' => DB::table(config('queue.failed.table', 'failed_jobs'))->count(),
            'failed_runs' => CampaignRun::where('status', 'failed')->count(),
            'queued_recipients' => CampaignRecipient::where('status', 'queued')->count(),
            'scheduled_runs' => CampaignRun::where('status', 'scheduled')->count(),
        ];
    }

    /** @return array{status:string,last_tick_at:?Carbon,cron_enabled:bool} */
    public function schedulerHealth(): array
    {
        $lastTickAt = $this->parseDate(Setting::get('observability.scheduler.last_tick_at'));

        return [
            'status' => $lastTickAt === null ? 'missing' : ($lastTickAt->lt(now()->subMinutes(2)) ? 'stale' : 'healthy'),
            'last_tick_at' => $lastTickAt,
            'cron_enabled' => (bool) Setting::get('automatisation.cron_enabled', true),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function scheduledTasks(): Collection
    {
        $globalEnabled = (bool) Setting::get('automatisation.cron_enabled', true);

        return collect($this->scheduledEventMap())->map(function (array $task) use ($globalEnabled): array {
            $key = $task['key'];
            $enabled = (bool) Setting::get("automatisation.{$key}", true);

            $expression = $task['event']?->getExpression() ?? self::TASKS[$key]['expression'];
            $nextRunAt = $task['event']?->nextRunDate()
                ?? Carbon::instance((new CronExpression($expression))->getNextRunDate());

            return [
                'key' => $key,
                'command' => $task['command'],
                'expression' => $expression,
                'frequency' => $this->frequencyLabel($expression),
                'enabled' => $enabled,
                'effective_enabled' => $globalEnabled && $enabled,
                'next_run_at' => $nextRunAt->setTimezone('Europe/Paris'),
                'last_finished_at' => $this->parseDate(Setting::get("observability.tasks.{$key}.last_finished_at")),
                'last_result' => Setting::get("observability.tasks.{$key}.last_result"),
            ];
        })->values();
    }

    public function cancelJob(int $id): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        return DB::table(config('queue.connections.database.table', 'jobs'))->where('id', $id)->whereNull('reserved_at')->delete() === 1;
    }

    public function retryFailedJob(string $uuid): bool
    {
        if (app('queue.failer')->find($uuid) === null) {
            return false;
        }
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return true;
    }

    public function retryAllFailedJobs(): int
    {
        $count = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        if ($count > 0) {
            Artisan::call('queue:retry', ['id' => ['all']]);
        }

        return $count;
    }

    public function forgetFailedJob(string $uuid): bool
    {
        return app('queue.failer')->forget($uuid);
    }

    public function setCronEnabled(bool $enabled): void
    {
        Setting::set('automatisation.cron_enabled', $enabled);
    }

    public function setTaskEnabled(string $key, bool $enabled): bool
    {
        if (! isset($this->scheduledEventMap()[$key])) {
            return false;
        }
        Setting::set("automatisation.{$key}", $enabled);

        return true;
    }

    public function runTask(string $key): ?int
    {
        $task = $this->scheduledEventMap()[$key] ?? null;
        if ($task === null) {
            return null;
        }
        Setting::set("observability.tasks.{$key}.last_started_at", now()->utc()->toIso8601String());
        $exitCode = Artisan::call($task['command']);
        Setting::set("observability.tasks.{$key}.last_finished_at", now()->utc()->toIso8601String());
        Setting::set("observability.tasks.{$key}.last_result", $exitCode === 0 ? 'success' : 'failed');

        return $exitCode;
    }

    public function taskDispatchesMail(string $key): bool
    {
        return in_array($key, ['campaigns_dispatch_due', 'sequences_process'], true);
    }

    public static function recordScheduledResult(Event $event, string $result): void
    {
        $key = self::taskKey($event->command);

        if ($key === null) {
            return;
        }

        Setting::set("observability.tasks.{$key}.last_finished_at", now()->utc()->toIso8601String());
        Setting::set("observability.tasks.{$key}.last_result", $result);
    }

    private static function taskKey(?string $command): ?string
    {
        if (! is_string($command) || ! preg_match('/artisan["\']?\s+([^\s"\']+)/', $command, $matches)) {
            return null;
        }

        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower($matches[1]));

        return isset(self::TASKS[$key]) ? $key : null;
    }

    /** @return array<string, array{key:string,command:string,event:?Event}> */
    private function scheduledEventMap(): array
    {
        $tasks = [];
        foreach ($this->schedule->events() as $event) {
            if (! $event instanceof Event || ! is_string($event->command)) {
                continue;
            }
            $key = self::taskKey($event->command);
            if ($key === null) {
                continue;
            }
            $tasks[$key] = ['key' => $key, 'command' => self::TASKS[$key]['command'], 'event' => $event];
        }

        foreach (self::TASKS as $key => $task) {
            $tasks[$key] ??= ['key' => $key, 'command' => $task['command'], 'event' => null];
        }

        return $tasks;
    }

    private function frequencyLabel(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => 'Toutes les minutes',
            '*/15 * * * *' => 'Toutes les 15 minutes',
            '0 * * * *' => 'Toutes les heures',
            default => $expression,
        };
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone('Europe/Paris');
        } catch (\Throwable) {
            return null;
        }
    }

    private function firstLine(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            if (($line = trim($line)) !== '') {
                return $line;
            }
        }

        return '';
    }
}
