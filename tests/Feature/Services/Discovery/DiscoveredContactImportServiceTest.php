<?php

namespace Tests\Feature\Services\Discovery;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Services\Discovery\DiscoveredContactImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveredContactImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_every_supported_verification_category_and_skips_malformed_email_syntax(): void
    {
        $company = Company::factory()->create();
        $statuses = ['valid', 'accept_all', 'missing', 'unknown', 'invalid', 'disposable'];
        $rows = collect($statuses)->map(fn (string $status, int $index): array => [
            'email' => "quality{$index}@example.test",
            'verification_source' => 'hunter',
            'verification' => [
                'status' => $status,
                'date' => '2026-08-13T08:00:00Z',
            ],
        ])->all();
        $rows[] = ['email' => 'not-an-email', 'verification_status' => 'valid'];

        $result = app(DiscoveredContactImportService::class)->import($company, $rows);

        $this->assertSame(6, $result->created);
        $this->assertSame(['invalid_email' => 1], $result->skipped);
        foreach ($statuses as $index => $status) {
            $this->assertDatabaseHas('contacts', [
                'company_id' => $company->id,
                'email' => "quality{$index}@example.test",
                'status' => 'new',
                'source' => 'discovered',
                'legal_basis' => 'legitimate_interest',
                'email_verification_status' => $status,
            ]);
        }
    }

    public function test_it_only_fills_safe_blanks_and_preserves_lifecycle_and_manual_evidence(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->for($company)->create([
            'email' => 'owner@example.test',
            'name' => 'Manual Owner',
            'position' => null,
            'status' => 'contacted',
            'source' => 'manual',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'manual',
            'email_verification_checked_at' => '2026-08-13 10:00:00',
        ]);

        $result = app(DiscoveredContactImportService::class)->import($company, [[
            'email' => 'OWNER@example.test',
            'name' => 'Provider Name',
            'position' => 'Direction logistique',
            'verification_status' => 'invalid',
            'verification_source' => 'hunter',
            'verification_checked_at' => '2026-08-13 12:00:00',
        ]]);

        $contact->refresh();
        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame('contacted', $contact->status);
        $this->assertSame('manual', $contact->source);
        $this->assertSame('Manual Owner', $contact->name);
        $this->assertSame('Direction logistique', $contact->position);
        $this->assertSame('valid', $contact->email_verification_status);
        $this->assertSame('manual', $contact->email_verification_source);
    }

    public function test_it_never_reassigns_or_resurrects_a_global_email(): void
    {
        $owner = Company::factory()->create();
        $other = Company::factory()->create();
        Contact::factory()->for($owner)->create(['email' => 'owned@example.test']);
        Contact::factory()->for($owner)->create(['email' => 'erased@example.test'])->delete();

        $result = app(DiscoveredContactImportService::class)->import($other, [
            ['email' => 'owned@example.test'],
            ['email' => 'erased@example.test'],
        ]);

        $this->assertSame(0, $result->created);
        $this->assertSame([
            'owned_by_another_company' => 1,
            'tombstoned' => 1,
        ], $result->skipped);
        $this->assertSame(2, Contact::withTrashed()->where('company_id', $owner->id)->count());
        $this->assertSame(0, Contact::withTrashed()->where('company_id', $other->id)->count());
    }

    public function test_batch_provenance_is_idempotent_and_contains_no_duplicated_contact_data(): void
    {
        $company = Company::factory()->create();
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_id' => $company->id,
            'selected_domain' => $company->domain,
        ]);
        $rows = [['email' => 'linked@example.test', 'source' => 'hunter_domain_search']];
        $importer = app(DiscoveredContactImportService::class);

        $first = $importer->import($company, $rows, $item);
        $second = $importer->import($company, $rows, $item);

        $this->assertSame([1, 0, 1], [$first->created, $first->updated, $first->linked]);
        $this->assertSame([0, 0, 0], [$second->created, $second->updated, $second->linked]);
        $this->assertSame(1, Contact::query()->where('email', 'linked@example.test')->count());
        $this->assertSame(1, ProspectBatchContact::query()->count());
        $this->assertDatabaseHas('prospect_batch_contacts', [
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider_source' => 'hunter_domain_search',
        ]);
        $this->assertFalse(\Schema::hasColumn('prospect_batch_contacts', 'email'));
        $this->assertFalse(\Schema::hasColumn('prospect_batch_contacts', 'decision'));
    }
}
