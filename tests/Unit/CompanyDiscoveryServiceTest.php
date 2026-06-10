<?php

namespace Tests\Unit;

use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyDiscoveryService;
use Tests\TestCase;

/**
 * CompanyDiscoveryServiceTest — pure unit tests for buildQueries().
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because buildQueries()
 * calls config('global.data.company_countries') which requires the Laravel app.
 * No RefreshDatabase — unsaved ProspectCriteria instances used (fill() + no DB).
 */
class CompanyDiscoveryServiceTest extends TestCase
{
    private CompanyDiscoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CompanyDiscoveryService();
    }

    /**
     * Helper — create an unsaved (no DB) ProspectCriteria with given attributes.
     */
    private function makeCriteria(array $attributes): ProspectCriteria
    {
        $criteria = new ProspectCriteria();
        $criteria->fill($attributes);
        return $criteria;
    }

    // ── ISO → French label mapping ─────────────────────────────────────────────

    /**
     * ISO code 'FR' should produce queries using "France", not "FR".
     */
    public function test_iso_code_fr_maps_to_france(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('France', $query, 'ISO "FR" should map to "France" in queries');
            $this->assertStringNotContainsString(' FR ', $query, 'Raw ISO code should not appear in queries');
        }
    }

    /**
     * ISO code 'DE' should produce queries using "Allemagne", not "DE".
     */
    public function test_iso_code_de_maps_to_allemagne(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['DE'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $allemagne = array_filter($queries, fn($q) => str_contains($q, 'Allemagne'));
        $this->assertNotEmpty($allemagne, 'ISO "DE" should map to "Allemagne"');
    }

    /**
     * Multiple ISO codes are all mapped.
     */
    public function test_multiple_iso_codes_all_mapped(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR', 'MA'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $hasMaroc  = (bool) array_filter($queries, fn($q) => str_contains($q, 'Maroc'));

        $this->assertTrue($hasFrance, 'FR should map to France');
        $this->assertTrue($hasMaroc,  'MA should map to Maroc');
    }

    // ── Legacy free-text passthrough ──────────────────────────────────────────

    /**
     * Legacy free-text "France" passes through unchanged (no double-mapping).
     */
    public function test_legacy_freetext_france_passes_through(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['France'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $this->assertTrue($hasFrance, 'Legacy "France" string should pass through unchanged');
    }

    /**
     * Unknown / junk country token passes through without error.
     */
    public function test_junk_country_token_passes_through(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['JUNK_COUNTRY_XYZ'],
            'sectors'   => [],
        ]);

        // Should not throw
        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasJunk = (bool) array_filter($queries, fn($q) => str_contains($q, 'JUNK_COUNTRY_XYZ'));
        $this->assertTrue($hasJunk, 'Junk token should pass through unchanged');
    }

    // ── Empty countries fallback ──────────────────────────────────────────────

    /**
     * Empty countries array falls back to France + Maroc.
     */
    public function test_empty_countries_falls_back_to_france_and_maroc(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => [],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $hasMaroc  = (bool) array_filter($queries, fn($q) => str_contains($q, 'Maroc'));

        $this->assertTrue($hasFrance, 'Empty countries should fall back to France');
        $this->assertTrue($hasMaroc,  'Empty countries should fall back to Maroc');
    }

    /**
     * Null countries falls back to France + Maroc.
     */
    public function test_null_countries_falls_back_to_france_and_maroc(): void
    {
        $criteria = $this->makeCriteria([
            'sectors' => [],
        ]);
        // countries not set → null

        $queries = $this->service->buildQueries($criteria);

        $this->assertNotEmpty($queries);
        $hasFrance = (bool) array_filter($queries, fn($q) => str_contains($q, 'France'));
        $this->assertTrue($hasFrance, 'Null countries should fall back to France');
    }

    // ── Query budget (D10) ────────────────────────────────────────────────────

    /**
     * UE-27 selection × sectors does not produce more queries than the budget cap.
     */
    public function test_query_budget_caps_oversized_criteria(): void
    {
        $eu27 = config('global.data.eu_country_codes', []);
        $allSectors = config('global.data.prospect_sectors', []);

        $criteria = $this->makeCriteria([
            'countries' => $eu27,         // 27 countries
            'sectors'   => $allSectors,   // 16 sectors
        ]);

        $queries = $this->service->buildQueries($criteria);
        $budget  = (int) config('services.serpapi.max_queries_per_run', 40);

        $this->assertLessThanOrEqual(
            $budget,
            count($queries),
            "Query count must not exceed budget cap of {$budget}"
        );
    }

    /**
     * Small criteria below budget produces all queries uncapped.
     */
    public function test_small_criteria_within_budget_produces_all_queries(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => ['Transport & Logistique'],
        ]);

        $queries = $this->service->buildQueries($criteria);
        $budget  = (int) config('services.serpapi.max_queries_per_run', 40);

        // 1 country × (2 sector queries + 6 freight keywords) = 8 queries — well under budget
        $this->assertLessThanOrEqual($budget, count($queries));
        // Should have at least the freight keywords + sector queries
        $this->assertGreaterThanOrEqual(8, count($queries));
    }

    // ── Sector queries ────────────────────────────────────────────────────────

    /**
     * When sectors are provided, sector-specific queries are generated.
     */
    public function test_sectors_produce_sector_specific_queries(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => ['Agroalimentaire'],
        ]);

        $queries = $this->service->buildQueries($criteria);

        $sectorQuery = array_filter($queries, fn($q) => str_contains($q, 'Agroalimentaire'));
        $this->assertNotEmpty($sectorQuery, 'Sector should appear in sector-specific queries');
    }

    /**
     * When no sectors provided, only freight-keyword queries are generated.
     */
    public function test_no_sectors_generates_only_freight_keyword_queries(): void
    {
        $criteria = $this->makeCriteria([
            'countries' => ['FR'],
            'sectors'   => [],
        ]);

        $queries = $this->service->buildQueries($criteria);

        // All queries should contain freight keywords
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression(
                '/transitaire|freight forwarder|commissionnaire de transport|logistique|transport maritime|transport aérien/',
                $query,
                'Without sectors, only freight-keyword queries expected'
            );
        }
    }
}
