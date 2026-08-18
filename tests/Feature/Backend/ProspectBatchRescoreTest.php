<?php

namespace Tests\Feature\Backend;

use App\Jobs\RescoreProspectBatchCompaniesJob;
use App\Models\Company;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectCriteria;
use App\Models\User;
use App\Services\Scoring\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * "Relancer le scoring IA" batch action — ProspectBatchController::rescoreDispatch()/
 * rescoreStatus() (HTTP surface) and RescoreProspectBatchCompaniesJob (scoring +
 * qualification_status transitions), modeled on ProspectBatchJobsTest's job-level
 * style (direct ->handle() calls) plus CriteriaContactEnrichmentTest's HTTP style.
 */
class ProspectBatchRescoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['backend.access', 'view prospect_batches', 'run prospect resolution'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->givePermissionTo(['backend.access', 'view prospect_batches']);

        config(['services.scoring.driver' => 'heuristic', 'services.gemini.api_key' => null]);
    }

    private function criteria(array $attributes = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name' => 'Critère '.uniqid(),
            'sectors' => ['Transport'],
            'countries' => ['FR'],
            'is_active' => true,
        ], $attributes));
    }

    private function company(ProspectCriteria $criteria, array $attributes = []): Company
    {
        return Company::create(array_merge([
            'criteria_id' => $criteria->id,
            'name' => 'Entreprise '.uniqid(),
            'domain' => uniqid().'.example.com',
            'relationship' => 'prospect',
            'qualification_status' => 'pending',
            'source' => 'discovered',
        ], $attributes));
    }

    private function batchWithPromotedCompany(ProspectCriteria $criteria, Company $company, array $batchAttributes = []): ProspectBatch
    {
        $batch = ProspectBatch::factory()->create(array_merge([
            'created_by' => $this->user->id,
            'status' => 'review',
            'prospect_criteria_id' => $criteria->id,
        ], $batchAttributes));
        ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'promoted',
            'company_id' => $company->id,
        ]);

        return $batch;
    }

    private function excludingScorer(): LeadScoringService
    {
        return new class extends LeadScoringService
        {
            public function __construct() {}

            public function score(array $candidate, ProspectCriteria $criteria, ?int $timeoutSeconds = null): array
            {
                return ['score' => 10, 'explanation' => 'Concurrent direct — Heuristique', 'exclude' => true];
            }
        };
    }

    // ── HTTP surface ─────────────────────────────────────────────────────────

    public function test_dispatch_requires_run_prospect_resolution_permission(): void
    {
        $criteria = $this->criteria();
        $company = $this->company($criteria);
        $batch = $this->batchWithPromotedCompany($criteria, $company);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.rescore_dispatch', $batch))
            ->assertForbidden();
    }

    public function test_dispatch_and_status_403_for_a_batch_owned_by_another_user(): void
    {
        $this->user->givePermissionTo('run prospect resolution');
        $criteria = $this->criteria();
        $company = $this->company($criteria);
        $batch = $this->batchWithPromotedCompany($criteria, $company);

        $otherOwner = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $otherOwner->givePermissionTo(['backend.access', 'view prospect_batches', 'run prospect resolution']);

        $this->actingAs($otherOwner)
            ->postJson(route('admin.prospect_batches.rescore_dispatch', $batch))
            ->assertForbidden();
        $this->actingAs($otherOwner)
            ->getJson(route('admin.prospect_batches.rescore_status', $batch->id))
            ->assertForbidden();
    }

    public function test_dispatch_422s_when_the_batch_has_no_linked_criteria_or_is_still_processing(): void
    {
        $this->user->givePermissionTo('run prospect resolution');

        $noCriteria = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'review',
            'prospect_criteria_id' => null,
        ]);
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.rescore_dispatch', $noCriteria))
            ->assertStatus(422);

        $criteria = $this->criteria();
        $stillRunning = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'running',
            'prospect_criteria_id' => $criteria->id,
        ]);
        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.rescore_dispatch', $stillRunning))
            ->assertStatus(422);
    }

    public function test_dispatch_queues_the_job_and_409s_on_a_second_dispatch_while_admitted(): void
    {
        Queue::fake();
        $this->user->givePermissionTo('run prospect resolution');
        $criteria = $this->criteria();
        $company = $this->company($criteria);
        $batch = $this->batchWithPromotedCompany($criteria, $company);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.rescore_dispatch', $batch))
            ->assertStatus(202)
            ->assertJsonStructure(['status_url']);

        Queue::assertPushed(
            RescoreProspectBatchCompaniesJob::class,
            fn (RescoreProspectBatchCompaniesJob $job): bool => $job->prospectBatchId === $batch->id,
        );

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.rescore_dispatch', $batch))
            ->assertStatus(409);

        Queue::assertPushed(RescoreProspectBatchCompaniesJob::class, 1);
    }

    public function test_status_endpoint_requires_view_permission_and_serves_the_token_payload(): void
    {
        Queue::fake();
        $this->user->givePermissionTo('run prospect resolution');
        $criteria = $this->criteria();
        $company = $this->company($criteria);
        $batch = $this->batchWithPromotedCompany($criteria, $company);

        $dispatch = $this->actingAs($this->user)
            ->postJson(route('admin.prospect_batches.rescore_dispatch', $batch))
            ->assertStatus(202);
        $statusUrl = $dispatch->json('status_url');
        $token = [];
        parse_str((string) parse_url($statusUrl, PHP_URL_QUERY), $token);

        $this->actingAs($this->user)
            ->getJson(route('admin.prospect_batches.rescore_status', $batch->id).'?token='.$token['token'])
            ->assertOk()
            ->assertJsonPath('terminal', false)
            ->assertJsonPath('total', 0);

        $this->actingAs($this->user)
            ->getJson(route('admin.prospect_batches.rescore_status', $batch->id))
            ->assertNotFound();

        $noPermission = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->actingAs($noPermission)
            ->getJson(route('admin.prospect_batches.rescore_status', $batch->id).'?token='.$token['token'])
            ->assertForbidden();
    }

    // ── Job scoring transitions ──────────────────────────────────────────────

    public function test_job_writes_ai_score_and_explanation_via_the_heuristic_driver(): void
    {
        $criteria = $this->criteria();
        $company = $this->company($criteria, ['domain' => 'transitaire-fret.example.com', 'ai_score' => null]);
        $batch = $this->batchWithPromotedCompany($criteria, $company);

        (new RescoreProspectBatchCompaniesJob($batch->id, 'token-1', $this->user->id, 'owner-1'))
            ->handle(app(LeadScoringService::class));

        $fresh = $company->fresh();
        $this->assertNotNull($fresh->ai_score);
        $this->assertIsString($fresh->ai_explanation);
        $this->assertNotEmpty(trim((string) $fresh->ai_explanation));
    }

    public function test_exclude_rejects_a_non_client_company(): void
    {
        $criteria = $this->criteria();
        $company = $this->company($criteria, ['relationship' => 'prospect', 'qualification_status' => 'pending']);
        $batch = $this->batchWithPromotedCompany($criteria, $company);

        (new RescoreProspectBatchCompaniesJob($batch->id, 'token-2', $this->user->id, 'owner-2'))
            ->handle($this->excludingScorer());

        $this->assertSame('rejected', Company::withRejected()->findOrFail($company->id)->qualification_status);
    }

    public function test_a_client_company_is_never_set_to_rejected_even_when_excluded(): void
    {
        $criteria = $this->criteria();
        $client = $this->company($criteria, ['relationship' => 'client', 'qualification_status' => 'pending']);
        $batch = $this->batchWithPromotedCompany($criteria, $client);

        (new RescoreProspectBatchCompaniesJob($batch->id, 'token-3', $this->user->id, 'owner-3'))
            ->handle($this->excludingScorer());

        $this->assertSame('pending', Company::withRejected()->findOrFail($client->id)->qualification_status);
    }

    public function test_a_rejected_non_client_with_a_non_excluded_score_returns_to_pending(): void
    {
        $criteria = $this->criteria();
        $company = $this->company($criteria, ['relationship' => 'prospect', 'qualification_status' => 'rejected']);
        $batch = $this->batchWithPromotedCompany($criteria, $company);

        // Default heuristic driver never excludes — a rescored rejected
        // non-client must be un-rejected back to 'pending'.
        (new RescoreProspectBatchCompaniesJob($batch->id, 'token-4', $this->user->id, 'owner-4'))
            ->handle(app(LeadScoringService::class));

        $this->assertSame('pending', Company::withRejected()->findOrFail($company->id)->qualification_status);
    }

    public function test_a_same_criteria_company_not_promoted_into_the_batch_is_untouched(): void
    {
        $criteria = $this->criteria();
        $inBatch = $this->company($criteria, ['ai_score' => null]);
        $batch = $this->batchWithPromotedCompany($criteria, $inBatch);
        // Same criteria, never linked via prospect_batch_items to this batch.
        $sibling = $this->company($criteria, ['ai_score' => 77, 'ai_explanation' => 'untouched']);

        (new RescoreProspectBatchCompaniesJob($batch->id, 'token-5', $this->user->id, 'owner-5'))
            ->handle(app(LeadScoringService::class));

        $this->assertNotNull(Company::withRejected()->findOrFail($inBatch->id)->ai_score);
        $freshSibling = Company::withRejected()->findOrFail($sibling->id);
        $this->assertSame(77, $freshSibling->ai_score);
        $this->assertSame('untouched', $freshSibling->ai_explanation);
    }
}
