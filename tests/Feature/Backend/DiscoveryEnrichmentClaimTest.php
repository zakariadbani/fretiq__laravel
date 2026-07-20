<?php

namespace Tests\Feature\Backend;

use App\Exceptions\CriteriaCompanyNoLongerEligibleException;
use App\Exceptions\EnrichmentInFlightException;
use App\Exceptions\QuotaExhaustedException;
use App\Models\Company;
use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Models\Setting;
use App\Services\Quota\DiscoveryQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DiscoveryEnrichmentClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criteria(array $overrides = []): ProspectCriteria
    {
        return ProspectCriteria::create(array_merge([
            'name' => 'Claim criteria '.uniqid(),
            'daily_limit' => 5,
            'contact_limit' => null,
            'auto_enrich' => true,
            'min_score_enrich' => 50,
            'is_active' => true,
        ], $overrides));
    }

    private function discoveryRun(ProspectCriteria $criteria, array $overrides = []): DiscoveryRun
    {
        return DiscoveryRun::create(array_merge([
            'prospect_criteria_id' => $criteria->id,
            'type' => 'discovery',
            'status' => 'running',
            'credits_reserved' => 5,
            'searches_reserved' => 5,
            'contact_credits_reserved' => 20,
            'contact_consumed' => 0,
            'successful_enrichments_target' => 20,
            'successful_enrichments' => 0,
            'quota_date' => now()->toDateString(),
            'started_at' => now(),
        ], $overrides));
    }

    private function company(ProspectCriteria $criteria, array $overrides = []): Company
    {
        $claimKeys = array_flip(['enrichment_attempted_at', 'enrichment_claim_run_id']);
        $claimAttributes = array_intersect_key($overrides, $claimKeys);

        $company = Company::create(array_merge([
            'criteria_id' => $criteria->id,
            'name' => 'Claim Company '.uniqid(),
            'domain' => 'claim-'.uniqid().'.test',
            'relationship' => 'prospect',
            'source' => 'discovered',
            'qualification_status' => 'pending',
            'is_active' => true,
        ], array_diff_key($overrides, $claimKeys)));

        if ($claimAttributes !== []) {
            $company->forceFill($claimAttributes)->save();
        }

        return $company;
    }

    public function test_claim_debits_parent_before_provider_and_marks_company_enriching(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Carbon::setTestNow('2026-07-19 08:00:00');
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $company = $this->company($criteria, ['ai_score' => null]);

        $claimed = app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $run);

        $this->assertSame($company->id, $claimed->id);
        $this->assertSame(1, (int) $run->refresh()->contact_consumed);
        $company->refresh();
        $this->assertSame(Company::ENRICHMENT_ENRICHING, $company->enrichment_status);
        $this->assertSame($run->id, (int) $company->enrichment_claim_run_id);
        $this->assertTrue($company->enrichment_attempted_at->equalTo(now()));
    }

    public function test_same_run_cannot_claim_or_debit_company_twice(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $company = $this->company($criteria);
        $service = app(DiscoveryQuotaService::class);
        $service->claimAutomaticEnrichment($company->id, $run);

        try {
            $service->claimAutomaticEnrichment($company->id, $run);
            $this->fail('A same-run claim must not be admitted twice.');
        } catch (EnrichmentInFlightException) {
            $this->assertSame(1, (int) $run->refresh()->contact_consumed);
        }
    }

    public function test_active_foreign_claim_blocks_without_debit(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $owner = $this->discoveryRun($criteria);
        $challenger = $this->discoveryRun($criteria);
        $company = $this->company($criteria, [
            'enrichment_status' => Company::ENRICHMENT_ENRICHING,
            'enrichment_claim_run_id' => $owner->id,
            'enrichment_attempted_at' => now(),
        ]);

        try {
            app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $challenger);
            $this->fail('An active foreign claim must block admission.');
        } catch (EnrichmentInFlightException) {
            $this->assertSame(0, (int) $challenger->refresh()->contact_consumed);
            $this->assertSame($owner->id, (int) $company->refresh()->enrichment_claim_run_id);
        }
    }

    public function test_terminal_foreign_claim_is_immediately_reclaimable(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $owner = $this->discoveryRun($criteria, ['status' => 'failed', 'finished_at' => now()]);
        $challenger = $this->discoveryRun($criteria);
        $company = $this->company($criteria, [
            'enrichment_status' => Company::ENRICHMENT_HUNTER_FAILED,
            'enrichment_claim_run_id' => $owner->id,
            'enrichment_attempted_at' => now()->subMinute(),
        ]);

        app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $challenger);

        $this->assertSame(1, (int) $challenger->refresh()->contact_consumed);
        $this->assertSame($challenger->id, (int) $company->refresh()->enrichment_claim_run_id);
    }

    public function test_stale_running_foreign_claim_is_reclaimable_after_660_seconds(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Carbon::setTestNow('2026-07-19 12:00:00');
        $criteria = $this->criteria();
        $owner = $this->discoveryRun($criteria, ['started_at' => now()->subSeconds(661)]);
        $owner->timestamps = false;
        $owner->updated_at = now()->subSeconds(661);
        $owner->save();
        $challenger = $this->discoveryRun($criteria);
        $company = $this->company($criteria, [
            'enrichment_status' => Company::ENRICHMENT_ENRICHING,
            'enrichment_claim_run_id' => $owner->id,
            'enrichment_attempted_at' => now()->subSeconds(661),
        ]);

        app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $challenger);

        $this->assertSame(1, (int) $challenger->refresh()->contact_consumed);
        $this->assertSame($challenger->id, (int) $company->refresh()->enrichment_claim_run_id);
    }

    public function test_running_foreign_claim_remains_active_before_660_seconds(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        Carbon::setTestNow('2026-07-19 12:00:00');
        $criteria = $this->criteria();
        $owner = $this->discoveryRun($criteria, ['started_at' => now()->subSeconds(659)]);
        $owner->timestamps = false;
        $owner->updated_at = now()->subSeconds(659);
        $owner->save();
        $challenger = $this->discoveryRun($criteria);
        $company = $this->company($criteria, [
            'enrichment_status' => Company::ENRICHMENT_ENRICHING,
            'enrichment_claim_run_id' => $owner->id,
            'enrichment_attempted_at' => now()->subSeconds(659),
        ]);

        try {
            app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $challenger);
            $this->fail('A running claim younger than 660 seconds must remain protected.');
        } catch (EnrichmentInFlightException) {
            $this->assertSame(0, (int) $challenger->refresh()->contact_consumed);
            $this->assertSame($owner->id, (int) $company->refresh()->enrichment_claim_run_id);
        }
    }

    public function test_null_score_is_eligible_only_when_automatic_scoring_is_disabled(): void
    {
        $criteria = $this->criteria(['min_score_enrich' => 50]);
        $company = $this->company($criteria, ['ai_score' => null]);

        Setting::set('decouverte.auto_scoring', true);
        $run = $this->discoveryRun($criteria);
        try {
            app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $run);
            $this->fail('Scoring-enabled claims require a score at or above threshold.');
        } catch (CriteriaCompanyNoLongerEligibleException) {
            $this->assertSame(0, (int) $run->refresh()->contact_consumed);
        }

        Setting::set('decouverte.auto_scoring', false);
        $secondRun = $this->discoveryRun($criteria);
        app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $secondRun);

        $this->assertSame(1, (int) $secondRun->refresh()->contact_consumed);
    }

    public function test_hunter_empty_is_not_automatically_claimable(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $company = $this->company($criteria, [
            'enrichment_status' => Company::ENRICHMENT_HUNTER_EMPTY,
        ]);

        $this->expectException(CriteriaCompanyNoLongerEligibleException::class);

        app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $run);
    }

    public function test_malformed_domain_is_rejected_before_parent_debit(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $company = $this->company($criteria, ['domain' => 'not a domain']);

        try {
            app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $run);
            $this->fail('Malformed domains must never consume a Hunter attempt.');
        } catch (CriteriaCompanyNoLongerEligibleException) {
            $this->assertSame(0, (int) $run->refresh()->contact_consumed);
            $this->assertNull($company->refresh()->enrichment_claim_run_id);
        }
    }

    public function test_enriched_status_without_contacts_can_be_claimed_again_automatically(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $company = $this->company($criteria, [
            'enrichment_status' => Company::ENRICHMENT_ENRICHED,
        ]);

        app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $run);

        $this->assertSame(1, (int) $run->refresh()->contact_consumed);
        $this->assertSame($run->id, (int) $company->refresh()->enrichment_claim_run_id);
    }

    public function test_inactive_company_is_not_automatically_claimable(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria);
        $company = $this->company($criteria, ['is_active' => false]);

        try {
            app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $run);
            $this->fail('Inactive companies must never consume an automatic Hunter attempt.');
        } catch (CriteriaCompanyNoLongerEligibleException) {
            $this->assertSame(0, (int) $run->refresh()->contact_consumed);
        }
    }

    public function test_parent_reservation_is_enforced_before_company_mutation(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $run = $this->discoveryRun($criteria, [
            'contact_credits_reserved' => 1,
            'contact_consumed' => 1,
        ]);
        $company = $this->company($criteria);

        try {
            app(DiscoveryQuotaService::class)->claimAutomaticEnrichment($company->id, $run);
            $this->fail('An exhausted parent reservation must reject another claim.');
        } catch (QuotaExhaustedException) {
            $this->assertNull($company->refresh()->enrichment_claim_run_id);
            $this->assertNull($company->enrichment_attempted_at);
        }
    }

    public function test_manual_reservation_honours_active_discovery_claim_without_debit(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $discoveryRun = $this->discoveryRun($criteria);
        $company = $this->company($criteria);
        $service = app(DiscoveryQuotaService::class);
        $service->claimAutomaticEnrichment($company->id, $discoveryRun);

        try {
            $service->reserveManualEnrichment($company);
            $this->fail('Manual enrichment must not race an active discovery claim.');
        } catch (EnrichmentInFlightException) {
            $this->assertSame(1, DiscoveryRun::where('type', 'discovery')->count());
            $this->assertSame(0, DiscoveryRun::where('type', 'manual')->count());
        }
    }

    public function test_criteria_manual_reservation_honours_active_discovery_claim(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $discoveryRun = $this->discoveryRun($criteria);
        $company = $this->company($criteria, ['ai_score' => 80]);
        $service = app(DiscoveryQuotaService::class);
        $service->claimAutomaticEnrichment($company->id, $discoveryRun);

        try {
            $service->reserveCriteriaEnrichment($company->id, $criteria);
            $this->fail('Criteria enrichment must not race an active discovery claim.');
        } catch (EnrichmentInFlightException) {
            $this->assertSame(0, DiscoveryRun::where('type', 'manual')->count());
            $this->assertSame(1, (int) $discoveryRun->refresh()->contact_consumed);
        }
    }

    public function test_active_manual_claim_blocks_automatic_parent_without_debit(): void
    {
        Setting::set('decouverte.auto_scoring', false);
        $criteria = $this->criteria();
        $company = $this->company($criteria);
        $service = app(DiscoveryQuotaService::class);
        $manualRun = $service->reserveManualEnrichment($company);
        $parentRun = $this->discoveryRun($criteria);

        try {
            $service->claimAutomaticEnrichment($company->id, $parentRun);
            $this->fail('An active manual claim must block automatic admission.');
        } catch (EnrichmentInFlightException) {
            $this->assertSame(0, (int) $parentRun->refresh()->contact_consumed);
            $this->assertSame($manualRun->id, (int) $company->refresh()->enrichment_claim_run_id);
        }
    }

    public function test_manual_reservation_sets_its_company_claim_atomically(): void
    {
        $criteria = $this->criteria();
        $company = $this->company($criteria, [
            'enrichment_status' => Company::ENRICHMENT_HUNTER_EMPTY,
        ]);

        $run = app(DiscoveryQuotaService::class)->reserveManualEnrichment($company);

        $company->refresh();
        $this->assertSame($run->id, (int) $company->enrichment_claim_run_id);
        $this->assertSame(Company::ENRICHMENT_ENRICHING, $company->enrichment_status);
        $this->assertNotNull($company->enrichment_attempted_at);
        $this->assertSame(1, (int) $run->contact_consumed);
    }
}
