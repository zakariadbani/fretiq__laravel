<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryPipelineService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

class DiscoveryPipelineCompanyUpsertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('criteria_id')->nullable();
            $table->string('domain')->unique();
            $table->string('name');
            $table->string('sector')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('relationship')->nullable();
            $table->string('source')->nullable();
            $table->json('enrichment_data')->nullable();
            $table->integer('ai_score')->nullable();
            $table->text('ai_explanation')->nullable();
            $table->string('qualification_status')->nullable();
            $table->boolean('is_active')->nullable();
            $table->string('discovery_query')->nullable();
            $table->string('phone')->nullable();
            $table->string('enrichment_status', 32)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Invoke the private upsertCompany() on a constructor-less service instance.
     */
    private function upsert(array $args): Company
    {
        $service = (new ReflectionClass(DiscoveryPipelineService::class))
            ->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('upsertCompany');
        $method->setAccessible(true);

        return $method->invokeArgs($service, $args);
    }

    private function makeCriteria(): ProspectCriteria
    {
        $criteria = new ProspectCriteria();
        $criteria->forceFill(['id' => 42]);
        $criteria->exists = true;

        return $criteria;
    }

    public function test_partial_enrichment_does_not_erase_existing_country_or_sector(): void
    {
        $company = Company::create([
            'domain' => 'existing.test',
            'name' => 'Existing Freight Company',
            'sector' => 'Freight & Logistics',
            'country' => 'FR',
            'relationship' => 'prospect',
            'source' => 'manual',
        ]);

        $criteria = new ProspectCriteria();
        $criteria->forceFill(['id' => 42]);
        $criteria->exists = true;

        $service = (new ReflectionClass(DiscoveryPipelineService::class))
            ->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('upsertCompany');
        $method->setAccessible(true);

        $method->invoke(
            $service,
            $criteria,
            'existing.test',
            ['title' => 'Rediscovered Company', 'discovery_query' => 'freight france'],
            [
                'organization' => 'Rediscovered Company',
                'industry' => null,
                'country' => null,
                'emails' => [],
                'raw' => ['company' => null, 'domain_search' => []],
            ],
            null,
            null,
            false,
            false,
        );

        $company->refresh();
        $this->assertSame('Freight & Logistics', $company->sector);
        $this->assertSame('FR', $company->country);
    }

    // ── Forward-compatible candidate metadata (Google Maps discovery source) ──
    //
    // Candidates may carry optional phone / country / sector_hint keys. They are
    // FALLBACKS ONLY: they fill a field the enrichment left empty on a row that is
    // itself empty for that field, and never overwrite data we already hold.

    public function test_candidate_metadata_fills_a_brand_new_company(): void
    {
        $company = $this->upsert([
            $this->makeCriteria(),
            'maps-new.test',
            [
                'title'       => 'Maps Discovered Co',
                'phone'       => '+33 1 23 45 67 89',
                'country'     => 'France',
                'sector_hint' => 'Logistique',
            ],
            null, null, null, false, false, null,
        ]);

        $this->assertSame('Logistique', $company->sector);
        $this->assertSame('FR', $company->country, 'country must go through the ISO-2 map.');
        $this->assertSame('+33 1 23 45 67 89', $company->phone);
    }

    public function test_candidate_metadata_never_overwrites_existing_company_data(): void
    {
        $company = Company::create([
            'domain'       => 'maps-existing.test',
            'name'         => 'Existing Co',
            'sector'       => 'Freight & Logistics',
            'country'      => 'FR',
            'phone'        => '0100000000',
            'relationship' => 'prospect',
            'source'       => 'manual',
        ]);

        $this->upsert([
            $this->makeCriteria(),
            'maps-existing.test',
            [
                'title'       => 'Existing Co',
                'phone'       => '+99 SHOULD NOT WIN',
                'country'     => 'Espagne',
                'sector_hint' => 'SHOULD NOT WIN',
            ],
            ['organization' => 'Existing Co', 'industry' => null, 'country' => null, 'emails' => [], 'raw' => []],
            null, null, false, false, null,
        ]);

        $company->refresh();
        $this->assertSame('Freight & Logistics', $company->sector);
        $this->assertSame('FR', $company->country);
        $this->assertSame('0100000000', $company->phone);
    }

    public function test_hunter_enrichment_beats_candidate_metadata(): void
    {
        $company = $this->upsert([
            $this->makeCriteria(),
            'maps-both.test',
            ['title' => 'Both', 'country' => 'Espagne', 'sector_hint' => 'Candidate Hint'],
            [
                'organization' => 'Both',
                'industry'     => 'Hunter Sector',
                'country'      => 'Italie',
                'emails'       => [],
                'raw'          => [],
            ],
            null, null, false, false, null,
        ]);

        $this->assertSame('Hunter Sector', $company->sector);
        $this->assertSame('IT', $company->country);
    }

    // ── enrichment_status persistence ────────────────────────────────────────

    public function test_enrichment_status_is_persisted_in_the_same_write(): void
    {
        $company = $this->upsert([
            $this->makeCriteria(),
            'status.test',
            ['title' => 'Status Co'],
            null, null, null, false, false,
            Company::ENRICHMENT_SKIPPED_LOW_SCORE,
        ]);

        $this->assertSame(Company::ENRICHMENT_SKIPPED_LOW_SCORE, $company->enrichment_status);
        $this->assertSame(
            Company::ENRICHMENT_SKIPPED_LOW_SCORE,
            Company::withRejected()->where('domain', 'status.test')->first()->enrichment_status
        );
    }

    public function test_null_enrichment_status_leaves_the_stored_value_untouched(): void
    {
        Company::create([
            'domain'            => 'keep-status.test',
            'name'              => 'Keep Status',
            'enrichment_status' => Company::ENRICHMENT_ENRICHED,
            'relationship'      => 'prospect',
            'source'            => 'discovered',
        ]);

        $this->upsert([
            $this->makeCriteria(),
            'keep-status.test',
            ['title' => 'Keep Status'],
            null, null, null, false, false, null,
        ]);

        $this->assertSame(
            Company::ENRICHMENT_ENRICHED,
            Company::withRejected()->where('domain', 'keep-status.test')->first()->enrichment_status
        );
    }
}
