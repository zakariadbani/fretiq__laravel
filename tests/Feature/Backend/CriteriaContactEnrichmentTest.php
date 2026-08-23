<?php

namespace Tests\Feature\Backend;

use App\Crud\ViewConfigs\ProspectCriteriaViewConfig;
use App\Exceptions\CriteriaCompanyNoLongerEligibleException;
use App\Exceptions\QuotaExhaustedException;
use App\Jobs\EnrichCriteriaContactsJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Models\User;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Services\Discovery\CriteriaContactEnrichmentService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Quota\DiscoveryQuotaService;
use Database\Seeders\Acl\PermissionsSeeder;
use Database\Seeders\Acl\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CriteriaContactEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private User $allowed;

    private User $denied;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        Cache::flush();

        $this->allowed = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->allowed->givePermissionTo('backend.access', 'view prospect_criteria', 'edit prospect_criteria', 'enrich companies');
        $this->denied = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->denied->givePermissionTo('backend.access', 'view prospect_criteria');
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
            'ai_score' => 50,
            'qualification_status' => 'pending',
            'relationship' => 'prospect',
            'source' => 'discovered',
        ], $attributes));
    }

    private function recordBatchAttempt(
        int $criteriaId,
        int $companyId,
        string $batchId,
        int $successful,
        int $contacts = 0,
    ): array {
        DiscoveryRun::create([
            'prospect_criteria_id' => $criteriaId,
            'type' => 'manual',
            'company_id' => $companyId,
            'status' => 'completed',
            'credits_reserved' => 0,
            'consumed' => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed' => 1,
            'successful_enrichments_target' => 20,
            'successful_enrichments' => $successful,
            'enrichment_batch_id' => $batchId,
            'contacts_count' => $contacts,
            'quota_date' => now()->toDateString(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        return [
            'outcome' => $successful === 1 ? 'enriched' : 'hunter_empty',
            'contacts_count' => $contacts,
            'successful_enrichments' => $successful,
        ];
    }

    public function test_snapshot_uses_override_and_filters_every_eligibility_rule(): void
    {
        Setting::set('decouverte.min_score_enrich', 90);
        $criteria = $this->criteria(['min_score_enrich' => 50]);
        $other = $this->criteria();

        $equal = $this->company($criteria, ['ai_score' => 50]);
        $this->company($criteria, ['ai_score' => 49]);
        $this->company($criteria, ['ai_score' => null]);
        $this->company($criteria, ['domain' => null]);
        $this->company($criteria, ['domain' => 'linkedin.com']);
        $this->company($criteria, ['qualification_status' => 'rejected']);
        $this->company($other, ['ai_score' => 100]);
        $withContact = $this->company($criteria, ['ai_score' => 100]);
        Contact::create(['company_id' => $withContact->id, 'email' => uniqid().'@example.com', 'name' => 'Contact']);
        $softDeleted = $this->company($criteria, ['ai_score' => 100]);
        $contact = Contact::create(['company_id' => $softDeleted->id, 'email' => uniqid().'@example.com', 'name' => 'Deleted']);
        $contact->delete();

        $snapshot = app(CriteriaContactEnrichmentService::class)->snapshot($criteria);

        $this->assertSame(50, $snapshot['effective_min_score']);
        $this->assertSame(2, $snapshot['eligible_count']);
        $this->assertSame(2, $snapshot['callable_count']);
        $this->assertTrue($equal->exists);
    }

    public function test_snapshot_separates_success_target_from_attempt_limit(): void
    {
        Setting::set('decouverte.min_score_enrich', 70);
        $criteria = $this->criteria(['min_score_enrich' => null, 'contact_limit' => 1]);
        $this->company($criteria, ['ai_score' => 70]);
        $this->company($criteria, ['ai_score' => 100]);

        $snapshot = app(CriteriaContactEnrichmentService::class)->snapshot($criteria);

        $this->assertSame(70, $snapshot['effective_min_score']);
        $this->assertSame(2, $snapshot['eligible_count']);
        $this->assertSame(1, $snapshot['success_target']);
        $this->assertSame(2, $snapshot['attempt_limit']);
        $this->assertSame(2, $snapshot['callable_count']);
        $this->assertNotContains('contact_limit', $snapshot['limiting_factors']);
    }

    public function test_preview_and_dispatch_require_enrich_permission(): void
    {
        $criteria = $this->criteria();

        $this->actingAs($this->denied)->getJson(route('admin.prospect_criteria.contact_enrichment_preview', $criteria))->assertForbidden();
        $this->actingAs($this->denied)->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria))->assertForbidden();
    }

    public function test_preview_matches_dispatch_and_duplicate_is_rejected(): void
    {
        Queue::fake();
        $criteria = $this->criteria();
        $this->company($criteria);

        $preview = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_criteria.contact_enrichment_preview', $criteria))
            ->assertOk()
            ->assertJsonPath('eligible_count', 1)
            ->assertJsonPath('callable_count', 1);

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria), [
                'approval_token' => $preview->json('approval_token'),
            ])
            ->assertStatus(202)
            ->assertJsonPath('callable_count', $preview->json('callable_count'));
        Queue::assertPushed(EnrichCriteriaContactsJob::class);

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria), [
                'approval_token' => $preview->json('approval_token'),
            ])
            ->assertStatus(409);
    }

    public function test_dispatch_keeps_token_approved_limits_when_criteria_changes_after_preview(): void
    {
        Queue::fake();
        $criteria = $this->criteria(['contact_limit' => 1]);
        $this->company($criteria);
        $this->company($criteria);

        $preview = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_criteria.contact_enrichment_preview', $criteria))
            ->assertOk()
            ->assertJsonPath('success_target', 1)
            ->assertJsonPath('attempt_limit', 2);

        $criteria->update(['contact_limit' => 2]);

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria), [
                'approval_token' => $preview->json('approval_token'),
            ])
            ->assertStatus(202)
            ->assertJsonPath('success_target', 1)
            ->assertJsonPath('attempt_limit', 2);

        Queue::assertPushed(EnrichCriteriaContactsJob::class, fn (EnrichCriteriaContactsJob $job): bool => $job->successTarget === 1 && $job->approvedAttempts === 2);
    }

    public function test_zero_callable_does_not_dispatch(): void
    {
        Queue::fake();
        $criteria = $this->criteria();

        $preview = $this->actingAs($this->allowed)
            ->getJson(route('admin.prospect_criteria.contact_enrichment_preview', $criteria));
        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria), [
                'approval_token' => $preview->json('approval_token'),
            ])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_dispatch_rejects_missing_wrong_criteria_and_expired_approval_tokens(): void
    {
        Queue::fake();
        $criteria = $this->criteria();
        $other = $this->criteria();
        $this->company($criteria);

        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria))
            ->assertStatus(422);

        $wrong = Crypt::encryptString(json_encode([
            'criteria_id' => $other->id, 'approved_max' => 1, 'expires_at' => now()->addMinute()->timestamp,
        ]));
        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria), ['approval_token' => $wrong])
            ->assertStatus(422);

        $expired = Crypt::encryptString(json_encode([
            'criteria_id' => $criteria->id, 'approved_max' => 1, 'expires_at' => now()->subSecond()->timestamp,
        ]));
        $this->actingAs($this->allowed)
            ->postJson(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria), ['approval_token' => $expired])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_edit_page_contains_generated_preview_and_dispatch_urls(): void
    {
        $criteria = $this->criteria();

        $this->actingAs($this->allowed)
            ->get(route('admin.prospect_criteria.edit', $criteria))
            ->assertOk()
            ->assertSee('Chercher les contacts manquants')
            ->assertSee(route('admin.prospect_criteria.contact_enrichment_preview', $criteria), false)
            ->assertSee(route('admin.prospect_criteria.contact_enrichment_dispatch', $criteria), false)
            ->assertSee('Enregistrez avant d’enrichir');
    }

    public function test_company_enrichment_only_maps_typed_domain_error_to_422(): void
    {
        $company = Company::create(['name' => 'Sans domaine', 'domain' => null]);
        $this->actingAs($this->allowed)
            ->postJson(route('admin.companies.enrich', $company))
            ->assertStatus(422);

        $company->update(['domain' => 'typed-error.example.com']);
        $service = Mockery::mock(CompanyEnrichmentService::class);
        $service->shouldReceive('enrich')->once()->andThrow(new \InvalidArgumentException('downstream bug'));
        $this->instance(CompanyEnrichmentService::class, $service);

        $this->actingAs($this->allowed)
            ->postJson(route('admin.companies.enrich', $company))
            ->assertStatus(500);
    }

    public function test_snapshot_applies_daily_monthly_and_criteria_caps(): void
    {
        $criteria = $this->criteria(['contact_limit' => null]);
        foreach (range(1, 5) as $i) {
            $this->company($criteria, ['domain' => "quota-{$i}.example.com"]);
        }

        $this->assignPackage(1, null);
        $dailyLimited = app(CriteriaContactEnrichmentService::class)->snapshot($criteria);
        $this->assertSame(20, $dailyLimited['success_target']);
        $this->assertSame(1, $dailyLimited['attempt_limit']);
        $this->assertSame(1, $dailyLimited['callable_count']);

        $this->assignPackage(null, 2);
        $this->assertSame(2, app(CriteriaContactEnrichmentService::class)->snapshot($criteria)['callable_count']);

        $criteria->update(['contact_limit' => 3]);
        $this->assignPackage(null, null);
        $unlimited = app(CriteriaContactEnrichmentService::class)->snapshot($criteria->fresh());
        $this->assertSame(3, $unlimited['success_target']);
        $this->assertSame(5, $unlimited['attempt_limit']);
        $this->assertSame(5, $unlimited['callable_count']);

        $this->assignPackage(4, 2);
        $snapshot = app(CriteriaContactEnrichmentService::class)->snapshot($criteria->fresh());
        $this->assertSame(2, $snapshot['callable_count']);
        $this->assertContains('monthly_quota', $snapshot['limiting_factors']);
    }

    public function test_snapshot_applies_hard_batch_safety_cap_of_hundred(): void
    {
        $criteria = $this->criteria(['contact_limit' => null]);
        foreach (range(1, 101) as $i) {
            $this->company($criteria, ['domain' => "safety-{$i}.example.com"]);
        }

        $snapshot = app(CriteriaContactEnrichmentService::class)->snapshot($criteria);

        $this->assertSame(100, $snapshot['callable_count']);
        $this->assertContains('batch_safety', $snapshot['limiting_factors']);
    }

    public function test_snapshot_reports_contact_coverage_and_quota_deferrals(): void
    {
        $criteria = $this->criteria();
        $withContact = $this->company($criteria);
        Contact::create([
            'company_id' => $withContact->id,
            'email' => uniqid().'@example.com',
            'name' => 'Contact existant',
        ]);
        foreach (range(1, 3) as $i) {
            $this->company($criteria, ['domain' => "eligible-{$i}.example.com"]);
        }
        $this->company($criteria, ['ai_score' => 49]);
        $this->assignPackage(1, null);

        $snapshot = app(CriteriaContactEnrichmentService::class)->snapshot($criteria);

        $this->assertSame(5, $snapshot['companies_count']);
        $this->assertSame(1, $snapshot['with_contacts_count']);
        $this->assertSame(4, $snapshot['without_contacts_count']);
        $this->assertSame(3, $snapshot['eligible_count']);
        $this->assertSame(1, $snapshot['ineligible_count']);
        $this->assertSame(1, $snapshot['callable_count']);
        $this->assertSame(2, $snapshot['quota_deferred_count']);
        $this->assertSame(0, $snapshot['batch_deferred_count']);
        $this->assertSame(
            $snapshot['without_contacts_count'],
            $snapshot['eligible_count'] + $snapshot['ineligible_count'],
        );
        $this->assertSame(
            $snapshot['eligible_count'],
            $snapshot['callable_count']
                + $snapshot['quota_deferred_count']
                + $snapshot['batch_deferred_count'],
        );

        $viewConfig = ProspectCriteriaViewConfig::make($criteria, [
            'contact_coverage' => $snapshot,
        ]);
        $enrichmentCard = collect($viewConfig['stat_cards'])
            ->firstWhere('label', 'Entreprises à enrichir');

        $this->assertNotNull($enrichmentCard);
        $this->assertSame(3, $enrichmentCard['value']);
        $this->assertSame('Score ≥ 50 · 1 traitable au prochain lot', $enrichmentCard['hint']);

        $this->actingAs($this->allowed)
            ->get(route('admin.prospect_criteria.view', $criteria))
            ->assertOk()
            ->assertSee('Entreprises avec contacts')
            ->assertSee('Entreprises sans contacts')
            ->assertSee('Entreprises à enrichir')
            ->assertSee('Score ≥ 50 · 1 traitable au prochain lot')
            ->assertSee('En attente du quota');
    }

    public function test_fresh_recheck_rejects_each_stale_candidate_change(): void
    {
        $criteria = $this->criteria();
        $other = $this->criteria();
        $service = app(CriteriaContactEnrichmentService::class);

        $withContact = $this->company($criteria);
        $lowScore = $this->company($criteria);
        $rejected = $this->company($criteria);
        $changedDomain = $this->company($criteria);
        $reassigned = $this->company($criteria);

        Contact::create(['company_id' => $withContact->id, 'email' => uniqid().'@example.com', 'name' => 'Nouveau']);
        $lowScore->update(['ai_score' => 49]);
        $rejected->update(['qualification_status' => 'rejected']);
        $changedDomain->update(['domain' => 'facebook.com']);
        $reassigned->update(['criteria_id' => $other->id]);

        foreach ([$withContact, $lowScore, $rejected, $changedDomain, $reassigned] as $company) {
            $this->assertFalse($service->isEligible($company, $criteria));
        }
    }

    public function test_automatic_backlog_prioritizes_deferred_then_failed_then_never_attempted(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria(['is_active' => true, 'auto_enrich' => true]);

        $neverAttempted = $this->company($criteria, ['ai_score' => null]);
        $failed = $this->company($criteria, [
            'ai_score' => null,
            'enrichment_status' => Company::ENRICHMENT_HUNTER_FAILED,
        ]);
        $deferredProvider = $this->company($criteria, [
            'ai_score' => null,
            'enrichment_status' => Company::ENRICHMENT_SKIPPED_PROVIDER_UNAVAILABLE,
        ]);
        $deferredBudget = $this->company($criteria, [
            'ai_score' => null,
            'enrichment_status' => Company::ENRICHMENT_SKIPPED_BUDGET,
        ]);
        $hunterEmpty = $this->company($criteria, [
            'ai_score' => 100,
            'enrichment_status' => Company::ENRICHMENT_HUNTER_EMPTY,
        ]);
        $this->company($criteria, ['ai_score' => null, 'is_active' => false]);
        $this->company($criteria, ['ai_score' => null, 'domain' => 'not-a-domain']);
        $this->company($criteria, ['ai_score' => null, 'domain' => 'linkedin.com']);

        $ids = app(CriteriaContactEnrichmentService::class)
            ->automaticEligibleIds($criteria)
            ->values()
            ->all();

        $this->assertSame([
            $deferredProvider->id,
            $deferredBudget->id,
            $failed->id,
            $neverAttempted->id,
        ], $ids);
        $this->assertNotContains($hunterEmpty->id, $ids);
        $this->assertNotContains(
            $hunterEmpty->id,
            app(CriteriaContactEnrichmentService::class)->eligibleIds($criteria)->all(),
            'Criteria batches must not spend more credits on a known empty result.',
        );
    }

    public function test_automatic_backlog_applies_score_gate_only_when_automatic_scoring_is_enabled(): void
    {
        $criteria = $this->criteria([
            'is_active' => true,
            'auto_enrich' => true,
            'min_score_enrich' => 50,
        ]);
        $nullScore = $this->company($criteria, ['ai_score' => null]);
        $lowScore = $this->company($criteria, ['ai_score' => 49]);
        $passingScore = $this->company($criteria, ['ai_score' => 50]);

        Setting::set('decouverte.auto_scoring', false);
        $withoutScoring = app(CriteriaContactEnrichmentService::class)
            ->automaticEligibleIds($criteria)->all();
        $this->assertContains($nullScore->id, $withoutScoring);
        $this->assertContains($lowScore->id, $withoutScoring);
        $this->assertContains($passingScore->id, $withoutScoring);

        Setting::set('decouverte.auto_scoring', true);
        $withScoring = app(CriteriaContactEnrichmentService::class)
            ->automaticEligibleIds($criteria)->all();
        $this->assertNotContains($nullScore->id, $withScoring);
        $this->assertNotContains($lowScore->id, $withScoring);
        $this->assertContains($passingScore->id, $withScoring);
    }

    public function test_batch_reservation_rejects_contact_inserted_after_preview_before_debit(): void
    {
        $criteria = $this->criteria();
        $company = $this->company($criteria);
        Contact::create(['company_id' => $company->id, 'email' => uniqid().'@example.com', 'name' => 'Concurrent']);

        $this->expectException(CriteriaCompanyNoLongerEligibleException::class);
        app(DiscoveryQuotaService::class)->reserveCriteriaEnrichment($company->id, $criteria);
    }

    public function test_batch_reservation_rejects_domain_changed_after_preview_before_debit(): void
    {
        $criteria = $this->criteria();
        $company = $this->company($criteria);
        $company->update(['domain' => 'linkedin.com']);

        $this->expectException(CriteriaCompanyNoLongerEligibleException::class);
        app(DiscoveryQuotaService::class)->reserveCriteriaEnrichment($company->id, $criteria);
    }

    public function test_job_stops_after_provider_failure_and_clears_admission(): void
    {
        $criteria = $this->criteria();
        $this->company($criteria);
        $this->company($criteria);
        $lock = Cache::lock(EnrichCriteriaContactsJob::admissionKey($criteria->id), 3600);
        $this->assertTrue($lock->get());

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichForCriteria')->once()->andReturn(['outcome' => 'provider_failed', 'contacts_count' => 0]);

        (new EnrichCriteriaContactsJob($criteria->id, 2, $lock->owner()))
            ->handle(app(CriteriaContactEnrichmentService::class), $enrichment);

        $this->assertFalse(Cache::has(EnrichCriteriaContactsJob::admissionKey($criteria->id)));
    }

    public function test_job_continues_after_hunter_empty(): void
    {
        $criteria = $this->criteria();
        $this->company($criteria);
        $this->company($criteria);
        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichForCriteria')->twice()->andReturn(['outcome' => 'hunter_empty', 'contacts_count' => 0]);

        (new EnrichCriteriaContactsJob($criteria->id))->handle(app(CriteriaContactEnrichmentService::class), $enrichment);
        $this->addToAssertionCount(1);
    }

    public function test_job_stops_after_success_target_instead_of_attempt_count(): void
    {
        $criteria = $this->criteria(['contact_limit' => 1]);
        $this->company($criteria);
        $this->company($criteria);
        $this->company($criteria);

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichForCriteria')
            ->once()
            ->ordered()
            ->andReturnUsing(fn (int $companyId, ProspectCriteria $fresh, string $batchId) => $this->recordBatchAttempt($fresh->id, $companyId, $batchId, 0));
        $enrichment->shouldReceive('enrichForCriteria')
            ->once()
            ->ordered()
            ->andReturnUsing(fn (int $companyId, ProspectCriteria $fresh, string $batchId) => $this->recordBatchAttempt($fresh->id, $companyId, $batchId, 1, 3));

        (new EnrichCriteriaContactsJob(
            criteriaId: $criteria->id,
            approvedAttempts: 3,
            successTarget: 1,
            batchId: '018f7ebd-3e61-7ba0-946f-d60d624dd29e',
        ))->handle(app(CriteriaContactEnrichmentService::class), $enrichment);

        $this->addToAssertionCount(1);
    }

    public function test_successful_manual_enrichment_counts_company_once_when_multiple_contacts_are_created(): void
    {
        config(['services.hunter.driver' => 'local']);
        $criteria = $this->criteria();
        $company = $this->company($criteria, ['domain' => 'geodis.com']);

        $result = app(CompanyEnrichmentService::class)->enrichForCriteria($company->id, $criteria);
        $run = DiscoveryRun::where('type', 'manual')->latest('id')->firstOrFail();

        $this->assertSame('enriched', $result['outcome']);
        $this->assertGreaterThan(1, $result['contacts_count']);
        $this->assertSame(1, $result['successful_enrichments']);
        $this->assertSame(1, (int) $run->successful_enrichments);
    }

    public function test_known_empty_company_remains_retryable_through_explicit_single_company_action(): void
    {
        config(['services.hunter.driver' => 'local']);
        $criteria = $this->criteria();
        $company = $this->company($criteria, [
            'domain' => 'geodis.com',
            'enrichment_status' => Company::ENRICHMENT_HUNTER_EMPTY,
        ]);

        $this->assertNotContains(
            $company->id,
            app(CriteriaContactEnrichmentService::class)->eligibleIds($criteria)->all(),
        );

        $result = app(CompanyEnrichmentService::class)->enrich($company);

        $this->assertSame('enriched', $result['outcome']);
        $this->assertSame(Company::ENRICHMENT_ENRICHED, $company->fresh()->enrichment_status);
    }

    public function test_job_never_exceeds_preview_approved_max_when_eligibility_grows(): void
    {
        $criteria = $this->criteria();
        $this->company($criteria);
        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichForCriteria')->once()
            ->andReturnUsing(fn (int $companyId, ProspectCriteria $fresh, string $batchId) => $this->recordBatchAttempt($fresh->id, $companyId, $batchId, 0));

        // Became eligible after approval; approved maximum remains one.
        $this->company($criteria);
        (new EnrichCriteriaContactsJob($criteria->id, 1, 'test-owner'))
            ->handle(app(CriteriaContactEnrichmentService::class), $enrichment);
        $this->addToAssertionCount(1);
    }

    public function test_job_retry_skips_manual_runs_already_attempted_by_the_same_batch(): void
    {
        $criteria = $this->criteria();
        $alreadyAttempted = $this->company($criteria);
        $this->company($criteria);
        $this->company($criteria);
        $batchId = '018f7ebd-3e61-7ba0-946f-d60d624dd29e';

        DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'manual',
            'company_id' => $alreadyAttempted->id,
            'status' => 'completed',
            'credits_reserved' => 0,
            'consumed' => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed' => 1,
            'successful_enrichments_target' => 2,
            'successful_enrichments' => 0,
            'enrichment_batch_id' => $batchId,
            'quota_date' => now()->toDateString(),
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichForCriteria')
            ->once()
            ->withArgs(fn (int $companyId): bool => $companyId !== $alreadyAttempted->id)
            ->andReturnUsing(fn (int $companyId, ProspectCriteria $fresh, string $jobBatchId) => $this->recordBatchAttempt($fresh->id, $companyId, $jobBatchId, 0));

        (new EnrichCriteriaContactsJob(
            $criteria->id,
            approvedAttempts: 2,
            successTarget: 2,
            batchId: $batchId,
        ))->handle(app(CriteriaContactEnrichmentService::class), $enrichment);
    }

    public function test_old_admission_owner_cannot_release_newer_lock(): void
    {
        $key = 'criteria-contact-enrichment:test-owner-lock';
        $old = Cache::lock($key, 60);
        $this->assertTrue($old->get());
        $oldOwner = $old->owner();
        $old->release();

        $new = Cache::lock($key, 60);
        $this->assertTrue($new->get());
        Cache::restoreLock($key, $oldOwner)->release();

        $this->assertFalse(Cache::lock($key, 60)->get());
        $new->release();
    }

    public function test_failed_hook_clears_admission_marker(): void
    {
        $criteria = $this->criteria();
        $key = EnrichCriteriaContactsJob::admissionKey($criteria->id);
        $lock = Cache::lock($key, 3600);
        $this->assertTrue($lock->get());

        (new EnrichCriteriaContactsJob($criteria->id, 1, $lock->owner()))
            ->failed(new \RuntimeException('worker failed'));

        $this->assertFalse(Cache::has($key));
    }

    public function test_job_stops_when_quota_is_exhausted(): void
    {
        $criteria = $this->criteria();
        $this->company($criteria);
        $this->company($criteria);
        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichForCriteria')->once()->andThrow(new QuotaExhaustedException);

        (new EnrichCriteriaContactsJob($criteria->id))->handle(app(CriteriaContactEnrichmentService::class), $enrichment);
        $this->addToAssertionCount(1);
    }

    public function test_job_rethrows_transient_lock_failure_and_keeps_batch_admission_for_retry(): void
    {
        $criteria = $this->criteria();
        $this->company($criteria);
        $key = EnrichCriteriaContactsJob::admissionKey($criteria->id);
        $lock = Cache::lock($key, 3600);
        $this->assertTrue($lock->get());

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichForCriteria')->once()->andThrow(new \App\Exceptions\QuotaLockUnavailableException);

        $job = new EnrichCriteriaContactsJob(
            criteriaId: $criteria->id,
            approvedAttempts: 1,
            admissionOwner: $lock->owner(),
            successTarget: 1,
        );

        try {
            $job->handle(app(CriteriaContactEnrichmentService::class), $enrichment);
            $this->fail('Transient lock failure must be rethrown for queue retry.');
        } catch (\App\Exceptions\QuotaLockUnavailableException) {
            $contender = Cache::lock($key, 1);
            $this->assertFalse($contender->get());
        } finally {
            $lock->release();
        }
    }

    public function test_job_keeps_batch_admission_when_transient_failure_happens_before_candidate_loop(): void
    {
        $criteria = $this->criteria();
        $key = EnrichCriteriaContactsJob::admissionKey($criteria->id);
        $lock = Cache::lock($key, 3600);
        $this->assertTrue($lock->get());

        $eligibility = Mockery::mock(CriteriaContactEnrichmentService::class);
        $eligibility->shouldReceive('snapshot')->once()->andThrow(new \RuntimeException('temporary database failure'));
        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldNotReceive('enrichForCriteria');

        $job = new EnrichCriteriaContactsJob(
            criteriaId: $criteria->id,
            approvedAttempts: 1,
            admissionOwner: $lock->owner(),
            successTarget: 1,
        );

        try {
            $job->handle($eligibility, $enrichment);
            $this->fail('Pre-loop transient failure must be rethrown for queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('temporary database failure', $e->getMessage());
            $contender = Cache::lock($key, 1);
            $this->assertFalse($contender->get());
        } finally {
            $lock->release();
        }
    }

    public function test_manual_run_counts_only_contacts_created_not_existing_updates(): void
    {
        config(['services.hunter.driver' => 'local']);
        $criteria = $this->criteria();
        $company = $this->company($criteria, ['domain' => 'geodis.com']);
        $fixture = json_decode(
            file_get_contents(base_path('database/fixtures/discovery/hunter.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['geodis.com'];

        foreach ($fixture['emails'] as $email) {
            Contact::create([
                'company_id' => $company->id,
                'email' => $email['value'],
                'name' => 'Déjà présent',
            ]);
        }

        $result = app(CompanyEnrichmentService::class)->enrich($company);
        $run = DiscoveryRun::where('type', 'manual')->latest('id')->firstOrFail();

        $this->assertSame('enriched', $result['outcome']);
        $this->assertSame(0, $result['contacts_count']);
        $this->assertSame(0, (int) $run->contacts_count);
        $this->assertSame('completed', $run->status);
        $this->assertNull($company->fresh()->enrichment_claim_run_id);
    }

    public function test_claimed_enrichment_preserves_existing_country_and_prefers_hunter_over_deferred_fallback(): void
    {
        config(['services.hunter.driver' => 'local']);
        $criteria = $this->criteria();

        $existing = $this->company($criteria, [
            'domain' => 'geodis.com',
            'country' => 'MA',
        ]);
        app(CompanyEnrichmentService::class)->enrich($existing);
        $this->assertSame('MA', $existing->fresh()->country);

        $deferred = $this->company($criteria, [
            'domain' => 'bolloretransport.com',
        ]);
        $quota = app(DiscoveryQuotaService::class);
        $run = $quota->reserveManualEnrichment($deferred);

        app(CompanyEnrichmentService::class)->enrichClaimed(
            $deferred->fresh(),
            $run,
            null,
            'MA',
        );

        $this->assertSame('FR', $deferred->fresh()->country);
    }

    public function test_late_provider_outcome_cannot_overwrite_a_newer_claim(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria(['is_active' => true, 'auto_enrich' => true]);
        $company = $this->company($criteria);
        $oldRun = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed' => 0,
            'successful_enrichments_target' => 1,
            'successful_enrichments' => 0,
            'started_at' => now(),
        ]);
        $newRun = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed' => 0,
            'successful_enrichments_target' => 1,
            'successful_enrichments' => 0,
            'started_at' => now(),
        ]);

        app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $oldRun);
        $company->refresh()->forceFill([
            'enrichment_claim_run_id' => $newRun->id,
            'enrichment_status' => Company::ENRICHMENT_ENRICHING,
        ])->save();

        $this->app->instance(HunterEnrichmentService::class, new class extends HunterEnrichmentService
        {
            public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null, ?\App\Services\Providers\ProviderCallContext $context = null): array
            {
                return ['status' => 'ok', 'data' => [
                    'organization' => 'Réponse tardive',
                    'industry' => 'Transport',
                    'country' => 'FR',
                    'emails' => [['value' => 'late@example.test']],
                    'raw' => ['late' => true],
                ]];
            }
        });

        try {
            app(CompanyEnrichmentService::class)->enrichClaimed($company, $oldRun);
            $this->fail('The stale outcome must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('n’appartient plus', $exception->getMessage());
        }

        $fresh = $company->fresh();
        $this->assertSame($newRun->id, (int) $fresh->enrichment_claim_run_id);
        $this->assertSame(Company::ENRICHMENT_ENRICHING, $fresh->enrichment_status);
        $this->assertFalse(Contact::where('email', 'late@example.test')->exists());
        $this->assertSame(0, (int) $oldRun->fresh()->contacts_count);
    }

    public function test_unexpected_enrichment_error_marks_owned_claim_failed_before_clearing_it(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria(['is_active' => true, 'auto_enrich' => true]);
        $company = $this->company($criteria);
        $run = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed' => 0,
            'successful_enrichments_target' => 1,
            'successful_enrichments' => 0,
            'started_at' => now(),
        ]);
        $claimed = app(DiscoveryQuotaService::class)
            ->claimAutomaticEnrichment($company->id, $run);

        $this->app->instance(HunterEnrichmentService::class, new class extends HunterEnrichmentService
        {
            public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null, ?\App\Services\Providers\ProviderCallContext $context = null): array
            {
                throw new \RuntimeException('provider transport exploded');
            }
        });

        $this->expectException(\RuntimeException::class);

        try {
            app(CompanyEnrichmentService::class)->enrichClaimed($claimed, $run);
        } finally {
            $fresh = $company->fresh();
            $this->assertSame(Company::ENRICHMENT_HUNTER_FAILED, $fresh->enrichment_status);
            $this->assertNull($fresh->enrichment_claim_run_id);
        }
    }

    private function assignPackage(?int $dailyContacts, ?int $monthlyContacts): void
    {
        $package = Package::create([
            'name' => 'Contacts '.uniqid(),
            'daily_credits' => null,
            'monthly_credits' => null,
            'daily_contact_credits' => $dailyContacts,
            'monthly_contact_credits' => $monthlyContacts,
            'is_active' => true,
        ]);
        PackageAssignment::create(['package_id' => $package->id]);
    }
}
