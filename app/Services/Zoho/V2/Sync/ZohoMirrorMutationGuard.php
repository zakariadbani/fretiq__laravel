<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoSyncBatch;
use App\Models\ZohoSyncCheckpoint;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claims the same per-module checkpoint used by Records and Bulk jobs.
 * Manual repair writers therefore cannot overlap a newer mirror generation.
 */
final class ZohoMirrorMutationGuard
{
    public function __construct(private readonly ZohoModuleRegistry $registry) {}

    public function claim(string $moduleKey, string $purpose): ?ZohoMirrorMutationLease
    {
        $definition = $this->registry->get($moduleKey);
        $module = 'v2:'.$definition->key;
        $submodule = $definition->submodule ?? '';
        $owner = substr($purpose.':'.Str::uuid(), 0, 100);

        return DB::transaction(function () use ($module, $submodule, $owner): ?ZohoMirrorMutationLease {
            $existing = ZohoSyncCheckpoint::query()->firstOrCreate(
                ['module' => $module, 'submodule' => $submodule],
                ['status' => 'idle', 'sync_mode' => 'delta'],
            );
            // Discover ownership without a row lock, then acquire the shared
            // runtime order: authoritative batch before checkpoint. If the
            // owner changes between discovery and the checkpoint lock, defer
            // this repair instead of acquiring the new owner's rows out of
            // order.
            $ownerBatchId = ZohoSyncCheckpoint::query()
                ->whereKey($existing->getKey())
                ->value('sync_batch_id');
            $ownerBatch = $ownerBatchId === null
                ? null
                : ZohoSyncBatch::query()->lockForUpdate()->find($ownerBatchId);
            $checkpoint = ZohoSyncCheckpoint::query()
                ->whereKey($existing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (($ownerBatchId === null && $checkpoint->sync_batch_id !== null)
                || ($ownerBatchId !== null && (int) $checkpoint->sync_batch_id !== (int) $ownerBatchId)) {
                return null;
            }

            // A standard/Bulk outbox remains owned while it is queued or
            // retrying between short leases. Manual mutation writers must
            // defer instead of incrementing that generation in the gap.
            if ($ownerBatch !== null && $ownerBatch->completed_at === null) {
                return null;
            }

            if ($checkpoint->lease_expires_at?->isFuture()) {
                return null;
            }

            $generation = ((int) $checkpoint->generation) + 1;
            $checkpoint->update([
                'generation' => $generation,
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'heartbeat_at' => now(),
            ]);

            return new ZohoMirrorMutationLease((int) $checkpoint->id, $generation, $owner);
        });
    }

    public function assertOwned(ZohoMirrorMutationLease $lease): void
    {
        $this->withOwnedLease($lease, static function (): void {});
    }

    /**
     * Hold the checkpoint row lock while a local mirror write is committed.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function withOwnedLease(ZohoMirrorMutationLease $lease, callable $callback): mixed
    {
        return DB::transaction(function () use ($lease, $callback): mixed {
            $checkpoint = ZohoSyncCheckpoint::query()
                ->whereKey($lease->checkpointId)
                ->lockForUpdate()
                ->first();

            if ($checkpoint === null
                || (int) $checkpoint->generation !== $lease->generation
                || (string) $checkpoint->lease_owner !== $lease->owner
                || $checkpoint->lease_expires_at?->isFuture() !== true) {
                throw new ZohoLeaseLostException('The shared mirror mutation lease was reclaimed.');
            }

            $checkpoint->update([
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'heartbeat_at' => now(),
            ]);

            return $callback();
        });
    }

    public function release(ZohoMirrorMutationLease $lease): void
    {
        ZohoSyncCheckpoint::query()
            ->whereKey($lease->checkpointId)
            ->where('generation', $lease->generation)
            ->where('lease_owner', $lease->owner)
            ->update([
                'lease_owner' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => now(),
            ]);
    }

    private function leaseSeconds(): int
    {
        return max(60, (int) config('zoho-v2.module.lease_seconds', 1500));
    }
}
