<?php

namespace Tests\Unit;

use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Settings\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Unit coverage for the wall-clock deadline guard in DiscoveryPipelineService.
 *
 * The guard stops a long attempt cleanly before RunDiscoveryPipelineJob's own
 * $timeout (300 s) kills it mid-candidate. Both halves of the decision are
 * exercised through reflection so nothing here has to simulate a 240-second run:
 *
 *   - runTimeBudget()      resolves the budget, defaulting defensively.
 *   - timeBudgetExceeded() compares elapsed against it, with an injectable $now.
 *
 * DB safety: this test never touches the `fretiq` dev database. It purges onto an
 * in-memory sqlite connection and primes the `app_settings` cache key directly
 * rather than writing settings rows via Setting::set().
 */
class DiscoveryPipelineTimeBudgetTest extends TestCase
{
    /** Mirrors DiscoveryPipelineService::DEFAULT_RUN_TIME_BUDGET. */
    private const DEFAULT_BUDGET = 240;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();

        $this->primeSettings([]);
    }

    /**
     * Seed the settings map without writing to the database.
     *
     * clearCache() first so SettingService's in-process memo cannot serve a stale
     * map from an earlier assertion in the same test.
     *
     * @param  array<string, mixed>  $map
     */
    private function primeSettings(array $map): void
    {
        app(SettingService::class)->clearCache();
        Cache::put('app_settings', $map, 60);
    }

    /**
     * Constructor-less instance — neither helper touches a collaborator.
     */
    private function service(): DiscoveryPipelineService
    {
        return (new ReflectionClass(DiscoveryPipelineService::class))
            ->newInstanceWithoutConstructor();
    }

    private function invoke(string $method, array $args): mixed
    {
        $service = $this->service();
        $reflected = (new ReflectionClass($service))->getMethod($method);
        $reflected->setAccessible(true);

        return $reflected->invokeArgs($service, $args);
    }

    // ── runTimeBudget(): defensive setting resolution ─────────────────────────

    public function test_a_valid_setting_is_honoured(): void
    {
        $this->primeSettings(['decouverte.run_time_budget' => 600]);

        $this->assertSame(600, $this->invoke('runTimeBudget', []));
    }

    public function test_a_numeric_string_setting_is_honoured(): void
    {
        $this->primeSettings(['decouverte.run_time_budget' => '120']);

        $this->assertSame(120, $this->invoke('runTimeBudget', []));
    }

    /**
     * The footgun this guard exists for: honouring a stored 0 would make every
     * attempt stop before its first candidate, silently disabling all discovery.
     */
    public function test_a_zero_setting_falls_back_to_the_default(): void
    {
        $this->primeSettings(['decouverte.run_time_budget' => 0]);

        $this->assertSame(self::DEFAULT_BUDGET, $this->invoke('runTimeBudget', []));
    }

    public function test_a_negative_setting_falls_back_to_the_default(): void
    {
        $this->primeSettings(['decouverte.run_time_budget' => -30]);

        $this->assertSame(self::DEFAULT_BUDGET, $this->invoke('runTimeBudget', []));
    }

    public function test_a_missing_setting_falls_back_to_the_default(): void
    {
        $this->primeSettings([]);

        $this->assertSame(self::DEFAULT_BUDGET, $this->invoke('runTimeBudget', []));
    }

    public function test_a_non_numeric_setting_falls_back_to_the_default(): void
    {
        $this->primeSettings(['decouverte.run_time_budget' => 'plus tard']);

        $this->assertSame(self::DEFAULT_BUDGET, $this->invoke('runTimeBudget', []));
    }

    public function test_a_null_setting_falls_back_to_the_default(): void
    {
        $this->primeSettings(['decouverte.run_time_budget' => null]);

        $this->assertSame(self::DEFAULT_BUDGET, $this->invoke('runTimeBudget', []));
    }

    // ── timeBudgetExceeded(): the elapsed-vs-budget decision ──────────────────

    public function test_the_loop_stops_once_elapsed_passes_the_budget(): void
    {
        // started at t=1000, budget 240 s, now t=1241 → 241 s elapsed.
        $this->assertTrue($this->invoke('timeBudgetExceeded', [1000.0, 240, 1241.0]));
    }

    public function test_the_loop_continues_while_elapsed_is_under_the_budget(): void
    {
        // 239 s elapsed against a 240 s budget — one more candidate is allowed.
        $this->assertFalse($this->invoke('timeBudgetExceeded', [1000.0, 240, 1239.0]));
    }

    public function test_the_budget_boundary_stops_the_loop(): void
    {
        // Exactly at the budget: stop. The comparison is >=, so the guard errs
        // toward ending cleanly rather than starting a candidate it cannot finish.
        $this->assertTrue($this->invoke('timeBudgetExceeded', [1000.0, 240, 1240.0]));
    }

    public function test_a_freshly_started_attempt_is_never_immediately_stopped(): void
    {
        $this->assertFalse($this->invoke('timeBudgetExceeded', [microtime(true), self::DEFAULT_BUDGET]));
    }
}
