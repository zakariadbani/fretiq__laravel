<?php

namespace Tests\Unit\Services\Zoho\V2\Marketing;

use App\Services\Zoho\V2\Marketing\MarketingPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MarketingPeriodTest extends TestCase
{
    public function test_default_is_thirty_complete_paris_calendar_days_with_equal_previous_period(): void
    {
        $period = MarketingPeriod::fromInput([], CarbonImmutable::parse('2026-08-09 07:00:00', 'UTC'));

        $this->assertSame('2026-07-11', $period->startsAt->toDateString());
        $this->assertSame('2026-08-09', $period->endsAt->toDateString());
        $this->assertSame('2026-06-11', $period->previousStartsAt->toDateString());
        $this->assertSame('2026-07-10', $period->previousEndsAt->toDateString());
        $this->assertSame('Europe/Paris', $period->timezone);
    }

    public function test_custom_period_rejects_reverse_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarketingPeriod::fromInput(['preset' => 'custom', 'from' => '2026-08-02', 'to' => '2026-08-01']);
    }

    public function test_custom_period_rejects_calendar_rollover_dates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MarketingPeriod::fromInput(['preset' => 'custom', 'from' => '2026-02-31', 'to' => '2026-03-03']);
    }

    public function test_autumn_dst_day_round_trips_to_exact_utc_instants(): void
    {
        $period = MarketingPeriod::fromInput(['preset' => 'custom', 'from' => '2026-10-25', 'to' => '2026-10-25']);
        [$from, $to] = $period->databaseBounds();

        $this->assertSame('2026-10-24 22:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-25 22:59:59', $to->toDateTimeString());
        $this->assertSame('2026-10-25', $period->toArray()['from']);
        $this->assertSame('2026-10-25', $period->toArray()['to']);
    }

    public function test_quarter_and_year_presets_are_calendar_anchored(): void
    {
        $now = CarbonImmutable::parse('2026-08-09 12:00:00', 'Europe/Paris');
        $this->assertSame('2026-07-01', MarketingPeriod::fromInput(['preset' => 'qtd'], $now)->startsAt->toDateString());
        $this->assertSame('2026-01-01', MarketingPeriod::fromInput(['preset' => 'ytd'], $now)->startsAt->toDateString());
    }

    public function test_ninety_and_three_hundred_sixty_five_day_presets_have_exact_inclusive_previous_windows(): void
    {
        $now = CarbonImmutable::parse('2026-08-10 09:00:00', 'Europe/Paris');

        $ninety = MarketingPeriod::fromInput(['preset' => '90d'], $now);
        $this->assertSame('2026-05-13', $ninety->startsAt->toDateString());
        $this->assertSame('2026-08-10', $ninety->endsAt->toDateString());
        $this->assertSame('2026-02-12', $ninety->previousStartsAt->toDateString());
        $this->assertSame('2026-05-12', $ninety->previousEndsAt->toDateString());

        $year = MarketingPeriod::fromInput(['preset' => '365d'], $now);
        $this->assertSame('2025-08-11', $year->startsAt->toDateString());
        $this->assertSame('2026-08-10', $year->endsAt->toDateString());
        $this->assertSame('2024-08-11', $year->previousStartsAt->toDateString());
        $this->assertSame('2025-08-10', $year->previousEndsAt->toDateString());
    }
}
