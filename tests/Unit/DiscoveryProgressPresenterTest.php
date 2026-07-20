<?php

namespace Tests\Unit;

use App\Models\DiscoveryRun;
use App\Models\ProspectCriteria;
use App\Services\Discovery\DiscoveryProgressPresenter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class DiscoveryProgressPresenterTest extends TestCase
{
    public function test_completed_run_payload_has_the_exact_progress_contract(): void
    {
        $criteria = $this->criteria(42);
        $run = $this->discoveryRun([
            'id' => 91,
            'prospect_criteria_id' => 42,
            'status' => 'completed',
            'companies_count' => 5,
            'contacts_count' => 12,
            'successful_enrichments' => 3,
            'successful_enrichments_target' => 4,
            'contact_credits_reserved' => 5,
            'contact_consumed' => 5,
            'skipped_count' => 2,
            'low_score_count' => 1,
            'excluded_count' => 4,
            'searches_reserved' => 3,
            'searches_consumed' => 2,
            'consumed' => 3,
            'candidates_snapshot' => [
                ['domain' => 'one.test'],
                ['domain' => ''],
                ['title' => 'Missing domain'],
                'invalid row',
                ['domain' => 'two.test'],
            ],
            'finished_at' => Carbon::parse('2026-07-19 10:15:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-07-19 10:16:00', 'UTC'),
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($criteria, $run, 17, false);

        $this->assertSame([
            'status' => 'completed',
            'companies_count' => 5,
            'contacts_count' => 12,
            'successful_enrichments' => 3,
            'successful_enrichments_target' => 4,
            'contact_attempts_reserved' => 5,
            'contact_attempts_consumed' => 5,
            'skipped_count' => 2,
            'low_score_count' => 1,
            'excluded_count' => 4,
            'finished_at' => '2026-07-19T10:15:00+00:00',
            'companies_total' => 17,
            'stale' => false,
            'error' => null,
            'run_id' => 91,
            'prospect_criteria_id' => 42,
            'phase' => 'completed',
            'searches_consumed' => 2,
            'searches_reserved' => 3,
            'candidates_processed' => 3,
            'candidates_total' => 2,
            'candidates_total_final' => true,
            'progress_percent' => 100,
            'heartbeat_at' => '2026-07-19T10:16:00+00:00',
        ], $payload);
    }

    public function test_idle_payload_is_stable_without_losing_the_existing_company_total(): void
    {
        $payload = (new DiscoveryProgressPresenter)->present($this->criteria(42), null, 7, false);

        $this->assertSame([
            'status' => null,
            'companies_count' => 0,
            'contacts_count' => 0,
            'successful_enrichments' => 0,
            'successful_enrichments_target' => 0,
            'contact_attempts_reserved' => 0,
            'contact_attempts_consumed' => 0,
            'skipped_count' => 0,
            'low_score_count' => 0,
            'excluded_count' => 0,
            'finished_at' => null,
            'companies_total' => 7,
            'stale' => false,
            'error' => null,
            'run_id' => null,
            'prospect_criteria_id' => 42,
            'phase' => 'idle',
            'searches_consumed' => 0,
            'searches_reserved' => 0,
            'candidates_processed' => 0,
            'candidates_total' => 0,
            'candidates_total_final' => true,
            'progress_percent' => null,
            'heartbeat_at' => null,
        ], $payload);
    }

    public function test_running_progress_stays_in_collection_phase_while_the_candidate_total_can_still_grow(): void
    {
        $run = $this->discoveryRun([
            'id' => 92,
            'prospect_criteria_id' => 42,
            'status' => 'running',
            'searches_reserved' => 3,
            'searches_consumed' => 1,
            'consumed' => 1,
            'candidates_snapshot' => [
                ['domain' => 'one.test'],
                ['domain' => 'two.test'],
                ['domain' => 'three.test'],
            ],
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($this->criteria(42), $run, 0, false);

        $this->assertSame('collecting', $payload['phase']);
        $this->assertFalse($payload['candidates_total_final']);
        $this->assertNull($payload['progress_percent']);
    }

    public function test_pending_run_is_queued_and_uses_its_legacy_search_reservation(): void
    {
        $run = $this->discoveryRun([
            'id' => 96,
            'prospect_criteria_id' => 42,
            'status' => 'pending',
            'credits_reserved' => 4,
            'searches_reserved' => null,
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($this->criteria(42), $run, 0, false);

        $this->assertSame('queued', $payload['phase']);
        $this->assertSame(4, $payload['searches_reserved']);
        $this->assertFalse($payload['candidates_total_final']);
        $this->assertNull($payload['progress_percent']);
    }

    public function test_last_debited_search_stays_indeterminate_until_its_page_is_visible(): void
    {
        $run = $this->discoveryRun([
            'id' => 93,
            'prospect_criteria_id' => 42,
            'status' => 'running',
            'searches_reserved' => 3,
            'searches_consumed' => 3,
            'consumed' => 3,
            'candidates_snapshot' => [
                ['domain' => 'one.test'],
                ['domain' => 'two.test'],
                ['domain' => 'three.test'],
            ],
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($this->criteria(42), $run, 0, false);

        $this->assertSame('collecting', $payload['phase']);
        $this->assertFalse($payload['candidates_total_final']);
        $this->assertNull($payload['progress_percent']);
    }

    public function test_final_search_with_a_visible_processing_backlog_has_determinate_progress_below_one_hundred(): void
    {
        $criteria = $this->criteria(42, [
            'discovery_cursors' => [
                ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY => 97,
            ],
        ]);
        $run = $this->discoveryRun([
            'id' => 97,
            'prospect_criteria_id' => 42,
            'status' => 'running',
            'searches_reserved' => 3,
            'searches_consumed' => 3,
            'consumed' => 1,
            'candidates_snapshot' => [
                ['domain' => 'one.test'],
                ['domain' => 'two.test'],
                ['domain' => 'three.test'],
            ],
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($criteria, $run, 0, false);

        $this->assertSame('processing', $payload['phase']);
        $this->assertTrue($payload['candidates_total_final']);
        $this->assertSame(33, $payload['progress_percent']);
    }

    public function test_collection_marker_from_another_run_does_not_finalize_the_selected_run(): void
    {
        $criteria = $this->criteria(42, [
            'discovery_cursors' => [
                ProspectCriteria::DISCOVERY_COLLECTION_COMPLETE_RUN_KEY => 999,
            ],
        ]);
        $run = $this->discoveryRun([
            'id' => 98,
            'prospect_criteria_id' => 42,
            'status' => 'running',
            'searches_reserved' => 3,
            'searches_consumed' => 3,
            'consumed' => 1,
            'candidates_snapshot' => [
                ['domain' => 'one.test'],
                ['domain' => 'two.test'],
            ],
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($criteria, $run, 0, false);

        $this->assertSame('collecting', $payload['phase']);
        $this->assertFalse($payload['candidates_total_final']);
        $this->assertNull($payload['progress_percent']);
    }

    public function test_failed_run_is_final_but_has_no_progress_percent(): void
    {
        $run = $this->discoveryRun([
            'id' => 94,
            'prospect_criteria_id' => 42,
            'status' => 'failed',
            'searches_reserved' => 5,
            'searches_consumed' => 1,
            'consumed' => 1,
            'candidates_snapshot' => [['domain' => 'one.test']],
            'error' => 'SQLSTATE provider-secret.internal <img src=x onerror=alert(1)>',
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($this->criteria(42), $run, 0, false);

        $this->assertSame('failed', $payload['phase']);
        $this->assertTrue($payload['candidates_total_final']);
        $this->assertNull($payload['progress_percent']);
        $this->assertSame(DiscoveryRun::UNEXPECTED_FAILURE_MESSAGE, $payload['error']);
    }

    public function test_curated_configuration_failure_remains_actionable(): void
    {
        $message = 'La découverte d’entreprises ne peut pas démarrer : la clé API du fournisseur de recherche n’est pas configurée.';
        $run = $this->discoveryRun([
            'id' => 99,
            'prospect_criteria_id' => 42,
            'status' => 'failed',
            'error' => $message,
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($this->criteria(42), $run, 0, false);

        $this->assertSame($message, $payload['error']);
    }

    public function test_local_driver_can_mark_a_running_snapshot_final(): void
    {
        $run = $this->discoveryRun([
            'id' => 95,
            'prospect_criteria_id' => 42,
            'status' => 'running',
            'searches_reserved' => 5,
            'searches_consumed' => 1,
            'consumed' => 1,
            'candidates_snapshot' => [
                ['domain' => 'one.test'],
                ['domain' => 'two.test'],
            ],
        ]);

        $payload = (new DiscoveryProgressPresenter)->present($this->criteria(42), $run, 0, true);

        $this->assertTrue($payload['candidates_total_final']);
        $this->assertSame(50, $payload['progress_percent']);
    }

    private function criteria(int $id, array $attributes = []): ProspectCriteria
    {
        return (new ProspectCriteria)->forceFill(array_merge(['id' => $id], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function discoveryRun(array $attributes): DiscoveryRun
    {
        $run = new DiscoveryRun;
        $run->setDateFormat('Y-m-d H:i:s');

        return $run->forceFill(array_merge([
            'type' => 'discovery',
            'status' => 'pending',
            'companies_count' => 0,
            'contacts_count' => 0,
            'contact_credits_reserved' => 0,
            'contact_consumed' => 0,
            'skipped_count' => 0,
            'low_score_count' => 0,
            'excluded_count' => 0,
            'credits_reserved' => 0,
            'searches_reserved' => 0,
            'searches_consumed' => 0,
            'consumed' => 0,
            'candidates_snapshot' => null,
            'error' => null,
        ], $attributes));
    }
}
