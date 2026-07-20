<?php

namespace Tests\Unit;

use App\Console\Commands\DiscoveryTerminalizeStale;
use App\Exceptions\DiscoveryConfigurationException;
use App\Jobs\EnrichCriteriaContactsJob;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryExecutionDeadline;
use App\Services\Discovery\DiscoveryPipelineResult;
use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DiscoveryJobLifecycleTest extends TestCase
{
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
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->createTables();
        Carbon::setTestNow('2026-07-19 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_job_exposes_the_resumable_queue_policy(): void
    {
        $job = new RunDiscoveryPipelineJob(1, 1);

        $this->assertSame(20, $job->tries);
        $this->assertSame(2, $job->maxExceptions);
        $this->assertSame(540, $job->timeout);
    }

    public function test_backlog_and_discovery_receive_independent_configurable_deadlines(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Jobs/RunDiscoveryPipelineJob.php');

        $this->assertMatchesRegularExpression(
            '/new DiscoveryExecutionDeadline\(\s*\$attemptStartedAt,\s*\$this->discoveryAttemptBudget\(\)/s',
            $source,
        );
        $this->assertMatchesRegularExpression(
            '/new DiscoveryExecutionDeadline\(\s*\$discoveryStartedAt,\s*\$this->discoveryAttemptBudget\(\)/s',
            $source,
        );
        $this->assertMatchesRegularExpression(
            '/\$pipeline->run\([^;]*\$discoveryStartedAt[^;]*\$discoveryDeadline/s',
            $source,
        );
        $this->assertSame(2, substr_count($source, 'new DiscoveryExecutionDeadline('));
        $this->assertStringContainsString('$discoveryStartedAt = microtime(true);', $source);
        $this->assertStringContainsString('return max(30, min(240, (int) $value));', $source);
    }

    public function test_discovery_and_manual_batches_share_the_same_criteria_overlap_lock(): void
    {
        $discoveryJob = new RunDiscoveryPipelineJob(42, 1);
        $manualJob = new EnrichCriteriaContactsJob(42);
        $discoveryLock = $discoveryJob->middleware()[0];
        $manualLock = $manualJob->middleware()[0];

        $this->assertSame($discoveryLock->getLockKey($discoveryJob), $manualLock->getLockKey($manualJob));
        $this->assertSame('criteria-enrichment:42', $discoveryLock->key);
        $this->assertTrue($discoveryLock->shareKey);
        $this->assertTrue($manualLock->shareKey);
        $this->assertSame(30, $discoveryLock->releaseAfter);
        $this->assertSame(30, $manualLock->releaseAfter);
        $this->assertSame(570, $discoveryLock->expiresAfter);
        $this->assertSame(570, $manualLock->expiresAfter);
        $this->assertGreaterThan(1, $manualJob->tries);
    }

    public function test_incomplete_attempt_keeps_run_running_preserves_started_at_and_releases_same_job(): void
    {
        $criteria = $this->criteria();
        $startedAt = now()->subMinutes(10);
        $run = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => $startedAt,
            'updated_at' => now()->subMinute(),
        ]);

        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldReceive('run')
            ->once()
            ->withArgs(function (
                $actualCriteria,
                $searchBudget,
                $actualRun,
                $contactBudget,
                $attemptStartedAt,
                $providerUnavailable = false,
                $attemptDeadline = null,
            ) use ($criteria, $run): bool {
                return $actualCriteria->is($criteria)
                    && $searchBudget === 3
                    && $actualRun->is($run)
                    && $contactBudget === 3
                    && is_float($attemptStartedAt)
                    && $attemptStartedAt <= microtime(true)
                    && $providerUnavailable === false
                    && $attemptDeadline instanceof DiscoveryExecutionDeadline
                    && abs($attemptDeadline->workDeadlineAt() - ($attemptStartedAt + 230)) < 0.01;
            })
            ->andReturn($this->pipelineResult(collectionComplete: false, snapshotDrained: true));
        app()->instance(DiscoveryPipelineService::class, $pipeline);

        $job = (new RunDiscoveryPipelineJob($criteria->id, $run->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(5);
        $fresh = $run->fresh();
        $this->assertSame('running', $fresh->status);
        $this->assertTrue($fresh->started_at->equalTo($startedAt));
        $this->assertTrue($fresh->updated_at->equalTo(now()));
        $this->assertNull($fresh->finished_at);
    }

    public function test_complete_attempt_is_the_only_path_that_marks_run_completed(): void
    {
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $this->bindPipeline($this->pipelineResult(collectionComplete: true, snapshotDrained: true));

        $job = (new RunDiscoveryPipelineJob($criteria->id, $run->id))->withFakeQueueInteractions();
        $job->handle();

        $job->assertNotReleased();
        $fresh = $run->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($fresh->error);
    }

    public function test_job_ignores_a_run_owned_by_another_criterion(): void
    {
        $criteria = $this->criteria();
        $otherCriteria = $this->criteria();
        $foreignRun = $this->discoveryRun($otherCriteria);

        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldNotReceive('run');
        app()->instance(DiscoveryPipelineService::class, $pipeline);

        (new RunDiscoveryPipelineJob($criteria->id, $foreignRun->id))->handle();

        $this->assertSame('pending', $foreignRun->fresh()->status);
    }

    public function test_job_ignores_a_manual_run_even_when_it_belongs_to_the_criterion(): void
    {
        $criteria = $this->criteria();
        $manualRun = $this->discoveryRun($criteria, ['type' => 'manual']);

        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldNotReceive('run');
        app()->instance(DiscoveryPipelineService::class, $pipeline);

        (new RunDiscoveryPipelineJob($criteria->id, $manualRun->id))->handle();

        $this->assertSame('pending', $manualRun->fresh()->status);
    }

    public function test_job_with_a_missing_explicit_run_never_falls_back_to_untracked_execution(): void
    {
        $criteria = $this->criteria();

        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldNotReceive('run');
        app()->instance(DiscoveryPipelineService::class, $pipeline);

        (new RunDiscoveryPipelineJob($criteria->id, 999_999))->handle();

        $this->assertSame(0, DiscoveryRun::whereKey(999_999)->count());
    }

    public function test_completion_does_not_overwrite_a_concurrent_terminal_state(): void
    {
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);

        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldReceive('run')->once()->andReturnUsing(function () use ($run): DiscoveryPipelineResult {
            DiscoveryRun::whereKey($run->id)->update([
                'status' => 'failed',
                'error' => 'Terminalisé en concurrence',
                'finished_at' => now(),
            ]);

            return $this->pipelineResult(collectionComplete: true, snapshotDrained: true);
        });
        app()->instance(DiscoveryPipelineService::class, $pipeline);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Terminalisé en concurrence', $fresh->error);
    }

    public function test_last_attempt_fails_explicitly_instead_of_releasing_again(): void
    {
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $this->bindPipeline($this->pipelineResult(collectionComplete: false, snapshotDrained: false));

        $job = (new RunDiscoveryPipelineJob($criteria->id, $run->id))->withFakeQueueInteractions();
        $job->job->attempts = 20;
        $job->handle();

        $job->assertNotReleased();
        $job->assertFailed();
        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('20 tentatives', $fresh->error);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_failed_hook_only_terminalizes_an_active_run(): void
    {
        $criteria = $this->criteria();
        $completed = $this->discoveryRun($criteria, [
            'status' => 'completed',
            'error' => null,
            'finished_at' => now()->subMinute(),
        ]);

        (new RunDiscoveryPipelineJob($criteria->id, $completed->id))
            ->failed(new RuntimeException('Erreur tardive'));

        $fresh = $completed->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNull($fresh->error);
    }

    public function test_completed_run_releases_an_orphaned_enrichment_claim(): void
    {
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria, ['status' => 'running']);
        $company = $this->claimedCompany($criteria, $run);
        $this->bindPipeline($this->pipelineResult(collectionComplete: true, snapshotDrained: true));

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNull($company->fresh()->enrichment_claim_run_id);
        $this->assertSame(Company::ENRICHMENT_HUNTER_FAILED, $company->fresh()->enrichment_status);
    }

    public function test_failed_hook_cannot_terminalize_a_run_owned_by_another_criterion(): void
    {
        $criteria = $this->criteria();
        $otherCriteria = $this->criteria();
        $foreignRun = $this->discoveryRun($otherCriteria, ['status' => 'running']);

        (new RunDiscoveryPipelineJob($criteria->id, $foreignRun->id))
            ->failed(new RuntimeException('Erreur étrangère'));

        $this->assertSame('running', $foreignRun->fresh()->status);
        $this->assertNull($foreignRun->fresh()->error);
    }

    public function test_failed_hook_hides_unexpected_exception_details_and_releases_claims(): void
    {
        $criteria = $this->criteria();
        $running = $this->discoveryRun($criteria, ['status' => 'running']);
        $company = $this->claimedCompany($criteria, $running, 'failed-run-claim.test');

        (new RunDiscoveryPipelineJob($criteria->id, $running->id))
            ->failed(new RuntimeException('SQLSTATE secret-host.internal prospect@example.test'));

        $fresh = $running->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(
            'La découverte a échoué après plusieurs tentatives. Consultez les journaux applicatifs puis relancez-la.',
            $fresh->error,
        );
        $this->assertStringNotContainsString('SQLSTATE', $fresh->error);
        $this->assertNotNull($fresh->finished_at);
        $this->assertNull($company->fresh()->enrichment_claim_run_id);
        $this->assertSame(Company::ENRICHMENT_HUNTER_FAILED, $company->fresh()->enrichment_status);
    }

    public function test_failed_hook_keeps_explicit_configuration_guidance_visible(): void
    {
        $criteria = $this->criteria();
        $running = $this->discoveryRun($criteria, ['status' => 'running']);
        $message = 'La clé SerpAPI n’est pas configurée.';

        (new RunDiscoveryPipelineJob($criteria->id, $running->id))
            ->failed(new DiscoveryConfigurationException($message));

        $this->assertSame($message, $running->fresh()->error);
    }

    public function test_inactive_criteria_fails_its_active_run_without_calling_pipeline(): void
    {
        $criteria = $this->criteria(['is_active' => false]);
        $run = $this->discoveryRun($criteria);

        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldNotReceive('run');
        app()->instance(DiscoveryPipelineService::class, $pipeline);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('Critère introuvable ou inactif', $fresh->error);
    }

    public function test_running_staleness_uses_updated_at_as_heartbeat(): void
    {
        $criteria = $this->criteria();
        $freshHeartbeat = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subHour(),
            'updated_at' => now()->subSeconds(10),
        ]);
        $staleHeartbeat = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subSeconds(10),
            'updated_at' => now()->subSeconds(700),
        ]);

        $this->assertFalse($freshHeartbeat->fresh()->isStale());
        $this->assertTrue($staleHeartbeat->fresh()->isStale());
    }

    public function test_pending_staleness_allows_a_full_daily_queue_latency_window(): void
    {
        $criteria = $this->criteria();
        $fresh = $this->discoveryRun($criteria);
        $stale = $this->discoveryRun($criteria);
        DB::table('discovery_runs')->where('id', $fresh->id)->update([
            'created_at' => now()->subHours(23),
        ]);
        DB::table('discovery_runs')->where('id', $stale->id)->update([
            'created_at' => now()->subHours(25),
        ]);

        $this->assertFalse($fresh->fresh()->isStale());
        $this->assertTrue($stale->fresh()->isStale());
    }

    public function test_terminalizer_repeats_heartbeat_cutoff_in_atomic_update(): void
    {
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subHour(),
            'updated_at' => now()->subSeconds(700),
        ]);

        $heartbeatRefreshed = false;
        DB::listen(function (QueryExecuted $query) use ($run, &$heartbeatRefreshed): void {
            if ($heartbeatRefreshed
                || ! str_contains(strtolower($query->sql), 'select')
                || ! str_contains(strtolower($query->sql), 'discovery_runs')
                || ! in_array('running', $query->bindings, true)) {
                return;
            }

            $heartbeatRefreshed = true;
            DB::table('discovery_runs')->where('id', $run->id)->update(['updated_at' => now()]);
        });

        $this->artisan(DiscoveryTerminalizeStale::class)->assertSuccessful();

        $this->assertTrue($heartbeatRefreshed);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_terminalizer_flips_only_runs_whose_heartbeat_is_stale(): void
    {
        $criteria = $this->criteria();
        $stale = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subHour(),
            'updated_at' => now()->subSeconds(700),
        ]);
        $fresh = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subHour(),
            'updated_at' => now()->subSeconds(10),
        ]);

        $this->artisan(DiscoveryTerminalizeStale::class)->assertSuccessful();

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('running', $fresh->fresh()->status);
    }

    public function test_terminalizer_releases_only_the_claim_owned_by_the_run_it_flips(): void
    {
        $criteria = $this->criteria();
        $stale = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subHour(),
            'updated_at' => now()->subSeconds(700),
        ]);
        $fresh = $this->discoveryRun($criteria, [
            'status' => 'running',
            'started_at' => now()->subHour(),
            'updated_at' => now()->subSeconds(10),
        ]);
        $staleClaim = $this->claimedCompany($criteria, $stale, 'stale-claim.test');
        $freshClaim = $this->claimedCompany($criteria, $fresh, 'fresh-claim.test');

        $this->artisan(DiscoveryTerminalizeStale::class)->assertSuccessful();

        $this->assertNull($staleClaim->fresh()->enrichment_claim_run_id);
        $this->assertSame(Company::ENRICHMENT_HUNTER_FAILED, $staleClaim->fresh()->enrichment_status);
        $this->assertSame($fresh->id, (int) $freshClaim->fresh()->enrichment_claim_run_id);
        $this->assertSame(Company::ENRICHMENT_ENRICHING, $freshClaim->fresh()->enrichment_status);
    }

    public function test_prospect_discover_dispatches_to_queue_instead_of_running_synchronously(): void
    {
        Queue::fake();
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria, ['status' => 'pending']);

        $quota = Mockery::mock(DiscoveryQuotaService::class);
        $quota->shouldReceive('reserveRun')->once()->with(Mockery::on(fn ($value) => $value->is($criteria)))->andReturn($run);
        app()->instance(DiscoveryQuotaService::class, $quota);

        $this->artisan('prospect:discover', [
            '--criteria' => $criteria->id,
            '--max' => 2,
        ])
            ->assertSuccessful();

        Queue::assertPushed(RunDiscoveryPipelineJob::class, 1);
        $fresh = $run->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(2, (int) $fresh->credits_reserved);
        $this->assertSame(2, (int) $fresh->searches_reserved);
    }

    public function test_prospect_discover_terminalizes_its_pending_run_when_queue_dispatch_fails(): void
    {
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria, ['status' => 'pending']);

        $quota = Mockery::mock(DiscoveryQuotaService::class);
        $quota->shouldReceive('reserveRun')->once()->andReturn($run);
        app()->instance(DiscoveryQuotaService::class, $quota);

        $dispatcher = Mockery::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(RunDiscoveryPipelineJob::class))
            ->andThrow(new RuntimeException('SQLSTATE queue-secret.internal prospect@example.test'));
        app()->instance(BusDispatcher::class, $dispatcher);

        $this->artisan('prospect:discover', ['--criteria' => $criteria->id])
            ->assertFailed();

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame(
            'La découverte n’a pas pu être mise en file. Réessayez dans quelques instants.',
            $fresh->error,
        );
        $this->assertStringNotContainsString('SQLSTATE', $fresh->error);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_every_dispatch_failure_path_persists_only_the_shared_safe_message(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = [
            file_get_contents($root.'/app/Http/Controllers/Backend/ProspectCriteriaController.php'),
            file_get_contents($root.'/app/Console/Commands/ProspectAutoDiscover.php'),
            file_get_contents($root.'/app/Console/Commands/ProspectDiscover.php'),
        ];

        foreach ($sources as $source) {
            $this->assertStringContainsString('DiscoveryRun::failPendingDispatch', $source);
            $this->assertDoesNotMatchRegularExpression(
                "/'error'\s*=>\s*Str::limit\([^\n]*getMessage\(\)/",
                $source,
            );
        }
    }

    public function test_dispatch_failure_cas_cannot_overwrite_a_started_or_completed_run(): void
    {
        $criteria = $this->criteria();
        $pending = $this->discoveryRun($criteria, ['status' => 'pending']);
        $running = $this->discoveryRun($criteria, ['status' => 'running']);
        $completed = $this->discoveryRun($criteria, [
            'status' => 'completed',
            'finished_at' => now()->subMinute(),
        ]);

        $this->assertTrue(DiscoveryRun::failPendingDispatch($pending->id, $criteria->id));
        $this->assertFalse(DiscoveryRun::failPendingDispatch($running->id, $criteria->id));
        $this->assertFalse(DiscoveryRun::failPendingDispatch($completed->id, $criteria->id));

        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertSame(DiscoveryRun::DISPATCH_FAILURE_MESSAGE, $pending->fresh()->error);
        $this->assertSame('running', $running->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
    }

    public function test_prospect_discover_rejects_invalid_max_before_reserving_a_run(): void
    {
        $criteria = $this->criteria();
        $quota = Mockery::mock(DiscoveryQuotaService::class);
        $quota->shouldNotReceive('reserveRun');
        app()->instance(DiscoveryQuotaService::class, $quota);

        $this->artisan('prospect:discover', [
            '--criteria' => $criteria->id,
            '--max' => '0',
        ])->assertFailed();
    }

    private function bindPipeline(DiscoveryPipelineResult $result): void
    {
        $pipeline = Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldReceive('run')->once()->andReturn($result);
        app()->instance(DiscoveryPipelineService::class, $pipeline);
    }

    private function pipelineResult(bool $collectionComplete, bool $snapshotDrained): DiscoveryPipelineResult
    {
        return new DiscoveryPipelineResult(
            stats: [
                'companies' => 0,
                'contacts' => 0,
                'skipped' => 0,
                'low_score' => 0,
                'new' => 0,
                'contacts_consumed' => 0,
                'excluded' => 0,
            ],
            collectionComplete: $collectionComplete,
            snapshotDrained: $snapshotDrained,
        );
    }

    private function criteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name' => 'Critère test',
            'daily_limit' => 3,
            'contact_limit' => 3,
            'is_active' => true,
        ], $overrides));
    }

    private function discoveryRun(ProspectCriteria $criteria, array $overrides = []): DiscoveryRun
    {
        $run = DiscoveryRun::create(array_merge([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'pending',
            'credits_reserved' => 3,
            'searches_reserved' => 3,
            'searches_consumed' => 0,
            'consumed' => 0,
            'contact_credits_reserved' => 3,
            'contact_consumed' => 0,
            'successful_enrichments_target' => 3,
        ], $overrides));

        if (array_key_exists('updated_at', $overrides)) {
            DB::table('discovery_runs')->where('id', $run->id)->update([
                'updated_at' => $overrides['updated_at'],
            ]);
        }

        return $run->fresh();
    }

    private function claimedCompany(
        ProspectCriteria $criteria,
        DiscoveryRun $run,
        string $domain = 'orphaned-claim.test',
    ): Company {
        $company = new Company;
        $company->forceFill([
            'criteria_id' => $criteria->id,
            'name' => $domain,
            'domain' => $domain,
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => true,
            'enrichment_status' => Company::ENRICHMENT_ENRICHING,
            'enrichment_claim_run_id' => $run->id,
        ])->save();

        return $company;
    }

    private function createTables(): void
    {
        Schema::create('prospect_criteria', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('contact_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('prospect_criteria_id');
            $table->string('type')->default('discovery');
            $table->string('status')->default('pending');
            $table->unsignedInteger('companies_count')->default(0);
            $table->unsignedInteger('new_companies_count')->default(0);
            $table->unsignedInteger('contacts_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('low_score_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->unsignedInteger('credits_reserved')->default(0);
            $table->unsignedInteger('searches_reserved')->nullable();
            $table->unsignedInteger('searches_consumed')->default(0);
            $table->unsignedInteger('consumed')->default(0);
            $table->unsignedInteger('contact_credits_reserved')->default(0);
            $table->unsignedInteger('contact_consumed')->default(0);
            $table->unsignedInteger('successful_enrichments_target')->default(0);
            $table->unsignedInteger('successful_enrichments')->default(0);
            $table->uuid('enrichment_batch_id')->nullable();
            $table->date('quota_date')->nullable();
            $table->unsignedBigInteger('package_assignment_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->text('candidates_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('criteria_id')->nullable();
            $table->string('name');
            $table->string('domain')->nullable()->unique();
            $table->string('relationship')->default('prospect');
            $table->string('source')->default('manual');
            $table->string('qualification_status')->default('pending');
            $table->boolean('is_active')->default(true);
            $table->string('enrichment_status')->nullable();
            $table->timestamp('enrichment_attempted_at')->nullable();
            $table->unsignedBigInteger('enrichment_claim_run_id')->nullable();
            $table->timestamps();
        });
    }
}
