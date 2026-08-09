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
            'zoho_sync_batches' => ['status', 'requested_at', 'mode', 'correlation_id'],
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

    /** @return array<string,mixed> */
    public function data(): array
    {
        $token = ZohoToken::where('service', 'crm')->first();
        $expiry = $token?->expires_at;
        $tokenPresent = $token && trim((string) $token->access_token) !== '';
        $remainingMinutes = $expiry ? (int) now()->diffInMinutes($expiry, false) : null;
        $oauth = ! $tokenPresent ? 'absent' : ($remainingMinutes === null ? 'unknown' : ($remainingMinutes <= 0 ? 'expired' : ($remainingMinutes < 15 ? 'soon' : 'ready')));
        $checkpoints = ZohoSyncCheckpoint::query()->get()->keyBy(fn (ZohoSyncCheckpoint $checkpoint): string => $checkpoint->module.'|'.($checkpoint->submodule ?? ''));
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
        $modules = [];

        foreach ($this->registry->all() as $key => $definition) {
            $attempt = $attempts->get($key);
            $success = $successes->get($key);
            $reconcile = $reconciliations->get($key);
            $reconciliation = $this->reconciliationEvidence($reconcile, $reconcile ? $reconciliationFailures->get($key.'|'.$reconcile->correlation_id) : null);
            $manifest = $manifestsByModule->get($definition->apiName) ?? $manifestsByModule->get($key);
            $freshness = $this->freshness($success?->synced_at, false);
            $modules[$key] = [
                'label' => $definition->apiName,
                'attempt' => $attempt,
                'success' => $success,
                'freshness' => $freshness,
                'reconciliation_freshness' => $this->freshness($reconcile?->synced_at, true),
                'quality' => $this->quality->forModule($key),
                'checkpoint' => $checkpoints->get('v2:'.$key.'|'.($definition->submodule ?? '')),
                'reconciliation' => $reconciliation,
                'manifest' => $manifest,
                'active' => ! $definition->activationGated,
                'note' => $definition->activationNote,
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
            'batch' => ZohoSyncBatch::query()->whereIn('status', ['queued', 'running'])->oldest('requested_at')->first(),
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
