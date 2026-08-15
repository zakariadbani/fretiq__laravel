<?php

namespace Tests\Feature\Backend;

use App\Jobs\FinalizeProspectBatchJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\Company;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Models\User;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Prospecting\ProspectItemProcessor;
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
}
