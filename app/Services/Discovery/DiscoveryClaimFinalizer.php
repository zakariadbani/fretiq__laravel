<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\DiscoveryRun;
use Illuminate\Support\Facades\Schema;

/**
 * Releases durable Hunter claims after their owning run becomes terminal.
 *
 * Callers perform the terminal run transition and this cleanup in the same DB
 * transaction. The owner predicate keeps a late/competing run from being touched.
 */
final class DiscoveryClaimFinalizer
{
    public function releaseForTerminalRun(int $runId): int
    {
        if (! Schema::hasColumn('companies', 'enrichment_claim_run_id')
            || ! Schema::hasColumn('companies', 'enrichment_status')
        ) {
            return 0;
        }

        $terminal = DiscoveryRun::whereKey($runId)
            ->whereNotIn('status', ['pending', 'running'])
            ->exists();

        if (! $terminal) {
            return 0;
        }

        $now = now();
        $failed = Company::withRejected()
            ->where('enrichment_claim_run_id', $runId)
            ->where('enrichment_status', Company::ENRICHMENT_ENRICHING)
            ->update([
                'enrichment_status' => Company::ENRICHMENT_HUNTER_FAILED,
                'enrichment_claim_run_id' => null,
                'updated_at' => $now,
            ]);

        // A crash can also happen after the outcome was persisted but before the
        // provider service's finally block cleared the claim. Preserve that outcome.
        $released = Company::withRejected()
            ->where('enrichment_claim_run_id', $runId)
            ->update([
                'enrichment_claim_run_id' => null,
                'updated_at' => $now,
            ]);

        return $failed + $released;
    }
}
