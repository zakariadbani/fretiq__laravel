<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryPipelineService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * The fixtures include both 'generic' (→ email_kind='role') and 'personal'
     * (→ email_kind='personal') Hunter types — both must appear in the output.
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

        // Both email_kind values must appear (fixtures mix generic+personal)
        $roleCount     = $discoveredContacts->where('email_kind', 'role')->count();
        $personalCount = $discoveredContacts->where('email_kind', 'personal')->count();

        $this->assertGreaterThan(0, $roleCount, 'At least one contact must have email_kind=role (from Hunter type=generic)');
        $this->assertGreaterThan(0, $personalCount, 'At least one contact must have email_kind=personal (from Hunter type=personal)');

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
     * When daily_limit=1, the pipeline must create at most 1 discovered company.
     * (If the fixture returns fewer results than the cap, the count is <= daily_limit.)
     */
    public function test_pipeline_respects_daily_limit(): void
    {
        $criteria = $this->makeCriteria(['daily_limit' => 1]);

        /** @var DiscoveryPipelineService $pipeline */
        $pipeline = app(DiscoveryPipelineService::class);
        $pipeline->run($criteria);

        $discoveredCount = Company::where('source', 'discovered')->count();

        $this->assertLessThanOrEqual(
            1,
            $discoveredCount,
            'Pipeline must honour daily_limit=1 — at most 1 company may be created'
        );
    }
}
