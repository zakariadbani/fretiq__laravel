<?php

namespace Tests\Feature\Jobs;

use App\Jobs\DrainEnrichmentJob;
use App\Services\Discovery\EnrichmentDrainService;
use App\Services\Discovery\HunterEnrichmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * DrainEnrichmentJobTest — executing coverage for the money/background-job
 * orchestrator. EnrichmentDrainService is mocked so no real Hunter spend or DB
 * work happens; HunterEnrichmentService is bound to control the verify-meter
 * preflight. handle() is driven manually with withFakeQueueInteractions() so
 * release()/finalize() are assertable, and the cursor + cumulative counts (kept
 * in Cache, keyed by runId) carry across the two simulated release cycles.
 */
class DrainEnrichmentJobTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function drainResult(array $overrides = []): array
    {
        return array_merge([
            'last_id' => null,
            'processed' => 0,
            'enriched' => 0,
            'empty' => 0,
            'failed' => 0,
            'stopped_reason' => null,
            'exhausted' => false,
        ], $overrides);
    }

    /** @param array<string, mixed>|null $usage null = local mode (preflight proceeds). */
    private function bindHunter(?array $usage): void
    {
        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldReceive('accountUsage')->andReturn($usage);
        $this->app->instance(HunterEnrichmentService::class, $hunter);
    }

    public function test_cap_is_enforced_across_release_cycles_and_verify_runs_once(): void
    {
        $runId = (string) Str::uuid();
        $this->bindHunter(null); // local mode → verify preflight proceeds

        $svc = Mockery::mock(EnrichmentDrainService::class);
        // Cycle 1: remaining = cap(5) - 0, cursor null → 3 processed, backlog not exhausted.
        $svc->shouldReceive('drainCompanies')->once()->ordered()
            ->with(5, null, Mockery::type('int'), null, false)
            ->andReturn($this->drainResult(['last_id' => 3, 'processed' => 3, 'enriched' => 3]));
        // Cycle 2: remaining = cap(5) - 3 = 2, cursor resumes at 3 → 2 processed, exhausted.
        $svc->shouldReceive('drainCompanies')->once()->ordered()
            ->with(2, 3, Mockery::type('int'), null, false)
            ->andReturn($this->drainResult(['last_id' => 5, 'processed' => 2, 'enriched' => 2, 'exhausted' => true]));
        // Verification enqueued exactly ONCE across both cycles (full mode).
        $svc->shouldReceive('drainContacts')->once()->andReturn(7);

        // Cycle 1 → job released to continue; verification NOT yet run.
        $job1 = (new DrainEnrichmentJob('full', false, 5, $runId))->withFakeQueueInteractions();
        $job1->handle($svc);
        $job1->assertReleased(5);

        // Cycle 2 → cap reached (3 + 2 = 5), verification runs, job not released.
        $job2 = (new DrainEnrichmentJob('full', false, 5, $runId))->withFakeQueueInteractions();
        $job2->handle($svc);
        $job2->assertNotReleased();

        $this->assertNull(Cache::get('drain:active'), 'A clean finalize must clear the single-flight marker.');
    }

    public function test_provider_failed_hard_stops_without_verifying_and_clears_marker(): void
    {
        $runId = (string) Str::uuid();
        $this->bindHunter(['verifications_available' => 1000]); // would allow verify if it were reached

        $svc = Mockery::mock(EnrichmentDrainService::class);
        $svc->shouldReceive('drainCompanies')->once()
            ->andReturn($this->drainResult(['last_id' => 2, 'processed' => 1, 'failed' => 1, 'stopped_reason' => 'provider_failed']));
        // Must NOT fall through to verification — it hits the same failing provider.
        $svc->shouldReceive('drainContacts')->never();

        $job = (new DrainEnrichmentJob('full', false, 5, $runId))->withFakeQueueInteractions();
        $job->handle($svc);

        $job->assertNotReleased();
        $this->assertFalse(Cache::has('drain:active'), 'A hard stop must finalize and clear the marker.');
    }

    public function test_verify_stage_skips_enqueue_when_no_verification_credit(): void
    {
        $runId = (string) Str::uuid();
        $this->bindHunter(['verifications_available' => 0]); // meter exhausted → skip enqueue

        $svc = Mockery::mock(EnrichmentDrainService::class);
        $svc->shouldReceive('drainContacts')->never();

        // verify-only: no company stage, straight to the preflight.
        $job = (new DrainEnrichmentJob('verify', false, 0, $runId))->withFakeQueueInteractions();
        $job->handle($svc);

        $job->assertNotReleased();
        $this->assertNull(Cache::get('drain:active'));
    }

    public function test_failed_clears_the_single_flight_marker(): void
    {
        $runId = (string) Str::uuid();
        Cache::put('drain:active', $runId, now()->addSeconds(1200));

        (new DrainEnrichmentJob('full', false, 5, $runId))->failed(new \RuntimeException('boom'));

        $this->assertNull(Cache::get('drain:active'), 'failed() must clear the marker so the next drain is not blocked.');
    }
}
