<?php

namespace Tests\Unit;

use App\Services\Scheduling\BusinessCalendarService;
use App\Services\Settings\SettingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * BusinessCalendarServiceTest — settings-backed weekend/blackout calendar.
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because the service
 * reads Setting::get(), which needs the Laravel container.
 *
 * No database: settings are injected by priming the SettingService cache key
 * ('app_settings'), which is exactly what SettingService::loadMap() reads. That
 * short-circuits buildMap() — and its Schema::hasTable('settings') probe — so the
 * suite never opens a connection. Same idiom as tests/Unit/DomainBlocklistTest.php.
 */
class BusinessCalendarServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $this->settings([
            'planification.skip_weekends' => true,
            'planification.blackout_dates' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Inject the settings map without touching the database.
     *
     * @param  array<string, mixed>  $map
     */
    private function settings(array $map): void
    {
        // clearCache() first: it wipes the in-process memo on the resolved
        // SettingService singleton AND the cache key, so the put() below is what
        // the next loadMap() call sees.
        app(SettingService::class)->clearCache();
        Cache::put('app_settings', $map, 3600);
    }

    // ── Parser ───────────────────────────────────────────────────────────────────

    public function test_parses_one_off_and_recurring_dates_ignoring_comments_and_blanks_with_crlf(): void
    {
        $raw = "2026-12-25 # Noël\r\n\r\n12-25\n   \nbad-line\n2026-13-40\n99-99\n";

        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => $raw,
        ]);

        $service = new BusinessCalendarService();

        // One-off date parses despite the trailing comment.
        $this->assertTrue($service->isBlockedDate(Carbon::parse('2026-12-25', 'Europe/Paris')));

        // "12-25" recurs every year — verified across two consecutive years.
        $this->assertTrue($service->isBlockedDate(Carbon::parse('2027-12-25', 'Europe/Paris')));
        $this->assertTrue($service->isBlockedDate(Carbon::parse('2028-12-25', 'Europe/Paris')));

        // A day not in the list stays allowed.
        $this->assertFalse($service->isBlockedDate(Carbon::parse('2026-12-24', 'Europe/Paris')));

        // Malformed lines are dropped by the parser AND surfaced by invalidLines().
        $this->assertSame(['bad-line', '2026-13-40', '99-99'], BusinessCalendarService::invalidLines($raw));
    }

    public function test_empty_setting_means_an_empty_blackout_set_not_a_default_holiday_list(): void
    {
        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => '',
        ]);

        $service = new BusinessCalendarService();

        // Unlike DomainBlocklist, blank must NOT fall back to a built-in list —
        // Christmas must stay allowed when the admin has cleared the field.
        $this->assertFalse($service->isBlockedDate(Carbon::parse('2026-12-25', 'Europe/Paris')));
        $this->assertSame([], $service->blockedDatesFor(Carbon::parse('2026-01-01', 'Europe/Paris'), 60));
        $this->assertSame([], BusinessCalendarService::invalidLines(''));
    }

    public function test_leap_day_annual_entry_blocks_only_in_leap_years(): void
    {
        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => '02-29',
        ]);

        $service = new BusinessCalendarService();

        $this->assertTrue($service->isBlockedDate(Carbon::parse('2028-02-29', 'Europe/Paris')));
        // 2027 has no Feb 29 — the nearest real date must stay unblocked.
        $this->assertFalse($service->isBlockedDate(Carbon::parse('2027-02-28', 'Europe/Paris')));
        $this->assertSame([], BusinessCalendarService::invalidLines('02-29'));
    }

    // ── skip_weekends toggle ─────────────────────────────────────────────────────

    public function test_skip_weekends_false_blocks_only_the_explicit_blackout_date(): void
    {
        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => '2026-08-15',
        ]);

        $service = new BusinessCalendarService();

        $this->assertTrue($service->isBlockedDate(Carbon::parse('2026-08-15', 'Europe/Paris')));
        // 2026-08-16 is also a weekend day but NOT in the blackout list, and
        // skip_weekends is off — it must be allowed.
        $this->assertFalse($service->isBlockedDate(Carbon::parse('2026-08-16', 'Europe/Paris')));
    }

    // ── shiftToAllowed ───────────────────────────────────────────────────────────

    public function test_shift_to_allowed_preserves_paris_wall_time_across_the_dst_boundary(): void
    {
        // Force Friday itself blocked (via blackout) so the walk crosses Fri→Sat→Sun→Mon,
        // straddling the EU spring-forward transition on Sunday 2026-03-29.
        $this->settings([
            'planification.skip_weekends' => true,
            'planification.blackout_dates' => '2026-03-27',
        ]);

        $service = new BusinessCalendarService();
        $friday = Carbon::parse('2026-03-27 09:00:00', 'UTC'); // 10:00 Europe/Paris (CET)

        $next = $service->shiftToAllowed($friday, 'Europe/Paris');

        $this->assertSame('2026-03-30 10:00', $next->copy()->setTimezone('Europe/Paris')->format('Y-m-d H:i'));
        $this->assertSame('2026-03-30 08:00', $next->format('Y-m-d H:i'));
        $this->assertSame('UTC', $next->timezoneName);
    }

    public function test_shift_to_allowed_is_idempotent_on_an_already_allowed_instant(): void
    {
        $this->settings([
            'planification.skip_weekends' => true,
            'planification.blackout_dates' => '',
        ]);

        $service = new BusinessCalendarService();
        $monday = Carbon::parse('2026-03-30 08:00:00', 'UTC'); // Monday 10:00 Europe/Paris, already allowed

        $shifted = $service->shiftToAllowed($monday, 'Europe/Paris');

        $this->assertTrue($shifted->equalTo($monday));

        // Calling it again on the already-shifted result must not move it further.
        $shiftedAgain = $service->shiftToAllowed($shifted, 'Europe/Paris');
        $this->assertTrue($shiftedAgain->equalTo($shifted));
    }

    public function test_shift_to_allowed_bails_out_after_366_iterations_and_logs_an_error(): void
    {
        // 367 consecutive blocked days (the bound is 366 forward STEPS from the
        // starting day, so the starting day plus 366 more must all be blocked).
        $start = Carbon::parse('2026-01-01', 'Europe/Paris');
        $lines = [];
        for ($i = 0; $i <= 366; $i++) {
            $lines[] = $start->copy()->addDays($i)->format('Y-m-d');
        }

        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => implode("\n", $lines),
        ]);

        Log::shouldReceive('error')->once();

        $service = new BusinessCalendarService();
        $from = Carbon::parse('2026-01-01 08:00:00', 'UTC');

        $result = $service->shiftToAllowed($from, 'Europe/Paris');

        $this->assertTrue($result->equalTo($from));
    }

    // ── Memo ─────────────────────────────────────────────────────────────────────

    public function test_memo_invalidates_after_the_underlying_setting_changes_within_one_process(): void
    {
        // One long-lived instance: the point is that the service's OWN memo
        // (keyed on the raw setting strings) re-reads when the setting changes,
        // exactly like DomainBlocklistTest::test_a_changed_setting_invalidates_the_memo.
        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => '2026-01-01',
        ]);
        $service = new BusinessCalendarService();

        $this->assertTrue($service->isBlockedDate(Carbon::parse('2026-01-01', 'Europe/Paris')));
        $this->assertFalse($service->isBlockedDate(Carbon::parse('2026-01-02', 'Europe/Paris')));

        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => '2026-01-02',
        ]);

        $this->assertFalse($service->isBlockedDate(Carbon::parse('2026-01-01', 'Europe/Paris')));
        $this->assertTrue($service->isBlockedDate(Carbon::parse('2026-01-02', 'Europe/Paris')));
    }

    // ── blockedDatesFor / resolveTimezone ───────────────────────────────────────

    public function test_blocked_dates_for_lists_weekends_and_blackout_dates_within_the_window(): void
    {
        $this->settings([
            'planification.skip_weekends' => true,
            'planification.blackout_dates' => '2026-01-01',
        ]);

        $service = new BusinessCalendarService();

        // 2026-01-01 is a Thursday — blacked out explicitly, plus the following
        // Saturday/Sunday from the weekend rule.
        $blocked = $service->blockedDatesFor(Carbon::parse('2026-01-01', 'Europe/Paris'), 5);

        $this->assertSame(['2026-01-01', '2026-01-03', '2026-01-04'], $blocked);
    }

    public function test_resolve_timezone_falls_back_to_decouverte_timezone_when_no_campaign(): void
    {
        $this->settings([
            'planification.skip_weekends' => true,
            'planification.blackout_dates' => '',
            'decouverte.timezone' => 'UTC',
        ]);

        $service = new BusinessCalendarService();

        $this->assertSame('UTC', $service->resolveTimezone(null));
    }

    // ── blackoutDatesFor ─────────────────────────────────────────────────────────

    public function test_blackout_dates_for_excludes_plain_weekends_but_includes_a_blackout_entry_on_a_saturday(): void
    {
        // 2026-01-03 is a Saturday — put it in the blackout list explicitly.
        // 2026-01-04 (Sunday) is a plain weekend with no blackout entry.
        $this->settings([
            'planification.skip_weekends' => true,
            'planification.blackout_dates' => '2026-01-03',
        ]);

        $service = new BusinessCalendarService();

        $blackout = $service->blackoutDatesFor(Carbon::parse('2026-01-01', 'Europe/Paris'), 5);

        // Only the explicit blackout entry appears — even though it falls on a
        // weekend, it must show (the admin typed it). The plain Sunday
        // (2026-01-04) must NOT appear: it is not in the blackout list.
        $this->assertSame(['2026-01-03'], $blackout);
    }

    public function test_blackout_dates_for_resolves_annual_mm_dd_entries_within_the_window(): void
    {
        $this->settings([
            'planification.skip_weekends' => false,
            'planification.blackout_dates' => '01-01',
        ]);

        $service = new BusinessCalendarService();

        $blackout = $service->blackoutDatesFor(Carbon::parse('2026-01-01', 'Europe/Paris'), 3);

        $this->assertSame(['2026-01-01'], $blackout);
    }

    public function test_blackout_dates_for_returns_empty_when_list_is_empty_even_though_blocked_dates_for_returns_weekends(): void
    {
        $this->settings([
            'planification.skip_weekends' => true,
            'planification.blackout_dates' => '',
        ]);

        $service = new BusinessCalendarService();

        // Window 2026-01-01 (Thursday) .. +4 days includes the weekend of
        // 2026-01-03/04 — blockedDatesFor() must report those two days, while
        // blackoutDatesFor() over the identical window reports nothing.
        $window = Carbon::parse('2026-01-01', 'Europe/Paris');

        $this->assertSame(['2026-01-03', '2026-01-04'], $service->blockedDatesFor($window->copy(), 5));
        $this->assertSame([], $service->blackoutDatesFor($window->copy(), 5));
    }
}
