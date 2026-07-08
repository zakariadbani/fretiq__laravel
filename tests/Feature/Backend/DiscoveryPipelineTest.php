<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\ContactUpsertService;
use App\Services\Discovery\DiscoveryPipelineService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DiscoveryPipelineTest — service-level tests for DiscoveryPipelineService.
 *
 * Runs the service directly (not the job) so the assertions are synchronous.
 * No auth is needed — these tests bypass HTTP entirely.
 *
 * Environment: DISCOVERY_DRIVER is unset in phpunit.xml → config defaults to
 * 'local' → CompanyDiscoveryService + HunterEnrichmentService read from
 *   database/fixtures/discovery/serpapi.json
 *   database/fixtures/discovery/hunter.json
 * No HTTP calls are made.
 *
 * Fixture domains (from serpapi.json):
 *   bolloretransport.com, geodis.com, kuehne-nagel.com, rhenus.fr,
 *   clasquin.com, marmedsa.com
 *
 * Hunter fixture keys: bolloretransport.com (generic+personal+generic),
 *   geodis.com (generic+personal), clasquin.com (generic+personal+generic).
 * All other domains fall back to the __default__ entry (generic+personal).
 */
class DiscoveryPipelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The first domain in serpapi.json — used for the client-downgrade guard test.
     * Reading the fixture in a const is not possible at parse time, so this is a
     * string literal kept in sync with the fixture file.
     */
    private const FIXTURE_DOMAIN_CLIENT = 'bolloretransport.com';

    protected function setUp(): void
    {
        parent::setUp();

        // The pipeline service uses Company/Contact models which require a clean DB.
        // ACL seeders are not needed here (no auth).
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name'        => 'Test Criteria ' . uniqid(),
            'sectors'     => ['transport'],
            'countries'   => ['France'],
            'daily_limit' => 10,
            'is_active'   => true,
        ], $overrides));
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * After running the pipeline, at least one Company with source='discovered'
     * and relationship='prospect' must exist, and every such company must have
     * the criteria_id pointing to the criteria used.
     */
    public function test_pipeline_creates_prospect_companies(): void
    {
        $criteria = $this->makeCriteria();

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria);

        $discoveredCount = Company::where('source', 'discovered')
            ->where('relationship', 'prospect')
            ->count();

        $this->assertGreaterThan(0, $discoveredCount, 'Pipeline must create at least one discovered prospect company');

        // Every discovered prospect company must be linked to this criteria
        $wrongCriteria = Company::where('source', 'discovered')
            ->where('relationship', 'prospect')
            ->where('criteria_id', '!=', $criteria->id)
            ->count();

        $this->assertSame(0, $wrongCriteria, 'All discovered companies must have the correct criteria_id');
    }

    /**
     * Discovered contacts must have legal_basis='legitimate_interest'.
     * Classification is now domain-based: all fixture emails are at corporate
     * domains (bolloretransport.com, geodis.com, clasquin.com, example-freight.com)
     * — none are free-webmail — so every contact must have email_kind='role'.
     * Each contact must have source_url and source_captured_at set.
     */
    public function test_pipeline_creates_legitimate_interest_contacts_with_email_kind(): void
    {
        $criteria = $this->makeCriteria();

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria);

        $discoveredContacts = Contact::where('source', 'discovered')
            ->where('legal_basis', 'legitimate_interest')
            ->get();

        $this->assertGreaterThan(
            0,
            $discoveredContacts->count(),
            'Pipeline must create at least one contact with legal_basis=legitimate_interest'
        );

        // All fixture emails are at corporate domains → domain-based classifier
        // assigns email_kind='role' to every one of them. No free-webmail domains
        // appear in hunter.json, so personal count must be 0.
        $roleCount     = $discoveredContacts->where('email_kind', 'role')->count();
        $personalCount = $discoveredContacts->where('email_kind', 'personal')->count();

        $this->assertGreaterThan(0, $roleCount, 'All fixture contacts are on corporate domains → must have email_kind=role');
        $this->assertSame(0, $personalCount, 'No fixture email is at a free-webmail domain → email_kind=personal must be 0');

        // Every discovered contact must carry provenance metadata
        foreach ($discoveredContacts as $contact) {
            $this->assertNotNull(
                $contact->source_url,
                "Contact {$contact->email} must have source_url set"
            );
            $this->assertNotNull(
                $contact->source_captured_at,
                "Contact {$contact->email} must have source_captured_at set"
            );
        }
    }

    /**
     * Running the pipeline twice must not duplicate discovered companies.
     * The second run is idempotent — count stays the same.
     */
    public function test_pipeline_is_idempotent(): void
    {
        $criteria = $this->makeCriteria();

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);

        $pipeline->run($criteria);
        $countAfterFirstRun = Company::where('source', 'discovered')->count();

        $pipeline->run($criteria);
        $countAfterSecondRun = Company::where('source', 'discovered')->count();

        $this->assertSame(
            $countAfterFirstRun,
            $countAfterSecondRun,
            'Running the pipeline twice must not create duplicate companies (idempotency)'
        );
    }

    /**
     * A company that already exists with relationship='client' must NOT be
     * downgraded to 'prospect' by the discovery pipeline.
     *
     * Fixture domain used: bolloretransport.com (first entry in serpapi.json).
     * This domain will be processed by the pipeline; the guard in
     * DiscoveryPipelineService::upsertCompany() must preserve 'client'.
     */
    public function test_pipeline_does_not_downgrade_client_companies(): void
    {
        // Pre-create the company as a client from Zoho
        $clientCompany = Company::create([
            'name'         => 'Bolloré Transport & Logistics (pre-existing)',
            'domain'       => self::FIXTURE_DOMAIN_CLIENT,
            'relationship' => 'client',
            'source'       => 'zoho',
            'qualification_status' => 'qualified',
        ]);

        $criteria = $this->makeCriteria();

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria);

        // Reload the company from DB — relationship must still be 'client'
        $clientCompany->refresh();

        $this->assertSame(
            'client',
            $clientCompany->relationship,
            'Discovery pipeline must not downgrade a client company to prospect'
        );
    }

    /**
     * daily_limit is a SerpAPI call budget, not a kept-company budget.
     * In local fixture mode, one search-call unit exposes up to one SerpAPI page
     * (10 candidates), so daily_limit=1 may create multiple companies.
     */
    public function test_pipeline_daily_limit_one_search_can_create_multiple_companies(): void
    {
        $criteria = $this->makeCriteria([
            'daily_limit' => 1,
            'auto_enrich' => false,
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria);

        $discoveredCount = Company::where('source', 'discovered')->count();

        $this->assertGreaterThan(
            1,
            $discoveredCount,
            'daily_limit=1 means one SerpAPI search page, not one final company'
        );
    }

    public function test_pipeline_reuses_sufficient_candidates_snapshot_without_serpapi_http(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
            'services.hunter.driver'   => 'local',
        ]);

        Http::fake();

        $criteria = $this->makeCriteria([
            'daily_limit' => 10,
            'auto_enrich' => false,
        ]);

        $snapshot = collect(range(0, 3))->map(fn ($i) => [
            'domain'          => "snapshot-{$i}.test",
            'title'           => "Snapshot {$i}",
            'snippet'         => 'Snapshot candidate',
            'url'             => "https://snapshot-{$i}.test",
            'discovery_query' => 'snapshot query',
        ])->all();

        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'running',
            'credits_reserved'     => 10,
            'consumed'             => 1,
            'companies_count'      => 0,
            'candidates_snapshot'  => $snapshot,
            'started_at'           => now(),
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 1, $run, 0);

        Http::assertNothingSent();

        $run->refresh();
        $this->assertSame(4, (int) $run->consumed);
        $this->assertSame(3, (int) $run->companies_count);
        $this->assertTrue(Company::where('domain', 'snapshot-1.test')->exists());
        $this->assertTrue(Company::where('domain', 'snapshot-3.test')->exists());
    }

    public function test_pipeline_extends_insufficient_candidates_snapshot_with_serpapi_page(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
            'services.hunter.driver'   => 'local',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => [
                    [
                        'title'   => 'Fresh One',
                        'link'    => 'https://fresh-one.test/about',
                        'snippet' => 'Fresh candidate',
                    ],
                    [
                        'title'   => 'Fresh Two',
                        'link'    => 'https://fresh-two.test/about',
                        'snippet' => 'Fresh candidate',
                    ],
                ],
            ], 200),
        ]);

        $criteria = $this->makeCriteria([
            'daily_limit' => 10,
            'auto_enrich' => false,
            'ai_queries'  => [['q' => 'snapshot query', 'enabled' => true]],
        ]);

        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'running',
            'credits_reserved'     => 10,
            'consumed'             => 1,
            'companies_count'      => 0,
            'candidates_snapshot'  => [[
                'domain'          => 'snapshot-only.test',
                'title'           => 'Snapshot Only',
                'snippet'         => 'Existing candidate',
                'url'             => 'https://snapshot-only.test',
                'discovery_query' => 'snapshot query',
            ]],
            'started_at'           => now(),
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria, 1, $run, 0);

        Http::assertSentCount(1);

        $run->refresh();
        $criteria->refresh();
        $key = md5('snapshot query');

        $this->assertCount(3, $run->candidates_snapshot);
        $this->assertSame(10, (int) data_get($criteria->discovery_cursors, "{$key}.start"));
        $this->assertTrue((bool) data_get($criteria->discovery_cursors, "{$key}.exhausted"));
    }

    public function test_duplicate_domain_query_exception_advances_cursor_without_aborting_run(): void
    {
        config([
            'services.serpapi.driver'  => 'serpapi',
            'services.serpapi.api_key' => 'test-key',
            'services.hunter.driver'   => 'local',
        ]);

        Http::fake([
            '*' => Http::response([
                'organic_results' => [[
                    'title'   => 'Race Domain',
                    'link'    => 'https://race-domain.test/about',
                    'snippet' => 'Fresh candidate',
                ]],
            ], 200),
        ]);

        $this->app->bind(ContactUpsertService::class, fn () => new class extends ContactUpsertService {
            public function upsertFromHunter(Company $company, string $domain, array $emails): int
            {
                throw new QueryException(
                    'mysql',
                    'insert into `companies` (`domain`) values (?)',
                    [$domain],
                    new \Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$domain}' for key 'companies_domain_unique'")
                );
            }
        });

        $criteria = $this->makeCriteria([
            'daily_limit'       => 10,
            'auto_enrich'       => true,
            'min_score_enrich'  => 0,
            'ai_queries'        => [['q' => 'duplicate query', 'enabled' => true]],
        ]);

        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type'                 => 'discovery',
            'status'               => 'running',
            'credits_reserved'     => 10,
            'consumed'             => 0,
            'companies_count'      => 0,
            'contact_consumed'     => 0,
            'started_at'           => now(),
        ]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $stats = $pipeline->run($criteria, 1, $run, 1);

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame(1, (int) $run->consumed);
        $this->assertSame(1, (int) $run->contact_consumed);
        $this->assertSame(0, (int) $run->companies_count);
        $this->assertSame(1, $stats['contacts_consumed']);
    }
}
