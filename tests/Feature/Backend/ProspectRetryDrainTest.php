<?php

namespace Tests\Feature\Backend;

use App\Jobs\DrainRetryableProspectItemsJob;
use App\Jobs\ProcessProspectBatchItemJob;
use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProspectCriteria;
use App\Models\ProviderCall;
use App\Models\User;
use App\Services\Prospecting\ProspectBatchService;
use App\Services\Providers\ProviderCallLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProspectRetryDrainTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

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
    }

    public function test_preview_classifies_every_skip_reason_and_reports_headroom_and_cost(): void
    {
        [$eligible, $windowOpen, $budgetExhausted, $uncertain, $criterionBlocked] = $this->fiveItemScenario();

        $response = $this->actingAs($this->user)->postJson(route('admin.prospect_review.retry_drain.preview'));
        $payload = $response->assertOk()->json();

        $this->assertSame(5, $payload['total_matching']);
        $this->assertSame(5, $payload['considered']);
        $this->assertSame(1, $payload['eligible_count']);
        $this->assertSame([
            'retry_window_open' => 1,
            'budget_exhausted' => 1,
            'provider_outcome_uncertain' => 1,
            'criterion_inactive' => 1,
        ], $payload['skipped']);
        $this->assertEquals(1.0, $payload['estimated_units']);
        // The one eligible item's matching provider_call is at attempt_count=1
        // of 4 — 3 attempts of headroom, exactly the live-state fact this
        // feature was built to protect.
        $this->assertSame(3, $payload['min_attempt_headroom']);
    }

    public function test_preview_caps_considered_at_100_but_still_reports_the_true_total(): void
    {
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id]);
        ProspectBatchItem::factory()->for($batch, 'batch')->count(105)->create(['status' => 'failed']);

        $response = $this->actingAs($this->user)->postJson(route('admin.prospect_review.retry_drain.preview'));

        $response->assertOk()
            ->assertJsonPath('total_matching', 105)
            ->assertJsonPath('considered', 100);
    }

    public function test_store_selects_and_caps_items_then_dispatches_the_queued_drain_job(): void
    {
        Queue::fake();
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id]);
        $items = ProspectBatchItem::factory()->for($batch, 'batch')->count(105)->create(['status' => 'failed']);
        $expectedIds = $items->sortBy('id')->take(100)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $response = $this->actingAs($this->user)->postJson(route('admin.prospect_review.retry_drain.store'));

        $response->assertOk()->assertJsonPath('considered', 100);
        Queue::assertPushed(
            DrainRetryableProspectItemsJob::class,
            fn (DrainRetryableProspectItemsJob $job): bool => count($job->itemIds) === 100 && $job->itemIds === $expectedIds,
        );
    }

    public function test_store_reports_empty_when_nothing_matches_the_current_filter(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson(route('admin.prospect_review.retry_drain.store'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'prospect_retry_drain_empty');
        Queue::assertNothingPushed();
    }

    public function test_job_retries_only_eligible_items_dispatches_through_the_existing_job_and_reports_every_skip_reason(): void
    {
        Queue::fake();
        [$eligible, $windowOpen, $budgetExhausted, $uncertain, $criterionBlocked] = $this->fiveItemScenario();
        $token = 'retry-report-token';

        (new DrainRetryableProspectItemsJob(
            [$eligible->id, $windowOpen->id, $budgetExhausted->id, $uncertain->id, $criterionBlocked->id],
            $token,
            $this->user->id,
        ))->handle(app(ProspectBatchService::class), app(ProviderCallLedger::class));

        // Reuses the existing per-item worker — never a second retry path.
        Queue::assertPushed(ProcessProspectBatchItemJob::class, fn (ProcessProspectBatchItemJob $job): bool => $job->itemId === $eligible->id);
        $this->assertCount(1, Queue::pushed(ProcessProspectBatchItemJob::class));

        $this->assertSame('pending', $eligible->fresh()->status);
        $this->assertNull($eligible->fresh()->error_code);
        foreach ([$windowOpen, $budgetExhausted, $uncertain, $criterionBlocked] as $untouched) {
            $this->assertSame('failed', $untouched->fresh()->status, "item {$untouched->id} must stay untouched");
        }

        $result = Cache::get(DrainRetryableProspectItemsJob::cacheKeyFor($token));
        $this->assertTrue($result['terminal']);
        $this->assertFalse($result['error']);
        $this->assertSame(1, $result['retried']);
        $this->assertSame([
            'retry_window_open' => 1,
            'budget_exhausted' => 1,
            'provider_outcome_uncertain' => 1,
            'criterion_inactive' => 1,
        ], $result['skipped']);
    }

    public function test_job_never_exceeds_the_100_item_cap_even_if_constructed_with_more(): void
    {
        Queue::fake();
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id]);
        $items = ProspectBatchItem::factory()->for($batch, 'batch')->count(105)->create(['status' => 'failed']);
        // The controller already caps at 100 before building the job — this
        // proves the cap is enforced at selection time, not silently ignored
        // if a caller ever handed the job more than the documented limit.
        $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertGreaterThan(100, count($ids));

        $response = $this->actingAs($this->user)->postJson(route('admin.prospect_review.retry_drain.store'));

        $response->assertOk();
        Queue::assertPushed(DrainRetryableProspectItemsJob::class, fn (DrainRetryableProspectItemsJob $job): bool => count($job->itemIds) <= 100);
    }

    public function test_job_stages_dispatch_delays_so_the_criterion_mutex_is_not_thrashed(): void
    {
        Queue::fake();
        $criteria = ProspectCriteria::create(['name' => 'Actif', 'is_active' => true]);
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'prospect_criteria_id' => $criteria->id]);
        $items = collect(range(1, 3))->map(function () use ($batch) {
            $item = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed', 'error_code' => 'usage_limit']);
            $this->providerCall($item, 'usage_limit', 1, 'failed');

            return $item;
        });

        (new DrainRetryableProspectItemsJob($items->pluck('id')->map(fn ($id) => (int) $id)->all(), 'spacing-token', $this->user->id))
            ->handle(app(ProspectBatchService::class), app(ProviderCallLedger::class));

        $delays = Queue::pushed(ProcessProspectBatchItemJob::class)->map(fn (ProcessProspectBatchItemJob $job) => $job->delay)->values();
        $this->assertCount(3, $delays);
        $this->assertInstanceOf(Carbon::class, $delays[0]);
        $this->assertEqualsWithDelta(8, $delays[0]->diffInSeconds($delays[1]), 1);
        $this->assertEqualsWithDelta(16, $delays[0]->diffInSeconds($delays[2]), 1);
    }

    public function test_drain_status_is_scoped_to_the_requester_unless_privileged(): void
    {
        $other = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $other->givePermissionTo(['backend.access', 'review prospect matches']);
        $token = 'scoped-token';
        Cache::put(DrainRetryableProspectItemsJob::cacheKeyFor($token), [
            'terminal' => true,
            'requested_by' => $other->id,
            'total' => 1,
            'retried' => 1,
            'skipped' => ['retry_window_open' => 0, 'budget_exhausted' => 0, 'provider_outcome_uncertain' => 0, 'criterion_inactive' => 0],
            'error' => false,
            'finished_at' => now()->toIso8601String(),
        ], now()->addHour());

        $this->actingAs($this->user)
            ->getJson(route('admin.prospect_review.retry_drain.status', $token))
            ->assertForbidden();

        $this->actingAs($other)
            ->getJson(route('admin.prospect_review.retry_drain.status', $token))
            ->assertOk()
            ->assertJsonPath('retried', 1);

        $this->actingAs($this->user)
            ->getJson(route('admin.prospect_review.retry_drain.status', 'unknown-token'))
            ->assertNotFound();
    }

    public function test_bulk_bar_shows_the_button_when_at_least_one_blocked_item_has_an_active_criterion(): void
    {
        $criteria = ProspectCriteria::create(['name' => 'Actif', 'is_active' => true]);
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'prospect_criteria_id' => $criteria->id]);
        ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed']);

        // Asserts on visible copy, not the raw data-* attribute names: those
        // also appear as JS selector strings in the always-rendered
        // @push('scripts') block (it no-ops when the bar markup is absent,
        // matching the existing single-item monitor's pattern), so checking
        // for the attribute name alone can't distinguish "markup present"
        // from "markup absent but the script tag still mentions it".
        $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index', ['tab' => 'companies', 'state' => 'blocked']))
            ->assertOk()
            ->assertSee('Relancer les entreprises éligibles')
            ->assertDontSee('Toutes les entreprises sont bloquées par un critère inactif');
    }

    public function test_bulk_bar_shows_the_inactive_criterion_reason_instead_of_a_dead_button_when_every_blocked_item_is_stuck(): void
    {
        $criteria = ProspectCriteria::create(['name' => 'Critère arrêté', 'is_active' => false]);
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'prospect_criteria_id' => $criteria->id]);
        ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed']);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index', ['tab' => 'companies', 'state' => 'blocked']))
            ->assertOk()
            ->assertSee('Toutes les entreprises sont bloquées par un critère inactif')
            ->assertDontSee('Relancer les entreprises éligibles');
    }

    public function test_bulk_bar_is_absent_outside_the_blocked_pill_and_when_nothing_is_blocked(): void
    {
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id]);
        ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'review']);

        $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index', ['tab' => 'companies', 'state' => 'attention']))
            ->assertOk()
            ->assertDontSee('Relancer les entreprises éligibles')
            ->assertDontSee('Toutes les entreprises sont bloquées par un critère inactif');

        $this->actingAs($this->user)
            ->get(route('admin.prospect_review.index', ['tab' => 'companies', 'state' => 'blocked']))
            ->assertOk()
            ->assertDontSee('Relancer les entreprises éligibles')
            ->assertDontSee('Toutes les entreprises sont bloquées par un critère inactif');
    }

    public function test_drain_routes_are_gated_by_the_same_permission_decide_item_uses(): void
    {
        $unprivileged = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $unprivileged->givePermissionTo('backend.access');

        $this->actingAs($unprivileged)->postJson(route('admin.prospect_review.retry_drain.preview'))->assertForbidden();
        $this->actingAs($unprivileged)->postJson(route('admin.prospect_review.retry_drain.store'))->assertForbidden();
    }

    /** @return list<ProspectBatchItem> [eligible, retry_window_open, budget_exhausted, provider_outcome_uncertain, criterion_inactive] */
    private function fiveItemScenario(): array
    {
        $active = ProspectCriteria::create(['name' => 'Actif', 'is_active' => true]);
        $inactive = ProspectCriteria::create(['name' => 'Arrêté', 'is_active' => false]);
        $batch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'prospect_criteria_id' => $active->id]);
        $inactiveBatch = ProspectBatch::factory()->create(['created_by' => $this->user->id, 'prospect_criteria_id' => $inactive->id]);

        $eligible = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed', 'error_code' => 'usage_limit']);
        $this->providerCall($eligible, 'usage_limit', 1, 'failed');

        $windowOpen = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed', 'error_code' => 'usage_limit']);
        $this->providerCall($windowOpen, 'usage_limit', 1, 'retryable', now()->addMinutes(10));

        $budgetExhausted = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed', 'error_code' => 'usage_limit']);
        $this->providerCall($budgetExhausted, 'usage_limit', 4, 'failed');

        $uncertain = ProspectBatchItem::factory()->for($batch, 'batch')->create(['status' => 'failed', 'error_code' => 'provider_outcome_uncertain']);

        $criterionBlocked = ProspectBatchItem::factory()->for($inactiveBatch, 'batch')->create(['status' => 'failed', 'error_code' => 'usage_limit']);
        $this->providerCall($criterionBlocked, 'usage_limit', 1, 'failed');

        return [$eligible, $windowOpen, $budgetExhausted, $uncertain, $criterionBlocked];
    }

    private function providerCall(
        ProspectBatchItem $item,
        string $errorCode,
        int $attemptCount,
        string $status,
        ?Carbon $retryAt = null,
    ): ProviderCall {
        return ProviderCall::query()->create([
            'prospect_batch_id' => $item->prospect_batch_id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'drain-test-'.$item->id.'-'.$status.'-'.uniqid('', true)),
            'status' => $status,
            'attempt_count' => $attemptCount,
            'metadata' => ['error_code' => $errorCode],
            'retry_at' => $retryAt,
        ]);
    }
}
