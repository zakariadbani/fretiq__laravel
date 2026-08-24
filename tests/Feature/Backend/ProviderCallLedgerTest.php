<?php

namespace Tests\Feature\Backend;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use App\Services\Providers\ProviderRequestException;
use App\Services\Providers\ProviderResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use ReflectionMethod;
use Tests\TestCase;

class ProviderCallLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_key_executes_transport_once_and_replays_settled_call(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $context = new ProviderCallContext(hash('sha256', 'once'), 1);
        $calls = 0;
        $transport = function () use (&$calls): ProviderResponse {
            $calls++;

            return new ProviderResponse(200, ['ok' => true]);
        };

        $first = $ledger->execute($context, 'hunter', 'domain_search', $transport);
        DB::transaction(fn () => $ledger->settle($first, 2, 1, ['filters_hash' => 'abc']));
        $second = $ledger->execute($context, 'hunter', 'domain_search', $transport);

        $this->assertTrue($second->replayed);
        $this->assertNull($second->response);
        $this->assertSame(1, $calls);
        $this->assertSame('succeeded', $second->call->status);
    }

    public function test_429_respects_retry_after_and_settles_consumption_once(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $context = new ProviderCallContext(hash('sha256', 'retry'), 2);

        try {
            $ledger->execute($context, 'hunter', 'domain_search', function (): never {
                throw ProviderRequestException::fromHttp(429, 'rate_limit', 91);
            });
            $this->fail('Expected retryable provider failure.');
        } catch (ProviderRequestException $exception) {
            $this->assertTrue($exception->retryable);
        }

        $call = ProviderCall::query()->sole();
        $this->assertSame('retryable', $call->status);
        $this->assertGreaterThanOrEqual(89, $call->retry_at->diffInSeconds(now(), true));
        $this->assertLessThanOrEqual(91, $call->retry_at->diffInSeconds(now(), true));
        $this->assertSame('rate_limit', $call->metadata['error_code']);
        $this->assertSame(0.0, (float) $call->consumed_units);
    }

    public function test_provider_failure_logs_safe_structured_diagnostics_after_the_ledger_persists_it(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        $idempotencyKey = hash('sha256', 'safe-structured-provider-failure-log');
        $context = new ProviderCallContext($idempotencyKey, 1, $batch->id, $item->id);
        Log::spy();

        try {
            app(ProviderCallLedger::class)->execute($context, 'hunter', 'company_enrichment', function (): never {
                throw ProviderRequestException::fromHttp(429, 'usage_limit', provider: 'hunter');
            });
            $this->fail('Expected terminal provider failure.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame('usage_limit', $exception->safeCode);
        }

        $call = ProviderCall::query()->sole();
        Log::shouldHaveReceived('warning')->once()->with('provider_call_failed', \Mockery::on(function (array $context) use ($call, $batch, $item, $idempotencyKey): bool {
            $this->assertSame($call->id, $context['provider_call_id']);
            $this->assertSame('hunter', $context['provider']);
            $this->assertSame('company_enrichment', $context['operation']);
            $this->assertSame($batch->id, $context['prospect_batch_id']);
            $this->assertSame($item->id, $context['prospect_batch_item_id']);
            $this->assertSame('failed', $context['status']);
            $this->assertSame(429, $context['http_status']);
            $this->assertSame('usage_limit', $context['error_code']);
            $this->assertFalse($context['retryable']);
            $this->assertNull($context['retry_after_seconds']);
            $this->assertSame(1, $context['attempt_count']);
            $this->assertSame([
                'provider_call_id',
                'provider',
                'operation',
                'prospect_batch_id',
                'prospect_batch_item_id',
                'status',
                'http_status',
                'error_code',
                'retryable',
                'retry_after_seconds',
                'attempt_count',
            ], array_keys($context));

            return true;
        }));
    }

    public function test_terminal_provider_errors_and_retryable_rate_limits_are_distinguished(): void
    {
        $this->assertFalse(ProviderRequestException::fromHttp(429, 'usage_limit', provider: 'hunter')->retryable);
        $this->assertTrue(ProviderRequestException::fromHttp(429, 'rate_limit', provider: 'serpapi')->retryable);
        $this->assertTrue(ProviderRequestException::fromHttp(403, 'rate_limit', provider: 'hunter')->retryable);
        $this->assertFalse(ProviderRequestException::fromHttp(403, 'rate_limit', provider: 'serpapi')->retryable);
        $this->assertFalse(ProviderRequestException::fromHttp(403, 'authorization_failed', provider: 'hunter')->retryable);
        $this->assertTrue(ProviderRequestException::fromHttp(503, 'unavailable')->retryable);
        $this->assertFalse(ProviderRequestException::fromHttp(422, 'invalid_request')->retryable);
    }

    public function test_retryable_failures_rethrow_the_effective_ledger_delay_and_stop_at_the_attempt_cap(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $context = new ProviderCallContext(hash('sha256', 'effective-backoff'), 1);

        foreach ([30, 120, 600] as $expectedDelay) {
            try {
                $ledger->execute($context, 'hunter', 'domain_search', function (): never {
                    throw ProviderRequestException::fromHttp(503, 'provider_unavailable', provider: 'hunter');
                });
                $this->fail('Expected a retryable provider failure.');
            } catch (ProviderRequestException $exception) {
                $this->assertTrue($exception->retryable);
                $this->assertSame($expectedDelay, $exception->retryAfterSeconds);
            }

            ProviderCall::query()->where('idempotency_key', $context->idempotencyKey)->update(['retry_at' => now()->subSecond()]);
        }

        try {
            $ledger->execute($context, 'hunter', 'domain_search', function (): never {
                throw ProviderRequestException::fromHttp(503, 'provider_unavailable', provider: 'hunter');
            });
            $this->fail('Expected the provider call to become terminal at the attempt cap.');
        } catch (ProviderRequestException $exception) {
            $this->assertFalse($exception->retryable);
            $this->assertNull($exception->retryAfterSeconds);
        }

        $call = ProviderCall::query()->where('idempotency_key', $context->idempotencyKey)->sole();
        $this->assertSame('failed', $call->status);
        $this->assertSame(4, $call->attempt_count);
    }

    public function test_metadata_allowlist_drops_api_key_full_url_raw_response_and_email(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $execution = $ledger->execute(
            new ProviderCallContext(hash('sha256', 'metadata'), 1),
            'serpapi',
            'google_maps',
            fn (): ProviderResponse => new ProviderResponse(200, [], [
                'page' => 2,
                'api_key' => 'secret',
                'nested' => ['email' => 'person@example.test', 'filters_hash' => 'safe'],
                'raw_response' => ['too' => 'much'],
            ])
        );

        $call = DB::transaction(fn () => $ledger->settle($execution, 0, 1, [
            'offset' => 50,
            'full_url' => 'https://secret.test',
            'email' => 'person@example.test',
        ]));

        $this->assertSame(['page' => 2, 'nested' => ['filters_hash' => 'safe'], 'offset' => 50], $call->metadata);
    }

    public function test_metadata_drops_sensitive_values_and_normalizes_unsafe_error_codes(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $safeHash = hash('sha256', 'filters');
        $execution = $ledger->execute(
            new ProviderCallContext(hash('sha256', 'sensitive-values'), 1),
            'hunter',
            'domain_search',
            fn (): ProviderResponse => new ProviderResponse(200, [], [
                'filters_hash' => $safeHash,
                'status' => 'valid',
                'source' => 'hunter',
                'reason' => 'person@example.test',
                'provider_status' => 'Bearer secret-token',
                'nested' => [
                    'status' => 'https://secret.test/path?api_key=hidden',
                    'reason' => "unsafe\ncontrol",
                    'query_hash' => hash('sha256', 'query'),
                ],
            ])
        );

        $call = DB::transaction(fn () => $ledger->settle($execution, 0, 1));
        $this->assertEquals([
            'filters_hash' => $safeHash,
            'status' => 'valid',
            'source' => 'hunter',
            'nested' => ['query_hash' => hash('sha256', 'query')],
        ], $call->metadata);

        try {
            $ledger->execute(
                new ProviderCallContext(hash('sha256', 'unsafe-error-code'), 1),
                'serpapi',
                'google_maps',
                fn (): ProviderResponse => new ProviderResponse(422, [], ['error_code' => 'https://secret.test?api_key=hidden'])
            );
            $this->fail('Expected a terminal provider error.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame('provider_http_error', $exception->safeCode);
        }

        $failed = ProviderCall::query()->where('status', 'failed')->sole();
        $this->assertSame('provider_http_error', $failed->metadata['error_code']);
    }

    public function test_settlement_rounds_consumption_and_rejects_decimal_overflow(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $roundedExecution = $ledger->execute(
            new ProviderCallContext(hash('sha256', 'rounded-consumption'), 1),
            'hunter',
            'domain_search',
            fn (): ProviderResponse => new ProviderResponse(200, [])
        );
        $rounded = DB::transaction(fn () => $ledger->settle($roundedExecution, 0, 0.005));
        $this->assertSame(0.01, (float) $rounded->consumed_units);

        $overflowExecution = $ledger->execute(
            new ProviderCallContext(hash('sha256', 'overflow-consumption'), 1),
            'hunter',
            'domain_search',
            fn (): ProviderResponse => new ProviderResponse(200, [])
        );

        $this->expectException(\InvalidArgumentException::class);
        DB::transaction(fn () => $ledger->settle($overflowExecution, 0, 10000000000.0));
    }

    public function test_unique_violation_detection_does_not_swallow_unrelated_query_errors(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $method = new ReflectionMethod($ledger, 'isUniqueConstraintViolation');

        $pdo = new PDOException('database unavailable');
        $unrelated = new QueryException('mysql', 'select 1', [], $pdo);

        $this->assertFalse($method->invoke($ledger, $unrelated));
    }

    public function test_running_call_is_not_reissued_after_uncertain_crash_window(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $context = new ProviderCallContext(hash('sha256', 'running'), 1);
        $first = $ledger->execute($context, 'hunter', 'domain_search', fn (): ProviderResponse => new ProviderResponse(200, []));

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('provider_call_in_progress');
        $ledger->execute($context, 'hunter', 'domain_search', fn (): ProviderResponse => new ProviderResponse(200, []));
        $this->assertFalse($first->replayed);
    }

    public function test_operator_retry_reopens_only_the_matching_confirmed_failure(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        $safe = ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'company_enrichment',
            'idempotency_key' => hash('sha256', 'safe-operator-retry'),
            'status' => 'failed',
            'attempt_count' => 1,
            'metadata' => ['error_code' => 'usage_limit', 'retryable' => false],
        ]);
        $unsafe = ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'unsafe-operator-retry'),
            'status' => 'failed',
            'attempt_count' => 1,
            'metadata' => ['error_code' => 'permission_denied', 'retryable' => false],
        ]);

        $authorized = app(ProviderCallLedger::class)
            ->authorizeKnownFailureRetryForItem($item->id, 'too_many_requests');

        $this->assertSame(1, $authorized);
        $this->assertSame('retryable', $safe->fresh()->status);
        $this->assertSame('failed', $unsafe->fresh()->status);
        $this->assertSame('usage_limit', $safe->fresh()->metadata['error_code']);
        $this->assertSame('operator_retry_authorized', $safe->fresh()->metadata['reason']);
    }

    public function test_operator_retry_rearms_the_budget_for_an_all_exhausted_transient_item(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        $maxed = ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'serpapi',
            'operation' => 'google_maps',
            'idempotency_key' => hash('sha256', 'rearm-maxed'),
            'status' => 'failed',
            'attempt_count' => 4,
            'metadata' => ['error_code' => 'rate_limit', 'retryable' => false],
        ]);

        $authorized = app(ProviderCallLedger::class)
            ->authorizeKnownFailureRetryForItem($item->id, 'rate_limit');

        $this->assertSame(1, $authorized);
        $fresh = $maxed->fresh();
        $this->assertSame('retryable', $fresh->status);
        $this->assertSame(0, $fresh->attempt_count);
        $this->assertSame('operator_budget_rearm', $fresh->metadata['reason']);
    }

    public function test_operator_retry_still_throws_exhausted_for_an_all_exhausted_permanent_item(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'serpapi',
            'operation' => 'google_maps',
            'idempotency_key' => hash('sha256', 'no-rearm-maxed'),
            'status' => 'failed',
            'attempt_count' => 4,
            'metadata' => ['error_code' => 'pagination_error', 'retryable' => false],
        ]);

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('provider_call_retry_exhausted');
        app(ProviderCallLedger::class)->authorizeKnownFailureRetryForItem($item->id, 'pagination_error');
    }

    public function test_retry_headroom_reports_max_remaining_attempts_across_matching_failed_calls(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        // attempt_count=1 of 4 — matches the live-state fact this feature was built
        // against (3 attempts of headroom remaining).
        ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'headroom-fresh'),
            'status' => 'failed',
            'attempt_count' => 1,
            'metadata' => ['error_code' => 'usage_limit'],
        ]);

        $ledger = app(ProviderCallLedger::class);

        $this->assertSame(3, $ledger->retryHeadroomForItem($item->id, 'too_many_requests'));
        // Unknown / unrecognised error codes are not ledger-gated at all.
        $this->assertNull($ledger->retryHeadroomForItem($item->id, 'missing_domain'));
    }

    public function test_retry_headroom_is_zero_only_when_every_matching_call_is_maxed_out(): void
    {
        // Uses a permanent (non-re-armable) error code — usage_limit is now
        // manually re-armable when fully exhausted (returns MAX_ATTEMPTS
        // instead of 0), which is covered separately by
        // test_retry_headroom_rearms_to_full_budget_for_an_all_exhausted_transient_item.
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'headroom-maxed'),
            'status' => 'failed',
            'attempt_count' => 4,
            'metadata' => ['error_code' => 'pagination_error'],
        ]);
        $ledger = app(ProviderCallLedger::class);

        $this->assertSame(0, $ledger->retryHeadroomForItem($item->id, 'pagination_error'));

        // A second matching call that still has headroom flips the item back
        // to retryable, mirroring authorizeKnownFailureRetryForItem()'s own
        // every()-must-all-be-maxed exhaustion rule.
        ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'headroom-fresh-sibling'),
            'status' => 'failed',
            'attempt_count' => 2,
            'metadata' => ['error_code' => 'pagination_error'],
        ]);

        $this->assertSame(2, $ledger->retryHeadroomForItem($item->id, 'pagination_error'));
    }

    public function test_retry_headroom_rearms_to_full_budget_for_an_all_exhausted_transient_item(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'headroom-rearm-maxed'),
            'status' => 'failed',
            'attempt_count' => 4,
            'metadata' => ['error_code' => 'rate_limit'],
        ]);
        $ledger = app(ProviderCallLedger::class);

        $this->assertSame(4, $ledger->retryHeadroomForItem($item->id, 'rate_limit'));
    }

    public function test_open_retry_window_is_detected_regardless_of_call_status_and_clears_once_retry_at_passes(): void
    {
        $batch = ProspectBatch::factory()->create();
        $item = ProspectBatchItem::factory()->for($batch, 'batch')->create();
        $call = ProviderCall::query()->create([
            'prospect_batch_id' => $batch->id,
            'prospect_batch_item_id' => $item->id,
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => hash('sha256', 'open-window'),
            'status' => 'retryable',
            'attempt_count' => 1,
            'retry_at' => now()->addMinutes(5),
        ]);
        $ledger = app(ProviderCallLedger::class);

        $this->assertTrue($ledger->hasOpenRetryWindow($item->id));

        $call->update(['retry_at' => now()->subSecond()]);

        $this->assertFalse($ledger->hasOpenRetryWindow($item->id));
    }

    public function test_pending_logical_call_can_be_polled_under_the_same_key_without_a_second_reservation(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $context = new ProviderCallContext(hash('sha256', 'pending'), 3);
        $first = $ledger->execute($context, 'hunter', 'email_verifier', fn (): ProviderResponse => new ProviderResponse(202, []));
        $ledger->markPending($first, now()->subSecond(), ['page' => 1]);
        $this->travel(2)->seconds();
        $second = $ledger->execute($context, 'hunter', 'email_verifier', fn (): ProviderResponse => new ProviderResponse(200, []));

        $this->assertFalse($second->replayed);
        $this->assertSame($first->call->id, $second->call->id);
        $this->assertSame(2, $second->call->attempt_count);
        $this->assertSame(3.0, (float) $second->call->reserved_units);
    }

    public function test_context_rejects_any_idempotency_key_that_is_not_exactly_64_hex_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderCallContext(strtoupper(hash('sha256', 'uppercase')), 1);
    }

    public function test_context_normalizes_reservation_and_rejects_unsafe_identifiers_and_response_invariants(): void
    {
        $this->assertSame(0.01, (new ProviderCallContext(hash('sha256', 'decimal'), 0.005))->reservedUnits);

        foreach ([
            fn () => new ProviderCallContext(hash('sha256', 'engine'), 1, engine: 'Google Maps'),
            fn () => app(ProviderCallLedger::class)->execute(new ProviderCallContext(hash('sha256', 'operation'), 1), 'Hunter', 'domain search', fn () => new ProviderResponse(200, [])),
            fn () => new ProviderResponse(99, []),
            fn () => new ProviderResponse(200, [], requestId: "bad\nrequest"),
        ] as $invalid) {
            try {
                $invalid();
                $this->fail('Expected an invalid provider contract to be rejected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_transport_is_called_outside_database_transaction_and_context_mismatch_never_calls_transport(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $context = new ProviderCallContext(hash('sha256', 'context'), 1, engine: 'google');
        $baselineTransactionLevel = DB::transactionLevel();
        $transactionLevel = null;
        $ledger->execute($context, 'hunter', 'domain_search', function () use (&$transactionLevel): ProviderResponse {
            $transactionLevel = DB::transactionLevel();

            return new ProviderResponse(200, []);
        });
        // RefreshDatabase keeps an outer test transaction open; the ledger adds no transaction around transport.
        $this->assertSame($baselineTransactionLevel, $transactionLevel);

        $calls = 0;
        try {
            $ledger->execute(new ProviderCallContext($context->idempotencyKey, 1, engine: 'bing'), 'hunter', 'domain_search', function () use (&$calls): ProviderResponse {
                $calls++;

                return new ProviderResponse(200, []);
            });
            $this->fail('Expected context mismatch.');
        } catch (ProviderRequestException $exception) {
            $this->assertSame('provider_call_context_mismatch', $exception->safeCode);
        }
        $this->assertSame(0, $calls);
    }

    public function test_same_digest_is_namespaced_by_provider_and_operation(): void
    {
        $ledger = app(ProviderCallLedger::class);
        $context = new ProviderCallContext(hash('sha256', 'shared-digest'), 1);
        $transports = 0;

        $hunter = $ledger->execute(
            $context,
            'hunter',
            'domain_search',
            function () use (&$transports): ProviderResponse {
                $transports++;

                return new ProviderResponse(200, []);
            },
        );
        DB::transaction(fn () => $ledger->settle($hunter, 0, 1));

        $serp = $ledger->execute(
            $context,
            'serpapi',
            'search',
            function () use (&$transports): ProviderResponse {
                $transports++;

                return new ProviderResponse(200, []);
            },
        );
        DB::transaction(fn () => $ledger->settle($serp, 0, 1));

        $this->assertSame(2, $transports);
        $this->assertDatabaseCount('provider_calls', 2);
        $this->assertDatabaseHas('provider_calls', [
            'provider' => 'hunter',
            'operation' => 'domain_search',
            'idempotency_key' => $context->idempotencyKey,
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('provider_calls', [
            'provider' => 'serpapi',
            'operation' => 'search',
            'idempotency_key' => $context->idempotencyKey,
            'status' => 'succeeded',
        ]);
    }
}
