<?php

namespace Tests\Feature\Backend;

use App\Jobs\FinalizeProspectBatchJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProviderCall;
use App\Models\ProspectBatchItem;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Models\User;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectItemProcessor;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Exceptions\QuotaExhaustedException;
use App\Exceptions\CriteriaCompanyNoLongerEligibleException;
use App\Services\Providers\ProviderRequestException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProspectBatchJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hunter.driver' => 'hunter',
            'services.hunter.api_key' => 'test-key',
            'cache.default' => 'array',
        ]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_jobs_use_stable_unique_and_overlap_keys(): void
    {
        $item = new ProcessProspectBatchItemJob(42);
        $finalizer = new FinalizeProspectBatchJob(7);

        $this->assertInstanceOf(ShouldBeUnique::class, $item);
        $this->assertSame('prospect-item:42', $item->uniqueId());
        $this->assertInstanceOf(WithoutOverlapping::class, $item->middleware()[0]);
        $this->assertInstanceOf(ShouldBeUnique::class, $finalizer);
        $this->assertSame('prospect-batch-finalize:7', $finalizer->uniqueId());
        $this->assertInstanceOf(WithoutOverlapping::class, $finalizer->middleware()[0]);
    }

    public function test_criterion_item_job_adds_a_shared_batch_overlap_key(): void
    {
        $criteria = $this->criteria();
        $batch = ProspectBatch::factory()->create(['prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();

        $middleware = (new ProcessProspectBatchItemJob($item->id))->middleware();

        $this->assertCount(2, $middleware);
        $this->assertSame("prospect-item:{$item->id}", $middleware[0]->key);
        $this->assertSame("prospect-criterion-batch:{$batch->id}", $middleware[1]->key);
    }

    public function test_discover_draft_does_not_reset_an_active_criteria_cursor(): void
    {
        $service = app(ProspectBatchService::class);
        $actor = $this->actor();
        $criteria = $this->criteria();
        $criteria->forceFill([
            'hunter_discover_filters' => ['industry' => ['include' => ['Logistics']]],
            'hunter_discover_prompt_hash' => str_repeat('a', 64),
            'hunter_discover_offset' => 100,
            'hunter_discover_exhausted' => false,
        ])->save();

        $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);

        $criteria->refresh();
        $this->assertSame(str_repeat('a', 64), $criteria->hunter_discover_prompt_hash);
        $this->assertSame(100, $criteria->hunter_discover_offset);
        $this->assertFalse($criteria->hunter_discover_exhausted);
    }

    public function test_item_worker_marks_a_queued_batch_running_before_processing(): void
    {
        config([
            'services.hunter.driver' => 'local',
            'services.serpapi.driver' => 'local',
        ]);
        $batch = ProspectBatch::factory()->create([
            'status' => 'queued',
            'started_at' => null,
        ]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $batch->refresh();
        $this->assertSame('running', $batch->status);
        $this->assertNotNull($batch->started_at);
    }

    public function test_item_worker_reopens_a_terminal_batch_when_an_item_is_retried(): void
    {
        config([
            'services.hunter.driver' => 'local',
            'services.serpapi.driver' => 'local',
        ]);
        $batch = ProspectBatch::factory()->create([
            'status' => 'failed',
            'finished_at' => now()->subMinute(),
            'error' => 'usage_limit',
        ]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $batch->refresh();
        $this->assertSame('running', $batch->status);
        $this->assertNull($batch->finished_at);
        $this->assertNull($batch->error);
    }

    public function test_item_worker_links_and_promotes_the_resolved_company(): void
    {
        config([
            'services.hunter.driver' => 'local',
            'services.serpapi.driver' => 'local',
        ]);
        $batch = ProspectBatch::factory()->create(['status' => 'queued']);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis',
            'normalized_name' => 'geodis',
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $item->refresh();
        $this->assertSame('promoted', $item->status);
        $this->assertNotNull($item->company_id);
        $this->assertDatabaseHas('companies', ['id' => $item->company_id, 'domain' => 'geodis.com']);
    }

    public function test_item_worker_scores_and_links_criterion_bound_company_with_expected_score(): void
    {
        config([
            'services.hunter.driver' => 'local',
            'services.serpapi.driver' => 'local',
            'services.scoring.driver' => 'heuristic',
            'services.gemini.api_key' => null,
        ]);
        Setting::set('decouverte.auto_scoring', true);

        $criteria = $this->criteria();
        $batch = ProspectBatch::factory()->create([
            'status' => 'queued',
            'prospect_criteria_id' => $criteria->id,
        ]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis',
            'normalized_name' => 'geodis',
            'country' => 'FR',
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $item->refresh();
        $this->assertSame('promoted', $item->status);
        $this->assertNotNull($item->company_id);

        $company = Company::query()->findOrFail((int) $item->company_id);
        $this->assertSame(40, $company->ai_score);
        $this->assertIsString($company->ai_explanation);
        $this->assertNotEmpty(trim((string) $company->ai_explanation));
    }

    public function test_criterion_bound_low_score_skips_provider_admission_and_promotes_item(): void
    {
        config([
            'services.scoring.driver' => 'heuristic',
            'services.gemini.api_key' => null,
        ]);
        Setting::set('decouverte.auto_scoring', true);

        $criteria = $this->criteria();
        $criteria->forceFill([
            'auto_enrich' => true,
            'min_score_enrich' => 50,
        ])->save();
        $batch = ProspectBatch::factory()->create([
            'status' => 'queued',
            'prospect_criteria_id' => $criteria->id,
        ]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis',
            'normalized_name' => 'geodis',
            'country' => 'FR',
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $item->refresh();
        $company = Company::query()->findOrFail((int) $item->company_id);

        $this->assertSame('promoted', $item->status);
        $this->assertSame(40, $company->ai_score);
        $this->assertSame('pending', $company->qualification_status);
        $this->assertSame(Company::ENRICHMENT_SKIPPED_LOW_SCORE, $company->enrichment_status);
        $this->assertDatabaseCount('provider_calls', 0);
        $this->assertDatabaseCount('contacts', 0);
        Http::assertNothingSent();
    }

    public function test_criterion_bound_score_equal_to_threshold_enriches_and_promotes_item(): void
    {
        config([
            'services.hunter.driver' => 'local',
            'services.serpapi.driver' => 'local',
            'services.scoring.driver' => 'heuristic',
            'services.gemini.api_key' => null,
        ]);
        Setting::set('decouverte.auto_scoring', true);

        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 40])->save();
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis',
            'normalized_name' => 'geodis',
            'country' => 'FR',
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $item->refresh();
        $company = Company::query()->findOrFail((int) $item->company_id);

        $this->assertSame('promoted', $item->status);
        $this->assertSame(Company::ENRICHMENT_ENRICHED, $company->enrichment_status);
        $this->assertDatabaseCount('provider_calls', 1);
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_criterion_bound_auto_enrich_off_skips_provider_admission(): void
    {
        config(['services.scoring.driver' => 'heuristic', 'services.gemini.api_key' => null]);
        Setting::set('decouverte.auto_scoring', true);

        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => false, 'min_score_enrich' => 0])->save();
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis',
            'normalized_name' => 'geodis',
            'country' => 'FR',
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $item->refresh();
        $company = Company::query()->findOrFail((int) $item->company_id);

        $this->assertSame('promoted', $item->status);
        $this->assertSame(Company::ENRICHMENT_SKIPPED_ENRICH_OFF, $company->enrichment_status);
        $this->assertDatabaseCount('provider_calls', 0);
        Http::assertNothingSent();
    }

    public function test_criterion_bound_excluded_score_is_skipped_and_never_promoted(): void
    {
        Setting::set('decouverte.auto_scoring', true);
        $this->fakeExcludingScorer();

        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 0])->save();
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis',
            'normalized_name' => 'geodis',
            'country' => 'FR',
            'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(
            app(ProspectItemProcessor::class),
            app(ProspectBatchService::class),
        );

        $item->refresh();
        $company = Company::withRejected()->findOrFail((int) $item->company_id);

        $this->assertSame('skipped', $item->status);
        $this->assertSame('rejected', $company->qualification_status);
        $this->assertSame(Company::ENRICHMENT_SKIPPED_EXCLUDED, $company->enrichment_status);
        $this->assertDatabaseCount('provider_calls', 0);
        Http::assertNothingSent();
    }

    public function test_inactive_criterion_skips_before_scoring_or_provider_work(): void
    {
        config(['services.hunter.driver' => 'local', 'services.serpapi.driver' => 'local']);
        Setting::set('decouverte.auto_scoring', true);
        $criteria = $this->criteria();
        $criteria->forceFill(['is_active' => false, 'auto_enrich' => true])->save();
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis', 'normalized_name' => 'geodis', 'country' => null,
            'status' => 'pending', 'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $this->assertSame('skipped', $item->fresh()->status);
        $this->assertDatabaseCount('provider_calls', 0);
        $this->assertNull($item->fresh()->company_id);
    }

    public function test_same_criterion_rejected_company_is_skipped_without_rescoring_or_enrichment(): void
    {
        config(['services.hunter.driver' => 'local', 'services.serpapi.driver' => 'local']);
        $criteria = $this->criteria();
        $company = Company::query()->create([
            'name' => 'Geodis', 'domain' => 'geodis.com', 'criteria_id' => $criteria->id,
            'relationship' => 'prospect', 'qualification_status' => 'rejected',
            'enrichment_status' => Company::ENRICHMENT_SKIPPED_EXCLUDED,
        ]);
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis', 'normalized_name' => 'geodis', 'status' => 'pending',
            'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $this->assertSame('skipped', $item->fresh()->status);
        $this->assertSame(Company::ENRICHMENT_SKIPPED_EXCLUDED, $company->fresh()->enrichment_status);
        $this->assertDatabaseCount('provider_calls', 0);
    }

    public function test_existing_company_is_reassigned_to_current_criterion_before_scoring(): void
    {
        config(['services.hunter.driver' => 'local', 'services.serpapi.driver' => 'local']);
        $former = $this->criteria();
        $current = ProspectCriteria::query()->create(array_merge($former->only([
            'ai_target', 'sectors', 'countries', 'company_sizes', 'daily_limit', 'is_active',
        ]), ['name' => 'Nouveau critere']));
        $company = Company::query()->create([
            'name' => 'Geodis', 'domain' => 'geodis.com', 'criteria_id' => $former->id,
            'relationship' => 'prospect', 'qualification_status' => 'pending',
        ]);
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $current->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis', 'normalized_name' => 'geodis', 'status' => 'pending', 'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $this->assertSame($current->id, $company->fresh()->criteria_id);
    }

    public function test_existing_company_contact_skips_provider_enrichment(): void
    {
        config(['services.hunter.driver' => 'local', 'services.serpapi.driver' => 'local']);
        Setting::set('decouverte.auto_scoring', true);
        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 0])->save();
        $company = Company::query()->create([
            'name' => 'Geodis', 'domain' => 'geodis.com', 'criteria_id' => $criteria->id,
            'relationship' => 'prospect', 'qualification_status' => 'pending', 'ai_score' => 80,
        ]);
        Contact::factory()->create(['company_id' => $company->id]);
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis', 'normalized_name' => 'geodis', 'status' => 'pending', 'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $this->assertDatabaseCount('provider_calls', 0);
        $this->assertNotSame(Company::ENRICHMENT_ENRICHING, $company->fresh()->enrichment_status);
    }

    public function test_eligible_criterion_item_uses_a_durable_criteria_enrichment_reservation(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key', 'services.serpapi.driver' => 'local']);
        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/domain-search')) {
                return Http::response(['data' => ['domain' => 'geodis.com', 'organization' => 'Geodis', 'emails' => [['value' => 'contact@geodis.com']]]], 200);
            }
            if (str_contains($request->url(), '/companies/find')) {
                return Http::response(['data' => ['name' => 'Geodis', 'category' => ['industry' => 'Transport'], 'geo' => ['countryCode' => 'FR']]], 200);
            }
            return Http::response([], 500);
        });
        Setting::set('decouverte.auto_scoring', true);
        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 0, 'contact_limit' => 1])->save();
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'company_name' => 'Geodis', 'normalized_name' => 'geodis', 'country' => 'FR',
            'status' => 'pending', 'provided_domain' => 'geodis.com',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $run = DiscoveryRun::query()->where('company_id', $item->fresh()->company_id)->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->successful_enrichments_target);
        $company = Company::withRejected()->findOrFail($item->fresh()->company_id);
        $this->assertSame(Company::ENRICHMENT_ENRICHED, $company->enrichment_status);
        $this->assertSame('pending', $company->qualification_status);
        $this->assertSame('Transport', $company->sector);
        $this->assertSame('FR', $company->country);
        $this->assertIsArray($company->enrichment_data);
        $this->assertNotNull($company->enrichment_attempted_at);
        $this->assertGreaterThan(0, ProspectBatchContact::query()->where('prospect_batch_item_id', $item->id)->count());
        $calls = ProviderCall::query()->where('prospect_batch_id', $batch->id)
            ->where('prospect_batch_item_id', $item->id)->orderBy('operation')->get();
        $this->assertCount(2, $calls);
        $this->assertSame(['company_enrichment', 'domain_search'], $calls->pluck('operation')->all());
        $this->assertCount(2, $calls->pluck('idempotency_key')->unique());
        $this->assertSame([0.2, 1.0], $calls->pluck('reserved_units')->map(fn ($unit) => (float) $unit)->all());
    }

    public function test_criterion_item_quota_exhaustion_skips_without_provider_work(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key', 'services.serpapi.driver' => 'local']);
        Setting::set('decouverte.auto_scoring', true);
        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 0])->save();
        $this->mock(CompanyEnrichmentService::class, function ($mock): void {
            $mock->shouldReceive('enrichForCriteria')->once()->andThrow(new QuotaExhaustedException);
        });
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create(['company_name' => 'Geodis', 'normalized_name' => 'geodis', 'country' => 'FR', 'status' => 'pending', 'provided_domain' => 'geodis.com']);

        (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $company = Company::withRejected()->findOrFail($item->fresh()->company_id);
        $this->assertSame('skipped', $item->fresh()->status);
        $this->assertSame(Company::ENRICHMENT_SKIPPED_BUDGET, $company->enrichment_status);
        $this->assertDatabaseCount('provider_calls', 0);
    }

    public function test_criterion_item_no_longer_eligible_completes_without_provider_retry(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key', 'services.serpapi.driver' => 'local']);
        Setting::set('decouverte.auto_scoring', true);
        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 0])->save();
        $this->mock(CompanyEnrichmentService::class, function ($mock): void {
            $mock->shouldReceive('enrichForCriteria')->once()->andThrow(new CriteriaCompanyNoLongerEligibleException);
        });
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create(['company_name' => 'Geodis', 'normalized_name' => 'geodis', 'country' => 'FR', 'status' => 'pending', 'provided_domain' => 'geodis.com']);

        (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $this->assertSame('promoted', $item->fresh()->status);
        $this->assertDatabaseCount('provider_calls', 0);
        $this->assertNotSame(Company::ENRICHMENT_ENRICHING, Company::withRejected()->findOrFail($item->fresh()->company_id)->enrichment_status);
    }

    public function test_duplicate_criterion_items_share_one_provider_admission(): void
    {
        config(['services.hunter.driver' => 'local', 'services.serpapi.driver' => 'local']);
        Setting::set('decouverte.auto_scoring', true);
        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 0, 'contact_limit' => 2])->save();
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $items = collect([1, 2])->map(fn (int $row) => ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'row_number' => $row, 'company_name' => 'Geodis', 'normalized_name' => 'geodis', 'country' => 'FR', 'status' => 'pending', 'provided_domain' => 'geodis.com',
        ]));

        foreach ($items as $item) {
            (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));
        }

        $this->assertSame(1, DiscoveryRun::query()->count());
        $this->assertSame(1, ProviderCall::query()->count());
    }

    public function test_systemic_failure_opens_batch_circuit_and_skips_later_item(): void
    {
        config(['services.hunter.driver' => 'hunter', 'services.hunter.api_key' => 'test-key', 'services.serpapi.driver' => 'local']);
        Setting::set('decouverte.auto_scoring', true);
        Http::fake(['api.hunter.io/v2/*' => Http::response([], 503)]);
        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => true, 'min_score_enrich' => 0])->save();
        $batch = ProspectBatch::factory()->create(['status' => 'queued', 'prospect_criteria_id' => $criteria->id]);
        $first = ProspectBatchItem::factory()->for($batch, 'batch')->create(['row_number' => 1, 'company_name' => 'Acme', 'normalized_name' => 'acme', 'provided_domain' => 'acme.fr', 'status' => 'pending']);
        $second = ProspectBatchItem::factory()->for($batch, 'batch')->create(['row_number' => 2, 'company_name' => 'Beta', 'normalized_name' => 'beta', 'provided_domain' => 'beta.fr', 'status' => 'pending']);
        foreach ([$first, $second] as $item) (new ProcessProspectBatchItemJob($item->id))->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));
        $this->assertSame(2, ProviderCall::query()->count());
        $firstCompany = Company::withRejected()->findOrFail($first->fresh()->company_id);
        $secondCompany = Company::withRejected()->findOrFail($second->fresh()->company_id);
        $firstRun = DiscoveryRun::query()->where('company_id', $firstCompany->id)->sole();
        $this->assertSame(Company::ENRICHMENT_HUNTER_FAILED, $firstCompany->enrichment_status);
        $this->assertNull($firstCompany->enrichment_claim_run_id);
        $this->assertTrue($firstRun->hunter_circuit_open);
        $this->assertSame(Company::ENRICHMENT_SKIPPED_PROVIDER_UNAVAILABLE, $secondCompany->enrichment_status);
        $this->assertSame('skipped', $second->fresh()->status);
        $this->assertNotSame(Company::ENRICHMENT_ENRICHING, $firstCompany->enrichment_status);
        $this->assertNotSame(Company::ENRICHMENT_ENRICHING, $secondCompany->enrichment_status);
    }

    public function test_confirmation_only_queues_finalizer_and_never_calls_hunter_in_request(): void
    {
        $service = app(ProspectBatchService::class);
        $actor = $this->actor();
        $criteria = $this->criteria();
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);

        $service->confirmAndDispatch($batch, $actor);

        Http::assertNothingSent();
        Queue::assertPushed(FinalizeProspectBatchJob::class, fn (FinalizeProspectBatchJob $job): bool => $job->batchId === $batch->id);
        $this->assertSame('queued', $batch->fresh()->status);
    }

    public function test_second_active_discover_batch_for_same_criteria_is_refused(): void
    {
        $service = app(ProspectBatchService::class);
        $actor = $this->actor();
        $criteria = $this->criteria();
        $first = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);
        $service->confirmAndDispatch($first, $actor);
        $second = $service->createDiscoverBatch($actor, $criteria, 'Fabricants', null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('prospect_discover_active_batch_exists');

        $service->confirmAndDispatch($second, $actor);
    }

    public function test_finalizer_collects_next_discover_page_outside_transaction_and_does_not_complete_early(): void
    {
        $service = app(ProspectBatchService::class);
        $actor = $this->actor();
        $criteria = $this->criteria();
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null, ['limit' => 1]);
        $service->confirmAndDispatch($batch, $actor);
        $levels = [];
        $baselineTransactionLevel = \Illuminate\Support\Facades\DB::transactionLevel();
        Http::fake(function (HttpRequest $request) use (&$levels) {
            $levels[] = \Illuminate\Support\Facades\DB::transactionLevel();

            return Http::response([
                'data' => [[
                    'domain' => 'acme.fr',
                    'organization' => 'ACME',
                ]],
                'meta' => [
                    'filters' => ['industry' => ['include' => ['Logistics']]],
                    'limit' => 1,
                    'offset' => 0,
                    'results' => 2,
                ],
            ]);
        });

        (new FinalizeProspectBatchJob($batch->id))->handle($service);

        $this->assertSame([$baselineTransactionLevel], $levels);
        $this->assertSame('running', $batch->fresh()->status);
        $this->assertFalse($batch->fresh()->source_cursor['exhausted']);
        $this->assertDatabaseCount('prospect_batch_items', 1);
    }

    public function test_exhausted_discover_with_terminal_items_completes(): void
    {
        $batch = ProspectBatch::query()->create([
            'source_type' => 'discover',
            'name' => 'Exhausted',
            'status' => 'running',
            'source_cursor' => ['exhausted' => true],
            'cost_confirmed_at' => now(),
        ]);
        ProspectBatchItem::query()->create([
            'prospect_batch_id' => $batch->id,
            'row_number' => 1,
            'original_input' => 'ACME',
            'company_name' => 'ACME',
            'normalized_name' => 'acme',
            'status' => 'ready',
        ]);

        (new FinalizeProspectBatchJob($batch->id))->handle(app(ProspectBatchService::class));

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->finished_at);
    }

    public function test_item_worker_releases_a_transient_failure_without_dispatching_a_finalizer(): void
    {
        $batch = ProspectBatch::factory()->create(['status' => 'running']);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'pending',
            'provided_domain' => 'acme.fr',
        ]);
        Http::fake(['api.hunter.io/v2/companies/find*' => Http::response([], 503, ['Retry-After' => '120'])]);
        $job = (new ProcessProspectBatchItemJob($item->id))->withFakeQueueInteractions();

        $job->handle(app(ProspectItemProcessor::class), app(ProspectBatchService::class));

        $job->assertReleased(120 + ($item->id % 11));
        $this->assertSame('pending', $item->fresh()->status);
        Queue::assertNotPushed(FinalizeProspectBatchJob::class);
    }

    public function test_exhausted_pending_retry_becomes_a_known_failure_and_dispatches_finalization(): void
    {
        $batch = ProspectBatch::factory()->create(['status' => 'running']);
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'pending',
            'domain_reason' => 'provided_domain',
            'error_code' => 'provider_unavailable',
        ]);

        (new ProcessProspectBatchItemJob($item->id))->failed(new ProviderRequestException('provider_unavailable', true, 503, 600));

        $item->refresh();
        $this->assertSame('failed', $item->status);
        $this->assertSame('provided_domain', $item->domain_reason);
        $this->assertSame('provider_unavailable', $item->error_code);
        Queue::assertPushed(FinalizeProspectBatchJob::class, fn (FinalizeProspectBatchJob $job): bool => $job->batchId === $batch->id);
    }

    public function test_finalizer_returns_without_releasing_or_terminalizing_a_retry_pending_batch(): void
    {
        $batch = ProspectBatch::factory()->create(['status' => 'running']);
        ProspectBatchItem::factory()->for($batch, 'batch')->create([
            'status' => 'pending',
            'error_code' => 'provider_unavailable',
        ]);
        $job = (new FinalizeProspectBatchJob($batch->id))->withFakeQueueInteractions();

        $job->handle(app(ProspectBatchService::class));

        $job->assertNotReleased();
        $this->assertSame('running', $batch->fresh()->status);
        $this->assertNull($batch->fresh()->error);
    }

    public function test_finalizer_terminalizes_without_releasing_when_discover_page_returns_zero(): void
    {
        $actor = $this->actor();
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null, ['limit' => 100]);
        $service->confirmAndDispatch($batch, $actor);
        // Hunter reporting zero rows for this page is a real collectDiscoverPage()
        // zero-return path (distinct from claim-refused/replayed) that also
        // exhausts the cursor in the same settle transaction, so it doubles as
        // proof handle() re-checks terminal state instead of always releasing.
        Http::fake(fn () => Http::response([
            'data' => [],
            'meta' => ['filters' => [], 'limit' => 100, 'offset' => 0, 'results' => 0],
        ]));

        $job = (new FinalizeProspectBatchJob($batch->id))->withFakeQueueInteractions();
        $job->handle($service);

        $job->assertNotReleased();
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertTrue($batch->fresh()->source_cursor['exhausted']);
    }

    public function test_failed_leaves_an_open_cursor_discover_batch_resumable(): void
    {
        $criteria = $this->criteria();
        $batch = ProspectBatch::query()->create([
            'source_type' => 'discover',
            'name' => 'Stranded discover',
            'status' => 'running',
            'prospect_criteria_id' => $criteria->id,
            'source_cursor' => ['exhausted' => false, 'offset' => 100, 'limit' => 100],
            'cost_confirmed_at' => now(),
        ]);

        (new FinalizeProspectBatchJob($batch->id))->failed(new \RuntimeException('boom'));

        $batch->refresh();
        $this->assertSame('running', $batch->status);
        $this->assertSame('finalization_delayed', $batch->error);
    }

    public function test_failed_still_terminalizes_a_non_discover_or_exhausted_batch_to_review(): void
    {
        $listBatch = ProspectBatch::factory()->create(['status' => 'running']);
        (new FinalizeProspectBatchJob($listBatch->id))->failed(new \RuntimeException('boom'));
        $this->assertSame('review', $listBatch->fresh()->status);
        $this->assertSame('finalization_delayed', $listBatch->fresh()->error);

        $criteria = $this->criteria();
        $exhaustedBatch = ProspectBatch::query()->create([
            'source_type' => 'discover',
            'name' => 'Exhausted discover',
            'status' => 'running',
            'prospect_criteria_id' => $criteria->id,
            'source_cursor' => ['exhausted' => true, 'offset' => 200, 'limit' => 100],
            'cost_confirmed_at' => now(),
        ]);
        (new FinalizeProspectBatchJob($exhaustedBatch->id))->failed(new \RuntimeException('boom'));
        $this->assertSame('review', $exhaustedBatch->fresh()->status);
        $this->assertSame('finalization_delayed', $exhaustedBatch->fresh()->error);
    }

    private function criteria(): ProspectCriteria
    {
        return ProspectCriteria::query()->create([
            'name' => 'Critere',
            'ai_target' => 'Exportateurs',
            'sectors' => ['Transport'],
            'countries' => ['FR'],
            'company_sizes' => ['11-50'],
            'daily_limit' => 10,
            'is_active' => true,
        ]);
    }

    private function actor(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->givePermissionTo(Permission::findOrCreate('run prospect resolution', 'web'));

        return $user;
    }

    private function fakeExcludingScorer(): void
    {
        $this->app->instance(
            \App\Services\Scoring\LeadScoringService::class,
            new class extends \App\Services\Scoring\LeadScoringService
            {
                public function __construct() {}

                public function score(array $candidate, ProspectCriteria $criteria, ?int $timeoutSeconds = null): array
                {
                    return ['score' => 95, 'explanation' => 'Concurrent direct', 'exclude' => true];
                }
            },
        );
    }
}
