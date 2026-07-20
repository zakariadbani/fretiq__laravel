<?php

namespace Tests\Feature\Backend;

use App\Exceptions\QuotaLockUnavailableException;
use App\Jobs\RunDiscoveryPipelineJob;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\Package;
use App\Models\PackageAssignment;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Discovery\DiscoveryExecutionDeadline;
use App\Services\Discovery\DiscoveryPipelineResult;
use App\Services\Discovery\DiscoveryPipelineService;
use App\Services\Discovery\HunterEnrichmentService;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklogFirstDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.serpapi.driver' => 'local',
            'services.hunter.driver' => 'local',
        ]);
        Setting::set('decouverte.auto_scoring', false);
        Setting::set('decouverte.auto_enrich', true);
    }

    public function test_backlog_uses_hunter_attempts_before_new_candidates_and_remaining_attempts_flow_to_fresh_companies(): void
    {
        $criteria = $this->criteria();
        $backlog = $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 1, contacts: 2);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, (int) $run->contact_consumed);
        $this->assertTrue($backlog->fresh()->contacts()->exists());
        $this->assertNull($backlog->fresh()->enrichment_claim_run_id);
        $this->assertGreaterThan(0, (int) $run->contacts_count);
        $this->assertSame(2, Company::where('enrichment_status', Company::ENRICHMENT_ENRICHED)->count());
        $this->assertGreaterThan(0, Company::where('enrichment_status', Company::ENRICHMENT_SKIPPED_BUDGET)->count());
        $this->assertSame(0, DiscoveryRun::where('type', 'manual')->count());
    }

    public function test_discovery_continues_when_backlog_consumes_all_hunter_capacity(): void
    {
        $criteria = $this->criteria();
        $backlog = $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 1, contacts: 1);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $run->refresh();
        $this->assertSame(1, (int) $run->contact_consumed);
        $this->assertTrue($backlog->fresh()->contacts()->exists());
        $this->assertGreaterThan(0, (int) $run->companies_count);
        $this->assertGreaterThan(1, Company::count());
        $this->assertGreaterThan(0, Company::where('enrichment_status', Company::ENRICHMENT_SKIPPED_BUDGET)->count());
    }

    public function test_provider_failure_calls_hunter_once_then_discovery_marks_fresh_candidates_deferred(): void
    {
        $criteria = $this->criteria();
        $backlog = $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 1, contacts: 5);

        $hunter = new class extends HunterEnrichmentService
        {
            public int $calls = 0;

            public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null): array
            {
                $this->calls++;

                return ['status' => 'provider_failed', 'data' => null];
            }
        };
        $this->app->instance(HunterEnrichmentService::class, $hunter);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $this->assertSame(1, $hunter->calls);
        $this->assertSame(Company::ENRICHMENT_HUNTER_FAILED, $backlog->fresh()->enrichment_status);
        $this->assertTrue((bool) $run->fresh()->hunter_circuit_open);
        $this->assertGreaterThan(0, Company::where(
            'enrichment_status',
            Company::ENRICHMENT_SKIPPED_PROVIDER_UNAVAILABLE,
        )->count());
        $this->assertGreaterThan(0, (int) $run->fresh()->companies_count);
    }

    public function test_contact_only_run_processes_backlog_without_loading_discovery_fixtures(): void
    {
        $criteria = $this->criteria();
        $backlog = $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 0, contacts: 1);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) $run->contact_consumed);
        $this->assertTrue($backlog->fresh()->contacts()->exists());
        $this->assertSame(1, Company::count());
        $this->assertSame(0, (int) $run->companies_count);
        $this->assertSame([], $run->candidates_snapshot ?? []);
    }

    public function test_backlog_honours_package_capacity_reduced_after_parent_reservation(): void
    {
        $criteria = $this->criteria();
        $first = $this->backlogCompany($criteria);
        $second = $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 0, contacts: 5);

        $package = Package::create([
            'name' => 'Hunter réduit',
            'daily_credits' => null,
            'daily_contact_credits' => 1,
            'monthly_credits' => null,
            'monthly_contact_credits' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create([
            'package_id' => $package->id,
            'assigned_by' => null,
        ]);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) $run->contact_consumed);
        $this->assertSame(1, collect([$first, $second])
            ->filter(fn (Company $company) => $company->fresh()->contacts()->exists())
            ->count());
    }

    public function test_package_downgrade_subtracts_attempts_already_consumed_by_parent(): void
    {
        $criteria = $this->criteria();
        $this->backlogCompany($criteria);
        $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 0, contacts: 5);
        $run->forceFill(['contact_consumed' => 1])->save();

        $package = Package::create([
            'name' => 'Hunter partiellement consommé',
            'daily_credits' => null,
            'daily_contact_credits' => 2,
            'monthly_credits' => null,
            'monthly_contact_credits' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create(['package_id' => $package->id, 'assigned_by' => null]);

        $hunter = new class extends HunterEnrichmentService
        {
            public int $calls = 0;

            public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null): array
            {
                $this->calls++;

                return ['status' => 'empty', 'data' => null];
            }
        };
        $this->app->instance(HunterEnrichmentService::class, $hunter);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $this->assertSame(1, $hunter->calls);
        $this->assertSame(2, (int) $run->fresh()->contact_consumed);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_zero_runtime_search_capacity_forfeits_unusable_reservation_and_completes(): void
    {
        $criteria = $this->criteria();
        $criteria->forceFill(['auto_enrich' => false])->save();
        $run = $this->discoveryRun($criteria, searches: 5, contacts: 0);
        $run->forceFill(['searches_consumed' => 1])->save();

        $package = Package::create([
            'name' => 'SerpAPI déjà consommé',
            'daily_credits' => 1,
            'daily_contact_credits' => null,
            'monthly_credits' => null,
            'monthly_contact_credits' => null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        PackageAssignment::create(['package_id' => $package->id, 'assigned_by' => null]);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) $run->searches_reserved);
        $this->assertSame(1, (int) $run->searches_consumed);
        $this->assertSame([], $run->candidates_snapshot ?? []);
    }

    public function test_unrelated_same_criteria_failure_does_not_open_parent_circuit(): void
    {
        $criteria = $this->criteria();
        $this->backlogCompany($criteria);
        Company::create([
            'criteria_id' => $criteria->id,
            'name' => 'Unrelated failure',
            'domain' => 'unrelated-failure.example.test',
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => false,
            'enrichment_status' => Company::ENRICHMENT_HUNTER_FAILED,
        ])->forceFill(['enrichment_attempted_at' => now()])->save();
        $run = $this->discoveryRun($criteria, searches: 0, contacts: 1);

        $hunter = new class extends HunterEnrichmentService
        {
            public int $calls = 0;

            public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null): array
            {
                $this->calls++;

                return ['status' => 'empty', 'data' => null];
            }
        };
        $this->app->instance(HunterEnrichmentService::class, $hunter);

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $this->assertSame(1, $hunter->calls);
        $this->assertFalse((bool) $run->fresh()->hunter_circuit_open);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_contact_only_run_remains_resumable_when_backlog_admission_temporarily_fails(): void
    {
        $criteria = $this->criteria();
        $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 0, contacts: 1);

        $this->app->instance(DiscoveryQuotaService::class, new class extends DiscoveryQuotaService
        {
            public function claimAutomaticEnrichment(int $companyId, DiscoveryRun $run): Company
            {
                throw new QuotaLockUnavailableException;
            }
        });

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertNull($run->finished_at);
        $this->assertSame(0, (int) $run->contact_consumed);
    }

    public function test_retry_skips_a_claim_already_debited_by_the_same_parent(): void
    {
        $criteria = $this->criteria();
        $company = $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 0, contacts: 2);
        $run->forceFill(['contact_consumed' => 1])->save();
        $company->forceFill([
            'enrichment_status' => Company::ENRICHMENT_ENRICHING,
            'enrichment_attempted_at' => now(),
            'enrichment_claim_run_id' => $run->id,
        ])->save();

        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $run->refresh();
        $company->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, (int) $run->contact_consumed);
        $this->assertNull($company->enrichment_claim_run_id);
        $this->assertSame(Company::ENRICHMENT_HUNTER_FAILED, $company->enrichment_status);
        $this->assertFalse($company->contacts()->exists());
    }

    public function test_active_foreign_claim_is_not_actionable_backlog_for_parent(): void
    {
        $criteria = $this->criteria();
        $company = $this->backlogCompany($criteria);
        $manualRun = DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'manual',
            'company_id' => $company->id,
            'status' => 'running',
            'credits_reserved' => 0,
            'consumed' => 0,
            'contact_credits_reserved' => 1,
            'contact_consumed' => 1,
            'quota_date' => now()->toDateString(),
            'started_at' => now(),
        ]);
        $company->forceFill([
            'enrichment_status' => Company::ENRICHMENT_ENRICHING,
            'enrichment_attempted_at' => now(),
            'enrichment_claim_run_id' => $manualRun->id,
        ])->save();
        $parent = $this->discoveryRun($criteria, searches: 0, contacts: 2);

        (new RunDiscoveryPipelineJob($criteria->id, $parent->id))->handle();

        $this->assertSame('completed', $parent->fresh()->status);
        $this->assertSame(0, (int) $parent->fresh()->contact_consumed);
        $this->assertSame($manualRun->id, (int) $company->fresh()->enrichment_claim_run_id);
        $this->assertSame('running', $manualRun->fresh()->status);
    }

    public function test_discovery_receives_a_fresh_time_window_after_backlog_finishes(): void
    {
        $criteria = $this->criteria();
        $this->backlogCompany($criteria);
        $run = $this->discoveryRun($criteria, searches: 0, contacts: 1);
        $hunter = new class extends HunterEnrichmentService
        {
            public float $returnedAt = 0.0;

            public function domainSearchResult(string $domain, int $limit = 10, ?int $timeoutSeconds = null): array
            {
                usleep(20_000);
                $this->returnedAt = microtime(true);

                return ['status' => 'empty', 'data' => null];
            }
        };
        $this->app->instance(HunterEnrichmentService::class, $hunter);

        $discoveryStartedAt = 0.0;
        $discoveryDeadline = null;
        $pipeline = \Mockery::mock(DiscoveryPipelineService::class);
        $pipeline->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (
                ProspectCriteria $receivedCriteria,
                ?int $searchCap,
                ?DiscoveryRun $receivedRun,
                ?int $contactCap,
                ?float $startedAt,
                bool $providerUnavailable = false,
                ?DiscoveryExecutionDeadline $deadline = null,
            ) use (&$discoveryStartedAt, &$discoveryDeadline): DiscoveryPipelineResult {
                $discoveryStartedAt = (float) $startedAt;
                $discoveryDeadline = $deadline;

                return new DiscoveryPipelineResult([
                    'companies' => 0,
                    'contacts' => 0,
                    'skipped' => 0,
                    'low_score' => 0,
                    'new' => 0,
                    'contacts_consumed' => 0,
                    'excluded' => 0,
                ], true, true);
            });
        $this->app->instance(DiscoveryPipelineService::class, $pipeline);

        $handleCalledAt = microtime(true);
        (new RunDiscoveryPipelineJob($criteria->id, $run->id))->handle();

        $this->assertGreaterThanOrEqual($handleCalledAt, $discoveryStartedAt);
        $this->assertGreaterThanOrEqual($hunter->returnedAt, $discoveryStartedAt);
        $this->assertInstanceOf(DiscoveryExecutionDeadline::class, $discoveryDeadline);
        $this->assertEqualsWithDelta($discoveryStartedAt + 230, $discoveryDeadline->workDeadlineAt(), 0.01);
        $this->assertSame('completed', $run->fresh()->status);
    }

    private function criteria(): ProspectCriteria
    {
        return ProspectCriteria::create([
            'name' => 'Backlog '.uniqid(),
            'daily_limit' => 1,
            'contact_limit' => null,
            'auto_enrich' => true,
            'is_active' => true,
        ]);
    }

    private function backlogCompany(ProspectCriteria $criteria): Company
    {
        return Company::create([
            'criteria_id' => $criteria->id,
            'name' => 'Backlog '.uniqid(),
            'domain' => uniqid('backlog-').'.example.test',
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => true,
            'enrichment_status' => Company::ENRICHMENT_SKIPPED_BUDGET,
        ]);
    }

    private function discoveryRun(ProspectCriteria $criteria, int $searches, int $contacts): DiscoveryRun
    {
        return DiscoveryRun::create([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'pending',
            'credits_reserved' => $searches,
            'searches_reserved' => $searches,
            'searches_consumed' => 0,
            'consumed' => 0,
            'contact_credits_reserved' => $contacts,
            'contact_consumed' => 0,
            'successful_enrichments_target' => $contacts,
            'successful_enrichments' => 0,
            'quota_date' => now()->toDateString(),
        ]);
    }
}
