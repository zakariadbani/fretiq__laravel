<?php

namespace Tests\Feature\Backend;

use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectContactCandidate;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $this->user->givePermissionTo(['backend.access', 'review prospect matches']);
        $this->batch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'status' => 'review']);
    }

    public function test_review_lists_ambiguous_platform_collision_missing_domain_risky_email_and_errors(): void
    {
        foreach (['ambiguous_domain', 'platform_domain', 'registrable_domain_collision', 'missing_domain'] as $index => $reason) {
            ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
                'row_number' => $index + 1,
                'company_name' => 'Review '.$index,
                'status' => 'review',
                'domain_reason' => $reason,
            ]);
        }
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'row_number' => 10,
            'status' => 'ready',
        ]);
        ProspectContactCandidate::factory()->for($this->batch, 'batch')->for($item, 'item')->create([
            'email' => 'risk@example.test',
            'normalized_email' => 'risk@example.test',
            'verification_status' => 'accept_all',
            'decision' => 'pending',
        ]);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index'))
            ->assertOk()
            ->assertSee('Correspondances à revoir')
            ->assertSee('risk@example.test')
            ->assertSee('accept_all');
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

        $this->assertDatabaseHas('prospect_batch_items', ['id' => $item->id, 'selected_domain' => 'acme.fr', 'status' => 'pending']);
        Queue::assertPushed(ProcessProspectBatchItemJob::class, fn (ProcessProspectBatchItemJob $job): bool => $job->itemId === $item->id);
    }

    public function test_approve_or_reject_is_idempotent_and_records_actor_reason_time(): void
    {
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create(['status' => 'ready']);
        $candidate = ProspectContactCandidate::factory()->for($this->batch, 'batch')->for($item, 'item')->create([
            'email' => 'reject@example.test',
            'normalized_email' => 'reject@example.test',
            'decision' => 'pending',
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($this->user)
                ->postJson(route('admin.prospect_review.candidates.decide', $candidate), [
                    'action' => 'reject',
                    'reason' => 'not_relevant',
                ])
                ->assertOk()
                ->assertJsonPath('decision', 'rejected');
        }

        $candidate->refresh();
        $this->assertSame($this->user->id, $candidate->decided_by);
        $this->assertSame('not_relevant', $candidate->decision_reason);
        $this->assertNotNull($candidate->decided_at);
    }

    public function test_review_datatable_does_not_expose_raw_input_or_internal_metadata(): void
    {
        ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'company_name' => 'Visible Company',
            'status' => 'review',
            'domain_reason' => 'ambiguous_domain',
            'original_input' => 'secret-original-row',
            'source_metadata' => ['raw' => 'secret-provider-payload'],
            'error_message' => 'secret-exception-message',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('admin.prospect_review.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringContainsString('Visible Company', $body);
        $this->assertStringNotContainsString('secret-original-row', $body);
        $this->assertStringNotContainsString('secret-provider-payload', $body);
        $this->assertStringNotContainsString('secret-exception-message', $body);
    }

    public function test_html_review_action_redirects_to_the_review_queue(): void
    {
        $item = ProspectBatchItem::factory()->for($this->batch, 'batch')->create([
            'status' => 'review',
            'domain_reason' => 'missing_domain',
        ]);

        $this->actingAs($this->user)
            ->post(route('admin.prospect_review.items.decide', $item), [
                'action' => 'reject',
                'reason' => 'not_relevant',
            ])
            ->assertRedirect(route('admin.prospect_review.index'))
            ->assertSessionHas('success');
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

        $this->assertDatabaseHas('provider_calls', [
            'id' => $call->id,
            'status' => 'retryable',
        ]);
        Queue::assertPushed(ProcessProspectBatchItemJob::class);
    }
}
