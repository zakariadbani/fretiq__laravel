<?php

namespace App\Services\Analytics;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Setting;
use App\Support\ScheduleDefinition;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueObservabilityService
{
    /** Keys that render pause/resume + run-now controls (gated by automatisation.{key}). */
    private const CONTROLLABLE = [
        'campaigns_dispatch_due',
        'campaigns_generate_runs',
        'sequences_process',
        'smtp_dispatch_reservations',
        'campaigns_sync_sequence_enrollments',
        'campaign_sync_stats',
        'discovery_terminalize_stale',
        'prospect_auto_discover',
        'inbox_poll',
    ];

    /** Keys that can be invoked via Artisan::call() from "Exécuter maintenant". */
    private const RUNNABLE = self::CONTROLLABLE;

    /** Artisan command name for each runnable key. */
    private const RUN_COMMANDS = [
        'campaigns_dispatch_due' => 'campaigns:dispatch-due',
        'campaigns_generate_runs' => 'campaigns:generate-runs',
        'sequences_process' => 'sequences:process',
        'smtp_dispatch_reservations' => 'smtp:dispatch-reservations',
        'campaigns_sync_sequence_enrollments' => 'campaigns:sync-sequence-enrollments',
        'campaign_sync_stats' => 'campaign:sync-stats',
        'discovery_terminalize_stale' => 'discovery:terminalize-stale',
        'prospect_auto_discover' => 'prospect:auto-discover',
        'inbox_poll' => 'inbox:poll',
    ];

    /** French description shown on the observability page, per display key. Excludes the heartbeat (not a display row). */
    private const LABELS = [
        'campaigns_dispatch_due' => 'Place les campagnes arrivées à échéance dans la file d’envoi.',
        'campaigns_generate_runs' => 'Crée les exécutions dues pour les campagnes récurrentes.',
        'sequences_process' => 'Récupère les vagues Zoho dues et met en file les étapes SMTP dues pour le worker campagnes.',
        'smtp_dispatch_reservations' => 'Traite les réservations d’envoi SMTP en attente.',
        'campaigns_sync_sequence_enrollments' => 'Inscrit les nouveaux contacts éligibles dans les campagnes séquentielles.',
        'campaign_sync_stats' => 'Met à jour les statistiques des campagnes envoyées.',
        'discovery_terminalize_stale' => 'Clôture les découvertes bloquées et libère leurs crédits réservés.',
        'prospect_auto_discover' => 'Lance les découvertes automatiques dues selon les critères configurés.',
        'inbox_poll' => 'Relève les boîtes IMAP actives et importe les nouvelles réponses.',
        'zoho_crm_sync_delta' => 'Synchronise le miroir Zoho CRM (delta).',
        'zoho_crm_sync_reconcile' => 'Réconciliation nocturne du miroir Zoho CRM.',
        'zoho_bulk_terminalization_recovery' => 'Relance la récupération des terminalisations Zoho en masse.',
        'zoho_standard_work_recovery' => 'Relance les outbox de réconciliation Zoho.',
    ];

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
        return collect($this->scheduleEvents())
            ->filter(fn (Event $event): bool => is_string($event->description) && $event->description !== '' && $event->description !== 'observability_scheduler_heartbeat')
            ->map(function (Event $event): array {
                $key = $event->description;
                $command = is_string($event->command) ? trim($event->command) : null;
                $expression = $event->getExpression();

                return [
                    'key' => $key,
                    'command' => $command,
                    'description' => self::LABELS[$key] ?? $command ?? $key,
                    'expression' => $expression,
                    'frequency' => $this->frequencyLabel($expression),
                    'enabled' => (bool) Setting::get("automatisation.{$key}", true),
                    'effective_enabled' => $event->filtersPass(app()),
                    'controllable' => in_array($key, self::CONTROLLABLE, true),
                    'runnable' => in_array($key, self::RUNNABLE, true),
                    'next_run_at' => Carbon::instance($event->nextRunDate())->setTimezone('Europe/Paris'),
                    'last_finished_at' => $this->parseDate(Setting::get("observability.tasks.{$key}.last_finished_at")),
                    'last_result' => Setting::get("observability.tasks.{$key}.last_result"),
                ];
            })
            ->values();
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
        if (! in_array($key, self::CONTROLLABLE, true)) {
            return false;
        }
        Setting::set("automatisation.{$key}", $enabled);

        return true;
    }

    public function runTask(string $key): ?int
    {
        if (! in_array($key, self::RUNNABLE, true)) {
            return null;
        }
        Setting::set("observability.tasks.{$key}.last_started_at", now()->utc()->toIso8601String());
        $exitCode = Artisan::call(self::RUN_COMMANDS[$key]);
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
        $key = self::taskKey($event->description);

        if ($key === null) {
            return;
        }

        Setting::set("observability.tasks.{$key}.last_finished_at", now()->utc()->toIso8601String());
        Setting::set("observability.tasks.{$key}.last_result", $result);
    }

    private static function taskKey(?string $description): ?string
    {
        if (! is_string($description) || $description === '') {
            return null;
        }

        // Scoped to the known display-key set: excludes the heartbeat (fires every
        // minute) and any unnamed/unknown event, so recordScheduledResult() only
        // ever writes settings for tasks actually shown on the observability page.
        return isset(self::LABELS[$description]) ? $description : null;
    }

    /** @return array<int, Event> */
    private function scheduleEvents(): array
    {
        // A fresh instance, not the app(Schedule::class) singleton: the singleton
        // is only populated when routes/console.php runs during console/scheduler
        // bootstrap, and stays empty for a plain HTTP request.
        $schedule = new Schedule();
        ScheduleDefinition::define($schedule);

        return $schedule->events();
    }

    private function frequencyLabel(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => 'Toutes les minutes',
            '*/5 * * * *' => 'Toutes les 5 minutes',
            '*/15 * * * *' => 'Toutes les 15 minutes',
            '0 * * * *' => 'Toutes les heures',
            '10 * * * *' => 'Chaque heure à :10',
            '30 2 * * *' => 'Chaque jour à 02:30',
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
