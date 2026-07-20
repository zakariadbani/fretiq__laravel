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

        config([
            'services.serpapi.driver' => 'local',
            'services.hunter.driver' => 'local',
        ]);

        // Reset settings to defaults before each test to prevent leakage.
        app(\App\Services\Settings\SettingService::class)->clearCache();
        Setting::set('decouverte.discovery_engines', ['google']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name' => 'Gate Test '.uniqid(),
            'sectors' => ['transport'],
            'countries' => ['France'],
            'daily_limit' => 10,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Create a DiscoveryRun in 'running' state with the given consumed offset.
     */
    private function makeRunningRun(ProspectCriteria $criteria, int $reserved = 10, int $consumed = 0): DiscoveryRun
    {
        return DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => $reserved,
            'consumed' => $consumed,
            'contact_credits_reserved' => $reserved,
            'contact_consumed' => 0,
            'successful_enrichments_target' => min(20, max(1, (int) ($criteria->contact_limit ?? 20))),
            'successful_enrichments' => 0,
            'quota_date' => Carbon::today()->toDateString(),
            'started_at' => now(),
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
        $run = $this->makeRunningRun($criteria, 6);

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
        $run = $this->makeRunningRun($criteria, 6);

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
        $run = $this->makeRunningRun($criteria, 6);

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
        $run = $this->makeRunningRun($criteria, 6);

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
            'name' => 'Pre-Existing Company',
            'domain' => 'bolloretransport.com',
            'enrichment_data' => $preExistingEnrichment,
            'source' => 'zoho',
            'relationship' => 'prospect',
        ]);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        $run = $this->makeRunningRun($criteria, 6);

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
     * candidates), call run() with enough SerpAPI-call budget to expose the local fixture.
     * Assert only the remaining 4 candidates are processed (not the first 2 again) and
     * companies_count goes from 2 to 6.
     *
     * daily_limit/cap now represent SerpAPI searches, not final companies. In local
     * fixture mode a cap of 2 exposes the whole six-domain fixture.
     */
    public function test_retry_resume_starts_from_consumed_offset(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);  // all pass

        // Use daily_limit=10 so criteria never constrains the test budget.
        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        // First: run with 2 SerpAPI-search units; local fixture contains 6 companies total.
        $run1 = $this->makeRunningRun($criteria, 10);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 2, $run1);

        $run1->refresh();
        $this->assertSame(6, (int) $run1->consumed);
        $this->assertSame(6, (int) $run1->companies_count);

        // Record contacts after the first complete run
        $contactsAfterFirst2 = Contact::where('source', 'discovered')->count();
        $this->assertGreaterThan(0, $contactsAfterFirst2, 'First run must create contacts');

        // Now simulate a retry: new run with consumed=2 pre-set, budget=4
        $run2 = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 6,
            'consumed' => 2,
            'companies_count' => 2,  // already persisted from the first run
            'contact_credits_reserved' => 6,
            'contact_consumed' => 0,
            'successful_enrichments_target' => min(20, max(1, (int) ($criteria->contact_limit ?? 20))),
            'successful_enrichments' => 0,
            'quota_date' => Carbon::today()->toDateString(),
            'started_at' => now(),
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
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 10,
            'consumed' => 5,
            'companies_count' => 5,
            'quota_date' => Carbon::today()->toDateString(),
            'started_at' => now(),
        ]);

        // Create a stale model with consumed=0 (as if loaded before DB was updated)
        $staleRun = DiscoveryRun::find($run->id);
        $staleRun->consumed = 0;  // mutate in-memory only (do not save to DB)

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $stats = $pipeline->run($criteria, 5, $staleRun);

        // Pipeline must return early because CAS fails (DB consumed=5, expected=0)
        $this->assertSame(0, $stats['companies'],
            'No companies should be counted when CAS ownership check fails immediately');

        // DB consumed must remain 5 (not incremented by the stale pipeline call)
        $run->refresh();
        $this->assertSame(5, (int) $run->consumed,
            'DB consumed must remain 5 — stale pipeline call must not advance cursor');
    }

    /**
     * Second separate run against the same fixture reports zero new companies.
     *
     * Run 1 inserts all 6 fixture domains (new_companies_count === 6).
     * Run 2 processes the same 6 domains again via upsert (update path) →
     * new_companies_count === 0, companies_count still === 6 (all re-processed),
     * and the total distinct company rows in the DB remains 6.
     */
    public function test_second_separate_run_reports_zero_new_companies(): void
    {
        config(['services.serpapi.driver' => 'local']);

        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);  // all pass gate

        $criteria = $this->makeCriteria(['daily_limit' => 10]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);

        // Run 1 — all 6 fixture domains are new inserts
        $run1 = $this->makeRunningRun($criteria, 10);
        $pipeline->run($criteria, 10, $run1);
        $run1->refresh();

        $this->assertSame(6, (int) $run1->companies_count,
            'Run 1 must process all 6 fixture candidates');
        $this->assertSame(6, (int) $run1->new_companies_count,
            'Run 1 must report 6 new inserts');

        // Run 2 — same domains, all update path → zero new inserts
        $run2 = $this->makeRunningRun($criteria, 10);
        $pipeline->run($criteria, 10, $run2);
        $run2->refresh();

        $this->assertSame(6, (int) $run2->companies_count,
            'Run 2 must re-process all 6 fixture candidates (companies_count = 6)');
        $this->assertSame(0, (int) $run2->new_companies_count,
            'Run 2 must report 0 new inserts (all domains already exist)');

        // Distinct company rows must remain 6 — no phantom inflation
        $this->assertSame(6, (int) Company::where('criteria_id', $criteria->id)->count(),
            'Only 6 distinct companies must exist after two runs of the same fixture');
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
        $run = $this->makeRunningRun($criteria, 6);

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

    // ── Per-criteria overrides (min_score_enrich / auto_enrich) ─────────────────

    /**
     * criteria.min_score_enrich overrides the global setting — global=0 (all pass)
     * but criteria override=101 (above max) gates all enrichment despite the
     * permissive global default.
     */
    public function test_criteria_min_score_override_beats_global(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);  // global: all pass

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'min_score_enrich' => 101]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertSame(0, (int) $run->contacts_count,
            'contacts_count must be 0 — criteria override min_score_enrich=101 beats the permissive global setting');
        $this->assertSame($run->companies_count, (int) $run->low_score_count,
            'low_score_count must equal companies_count when the criteria override gates all enrichment');
    }

    /**
     * criteria.min_score_enrich = null falls back to the global setting —
     * identical behavior to the pre-override code path.
     */
    public function test_null_min_score_enrich_falls_back_to_global(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);  // global: all pass

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'min_score_enrich' => null]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertGreaterThan(0, $run->contacts_count,
            'contacts_count must be > 0 — null override falls back to the permissive global min_score_enrich=0');
        $this->assertSame(0, (int) $run->low_score_count,
            'low_score_count must be 0 when the global (fallback) gate is permissive');
    }

    /**
     * criteria.auto_enrich = false overrides a global auto_enrich=true — no
     * Hunter calls / contacts regardless of the permissive global setting.
     */
    public function test_criteria_auto_enrich_false_overrides_global_true(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);  // global: enrich on
        Setting::set('decouverte.min_score_enrich', 0);

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'auto_enrich' => false]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertSame(0, (int) $run->contacts_count,
            'contacts_count must be 0 — criteria auto_enrich=false overrides the global auto_enrich=true');
        $this->assertSame(0, (int) $run->contact_consumed,
            'contact_consumed must be 0 — Hunter must never be called when the criteria override disables enrichment');
    }

    /**
     * criteria.auto_enrich = true overrides a global auto_enrich=false — Hunter
     * still runs despite the restrictive global default.
     */
    public function test_criteria_auto_enrich_true_overrides_global_false(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', false);  // global: enrich off
        Setting::set('decouverte.min_score_enrich', 0);

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'auto_enrich' => true]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertGreaterThan(0, $run->contacts_count,
            'contacts_count must be > 0 — criteria auto_enrich=true overrides the global auto_enrich=false');
    }

    /**
     * criteria.auto_enrich = null falls back to the global setting — identical
     * behavior to the pre-override code path.
     */
    public function test_null_auto_enrich_inherits_global(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', false);  // global: enrich off
        Setting::set('decouverte.min_score_enrich', 0);

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'auto_enrich' => null]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertSame(0, (int) $run->contacts_count,
            'contacts_count must be 0 — null override falls back to the restrictive global auto_enrich=false');
    }

    public function test_criteria_min_score_null_falls_back_to_global(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 101);

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'min_score_enrich' => null]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertSame(0, (int) $run->contacts_count);
    }

    /**
     * Product decision guard: enrichment is opted into PER CRITERIA. With NO
     * decouverte.auto_enrich settings row at all (fresh install) and a criteria left
     * on « Hérité » (auto_enrich = null), the code-level fallback must be false —
     * Hunter is never called.
     *
     * Regression: the fallback used to be `true`, so a missing settings row silently
     * turned enrichment on for every criteria and burned contact credits.
     */
    public function test_missing_auto_enrich_setting_with_inherited_criteria_does_not_enrich(): void
    {
        // Deliberately do NOT set decouverte.auto_enrich — the row must not exist.
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.min_score_enrich', 0);  // permissive gate: only auto_enrich can block

        $this->assertFalse(
            Setting::has('decouverte.auto_enrich'),
            'Precondition: no decouverte.auto_enrich settings row must exist for this test'
        );

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'auto_enrich' => null]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertGreaterThan(0, (int) $run->companies_count,
            'Companies must still be discovered and scored — only enrichment is off');
        $this->assertSame(0, (int) $run->contacts_count,
            'contacts_count must be 0 — a missing auto_enrich setting falls back to false');
        $this->assertSame(0, (int) $run->contact_consumed,
            'contact_consumed must be 0 — Hunter must never be called');
        $this->assertSame(0, Contact::where('source', 'discovered')->count(),
            'No discovered contact rows may exist when enrichment never ran');
    }

    public function test_criteria_auto_enrich_null_inherits_global(): void
    {
        Setting::set('decouverte.auto_enrich', false);
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.min_score_enrich', 0);

        $criteria = $this->makeCriteria(['daily_limit' => 6, 'auto_enrich' => null]);
        $run = $this->makeRunningRun($criteria, 6);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 6, $run);

        $run->refresh();

        $this->assertSame(0, (int) $run->contacts_count);
    }

    // ── enrichment_status audit column ────────────────────────────────────────
    //
    // One case per branch of DiscoveryPipelineService::resolveEnrichmentStatus().
    // These answer the operational question "87 companies, 2 with contacts — why?"

    /**
     * Replace the container's HunterEnrichmentService with a stub returning $payload.
     * null models a provider failure (no API key / every provider call failed).
     */
    private function fakeHunter(?array $payload): void
    {
        $this->app->instance(
            \App\Services\Discovery\HunterEnrichmentService::class,
            new class($payload) extends \App\Services\Discovery\HunterEnrichmentService
            {
                public function __construct(private readonly ?array $payload) {}

                public function domainSearch(string $domain, int $limit = 10, ?int $timeoutSeconds = null): ?array
                {
                    return $this->payload;
                }

                public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null): array
                {
                    return $this->payload === null
                        ? ['status' => 'provider_failed', 'data' => null]
                        : ['status' => 'ok', 'data' => $this->payload];
                }
            }
        );
    }

    /**
     * Replace the container's LeadScoringService so every candidate is flagged as a
     * competitor (exclude=true) — the highest-precedence enrichment_status branch.
     */
    private function fakeExcludingScorer(): void
    {
        $this->app->instance(
            \App\Services\Scoring\LeadScoringService::class,
            new class extends \App\Services\Scoring\LeadScoringService
            {
                public function __construct() {}

                public function score(array $candidate, \App\Models\ProspectCriteria $criteria, ?int $timeoutSeconds = null): array
                {
                    return ['score' => 95, 'explanation' => 'Concurrent direct', 'exclude' => true];
                }
            }
        );
    }

    private function statusesOf(): array
    {
        return Company::withRejected()
            ->where('source', 'discovered')
            ->pluck('enrichment_status')
            ->unique()
            ->values()
            ->all();
    }

    public function test_enrichment_status_enriched_when_hunter_returns_emails(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $this->assertSame([Company::ENRICHMENT_ENRICHED], $this->statusesOf());
    }

    public function test_enrichment_status_hunter_empty_when_provider_returns_no_usable_emails(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);
        $this->fakeHunter(['organization' => null, 'industry' => null, 'country' => null, 'emails' => [], 'raw' => []]);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $this->assertSame([Company::ENRICHMENT_HUNTER_EMPTY], $this->statusesOf());
        $this->assertSame(0, Contact::count(), 'No contact may be created when Hunter returns no emails.');
    }

    public function test_enrichment_status_hunter_failed_when_provider_returns_null(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);
        $this->fakeHunter(null);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $this->assertEqualsCanonicalizing([
            Company::ENRICHMENT_HUNTER_FAILED,
            Company::ENRICHMENT_SKIPPED_PROVIDER_UNAVAILABLE,
        ], $this->statusesOf());
        $this->assertSame(1, Company::where(
            'enrichment_status',
            Company::ENRICHMENT_HUNTER_FAILED,
        )->count(), 'The provider circuit must stop Hunter after its first systemic failure.');
    }

    public function test_enrichment_status_skipped_low_score_below_threshold(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 101); // gates every candidate

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $this->assertSame([Company::ENRICHMENT_SKIPPED_LOW_SCORE], $this->statusesOf());
    }

    public function test_enrichment_status_skipped_enrich_off_beats_low_score(): void
    {
        // auto_enrich=false has higher precedence than the score gate, so even a
        // score-gating threshold must still report « enrichissement désactivé ».
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', false);
        Setting::set('decouverte.min_score_enrich', 101);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $this->assertSame([Company::ENRICHMENT_SKIPPED_ENRICH_OFF], $this->statusesOf());
    }

    public function test_enrichment_status_skipped_budget_once_contact_cap_is_spent(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);

        // contactCap=2 → the first 2 candidates enrich, the rest hit the budget wall.
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6), 2);

        $this->assertSame(
            2,
            Company::where('enrichment_status', Company::ENRICHMENT_ENRICHED)->count(),
            'Exactly the contact-capped number of companies may be enriched.'
        );
        $this->assertGreaterThan(
            0,
            Company::where('enrichment_status', Company::ENRICHMENT_SKIPPED_BUDGET)->count(),
            'Candidates processed after the contact budget is spent must report skipped_budget.'
        );
    }

    public function test_enrichment_status_skipped_excluded_wins_over_every_other_branch(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);
        $this->fakeExcludingScorer();

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $this->assertSame([Company::ENRICHMENT_SKIPPED_EXCLUDED], $this->statusesOf());
    }

    /**
     * The already-rejected early-continue branch must NOT rewrite the stored status:
     * re-discovering a known competitor costs no Gemini/Hunter call, so it has no new
     * information to record.
     */
    public function test_already_rejected_candidate_keeps_its_existing_enrichment_status(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        Setting::set('decouverte.auto_enrich', true);
        Setting::set('decouverte.min_score_enrich', 0);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);

        $rejected = Company::create([
            'name' => 'Known Competitor',
            'domain' => 'bolloretransport.com',
            'criteria_id' => $criteria->id,
            'qualification_status' => 'rejected',
            'enrichment_status' => Company::ENRICHMENT_ENRICHED,
            'relationship' => 'prospect',
            'source' => 'discovered',
        ]);

        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $rejected->refresh();

        $this->assertSame(
            Company::ENRICHMENT_ENRICHED,
            $rejected->enrichment_status,
            'The already-rejected short-circuit must leave enrichment_status untouched.'
        );
    }

    /**
     * NULL means "never attempted" — a manually created company must not be given a
     * status by a discovery run that never reaches its domain.
     */
    public function test_manually_created_company_keeps_null_enrichment_status(): void
    {
        $manual = Company::create([
            'name' => 'Manual Entry',
            'domain' => 'manual-only.test',
            'relationship' => 'prospect',
            'source' => 'manual',
        ]);

        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);

        $criteria = $this->makeCriteria(['daily_limit' => 6]);
        app(DiscoveryPipelineService::class)->run($criteria, 6, $this->makeRunningRun($criteria, 6));

        $manual->refresh();

        $this->assertNull($manual->enrichment_status);
    }
}
