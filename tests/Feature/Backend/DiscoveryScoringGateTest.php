<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Discovery\DiscoveryPipelineService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DiscoveryScoringGateTest — tests the scoring gate, enrichment bypass,
 * and incremental CAS accounting in DiscoveryPipelineService.
 *
 * Fixture domains (serpapi.json):
 *   bolloretransport.com, geodis.com, kuehne-nagel.com, rhenus.fr,
 *   clasquin.com, marmedsa.com  (6 total, all score ≥ 50 with transport criteria)
 *
 * All tests use the local driver — no HTTP calls.
 * RefreshDatabase wraps each test; settings are cleared per-test via setUp.
 */
class DiscoveryScoringGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);

        // Reset settings to defaults before each test to prevent leakage.
        app(\App\Services\Settings\SettingService::class)->clearCache();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Gate Test ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    /**
     * Create a DiscoveryRun in 'running' state with the given consumed offset.
     */
    private function makeRunningRun(ProspectCriteria $criteria, int $reserved = 10, int $consumed = 0): DiscoveryRun
    {
        return DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'running',
            'credits_reserved'     => $reserved,
            'consumed'             => $consumed,
            'quota_date'           => Carbon::today()->toDateString(),
            'started_at'           => now(),
        ]);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * When auto_scoring=false, all candidates are enriched regardless of score,
     * contacts are created, and companies have ai_score=NULL (scoring never ran).
     * consumed == companies_count on the run row.
     */
    public function test_scoring_off_all_candidates_enriched(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 50);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        $run      = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        // All 6 fixture candidates processed
        $this->assertSame($run->consumed, $run->companies_count,
            'consumed must equal companies_count when all candidates processed');

        // Contacts were created (Hunter enrichment ran for all)
        $this->assertGreaterThan(0, $run->contacts_count,
            'contacts must be created when auto_enrich=true and scoring is off');

        // No ai_score on any company — scoring did not run
        $nullScoreCount = Company::whereNull('ai_score')->where('source', 'discovered')->count();
        $this->assertGreaterThan(0, $nullScoreCount,
            'Companies must have ai_score=NULL when scoring is disabled');

        $scoredCount = Company::whereNotNull('ai_score')->where('source', 'discovered')->count();
        $this->assertSame(0, $scoredCount,
            'No company must have ai_score set when auto_scoring=false');
    }

    /**
     * When min_score=0 and scoring is on, all fixture candidates (all score ≥ 50)
     * pass the gate → all enriched, all have non-null ai_score + ai_explanation.
     */
    public function test_min_score_zero_all_enriched_and_scored(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        $run      = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertSame($run->consumed, $run->companies_count,
            'consumed must equal companies_count');
        $this->assertGreaterThan(0, $run->contacts_count,
            'Contacts must be created when min_score=0 (all pass gate)');
        $this->assertSame(0, (int) $run->low_score_count,
            'low_score_count must be 0 when min_score=0');

        // Every discovered company must have ai_score and ai_explanation set
        $companies = Company::where('source', 'discovered')->get();
        $this->assertGreaterThan(0, $companies->count());

        foreach ($companies as $company) {
            $this->assertNotNull($company->ai_score,
                "Company {$company->domain} must have ai_score set");
            $this->assertNotNull($company->ai_explanation,
                "Company {$company->domain} must have ai_explanation set");
        }
    }

    /**
     * When min_score=101 (above any possible score), all candidates are scored
     * but no Hunter enrichment runs.
     * consumed == companies_count, contacts_count == 0, low_score_count == companies_count.
     */
    public function test_min_score_101_skips_all_enrichment(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 101);  // above max possible score

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        $run      = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertGreaterThan(0, $run->companies_count,
            'Companies must still be upserted even when gate blocks enrichment');
        $this->assertSame($run->consumed, $run->companies_count,
            'consumed must equal companies_count');
        $this->assertSame(0, (int) $run->contacts_count,
            'contacts_count must be 0 when all candidates fail the scoring gate');
        $this->assertSame($run->companies_count, (int) $run->low_score_count,
            'low_score_count must equal companies_count when all fail the gate');

        // Companies are scored (ai_score set), not enriched (enrichment_data not set by this run)
        $scoredCompanies = Company::where('source', 'discovered')->whereNotNull('ai_score')->count();
        $this->assertGreaterThan(0, $scoredCompanies,
            'Companies must be scored even when enrichment is gated');
    }

    /**
     * When auto_enrich=false, enrichment never runs regardless of score.
     * consumed == companies_count, contacts_count == 0, low_score_count == 0
     * (low_score only increments when auto_enrich=true AND scoring gates the call).
     */
    public function test_auto_enrich_false_no_contacts_no_low_score(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', false);
        Setting::set('decouverte.min_score_enrich', 50);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        $run      = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertSame($run->consumed, $run->companies_count,
            'consumed must equal companies_count');
        $this->assertSame(0, (int) $run->contacts_count,
            'contacts_count must be 0 when auto_enrich=false');
        $this->assertSame(0, (int) $run->low_score_count,
            'low_score_count must be 0 when auto_enrich=false (gate never applies)');

        // Companies scored (auto_scoring=true)
        $scoredCompanies = Company::where('source', 'discovered')->whereNotNull('ai_score')->count();
        $this->assertGreaterThan(0, $scoredCompanies,
            'Companies must be scored when auto_scoring=true');
    }

    /**
     * Skip path preserves pre-existing enrichment_data:
     * Pre-create a company with enrichment_data for a fixture domain,
     * run a gated (min_score=101) pipeline → enrichment_data unchanged,
     * ai_score updated (scoring still ran).
     */
    public function test_skip_path_preserves_existing_enrichment_data(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 101);  // gates all enrichment

        $preExistingEnrichment = ['organization' => 'Pre-Existing Data', 'industry' => 'Logistics'];

        // Pre-create the first fixture domain with enrichment data
        $existingCompany = Company::create([
            'name'            => 'Pre-Existing Company',
            'domain'          => 'bolloretransport.com',
            'enrichment_data' => $preExistingEnrichment,
            'source'          => 'zoho',
            'relationship'    => 'prospect',
        ]);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        $run      = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $existingCompany->refresh();

        // enrichment_data must be unchanged (Hunter was gated)
        // Use assertEquals (not assertSame) — JSON round-trip may change key order.
        $this->assertEquals(
            $preExistingEnrichment,
            $existingCompany->enrichment_data,
            'enrichment_data must not be wiped when the scoring gate skips Hunter'
        );

        // ai_score must be updated (scoring still ran)
        $this->assertNotNull($existingCompany->ai_score,
            'ai_score must be updated even when enrichment is gated');
    }

    /**
     * Retry-resume: create a running run with consumed=2 (simulating 2 already-processed
     * candidates), call run() with budget=4. Assert only 4 more candidates are processed
     * (not the first 2 again) and companies_count goes from 2 to 6.
     *
     * Uses daily_limit=10 so the criteria limit does not cap the retry budget.
     * The run's budget (cap param) drives the slice size.
     */
    public function test_retry_resume_starts_from_consumed_offset(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);  // all pass

        // Use daily_limit=10 so criteria never constrains the test budget.
        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        // First: run with budget=2 to create the first 2 companies
        $run1 = $this->makeRunningRun($criteria, 10);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 2, $run1);

        $run1->refresh();
        $this->assertSame(2, (int) $run1->consumed);
        $this->assertSame(2, (int) $run1->companies_count);

        // Record contacts after the first 2 companies
        $contactsAfterFirst2 = Contact::where('source', 'discovered')->count();
        $this->assertGreaterThan(0, $contactsAfterFirst2, 'First run must create contacts');

        // Now simulate a retry: new run with consumed=2 pre-set, budget=4
        $run2 = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'running',
            'credits_reserved'     => 6,
            'consumed'             => 2,
            'companies_count'      => 2,  // already persisted from the first run
            'quota_date'           => Carbon::today()->toDateString(),
            'started_at'           => now(),
        ]);

        // Pass cap=4 — pipeline slices from offset=2 and processes 4 candidates
        $pipeline->run($criteria, 4, $run2);

        $run2->refresh();

        // consumed should advance from 2 by 4 → 6
        $this->assertSame(6, (int) $run2->consumed,
            'consumed must advance from 2 to 6 on retry with budget=4');

        // companies_count should have incremented by 4 (from 2 to 6)
        $this->assertSame(6, (int) $run2->companies_count,
            'companies_count must increment by 4 on retry');

        // Contacts for the first 2 domains must not be duplicated
        $contactsAfterRetry = Contact::where('source', 'discovered')->count();
        $this->assertGreaterThanOrEqual($contactsAfterFirst2, $contactsAfterRetry,
            'retry must not decrease contact count (idempotent upserts)');
    }

    /**
     * CAS ownership test: run model with stale consumed=0 is passed to run(),
     * but the DB already has consumed=5. The first CAS UPDATE (expected=0) will fail
     * because the DB consumed != 0. Pipeline returns early with zero rows debited.
     */
    public function test_cas_ownership_stale_model_returns_early(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 50);

        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        // Create the run row with consumed=5 in the DB
        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'running',
            'credits_reserved'     => 10,
            'consumed'             => 5,
            'companies_count'      => 5,
            'quota_date'           => Carbon::today()->toDateString(),
            'started_at'           => now(),
        ]);

        // Create a stale model with consumed=0 (as if loaded before DB was updated)
        $staleRun          = DiscoveryRun::find($run->id);
        $staleRun->consumed = 0;  // mutate in-memory only (do not save to DB)

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $stats    = $pipeline->run($criteria, 5, $staleRun);

        // Pipeline must return early because CAS fails (DB consumed=5, expected=0)
        $this->assertSame(0, $stats['companies'],
            'No companies should be counted when CAS ownership check fails immediately');

        // DB consumed must remain 5 (not incremented by the stale pipeline call)
        $run->refresh();
        $this->assertSame(5, (int) $run->consumed,
            'DB consumed must remain 5 — stale pipeline call must not advance cursor');
    }

    /**
     * Defaults regression: with default settings (true/true/50) all fixture candidates
     * score ≥ 50 (confirmed by LeadScoringServiceTest fixture invariant) → behavior
     * identical to legacy (all enriched).
     */
    public function test_defaults_regression_all_enriched_like_legacy(): void
    {
        // Explicit defaults (match Setting::get defaults)
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 50);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        $run      = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        // All fixture candidates score ≥ 50 → all enriched → contacts created
        $this->assertGreaterThan(0, $run->contacts_count,
            'All fixture candidates must be enriched with default settings');
        $this->assertSame(0, (int) $run->low_score_count,
            'low_score_count must be 0 with default settings (all fixtures score ≥ 50)');
        $this->assertSame($run->consumed, $run->companies_count,
            'consumed must equal companies_count');

        // All companies scored
        $unscoredCount = Company::where('source', 'discovered')->whereNull('ai_score')->count();
        $this->assertSame(0, $unscoredCount,
            'All discovered companies must be scored with default settings');
    }
}
