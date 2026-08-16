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

    public function test_legacy_contacts_tab_redirects_to_company_review_and_preserves_batch_scope(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.prospect_review.index', [
            'tab' => 'contacts',
            'batch' => $this->batch->id,
            'state' => 'attention',
            'q' => 'Atlas',
        ]));

        $response->assertRedirect(route('admin.prospect_review.index', [
            'batch' => $this->batch->id,
            'tab' => 'companies',
            'state' => 'attention',
            'q' => 'Atlas',
        ]));
        $response->assertSessionHas('info');
    }

    public function test_review_is_company_only_and_explains_that_contact_import_does_not_send(): void
    {
        ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'company_name' => 'Visible Company',
            'status' => 'review',
            'domain_reason' => 'missing_domain',
            'original_input' => 'private-original-row',
            'source_metadata' => ['raw' => 'private-provider-payload'],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index', ['tab' => 'companies', 'batch' => $this->batch->id]));

        $response->assertOk()
            ->assertSee('Vérifier les entreprises')
            ->assertSee('Les contacts sont importés automatiquement')
            ->assertSee('sans déclencher d’email')
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
            ->assertJsonPath('review_url', route('admin.prospect_review.index', [
                'tab' => 'companies',
                'batch' => $this->batch->id,
                'item' => $item->id,
                'monitor_item' => $item->id,
            ], false));
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
            ->get(route('admin.prospect_review.index', [
                'tab' => 'companies',
                'batch' => $this->batch->id,
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

    public function test_partial_request_returns_only_the_detail_card_and_preserves_the_current_page(): void
    {
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_review.index', [
            'tab' => 'companies',
            'batch' => $this->batch->id,
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

    public function test_partial_request_falls_back_to_the_empty_state_when_the_item_is_no_longer_selectable(): void
    {
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'ready',
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.prospect_review.index', [
            'tab' => 'companies',
            'batch' => $this->batch->id,
            'item' => $item->id,
            'partial' => 1,
        ]));

        $response->assertOk()
            ->assertSee('data-review-empty', false)
            ->assertDontSee('data-review-company-card', false);
    }

    public function test_deep_link_to_a_failed_item_renders_its_card_under_the_default_state_filter(): void
    {
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'failed',
            'domain_reason' => 'provider_unavailable',
            'error_code' => 'provider_unavailable',
        ]);

        // No ?state= at all — the general "À décider" default must not
        // silently hide a deep-linked failed item; the default should
        // resolve to "blocked" for this item so its card actually renders.
        $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index', [
                'tab' => 'companies',
                'batch' => $this->batch->id,
                'item' => $item->id,
            ]))
            ->assertOk()
            ->assertSee('data-review-company-card="'.$item->id.'"', false)
            ->assertSee('<input type="hidden" name="state" value="blocked">', false)
            ->assertDontSee('data-review-empty', false);

        // An explicit ?state= is never overridden by the item-based default.
        $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index', [
                'tab' => 'companies',
                'batch' => $this->batch->id,
                'item' => $item->id,
                'state' => 'attention',
            ]))
            ->assertOk()
            ->assertSee('<input type="hidden" name="state" value="attention">', false);
    }

    public function test_historical_inactive_criterion_skip_explains_what_happened_and_the_safe_next_step(): void
    {
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
            ->get(route('admin.prospect_review.index', [
                'tab' => 'companies',
                'batch' => $this->batch->id,
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
}
