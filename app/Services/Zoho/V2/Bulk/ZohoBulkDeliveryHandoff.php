<?php

namespace App\Services\Zoho\V2\Bulk;

use App\Jobs\Zoho\RunZohoBulkBackfillJob;
use App\Models\Zoho\ZohoBulkReadJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** Atomically reserves a descendant generation and inserts its database-queue row. */
class ZohoBulkDeliveryHandoff
{
    public function reserveAndDispatch(
        RunZohoBulkBackfillJob $parent,
        BulkBackfillStep $step,
    ): bool {
        if ($step->action !== BulkBackfillStep::REDISPATCH || $step->bulkJobId === null) {
            throw new RuntimeException('Bulk continuation handoff is invalid.');
        }

        $connection = (string) config('zoho-v2.queue_connection', 'zoho');
        if (config('queue.connections.'.$connection.'.driver') !== 'database'
            || config('queue.connections.'.$connection.'.after_commit', false)) {
            throw new RuntimeException('Bulk continuation requires an immediate database queue transaction.');
        }
        $queueDatabase = config('queue.connections.'.$connection.'.connection')
            ?: DB::getDefaultConnection();
        if (! is_string($queueDatabase) || $queueDatabase !== DB::getDefaultConnection()) {
            throw new RuntimeException('Bulk continuation queue and state must share one database connection.');
        }

        $child = $parent->continuation($step->bulkJobId);

        return DB::connection($queueDatabase)->transaction(function () use (
            $parent,
            $step,
            $child,
            $connection,
        ): bool {
            $root = ZohoBulkReadJob::query()
                ->where('sync_batch_id', $parent->batchId)
                ->where('module', $parent->module)
                ->where('page_key', 'root')
                ->lockForUpdate()
                ->first();
            if ($root === null
                || ! hash_equals((string) $root->correlation_id, $parent->correlationId)
                || ! hash_equals((string) $root->module_lease_owner, $parent->runOwner)) {
                throw new RuntimeException('Bulk continuation run ownership is invalid.');
            }

            $generation = (int) $root->delivery_generation;
            if ($generation > $parent->deliveryGeneration) {
                // A newer delivery has already been durably reserved.
                return false;
            }
            if ($generation !== $parent->deliveryGeneration) {
                throw new RuntimeException('Bulk continuation generation is invalid.');
            }

            $root->update([
                'delivery_generation' => $child->deliveryGeneration,
                'heartbeat_at' => now(),
            ]);
            $queuedId = Queue::connection($connection)->later(
                now()->addSeconds($step->delaySeconds),
                $child,
                (string) config('zoho-v2.queue', 'zoho'),
            );
            if ($queuedId === null || $queuedId === false) {
                throw new RuntimeException('Bulk continuation queue insertion failed.');
            }

            return true;
        });
    }
}
