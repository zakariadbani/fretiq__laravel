<?php

namespace Tests\Unit;

use App\Jobs\RunDiscoveryPipelineJob;
use App\Jobs\SendCampaignJob;
use App\Jobs\SendSequenceStepJob;
use App\Jobs\SendSequenceWaveStepJob;
use App\Jobs\SyncCampaignWaveZohoListJob;
use PHPUnit\Framework\TestCase;

class QueueIsolationTest extends TestCase
{
    public function test_long_running_jobs_use_their_dedicated_queues(): void
    {
        $this->assertSame('discovery', (new RunDiscoveryPipelineJob(1))->queue);
        $this->assertSame('campaigns', (new SendCampaignJob(1))->queue);
        $this->assertSame('campaigns', (new SendSequenceStepJob(1))->queue);
        $this->assertSame('campaigns', (new SendSequenceWaveStepJob(1))->queue);
        $this->assertSame('campaigns', (new SyncCampaignWaveZohoListJob(1))->queue);
    }

    public function test_campaign_overlap_locks_expire_after_interrupted_workers(): void
    {
        foreach ([
            new SendCampaignJob(1),
            new SendSequenceStepJob(1),
            new SendSequenceWaveStepJob(1),
            new SyncCampaignWaveZohoListJob(1),
        ] as $job) {
            $this->assertSame($job->timeout + 60, $job->middleware()[0]->expiresAfter);
            $this->assertStringContainsString('-v2-', $job->middleware()[0]->key);
        }
    }
}
