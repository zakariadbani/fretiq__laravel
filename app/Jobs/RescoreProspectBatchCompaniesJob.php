<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\ProspectBatch;
use App\Services\Scoring\LeadScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Manual "Relancer le scoring IA" batch action. One admitted run per batch
 * (see admissionKey()) rescoring every distinct company promoted into this
 * batch (prospect_batch_items.company_id, no status filter).
 *
 * ponytail: single job with chunkById(100); switch to sliced self-chaining
 * only if the Gemini driver goes default — the default heuristic driver is
 * in-process/no network, so a chunked run of a few hundred companies
 * finishes well inside one job timeout.
 */
final class RescoreProspectBatchCompaniesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    private const CACHE_TTL_MINUTES = 60;

    public function __construct(
        public readonly int $prospectBatchId,
        public readonly string $progressToken,
        public readonly int $requestedBy,
        public readonly string $admissionOwner,
    ) {
        $this->afterCommit = true;
    }

    public static function admissionKey(int $prospectBatchId): string
    {
        return "prospect-batch-rescore:admission:{$prospectBatchId}";
    }

    public static function cacheKeyFor(string $token): string
    {
        return 'prospect-batch-rescore:'.$token;
    }

    public function handle(LeadScoringService $scoring): void
    {
        try {
            $batch = ProspectBatch::find($this->prospectBatchId);
            $criteria = $batch?->criteria;
            if ($batch === null || $criteria === null) {
                $this->writeResult(0, 0, 0, true);

                return;
            }

            $companyIdsSubquery = function ($query) use ($batch): void {
                $query->select('company_id')
                    ->from('prospect_batch_items')
                    ->where('prospect_batch_id', $batch->getKey())
                    ->whereNotNull('company_id')
                    ->distinct();
            };

            $total = DB::table('prospect_batch_items')
                ->where('prospect_batch_id', $batch->getKey())
                ->whereNotNull('company_id')
                ->distinct()
                ->count('company_id');

            $rescored = 0;
            $excluded = 0;
            $this->writeResult($total, 0, 0);

            Company::withRejected()
                ->whereIn('id', $companyIdsSubquery)
                ->chunkById(100, function ($companies) use ($criteria, $scoring, $total, &$rescored, &$excluded): void {
                    foreach ($companies as $company) {
                        // Scoring call OUTSIDE any transaction — it may hit an
                        // external provider (Gemini) and must never hold row locks.
                        $result = $scoring->score([
                            'domain' => (string) $company->domain,
                            'url' => $company->domain ? "https://{$company->domain}" : '',
                            'title' => (string) $company->name,
                        ], $criteria, 20);
                        $score = (int) ($result['score'] ?? 0);
                        $explanation = (string) ($result['explanation'] ?? '');
                        $exclude = ($result['exclude'] ?? false) === true;

                        DB::transaction(function () use ($company, $score, $explanation, $exclude): void {
                            $locked = Company::withRejected()->lockForUpdate()->find($company->id);
                            if ($locked === null) {
                                return;
                            }

                            $isClient = $locked->relationship === 'client';
                            $locked->forceFill([
                                'ai_score' => $score,
                                'ai_explanation' => $explanation,
                                // Never auto-reject a client company (Zoho-sourced,
                                // not a cold prospect) — a repeatable batch-wide
                                // action must not mass-reject existing clients.
                                // Un-reject on a good score keeps the same guard
                                // ProspectItemProcessor uses.
                                'qualification_status' => match (true) {
                                    $exclude && ! $isClient => 'rejected',
                                    ! $exclude && ! $isClient && $locked->qualification_status === 'rejected' => 'pending',
                                    default => $locked->qualification_status,
                                },
                            ])->save();
                        });

                        $rescored++;
                        if ($exclude) {
                            $excluded++;
                        }
                        // Throttled: one cache write per company (on the file
                        // driver, one file write) is wasted I/O — every 10th
                        // company is close enough for a progress bar, and the
                        // terminal write below always fires the final count.
                        if ($rescored % 10 === 0) {
                            $this->writeResult($total, $rescored, $excluded);
                        }
                    }
                });

            $this->writeResult($total, $rescored, $excluded, true);
        } finally {
            $this->clearAdmission();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(self::cacheKeyFor($this->progressToken), [
            'terminal' => true,
            'requested_by' => $this->requestedBy,
            'error' => true,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        $this->clearAdmission();
    }

    private function clearAdmission(): void
    {
        Cache::restoreLock(self::admissionKey($this->prospectBatchId), $this->admissionOwner)->release();
    }

    private function writeResult(int $total, int $rescored, int $excluded, bool $terminal = false): void
    {
        Cache::put(self::cacheKeyFor($this->progressToken), [
            'terminal' => $terminal,
            'requested_by' => $this->requestedBy,
            'total' => $total,
            'rescored' => $rescored,
            'excluded' => $excluded,
            'error' => false,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));
    }
}
