<?php

namespace Tests\Unit;

use App\Exceptions\DiscoveryConfigurationException;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\DiscoveryCollectionResult;
use App\Services\Settings\SettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyDiscoveryCollectionLifecycleTest extends TestCase
{
    private CompanyDiscoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'services.serpapi.driver' => 'local',
            'services.serpapi.api_key' => null,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->createTables();
        Setting::set('decouverte.discovery_engines', ['google']);
        app(SettingService::class)->clearCache();

        $this->service = new CompanyDiscoveryService;
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_local_collection_returns_typed_terminal_result_and_persists_exact_snapshot(): void
    {
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 3);
        $expected = $this->service->discover($criteria, 1_000);

        DB::table('discovery_runs')->where('id', $run->id)->update([
            'updated_at' => '2000-01-01 00:00:00',
        ]);

        $result = $this->service->discoverForRun($criteria, $run->fresh(), 1);
        $freshRun = $run->fresh();
        $resultType = new \ReflectionClass($result);

        $this->assertInstanceOf(DiscoveryCollectionResult::class, $result);
        $this->assertTrue($resultType->isFinal());
        $this->assertTrue($resultType->isReadOnly());
        $this->assertTrue($result->terminal);
        $this->assertSame($expected, $result->candidates);
        $this->assertSame($expected, $freshRun->candidates_snapshot);
        $this->assertSame(0, $freshRun->searches_consumed);
        $this->assertTrue($freshRun->updated_at->greaterThan('2000-01-01 00:00:00'));
        Http::assertNothingSent();
    }

    public function test_local_collection_reuses_a_fully_consumed_snapshot_instead_of_regenerating_it(): void
    {
        $criteria = $this->makeCriteria();
        $snapshot = [[
            'domain' => 'durable.test',
            'title' => 'Durable',
            'snippet' => null,
            'url' => 'https://durable.test',
        ]];
        $run = $this->makeRun($criteria, searchesReserved: 3, consumed: 1, snapshot: $snapshot);

        $result = $this->service->discoverForRun($criteria, $run, 1);

        $this->assertTrue($result->terminal);
        $this->assertSame($snapshot, $result->candidates);
        $this->assertSame($snapshot, $run->fresh()->candidates_snapshot);
        Http::assertNothingSent();
    }

    public function test_local_collection_reuses_an_explicit_empty_snapshot(): void
    {
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 3, snapshot: []);

        $result = $this->service->discoverForRun($criteria, $run, 1);

        $this->assertTrue($result->terminal);
        $this->assertSame([], $result->candidates);
        $this->assertSame([], $run->fresh()->candidates_snapshot);
        Http::assertNothingSent();
    }

    public function test_local_collection_rechecks_the_durable_snapshot_when_the_run_instance_is_stale(): void
    {
        $criteria = $this->makeCriteria();
        $staleRun = $this->makeRun($criteria, searchesReserved: 3);
        $durableSnapshot = [[
            'domain' => 'won-the-race.test',
            'title' => null,
            'snippet' => null,
            'url' => 'https://won-the-race.test',
        ]];

        DiscoveryRun::whereKey($staleRun->id)->update([
            'candidates_snapshot' => json_encode($durableSnapshot),
        ]);

        $result = $this->service->discoverForRun($criteria, $staleRun, 1);

        $this->assertTrue($result->terminal);
        $this->assertSame($durableSnapshot, $result->candidates);
        $this->assertSame($durableSnapshot, $staleRun->fresh()->candidates_snapshot);
        Http::assertNothingSent();
    }

    public function test_deadline_before_live_call_sends_and_debits_nothing_and_is_nonterminal(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 3);

        Http::fake();

        $result = $this->service->discoverForRun($criteria, $run, 3, microtime(true) + 0.5);

        $this->assertFalse($result->terminal);
        $this->assertSame([], $result->candidates);
        $this->assertSame(0, $run->fresh()->searches_consumed);
        Http::assertNothingSent();
    }

    public function test_deadline_after_one_live_page_preserves_it_and_the_next_attempt_resumes_from_its_cursor(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 2);
        $requests = [];

        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request;

            if (count($requests) === 1) {
                // Leave less than the full second required to start another call.
                usleep(650_000);

                return Http::response([
                    'organic_results' => [[
                        'title' => 'First durable page',
                        'link' => 'https://first-page.test/about',
                        'snippet' => 'Persisted before the deadline',
                    ]],
                    'serpapi_pagination' => ['next' => 'https://serpapi.test/next?start=10'],
                ], 200);
            }

            return Http::response([
                'organic_results' => [[
                    'title' => 'Resumed page',
                    'link' => 'https://resumed-page.test/about',
                    'snippet' => 'Fetched by the next attempt',
                ]],
            ], 200);
        });

        $interrupted = $this->service->discoverForRun(
            $criteria,
            $run,
            2,
            microtime(true) + 1.5,
        );
        $afterFirstPage = $run->fresh();

        $this->assertFalse($interrupted->terminal);
        $this->assertSame(['first-page.test'], array_column($interrupted->candidates, 'domain'));
        $this->assertSame(1, $afterFirstPage->searches_consumed);
        $this->assertSame(10, (int) data_get(
            $criteria->fresh()->discovery_cursors,
            md5('transitaire France').'.start',
        ));
        $this->assertCount(1, $requests);

        // The pipeline consumes the durable page before asking collection to resume.
        $afterFirstPage->forceFill(['consumed' => 1])->save();

        $resumed = $this->service->discoverForRun(
            $criteria->fresh(),
            $afterFirstPage->fresh(),
            2,
            microtime(true) + 3,
        );
        $freshRun = $run->fresh();

        $this->assertTrue($resumed->terminal);
        $this->assertSame(
            ['first-page.test', 'resumed-page.test'],
            array_column($resumed->candidates, 'domain'),
        );
        $this->assertSame(2, count(array_unique(array_column($freshRun->candidates_snapshot, 'domain'))));
        $this->assertSame(2, $freshRun->searches_consumed);
        $this->assertCount(2, $requests);
        $this->assertSame('10', (string) $requests[1]->data()['start']);
    }

    public function test_live_collection_throws_an_explicit_exception_when_api_key_is_missing(): void
    {
        $this->useLiveDriver(null);
        Http::fake();
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 3);

        $this->expectException(DiscoveryConfigurationException::class);
        $this->expectExceptionMessage('clé API');

        $this->service->discoverForRun($criteria, $run, 1);
    }

    public function test_live_collection_throws_an_explicit_exception_when_no_stream_is_enabled(): void
    {
        $this->useLiveDriver('test-key');
        Http::fake();
        $criteria = $this->makeCriteria([
            'ai_queries' => [['q' => 'transitaire France', 'enabled' => false]],
        ]);
        $run = $this->makeRun($criteria, searchesReserved: 3);

        $this->expectException(DiscoveryConfigurationException::class);
        $this->expectExceptionMessage('aucune requête ni aucun moteur');

        $this->service->discoverForRun($criteria, $run, 1);
    }

    public function test_zero_invocation_budget_is_nonterminal_while_durable_reservation_remains(): void
    {
        $this->useLiveDriver('test-key');
        Http::fake();
        $criteria = $this->makeCriteria();
        $snapshot = [[
            'domain' => 'already-collected.test',
            'title' => null,
            'snippet' => null,
            'url' => 'https://already-collected.test',
        ]];
        $run = $this->makeRun($criteria, searchesReserved: 3, snapshot: $snapshot);

        $result = $this->service->discoverForRun($criteria, $run, 0);

        $this->assertFalse($result->terminal);
        $this->assertSame($snapshot, $result->candidates);
        Http::assertNothingSent();
    }

    public function test_zero_invocation_budget_still_finalizes_when_every_durable_stream_is_exhausted(): void
    {
        $this->useLiveDriver(null);
        Http::fake();
        $query = 'transitaire France';
        $criteria = $this->makeCriteria([
            'ai_queries' => [['q' => $query, 'enabled' => true]],
            'discovery_cursors' => [
                md5($query) => [
                    'q' => $query,
                    'engine' => 'google',
                    'start' => 10,
                    'exhausted' => true,
                    'provider_params' => [],
                ],
            ],
        ]);
        $run = $this->makeRun($criteria, searchesReserved: 3);

        $result = $this->service->discoverForRun($criteria, $run, 0);

        $this->assertTrue($result->terminal);
        $this->assertSame(
            $run->id,
            data_get(
                $criteria->fresh()->discovery_cursors,
                ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY,
            ),
        );
        Http::assertNothingSent();
    }

    public function test_consumed_search_reservation_is_terminal_without_requiring_live_configuration(): void
    {
        $this->useLiveDriver(null);
        Http::fake();
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 2, searchesConsumed: 2);

        $result = $this->service->discoverForRun($criteria, $run, 1);

        $this->assertTrue($result->terminal);
        $this->assertSame([], $result->candidates);
        $this->assertSame(
            $run->id,
            data_get(
                $criteria->fresh()->discovery_cursors,
                ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY,
            ),
        );
        Http::assertNothingSent();
    }

    public function test_all_enabled_stream_cursors_exhausted_is_terminal_without_requiring_live_configuration(): void
    {
        $this->useLiveDriver(null);
        Http::fake();
        $query = 'transitaire France';
        $criteria = $this->makeCriteria([
            'ai_queries' => [['q' => $query, 'enabled' => true]],
            'discovery_cursors' => [
                md5($query) => [
                    'q' => $query,
                    'engine' => 'google',
                    'start' => 10,
                    'exhausted' => true,
                    'provider_params' => [],
                ],
            ],
        ]);
        $run = $this->makeRun($criteria, searchesReserved: 3);

        $result = $this->service->discoverForRun($criteria, $run, 1);

        $this->assertTrue($result->terminal);
        $this->assertSame([], $result->candidates);
        $this->assertSame(
            $run->id,
            data_get(
                $criteria->fresh()->discovery_cursors,
                ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY,
            ),
        );
        Http::assertNothingSent();
    }

    public function test_unprocessed_snapshot_is_returned_nonterminal_when_provider_work_remains(): void
    {
        $this->useLiveDriver(null);
        $criteria = $this->makeCriteria();
        $snapshot = [[
            'domain' => 'queued.test',
            'title' => null,
            'snippet' => null,
            'url' => 'https://queued.test',
        ]];
        $run = $this->makeRun($criteria, searchesReserved: 3, consumed: 0, snapshot: $snapshot);

        Http::fake();

        $result = $this->service->discoverForRun($criteria, $run, 2);

        $this->assertFalse($result->terminal);
        $this->assertSame($snapshot, $result->candidates);
        $this->assertSame(0, $run->fresh()->searches_consumed);
        Http::assertNothingSent();
    }

    public function test_invocation_cap_is_nonterminal_while_reservation_and_stream_work_remain(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 20);

        Http::fake([
            '*' => Http::response(['error' => 'temporary provider failure'], 500),
        ]);

        $result = $this->service->discoverForRun($criteria, $run, 20);

        $this->assertSame([], $result->candidates);
        $this->assertSame(15, $run->fresh()->searches_consumed);
        Http::assertSentCount(15);
        $this->assertFalse($result->terminal);
    }

    public function test_smaller_invocation_budget_does_not_replace_the_durable_reservation_terminal_rule(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 5);

        Http::fake([
            '*' => Http::response(['error' => 'temporary provider failure'], 500),
        ]);

        $result = $this->service->discoverForRun($criteria, $run, 2);

        $this->assertSame(2, $run->fresh()->searches_consumed);
        Http::assertSentCount(2);
        $this->assertFalse($result->terminal);
    }

    public function test_all_429_responses_with_an_empty_snapshot_signals_search_provider_down_and_stops_after_one_call(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 10);

        Http::fake([
            '*' => Http::response(['error' => 'quota exceeded'], 429),
        ]);

        $result = $this->service->discoverForRun($criteria, $run, 10);

        $this->assertTrue($result->searchProviderDown);
        $this->assertSame([], $result->candidates);
        // Debit-before-network-IO invariant is preserved: searches_consumed still
        // equals the attempts actually made, even though the loop stopped cold on
        // the very first 429 instead of burning the rest of the reservation.
        $this->assertSame(1, $run->fresh()->searches_consumed);
        Http::assertSentCount(1);
    }

    public function test_all_429_responses_with_a_preloaded_snapshot_drains_existing_candidates_instead_of_signaling_down(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $snapshot = [[
            'domain' => 'already-collected.test',
            'title' => null,
            'snippet' => null,
            'url' => 'https://already-collected.test',
        ]];
        // consumed must match count($snapshot) or discoverForRun() short-circuits
        // before ever calling the provider (durable candidates awaiting processing).
        $run = $this->makeRun($criteria, searchesReserved: 10, consumed: 1, snapshot: $snapshot);

        Http::fake([
            '*' => Http::response(['error' => 'quota exceeded'], 429),
        ]);

        $result = $this->service->discoverForRun($criteria, $run, 10);

        $this->assertTrue($result->terminal);
        $this->assertFalse($result->searchProviderDown);
        $this->assertSame($snapshot, $result->candidates);
        Http::assertSentCount(1);
    }

    public function test_non_running_run_is_not_debited_or_sent_to_the_provider(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 2);
        $run->update(['status' => 'failed']);

        Http::fake();

        $result = $this->service->discoverForRun($criteria, $run->fresh(), 2);

        $this->assertSame(0, $run->fresh()->searches_consumed);
        Http::assertNothingSent();
        $this->assertSame([], $result->candidates);
        $this->assertFalse($result->terminal);
    }

    public function test_run_terminalized_during_request_cannot_append_the_provider_page(): void
    {
        $this->useLiveDriver('test-key');
        $criteria = $this->makeCriteria();
        $run = $this->makeRun($criteria, searchesReserved: 2);

        Http::fake(function () use ($run) {
            DiscoveryRun::whereKey($run->id)->update(['status' => 'failed']);

            return Http::response([
                'organic_results' => [[
                    'title' => 'Late page',
                    'link' => 'https://late-page.test/about',
                    'snippet' => 'Must not be appended',
                ]],
                'serpapi_pagination' => ['next' => 'https://serpapi.test/next?start=10'],
            ], 200);
        });

        $result = $this->service->discoverForRun($criteria, $run, 2);
        $freshRun = $run->fresh();

        $this->assertSame([], $result->candidates);
        $this->assertSame(1, $freshRun->searches_consumed);
        $this->assertNull($freshRun->candidates_snapshot);
        $this->assertSame('failed', $freshRun->status);
        $this->assertSame(0, (int) data_get(
            $criteria->fresh()->discovery_cursors,
            md5('transitaire France').'.start',
        ));
        Http::assertSentCount(1);
        $this->assertFalse($result->terminal);
    }

    private function useLiveDriver(?string $apiKey): void
    {
        config([
            'services.serpapi.driver' => 'serpapi',
            'services.serpapi.api_key' => $apiKey,
        ]);
    }

    private function makeCriteria(array $overrides = []): ProspectCriteria
    {
        $criteria = new ProspectCriteria;
        $criteria->forceFill(array_merge([
            'name' => 'Lifecycle '.uniqid(),
            'ai_queries' => [['q' => 'transitaire France', 'enabled' => true]],
            'discovery_cursors' => null,
            'sectors' => [],
            'countries' => [],
        ], $overrides));
        $criteria->save();

        return $criteria;
    }

    private function makeRun(
        ProspectCriteria $criteria,
        int $searchesReserved,
        int $searchesConsumed = 0,
        int $consumed = 0,
        ?array $snapshot = null,
    ): DiscoveryRun {
        $run = new DiscoveryRun;
        $run->forceFill([
            'prospect_criteria_id' => $criteria->id,
            'status' => 'running',
            'credits_reserved' => $searchesReserved,
            'searches_reserved' => $searchesReserved,
            'searches_consumed' => $searchesConsumed,
            'consumed' => $consumed,
            'candidates_snapshot' => $snapshot,
        ]);
        $run->save();

        return $run;
    }

    private function createTables(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_name');
            $table->string('setting_key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['group_name', 'setting_key']);
        });

        Schema::create('prospect_criteria', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('ai_queries')->nullable();
            $table->text('discovery_cursors')->nullable();
            $table->text('sectors')->nullable();
            $table->text('countries')->nullable();
            $table->timestamps();
        });

        Schema::create('discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prospect_criteria_id');
            $table->string('status')->default('running');
            $table->unsignedInteger('credits_reserved')->default(0);
            $table->unsignedInteger('searches_reserved')->nullable();
            $table->unsignedInteger('searches_consumed')->default(0);
            $table->unsignedInteger('consumed')->default(0);
            $table->text('candidates_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->nullable();
            $table->unsignedBigInteger('criteria_id')->nullable();
            $table->string('qualification_status')->nullable();
        });
    }
}
