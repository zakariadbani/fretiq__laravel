<?php

namespace App\Jobs;

use App\Services\Discovery\EnrichmentDrainService;
use App\Services\Discovery\HunterEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * DrainEnrichmentJob — burns leftover discovery/verification quota against the
 * eligible backlog before a monthly plan reset wipes it.
 *
 * Only ONE drain ever runs. Single-flight is one authoritative marker,
 * `drain:active`, shared by all three channels (CLI, web controller, this job)
 * and refreshed at the start of every handle() cycle so it never lapses during a
 * long drain; uniqueId (a constant) + uniqueFor are the secondary guard against a
 * duplicate dispatch. The company stage is resumable: it runs under an
 * elapsed-time budget and, when the backlog outlasts one attempt, continues via
 * release() — NOT a self-dispatch.
 *
 * ponytail: release() re-queues THIS serialized instance; a same-uniqueId
 * re-dispatch under ShouldBeUnique is silently dropped by the unique lock (see
 * FinalizeProspectBatchJob's release note). Cursor + cumulative counts live in
 * Cache (not instance props) so each attempt resumes exactly where the last
 * stopped without re-charging companies it already attempted.
 */
class DrainEnrichmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** release() drives continuation, so this caps total attempts, not failures. */
    public int $tries = 50;

    public int $timeout = 540;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly string $mode,
        public readonly bool $includeEmpty,
        public readonly int $maxCompanyAttempts,
        public readonly string $runId,
    ) {
        $this->onQueue('discovery');
    }

    /** Only one drain may execute at a time, regardless of dispatch source. */
    public function uniqueId(): string
    {
        return 'drain-enrichment';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('drain-enrichment'))
            ->releaseAfter(30)
            ->expireAfter(570)];
    }

    public function handle(EnrichmentDrainService $svc): void
    {
        // Refresh the shared single-flight marker at the start of every cycle
        // (release() re-invokes handle()). Each cycle is <=485s < 1200s, so the
        // marker stays alive for the whole drain; finalize()/failed() clear it.
        Cache::put('drain:active', $this->runId, now()->addSeconds(1200));

        $ttl = now()->addHours(2);
        $cursorKey = "drain:{$this->runId}:cursor";
        $processedKey = "drain:{$this->runId}:processed";
        $verifyKey = "drain:{$this->runId}:verify_enqueued";

        $cursor = (int) Cache::get($cursorKey, 0);
        $processed = (int) Cache::get($processedKey, 0);

        // ── Company enrichment stage (companies | full) ──────────────────────
        if (in_array($this->mode, ['companies', 'full'], true) && $processed < $this->maxCompanyAttempts) {
            $deadline = time() + 480; // stay under the 540s worker timeout
            $remaining = $this->maxCompanyAttempts - $processed;

            $r = $svc->drainCompanies(
                $remaining,
                $cursor > 0 ? $cursor : null,
                $deadline,
                null,
                $this->includeEmpty,
            );

            $cursor = $r['last_id'] ?? $cursor;
            $processed += (int) $r['processed'];
            Cache::put($cursorKey, $cursor, $ttl);
            Cache::put($processedKey, $processed, $ttl);

            $reason = $r['stopped_reason'];

            // Hard stop: a systemic provider/quota/lock failure or an unexpected
            // error. Do NOT continue and do NOT fall through to verification —
            // verification hits the same provider and would just fail too.
            if (in_array($reason, ['provider_failed', 'quota_exhausted', 'lock_unavailable', 'error'], true)) {
                Log::channel('discovery')->warning('[DrainEnrichmentJob] Company drain hard-stopped.', [
                    'run_id' => $this->runId,
                    'mode' => $this->mode,
                    'reason' => $reason,
                    'companies_processed' => $processed,
                ]);
                $this->finalize($processed, false);

                return;
            }

            // Backlog outlasted this attempt (deadline hit or a partial page) and
            // the cap is not reached: resume next cycle. Never self-dispatch.
            if (! $r['exhausted'] && $processed < $this->maxCompanyAttempts && in_array($reason, [null, 'deadline'], true)) {
                $this->release(5);

                return;
            }

            // else: backlog exhausted or cap reached → fall through to verification.
        }

        // ── Verification stage (verify | full), enqueued exactly once ────────
        if (in_array($this->mode, ['verify', 'full'], true) && ! Cache::get($verifyKey, false)) {
            // Preflight the SEPARATE Hunter verifications meter (accountUsage is
            // cached 10 min, so this is cheap). A live balance of 0 means every
            // enqueued verification would just fail and retry (tries=20) — skip the
            // enqueue and log. A null usage (local mode) or a positive/unknown
            // balance proceeds.
            $usage = app(HunterEnrichmentService::class)->accountUsage();
            $verificationsLeft = is_array($usage) ? ($usage['verifications_available'] ?? null) : null;

            if (is_int($verificationsLeft) && $verificationsLeft <= 0) {
                Log::channel('discovery')->warning('[DrainEnrichmentJob] Verification skipped — no verification credit.', [
                    'run_id' => $this->runId,
                    'mode' => $this->mode,
                ]);
            } else {
                $enqueued = $svc->drainContacts();
                Cache::put($verifyKey, true, $ttl);
                Log::channel('discovery')->info('[DrainEnrichmentJob] Email verification enqueued.', [
                    'run_id' => $this->runId,
                    'mode' => $this->mode,
                    'enqueued' => $enqueued,
                ]);
            }
        }

        $this->finalize($processed, true);
    }

    /**
     * Called by the worker once all attempts are exhausted. Clears the
     * single-flight marker so a stuck drain never blocks the next one for the
     * full marker TTL.
     */
    public function failed(\Throwable $e): void
    {
        Log::channel('discovery')->error('[DrainEnrichmentJob] Drain failed.', [
            'run_id' => $this->runId,
            'mode' => $this->mode,
            'error' => $e->getMessage(),
        ]);

        Cache::forget('drain:active');
        Cache::forget('provider.hunter.account.v2');
    }

    /** Release the single-flight marker and refresh the cached vendor balance. */
    private function finalize(int $processed, bool $clean): void
    {
        Cache::forget('drain:active');
        Cache::forget('provider.hunter.account.v2');

        Log::channel('discovery')->info('[DrainEnrichmentJob] Drain finalized.', [
            'run_id' => $this->runId,
            'mode' => $this->mode,
            'companies_processed' => $processed,
            'clean' => $clean,
        ]);
    }
}
