<?php

namespace Tests\Unit;

use App\Services\Discovery\DiscoveryPipelineResult;
use PHPUnit\Framework\TestCase;

class DiscoveryPipelineResultTest extends TestCase
{
    private array $stats = [
        'companies' => 2,
        'contacts' => 1,
        'skipped' => 0,
        'low_score' => 1,
        'new' => 2,
        'contacts_consumed' => 1,
        'excluded' => 0,
    ];

    public function test_completion_requires_both_terminal_collection_and_a_drained_snapshot(): void
    {
        $complete = new DiscoveryPipelineResult($this->stats, true, true);
        $collecting = new DiscoveryPipelineResult($this->stats, false, true);
        $processing = new DiscoveryPipelineResult($this->stats, true, false);

        $this->assertTrue($complete->isComplete());
        $this->assertFalse($complete->needsContinuation);
        $this->assertTrue($collecting->needsContinuation);
        $this->assertTrue($processing->needsContinuation);
    }

    public function test_array_access_preserves_the_legacy_statistics_contract(): void
    {
        $result = new DiscoveryPipelineResult($this->stats, true, true);

        $this->assertSame(2, $result['companies']);
        $this->assertSame(1, $result['contacts']);
    }

    public function test_serialized_result_exposes_the_lifecycle_flags(): void
    {
        $result = new DiscoveryPipelineResult($this->stats, false, true);

        $this->assertSame([
            ...$this->stats,
            'collection_complete' => false,
            'snapshot_drained' => true,
            'needs_continuation' => true,
        ], $result->toArray());
    }
}
