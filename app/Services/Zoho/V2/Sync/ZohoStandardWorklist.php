<?php

namespace App\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoStandardSyncRun;
use App\Models\Zoho\ZohoStandardSyncWorkItem;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class ZohoStandardWorklist
{
    /** @param array<string, mixed> $attributes */
    public function getOrCreateRun(ZohoSyncBatch $batch, string $module, string $submodule, array $attributes): ZohoStandardSyncRun
    {
        $this->assertAuthoritativeAttributes($batch, $attributes);
        $attributes['query_params'] = $this->canonicalize($attributes['query_params']);

        $run = ZohoStandardSyncRun::query()->firstOrCreate(
            [
                'sync_batch_id' => $batch->id,
                'module' => $module,
                'submodule' => $submodule,
            ],
            $attributes,
        );

        $this->assertSameImmutableRun($run, $batch, $attributes);

        return $run;
    }

    /** @param array<int, mixed> $zohoIds */
    public function stage(ZohoStandardSyncRun $run, array $zohoIds): int
    {
        $now = now();
        $rows = collect($zohoIds)
            ->filter(static fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(static fn (string $id): string => trim($id))
            ->unique()
            ->map(static fn (string $id): array => [
                'zoho_standard_sync_run_id' => $run->id,
                'zoho_id' => $id,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        if ($rows->isEmpty()) {
            return 0;
        }

        $inserted = 0;
        $rows->chunk(500)->each(function ($chunk) use (&$inserted): void {
            $inserted += DB::table('zoho_standard_sync_work_items')->insertOrIgnore($chunk->all());
        });

        return $inserted;
    }

    /** @return Collection<int, ZohoStandardSyncWorkItem> */
    public function queued(ZohoStandardSyncRun $run, int $limit): Collection
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The queued work item limit must be positive.');
        }

        return $run->workItems()
            ->where('status', 'queued')
            ->orderBy('zoho_id')
            ->limit($limit)
            ->get();
    }

    public function restartEnumeration(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner): void
    {
        DB::transaction(function () use ($run, $checkpoint, $generation, $owner): void {
            [, $lockedCheckpoint, $locked] = $this->lockRuntimeFence($run, $checkpoint, $generation, $owner);
            $now = now();
            $locked->increment('enumeration_restart_count', 1, [
                'page_token' => null,
                'page_token_expires_at' => null,
                'status' => 'enumerating',
                'enumerated_at' => null,
                'completed_at' => null,
                'updated_at' => $now,
            ]);
            ZohoStandardSyncWorkItem::query()
                ->where('zoho_standard_sync_run_id', $locked->id)
                ->where('status', 'processing')
                ->update(['status' => 'queued', 'lease_owner' => null, 'lease_expires_at' => null, 'updated_at' => $now]);
        });
    }

    /**
     * Persist API attempts which cannot be attributed to a completed work item
     * (for example, a rejected page token or a local throttle deferral).
     */
    public function recordUnattachedApiRequests(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner, int $apiRequests): void
    {
        if ($apiRequests < 1) {
            return;
        }

        DB::transaction(function () use ($run, $checkpoint, $generation, $owner, $apiRequests): void {
            [, , $locked] = $this->lockRuntimeFence($run, $checkpoint, $generation, $owner);
            $counters = is_array($locked->counters) ? $locked->counters : [];
            $counters['api_requests'] = max(0, (int) ($counters['api_requests'] ?? 0)) + $apiRequests;
            $locked->update(['counters' => $counters]);
        });
    }

    /** @param array<int, mixed> $zohoIds @param array<int, mixed> $unresolvedIds */
    public function seedCompleted(ZohoStandardSyncRun $run, array $zohoIds, array $unresolvedIds = []): void
    {
        $this->stage($run, array_merge($zohoIds, $unresolvedIds));
        $ids = $this->normalizeIds($zohoIds);
        $unresolved = $this->normalizeIds($unresolvedIds);

        DB::transaction(function () use ($run, $ids, $unresolved): void {
            $now = now();
            $complete = array_values(array_diff($ids, $unresolved));

            if ($complete !== []) {
                ZohoStandardSyncWorkItem::query()
                    ->where('zoho_standard_sync_run_id', $run->id)
                    ->whereIn('zoho_id', $complete)
                    ->update([
                        'status' => 'completed',
                        'outcome' => 'seeded',
                        'error_summary' => null,
                        'records_created' => 0,
                        'records_updated' => 0,
                        'records_unchanged' => 0,
                        'api_requests' => 0,
                        'processed_at' => $now,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'updated_at' => $now,
                    ]);
            }

            if ($unresolved !== []) {
                ZohoStandardSyncWorkItem::query()
                    ->where('zoho_standard_sync_run_id', $run->id)
                    ->whereIn('zoho_id', $unresolved)
                    ->update([
                        'status' => 'queued',
                        'outcome' => null,
                        'error_summary' => null,
                        'records_created' => 0,
                        'records_updated' => 0,
                        'records_unchanged' => 0,
                        'api_requests' => 0,
                        'processed_at' => null,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'updated_at' => $now,
                    ]);
            }
        });
    }

    /** @param array<int, mixed> $zohoIds @param array<string, mixed>|null $counters */
    public function persistEnumerationPage(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner, array $zohoIds, ?string $nextToken, mixed $expiry, bool $complete, ?array $counters = null): void
    {
        if ($complete && ($nextToken !== null || $expiry !== null)) {
            throw new InvalidArgumentException('A completed enumeration page cannot retain a next token or expiry.');
        }
        if (! $complete && (trim((string) $nextToken) === '' || $expiry === null)) {
            throw new InvalidArgumentException('An incomplete enumeration page requires a non-empty next token and expiry.');
        }
        if (! $complete) {
            try {
                Carbon::parse($expiry);
            } catch (\Throwable) {
                throw new InvalidArgumentException('An incomplete enumeration page requires a valid next token and expiry.');
            }
        }

        DB::transaction(function () use ($run, $checkpoint, $generation, $owner, $zohoIds, $nextToken, $expiry, $complete, $counters): void {
            [, , $locked] = $this->lockRuntimeFence($run, $checkpoint, $generation, $owner);
            if (! in_array($locked->status, ['enumerating'], true)) {
                throw new InvalidArgumentException('Enumeration pages can only be persisted while the run is enumerating.');
            }

            $now = now();
            $rows = $this->workRows($locked->id, $zohoIds, $now);
            if ($rows !== []) {
                DB::table('zoho_standard_sync_work_items')->insertOrIgnore($rows);
            }

            $locked->fill([
                'page_token' => $complete ? null : $nextToken,
                'page_token_expires_at' => $complete ? null : ($expiry === null ? null : Carbon::parse($expiry)),
                'status' => $complete ? 'hydrating' : 'enumerating',
                'enumerated_at' => $complete ? $now : null,
                'counters' => $counters ?? $locked->counters,
            ])->save();
        });
    }

    /** @return Collection<int, ZohoStandardSyncWorkItem> */
    public function claimQueued(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner, int $limit): Collection
    {
        if ($generation < 1 || trim($owner) === '' || $limit < 1) {
            throw new InvalidArgumentException('A positive generation, non-empty owner, and positive claim limit are required.');
        }

        return DB::transaction(function () use ($run, $checkpoint, $generation, $owner, $limit): Collection {
            [, $lockedCheckpoint, $lockedRun] = $this->lockRuntimeFence($run, $checkpoint, $generation, $owner);
            $now = now();
            ZohoStandardSyncWorkItem::query()
                ->where('zoho_standard_sync_run_id', $lockedRun->id)
                ->where('status', 'processing')
                ->where(function ($query) use ($generation, $now): void {
                    $query->where('delivery_generation', '<', $generation)
                        ->orWhere(function ($query) use ($generation, $now): void {
                            $query->where('delivery_generation', $generation)
                                ->where('lease_expires_at', '<=', $now);
                        });
                })
                ->update([
                    'status' => 'queued',
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'updated_at' => $now,
                ]);

            $items = $lockedRun->workItems()->where('status', 'queued')->orderBy('zoho_id')->limit($limit)->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->update([
                    'status' => 'processing',
                    'lease_owner' => $owner,
                    'delivery_generation' => $generation,
                    'attempts' => $item->attempts + 1,
                    'lease_expires_at' => $lockedCheckpoint->lease_expires_at,
                    'updated_at' => $now,
                ]);
            }

            return $items;
        });
    }

    /** @param array{records_created?: mixed, records_updated?: mixed, records_unchanged?: mixed, outcome?: mixed, error_summary?: mixed} $outcome */
    public function markCompleted(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, ZohoStandardSyncWorkItem $item, int $generation, string $owner, array $outcome = []): bool
    {
        return $this->completeClaimed(
            $run,
            $checkpoint,
            $generation,
            $owner,
            $item,
            static fn (): array => $outcome,
        ) !== false;
    }

    /**
     * @param callable(ZohoStandardSyncWorkItem): array<string, mixed> $persist
     * @return array<string, mixed>|false
     */
    public function completeClaimed(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner, ZohoStandardSyncWorkItem $item, callable $persist): array|false
    {
        return DB::transaction(function () use ($run, $checkpoint, $item, $generation, $owner, $persist): array|false {
            $this->lockRuntimeFence($run, $checkpoint, $generation, $owner);
            if ($item->delivery_generation !== $generation || $item->lease_owner !== $owner) {
                return false;
            }
            $locked = ZohoStandardSyncWorkItem::query()
                ->whereKey($item->id)
                ->where('status', 'processing')
                ->where('lease_owner', $owner)
                ->where('delivery_generation', $generation)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof ZohoStandardSyncWorkItem) {
                return false;
            }

            $outcome = $persist($locked);
            if (! is_array($outcome)) {
                throw new InvalidArgumentException('A claimed-item persistence callback must return outcome counters as an array.');
            }

            $normalized = [
                'records_created' => $this->counter($outcome, 'records_created'),
                'records_updated' => $this->counter($outcome, 'records_updated'),
                'records_unchanged' => $this->counter($outcome, 'records_unchanged'),
                'api_requests' => $this->counter($outcome, 'api_requests'),
                'outcome' => isset($outcome['outcome']) ? (string) $outcome['outcome'] : null,
                'error_summary' => isset($outcome['error_summary']) ? (string) $outcome['error_summary'] : null,
            ];
            $locked->fill([
                'status' => 'completed',
                ...$normalized,
                'processed_at' => now(),
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();

            return $normalized;
        });
    }

    public function markQuarantined(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, ZohoStandardSyncWorkItem $item, int $generation, string $owner, string $errorSummary): bool
    {
        return $this->quarantineClaimed(
            $run,
            $checkpoint,
            $generation,
            $owner,
            $item,
            static fn (): array => ['outcome' => 'quarantined', 'error_summary' => $errorSummary],
        ) !== false;
    }

    /**
     * @param callable(ZohoStandardSyncWorkItem): array<string, mixed> $persistFailure
     * @return array<string, mixed>|false
     */
    public function quarantineClaimed(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner, ZohoStandardSyncWorkItem $item, callable $persistFailure): array|false
    {
        return DB::transaction(function () use ($run, $checkpoint, $item, $generation, $owner, $persistFailure): array|false {
            $this->lockRuntimeFence($run, $checkpoint, $generation, $owner);
            if ($item->delivery_generation !== $generation || $item->lease_owner !== $owner) {
                return false;
            }
            $locked = ZohoStandardSyncWorkItem::query()
                ->whereKey($item->id)
                ->where('status', 'processing')
                ->where('lease_owner', $owner)
                ->where('delivery_generation', $generation)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof ZohoStandardSyncWorkItem) {
                return false;
            }

            $outcome = $persistFailure($locked);
            if (! is_array($outcome)) {
                throw new InvalidArgumentException('A quarantine persistence callback must return an outcome array.');
            }

            $normalized = [
                'records_created' => $this->counter($outcome, 'records_created'),
                'records_updated' => $this->counter($outcome, 'records_updated'),
                'records_unchanged' => $this->counter($outcome, 'records_unchanged'),
                'api_requests' => $this->counter($outcome, 'api_requests'),
                'outcome' => isset($outcome['outcome']) ? (string) $outcome['outcome'] : 'quarantined',
                'error_summary' => isset($outcome['error_summary']) ? (string) $outcome['error_summary'] : null,
            ];
            $locked->fill([
                'status' => 'quarantined',
                ...$normalized,
                'processed_at' => now(),
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();

            return $normalized;
        });
    }

    /** @param array<string, mixed> $counters */
    public function completeRunIfDrained(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner, array $counters): bool
    {
        return DB::transaction(function () use ($run, $checkpoint, $generation, $owner, $counters): bool {
            [, , $lockedRun] = $this->lockRuntimeFence($run, $checkpoint, $generation, $owner);
            if ($lockedRun->workItems()->whereIn('status', ['queued', 'processing'])->exists()) {
                return false;
            }

            $lockedRun->fill([
                'status' => 'completed',
                'completed_at' => now(),
                'counters' => $counters,
            ])->save();

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function durableCounters(ZohoStandardSyncRun $run): array
    {
        $fresh = $run->fresh();
        if (! $fresh instanceof ZohoStandardSyncRun) {
            throw new InvalidArgumentException('The standard sync run no longer exists.');
        }

        $counters = is_array($fresh->counters) ? $fresh->counters : [];
        if ($fresh->status === 'completed') {
            return $counters;
        }

        foreach (['seen', 'created', 'updated', 'unchanged', 'quarantined', 'api_requests'] as $key) {
            $counters[$key] = max(0, (int) ($counters[$key] ?? 0));
        }

        $items = $fresh->workItems()
            ->whereIn('status', ['completed', 'quarantined'])
            ->where(function ($query): void {
                $query->whereNull('outcome')->orWhere('outcome', '!=', 'seeded');
            })
            ->get(['status', 'records_created', 'records_updated', 'records_unchanged', 'api_requests']);

        foreach ($items as $item) {
            $counters['seen']++;
            $counters['created'] += $item->records_created;
            $counters['updated'] += $item->records_updated;
            $counters['unchanged'] += $item->records_unchanged;
            $counters['api_requests'] += $item->api_requests;
            if ($item->status === 'quarantined') {
                $counters['quarantined']++;
            }
        }

        return $counters;
    }

    /** @param array<int, mixed> $ids @return array<int, string> */
    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(static fn (string $id): string => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, mixed> $ids @return array<int, array<string, mixed>> */
    private function workRows(int $runId, array $ids, mixed $now): array
    {
        return array_map(static fn (string $id): array => [
            'zoho_standard_sync_run_id' => $runId,
            'zoho_id' => $id,
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->normalizeIds($ids));
    }

    /** @param array<string, mixed> $attributes */
    private function assertAuthoritativeAttributes(ZohoSyncBatch $batch, array $attributes): void
    {
        foreach (['correlation_id', 'mode', 'query_fingerprint', 'query_params', 'watermark_at'] as $key) {
            if (! array_key_exists($key, $attributes)) {
                throw new InvalidArgumentException("A standard sync run requires {$key}.");
            }
        }
        if ($attributes['correlation_id'] !== $batch->correlation_id || $attributes['mode'] !== $batch->mode) {
            throw new InvalidArgumentException('Standard sync run correlation ID and mode must match the authoritative batch.');
        }
        if (! is_array($attributes['query_params'])) {
            throw new InvalidArgumentException('Standard sync run query params must be an array.');
        }
        if ($attributes['watermark_at'] === null) {
            throw new InvalidArgumentException('A standard sync run requires a non-null watermark_at.');
        }
    }

    /** @return array{ZohoSyncBatch, ZohoSyncCheckpoint, ZohoStandardSyncRun} */
    private function lockRuntimeFence(ZohoStandardSyncRun $run, ZohoSyncCheckpoint $checkpoint, int $generation, string $owner): array
    {
        if ($generation < 1 || trim($owner) === '') {
            throw new InvalidArgumentException('A positive generation and non-empty owner are required for a fenced worklist operation.');
        }

        $batch = ZohoSyncBatch::query()->whereKey($run->sync_batch_id)->lockForUpdate()->firstOrFail();
        if ($batch->status !== 'running' || $batch->completed_at !== null || $batch->correlation_id !== $run->correlation_id || $batch->mode !== $run->mode) {
            throw new InvalidArgumentException('The standard sync run is not owned by a live running batch.');
        }

        $lockedCheckpoint = ZohoSyncCheckpoint::query()->whereKey($checkpoint->id)->lockForUpdate()->firstOrFail();
        if ($lockedCheckpoint->sync_batch_id !== $batch->id
            || $lockedCheckpoint->module !== 'v2:'.$run->module
            || $lockedCheckpoint->submodule !== $run->submodule
            || $lockedCheckpoint->correlation_id !== $run->correlation_id
            || $lockedCheckpoint->sync_mode !== $run->mode
            || $lockedCheckpoint->generation !== $generation
            || $lockedCheckpoint->lease_owner !== $owner
            || $lockedCheckpoint->lease_expires_at === null
            || $lockedCheckpoint->lease_expires_at->lte(now())) {
            throw new InvalidArgumentException('The standard sync worklist checkpoint fence is stale or not owned by this delivery.');
        }

        $lockedRun = ZohoStandardSyncRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($lockedRun->sync_batch_id !== $batch->id
            || $lockedRun->correlation_id !== $batch->correlation_id
            || $lockedRun->mode !== $batch->mode
            || $lockedCheckpoint->module !== 'v2:'.$lockedRun->module
            || $lockedCheckpoint->submodule !== $lockedRun->submodule) {
            throw new InvalidArgumentException('The locked standard sync run no longer matches its batch or checkpoint fence.');
        }

        return [$batch, $lockedCheckpoint, $lockedRun];
    }

    /** @param array<string, mixed> $attributes */
    private function assertSameImmutableRun(ZohoStandardSyncRun $run, ZohoSyncBatch $batch, array $attributes): void
    {
        if ($run->correlation_id !== $batch->correlation_id || $run->mode !== $batch->mode) {
            throw new InvalidArgumentException('Existing standard sync run does not match the authoritative batch correlation ID or mode.');
        }
        if ($run->query_fingerprint !== $attributes['query_fingerprint']) {
            throw new InvalidArgumentException('Existing standard sync run has a different query fingerprint.');
        }
        if ($this->canonicalize($run->query_params ?? []) !== $this->canonicalize($attributes['query_params'])) {
            throw new InvalidArgumentException('Existing standard sync run has different query params.');
        }
        foreach (['watermark_at', 'since_at'] as $key) {
            if (! $this->sameDateTime($run->{$key}, $attributes[$key] ?? null)) {
                throw new InvalidArgumentException("Existing standard sync run has a different {$key}.");
            }
        }
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? $this->canonicalize($item) : $item;
        }
        ksort($value);

        return $value;
    }

    private function sameDateTime(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return Carbon::parse($left)->getTimestamp() === Carbon::parse($right)->getTimestamp();
    }

    /** @param array<string, mixed> $outcome */
    private function counter(array $outcome, string $key): int
    {
        return max(0, (int) ($outcome[$key] ?? 0));
    }
}
