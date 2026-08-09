<?php

namespace Tests\Unit\Services\Zoho\V2\Transport;

use App\Services\Zoho\V2\Transport\Sleeper;
use App\Services\Zoho\V2\Transport\ZohoApiThrottle;
use App\Services\Zoho\V2\Transport\ZohoThrottleUnavailableException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ZohoApiThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('zoho-v2.throttle.acquire_timeout_milliseconds', 0);
        config()->set('zoho-v2.throttle.poll_milliseconds', 1);
    }

    public function test_it_releases_a_concurrency_slot_after_the_attempt(): void
    {
        config()->set('zoho-v2.throttle.max_concurrent_requests', 1);
        config()->set('zoho-v2.throttle.requests_per_minute', 0);
        $gate = new ZohoApiThrottle($this->sleeper());

        $inside = $gate->execute(function () use ($gate): string {
            try {
                $gate->execute(fn (): string => 'nested');
            } catch (ZohoThrottleUnavailableException) {
                return 'blocked';
            }
        });

        $this->assertSame('blocked', $inside);
        $this->assertSame('released', $gate->execute(fn (): string => 'released'));
    }

    public function test_it_fails_closed_when_the_shared_minute_budget_is_exhausted(): void
    {
        config()->set('zoho-v2.throttle.max_concurrent_requests', 0);
        config()->set('zoho-v2.throttle.requests_per_minute', 1);
        $gate = new ZohoApiThrottle($this->sleeper());

        $this->assertSame('accepted', $gate->execute(fn (): string => 'accepted'));

        $this->expectException(ZohoThrottleUnavailableException::class);
        $gate->execute(fn (): string => 'must-not-run');
    }

    public function test_invalid_or_disabled_bounds_do_not_block_the_call(): void
    {
        config()->set('zoho-v2.throttle.max_concurrent_requests', -1);
        config()->set('zoho-v2.throttle.requests_per_minute', 0);

        $this->assertSame('pass-through', (new ZohoApiThrottle($this->sleeper()))->execute(fn (): string => 'pass-through'));
    }

    public function test_named_bulk_download_budget_is_shared_and_isolated_from_the_general_api_bucket(): void
    {
        $global = new ZohoApiThrottle($this->sleeper(), 'global', 1, 0);
        $downloads = new ZohoApiThrottle($this->sleeper(), 'bulk-download', 1, 0);

        $this->assertSame('general', $global->execute(fn (): string => 'general'));
        $this->assertSame('download', $downloads->execute(fn (): string => 'download'));

        $this->expectException(ZohoThrottleUnavailableException::class);
        $downloads->execute(fn (): string => 'eleventh-download-must-not-run');
    }

    public function test_minute_reservation_fails_closed_while_its_atomic_counter_lock_is_held(): void
    {
        $window = intdiv(time(), 60);
        $lock = Cache::lock('zoho:v2:api-throttle:bulk-download:minute-lock:'.$window, 5);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(ZohoThrottleUnavailableException::class);
            (new ZohoApiThrottle($this->sleeper(), 'bulk-download', 10, 0))
                ->execute(fn (): string => 'must-not-run');
        } finally {
            $lock->release();
        }
    }

    private function sleeper(): Sleeper
    {
        return new class implements Sleeper
        {
            public function sleepMilliseconds(int $milliseconds): void
            {
                // Admission tests must never wait in real time.
            }
        };
    }
}
