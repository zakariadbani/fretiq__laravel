<?php

namespace App\Services\Prospecting;

use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatchItem;
use Illuminate\Support\Facades\DB;

final class BlockedProspectItemRecoveryService
{
    /** @return list<int> */
    public function eligibleIds(int $batchId, int $limit): array
    {
        return ProspectBatchItem::query()
            ->where('prospect_batch_id', $batchId)->where('status', 'failed')
            ->whereIn('error_code', ['too_many_requests', 'usage_limit'])
            ->whereHas('providerCalls', fn ($calls) => $calls->where('provider', 'hunter')->where('operation', 'company_enrichment')->where('status', 'retryable')->where('attempt_count', '<', 4)->where('retry_at', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function apply(int $batchId, int $limit, int $spacingSeconds): int
    {
        $requeuedIds = [];
        foreach ($this->eligibleIds($batchId, $limit) as $id) {
            $changed = DB::transaction(function () use ($id, $batchId): bool {
                $item = ProspectBatchItem::query()->lockForUpdate()->find($id);
                if ($item === null
                    || (int) $item->prospect_batch_id !== $batchId
                    || $item->status !== 'failed'
                    || ! in_array($item->error_code, ['too_many_requests', 'usage_limit'], true)) {
                    return false;
                }

                $due = $item->providerCalls()
                    ->where('provider', 'hunter')
                    ->where('operation', 'company_enrichment')
                    ->where('status', 'retryable')
                    ->where('attempt_count', '<', 4)
                    ->where('retry_at', '<=', now())
                    ->exists();
                if (! $due) {
                    return false;
                }

                $item->forceFill(['status' => 'pending', 'error_message' => null, 'processed_at' => null])->save();

                return true;
            });
            if ($changed) {
                $requeuedIds[] = $id;
            }
        }

        foreach ($requeuedIds as $index => $id) {
            ProcessProspectBatchItemJob::dispatch($id)->delay(now()->addSeconds($index * $spacingSeconds));
        }

        return count($requeuedIds);
    }
}
