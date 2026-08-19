<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\ProspectBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProspectPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
    }

    public function test_replayed_same_registrable_domain_promotion_creates_one_company(): void
    {
        $batch = ProspectBatch::factory()->create();
        $first = $this->readyItem($batch, 1);
        $second = $this->readyItem($batch, 2);
        $service = app(ProspectBatchService::class);

        $firstCompany = $service->promoteItem($first);
        $secondCompany = $service->promoteItem($second);

        $this->assertSame($firstCompany->id, $secondCompany->id);
        $this->assertSame(1, Company::withRejected()->where('registrable_domain', 'acme.fr')->count());
        $this->assertSame('promoted', $first->fresh()->status);
        $this->assertSame('promoted', $second->fresh()->status);
    }

    public function test_concurrent_promotion_of_same_registrable_domain_creates_one_company(): void
    {
        $this->markTestSkipped('A true two-connection lock assertion requires an available isolated MySQL concurrency harness.');
    }

    public function test_company_promotion_never_overwrites_nonblank_fields(): void
    {
        $existing = Company::factory()->create([
            'name' => 'Acme Logistics',
            'domain' => 'acme.fr',
            'registrable_domain' => 'acme.fr',
            'sector' => 'Secteur existant',
            'phone' => null,
            'qualification_status' => 'rejected',
        ]);
        $batch = ProspectBatch::factory()->create();
        $item = $this->readyItem($batch, 1, [
            'source_metadata' => [
                'company' => [
                    'name' => 'Acme Logistics SAS',
                    'sector' => 'Logistics',
                    'phone' => '+33102030405',
                    'country' => 'FR',
                    'estimated_size' => '51-200',
                ],
            ],
        ]);

        $promoted = app(ProspectBatchService::class)->promoteItem($item);

        $this->assertSame($existing->id, $promoted->id);
        $this->assertSame('Secteur existant', $promoted->fresh()->sector);
        $this->assertSame('+33102030405', $promoted->fresh()->phone);
        $this->assertSame('rejected', $promoted->fresh()->qualification_status);
    }

    public function test_description_is_written_onto_the_company_on_promotion(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = $this->readyItem($batch, 1, [
            'source_metadata' => [
                'description' => 'Leader du transport et de la logistique.',
            ],
        ]);

        $company = app(ProspectBatchService::class)->promoteItem($item);

        $this->assertSame('Leader du transport et de la logistique.', $company->fresh()->description);
    }

    public function test_existing_companys_description_is_never_overwritten(): void
    {
        $existing = Company::factory()->create([
            'name' => 'Acme Logistics',
            'domain' => 'acme.fr',
            'registrable_domain' => 'acme.fr',
            'description' => 'Description existante.',
        ]);
        $batch = ProspectBatch::factory()->create();
        $item = $this->readyItem($batch, 1, [
            'source_metadata' => [
                'description' => 'Nouvelle description venue du batch.',
            ],
        ]);

        $promoted = app(ProspectBatchService::class)->promoteItem($item);

        $this->assertSame($existing->id, $promoted->id);
        $this->assertSame('Description existante.', $promoted->fresh()->description);
    }

    /** @param array<string, mixed> $overrides */
    private function readyItem(
        ProspectBatch $batch,
        int $rowNumber,
        array $overrides = [],
    ): ProspectBatchItem {
        return ProspectBatchItem::factory()->create(array_replace([
            'prospect_batch_id' => $batch->id,
            'row_number' => $rowNumber,
            'company_name' => 'Acme Logistics',
            'normalized_name' => 'acme logistics',
            'country' => 'FR',
            'provided_domain' => 'acme.fr',
            'selected_domain' => 'acme.fr',
            'registrable_domain' => 'acme.fr',
            'domain_reason' => 'provided_domain',
            'status' => 'ready',
        ], $overrides));
    }
}
