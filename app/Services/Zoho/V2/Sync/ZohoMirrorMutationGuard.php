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
            ZohoSyncCheckpoint::query()->firstOrCreate(
                ['module' => $module, 'submodule' => $submodule],
                ['status' => 'idle', 'sync_mode' => 'delta'],
            );
            $checkpoint = ZohoSyncCheckpoint::query()
                ->where('module', $module)
                ->where('submodule', $submodule)
                ->lockForUpdate()
                ->firstOrFail();

            // A standard/Bulk outbox remains owned while it is queued or
            // retrying between short leases. Manual mutation writers must
            // defer instead of incrementing that generation in the gap.
            if ($checkpoint->sync_batch_id !== null) {
                $ownerBatch = ZohoSyncBatch::query()->lockForUpdate()->find($checkpoint->sync_batch_id);
                if ($ownerBatch !== null && $ownerBatch->completed_at === null) {
                    return null;
                }
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
        $owned = ZohoSyncCheckpoint::query()
            ->whereKey($lease->checkpointId)
            ->where('generation', $lease->generation)
            ->where('lease_owner', $lease->owner)
            ->where('lease_expires_at', '>', now())
            ->lockForUpdate()
            ->first(['id']);

        if ($owned === null) {
            throw new ZohoLeaseLostException('The shared mirror mutation lease was reclaimed.');
        }
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
