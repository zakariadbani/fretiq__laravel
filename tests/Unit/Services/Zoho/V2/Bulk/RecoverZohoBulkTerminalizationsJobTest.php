<?php

namespace Tests\Unit\Services\Zoho\V2\Bulk;

use App\Jobs\Zoho\RecoverZohoBulkTerminalizationsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

class RecoverZohoBulkTerminalizationsJobTest extends TestCase
{
    public function test_recovery_sweeper_uses_the_dedicated_bulk_queue_envelope(): void
    {
        config()->set('zoho-v2.queue_connection', 'zoho');
        config()->set('zoho-v2.queue', 'zoho');
        config()->set('zoho-v2.retry.max_attempts', 5);
        config()->set('zoho-v2.bulk.delivery_timeout_seconds', 900);
        config()->set('zoho-v2.bulk.lease_seconds', 1500);

        $job = new RecoverZohoBulkTerminalizationsJob;

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame('zoho', $job->connection);
        $this->assertSame('zoho', $job->queue);
        $this->assertSame(5, $job->tries);
        $this->assertSame(900, $job->timeout);
        $this->assertSame(1500, $job->uniqueFor());
        $this->assertSame('bulk-terminalization-recovery', $job->uniqueId());
        $this->assertSame([60, 300, 900, 1800], $job->backoff());
    }

    public function test_recovery_sweeper_is_registered_on_the_dark_gated_schedule(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => $event->getSummaryForDisplay() === 'zoho:v2:bulk-terminalization-recovery'
        );

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertSame('Europe/Paris', $event->timezone);
    }
}
