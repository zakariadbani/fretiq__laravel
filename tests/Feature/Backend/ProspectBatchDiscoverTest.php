<?php

namespace Tests\Feature\Backend;

use App\Jobs\FinalizeProspectBatchJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectCriteria;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Discovery\HunterDiscoverService;
use App\Services\Prospecting\ProspectBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProspectBatchDiscoverTest extends TestCase
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

    public function test_preview_builds_prompt_and_estimate_without_any_http_request(): void
    {
        $user = $this->actorWithPermissions('backend.access', 'run discovery');
        $criteria = $this->criteria();

        $response = $this->actingAs($user)->postJson(
            route('admin.prospect_criteria.hunter_discover_preview', $criteria),
            ['target' => '  Exportateurs   industriels  ', 'exclude' => ' Concurrents locaux '],
        );

        $response->assertOk()
            ->assertJsonPath('draft.source_type', 'discover')
            ->assertJsonPath('draft.target', 'Exportateurs industriels')
            ->assertJsonPath('draft.exclude', 'Concurrents locaux')
            ->assertJsonPath('estimate.calls.hunter_discover', 1)
            ->assertJsonPath('estimate.calls.hunter_domain_search', 20)
            ->assertJsonPath('estimate.calls.hunter_company_enrichment', 20)
            ->assertJsonPath('estimate.reserved_units.hunter', 24);
        $this->assertStringContainsString('Cible: Exportateurs industriels.', $response->json('prompt'));
        $this->assertDatabaseCount('prospect_batches', 0);
        $this->assertDatabaseCount('provider_calls', 0);
        Http::assertNothingSent();
    }

    public function test_discover_service_preview_is_also_local_only(): void
    {
        $result = app(HunterDiscoverService::class)->preview(
            $this->criteria(),
            'Exportateurs',
            'Concurrents',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['companies']);
        $this->assertDatabaseCount('provider_calls', 0);
        Http::assertNothingSent();
    }

    public function test_import_endpoint_creates_or_reuses_a_draft_without_companies_or_provider_calls(): void
    {
        $user = $this->actorWithPermissions('backend.access', 'run discovery', 'run prospect resolution');
        $criteria = $this->criteria();
        $payload = [
            'target' => 'Exportateurs industriels',
            'exclude' => 'Concurrents locaux',
            'quality_preset' => 'balanced',
        ];

        $first = $this->actingAs($user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'draft');
        $second = $this->actingAs($user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), $payload)
            ->assertCreated();

        $this->assertSame($first->json('batch_id'), $second->json('batch_id'));
        $this->assertSame(
            route('admin.prospect_batches.edit', ['id' => $first->json('batch_id'), 'step' => 3]),
            $first->json('redirect_url'),
        );
        $this->assertDatabaseCount('prospect_batches', 1);
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('provider_calls', 0);
        Http::assertNothingSent();
    }

    public function test_import_endpoint_refuses_without_run_prospect_resolution_permission(): void
    {
        // hunterDiscoverImport only authorizes `run discovery`; confirm()
        // requires `run prospect resolution`. Without this pre-check a user
        // with just `run discovery` could create a draft they can never
        // launch — orphaning it. The check must fire before any draft exists.
        $user = $this->actorWithPermissions('backend.access', 'run discovery');
        $criteria = $this->criteria();

        $this->actingAs($user)
            ->postJson(route('admin.prospect_criteria.hunter_discover_import', $criteria), [
                'target' => 'Exportateurs industriels',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('prospect_batches', 0);
        $this->assertDatabaseCount('provider_calls', 0);
        Http::assertNothingSent();
    }

    public function test_first_provider_call_occurs_only_after_explicit_cost_confirmation(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);

        $this->assertNull($batch->cost_confirmed_at);
        $this->assertDatabaseCount('provider_calls', 0);
        Http::assertNothingSent();

        // RefreshDatabase keeps one harness transaction open on SQLite. The
        // provider call must not add a service-owned transaction above it.
        $baselineTransactionLevel = DB::transactionLevel();
        $transactionLevels = [];
        Http::fake(function (HttpRequest $request) use (&$transactionLevels) {
            $transactionLevels[] = DB::transactionLevel();

            return Http::response([
                'data' => [[
                    'domain' => 'acme.fr',
                    'organization' => 'ACME',
                    'emails_count' => ['personal' => 1, 'generic' => 0, 'total' => 1],
                ]],
                'meta' => ['filters' => [], 'limit' => 100, 'offset' => 0, 'results' => 1],
            ]);
        });

        $confirmed = $service->confirmAndDispatch($batch, $actor);

        $this->assertNotNull($confirmed->fresh()->cost_confirmed_at);
        $this->assertSame([], $transactionLevels);
        Http::assertNothingSent();
        Queue::assertPushed(FinalizeProspectBatchJob::class, 1);

        (new FinalizeProspectBatchJob($batch->id))->handle($service);
        $this->assertSame([$baselineTransactionLevel], $transactionLevels);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('prospect_batch_items', [
            'prospect_batch_id' => $batch->id,
            'company_name' => 'ACME',
            'provided_domain' => 'acme.fr',
        ]);
        $this->assertDatabaseHas('provider_calls', [
            'prospect_batch_id' => $batch->id,
            'provider' => 'hunter',
            'operation' => 'discover',
            'status' => 'succeeded',
        ]);
        Queue::assertPushed(ProcessProspectBatchItemJob::class, 1);
        Queue::assertPushed(FinalizeProspectBatchJob::class, 1);
    }

    public function test_ai_prompt_is_sent_once_then_meta_filters_drive_later_pages(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', 'Concurrents');
        $filters = [
            'headquarters_location' => ['include' => [['country' => 'FR']]],
            'industry' => ['include' => ['Logistics']],
        ];
        $requests = [];
        $page = 0;

        Http::fake(function (HttpRequest $request) use (&$requests, &$page, $filters) {
            $requests[] = $request->data();
            $response = $page++ === 0
                ? ['data' => $this->discoverRows(0, 100), 'meta' => ['filters' => $filters, 'limit' => 100, 'offset' => 0, 'results' => 150]]
                : ['data' => $this->discoverRows(100, 50), 'meta' => ['filters' => $filters, 'limit' => 100, 'offset' => 100, 'results' => 150]];

            return Http::response($response);
        });

        $this->confirmAndRunFirstDiscoverPage($service, $batch, $actor);
        $this->assertSame(50, $service->collectDiscoverPage($batch->fresh()));

        $this->assertCount(2, $requests);
        $this->assertSame(['query', 'limit'], array_keys($requests[0]));
        $this->assertStringContainsString('Cible: Exportateurs.', $requests[0]['query']);
        $this->assertStringContainsString('Exclure: Concurrents.', $requests[0]['query']);
        $this->assertSame(100, $requests[0]['limit']);
        $this->assertArrayNotHasKey('offset', $requests[0]);
        $this->assertArrayNotHasKey('query', $requests[1]);
        $this->assertSame($filters + ['limit' => 100, 'offset' => 100], $requests[1]);
        $this->assertSame(150, $batch->items()->count());
    }

    public function test_filters_offset_and_exhaustion_are_persisted(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);
        $filters = ['industry' => ['include' => ['Logistics']]];

        Http::fake(fn () => Http::response([
            'data' => $this->discoverRows(0, 2),
            'meta' => ['filters' => $filters, 'limit' => 25, 'offset' => 0, 'results' => 2],
        ]));

        $this->confirmAndRunFirstDiscoverPage($service, $batch, $actor);

        $cursor = $batch->fresh()->source_cursor;
        $this->assertSame($filters, $criteria->fresh()->hunter_discover_filters);
        $this->assertSame(25, $criteria->fresh()->hunter_discover_offset);
        $this->assertTrue($criteria->fresh()->hunter_discover_exhausted);
        $this->assertSame(25, $cursor['offset']);
        $this->assertSame(25, $cursor['limit']);
        $this->assertSame(2, $cursor['results']);
        $this->assertTrue($cursor['exhausted']);
        $this->assertTrue($cursor['initialized']);
    }

    public function test_target_or_exclusion_change_invalidates_prompt_hash_filters_and_offset(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria(['ai_target' => 'Exportateurs', 'ai_exclude' => 'Concurrents']);
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', 'Concurrents');
        $sent = 0;

        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response([
                'data' => $this->discoverRows(0, 1),
                'meta' => [
                    'filters' => ['industry' => ['include' => ['Logistics']]],
                    'limit' => 1,
                    'offset' => 0,
                    'results' => 10,
                ],
            ]);
        });
        $this->confirmAndRunFirstDiscoverPage($service, $batch, $actor);

        $criteria->update(['ai_target' => 'Fabricants pharmaceutiques']);
        $criteria->refresh();

        $this->assertNull($criteria->hunter_discover_prompt_hash);
        $this->assertNull($criteria->hunter_discover_filters);
        $this->assertSame(0, $criteria->hunter_discover_offset);
        $this->assertFalse($criteria->hunter_discover_exhausted);

        try {
            $service->collectDiscoverPage($batch->fresh());
            $this->fail('A batch with an invalidated targeting hash must not collect another page.');
        } catch (LogicException $exception) {
            $this->assertSame('prospect_discover_prompt_stale', $exception->getMessage());
        }

        $this->assertSame(1, $sent);
    }

    public function test_equivalent_whitespace_and_set_order_do_not_invalidate_discover_state(): void
    {
        $criteria = $this->criteria([
            'ai_target' => 'Exportateurs industriels',
            'ai_exclude' => 'Concurrents locaux',
        ]);
        $criteria->forceFill([
            'hunter_discover_filters' => ['industry' => ['include' => ['Logistics']]],
            'hunter_discover_prompt_hash' => str_repeat('a', 64),
            'hunter_discover_offset' => 100,
            'hunter_discover_exhausted' => true,
        ])->save();

        $criteria->update([
            'ai_target' => '  Exportateurs   industriels ',
            'ai_exclude' => 'Concurrents   locaux',
            'sectors' => [' Logistique ', 'Transport'],
            'countries' => [' FR '],
            'company_sizes' => [' 11-50 '],
        ]);

        $criteria->refresh();
        $this->assertSame(str_repeat('a', 64), $criteria->hunter_discover_prompt_hash);
        $this->assertSame(['industry' => ['include' => ['Logistics']]], $criteria->hunter_discover_filters);
        $this->assertSame(100, $criteria->hunter_discover_offset);
        $this->assertTrue($criteria->hunter_discover_exhausted);
    }

    public function test_discover_page_key_contains_prompt_hash_filters_hash_and_offset(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);

        Http::fake(fn () => Http::response([
            'data' => [],
            'meta' => ['filters' => [], 'limit' => 100, 'offset' => 0, 'results' => 0],
        ]));
        $this->confirmAndRunFirstDiscoverPage($service, $batch, $actor);

        $promptHash = $batch->fresh()->source_options['prompt_hash'];
        $filtersHash = hash('sha256', '[]');
        $expected = hash('sha256', "discover|{$batch->id}|{$promptHash}|{$filtersHash}|0");

        $this->assertSame($expected, ProviderCall::query()->sole()->idempotency_key);
    }

    public function test_two_batches_with_the_same_target_use_distinct_provider_keys(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $service = app(ProspectBatchService::class);
        $first = $service->createDiscoverBatch($actor, $this->criteria(['name' => 'Premier critère']), 'Exportateurs', null);
        $second = $service->createDiscoverBatch($actor, $this->criteria(['name' => 'Second critère']), 'Exportateurs', null);
        Http::fake(fn () => Http::response([
            'data' => [],
            'meta' => ['filters' => [], 'limit' => 100, 'offset' => 0, 'results' => 0],
        ]));

        $this->confirmAndRunFirstDiscoverPage($service, $first, $actor);
        $this->confirmAndRunFirstDiscoverPage($service, $second, $actor);

        $this->assertSame(2, ProviderCall::query()->count());
        $this->assertCount(2, ProviderCall::query()->pluck('idempotency_key')->unique());
        Http::assertSentCount(2);
    }

    public function test_missing_first_page_filters_stops_pagination_instead_of_sending_a_global_request(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);
        $sent = 0;

        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response([
                'data' => $this->discoverRows(0, 100),
                'meta' => ['limit' => 100, 'offset' => 0, 'results' => 500],
            ]);
        });

        $this->confirmAndRunFirstDiscoverPage($service, $batch, $actor);

        $this->assertTrue($batch->fresh()->source_cursor['exhausted']);
        $this->assertSame(0, $service->collectDiscoverPage($batch->fresh()));
        $this->assertSame(1, $sent);
    }

    public function test_stale_cursor_response_is_settled_without_staging_or_rewinding(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);
        $batch->forceFill([
            'status' => 'queued',
            'cost_confirmed_at' => now(),
        ])->save();
        $criteria->forceFill([
            'hunter_discover_prompt_hash' => $batch->source_options['prompt_hash'],
            'hunter_discover_filters' => null,
            'hunter_discover_offset' => 0,
            'hunter_discover_exhausted' => false,
        ])->save();

        Http::fake(function () use ($batch, $criteria) {
            $cursor = $batch->fresh()->source_cursor;
            $cursor['offset'] = 50;
            $cursor['initialized'] = true;
            $batch->newQuery()->whereKey($batch->id)->update(['source_cursor' => json_encode($cursor, JSON_THROW_ON_ERROR)]);
            $criteria->newQuery()->whereKey($criteria->id)->update(['hunter_discover_offset' => 50]);

            return Http::response([
                'data' => $this->discoverRows(0, 1),
                'meta' => ['filters' => [], 'limit' => 100, 'offset' => 0, 'results' => 1],
            ]);
        });

        $this->assertSame(0, $service->collectDiscoverPage($batch));
        $this->assertDatabaseCount('prospect_batch_items', 0);
        $this->assertSame(50, $batch->fresh()->source_cursor['offset']);
        $this->assertSame('succeeded', ProviderCall::query()->sole()->status);
        $this->assertSame('0.00', ProviderCall::query()->sole()->consumed_units);
    }

    public function test_resume_reopens_review_batch_and_collects_the_final_page(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null, ['limit' => 100]);
        $filters = ['industry' => ['include' => ['Logistics']]];
        $page = 0;

        // A single fake with a page counter, not two Http::fake() calls: the
        // second registration doesn't replace the first catch-all closure
        // (proven pattern already used by test_ai_prompt_is_sent_once_...
        // above), so the second HTTP call would otherwise still see page 1.
        Http::fake(function () use (&$page, $filters) {
            $response = $page++ === 0
                ? ['data' => $this->discoverRows(0, 100), 'meta' => ['filters' => $filters, 'limit' => 100, 'offset' => 0, 'results' => 197]]
                : ['data' => $this->discoverRows(100, 97), 'meta' => ['filters' => $filters, 'limit' => 100, 'offset' => 100, 'results' => 197]];

            return Http::response($response);
        });
        $this->confirmAndRunFirstDiscoverPage($service, $batch, $actor);

        // Batch 2's real trap: page-1 items already resolved, the finalizer's
        // own pending-item gate isn't what re-blocks discovery below — the
        // forced status='review' write is. Simulate the pre-fix stranding.
        $batch->items()->update(['status' => 'ready']);
        $batch->fresh()->forceFill(['status' => 'review', 'error' => 'finalization_delayed'])->save();

        $resumed = $service->resumeDiscoverBatch($batch->fresh(), $actor);

        $this->assertSame('queued', $resumed->status);
        $this->assertNull($resumed->error);
        Queue::assertPushed(FinalizeProspectBatchJob::class, fn (FinalizeProspectBatchJob $job): bool => $job->batchId === $batch->id);

        (new FinalizeProspectBatchJob($batch->id))->handle($service);

        $this->assertSame('running', $batch->fresh()->status);
        $this->assertTrue($batch->fresh()->source_cursor['exhausted']);
        $this->assertSame(197, $batch->items()->count());
    }

    public function test_resume_refuses_an_exhausted_cursor(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null);
        $batch->forceFill([
            'status' => 'review',
            'error' => 'finalization_delayed',
            'cost_confirmed_at' => now(),
            'source_cursor' => [
                'offset' => 100,
                'limit' => 100,
                'exhausted' => true,
                'initialized' => true,
                'filters_hash' => hash('sha256', '[]'),
                'prompt_hash' => $batch->source_options['prompt_hash'],
            ],
        ])->save();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('prospect_discover_not_resumable');

        $service->resumeDiscoverBatch($batch->fresh(), $actor);
    }

    public function test_resume_refuses_an_orphaned_criterion_cursor(): void
    {
        $actor = $this->actorWithPermissions('run prospect resolution');
        $criteria = $this->criteria();
        $service = app(ProspectBatchService::class);
        $batch = $service->createDiscoverBatch($actor, $criteria, 'Exportateurs', null, ['limit' => 100]);

        Http::fake(fn () => Http::response([
            'data' => $this->discoverRows(0, 100),
            'meta' => ['filters' => ['industry' => ['include' => ['Logistics']]], 'limit' => 100, 'offset' => 0, 'results' => 197],
        ]));
        $this->confirmAndRunFirstDiscoverPage($service, $batch, $actor);
        $batch->fresh()->forceFill(['status' => 'review', 'error' => 'finalization_delayed'])->save();

        // A fresh Discover confirm on the same criteria (possible once change
        // 4 ships) resets its offset/exhausted and overwrites the prompt
        // hash, orphaning batch's still-open cursor.
        $second = $service->createDiscoverBatch($actor, $criteria, 'Fabricants', null);
        $service->confirmAndDispatch($second, $actor);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('prospect_discover_resume_criteria_stale');

        $service->resumeDiscoverBatch($batch->fresh(), $actor);
    }

    private function confirmAndRunFirstDiscoverPage(
        ProspectBatchService $service,
        \App\Models\ProspectBatch $batch,
        User $actor,
    ): void {
        $service->confirmAndDispatch($batch, $actor);
        (new FinalizeProspectBatchJob($batch->id))->handle($service);
    }

    private function criteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::query()->create(array_replace([
            'name' => 'Critère Discover',
            'ai_target' => 'Exportateurs',
            'ai_exclude' => null,
            'sectors' => ['Transport', 'Logistique'],
            'countries' => ['FR'],
            'company_sizes' => ['11-50'],
            'daily_limit' => 10,
            'is_active' => true,
        ], $overrides));
    }

    private function actorWithPermissions(string ...$permissions): User
    {
        $actor = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        foreach ($permissions as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $actor;
    }

    /** @return list<array<string, mixed>> */
    private function discoverRows(int $start, int $count): array
    {
        $rows = [];
        for ($index = $start; $index < $start + $count; $index++) {
            $rows[] = [
                'domain' => "company-{$index}.example.com",
                'organization' => "Company {$index}",
                'emails_count' => ['personal' => 1, 'generic' => 1, 'total' => 2],
            ];
        }

        return $rows;
    }
}
