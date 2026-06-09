<?php

namespace App\Services\Analytics;

use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * QueueObservabilityService — surfaces queue health data for the admin observability screen.
 *
 * All reads are non-destructive. The failed_jobs table is accessed via the
 * query builder (not a model) so it works regardless of custom queue config.
 */
class QueueObservabilityService
{
    // ── Failed jobs (Laravel queue) ────────────────────────────────────────────

    /**
     * Return the most recent failed_jobs rows with first-line exception context.
     *
     * Columns returned per row:
     *   id, connection, queue, exception (first line only), failed_at.
     *
     * @param  int  $limit  Maximum rows (default 50).
     * @return Collection<int, object>
     */
    public function failedJobs(int $limit = 50): Collection
    {
        return DB::table('failed_jobs')
            ->latest('failed_at')
            ->limit($limit)
            ->get(['id', 'connection', 'queue', 'exception', 'failed_at'])
            ->map(function (object $row): object {
                // Truncate exception to the first meaningful line
                $row->exception = $this->firstLine((string) ($row->exception ?? ''));
                return $row;
            });
    }

    // ── Failed campaign runs ────────────────────────────────────────────────────

    /**
     * Return CampaignRun rows with status='failed', eager-loading their campaign.
     *
     * @return Collection<int, CampaignRun>
     */
    public function failedRuns(): Collection
    {
        return CampaignRun::where('status', 'failed')
            ->with('campaign')
            ->orderByDesc('run_at')
            ->get();
    }

    // ── Summary counts ──────────────────────────────────────────────────────────

    /**
     * Return high-level count summary for the observability dashboard header.
     *
     * Keys:
     *   failed_jobs      — total rows in failed_jobs table
     *   failed_runs      — CampaignRun rows with status='failed'
     *   queued_recipients— CampaignRecipient rows with status='queued'
     *   scheduled_runs   — CampaignRun rows with status='scheduled'
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        // Guard: failed_jobs table might not exist in a fresh install
        try {
            $failedJobsCount = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failedJobsCount = 0;
        }

        return [
            'failed_jobs'       => $failedJobsCount,
            'failed_runs'       => CampaignRun::where('status', 'failed')->count(),
            'queued_recipients' => CampaignRecipient::where('status', 'queued')->count(),
            'scheduled_runs'    => CampaignRun::where('status', 'scheduled')->count(),
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Return the first non-empty line of an exception string, trimmed.
     */
    private function firstLine(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }
}
