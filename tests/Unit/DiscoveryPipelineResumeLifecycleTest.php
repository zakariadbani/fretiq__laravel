<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\CompanyEnrichmentService;
use App\Services\Discovery\DiscoveredContactImportService;
use App\Services\Discovery\DiscoveryCollectionResult;
use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Discovery\HomepageSnapshotService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Prospecting\HunterVerificationStatusNormalizer;
use App\Services\Quota\DiscoveryQuotaService;
use App\Services\Scoring\LeadScoringService;
use App\Services\Settings\SettingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class DiscoveryPipelineResumeLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.serpapi.driver' => 'local',
        ]);
        DB::purge();
        $this->createTables();

        app(SettingService::class)->clearCache();
        Cache::put('app_settings', [
            'decouverte.discovery_engines' => ['google'],
            'decouverte.auto_scoring' => true,
            'decouverte.auto_enrich' => false,
            'decouverte.fetch_homepage' => true,
            'decouverte.run_time_budget' => 30,
        ], 60);
    }

    public function test_partial_candidate_attempt_resumes_without_duplicates_and_only_then_completes(): void
    {
        $criteria = (new ProspectCriteria)->forceFill([
            'id' => 42,
            'name' => 'Reprise déterministe',
            'daily_limit' => 3,
            'auto_enrich' => false,
        ]);
        $criteria->exists = true;

        $run = DiscoveryRun::create([
            'prospect_criteria_id' => 42,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 3,
            'searches_reserved' => 3,
            'searches_consumed' => 0,
            'consumed' => 0,
        ]);

        $homepage = Mockery::mock(HomepageSnapshotService::class);
        $homepage->shouldReceive('prefetch')->twice()->andReturnTrue();
        $homepage->shouldReceive('cachedExcerpt')->times(6)->andReturnNull();

        $scoreCalls = 0;
        $scoring = Mockery::mock(LeadScoringService::class);
        $scoring->shouldReceive('score')->times(6)->andReturnUsing(
            function () use (&$scoreCalls): array {
                $scoreCalls++;

                // The first candidate starts with a valid one-second timeout, then
                // crosses the work deadline. The pipeline finishes that candidate
                // atomically and pauses before candidate #2.
                if ($scoreCalls === 1) {
                    usleep(1_600_000);
                }

                return ['score' => 80, 'explanation' => 'Prospect test', 'exclude' => false];
            }
        );

        $hunter = Mockery::mock(HunterEnrichmentService::class);
        $hunter->shouldNotReceive('domainSearch');
        $contacts = new DiscoveredContactImportService(new HunterVerificationStatusNormalizer);

        $pipeline = new DiscoveryPipelineService(
            new CompanyDiscoveryService,
            $hunter,
            $scoring,
            $contacts,
            $homepage,
        );

        $partial = $pipeline->run(
            $criteria,
            3,
            $run,
            0,
            microtime(true) - 18.5,
        );

        $run->refresh();
        $this->assertFalse($partial->isComplete());
        $this->assertTrue($partial->collectionComplete);
        $this->assertFalse($partial->snapshotDrained);
        $this->assertSame(1, (int) $run->consumed);
        $this->assertSame(1, Company::count());

        $completed = $pipeline->run($criteria, 3, $run->fresh(), 0);

        $run->refresh();
        $this->assertTrue($completed->isComplete());
        $this->assertSame(6, (int) $run->consumed);
        $this->assertSame(6, (int) $run->companies_count);
        $this->assertSame(6, Company::count(), 'Resuming must update/insert each domain exactly once.');
        $this->assertCount(6, $run->candidates_snapshot);
    }

    public function test_paid_scoring_is_not_replayed_when_deadline_expires_before_enrichment(): void
    {
        Cache::put('app_settings', [
            'decouverte.auto_scoring' => true,
            'decouverte.auto_enrich' => true,
            'decouverte.fetch_homepage' => true,
            'decouverte.run_time_budget' => 30,
        ], 60);

        $criteria = (new ProspectCriteria)->forceFill([
            'id' => 43,
            'name' => 'Reprise après scoring',
            'daily_limit' => 1,
            'auto_enrich' => true,
            'min_score_enrich' => 50,
            'countries' => ['MA'],
        ]);
        $criteria->exists = true;

        $candidate = [
            'domain' => 'scoring-checkpoint.test',
            'title' => 'Scoring checkpoint',
            'url' => 'https://scoring-checkpoint.test',
        ];
        $run = DiscoveryRun::create([
            'prospect_criteria_id' => 43,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 1,
            'searches_reserved' => 1,
            'searches_consumed' => 1,
            'consumed' => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed' => 0,
            'successful_enrichments_target' => 1,
            'candidates_snapshot' => [$candidate],
        ]);

        $discovery = Mockery::mock(CompanyDiscoveryService::class);
        $discovery->shouldReceive('discoverForRun')
            ->twice()
            ->andReturnUsing(
                fn (ProspectCriteria $criteria, DiscoveryRun $attemptRun): DiscoveryCollectionResult => new DiscoveryCollectionResult($attemptRun->fresh()->candidates_snapshot, true)
            );

        $homepage = Mockery::mock(HomepageSnapshotService::class);
        $homepage->shouldReceive('prefetch')->twice()->andReturnTrue();
        $homepage->shouldReceive('cachedExcerpt')->once()->andReturnNull();

        $scoreCalls = 0;
        $scoring = Mockery::mock(LeadScoringService::class);
        $scoring->shouldReceive('score')->andReturnUsing(
            function () use (&$scoreCalls): array {
                $scoreCalls++;
                usleep(1_800_000);

                return ['score' => 80, 'explanation' => 'Score payant', 'exclude' => false];
            }
        );

        $quota = Mockery::mock(DiscoveryQuotaService::class);
        $quota->shouldReceive('claimAutomaticEnrichment')
            ->once()
            ->andReturnUsing(
                fn (int $companyId): Company => Company::withRejected()->findOrFail($companyId)
            );

        $enrichment = Mockery::mock(CompanyEnrichmentService::class);
        $enrichment->shouldReceive('enrichClaimed')
            ->once()
            ->andReturnUsing(function (Company $company, DiscoveryRun $ownedRun, ?int $timeout, ?string $fallbackCountry): array {
                $this->assertNull($company->country);
                $this->assertSame('MA', $fallbackCountry);
                $company->forceFill(['country' => 'FR'])->save();

                return ['outcome' => 'hunter_empty', 'contacts_count' => 0];
            });

        $pipeline = new DiscoveryPipelineService(
            $discovery,
            Mockery::mock(HunterEnrichmentService::class),
            $scoring,
            new DiscoveredContactImportService(new HunterVerificationStatusNormalizer),
            $homepage,
            $quota,
            $enrichment,
        );

        $partial = $pipeline->run(
            $criteria,
            1,
            $run,
            1,
            microtime(true) - 18.5,
        );

        $this->assertFalse($partial->isComplete());
        $this->assertSame(0, (int) $run->fresh()->consumed);
        $this->assertNull(Company::firstOrFail()->country);

        $completed = $pipeline->run($criteria, 1, $run->fresh(), 1);

        $this->assertTrue($completed->isComplete());
        $this->assertSame(1, $scoreCalls, 'The paid scoring result must survive continuation.');
        $this->assertSame(1, (int) $run->fresh()->consumed);
        $this->assertSame(1, (int) $run->fresh()->new_companies_count, 'A company inserted before pausing must still be counted as new after continuation.');
        $this->assertSame('FR', Company::firstOrFail()->country);
    }

    private function createTables(): void
    {
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
            $table->text('candidates_snapshot')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('criteria_id')->nullable();
            $table->string('domain')->unique();
            $table->string('name');
            $table->string('sector')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('relationship')->nullable();
            $table->string('source')->nullable();
            $table->json('enrichment_data')->nullable();
            $table->integer('ai_score')->nullable();
            $table->text('ai_explanation')->nullable();
            $table->string('qualification_status')->nullable();
            $table->boolean('is_active')->nullable();
            $table->string('discovery_query')->nullable();
            $table->string('phone')->nullable();
            $table->string('enrichment_status', 32)->nullable();
            $table->timestamps();
        });
    }
}
