<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoStandardSyncRun;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoSyncFailure;
use App\Models\ZohoSyncCheckpoint;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\V2\Registry\ModuleDefinition;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class ZohoBatchResumePreparer
{
    private const PREPARED_BY = 'zoho:crm:prepare-resume';

    private const PAUSE_REASON = 'Prepared locally for durable resume; no work was dispatched.';

    public function __construct(
        private readonly ZohoModuleRegistry $registry,
        private readonly ZohoStandardWorklist $worklist,
    ) {}

    /**
     * @return array{
     *   batch_id:int,
     *   applied:bool,
     *   already_prepared:bool,
     *   completed_modules:list<string>,
     *   incomplete_modules:list<string>,
     *   modules:array<string, array{mirror_seeds:int, unresolved_failures:int, completed:int, queued:int}>,
     *   completed:int,
     *   queued:int
     * }
     */
    public function prepare(int $batchId, bool $apply = false): array
    {
        if ($batchId < 1) {
            throw new InvalidArgumentException('Batch must be a positive integer.');
        }

        if (! $apply) {
            $batch = ZohoSyncBatch::query()->find($batchId);
            if (! $batch instanceof ZohoSyncBatch) {
                throw new InvalidArgumentException("Zoho sync batch [{$batchId}] was not found.");
            }

            $definitions = $this->activeDefinitions();
            $alreadyPrepared = $this->validateBatch($batch, $definitions);
            $checkpoints = $this->ownedCheckpoints($batch);
            $this->validateCheckpoints($batch, $definitions, $checkpoints);
            $this->assertNoAssociatedQueueJob($batch);

            return $this->summary($batch, $definitions, false, $alreadyPrepared);
        }

        return DB::transaction(function () use ($batchId): array {
            $batch = ZohoSyncBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            if (! $batch instanceof ZohoSyncBatch) {
                throw new InvalidArgumentException("Zoho sync batch [{$batchId}] was not found.");
            }

            $definitions = $this->activeDefinitions();
            $alreadyPrepared = $this->validateBatch($batch, $definitions);
            $checkpoints = $this->ownedCheckpoints($batch, true);
            $this->validateCheckpoints($batch, $definitions, $checkpoints);
            ZohoStandardSyncRun::query()
                ->where('sync_batch_id', $batch->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->assertNoAssociatedQueueJob($batch);

            if ($alreadyPrepared) {
                return $this->summary($batch, $definitions, true, true);
            }

            $plan = $this->summary($batch, $definitions, true, false);
            $checkpointsByKey = $checkpoints->keyBy(
                fn (ZohoSyncCheckpoint $checkpoint): string => $checkpoint->module.'|'.$checkpoint->submodule,
            );
            $metadata = is_array($batch->resume_metadata) ? $batch->resume_metadata : [];
            $metadata = array_replace($metadata, [
                'prepared_by' => self::PREPARED_BY,
                'prepared_at' => now()->toIso8601String(),
                'batch_id' => (int) $batch->id,
                'correlation_id' => (string) $batch->correlation_id,
                'requested_watermark_at' => $batch->requested_at?->toIso8601String(),
                'prior' => [
                    'status' => (string) $batch->status,
                    'completed_at' => $batch->completed_at?->toIso8601String(),
                    'error_summary' => $batch->error_summary,
                ],
            ]);
            $batch->update([
                'status' => 'paused',
                'completed_at' => null,
                'paused_at' => now(),
                'pause_reason' => self::PAUSE_REASON,
                'error_summary' => null,
                'resume_metadata' => $metadata,
            ]);

            foreach ($plan['incomplete_modules'] as $module) {
                $definition = $definitions[$module];
                $checkpoint = $checkpointsByKey->get($this->checkpointKey($definition));
                if (! $checkpoint instanceof ZohoSyncCheckpoint) {
                    throw new InvalidArgumentException("Batch checkpoint ownership is incomplete for [{$definition->key}].");
                }
                $run = $this->worklist->getOrCreateRun(
                    $batch,
                    $definition->key,
                    $definition->submodule ?? '',
                    [
                        'correlation_id' => $batch->correlation_id,
                        'mode' => 'delta',
                        'query_fingerprint' => $definition->queryFingerprint('delta'),
                        'query_params' => $definition->enumerationQuery(),
                        'watermark_at' => $batch->requested_at,
                        'since_at' => $checkpoint->cursor_at?->copy()->subMinutes(
                            (int) config('zoho-v2.overlap_minutes', 15),
                        ),
                        'status' => 'enumerating',
                        'counters' => $checkpoint->counters,
                        'page_token' => null,
                        'page_token_expires_at' => null,
                    ],
                );
                $run->update([
                    'status' => 'enumerating',
                    'page_token' => null,
                    'page_token_expires_at' => null,
                    'counters' => $checkpoint->counters,
                    'enumerated_at' => null,
                    'completed_at' => null,
                ]);
                $this->worklist->seedCompleted(
                    $run,
                    $this->mirrorIds($batch, $definition),
                    $this->unresolvedIds($definition),
                );
                $checkpoint->update([
                    'status' => 'paused',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'generation' => ((int) $checkpoint->generation) + 1,
                ]);
            }

            return $this->summary($batch->fresh(), $definitions, true, false);
        });
    }

    /** @return array<string, ModuleDefinition> */
    private function activeDefinitions(): array
    {
        return array_filter(
            $this->registry->all(),
            static fn (ModuleDefinition $definition): bool => ! $definition->activationGated
                && $definition->key !== 'quoted_items',
        );
    }

    /** @param array<string, ModuleDefinition> $definitions */
    private function validateBatch(ZohoSyncBatch $batch, array $definitions): bool
    {
        $expected = array_keys($definitions);
        $stored = array_values((array) $batch->modules);
        if (count($stored) !== count($expected)
            || array_diff($stored, $expected) !== []
            || array_diff($expected, $stored) !== []) {
            throw new InvalidArgumentException('The batch does not contain the exact active Sync Tout module set.');
        }
        if ($batch->trigger !== 'manual' || $batch->mode !== 'delta'
            || trim((string) $batch->correlation_id) === '' || $batch->requested_at === null) {
            throw new InvalidArgumentException('Only a manual all-module delta batch with its original correlation and watermark can be prepared.');
        }

        if ($batch->status === 'paused' && $batch->completed_at === null) {
            $metadata = is_array($batch->resume_metadata) ? $batch->resume_metadata : [];
            if (($metadata['prepared_by'] ?? null) !== self::PREPARED_BY
                || (int) ($metadata['batch_id'] ?? 0) !== (int) $batch->id
                || ($metadata['correlation_id'] ?? null) !== $batch->correlation_id
                || ($metadata['requested_watermark_at'] ?? null) !== $batch->requested_at->toIso8601String()) {
                throw new InvalidArgumentException('The paused batch was not prepared by this command.');
            }

            return true;
        }

        if (! in_array($batch->status, ['error', 'failed'], true)) {
            throw new InvalidArgumentException('The batch is not an explicitly recoverable interrupted batch.');
        }

        return false;
    }

    /** @return Collection<int, ZohoSyncCheckpoint> */
    private function ownedCheckpoints(ZohoSyncBatch $batch, bool $lock = false): Collection
    {
        $query = ZohoSyncCheckpoint::query()
            ->where('sync_batch_id', $batch->id)
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    /**
     * @param array<string, ModuleDefinition> $definitions
     * @param Collection<int, ZohoSyncCheckpoint> $checkpoints
     */
    private function validateCheckpoints(ZohoSyncBatch $batch, array $definitions, Collection $checkpoints): void
    {
        $byKey = $checkpoints->keyBy(fn (ZohoSyncCheckpoint $checkpoint): string => $checkpoint->module.'|'.$checkpoint->submodule);
        foreach ($definitions as $definition) {
            $checkpoint = $byKey->get($this->checkpointKey($definition));
            if (! $checkpoint instanceof ZohoSyncCheckpoint
                || $checkpoint->sync_mode !== 'delta'
                || (int) $checkpoint->sync_batch_id !== (int) $batch->id
                || $checkpoint->correlation_id !== $batch->correlation_id) {
                throw new InvalidArgumentException("Batch checkpoint ownership is incomplete for [{$definition->key}].");
            }
        }
        if ($checkpoints->contains(fn (ZohoSyncCheckpoint $checkpoint): bool => $checkpoint->lease_expires_at?->isFuture() === true)) {
            throw new InvalidArgumentException('The batch still owns an unexpired checkpoint lease.');
        }
    }

    private function assertNoAssociatedQueueJob(ZohoSyncBatch $batch): void
    {
        $connection = (string) config('zoho-v2.queue_connection', 'zoho');
        $jobsTable = (string) config("queue.connections.{$connection}.table", 'jobs');
        if (! Schema::hasTable($jobsTable)) {
            return;
        }
        foreach (DB::table($jobsTable)->orderBy('id')->pluck('payload') as $rawPayload) {
            $payload = json_decode((string) $rawPayload, true);
            $serialized = is_array($payload) ? data_get($payload, 'data.command') : null;
            $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;
            if (! is_string($serialized) || $serialized === ''
                || ! is_string($displayName) || ! str_contains($displayName, 'Zoho')) {
                continue;
            }
            if (str_contains($serialized, '"batchId";i:'.(int) $batch->id.';')) {
                throw new InvalidArgumentException('The batch still has an associated queued or reserved Zoho job.');
            }
        }
    }

    /**
     * @param array<string, ModuleDefinition> $definitions
     * @return array<string, mixed>
     */
    private function summary(ZohoSyncBatch $batch, array $definitions, bool $applied, bool $alreadyPrepared): array
    {
        $latestLogs = ZohoSyncLog::query()
            ->where('sync_batch_id', $batch->id)
            ->where('mode', 'delta')
            ->where('correlation_id', $batch->correlation_id)
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (ZohoSyncLog $log): string => $log->module.'|'.$log->submodule)
            ->keyBy(fn (ZohoSyncLog $log): string => $log->module.'|'.$log->submodule);
        $completed = [];
        $incomplete = [];
        foreach ($definitions as $definition) {
            $latest = $latestLogs->get($definition->key.'|'.($definition->submodule ?? ''));
            if ($latest instanceof ZohoSyncLog && in_array($latest->status, ['success', 'partial'], true)) {
                $completed[] = $definition->key;
            } else {
                $incomplete[] = $definition->key;
            }
        }

        $modules = [];
        $completedItems = 0;
        $queuedItems = 0;
        foreach ($incomplete as $module) {
            $definition = $definitions[$module];
            $mirrorIds = $this->mirrorIds($batch, $definition);
            $unresolvedIds = $this->unresolvedIds($definition);
            $run = ZohoStandardSyncRun::query()
                ->where('sync_batch_id', $batch->id)
                ->where('module', $definition->key)
                ->where('submodule', $definition->submodule ?? '')
                ->first();
            $states = $run instanceof ZohoStandardSyncRun
                ? $run->workItems()->pluck('status', 'zoho_id')->all()
                : [];
            foreach ($mirrorIds as $id) {
                $states[$id] = in_array($id, $unresolvedIds, true) ? 'queued' : 'completed';
            }
            foreach ($unresolvedIds as $id) {
                $states[$id] = 'queued';
            }
            $moduleCompleted = count(array_filter($states, static fn (string $status): bool => $status === 'completed'));
            $moduleQueued = count(array_filter($states, static fn (string $status): bool => $status === 'queued'));
            $modules[$module] = [
                'mirror_seeds' => count($mirrorIds),
                'unresolved_failures' => count($unresolvedIds),
                'completed' => $moduleCompleted,
                'queued' => $moduleQueued,
            ];
            $completedItems += $moduleCompleted;
            $queuedItems += $moduleQueued;
        }

        return [
            'batch_id' => (int) $batch->id,
            'applied' => $applied,
            'already_prepared' => $alreadyPrepared,
            'completed_modules' => $completed,
            'incomplete_modules' => $incomplete,
            'modules' => $modules,
            'completed' => $completedItems,
            'queued' => $queuedItems,
        ];
    }

    /** @return list<string> */
    private function mirrorIds(ZohoSyncBatch $batch, ModuleDefinition $definition): array
    {
        $query = $definition->modelClass::query()->where('sync_batch_id', $batch->id);
        if ($definition->activityType !== null) {
            $query->where('activity_type', $definition->activityType);
        }

        return $query->pluck('zoho_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(static fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function unresolvedIds(ModuleDefinition $definition): array
    {
        return ZohoSyncFailure::query()
            ->where('module', $definition->key)
            ->where('submodule', $definition->submodule ?? '')
            ->where('failure_kind', 'record')
            ->whereNull('resolved_at')
            ->whereNotNull('zoho_id')
            ->pluck('zoho_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(static fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    private function checkpointKey(ModuleDefinition $definition): string
    {
        return 'v2:'.$definition->key.'|'.($definition->submodule ?? '');
    }
}
