<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectContactCandidate;
use App\Services\Prospecting\ProspectBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
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

    public function test_contact_promotion_copies_verification_provenance_without_deciding_eligibility(): void
    {
        $company = Company::factory()->create([
            'domain' => 'acme.fr',
            'registrable_domain' => 'acme.fr',
        ]);
        $item = $this->readyItem(ProspectBatch::factory()->create(), 1, [
            'company_id' => $company->id,
            'status' => 'promoted',
        ]);
        $candidate = ProspectContactCandidate::factory()->create([
            'prospect_batch_id' => $item->prospect_batch_id,
            'prospect_batch_item_id' => $item->id,
            'company_id' => $company->id,
            'email' => ' Sales@Acme.fr ',
            'normalized_email' => 'sales@acme.fr',
            'name' => 'Ada Lovelace',
            'source' => 'hunter_domain_search',
            'email_kind' => 'role',
            'verification_status' => 'accept_all',
            'verification_checked_at' => '2026-08-01 10:00:00',
            'verification_source' => 'hunter',
            'decision' => 'approved',
        ]);

        $contact = app(ProspectBatchService::class)->promoteContactCandidate($candidate);

        $this->assertSame('sales@acme.fr', $contact->email);
        $this->assertSame('accept_all', $contact->email_verification_status);
        $this->assertSame('2026-08-01 10:00:00', $contact->email_verification_checked_at->format('Y-m-d H:i:s'));
        $this->assertSame('hunter', $contact->email_verification_source);
        $this->assertSame('new', $contact->status);
        $this->assertSame('promoted', $candidate->fresh()->decision);
        $this->assertSame($contact->id, $candidate->fresh()->contact_id);
        $this->assertSame($contact->id, app(ProspectBatchService::class)->promoteContactCandidate($candidate->fresh())->id);
    }

    public function test_contact_promotion_preserves_newer_manual_verification_and_still_promotes_candidate(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create([
            'company_id' => $company->id,
            'email' => 'manual@acme.fr',
            'email_verification_status' => 'valid',
            'email_verification_checked_at' => '2026-08-10 10:00:00',
            'email_verification_source' => 'manual',
        ]);
        $item = $this->readyItem(ProspectBatch::factory()->create(), 1, [
            'company_id' => $company->id,
            'status' => 'promoted',
        ]);
        $candidate = ProspectContactCandidate::factory()->create([
            'prospect_batch_id' => $item->prospect_batch_id,
            'prospect_batch_item_id' => $item->id,
            'company_id' => $company->id,
            'email' => 'manual@acme.fr',
            'normalized_email' => 'manual@acme.fr',
            'source' => 'hunter_domain_search',
            'verification_status' => 'accept_all',
            'verification_checked_at' => '2026-08-01 10:00:00',
            'verification_source' => 'hunter',
            'decision' => 'approved',
        ]);

        $promoted = app(ProspectBatchService::class)->promoteContactCandidate($candidate);

        $this->assertSame($contact->id, $promoted->id);
        $this->assertSame('valid', $promoted->email_verification_status);
        $this->assertSame('2026-08-10 10:00:00', $promoted->email_verification_checked_at->format('Y-m-d H:i:s'));
        $this->assertSame('manual', $promoted->email_verification_source);
        $this->assertSame('promoted', $candidate->fresh()->decision);
        $this->assertSame($contact->id, $candidate->fresh()->contact_id);
    }

    public function test_contact_promotion_replaces_only_strictly_older_provider_verification(): void
    {
        $company = Company::factory()->create();
        $batch = ProspectBatch::factory()->create();
        $service = app(ProspectBatchService::class);

        foreach ([
            ['email' => 'newer@acme.fr', 'candidate_date' => '2026-08-02 10:00:00', 'expected' => 'accept_all'],
            ['email' => 'equal@acme.fr', 'candidate_date' => '2026-08-01 10:00:00', 'expected' => 'valid'],
        ] as $index => $case) {
            $contact = Contact::factory()->create([
                'company_id' => $company->id,
                'email' => $case['email'],
                'email_verification_status' => 'valid',
                'email_verification_checked_at' => '2026-08-01 10:00:00',
                'email_verification_source' => 'hunter',
            ]);
            $item = $this->readyItem($batch, $index + 1, [
                'company_id' => $company->id,
                'status' => 'promoted',
            ]);
            $candidate = ProspectContactCandidate::factory()->create([
                'prospect_batch_id' => $batch->id,
                'prospect_batch_item_id' => $item->id,
                'company_id' => $company->id,
                'email' => $case['email'],
                'normalized_email' => $case['email'],
                'source' => 'hunter_domain_search',
                'verification_status' => 'accept_all',
                'verification_checked_at' => $case['candidate_date'],
                'verification_source' => 'hunter',
                'decision' => 'approved',
            ]);

            $promoted = $service->promoteContactCandidate($candidate);

            $this->assertSame($contact->id, $promoted->id);
            $this->assertSame($case['expected'], $promoted->email_verification_status);
            $this->assertSame('promoted', $candidate->fresh()->decision);
        }
    }

    public function test_contact_promotion_never_moves_global_email_owned_by_another_company_even_when_soft_deleted(): void
    {
        $owner = Company::factory()->create();
        $target = Company::factory()->create();
        $existing = Contact::factory()->create([
            'company_id' => $owner->id,
            'email' => 'shared@example.fr',
        ]);
        $existing->delete();
        $item = $this->readyItem(ProspectBatch::factory()->create(), 1, [
            'company_id' => $target->id,
            'status' => 'promoted',
        ]);
        $candidate = ProspectContactCandidate::factory()->create([
            'prospect_batch_id' => $item->prospect_batch_id,
            'prospect_batch_item_id' => $item->id,
            'company_id' => $target->id,
            'email' => 'SHARED@example.fr',
            'normalized_email' => 'shared@example.fr',
            'decision' => 'approved',
        ]);

        try {
            app(ProspectBatchService::class)->promoteContactCandidate($candidate);
            $this->fail('A global email owned by another company must not be moved.');
        } catch (LogicException $exception) {
            $this->assertSame('prospect_contact_email_owned_by_another_company', $exception->getMessage());
        }

        $this->assertSame($owner->id, Contact::withTrashed()->findOrFail($existing->id)->company_id);
        $this->assertSame('approved', $candidate->fresh()->decision);
        $this->assertNull($candidate->fresh()->contact_id);
        $this->assertSame(1, Contact::withTrashed()->where('email', 'shared@example.fr')->count());
    }

    public function test_candidate_staging_allowlists_verification_and_metadata(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'row_number' => 1,
        ]);

        app(ProspectBatchService::class)->stageContactCandidates($batch, $item, [[
            'email' => 'safe@example.fr',
            'source' => 'hunter_domain_search',
            'verification_status' => 'NOT_A_STATUS',
            'verification_checked_at' => 'not-a-date',
            'verification_source' => 'untrusted_provider',
            'metadata' => [
                'origin' => 'domain_search',
                'source_hash' => str_repeat('a', 64),
                'url' => 'https://example.fr/private?api_key=secret',
                'raw_response' => ['secret' => true],
                'email' => 'leak@example.fr',
            ],
        ]]);

        $candidate = ProspectContactCandidate::query()->sole();
        $this->assertNull($candidate->verification_status);
        $this->assertNull($candidate->verification_checked_at);
        $this->assertNull($candidate->verification_source);
        $this->assertSame([
            'origin' => 'domain_search',
            'source_hash' => str_repeat('a', 64),
        ], $candidate->metadata);
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
