<?php

namespace Tests\Unit\Support;

use App\Support\ZohoSyncFrequency;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ZohoSyncFrequencyTest extends TestCase
{
    public function test_it_exposes_the_supported_frequency_values(): void
    {
        $this->assertSame([
            'every_15_minutes',
            'every_30_minutes',
            'hourly',
            'every_2_hours',
            'every_6_hours',
            'daily',
        ], ZohoSyncFrequency::values());
    }

    public function test_it_normalizes_unknown_values_to_hourly(): void
    {
        $this->assertSame('hourly', ZohoSyncFrequency::normalize('invalid'));
        $this->assertSame('hourly', ZohoSyncFrequency::normalize(null));
    }

    public function test_it_applies_every_supported_frequency_to_the_expected_cron_expression(): void
    {
        $expectedExpressions = [
            'every_15_minutes' => '*/15 * * * *',
            'every_30_minutes' => '*/30 * * * *',
            'hourly' => '10 * * * *',
            'every_2_hours' => '0 */2 * * *',
            'every_6_hours' => '0 */6 * * *',
            'daily' => '10 2 * * *',
        ];

        foreach ($expectedExpressions as $frequency => $expression) {
            $event = app(Schedule::class)->call(static fn (): null => null);

            $this->assertSame(
                $expression,
                ZohoSyncFrequency::apply($event, $frequency)->expression,
                "Unexpected cron expression for {$frequency}."
            );
        }
    }
}
