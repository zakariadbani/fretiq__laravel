<?php

declare(strict_types=1);

namespace Tests\Unit\Discovery;

use App\Exceptions\InvalidEnrichmentDomainException;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Services\Discovery\ContactVerificationBatchService;
use App\Services\Discovery\EnrichmentDrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * EnrichmentDrainServiceTest — money-path loop + eligibility correctness.
 *
 * CompanyEnrichmentService is mocked: enrich() never touches the DB here, so a
 * mocked-enriched company STAYS eligible (NULL status, no contacts) — which is
 * exactly how a persistent hunter_empty behaves in production and is what proves
 * the cursor actually advances instead of re-hitting (and re-charging) a row.
 */
class EnrichmentDrainServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(CompanyEnrichmentService $enrichment): EnrichmentDrainService
    {
        // Real verification batch is harmless: no test here calls drainContacts().
        return new EnrichmentDrainService($enrichment, new ContactVerificationBatchService);
    }

    private function enriched(): array
    {
        return ['outcome' => 'enriched', 'contacts_count' => 1, 'successful_enrichments' => 1];
    }

    private function providerFailed(): array
    {
        return ['outcome' => 'provider_failed', 'contacts_count' => 0, 'successful_enrichments' => 0];
    }

    public function test_eligible_query_includes_null_and_failed_excludes_others(): void
    {
        $null = Company::factory()->create(['enrichment_status' => null]);
        $failed = Company::factory()->create(['enrichment_status' => Company::ENRICHMENT_HUNTER_FAILED]);
        $enriched = Company::factory()->create(['enrichment_status' => Company::ENRICHMENT_ENRICHED]);
        $empty = Company::factory()->create(['enrichment_status' => Company::ENRICHMENT_HUNTER_EMPTY]);
        $withContact = Company::factory()->create(['enrichment_status' => null]);
        Contact::factory()->create(['company_id' => $withContact->id]);
        $noDomain = Company::factory()->create(['enrichment_status' => null, 'domain' => null]);

        $service = $this->service(Mockery::mock(CompanyEnrichmentService::class));

        $default = $service->eligibleCompaniesQuery()->pluck('id')->all();
        $this->assertContains($null->id, $default, 'NULL enrichment_status must be eligible.');
        $this->assertContains($failed->id, $default, 'hunter_failed must be eligible.');
        $this->assertNotContains($enriched->id, $default, 'enriched must be excluded.');
        $this->assertNotContains($empty->id, $default, 'hunter_empty must be excluded by default.');
        $this->assertNotContains($withContact->id, $default, 'a company with a contact must be excluded.');
        $this->assertNotContains($noDomain->id, $default, 'a company without a domain must be excluded.');

        $withEmpty = $service->eligibleCompaniesQuery(includeEmpty: true)->pluck('id')->all();
        $this->assertContains($empty->id, $withEmpty, 'hunter_empty must be eligible with --include-empty.');
    }

    public function test_drain_stops_on_provider_failed_without_processing_further(): void
    {
        // Two eligible companies; the first attempt fails systemically.
        Company::factory()->create(['enrichment_status' => null]);
        Company::factory()->create(['enrichment_status' => null]);

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrich')->once()->andReturn($this->providerFailed());

        $summary = $this->service($enrichment)->drainCompanies(10);

        $this->assertSame('provider_failed', $summary['stopped_reason']);
        $this->assertSame(1, $summary['processed']);
        $this->assertSame(1, $summary['failed']);
        $this->assertFalse($summary['exhausted']);
    }

    public function test_cursor_advances_past_each_company_no_infinite_rehit(): void
    {
        // Three eligible companies. Mocked enrich leaves every row eligible, so a
        // missing cursor advance would re-fetch the same id forever. Correct impl
        // visits each exactly once and then exhausts the backlog.
        Company::factory()->count(3)->create(['enrichment_status' => null]);

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrich')->times(3)->andReturn($this->enriched());

        $summary = $this->service($enrichment)->drainCompanies(10);

        $this->assertSame(3, $summary['processed']);
        $this->assertSame(3, $summary['enriched']);
        $this->assertTrue($summary['exhausted']);
        $this->assertNull($summary['stopped_reason']);
    }

    public function test_invalid_domain_skips_and_continues_to_next(): void
    {
        $a = Company::factory()->create(['enrichment_status' => null]);
        $b = Company::factory()->create(['enrichment_status' => null]);

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrich')
            ->andReturnUsing(function (Company $company) use ($a): array {
                if ($company->id === $a->id) {
                    throw new InvalidEnrichmentDomainException(InvalidEnrichmentDomainException::BLOCKED);
                }

                return $this->enriched();
            });

        $summary = $this->service($enrichment)->drainCompanies(10);

        // A is skipped (not counted, no spend); B is enriched; loop then exhausts.
        $this->assertSame(1, $summary['processed']);
        $this->assertSame(1, $summary['enriched']);
        $this->assertTrue($summary['exhausted']);
        $this->assertNull($summary['stopped_reason']);
        $this->assertSame($b->id, $summary['last_id']);
    }
}
