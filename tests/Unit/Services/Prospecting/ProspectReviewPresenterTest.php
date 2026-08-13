<?php

namespace Tests\Unit\Services\Prospecting;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Services\Prospecting\ProspectReviewPresenter;
use Tests\TestCase;

class ProspectReviewPresenterTest extends TestCase
{
    public function test_company_decision_ranks_identity_evidence_ahead_of_alphabetical_alternatives(): void
    {
        $item = new ProspectBatchItem([
            'company_name' => 'DEANTE MAROC',
            'status' => 'review',
            'domain_reason' => 'domain_identity_conflict',
            'domain_alternatives' => ['alceramic.ma', 'deante.ma', 'douane.gov.ma', 'instagram.com'],
            'source_metadata' => [
                'resolution' => [
                    'finder' => [
                        ['domain' => 'douane.gov.ma', 'company_name' => 'Douane Maroc', 'perfect_match' => true],
                        ['domain' => 'alceramic.ma', 'company_name' => 'Al Ceramic', 'perfect_match' => true],
                    ],
                    'google' => [
                        ['domain' => 'instagram.com', 'name' => 'deante (@deante.maroc)', 'platform' => true],
                        ['domain' => 'deante.ma', 'name' => 'Deante'],
                    ],
                    'maps' => [],
                    'provided' => [],
                ],
            ],
        ]);

        $decision = app(ProspectReviewPresenter::class)->companyDecision($item);

        $this->assertSame('deante.ma', $decision['primary_candidate']['domain']);
        $this->assertContains('Recherche web', $decision['primary_candidate']['sources']);
        $platform = collect($decision['alternatives'])->firstWhere('domain', 'instagram.com');
        $this->assertNotNull($platform);
        $this->assertFalse($platform['selectable']);
        $this->assertSame('Plateforme écartée', $platform['confidence']);
    }

    public function test_company_decision_preserves_provider_order_when_identity_evidence_is_equal(): void
    {
        $item = new ProspectBatchItem([
            'company_name' => 'ALMA',
            'status' => 'review',
            'domain_reason' => 'ambiguous_domain',
            'domain_alternatives' => ['alma.ar', 'alma.by', 'alma.fr', 'almapay.com'],
            'source_metadata' => [
                'resolution' => [
                    'finder' => [
                        ['domain' => 'alma.fr', 'company_name' => 'Alma', 'perfect_match' => true],
                        ['domain' => 'almapay.com', 'company_name' => 'Alma', 'perfect_match' => true],
                        ['domain' => 'alma.ar', 'company_name' => 'Alma', 'perfect_match' => true],
                        ['domain' => 'alma.by', 'company_name' => 'Alma', 'perfect_match' => true],
                    ],
                    'google' => [],
                    'maps' => [],
                    'provided' => [],
                ],
            ],
        ]);

        $decision = app(ProspectReviewPresenter::class)->companyDecision($item);

        $this->assertSame('alma.fr', $decision['primary_candidate']['domain']);
        $this->assertSame(['alma.ar', 'alma.by', 'almapay.com'], collect($decision['alternatives'])->pluck('domain')->all());
    }

    public function test_company_decision_never_makes_unpersisted_evidence_selectable(): void
    {
        $item = new ProspectBatchItem([
            'company_name' => 'Evidence Company',
            'status' => 'review',
            'domain_reason' => 'ambiguous_domain',
            'domain_alternatives' => ['allowed-example.com'],
            'source_metadata' => [
                'resolution' => [
                    'finder' => [
                        ['domain' => 'evidence-company.com', 'company_name' => 'Evidence Company', 'perfect_match' => true],
                    ],
                ],
            ],
        ]);

        $decision = app(ProspectReviewPresenter::class)->companyDecision($item);

        $this->assertSame('allowed-example.com', $decision['primary_candidate']['domain']);
        $evidenceOnly = collect($decision['alternatives'])->firstWhere('domain', 'evidence-company.com');
        $this->assertNotNull($evidenceOnly);
        $this->assertFalse($evidenceOnly['selectable']);
    }

    public function test_company_decision_uses_issue_specific_checks_and_primary_actions(): void
    {
        $presenter = app(ProspectReviewPresenter::class);
        $domains = ['example.com'];

        $failed = $presenter->companyDecision(new ProspectBatchItem([
            'company_name' => 'Interrupted Company',
            'status' => 'failed',
            'error_code' => 'rate_limit',
            'domain_reason' => 'hunter_perfect_match',
            'domain_alternatives' => $domains,
        ]));
        $ambiguous = $presenter->companyDecision(new ProspectBatchItem([
            'company_name' => 'Ambiguous Company',
            'status' => 'review',
            'domain_reason' => 'ambiguous_domain',
            'domain_alternatives' => $domains,
        ]));
        $collision = $presenter->companyDecision(new ProspectBatchItem([
            'company_name' => 'Collision Company',
            'status' => 'review',
            'domain_reason' => 'registrable_domain_collision',
            'domain_alternatives' => $domains,
        ]));
        $uncertain = $presenter->companyDecision(new ProspectBatchItem([
            'company_name' => 'Uncertain Company',
            'status' => 'failed',
            'error_code' => 'provider_outcome_uncertain',
        ]));
        $missing = $presenter->companyDecision(new ProspectBatchItem([
            'company_name' => 'Missing Company',
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]));

        $this->assertSame('retry', $failed['primary_action']);
        $this->assertSame('approve_domain', $ambiguous['primary_action']);
        $this->assertSame('compare_collision', $collision['primary_action']);
        $this->assertTrue($uncertain['requires_reissue_confirmation']);
        $this->assertCount(4, $missing['checks']);
        $this->assertSame('danger', $missing['checks'][1]['tone']);
    }

    public function test_failed_item_prioritizes_safe_error_copy(): void
    {
        $item = new ProspectBatchItem(['status' => 'failed', 'error_code' => 'rate_limit', 'domain_reason' => 'hunter_perfect_match']);
        $issue = app(ProspectReviewPresenter::class)->itemIssue($item);

        $this->assertSame('rate_limit', $issue['code']);
        $this->assertSame('Fournisseur temporairement limité', $issue['label']);
        $this->assertSame('interrupted', $issue['kind']);
    }

    public function test_pagination_failure_explains_that_contact_search_stopped_after_domain_selection(): void
    {
        $item = new ProspectBatchItem([
            'status' => 'failed',
            'error_code' => 'pagination_error',
            'domain_reason' => 'reviewer_selected',
        ]);

        $issue = app(ProspectReviewPresenter::class)->itemIssue($item);

        $this->assertSame('Recherche de contacts interrompue', $issue['label']);
        $this->assertStringContainsString('Le domaine a bien été enregistré', $issue['description']);
        $this->assertSame('pagination_error', $issue['code']);
    }

    public function test_failed_enriched_selected_domain_presents_a_contact_collection_resume(): void
    {
        $item = new ProspectBatchItem([
            'company_name' => 'DEANTE MAROC',
            'status' => 'failed',
            'selected_domain' => 'deante.ma',
            'domain_alternatives' => ['other.example'],
            'error_code' => 'provider_call_not_replayable',
            'source_metadata' => [
                'processing' => ['company_enrichment_done' => true],
            ],
        ]);
        $item->setAttribute('imported_contacts_count', 1);

        $decision = app(ProspectReviewPresenter::class)->companyDecision($item);

        $this->assertSame('retry', $decision['primary_action']);
        $this->assertSame('Relancer la recherche de contacts', $decision['primary_label']);
        $this->assertTrue($decision['contact_collection_resume']);
        $this->assertSame('deante.ma', $decision['selected_domain']);
        $this->assertSame('deante.ma', $decision['primary_candidate']['domain']);
        $this->assertSame(1, $decision['imported_contacts_count']);
        $this->assertTrue($decision['domains_read_only']);
        $this->assertStringContainsString('1 contact déjà importé.', $decision['correct']);
        $this->assertStringNotContainsString('(s)', $decision['correct']);
        $this->assertSame('Jusqu’à 10 adresses · 0 à 1 unité', $decision['retry_cost_note']);
        $this->assertSame('1 contact importé', $decision['checks'][3]['detail']);
    }

    public function test_unknown_item_code_uses_non_sensitive_fallback(): void
    {
        $item = new ProspectBatchItem(['status' => 'review', 'domain_reason' => 'raw_provider_exception']);

        $issue = app(ProspectReviewPresenter::class)->itemIssue($item);
        $this->assertSame('unknown', $issue['code']);
        $this->assertSame('Vérification manuelle requise', $issue['label']);
        $this->assertStringNotContainsString('raw_provider_exception', (string) json_encode($issue));
    }

    public function test_batch_state_never_claims_completion_from_zero_pending_alone(): void
    {
        $state = app(ProspectReviewPresenter::class)->batchNextState(new ProspectBatch(['status' => 'review']), 0);
        $this->assertSame('Décisions terminées — consulter le lot', $state['label']);
    }
}
