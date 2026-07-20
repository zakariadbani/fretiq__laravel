<?php

namespace Tests\Unit;

use App\Services\Discovery\DiscoveryExecutionDeadline;
use PHPUnit\Framework\TestCase;

class DiscoveryExecutionDeadlineTest extends TestCase
{
    public function test_it_reserves_ten_seconds_for_finalization(): void
    {
        $deadline = new DiscoveryExecutionDeadline(1_000.0, 120, 10);

        $this->assertSame(1_110.0, $deadline->workDeadlineAt());
        $this->assertSame(110.0, $deadline->remaining(1_000.0));
    }

    public function test_timeout_is_limited_to_whole_seconds_remaining(): void
    {
        $deadline = new DiscoveryExecutionDeadline(1_000.0, 120, 10);

        $this->assertSame(20, $deadline->timeout(20, 1_001.0));
        $this->assertSame(3, $deadline->timeout(20, 1_106.2));
    }

    public function test_no_external_call_is_allowed_with_less_than_one_second_left(): void
    {
        $deadline = new DiscoveryExecutionDeadline(1_000.0, 120, 10);

        $this->assertNull($deadline->timeout(20, 1_109.01));
        $this->assertFalse($deadline->canStartExternalCall(1_109.01));
    }

    public function test_deadline_reports_exhaustion_at_the_boundary(): void
    {
        $deadline = new DiscoveryExecutionDeadline(1_000.0, 120, 10);

        $this->assertFalse($deadline->isExhausted(1_109.99));
        $this->assertTrue($deadline->isExhausted(1_110.0));
    }
}
