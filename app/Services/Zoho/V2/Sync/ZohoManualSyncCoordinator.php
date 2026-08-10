<?php

namespace App\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Coordinates manual all-module batch admission and pause fencing; it never dispatches jobs. */
final class ZohoManualSyncCoordinator
{
    private const ACTIVE_STATUSES = ['queued', 'running'];

    private const TERMINAL_CHECKPOINT_STATUSES = ['completed', 'partial', 'failed'];

    public function __construct(
        private readonly ZohoSyncOrchestrator $orchestrator,
        private readonly ZohoModuleRegistry $registry,
    ) {}

    /** @param list<string> $moduleKeys */
    public function beginOrResumeAll(array $moduleKeys, int $requestedById): ManualSyncDecision
    {
        $moduleKeys = $this->normalizeModuleKeys($moduleKeys);

        return Cache::lock('zoho:v2:manual-sync-all', 15)->block(10, function () use ($moduleKeys, $requestedById): ManualSyncDecision {
            return DB::transaction(function () use ($moduleKeys, $requestedById): ManualSyncDecision {
                $active = ZohoSyncBatch::query()
                    ->where('trigger', 'manual')
                    ->where('mode', 'delta')
                    ->whereNull('completed_at')
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->oldest('requested_at')
                    ->oldest('id')
                    ->lockForUpdate()
                    ->first();

                if ($active !== null) {
                    return new ManualSyncDecision('already_running', $active, []);
                }

                $batch = ZohoSyncBatch::query()
                    ->where('trigger', 'manual')
                    ->where('mode', 'delta')
                    ->whereNull('completed_at')
                    ->where('status', 'paused')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->get()
                    ->first(fn (ZohoSyncBatch $candidate): bool => $this->moduleSetsMatch((array) $candidate->modules, $moduleKeys));

                if ($batch === null) {
                    $created = $this->orchestrator->createBatch($moduleKeys, 'delta', 'manual', $requestedById);

                    return new ManualSyncDecision('created', $created, $moduleKeys);
                }

                $modulesToDispatch = $this->incompleteModules($batch, $moduleKeys);
                $resumedAt = now();
                $batch->update([
                    'status' => 'running',
                    'resumed_at' => $resumedAt,
                    'resume_count' => ((int) $batch->resume_count) + 1,
                ]);

                $checkpointTargets = [];
                foreach ($modulesToDispatch as $moduleKey) {
                    $definition = $this->registry->get($moduleKey);
                    $checkpointTargets['v2:'.$definition->key.'|'.($definition->submodule ?? '')] = true;
                }
                $retryDeadline = $resumedAt->copy()->addHours((int) config('zoho-v2.retry.retry_window_hours', 12));
                $ownedCheckpoints = ZohoSyncCheckpoint::query()
                    ->where('sync_batch_id', $batch->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($ownedCheckpoints as $checkpoint) {
                    $checkpointKey = $checkpoint->module.'|'.$checkpoint->submodule;
                    if (! isset($checkpointTargets[$checkpointKey])) {
                        continue;
                    }
                    $checkpoint->update([
                        'status' => 'queued',
                        'completed_at' => null,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'heartbeat_at' => $resumedAt,
                        'delivery_retry_deadline_at' => $retryDeadline,
                    ]);
                }

                return new ManualSyncDecision(
                    'resumed',
                    $batch->fresh(),
                    $modulesToDispatch,
                    needsFinalization: $modulesToDispatch === [],
                );
            });
        });
    }

    /** @param list<string> $moduleKeys */
    public function pauseAll(int $batchId, array $moduleKeys, ?string $reason): ZohoSyncBatch
    {
        $moduleKeys = $this->normalizeModuleKeys($moduleKeys);

        return DB::transaction(function () use ($batchId, $moduleKeys, $reason): ZohoSyncBatch {
            // Lock order is authoritative batch first, then every owned checkpoint.
            $batch = ZohoSyncBatch::query()->lockForUpdate()->find($batchId);
            if (! $this->canPause($batch, $moduleKeys)) {
                throw new InvalidArgumentException('Only an unfinished manual delta all-module batch can be paused.');
            }

            $checkpoints = ZohoSyncCheckpoint::query()
                ->where('sync_batch_id', $batch->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $batch->update([
                'status' => 'paused',
                'paused_at' => now(),
                'pause_reason' => $this->normalizeReason($reason),
            ]);

            foreach ($checkpoints as $checkpoint) {
                $values = [
                    'generation' => ((int) $checkpoint->generation) + 1,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ];
                if (! in_array($checkpoint->status, self::TERMINAL_CHECKPOINT_STATUSES, true)) {
                    $values['status'] = 'paused';
                }
                $checkpoint->update($values);
            }

            return $batch->fresh();
        });
    }

    public function pausedBatchOwningModule(string $moduleKey): ?ZohoSyncBatch
    {
        $moduleKey = $this->normalizeModuleKeys([$moduleKey])[0];

        return ZohoSyncBatch::query()
            ->where('trigger', 'manual')
            ->where('mode', 'delta')
            ->where('status', 'paused')
            ->whereNull('completed_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn (ZohoSyncBatch $batch): bool => in_array($moduleKey, (array) $batch->modules, true));
    }

    /** @param list<string> $moduleKeys @return list<string> */
    private function normalizeModuleKeys(array $moduleKeys): array
    {
        $requested = [];
        foreach ($moduleKeys as $moduleKey) {
            if (! is_string($moduleKey) || ! filled($moduleKey)) {
                throw new InvalidArgumentException('A Zoho module key is required.');
            }
            $definition = $this->registry->get($moduleKey);
            if (! $definition->activationGated) {
                $requested[$definition->key] = true;
            }
        }

        $normalized = [];
        foreach ($this->registry->all() as $definition) {
            if (! $definition->activationGated && isset($requested[$definition->key])) {
                $normalized[] = $definition->key;
            }
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('At least one active Zoho module is required.');
        }

        return $normalized;
    }

    /** @param list<string> $stored @param list<string> $requested */
    private function moduleSetsMatch(array $stored, array $requested): bool
    {
        try {
            return $this->normalizeModuleKeys($stored) === $requested;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @param list<string> $moduleKeys */
    private function canPause(?ZohoSyncBatch $batch, array $moduleKeys): bool
    {
        return $batch !== null
            && $batch->trigger === 'manual'
            && $batch->mode === 'delta'
            && $batch->completed_at === null
            && in_array($batch->status, self::ACTIVE_STATUSES, true)
            && $this->moduleSetsMatch((array) $batch->modules, $moduleKeys);
    }

    /** @param list<string> $moduleKeys @return list<string> */
    private function incompleteModules(ZohoSyncBatch $batch, array $moduleKeys): array
    {
        $logs = ZohoSyncLog::query()
            ->where('sync_batch_id', $batch->id)
            ->where('mode', 'delta')
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (ZohoSyncLog $log): string => $log->module.'|'.$log->submodule);

        return array_values(array_filter($moduleKeys, function (string $moduleKey) use ($logs): bool {
            $definition = $this->registry->get($moduleKey);
            $key = $definition->key.'|'.($definition->submodule ?? '');
            $latest = $logs->get($key)?->first();

            return $latest === null || ! in_array($latest->status, ['success', 'partial'], true);
        }));
    }

    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }
        $reason = trim((string) preg_replace('/\s+/', ' ', $reason));

        return $reason === '' ? null : Str::limit($reason, 255, '');
    }
}
