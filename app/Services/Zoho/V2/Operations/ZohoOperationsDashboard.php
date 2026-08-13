<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Operations;

use App\Models\User;
use App\Models\Zoho\ZohoFieldManifest;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\Zoho\ZohoUser;
use App\Models\Zoho\ZohoUserMapping;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Models\ZohoToken;
use App\Services\Zoho\V2\Reconciliation\ZohoDataQualityMetrics;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only, locally sourced presentation queries for the V2 operations screen. */
final class ZohoOperationsDashboard
{
    /** @var array<string,list<string>> */
    private array $columns = [];

    public function __construct(private readonly ZohoModuleRegistry $registry, private readonly ZohoDataQualityMetrics $quality) {}

    public function available(): bool
    {
        $required = [
            'zoho_sync_batches' => [
                'status', 'requested_at', 'mode', 'correlation_id', 'paused_at', 'resumed_at',
                'resume_count', 'pause_reason', 'resume_metadata',
            ],
            'zoho_standard_sync_runs' => ['sync_batch_id', 'module', 'submodule', 'status'],
            'zoho_standard_sync_work_items' => ['zoho_standard_sync_run_id', 'zoho_id', 'status'],
            'zoho_sync_failures' => ['module', 'failure_kind', 'error_summary', 'correlation_id', 'resolved_at'],
            'zoho_field_manifests' => ['module', 'is_current', 'drift_state', 'verified_at'],
            'zoho_user_mappings' => ['zoho_user_id', 'fretiq_user_id', 'is_confirmed', 'is_override'],
            'zoho_sync_checkpoints' => ['module', 'submodule', 'sync_mode', 'status', 'cursor_at', 'cursor_zoho_id', 'cursor_page_token', 'retry_count', 'completed_at'],
            'zoho_sync_logs' => ['module', 'mode', 'sync_batch_id', 'status', 'records_seen', 'records_synced', 'records_created', 'records_updated', 'records_unchanged', 'records_deleted', 'records_quarantined', 'duration_ms', 'api_requests', 'telemetry', 'synced_at'],
        ];
        foreach ($this->registry->all() as $definition) {
            $required[$definition->table] = array_values(array_unique(array_merge($required[$definition->table] ?? [], ['zoho_deleted_at', 'last_synced_at'])));
        }
        $required['zoho_users'] = array_values(array_unique(array_merge($required['zoho_users'] ?? [], ['zoho_id', 'full_name'])));

        foreach ($required as $table => $columns) {
            $available = $this->columns($table);
            if ($available === []) {
                return false;
            }
            foreach ($columns as $column) {
                if (! in_array($column, $available, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** The exact-batch progress endpoint needs only its local aggregate tables. */
    public function progressAvailable(): bool
    {
        $required = [
            'zoho_sync_batches' => ['id', 'trigger', 'mode', 'modules', 'status', 'requested_at', 'started_at', 'paused_at', 'completed_at', 'resume_count', 'updated_at'],
            'zoho_standard_sync_runs' => ['id', 'sync_batch_id', 'module', 'submodule', 'status', 'enumerated_at', 'completed_at', 'updated_at'],
            'zoho_standard_sync_work_items' => ['id', 'zoho_standard_sync_run_id', 'status', 'updated_at'],
            'zoho_sync_checkpoints' => ['sync_batch_id', 'module', 'submodule', 'status', 'heartbeat_at', 'completed_at', 'updated_at'],
            'jobs' => ['queue', 'reserved_at'],
            'failed_jobs' => ['queue'],
        ];

        if (DB::connection()->getDriverName() !== 'mysql') {
            foreach ($required as $table => $columns) {
                if (! Schema::hasColumns($table, $columns)) {
                    return false;
                }
            }

            return true;
        }

        $available = DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->whereIn('table_name', array_keys($required))
            ->get(['table_name as progress_table', 'column_name as progress_column'])
            ->groupBy('progress_table');

        foreach ($required as $table => $columns) {
            $availableColumns = $available->get($table, collect())->pluck('progress_column')->all();
            if (array_diff($columns, $availableColumns) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        $token = ZohoToken::where('service', 'crm')->first();
        $expiry = $token?->expires_at;
        $tokenPresent = $token && trim((string) $token->access_token) !== '';
        $remainingMinutes = $expiry ? (int) now()->diffInMinutes($expiry, false) : null;
        $oauth = ! $tokenPresent ? 'absent' : ($remainingMinutes === null ? 'unknown' : ($remainingMinutes <= 0 ? 'expired' : ($remainingMinutes < 15 ? 'soon' : 'ready')));
        $checkpoints = ZohoSyncCheckpoint::query()->get()->keyBy(fn (ZohoSyncCheckpoint $checkpoint): string => $checkpoint->module.'|'.($checkpoint->submodule ?? ''));
        $checkpointOwnerIds = $checkpoints->pluck('sync_batch_id')->filter(fn ($id): bool => $id !== null)->unique()->values();
        $checkpointOwners = $checkpointOwnerIds->isEmpty()
            ? collect()
            : ZohoSyncBatch::query()->whereKey($checkpointOwnerIds)->get()->keyBy('id');
        $attempts = $this->latestLogs();
        $successes = $this->latestLogs(status: 'success');
        $reconciliations = $this->latestLogs(mode: 'reconcile');
        $currentManifests = ZohoFieldManifest::query()->where('is_current', true)->latest('verified_at')->get();
        $manifestsByModule = collect();
        foreach ($currentManifests as $manifest) {
            if (! $manifestsByModule->has($manifest->module)) {
                $manifestsByModule->put($manifest->module, $manifest);
            }
        }
        $reconciliationFailures = ZohoSyncFailure::query()->where('failure_kind', 'reconciliation')->whereNull('resolved_at')->get(['module', 'correlation_id', 'context', 'resolved_at'])
            ->keyBy(fn (ZohoSyncFailure $failure): string => $failure->module.'|'.$failure->correlation_id);
        $syncAllModuleKeys = array_keys(array_filter(
            $this->registry->all(),
            fn ($definition): bool => ! $definition->activationGated && $definition->key !== 'quoted_items',
        ));
        $activeManualDelta = ZohoSyncBatch::query()
            ->where('trigger', 'manual')
            ->where('mode', 'delta')
            ->whereNull('completed_at')
            ->whereIn('status', ['queued', 'running'])
            ->oldest('requested_at')
            ->oldest('id')
            ->first();
        $pausedSyncAllBatch = ZohoSyncBatch::query()
            ->where('trigger', 'manual')
            ->where('mode', 'delta')
            ->whereNull('completed_at')
            ->where('status', 'paused')
            ->orderByDesc('id')
            ->get()
            ->first(fn (ZohoSyncBatch $candidate): bool => $this->sameModuleSet((array) $candidate->modules, $syncAllModuleKeys));
        $activeIsSyncAll = $activeManualDelta !== null
            && $this->sameModuleSet((array) $activeManualDelta->modules, $syncAllModuleKeys);
        $syncAllBatch = $activeManualDelta ?? $pausedSyncAllBatch;
        $syncAllState = $activeManualDelta !== null ? 'active' : ($pausedSyncAllBatch !== null ? 'paused' : 'idle');
        $modules = [];

        foreach ($this->registry->all() as $key => $definition) {
            $attempt = $attempts->get($key);
            $success = $successes->get($key);
            $reconcile = $reconciliations->get($key);
            $reconciliation = $this->reconciliationEvidence($reconcile, $reconcile ? $reconciliationFailures->get($key.'|'.$reconcile->correlation_id) : null);
            $manifest = $manifestsByModule->get($definition->apiName) ?? $manifestsByModule->get($key);
            $freshness = $this->freshness($success?->synced_at, false);
            $checkpoint = $checkpoints->get('v2:'.$key.'|'.($definition->submodule ?? ''));
            $checkpointOwner = $checkpoint?->sync_batch_id === null ? null : $checkpointOwners->get((int) $checkpoint->sync_batch_id);
            $syncState = $this->moduleSyncState($key, $checkpoint, $checkpointOwner);
            $modules[$key] = [
                'label' => $definition->apiName,
                'attempt' => $attempt,
                'success' => $success,
                'freshness' => $freshness,
                'reconciliation_freshness' => $this->freshness($reconcile?->synced_at, true),
                'quality' => $this->quality->forModule($key),
                'checkpoint' => $checkpoint,
                'reconciliation' => $reconciliation,
                'manifest' => $manifest,
                'active' => ! $definition->activationGated && $key !== 'quoted_items',
                'note' => $definition->activationNote,
                'sync_state' => $syncState,
                'busy' => in_array($syncState, ['busy', 'retrying'], true),
                'paused_owner' => $syncState === 'paused',
            ];
        }

        $counts = $this->mirrorCounts();

        $openFailureCount = ZohoSyncFailure::query()->whereNull('resolved_at')->count();
        $failures = ZohoSyncFailure::query()->whereNull('resolved_at')->latest()->limit(20)
            ->get(['module', 'submodule', 'failure_kind', 'correlation_id', 'attempts', 'retry_after', 'created_at']);
        $overall = $this->overallHealth($oauth, $modules, $failures, $currentManifests);

        return [
            'oauth' => $oauth,
            'tokenExpiry' => $expiry,
            'queue' => $this->queueEvidence(),
            'batch' => ZohoSyncBatch::query()
                ->whereNull('completed_at')
                ->whereIn('status', ['queued', 'running', 'paused'])
                ->orderByRaw("CASE WHEN status IN ('queued', 'running') THEN 0 ELSE 1 END")
                ->oldest('requested_at')
                ->oldest('id')
                ->first(),
            'syncAllBatch' => $syncAllBatch,
            'syncProgress' => $syncAllBatch === null ? null : $this->syncProgress($syncAllBatch),
            'syncAllState' => $syncAllState,
            'syncAllCanPause' => $activeIsSyncAll,
            'overall' => $overall,
            'modules' => $modules,
            'counts' => $counts,
            'failures' => $failures,
            'openFailureCount' => $openFailureCount,
            'manifests' => $currentManifests,
            'identities' => $this->observedOwnerIdentities(),
            'mappings' => ZohoUserMapping::query()->latest()->get(['zoho_user_id', 'fretiq_user_id', 'match_method', 'is_confirmed', 'is_override', 'confirmed_at'])->keyBy('zoho_user_id'),
            'fretiqUsers' => User::query()->where('is_active', true)->role('commercial')->orderBy('name')->get(['id', 'name']),
            'trend' => ZohoSyncLog::query()->whereNotNull('sync_batch_id')->latest('synced_at')->limit(30)
                ->get(['module', 'status', 'duration_ms', 'records_seen', 'records_synced', 'api_requests', 'synced_at']),
        ];
    }

    private function moduleSyncState(string $module, ?ZohoSyncCheckpoint $checkpoint, ?ZohoSyncBatch $owner): string
    {
        if ($checkpoint === null) {
            return 'idle';
        }

        $checkpointStatus = (string) $checkpoint->status;
        $hasOwnerReference = $checkpoint->sync_batch_id !== null;
        if (($hasOwnerReference && $owner === null) || ($owner !== null && ! $this->checkpointOwnershipMatches($module, $checkpoint, $owner))) {
            return 'interrupted';
        }

        $ownerIsLive = $owner !== null
            && $owner->completed_at === null
            && in_array($owner->status, ['queued', 'running', 'paused'], true);
        if ($ownerIsLive) {
            if ($owner->status === 'paused' || $checkpointStatus === 'paused') {
                return 'paused';
            }

            return $checkpointStatus === 'retrying' ? 'retrying' : 'busy';
        }

        if (in_array($checkpointStatus, ['queued', 'running', 'retrying', 'paused'], true)) {
            return 'interrupted';
        }

        return match ($checkpointStatus) {
            'partial', 'failed', 'error' => 'error',
            'completed', 'success' => 'completed',
            '', 'idle' => 'idle',
            default => $checkpointStatus,
        };
    }

    private function checkpointOwnershipMatches(string $module, ZohoSyncCheckpoint $checkpoint, ZohoSyncBatch $owner): bool
    {
        return (int) $checkpoint->sync_batch_id === (int) $owner->id
            && trim((string) $checkpoint->correlation_id) !== ''
            && (string) $checkpoint->correlation_id === (string) $owner->correlation_id
            && (string) $checkpoint->sync_mode === (string) $owner->mode
            && in_array($module, (array) $owner->modules, true);
    }

    /**
     * Safe, aggregate-only durable worklist progress for one exact manual Sync Tout batch.
     *
     * @return array<string,mixed>|null
     */
    public function syncProgress(ZohoSyncBatch $batch): ?array
    {
        $moduleKeys = array_keys(array_filter(
            $this->registry->all(),
            fn ($definition): bool => ! $definition->activationGated && $definition->key !== 'quoted_items',
        ));
        if ($batch->trigger !== 'manual' || $batch->mode !== 'delta' || ! $this->sameModuleSet((array) $batch->modules, $moduleKeys)) {
            return null;
        }

        $runRows = DB::table('zoho_standard_sync_runs as runs')
            ->leftJoin('zoho_standard_sync_work_items as items', 'items.zoho_standard_sync_run_id', '=', 'runs.id')
            ->where('runs.sync_batch_id', $batch->id)
            ->groupBy('runs.id', 'runs.module', 'runs.submodule', 'runs.status', 'runs.enumerated_at', 'runs.completed_at', 'runs.updated_at')
            ->select([
                DB::raw("'run' as progress_row"),
                'runs.module', 'runs.submodule', 'runs.status as run_status', 'runs.enumerated_at',
                'runs.completed_at as run_completed_at', 'runs.updated_at as run_updated_at',
                DB::raw('COUNT(items.id) as discovered'),
                DB::raw("SUM(CASE WHEN items.status = 'completed' THEN 1 ELSE 0 END) as completed"),
                DB::raw("SUM(CASE WHEN items.status = 'queued' THEN 1 ELSE 0 END) as queued"),
                DB::raw("SUM(CASE WHEN items.status = 'processing' THEN 1 ELSE 0 END) as processing"),
                DB::raw("SUM(CASE WHEN items.status = 'quarantined' THEN 1 ELSE 0 END) as quarantined"),
                DB::raw('MAX(items.updated_at) as item_updated_at'),
                DB::raw('NULL as checkpoint_status'), DB::raw('NULL as heartbeat_at'),
                DB::raw('NULL as checkpoint_completed_at'), DB::raw('NULL as checkpoint_updated_at'),
            ]);
        $checkpointRows = DB::table('zoho_sync_checkpoints')
            ->where('sync_batch_id', $batch->id)
            ->select([
                DB::raw("'checkpoint' as progress_row"),
                'module', 'submodule', DB::raw('NULL as run_status'), DB::raw('NULL as enumerated_at'),
                DB::raw('NULL as run_completed_at'), DB::raw('NULL as run_updated_at'),
                DB::raw('0 as discovered'), DB::raw('0 as completed'), DB::raw('0 as queued'),
                DB::raw('0 as processing'), DB::raw('0 as quarantined'), DB::raw('NULL as item_updated_at'),
                'status as checkpoint_status', 'heartbeat_at', 'completed_at as checkpoint_completed_at',
                'updated_at as checkpoint_updated_at',
            ]);
        $progressRows = $runRows->unionAll($checkpointRows)->get();
        $runs = $progressRows->where('progress_row', 'run')
            ->keyBy(fn ($run): string => $run->module.'|'.$run->submodule);
        $checkpoints = $progressRows->where('progress_row', 'checkpoint')
            ->keyBy(fn ($checkpoint): string => str_replace('v2:', '', $checkpoint->module).'|'.$checkpoint->submodule);

        $modules = [];
        $activity = [$batch->updated_at];
        $summary = ['modules_total' => count($moduleKeys), 'modules_completed' => 0, 'discovered' => 0, 'processed' => 0, 'completed' => 0, 'queued' => 0, 'processing' => 0, 'quarantined' => 0];
        $determinate = true;
        foreach ($moduleKeys as $key) {
            $definition = $this->registry->get($key);
            $moduleKey = $key.'|'.($definition->submodule ?? '');
            $run = $runs->get($moduleKey);
            $checkpoint = $checkpoints->get($moduleKey);
            $checkpointStatus = (string) ($checkpoint?->checkpoint_status ?? 'waiting');
            $enumerationComplete = $run !== null
                ? ($run->enumerated_at !== null || in_array($run->run_status, ['completed', 'error'], true))
                : $checkpointStatus === 'completed';
            $phase = $this->syncProgressPhase($run?->run_status, $checkpointStatus, $enumerationComplete);
            if ($batch->status === 'paused' && ! in_array($phase, ['completed', 'error'], true)) {
                $phase = 'paused';
            }
            $completed = (int) ($run?->completed ?? 0);
            $quarantined = (int) ($run?->quarantined ?? 0);
            $processed = $completed + $quarantined;
            $discovered = (int) ($run?->discovered ?? 0);
            $moduleActivity = collect([
                $run?->run_updated_at, $run?->item_updated_at, $run?->run_completed_at,
                $checkpoint?->heartbeat_at, $checkpoint?->checkpoint_updated_at, $checkpoint?->checkpoint_completed_at,
            ])
                ->filter()
                ->map(fn ($at) => \Carbon\CarbonImmutable::parse($at))
                ->max();
            $activity[] = $moduleActivity;
            if (! $enumerationComplete) {
                $determinate = false;
            }
            if ($phase === 'completed') {
                $summary['modules_completed']++;
            }
            foreach (['discovered', 'completed', 'queued', 'processing', 'quarantined'] as $counter) {
                $summary[$counter] += (int) ($counter === 'discovered' ? $discovered : ($counter === 'completed' ? $completed : ($counter === 'quarantined' ? $quarantined : ($run?->{$counter} ?? 0))));
            }
            $summary['processed'] += $processed;
            $modules[$key] = [
                'key' => $key,
                'label' => $definition->apiName,
                'phase' => $phase,
                'checkpoint_status' => $checkpointStatus,
                'enumeration_complete' => $enumerationComplete,
                'discovered' => $discovered,
                'processed' => $processed,
                'completed' => $completed,
                'queued' => (int) ($run?->queued ?? 0),
                'processing' => (int) ($run?->processing ?? 0),
                'quarantined' => $quarantined,
                'percent' => $enumerationComplete && $discovered > 0 ? (int) floor($processed * 100 / $discovered) : null,
                'last_activity_at' => $this->syncProgressTimestamp($moduleActivity),
            ];
        }
        $summary['determinate'] = $determinate;
        $summary['percent'] = $determinate && $summary['discovered'] > 0 ? (int) floor($summary['processed'] * 100 / $summary['discovered']) : null;
        $lastActivity = collect($activity)->filter()->map(fn ($at) => \Carbon\CarbonImmutable::parse($at))->max();
        $stalledAfter = max((int) config('zoho-v2.module.delivery_timeout_seconds', 1200), (int) config('queue.connections.zoho.retry_after', 1260));
        $terminal = $batch->completed_at !== null || ! in_array($batch->status, ['queued', 'running', 'paused'], true);

        return [
            'batch' => [
                'id' => $batch->id, 'status' => $batch->status, 'terminal' => $terminal, 'resume_count' => (int) $batch->resume_count,
                'requested_at' => $this->syncProgressTimestamp($batch->requested_at), 'started_at' => $this->syncProgressTimestamp($batch->started_at),
                'paused_at' => $this->syncProgressTimestamp($batch->paused_at), 'completed_at' => $this->syncProgressTimestamp($batch->completed_at),
            ],
            'summary' => $summary,
            'modules' => $modules,
            'queue' => $this->syncProgressQueueEvidence(),
            'last_activity_at' => $this->syncProgressTimestamp($lastActivity),
            'stalled' => ! $terminal && $batch->status !== 'paused' && ($lastActivity === null || $lastActivity->lessThanOrEqualTo(now()->subSeconds($stalledAfter))),
            'stalled_after_seconds' => $stalledAfter,
            'poll_after_ms' => $batch->status === 'paused' ? 15000 : 5000,
            'observed_at' => now()->toIso8601String(),
        ];
    }

    private function syncProgressPhase(?string $runStatus, string $checkpointStatus, bool $enumerationComplete): string
    {
        $authoritativeCheckpointPhase = match ($checkpointStatus) {
            'paused' => 'paused',
            'retrying' => 'retrying',
            'completed' => 'completed',
            'partial', 'failed', 'error' => 'error',
            default => null,
        };
        if ($authoritativeCheckpointPhase !== null) {
            return $authoritativeCheckpointPhase;
        }

        return match ($runStatus ?? $checkpointStatus) {
            'queued', 'waiting', 'idle' => 'waiting',
            'enumerating' => 'enumerating',
            'hydrating', 'running' => $enumerationComplete ? 'hydrating' : 'enumerating',
            'retrying' => 'retrying',
            'paused' => 'paused',
            'completed', 'success' => 'completed',
            default => 'error',
        };
    }

    private function syncProgressTimestamp($at): ?string
    {
        return $at === null ? null : \Carbon\CarbonImmutable::parse($at)->toIso8601String();
    }

    /** @return array{waiting:int,reserved:int,failed:int} */
    private function syncProgressQueueEvidence(): array
    {
        $queue = (string) config('zoho-v2.queue', 'zoho');
        $jobs = DB::table('jobs')
            ->where('queue', $queue)
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) as waiting')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved')
            ->selectRaw('(SELECT COUNT(*) FROM failed_jobs WHERE queue = ?) as failed', [$queue])
            ->first();

        return [
            'waiting' => (int) ($jobs?->waiting ?? 0),
            'reserved' => (int) ($jobs?->reserved ?? 0),
            'failed' => (int) ($jobs?->failed ?? 0),
        ];
    }

    private function latestLogs(?string $status = null, ?string $mode = null): Collection
    {
        $ids = DB::table('zoho_sync_logs')->selectRaw('MAX(id)')->whereNotNull('sync_batch_id');
        if ($status !== null) {
            $ids->where('status', $status);
        }
        if ($mode !== null) {
            $ids->where('mode', $mode);
        }
        $ids->groupBy('module');

        return ZohoSyncLog::query()->whereIn('id', $ids)->get()->keyBy('module');
    }

    /** @return array<string,int|string|bool|null> */
    private function reconciliationEvidence($log, ?ZohoSyncFailure $failure): array
    {
        $telemetry = is_array($log?->telemetry) ? (array) ($log->telemetry['reconciliation'] ?? []) : [];
        $sameRunFailure = $log !== null
            && $failure !== null
            && $failure->resolved_at === null
            && filled($log->correlation_id)
            && hash_equals((string) $log->correlation_id, (string) $failure->correlation_id);
        $context = $sameRunFailure && is_array($failure->context) ? $failure->context : [];
        $counter = static function (string $key) use ($telemetry, $context): ?int {
            $value = array_key_exists($key, $telemetry) ? $telemetry[$key] : ($context[$key] ?? null);

            return is_numeric($value) ? max(0, (int) $value) : null;
        };
        $telemetryStatus = is_string($telemetry['status'] ?? null) && in_array($telemetry['status'], ['healthy', 'degraded'], true)
            ? $telemetry['status']
            : null;

        return [
            'status' => ! $log ? 'non disponible' : ($sameRunFailure ? 'degraded' : ($telemetryStatus ?? $log->status)),
            'remote' => $counter('remote_count'),
            'local' => $counter('local_count'),
            'missing' => $counter('missing_count'),
            'extra' => $counter('extra_count'),
            'complete' => array_key_exists('complete', $telemetry) && is_bool($telemetry['complete']) ? $telemetry['complete'] : null,
            'pages' => $counter('pages'),
            'http_status' => $counter('http_status'),
            'tombstoned' => $counter('tombstoned'),
        ];
    }

    /** @return array<string,array{active:int,tombstoned:int}> */
    private function mirrorCounts(): array
    {
        $counts = [];
        foreach (collect($this->registry->all())->groupBy('table') as $definitions) {
            $first = $definitions->first();
            if ($first->table === 'zoho_activities') {
                $rows = DB::table($first->table)->select('activity_type')
                    ->selectRaw('SUM(CASE WHEN zoho_deleted_at IS NULL THEN 1 ELSE 0 END) AS active_count')
                    ->selectRaw('SUM(CASE WHEN zoho_deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS tombstoned_count')
                    ->groupBy('activity_type')->get()->keyBy('activity_type');
                foreach ($definitions as $definition) {
                    $row = $rows->get($definition->activityType);
                    $counts[$definition->key] = ['active' => (int) ($row?->active_count ?? 0), 'tombstoned' => (int) ($row?->tombstoned_count ?? 0)];
                }

                continue;
            }
            $row = DB::table($first->table)
                ->selectRaw('SUM(CASE WHEN zoho_deleted_at IS NULL THEN 1 ELSE 0 END) AS active_count')
                ->selectRaw('SUM(CASE WHEN zoho_deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS tombstoned_count')->first();
            foreach ($definitions as $definition) {
                $counts[$definition->key] = ['active' => (int) ($row?->active_count ?? 0), 'tombstoned' => (int) ($row?->tombstoned_count ?? 0)];
            }
        }

        return $counts;
    }

    /** @return Collection<int,array{zoho_id:string,label:?string}> */
    public function observedOwnerIdentities(): Collection
    {
        $ids = ZohoUserMapping::query()->pluck('zoho_user_id')->filter()->map(fn ($id) => (string) $id);
        $owners = null;
        foreach (collect($this->registry->all())->unique('table') as $definition) {
            if (! in_array('owner_zoho_id', $this->columns($definition->table), true)) {
                continue;
            }
            $query = DB::table($definition->table)->selectRaw('owner_zoho_id AS zoho_id')->whereNull('zoho_deleted_at')->whereNotNull('owner_zoho_id');
            $owners = $owners === null ? $query : $owners->union($query);
        }
        if ($owners !== null) {
            $ids = $ids->merge($owners->pluck('zoho_id')->map(fn ($id) => (string) $id));
        }
        $ids = $ids->filter()->unique()->sort()->values();
        $labels = ZohoUser::current()->whereIn('zoho_id', $ids)->pluck('full_name', 'zoho_id');

        return $ids->map(fn (string $id): array => ['zoho_id' => $id, 'label' => $labels->get($id)]);
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        if (array_key_exists($table, $this->columns)) {
            return $this->columns[$table];
        }

        return $this->columns[$table] = Schema::getColumnListing($table);
    }

    /** @return array{state:string,waiting:int,reserved:int,failed:int} */
    private function queueEvidence(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('queue', (string) config('zoho-v2.queue', 'zoho'))->count() : 0;
        if (! Schema::hasTable('jobs')) {
            return ['state' => 'inconnu', 'waiting' => 0, 'reserved' => 0, 'failed' => $failed];
        }
        $jobs = DB::table('jobs')->where('queue', (string) config('zoho-v2.queue', 'zoho'));

        return ['state' => 'observé', 'waiting' => (clone $jobs)->whereNull('reserved_at')->count(), 'reserved' => (clone $jobs)->whereNotNull('reserved_at')->count(), 'failed' => $failed];
    }

    /** @param array<string,array<string,mixed>> $modules */
    private function overallHealth(string $oauth, array $modules, Collection $failures, Collection $manifests): array
    {
        $oldest = collect($modules)->pluck('freshness.minutes')->filter(fn ($value) => $value !== null)->max();
        $critical = collect($modules)->contains(fn ($module) => in_array($module['freshness']['color'], ['warning', 'danger'], true)
            || in_array($module['reconciliation_freshness']['color'], ['warning', 'danger'], true)
            || ($module['active'] && $module['attempt'] !== null && $module['attempt']->status !== 'success')
            || ($module['active'] && in_array($module['reconciliation']['status'], ['partial', 'error', 'degraded'], true)));
        $notStarted = collect($modules)->contains(fn ($module) => $module['active'] && $module['success'] === null);
        $reconciliationNotStarted = collect($modules)->contains(fn ($module) => $module['active'] && $module['reconciliation_freshness']['minutes'] === null);
        $drift = $manifests->contains(fn ($manifest) => $manifest->drift_state !== 'verified')
            || collect($modules)->contains(fn ($module) => $module['active'] && $module['manifest'] === null);
        $degraded = in_array($oauth, ['expired', 'absent', 'unknown'], true) || $critical || $notStarted || $reconciliationNotStarted || $drift || $failures->isNotEmpty();

        return ['label' => $degraded ? 'Dégradée' : 'Opérationnelle', 'color' => $degraded ? 'warning' : 'success', 'oldest_minutes' => $oldest, 'schema_drift' => $drift];
    }

    /** @param list<string> $stored @param list<string> $expected */
    private function sameModuleSet(array $stored, array $expected): bool
    {
        $stored = array_values(array_unique(array_filter($stored, 'is_string')));
        sort($stored);
        sort($expected);

        return $stored === $expected;
    }

    /** @return array{label:string,color:string,minutes:?int} */
    private function freshness($at, bool $nightly): array
    {
        if (! $at) {
            return ['label' => 'Jamais synchronisé', 'color' => 'secondary', 'minutes' => null];
        }
        $minutes = $at->diffInMinutes(now());
        $warning = $nightly ? (int) config('zoho-v2.freshness.nightly_warning_hours', 30) * 60 : (int) config('zoho-v2.freshness.hourly_warning_minutes', 90);
        $critical = $nightly ? (int) config('zoho-v2.freshness.nightly_critical_hours', 48) * 60 : (int) config('zoho-v2.freshness.hourly_critical_minutes', 180);
        $isCritical = $minutes > $critical;
        $isWarning = $nightly ? $minutes >= $warning : $minutes > $warning;

        return ['label' => $isCritical ? 'Critique' : ($isWarning ? 'À surveiller' : 'À jour'), 'color' => $isCritical ? 'danger' : ($isWarning ? 'warning' : 'success'), 'minutes' => $minutes];
    }
}
