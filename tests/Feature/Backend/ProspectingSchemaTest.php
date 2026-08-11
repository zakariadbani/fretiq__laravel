<?php

namespace Tests\Feature\Backend;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectContactCandidate;
use App\Models\ProspectCriteria;
use App\Models\ProviderCall;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProspectingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_row_and_email_idempotency_constraints_are_enforced(): void
    {
        $fingerprint = hash('sha256', 'recovery:2026-08-10');
        ProspectBatch::factory()->create(['source_fingerprint' => $fingerprint]);

        $this->assertDuplicateRejected(
            fn () => ProspectBatch::factory()->create(['source_fingerprint' => $fingerprint])
        );

        // Nullable fingerprints are intentionally allowed for ordinary user batches.
        ProspectBatch::factory()->count(2)->create(['source_fingerprint' => null]);

        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'row_number' => 1,
        ]);

        $this->assertDuplicateRejected(fn () => ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'row_number' => 1,
        ]));

        ProspectContactCandidate::factory()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'email' => 'Sales@Example.test',
            'normalized_email' => 'sales@example.test',
        ]);

        $this->assertDuplicateRejected(fn () => ProspectContactCandidate::factory()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'email' => 'sales@example.test',
            'normalized_email' => 'sales@example.test',
        ]));

        $call = [
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'batch:item:hunter:domain_search'),
        ];
        ProviderCall::query()->create($call);

        $this->assertDuplicateRejected(fn () => ProviderCall::query()->create($call));
    }

    public function test_models_cast_json_dates_and_expose_required_relationships(): void
    {
        $creator = User::factory()->create();
        $criteria = ProspectCriteria::query()->create([
            'name' => 'Cibles industrie France',
            'hunter_discover_filters' => ['headquarters_location' => ['FR']],
            'hunter_discover_prompt_hash' => hash('sha256', 'industrie france'),
            'hunter_discover_offset' => 25,
            'hunter_discover_exhausted' => true,
        ]);
        $company = Company::factory()->create(['registrable_domain' => 'example.test']);
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $auditMarker = 'RECOVERY-AUDIT-MARKER-6d4f7f7a';
        $batch = ProspectBatch::factory()->create([
            'created_by' => $creator->id,
            'prospect_criteria_id' => $criteria->id,
            'quality_settings' => ['domain_fallback' => true],
            'source_options' => ['country' => 'FR'],
            'source_cursor' => ['offset' => 25],
            'estimate' => ['hunter_units' => 10],
            'recovery_audit' => [
                'version' => 1,
                'changes' => [['field' => 'phone', 'old' => null, 'new' => $auditMarker]],
            ],
            'cost_confirmed_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $item = ProspectBatchItem::factory()->create([
            'prospect_batch_id' => $batch->id,
            'company_id' => $company->id,
            'domain_alternatives' => ['example.test', 'example.fr'],
            'source_metadata' => ['engine' => 'google_maps'],
            'processing_started_at' => now(),
            'processed_at' => now(),
        ]);
        $candidate = ProspectContactCandidate::factory()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'decided_by' => $creator->id,
            'verification_checked_at' => now(),
            'decided_at' => now(),
            'metadata' => ['confidence' => 90],
        ]);
        $providerCall = ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'serpapi',
            'operation' => 'search',
            'engine' => 'google_maps',
            'idempotency_key' => hash('sha256', 'cast-and-relations'),
            'reserved_units' => 1.5,
            'consumed_units' => 1,
            'metadata' => ['page' => 2],
            'retry_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $batch->refresh();
        $criteria->refresh();

        $this->assertTrue($batch->quality_settings['domain_fallback']);
        $this->assertSame($auditMarker, data_get($batch->recovery_audit, 'changes.0.new'));
        $this->assertStringNotContainsString(
            $auditMarker,
            (string) DB::table('prospect_batches')->where('id', $batch->id)->value('recovery_audit'),
        );
        $this->assertTrue($batch->hasCast('recovery_audit', 'encrypted:array'));
        $this->assertInstanceOf(User::class, $batch->creator);
        $this->assertTrue($batch->criteria->is($criteria));
        $this->assertTrue($batch->items->contains($item));
        $this->assertTrue($batch->contactCandidates->contains($candidate));
        $this->assertTrue($batch->providerCalls->contains($providerCall));
        $this->assertTrue($item->batch->is($batch));
        $this->assertTrue($item->company->is($company));
        $this->assertTrue($item->contactCandidates->contains($candidate));
        $this->assertTrue($item->providerCalls->contains($providerCall));
        $this->assertTrue($candidate->batch->is($batch));
        $this->assertTrue($candidate->item->is($item));
        $this->assertTrue($candidate->company->is($company));
        $this->assertTrue($candidate->contact->is($contact));
        $this->assertTrue($candidate->decidedBy->is($creator));
        $this->assertTrue($providerCall->batch->is($batch));
        $this->assertTrue($providerCall->item->is($item));
        $this->assertTrue($criteria->prospectBatches->contains($batch));
        $this->assertTrue($creator->prospectBatches->contains($batch));
        $this->assertTrue($company->prospectBatchItems->contains($item));
        $this->assertTrue($company->prospectContactCandidates->contains($candidate));

        $this->assertSame(['headquarters_location' => ['FR']], $criteria->hunter_discover_filters);
        $this->assertSame(25, $criteria->hunter_discover_offset);
        $this->assertTrue($criteria->hunter_discover_exhausted);
        $this->assertSame(['example.test', 'example.fr'], $item->domain_alternatives);
        $this->assertSame(['confidence' => 90], $candidate->metadata);
        $this->assertSame(['page' => 2], $providerCall->metadata);
        $this->assertNotNull($batch->cost_confirmed_at);
        $this->assertNotNull($item->processed_at);
        $this->assertNotNull($candidate->verification_checked_at);
        $this->assertNotNull($providerCall->retry_at);

        $this->assertSame(
            ['draft', 'queued', 'running', 'review', 'completed', 'failed', 'cancelled'],
            ProspectBatch::STATUSES
        );
        $this->assertSame(['company_list', 'discover', 'recovery'], ProspectBatch::SOURCES);
        $this->assertSame(
            ['pending', 'processing', 'review', 'ready', 'promoted', 'failed', 'skipped'],
            ProspectBatchItem::STATUSES
        );
        $this->assertSame(
            ['pending', 'approved', 'rejected', 'promoted'],
            ProspectContactCandidate::DECISIONS
        );
    }

    public function test_contact_candidate_factory_keeps_batch_and_item_in_same_batch(): void
    {
        $candidate = ProspectContactCandidate::factory()->create();

        $this->assertTrue($candidate->batch->is($candidate->item->batch));
    }

    private function assertDuplicateRejected(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database idempotency constraint to reject a duplicate row.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
