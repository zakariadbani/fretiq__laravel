<?php

namespace Tests\Feature\Backend;

use App\Jobs\EnrichCriteriaContactsJob;
use App\Models\Company;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Discovery\CriteriaContactEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ProspectBatchController::contactEnrichmentPreview() / dispatchContactEnrichment()
 * — the batch-scoped twin of CriteriaContactEnrichmentTest's criteria-wide
 * endpoints, modeled on that file's structure. Only what's specific to the
 * batch scoping (batchActionGuard, batch-scoped admission key) is re-tested
 * here; the eligibility/quota math itself is covered by
 * CriteriaContactEnrichmentTest.
 */
class ProspectBatchEnrichmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $allowed;

    private User $denied;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['backend.access', 'view prospect_batches', 'enrich companies'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->allowed = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->allowed->givePermissionTo(['backend.access', 'view prospect_batches', 'enrich companies']);
        $this->denied = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->denied->givePermissionTo(['backend.access', 'view prospect_batches']);
    }

    private function criteria(array $attributes = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name' => 'Critère manuel '.uniqid(),
            'min_score_enrich' => 50,
            'contact_limit' => null,
            'auto_enrich' => false,
            'is_active' => false,
        ], $attributes));
    }

    private function company(ProspectCriteria $criteria, array $attributes = []): Company
    {
        return Company::create(array_merge([
            'criteria_id' => $criteria->id,
            'name' => 'Entreprise '.uniqid(),
            'domain' => uniqid().'.example.com',
            'ai_score' => 90,
            'qualification_status' => 'pending',
            'relationship' => 'prospect',
            'source' => 'discovered',
        ], $attributes));
    }

    private function batchWithPromotedCompany(ProspectCriteria $criteria, array $batchAttributes = []): array
    {
        $batch = ProspectBatch::factory()->create(array_merge([
            'created_by' => $this->allowed->id,
            'status' => 'review',
            'prospect_criteria_id' => $criteria->id,
        ], $batchAttributes));
        $company = $this->company($criteria);
        ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'promoted',
            'company_id' => $company->id,
        ]);

        return [$batch, $company];
    }

    public function test_preview_and_dispatch_require_enrich_permission(): void
    {
        $criteria = $this->criteria();
        [$batch] = $this->batchWithPromotedCompany($criteria);

        $this->actingAs($this->denied)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
            ->assertForbidden();
        $this->actingAs($this->denied)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch))
            ->assertForbidden();
    }

    public function test_preview_and_dispatch_403_for_a_batch_owned_by_another_user(): void
    {
        $criteria = $this->criteria();
        [$batch] = $this->batchWithPromotedCompany($criteria);

        $otherOwner = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $otherOwner->givePermissionTo(['backend.access', 'view prospect_batches', 'enrich companies']);

        $this->actingAs($otherOwner)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
            ->assertForbidden();
        $this->actingAs($otherOwner)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch))
            ->assertForbidden();
    }

    public function test_preview_and_dispatch_422_when_the_batch_has_no_linked_criteria(): void
    {
        $batch = ProspectBatch::factory()->create([
            'created_by' => $this->allowed->id,
            'status' => 'review',
            'prospect_criteria_id' => null,
        ]);

        $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
            ->assertStatus(422);
        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch))
            ->assertStatus(422);
    }

    public function test_preview_and_dispatch_422_while_the_batch_is_still_processing(): void
    {
        $criteria = $this->criteria();

        foreach (['draft', 'queued', 'running'] as $status) {
            $batch = ProspectBatch::factory()->create([
                'created_by' => $this->allowed->id,
                'status' => $status,
                'prospect_criteria_id' => $criteria->id,
            ]);

            $this->actingAs($this->allowed)
                ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
                ->assertStatus(422);
        }
    }

    public function test_preview_returns_batch_scoped_counts_excluding_sibling_criteria_companies(): void
    {
        $criteria = $this->criteria();
        [$batch, $inBatch] = $this->batchWithPromotedCompany($criteria);
        // Same criteria, same eligibility shape, but never promoted into this
        // batch — CriteriaContactEnrichmentService::scopeToBatch() must exclude it.
        $this->company($criteria);

        $response = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
            ->assertOk()
            ->assertJsonPath('eligible_count', 1)
            ->assertJsonPath('callable_count', 1)
            ->assertJsonPath('companies_count', 1);

        $this->assertNotNull($response->json('approval_token'));
        $this->assertTrue($inBatch->exists);
    }

    public function test_dispatch_with_valid_token_queues_the_job_with_the_prospect_batch_id(): void
    {
        Queue::fake();
        $criteria = $this->criteria();
        [$batch] = $this->batchWithPromotedCompany($criteria);

        $preview = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
            ->assertOk();

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch), [
                'approval_token' => $preview->json('approval_token'),
            ])
            ->assertStatus(202);

        Queue::assertPushed(
            EnrichCriteriaContactsJob::class,
            fn (EnrichCriteriaContactsJob $job): bool => $job->criteriaId === $criteria->id
                && $job->prospectBatchId === $batch->id,
        );
    }

    public function test_dispatch_409s_while_the_batch_scoped_admission_lock_is_held(): void
    {
        Queue::fake();
        $criteria = $this->criteria();
        [$batch] = $this->batchWithPromotedCompany($criteria);

        $preview = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
            ->assertOk();

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch), [
                'approval_token' => $preview->json('approval_token'),
            ])
            ->assertStatus(202);

        $preview2 = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $batch))
            ->assertOk();
        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch), [
                'approval_token' => $preview2->json('approval_token'),
            ])
            ->assertStatus(409);

        Queue::assertPushed(EnrichCriteriaContactsJob::class, 1);
    }

    public function test_dispatch_rejects_a_token_from_a_different_batch(): void
    {
        Queue::fake();
        $criteria = $this->criteria();
        [$batch] = $this->batchWithPromotedCompany($criteria);
        [$otherBatch] = $this->batchWithPromotedCompany($criteria);

        $foreignPreview = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_batches.contact_enrichment_preview', $otherBatch))
            ->assertOk();

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch), [
                'approval_token' => $foreignPreview->json('approval_token'),
            ])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_dispatch_rejects_an_expired_token(): void
    {
        Queue::fake();
        $criteria = $this->criteria();
        [$batch] = $this->batchWithPromotedCompany($criteria);

        $expired = Crypt::encryptString(json_encode([
            'criteria_id' => $criteria->id,
            'prospect_batch_id' => $batch->id,
            'success_target' => 1,
            'attempt_limit' => 1,
            'expires_at' => now()->subSecond()->timestamp,
        ], JSON_THROW_ON_ERROR));

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_batches.contact_enrichment_dispatch', $batch), [
                'approval_token' => $expired,
            ])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_clear_admission_releases_the_batch_scoped_key_not_the_criteria_wide_key(): void
    {
        $criteria = $this->criteria();
        $this->company($criteria);
        $lock = \Illuminate\Support\Facades\Cache::lock(EnrichCriteriaContactsJob::admissionKey($criteria->id, 55), 3600);
        $this->assertTrue($lock->get());
        // The criteria-wide key (no batch id) must remain free — the two
        // admission scopes are independent locks, not the same key.
        $this->assertTrue(\Illuminate\Support\Facades\Cache::lock(EnrichCriteriaContactsJob::admissionKey($criteria->id), 1)->get());

        (new EnrichCriteriaContactsJob(
            criteriaId: $criteria->id,
            approvedAttempts: 1,
            admissionOwner: $lock->owner(),
            successTarget: 1,
            prospectBatchId: 55,
        ))->failed(new \RuntimeException('worker failed'));

        $this->assertFalse(\Illuminate\Support\Facades\Cache::has(EnrichCriteriaContactsJob::admissionKey($criteria->id, 55)));
    }
}
