<?php

namespace Tests\Feature\Backend;

use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProspectCriteria;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProspectReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ProspectBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['backend.access', 'review prospect matches'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->user->givePermissionTo(['backend.access', 'review prospect matches']);
        $this->batch = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'review',
        ]);
    }

    public function test_review_is_company_only_and_shows_no_contact_decision_ui(): void
    {
        $this->grantViewProspectBatches();
        ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'company_name' => 'Visible Company',
            'status' => 'review',
            'domain_reason' => 'missing_domain',
            'original_input' => 'private-original-row',
            'source_metadata' => ['raw' => 'private-provider-payload'],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', ['id' => $this->batch->id]));

        $response->assertOk()
            ->assertSee('Visible Company')
            ->assertDontSee('Décider des contacts')
            ->assertDontSee('Ajouter le contact')
            ->assertDontSee('Ne pas importer')
            ->assertDontSee('private-original-row')
            ->assertDontSee('private-provider-payload');

        $this->assertFalse(Route::has('admin.prospect_review.candidates.decide'));
        $this->assertFalse(\Schema::hasTable('prospect_contact_candidates'));
    }

    public function test_reviewer_can_select_only_an_alternative_belonging_to_the_item(): void
    {
        Queue::fake();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_alternatives' => ['acme.fr', 'acme.com'],
            'domain_reason' => 'ambiguous_domain',
        ]);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_review.items.decide', $item), [
                'action' => 'approve_domain',
                'selected_domain' => 'evil.example',
            ])
            ->assertUnprocessable();

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_review.items.decide', $item), [
                'action' => 'approve_domain',
                'selected_domain' => 'https://www.acme.fr/contact',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('prospect_batch_items', [
            'id' => $item->id,
            'selected_domain' => 'acme.fr',
            'status' => 'pending',
        ]);
        Queue::assertPushed(
            ProcessProspectBatchItemJob::class,
            fn (ProcessProspectBatchItemJob $job): bool => $job->itemId === $item->id,
        );
    }

    public function test_item_status_reports_imported_contacts_without_candidate_payload_keys(): void
    {
        $company = Company::factory()->create();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'ready',
            'company_id' => $company->id,
            'processing_started_at' => now()->subMinute(),
            'processed_at' => now(),
        ]);
        $contact = Contact::factory()->for($company)->create();
        ProspectBatchContact::query()->create([
            'prospect_batch_id' => $this->batch->id,
            'prospect_batch_item_id' => $item->id,
            'contact_id' => $contact->id,
            'provider_source' => 'hunter_domain_search',
            'imported_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('admin.prospect_review.items.status', $item));

        $response->assertOk()
            ->assertJsonPath('imported_contacts_count', 1)
            ->assertJsonMissingPath('pending_contacts_count')
            ->assertJsonMissingPath('contacts_url')
            ->assertJsonPath('review_url', route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'item' => $item->id,
                'monitor_item' => $item->id,
            ], false).'#prospect_batch_review');
    }

    public function test_review_status_enforces_batch_ownership(): void
    {
        $other = User::factory()->create();
        $foreignBatch = ProspectBatch::factory()->create([
            'created_by' => $other->id,
            'status' => 'review',
        ]);
        $foreignItem = ProspectBatchItem::factory()->for($foreignBatch, 'batch')->create(['status' => 'review']);

        $this->actingAs($this->user)
            ->getJson(route('admin.prospect_review.items.status', $foreignItem))
            ->assertForbidden();
    }

    public function test_uncertain_provider_outcome_requires_explicit_reissue_confirmation(): void
    {
        Queue::fake();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'provider_outcome_uncertain',
            'error_code' => 'provider_outcome_uncertain',
        ]);
        $call = ProviderCall::query()->create([
            'prospect_batch_id' => $this->batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'engine' => 'hunter',
            'idempotency_key' => hash('sha256', 'uncertain-call'),
            'status' => 'running',
            'reserved_units' => 1,
            'attempt_count' => 1,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_review.items.decide', $item), ['action' => 'retry'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_provider_reissue');

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_review.items.decide', $item), [
                'action' => 'retry',
                'confirm_provider_reissue' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('provider_calls', ['id' => $call->id, 'status' => 'retryable']);
        Queue::assertPushed(ProcessProspectBatchItemJob::class);
    }

    public function test_retry_of_an_item_linked_to_an_inactive_criterion_is_rejected_without_changing_its_state(): void
    {
        Queue::fake();
        $this->grantViewProspectBatches();
        $criteria = ProspectCriteria::create([
            'name' => 'Critère arrêté',
            'is_active' => false,
        ]);
        $this->batch->update(['prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'failed',
            'domain_reason' => 'provider_unavailable',
            'error_code' => 'provider_unavailable',
            'source_metadata' => ['existing' => 'must-stay'],
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'item' => $item->id,
            ]))
            ->assertOk()
            ->assertSee('data-review-retry-blocked', false)
            ->assertSee('Critère arrêté')
            ->assertDontSee('data-review-primary-action="retry"', false);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_review.items.decide', $item), ['action' => 'retry'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action')
            ->assertJsonPath('errors.action.0', 'Le critère « Critère arrêté » est inactif. Réactivez-le avant de relancer cette entreprise.');

        $this->assertDatabaseHas('prospect_batch_items', [
            'id' => $item->id,
            'status' => 'failed',
            'error_code' => 'provider_unavailable',
        ]);
        $this->assertSame(['existing' => 'must-stay'], $item->fresh()->source_metadata);
        Queue::assertNothingPushed();
    }

    public function test_retry_of_an_item_linked_to_an_active_criterion_is_still_queued(): void
    {
        Queue::fake();
        $criteria = ProspectCriteria::create([
            'name' => 'Critère actif',
            'is_active' => true,
        ]);
        $this->batch->update(['prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'failed',
            'domain_reason' => 'provider_unavailable',
            'error_code' => 'provider_unavailable',
        ]);

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_review.items.decide', $item), ['action' => 'retry'])
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('prospect_batch_items', [
            'id' => $item->id,
            'status' => 'pending',
            'error_code' => null,
        ]);
        Queue::assertPushed(
            ProcessProspectBatchItemJob::class,
            fn (ProcessProspectBatchItemJob $job): bool => $job->itemId === $item->id,
        );
    }

    public function test_retry_monitor_request_does_not_render_a_different_companys_actionable_card(): void
    {
        // The retried item flips to 'pending' as soon as decideItem() runs — it no
        // longer resolves in the ['review','failed'] scope the workspace fallback
        // reads from. A second, genuinely reviewable item must never get swapped in
        // under the monitor banner that is reporting on the retried company.
        $this->grantViewProspectBatches();
        $retriedItem = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'pending',
        ]);
        $otherItem = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'item' => $retriedItem->id,
            'monitor_item' => $retriedItem->id,
        ]));

        $response->assertOk()
            // "data-review-company-actions" also appears as a CSS attribute selector in
            // the page's inline <style> block, so assert the rendered attribute form
            // (with the trailing quote) rather than the bare string.
            ->assertDontSee('data-review-company-actions="', false)
            ->assertDontSee('data-review-company-card="'.$otherItem->id.'"', false)
            ->assertDontSee('data-review-company-card="'.$retriedItem->id.'"', false);
    }

    public function test_partial_request_returns_only_the_detail_card_and_preserves_the_current_page(): void
    {
        $this->grantViewProspectBatches();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'item' => $item->id,
            'companies_page' => 2,
            'partial' => 1,
        ]));

        $response->assertOk()
            ->assertSee('data-review-company-card="'.$item->id.'"', false)
            ->assertSee('name="return_companies_page" value="2"', false)
            ->assertDontSee('id="review-queue"', false)
            ->assertDontSee('File de vérification');
    }

    public function test_partial_request_falls_back_to_the_empty_state_when_no_other_item_is_selectable(): void
    {
        $this->grantViewProspectBatches();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'ready',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'item' => $item->id,
            'partial' => 1,
        ]));

        $response->assertOk()
            ->assertSee('data-review-empty', false)
            ->assertDontSee('data-review-company-card', false);
    }

    public function test_partial_request_falls_back_to_another_reviewable_item_when_the_requested_item_is_no_longer_selectable(): void
    {
        $this->grantViewProspectBatches();
        $stale = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'ready',
        ]);
        $fallback = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'item' => $stale->id,
            'partial' => 1,
        ]));

        $response->assertOk()
            ->assertSee('data-review-company-card="'.$fallback->id.'"', false)
            ->assertDontSee('data-review-empty', false);
    }

    public function test_deep_link_to_a_failed_item_renders_its_card_under_the_default_state_filter(): void
    {
        $this->grantViewProspectBatches();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'failed',
            'domain_reason' => 'provider_unavailable',
            'error_code' => 'provider_unavailable',
        ]);

        // No ?state= at all — the general "À décider" default must not
        // silently hide a deep-linked failed item; the default should
        // resolve to "blocked" for this item so its card actually renders.
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'item' => $item->id,
            ]))
            ->assertOk()
            ->assertSee('data-review-company-card="'.$item->id.'"', false)
            ->assertSee('<input type="hidden" name="state" value="blocked">', false)
            ->assertDontSee('data-review-empty', false);

        // An explicit ?state= is never overridden by the item-based default.
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'item' => $item->id,
                'state' => 'attention',
            ]))
            ->assertOk()
            ->assertSee('<input type="hidden" name="state" value="attention">', false);
    }

    public function test_reject_with_return_item_advances_the_redirect_to_the_next_reviewable_item(): void
    {
        Queue::fake();
        $this->grantViewProspectBatches();
        $older = ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['status' => 'review']);
        $newer = ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['status' => 'review']);

        $response = $this->actingAs($this->user)->post(route('admin.prospect_review.items.decide', $newer), [
            'action' => 'reject',
            'return_batch' => $this->batch->id,
            'return_state' => 'all',
            'return_item' => $newer->id,
        ]);

        $response->assertRedirect(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'state' => 'all',
            'item' => $older->id,
        ]).'#prospect_batch_review');
        $response->assertSessionHas('success');
    }

    public function test_deciding_an_already_processed_item_falls_back_to_a_card_instead_of_the_empty_pane(): void
    {
        $this->grantViewProspectBatches();
        $stale = ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['status' => 'ready']);
        $fallback = ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['status' => 'review']);

        $response = $this->actingAs($this->user)->followingRedirects()->post(
            route('admin.prospect_review.items.decide', $stale),
            [
                'action' => 'reject',
                'return_batch' => $this->batch->id,
                'return_state' => 'all',
                'return_item' => $stale->id,
            ]
        );

        $response->assertOk()
            ->assertSee('data-review-company-card="'.$fallback->id.'"', false)
            ->assertDontSee('data-review-empty', false);
    }

    public function test_historical_inactive_criterion_skip_explains_what_happened_and_the_safe_next_step(): void
    {
        $this->grantViewProspectBatches();
        $criteria = ProspectCriteria::create([
            'name' => 'Critère arrêté',
            'is_active' => false,
        ]);
        $this->batch->update(['prospect_criteria_id' => $criteria->id]);
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'company_name' => 'CSTransfo',
            'status' => 'skipped',
            'domain_reason' => 'criterion_inactive',
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'item' => $item->id,
                'monitor_item' => $item->id,
            ]))
            ->assertOk()
            ->assertSee('Pourquoi')
            ->assertSee('Critère arrêté')
            ->assertSee('Le critère est inactif.')
            ->assertSee('Aucun score, enrichissement ni recherche de contacts n’a été lancé.')
            ->assertSee('Réactivez le critère puis relancez la découverte.')
            ->assertDontSee('CSTransfo ne nécessite plus de traitement.');

        $this->actingAs($this->user)
            ->getJson(route('admin.prospect_review.items.status', $item))
            ->assertOk()
            ->assertJsonPath('title', 'Relance non effectuée · CSTransfo')
            ->assertJsonPath('result', 'Aucun score, enrichissement ni recherche de contacts n’a été lancé.')
            ->assertJsonPath('next_step', 'Réactivez le critère puis relancez la découverte.');
    }

    private function grantViewProspectBatches(): void
    {
        Permission::findOrCreate('view prospect_batches', 'web');
        $this->user->givePermissionTo('view prospect_batches');
    }

    public function test_batch_review_page_scopes_the_queue_to_this_batch_only(): void
    {
        $this->grantViewProspectBatches();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'company_name' => 'In This Batch',
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);
        $otherBatch = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'review',
        ]);
        $otherItem = ProspectBatchItem::factory()->for($otherBatch, 'batch')->create([
            'company_name' => 'In Another Owned Batch',
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', ['id' => $this->batch->id]));

        $response->assertOk()
            ->assertSee('data-review-company-card="'.$item->id.'"', false)
            ->assertDontSee('data-review-company-card="'.$otherItem->id.'"', false)
            ->assertDontSee('In Another Owned Batch');
    }

    public function test_batch_review_page_does_not_render_an_item_from_a_foreign_batch(): void
    {
        $this->grantViewProspectBatches();
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'company_name' => 'In This Batch',
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);
        $otherBatch = ProspectBatch::factory()->create([
            'created_by' => $this->user->id,
            'status' => 'review',
        ]);
        $otherItem = ProspectBatchItem::factory()->for($otherBatch, 'batch')->create([
            'company_name' => 'In Another Owned Batch',
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'item' => $otherItem->id,
        ]));

        // The foreign item never renders — the workspace falls back to this
        // batch's own queue instead (the existing fallback rules), not the
        // requested-but-out-of-scope card.
        $response->assertOk()
            ->assertDontSee('data-review-company-card="'.$otherItem->id.'"', false)
            ->assertDontSee('In Another Owned Batch')
            ->assertSee('data-review-company-card="'.$item->id.'"', false);
    }

    public function test_batch_review_page_403_for_a_batch_owned_by_another_user(): void
    {
        $this->grantViewProspectBatches();
        $other = User::factory()->create();
        $foreignBatch = ProspectBatch::factory()->create(['created_by' => $other->id, 'status' => 'review']);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', ['id' => $foreignBatch->id]))
            ->assertForbidden();
    }

    public function test_batch_review_page_403_without_view_prospect_batches_permission(): void
    {
        // setUp grants only 'review prospect matches' — 'view prospect_batches' is
        // additionally required for the batch view page itself.
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', ['id' => $this->batch->id]))
            ->assertForbidden();
    }

    public function test_view_page_renders_all_three_panes_for_a_reviewer(): void
    {
        $this->grantViewProspectBatches();
        ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', ['id' => $this->batch->id]))
            ->assertOk()
            ->assertSee('id="prospect_batch_apercu"', false)
            ->assertSee('id="prospect_batch_resultats"', false)
            ->assertSee('id="prospect_batch_review"', false);
    }

    public function test_review_pane_is_absent_and_partial_403s_without_review_permission(): void
    {
        // 'view prospect_batches' is present but 'review prospect matches' is not:
        // the tab/pane must not exist in the markup at all, and the partial=1
        // fetch endpoint (which would otherwise leak the workspace data) 403s.
        $this->grantViewProspectBatches();
        $this->user->revokePermissionTo('review prospect matches');

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', ['id' => $this->batch->id]))
            ->assertOk()
            ->assertDontSee('id="prospect_batch_review"', false);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', ['id' => $this->batch->id, 'partial' => 1]))
            ->assertForbidden();
    }

    public function test_results_sort_whitelist_falls_back_to_row_on_a_junk_value(): void
    {
        $this->grantViewProspectBatches();
        ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['row_number' => 1]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'results_sort' => 'not_a_real_column; DROP TABLE',
        ]));

        // The 'Ligne' (row) sort header only renders the active-sort class when
        // $resultsSort resolved to 'row' — proof the junk value was rejected
        // instead of reaching the orderBy() column whitelist untouched.
        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/<a href="[^"]*" class="text-primary fw-bold text-decoration-none">\s*Ligne/',
            $response->getContent(),
        );
    }

    public function test_results_page_pagination_coexists_with_workspace_state_and_q_params(): void
    {
        $this->grantViewProspectBatches();
        foreach (range(1, 30) as $row) {
            ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
                'row_number' => $row,
                'status' => 'ready',
                'company_name' => 'Result Company '.$row,
            ]);
        }
        $reviewItem = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'row_number' => 1000,
            'status' => 'review',
            'domain_reason' => 'missing_domain',
            'company_name' => 'Review Company',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'results_page' => 2,
            'state' => 'attention',
            'q' => '',
        ]));

        $response->assertOk()
            ->assertSee('Result Company 30')
            ->assertDontSee('Result Company 5')
            ->assertSee('data-review-company-card="'.$reviewItem->id.'"', false);
    }

    public function test_junk_review_state_param_does_not_302_the_view_page(): void
    {
        $this->grantViewProspectBatches();

        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'state' => 'zzz',
            ]))
            ->assertOk();
    }

    public function test_array_valued_query_params_do_not_500_the_view_page(): void
    {
        // q[]/results_sort[]/results_dir[] each cast an array to a string on
        // the naive (string) cast — Laravel rethrows the resulting E_WARNING
        // as an ErrorException. Every read must guard with is_string() first
        // and fall back to its default, same as the criteria-controller style
        // reason/state parsing already does.
        $this->grantViewProspectBatches();

        foreach (['q', 'results_sort', 'results_dir'] as $param) {
            $this->actingAs($this->user)
                ->get(route('admin.prospect_batches.view', [
                    'id' => $this->batch->id,
                    $param => ['x'],
                ]))
                ->assertOk();
        }

        // Same guard on the ?partial=1 fetch branch (ProspectReviewWorkspace::buildFromRequest).
        $this->actingAs($this->user)
            ->get(route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'partial' => 1,
                'q' => ['x'],
            ]))
            ->assertOk();
    }

    public function test_batch_review_page_shows_revenir_au_lot_when_the_batch_has_no_review_items_at_all(): void
    {
        // Plain load (no ?state=), no items in the batch at all — the default
        // 'attention' state resolves to zero results, but nothing is hidden by
        // it: this is a genuinely empty batch, so the empty state must offer
        // "Revenir au lot", not the misleading "Effacer les filtres".
        $this->grantViewProspectBatches();

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', ['id' => $this->batch->id]));

        $response->assertOk()->assertSee('Revenir au lot');
    }

    public function test_batch_review_page_defaults_to_the_blocked_pill_when_only_blocked_items_exist_on_plain_load(): void
    {
        // Root-cause fix: a plain load (no ?state=) used to hardcode
        // state=attention, which shows zero results when everything sits in
        // "À relancer" — a false "nothing to review" empty state even though
        // 51 items are one click away. The default must pick whichever pill
        // actually has content (ProspectReviewWorkspace::defaultState()), so
        // this batch's one blocked item renders directly, with neither the
        // "no items at all" nor the "filtered out" empty-state copy.
        $this->grantViewProspectBatches();
        ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'failed',
            'company_name' => 'Blocked Co',
            'domain_reason' => 'provider_unavailable',
            'error_code' => 'provider_unavailable',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_batches.view', ['id' => $this->batch->id]));

        $response->assertOk()
            ->assertSee('Blocked Co')
            ->assertDontSee('Effacer les filtres')
            ->assertDontSee('Revenir au lot');
    }

    public function test_reject_from_the_batch_page_redirects_back_to_the_batch_review_tab(): void
    {
        Queue::fake();
        $this->grantViewProspectBatches();
        $older = ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['status' => 'review']);
        $newer = ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['status' => 'review']);

        $response = $this->actingAs($this->user)->post(route('admin.prospect_review.items.decide', $newer), [
            'action' => 'reject',
            'return_batch' => $this->batch->id,
            'return_state' => 'all',
            'return_item' => $newer->id,
        ]);

        $response->assertRedirect(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'state' => 'all',
            'item' => $older->id,
        ]).'#prospect_batch_review');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');
    }

    public function test_retry_from_the_batch_page_returns_to_the_batch_url_and_does_not_leak_another_cards_actions(): void
    {
        Queue::fake();
        $this->grantViewProspectBatches();
        $retried = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'failed',
            'domain_reason' => 'provider_unavailable',
            'error_code' => 'provider_unavailable',
        ]);
        $other = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $retryPayload = [
            'action' => 'retry',
            'return_batch' => $this->batch->id,
        ];

        $response = $this->actingAs($this->user)->post(route('admin.prospect_review.items.decide', $retried), $retryPayload);

        $response->assertRedirect(route('admin.prospect_batches.view', [
            'id' => $this->batch->id,
            'item' => $retried->id,
            'monitor_item' => $retried->id,
        ]).'#prospect_batch_review');

        // Retry is idempotent once the item is 'pending' — following the redirect
        // re-submits the same decision, which is a no-op, then renders the batch
        // review page under the monitor banner it just redirected to.
        $followed = $this->actingAs($this->user)->followingRedirects()->post(
            route('admin.prospect_review.items.decide', $retried),
            $retryPayload,
        );

        $followed->assertOk()
            ->assertDontSee('data-review-company-actions="', false)
            ->assertDontSee('data-review-company-card="'.$other->id.'"', false)
            ->assertDontSee('data-review-company-card="'.$retried->id.'"', false);
    }

    public function test_item_status_always_returns_a_batch_scoped_review_url(): void
    {
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'ready',
            'processing_started_at' => now()->subMinute(),
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('admin.prospect_review.items.status', ['item' => $item->id]));

        $response->assertOk()
            ->assertJsonPath('review_url', route('admin.prospect_batches.view', [
                'id' => $this->batch->id,
                'item' => $item->id,
                'monitor_item' => $item->id,
            ], false).'#prospect_batch_review');
    }

    // ── Tabbar regression guard ──────────────────────────────────────────────
    // The generalized native-tab test in backend.partials.crud._tabbar
    // (`$native = ($mode === 'both') || ($mode === $currentPage)`) must not
    // change behaviour for companies, the only existing mode='both' consumer.

    public function test_tabbar_native_and_deep_link_behaviour_is_unchanged_for_companies_view_and_edit(): void
    {
        foreach (['view companies', 'edit companies'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user->givePermissionTo(['view companies', 'edit companies']);
        $company = Company::factory()->create();

        $viewHtml = $this->actingAs($this->user)->get(route('admin.companies.view', $company->id))->assertOk()->getContent();
        $editHtml = $this->actingAs($this->user)->get(route('admin.companies.edit', $company->id))->assertOk()->getContent();

        $assertNativeTab = function (string $html, string $paneId): void {
            $this->assertMatchesRegularExpression(
                '/<a\b(?=[^>]*\bdata-bs-toggle="tab")(?=[^>]*\bhref="#'.preg_quote($paneId, '/').'")[^>]*>/',
                $html,
            );
        };

        // View page: Aperçu is native, Général deep-links to the edit page, Contacts (mode=both) is native.
        $assertNativeTab($viewHtml, 'company_apercu');
        $this->assertStringContainsString('/admin/companies/'.$company->id.'/edit#company_general', $viewHtml);
        $assertNativeTab($viewHtml, 'company_contacts');

        // Edit page: Aperçu deep-links back to the view page, Général is native, Contacts stays native.
        $this->assertStringContainsString('/admin/companies/'.$company->id.'#company_apercu', $editHtml);
        $assertNativeTab($editHtml, 'company_general');
        $assertNativeTab($editHtml, 'company_contacts');
    }
}
