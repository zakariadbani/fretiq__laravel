<?php

namespace Tests\Feature\Backend;

use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryPipelineResult;
use App\Services\Discovery\DiscoveryPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * DiscoveryHeartbeatOwnershipTest — guards DiscoveryRun::touchHeartbeat() against the
 * changed-rows-vs-matched-rows regression (see ../structure/… root cause notes).
 *
 * Deliberately does NOT override database.default to sqlite (unlike
 * DiscoveryPipelineInterruptionTest / DiscoveryJobLifecycleTest and their siblings).
 * SQLite's sqlite3_changes() counts rows PROCESSED, so a same-value UPDATE returns
 * rowCount = 1 there — a frozen-clock test in an sqlite-backed class is green
 * against the broken code and proves nothing. This class runs against the real
 * `fretiq_test` MySQL connection declared in phpunit.xml, via RefreshDatabase.
 */
class DiscoveryHeartbeatOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The raw fact that motivates the fix: on MySQL, an `updated_at = now()` UPDATE
     * whose WHERE clause matches a row returns 0 affected rows when the column
     * already holds that exact value — because PDO reports CHANGED rows, not
     * MATCHED rows (PDO::MYSQL_ATTR_FOUND_ROWS is never set). A guard that reads
     * that 0 as "row no longer running" is wrong; DiscoveryRun::touchHeartbeat()
     * must still report ownership via an explicit exists() check.
     */
    public function test_mysql_reports_zero_affected_rows_for_a_same_second_updated_at_write(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL changed-rows semantics only.');
        }

        Carbon::setTestNow('2026-07-27 15:29:28');

        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria, ['status' => 'running']);

        // The row's updated_at already equals the frozen "now" from creation, so
        // this UPDATE's SET clause changes nothing.
        $this->assertSame(
            0,
            DiscoveryRun::whereKey($run->id)
                ->where('status', 'running')
                ->update(['updated_at' => now()]),
        );

        // Despite the 0 affected-row count above, the run IS still 'running' and
        // must be reported as owned.
        $this->assertTrue(DiscoveryRun::touchHeartbeat(DiscoveryRun::whereKey($run->id)));
    }

    /** Driver-independent contract: 'running' → true, any terminal status → false. */
    public function test_touch_heartbeat_reflects_running_status_only(): void
    {
        $criteria = $this->criteria();
        $runningRun = $this->discoveryRun($criteria, ['status' => 'running']);
        $failedRun = $this->discoveryRun($criteria, [
            'status' => 'failed',
            'finished_at' => now(),
        ]);

        $this->assertTrue(DiscoveryRun::touchHeartbeat(DiscoveryRun::whereKey($runningRun->id)));
        $this->assertFalse(DiscoveryRun::touchHeartbeat(DiscoveryRun::whereKey($failedRun->id)));
    }

    /**
     * The regression that protects the user-visible bug: an incomplete pipeline
     * attempt, whose pre-release heartbeat write lands in the same wall-clock
     * second as the row's current updated_at, must still be RELEASED back onto
     * the queue — never silently dropped ("Run bloqué détecté par le
     * terminalizer"). Mirrors DiscoveryJobLifecycleTest's helper conventions.
     */
    public function test_incomplete_attempt_is_released_even_when_heartbeat_write_changes_no_rows(): void
    {
        Carbon::setTestNow('2026-07-27 15:25:39');

        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subMinute(),
        ]);

        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldReceive('run')->once()->andReturn($this->pipelineResult(
            collectionComplete: false,
            snapshotDrained: true,
        ));
        app()->instance(DiscoveryPipelineService::class, $pipeline);

        $job = (new RunDiscoveryPipelineJob($criteria->id, $run->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(5);
        $fresh = $run->fresh();
        $this->assertSame('running', $fresh->status);
        $this->assertNull($fresh->finished_at);
    }

    private function criteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name' => 'Critère heartbeat '.uniqid(),
            'daily_limit' => 3,
            'auto_enrich' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function discoveryRun(ProspectCriteria $criteria, array $overrides = []): DiscoveryRun
    {
        return DiscoveryRun::create(array_merge([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'pending',
            'credits_reserved' => 3,
            'searches_reserved' => 3,
            'searches_consumed' => 0,
            'consumed' => 0,
            'contact_credits_reserved' => 0,
            'contact_consumed' => 0,
            'successful_enrichments_target' => 0,
        ], $overrides));
    }

    private function pipelineResult(bool $collectionComplete, bool $snapshotDrained): DiscoveryPipelineResult
    {
        return new DiscoveryPipelineResult(
            stats: [
                'companies' => 0,
                'contacts' => 0,
                'skipped' => 0,
                'low_score' => 0,
                'new' => 0,
                'contacts_consumed' => 0,
                'excluded' => 0,
            ],
            collectionComplete: $collectionComplete,
            snapshotDrained: $snapshotDrained,
        );
    }
}
