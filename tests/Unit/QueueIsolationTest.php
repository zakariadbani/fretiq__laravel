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
}
