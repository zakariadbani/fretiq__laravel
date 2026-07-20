<?php

namespace Tests\Unit;

use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\CompanyDiscoveryService;
use App\Services\Discovery\ContactUpsertService;
use App\Services\Discovery\DiscoveryCollectionResult;
use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Discovery\HomepageSnapshotService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Scoring\LeadScoringService;
use App\Services\Settings\SettingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DiscoveryPipelineInterruptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        app(SettingService::class)->clearCache();
        Cache::put('app_settings', [
            'decouverte.run_time_budget' => 30,
            'decouverte.auto_scoring' => true,
            'decouverte.auto_enrich' => false,
        ], 60);
    }

    public function test_expired_attempt_stops_before_prefetch_or_candidate_processing(): void
    {
        $criteria = $this->criteria();
        $candidate = ['domain' => 'reprenable.test', 'url' => 'https://reprenable.test'];

        $discovery = Mockery::mock(CompanyDiscoveryService::class);
        $discovery->shouldReceive('discover')->once()->andReturn([$candidate]);

        $homepage = Mockery::mock(HomepageSnapshotService::class);
        $homepage->shouldNotReceive('prefetch');
        $homepage->shouldNotReceive('cachedExcerpt');

        $scoring = Mockery::mock(LeadScoringService::class);
        $scoring->shouldNotReceive('score');

        $result = $this->pipeline($discovery, $homepage, $scoring)->run(
            $criteria,
            1,
            null,
            null,
            microtime(true) - 30,
        );

        $this->assertTrue($result->collectionComplete);
        $this->assertFalse($result->snapshotDrained);
        $this->assertTrue($result->needsContinuation);
        $this->assertSame(0, $result['companies']);
    }

    public function test_incomplete_collection_is_never_reported_complete_even_with_an_empty_snapshot(): void
    {
        $criteria = $this->criteria();
        $run = new DiscoveryRun([
            'type' => 'discovery',
            'status' => 'running',
            'consumed' => 0,
        ]);

        $discovery = Mockery::mock(CompanyDiscoveryService::class);
        $discovery->shouldReceive('discoverForRun')
            ->once()
            ->andReturn(new DiscoveryCollectionResult([], false));

        $homepage = Mockery::mock(HomepageSnapshotService::class);
        $homepage->shouldNotReceive('prefetch');
        $homepage->shouldNotReceive('cachedExcerpt');

        $scoring = Mockery::mock(LeadScoringService::class);
        $scoring->shouldNotReceive('score');

        $result = $this->pipeline($discovery, $homepage, $scoring)
            ->run($criteria, 1, $run);

        $this->assertFalse($result->collectionComplete);
        $this->assertTrue($result->snapshotDrained);
        $this->assertTrue($result->needsContinuation);
    }

    public function test_interrupted_homepage_batch_leaves_the_candidate_for_the_next_attempt(): void
    {
        $criteria = $this->criteria();
        $candidate = ['domain' => 'homepage.test', 'url' => 'https://homepage.test'];

        $discovery = Mockery::mock(CompanyDiscoveryService::class);
        $discovery->shouldReceive('discover')->once()->andReturn([$candidate]);

        $homepage = Mockery::mock(HomepageSnapshotService::class);
        $homepage->shouldReceive('prefetch')
            ->once()
            ->with([$candidate['domain']], Mockery::type('float'))
            ->andReturnFalse();
        $homepage->shouldNotReceive('cachedExcerpt');

        $scoring = Mockery::mock(LeadScoringService::class);
        $scoring->shouldNotReceive('score');

        $result = $this->pipeline($discovery, $homepage, $scoring)
            ->run($criteria, 1);

        $this->assertTrue($result->collectionComplete);
        $this->assertFalse($result->snapshotDrained);
        $this->assertTrue($result->needsContinuation);
    }

    private function criteria(): ProspectCriteria
    {
        return new ProspectCriteria([
            'name' => 'Test interruption',
            'daily_limit' => 1,
            'auto_enrich' => false,
        ]);
    }

    private function pipeline(
        CompanyDiscoveryService $discovery,
        HomepageSnapshotService $homepage,
        LeadScoringService $scoring,
    ): DiscoveryPipelineService {
        return new DiscoveryPipelineService(
            $discovery,
            Mockery::mock(HunterEnrichmentService::class),
            $scoring,
            Mockery::mock(ContactUpsertService::class),
            $homepage,
        );
    }
}
