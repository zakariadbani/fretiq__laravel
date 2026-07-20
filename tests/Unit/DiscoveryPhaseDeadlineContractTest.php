<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Regression guard for the independent backlog and discovery time windows. */
class DiscoveryPhaseDeadlineContractTest extends TestCase
{
    public function test_job_starts_a_fresh_deadline_after_backlog_processing(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/Jobs/RunDiscoveryPipelineJob.php',
        );

        $this->assertSame(2, substr_count($source, 'new DiscoveryExecutionDeadline('));
        $this->assertStringContainsString('$attemptStartedAt = microtime(true);', $source);
        $this->assertStringContainsString('$attemptDeadline = new DiscoveryExecutionDeadline(', $source);
        $this->assertStringContainsString('$discoveryStartedAt = microtime(true);', $source);
        $this->assertStringContainsString('$discoveryDeadline = new DiscoveryExecutionDeadline(', $source);
        $this->assertMatchesRegularExpression(
            '/\$pipeline->run\([^;]*\$discoveryStartedAt[^;]*\$discoveryDeadline/s',
            $source,
        );
    }
}
