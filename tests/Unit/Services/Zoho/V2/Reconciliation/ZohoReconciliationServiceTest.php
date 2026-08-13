<?php

namespace Tests\Unit\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoSyncFailure;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\V2\Mappers\QuoteItemMapper;
use App\Services\Zoho\V2\Mappers\QuoteMapper;
use App\Services\Zoho\V2\Reconciliation\ZohoDeletedRecordsReconciler;
use App\Services\Zoho\V2\Reconciliation\ZohoReconciliationService;
use App\Services\Zoho\V2\Transport\Sleeper;
use App\Services\Zoho\V2\Transport\ZohoThrottleUnavailableException;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('zoho-v2.reconciliation.quote_paged_verification', false);
    }

    public function test_deleted_records_are_retained_as_idempotent_tombstones(): void
    {
        $lead = $this->lead('lead-deleted');
        $transport = new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [['id' => 'lead-deleted', 'deleted_time' => '2026-08-09T10:00:00+00:00', 'type' => 'recycle']], 'info' => ['more_records' => false]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        $first = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 45, 'deleted-test');
        $second = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 45, 'deleted-test');

        $this->assertSame(1, $first['tombstoned']);
        $this->assertSame(0, $second['tombstoned']);
        $this->assertNotNull($lead->fresh()->zoho_deleted_at);
        $this->assertSame('recycle', $lead->fresh()->zoho_deletion_type);
        $this->assertSame(1, ZohoLead::query()->tombstoned()->count());
    }

    public function test_no_deleted_records_204_completes_without_pagination_metadata(): void
    {
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['status' => 204],
        ]));

        $result = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 105, 'no-deleted-records');

        $this->assertTrue($result['complete']);
        $this->assertFalse($result['degraded']);
        $this->assertSame(204, $result['status']);
        $this->assertSame(1, $result['pages']);
        $this->assertSame(1, $result['api_requests']);
        $this->assertSame(0, $result['seen']);
        $this->assertDatabaseMissing('zoho_sync_failures', [
            'module' => 'leads',
            'failure_kind' => 'deletion_scan',
        ]);
    }

    public function test_active_scan_waits_for_the_next_capacity_window_instead_of_restarting(): void
    {
        $transport = new ReconciliationCapacityTransport([
            '/Leads/deleted' => [
                ['status' => 204],
            ],
            '/Leads' => [
                ['status' => 0, 'error_code' => 'throttle_unavailable'],
                ['data' => [], 'info' => ['more_records' => false]],
            ],
        ]);
        $sleeper = new ReconciliationCapacitySleeper;
        $this->app->instance(ZohoTransport::class, $transport);
        $this->app->instance(Sleeper::class, $sleeper);

        $result = app(ZohoReconciliationService::class)->reconcile('leads', 106, 'capacity-scan');

        $this->assertTrue($result['complete']);
        $this->assertFalse($result['degraded']);
        $this->assertSame(1, $result['pages']);
        $this->assertSame(2, $transport->countCalls('/Leads'));
        $this->assertNotEmpty($sleeper->milliseconds);
        $this->assertLessThanOrEqual(10_000, max($sleeper->milliseconds));
        $this->assertGreaterThan(0, array_sum($sleeper->milliseconds));
    }

    public function test_record_hydration_waits_for_capacity_without_advancing_or_quarantining(): void
    {
        $transport = new ReconciliationCapacityTransport([
            '/Leads/deleted' => [
                ['status' => 204],
            ],
            '/Leads' => [
                ['data' => [['id' => 'lead-capacity-hydration']], 'info' => ['more_records' => false]],
            ],
        ]);
        $sleeper = new ReconciliationCapacitySleeper;
        $this->app->instance(ZohoTransport::class, $transport);
        $this->app->instance(Sleeper::class, $sleeper);
        $attempts = 0;

        $result = app(ZohoReconciliationService::class)->reconcile(
            'leads',
            107,
            'capacity-hydration',
            hydrateRecord: function (string $id) use (&$attempts): array {
                $attempts++;
                if ($attempts === 1) {
                    throw new ZohoThrottleUnavailableException;
                }
                $this->lead($id);

                return ['successful' => true, 'created' => 1, 'updated' => 0, 'unchanged' => 0, 'quarantined' => 0, 'api_requests' => 1];
            },
        );

        $this->assertTrue($result['complete']);
        $this->assertFalse($result['degraded']);
        $this->assertSame(2, $attempts);
        $this->assertSame(1, $result['hydration']['attempted']);
        $this->assertSame(0, $result['hydration']['quarantined']);
        $this->assertNotEmpty($sleeper->milliseconds);
        $this->assertLessThanOrEqual(10_000, max($sleeper->milliseconds));
        $this->assertGreaterThan(0, array_sum($sleeper->milliseconds));
        $this->assertDatabaseMissing('zoho_sync_failures', ['module' => 'leads', 'resolved_at' => null]);
    }

    public function test_deletion_state_advances_to_permanent_but_never_regresses(): void
    {
        $lead = $this->lead('lead-lifecycle');
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [['id' => $lead->zoho_id, 'deleted_time' => '2026-08-09T10:00:00+00:00', 'type' => 'recycle']], 'info' => ['more_records' => false]],
        ]));
        app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 101, 'recycle');

        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [['id' => $lead->zoho_id, 'deleted_time' => '2026-08-10T10:00:00+00:00', 'type' => 'permanent']], 'info' => ['more_records' => false]],
        ]));
        $permanent = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 102, 'permanent');

        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [['id' => $lead->zoho_id, 'deleted_time' => '2026-08-11T10:00:00+00:00', 'type' => 'recycle']], 'info' => ['more_records' => false]],
        ]));
        $regression = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 103, 'regression');

        $lead->refresh();
        $this->assertSame(0, $permanent['tombstoned']);
        $this->assertSame(0, $regression['tombstoned']);
        $this->assertSame('permanent', $lead->zoho_deletion_type);
        $this->assertSame('2026-08-10 10:00:00', $lead->zoho_deleted_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(103, $lead->sync_batch_id);
    }

    public function test_one_malformed_deleted_row_is_quarantined_without_losing_valid_tombstones(): void
    {
        $invalid = $this->lead('lead-invalid-deletion');
        $valid = $this->lead('lead-valid-deletion');
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [
                ['id' => $invalid->zoho_id, 'deleted_time' => 'not-a-time', 'type' => 'recycle'],
                ['id' => $valid->zoho_id, 'deleted_time' => '2026-08-09T10:00:00+00:00', 'type' => 'recycle'],
            ], 'info' => ['more_records' => false]],
        ]));

        $result = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 104, 'malformed-row');

        $this->assertTrue($result['complete']);
        $this->assertTrue($result['degraded']);
        $this->assertSame(1, $result['quarantined']);
        $this->assertNull($invalid->fresh()->zoho_deleted_at);
        $this->assertNotNull($valid->fresh()->zoho_deleted_at);
        $this->assertDatabaseHas('zoho_sync_failures', [
            'module' => 'leads',
            'zoho_id' => 'lead-invalid-deletion',
            'failure_kind' => 'deletion_record',
            'resolved_at' => null,
        ]);
    }

    public function test_deleted_quote_tombstones_its_current_items_in_the_same_pass(): void
    {
        $quote = ZohoQuote::query()->create(['zoho_id' => 'quote-deleted', 'raw_payload' => [], 'payload_hash' => hash('sha256', 'quote-deleted')]);
        $item = ZohoQuoteItem::query()->create([
            'zoho_quote_id' => $quote->zoho_id,
            'zoho_line_item_id' => 'quote-line-current',
            'raw_payload' => [],
            'payload_hash' => hash('sha256', 'quote-line-current'),
        ]);
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Quotes/deleted' => ['data' => [['id' => $quote->zoho_id, 'deleted_time' => '2026-08-09T10:00:00+00:00', 'type' => 'recycle']], 'info' => ['more_records' => false]],
        ]));

        $result = app(ZohoDeletedRecordsReconciler::class)->reconcile('quotes', 46, 'deleted-quote');

        $this->assertSame(1, $result['tombstoned']);
        $this->assertNotNull($quote->fresh()->zoho_deleted_at);
        $this->assertNotNull($item->fresh()->zoho_deleted_at);
        $this->assertSame('parent_quote_deleted', $item->fresh()->zoho_deletion_type);
        $this->assertSame(46, $item->fresh()->sync_batch_id);
    }

    public function test_deleted_record_mutation_is_rejected_after_the_module_fence_is_lost(): void
    {
        $lead = $this->lead('lead-protected-by-fence');
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => [
                'data' => [[
                    'id' => $lead->zoho_id,
                    'deleted_time' => '2026-08-09T10:00:00+00:00',
                    'type' => 'recycle',
                ]],
                'info' => ['more_records' => false],
            ],
        ]));

        $result = app(ZohoDeletedRecordsReconciler::class)->reconcile(
            'leads',
            47,
            'lost-deletion-fence',
            mutationFence: static fn (): bool => false,
        );

        $this->assertFalse($result['complete']);
        $this->assertTrue($result['lease_lost']);
        $this->assertNull($lead->fresh()->zoho_deleted_at);
        $this->assertDatabaseMissing('zoho_sync_failures', [
            'module' => 'leads',
            'zoho_id' => $lead->zoho_id,
            'failure_kind' => 'deletion_record',
        ]);
    }

    public function test_reconciliation_never_tombstones_active_ids_and_records_safe_discrepancies(): void
    {
        $this->lead('lead-present');
        $transport = new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [['id' => 'lead-present'], ['id' => 'lead-remote-only']], 'info' => ['more_records' => false]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        $result = app(ZohoReconciliationService::class)->reconcile('leads', 77, 'reconcile-test');
        $failure = ZohoSyncFailure::query()->where('failure_kind', 'reconciliation')->sole();

        $this->assertSame(2, $result['remote_count']);
        $this->assertSame(1, $result['local_count']);
        $this->assertSame(1, $result['missing_count']);
        $this->assertNull(ZohoLead::query()->where('zoho_id', 'lead-present')->value('zoho_deleted_at'));
        $this->assertSame(2, $failure->context['remote_count']);
        $this->assertSame(1, $failure->context['local_count']);
        $this->assertSame(1, $failure->context['missing_count']);
        $this->assertStringNotContainsString('lead-remote-only', $failure->error_summary);
        $this->assertStringNotContainsString('lead-remote-only', json_encode($failure->context));
    }

    public function test_complete_scan_repairs_missing_records_and_refreshes_last_seen_after_completion(): void
    {
        $present = $this->lead('lead-present-for-repair');
        $present->update(['last_seen_at' => now()->subDays(5)]);
        $before = $present->fresh()->last_seen_at;
        $transport = new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [
                ['id' => 'lead-present-for-repair'],
                ['id' => 'lead-missing-for-repair'],
            ], 'info' => ['more_records' => false]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);
        $hydrated = [];

        $result = app(ZohoReconciliationService::class)->reconcile(
            'leads',
            770,
            'repair-missing',
            function (string $id) use (&$hydrated): array {
                $hydrated[] = $id;
                $this->lead($id);

                return [
                    'successful' => true,
                    'created' => 1,
                    'updated' => 0,
                    'unchanged' => 0,
                    'quarantined' => 0,
                    'api_requests' => 1,
                ];
            },
            static fn (): bool => true,
        );

        $this->assertSame(['lead-missing-for-repair'], $hydrated);
        $this->assertSame('healthy', $result['status']);
        $this->assertSame(0, $result['missing_count']);
        $this->assertSame(2, $result['local_count']);
        $this->assertSame(1, $result['repaired_count']);
        $this->assertSame(1, $result['hydration']['created']);
        $this->assertTrue($present->fresh()->last_seen_at->greaterThan($before));
        $this->assertNotNull(ZohoLead::query()->where('zoho_id', 'lead-missing-for-repair')->value('last_seen_at'));
    }

    public function test_quotes_full_sweep_hydrates_every_remote_id_but_other_modules_only_repair_missing_ids(): void
    {
        foreach (['quote-sweep-1', 'quote-sweep-2'] as $id) {
            ZohoQuote::query()->create([
                'zoho_id' => $id,
                'raw_payload' => [],
                'payload_hash' => hash('sha256', $id),
            ]);
        }
        $transport = new ReconciliationTransport([
            '/Quotes/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Quotes' => ['data' => [
                ['id' => 'quote-sweep-1'],
                ['id' => 'quote-sweep-2'],
            ], 'info' => ['more_records' => false]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);
        $hydrated = [];

        $result = app(ZohoReconciliationService::class)->reconcile(
            'quotes',
            771,
            'quote-full-sweep',
            function (string $id) use (&$hydrated): array {
                $hydrated[] = $id;

                return [
                    'successful' => true,
                    'created' => 0,
                    'updated' => 0,
                    'unchanged' => 1,
                    'quarantined' => 0,
                    'api_requests' => 1,
                ];
            },
            static fn (): bool => true,
        );

        $this->assertSame(['quote-sweep-1', 'quote-sweep-2'], $hydrated);
        $this->assertSame(2, $result['swept_count']);
        $this->assertSame(2, $result['hydration']['unchanged']);
        $this->assertSame(2, $result['hydration']['api_requests']);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_paged_quote_verification_skips_specific_reads_when_parent_and_items_match(): void
    {
        config()->set('zoho-v2.reconciliation.quote_paged_verification', true);
        $quoteId = '600000000000000101';
        $itemId = '600000000000000201';
        $parent = [
            'id' => $quoteId,
            'Subject' => 'Same quote',
            'Currency' => 'EUR',
            'Exchange_Rate' => '1',
            'Valid_Till' => '2026-08-30',
            'Date_de_Cotation' => '2026-08-01',
            'Type_de_Transport' => ['Express', 'Road'],
            'Created_Time' => '2026-08-01T08:00:00+00:00',
            'Modified_Time' => '2026-08-02T09:00:00+00:00',
        ];
        $item = [
            'id' => $itemId,
            'Parent_Id' => ['id' => $quoteId],
            'Quantity' => 1,
            'Prix_1x40' => '10',
            'Prix_Total' => '10',
            'Created_Time' => '2026-08-01T08:00:00+00:00',
            'Modified_Time' => '2026-08-02T09:00:00+00:00',
        ];
        $specificParent = array_replace($parent, ['Type_de_Transport' => ['Road', 'Express']]);
        $specificItem = array_replace($item, [
            'Quantity' => '1.0000',
            'Prix_1x40' => '10.00',
            'Prix_Total' => '10.00',
            'Modified_Time' => '2026-08-03T09:00:00+00:00',
            '$layout_id' => 'specific-read-only-metadata',
        ]);
        $context = ['seen_at' => now()->subDay(), 'synced_at' => now()->subDay()];
        ZohoQuote::query()->create(app(QuoteMapper::class)->map(
            $specificParent + ['Quoted_Items' => [$specificItem]],
            $context,
        ));
        ZohoQuoteItem::query()->create(app(QuoteItemMapper::class)->map($specificItem, $context + [
            'quote_zoho_id' => $quoteId,
            'currency_code' => 'EUR',
            'sequence' => 1,
        ]));
        $this->assertNotSame(
            app(QuoteItemMapper::class)->payloadHash($item),
            app(QuoteItemMapper::class)->payloadHash($specificItem),
        );
        $transport = new ReconciliationTransport([
            '/Quotes/deleted' => ['status' => 204],
            '/Quotes' => ['data' => [$parent], 'info' => ['more_records' => false]],
            '/Quoted_Items' => ['data' => [$item], 'info' => ['more_records' => false]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);
        $specificReads = 0;

        $result = app(ZohoReconciliationService::class)->reconcile(
            'quotes',
            880,
            'paged-quote-verification',
            hydrateRecord: static function (string $id) use (&$specificReads): array {
                $specificReads++;

                return ['successful' => true, 'created' => 0, 'updated' => 0, 'unchanged' => 1, 'quarantined' => 0, 'api_requests' => 1];
            },
            heartbeat: static fn (): bool => true,
            mutationFence: static fn (): bool => true,
        );

        $this->assertSame(0, $specificReads);
        $this->assertTrue($result['fast_path']);
        $this->assertTrue($result['complete']);
        $this->assertSame('healthy', $result['status']);
        $this->assertSame(1, $result['swept_count']);
        $this->assertContains('/Quoted_Items', array_column($transport->calls, 'path'));
        $this->assertSame(880, ZohoQuote::query()->where('zoho_id', $quoteId)->value('sync_batch_id'));
        $this->assertSame(880, ZohoQuoteItem::query()->where('zoho_line_item_id', $itemId)->value('sync_batch_id'));
    }

    public function test_paged_quote_verification_hydrates_only_the_parent_of_a_changed_item(): void
    {
        config()->set('zoho-v2.reconciliation.quote_paged_verification', true);
        $quoteId = '600000000000000102';
        $itemId = '600000000000000202';
        $parent = ['id' => $quoteId, 'Subject' => 'Same quote', 'Currency' => 'EUR'];
        $oldItem = ['id' => $itemId, 'Parent_Id' => ['id' => $quoteId], 'Quantity' => 1, 'Prix_Total' => '10'];
        $newItem = ['id' => $itemId, 'Parent_Id' => ['id' => $quoteId], 'Quantity' => 1, 'Prix_Total' => '20'];
        ZohoQuote::query()->create([
            'zoho_id' => $quoteId,
            'subject' => 'Same quote',
            'currency_code' => 'EUR',
            'raw_payload' => $parent + ['Quoted_Items' => [$oldItem]],
            'payload_hash' => hash('sha256', 'changed-item-parent'),
            'last_seen_at' => now()->subDay(),
            'last_synced_at' => now()->subDay(),
        ]);
        ZohoQuoteItem::query()->create([
            'zoho_quote_id' => $quoteId,
            'zoho_line_item_id' => $itemId,
            'sequence' => 1,
            'currency_code' => 'EUR',
            'raw_payload' => $oldItem,
            'payload_hash' => app(QuoteItemMapper::class)->payloadHash($oldItem),
            'last_seen_at' => now()->subDay(),
            'last_synced_at' => now()->subDay(),
        ]);
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Quotes/deleted' => ['status' => 204],
            '/Quotes' => ['data' => [$parent], 'info' => ['more_records' => false]],
            '/Quoted_Items' => ['data' => [$newItem], 'info' => ['more_records' => false]],
        ]));
        $specificIds = [];

        $result = app(ZohoReconciliationService::class)->reconcile(
            'quotes',
            881,
            'paged-quote-item-drift',
            hydrateRecord: function (string $id) use (&$specificIds, $quoteId, $itemId, $newItem): array {
                $specificIds[] = $id;
                ZohoQuote::query()->where('zoho_id', $quoteId)->update(['sync_batch_id' => 881]);
                ZohoQuoteItem::query()->where('zoho_line_item_id', $itemId)->update([
                    'raw_payload' => json_encode($newItem, JSON_THROW_ON_ERROR),
                    'payload_hash' => app(QuoteItemMapper::class)->payloadHash($newItem),
                    'sync_batch_id' => 881,
                ]);

                return ['successful' => true, 'created' => 0, 'updated' => 1, 'unchanged' => 0, 'quarantined' => 0, 'api_requests' => 1];
            },
            heartbeat: static fn (): bool => true,
            mutationFence: static fn (): bool => true,
        );

        $this->assertSame([$quoteId], $specificIds);
        $this->assertTrue($result['complete']);
        $this->assertFalse($result['degraded']);
        $this->assertSame(1, $result['hydration']['updated']);
        $this->assertSame(1, $result['hydration']['attempted']);
    }

    public function test_paged_quote_verification_consumes_parent_and_item_page_tokens(): void
    {
        config()->set('zoho-v2.reconciliation.quote_paged_verification', true);
        $parents = [
            ['id' => '600000000000000111', 'Subject' => 'First quote', 'Currency' => 'EUR'],
            ['id' => '600000000000000112', 'Subject' => 'Second quote', 'Currency' => 'EUR'],
        ];
        $items = [
            ['id' => '600000000000000211', 'Parent_Id' => ['id' => $parents[0]['id']], 'Quantity' => 1, 'Prix_Total' => '10'],
            ['id' => '600000000000000212', 'Parent_Id' => ['id' => $parents[1]['id']], 'Quantity' => 2, 'Prix_Total' => '20'],
        ];
        $context = ['seen_at' => now()->subDay(), 'synced_at' => now()->subDay()];
        foreach ($parents as $index => $parent) {
            ZohoQuote::query()->create(app(QuoteMapper::class)->map($parent, $context));
            ZohoQuoteItem::query()->create(app(QuoteItemMapper::class)->map($items[$index], $context + [
                'quote_zoho_id' => $parent['id'],
                'currency_code' => 'EUR',
                'sequence' => 1,
            ]));
        }
        $transport = new ReconciliationTransport([
            '/Quotes/deleted' => ['status' => 204],
            '/Quotes' => ['data' => [$parents[0]], 'info' => ['more_records' => true, 'next_page_token' => 'quote-page-2']],
            '/Quotes|token:quote-page-2' => ['data' => [$parents[1]], 'info' => ['more_records' => false]],
            '/Quoted_Items' => ['data' => [$items[0]], 'info' => ['more_records' => true, 'next_page_token' => 'item-page-2']],
            '/Quoted_Items|token:item-page-2' => ['data' => [$items[1]], 'info' => ['more_records' => false]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);
        $specificReads = 0;

        $result = app(ZohoReconciliationService::class)->reconcile(
            'quotes',
            882,
            'paged-quote-pagination',
            hydrateRecord: static function () use (&$specificReads): array {
                $specificReads++;

                return ['successful' => true, 'created' => 0, 'updated' => 0, 'unchanged' => 1, 'quarantined' => 0, 'api_requests' => 1];
            },
            heartbeat: static fn (): bool => true,
            mutationFence: static fn (): bool => true,
        );

        $this->assertSame(0, $specificReads);
        $this->assertSame(['quote-page-2', 'item-page-2'], $transport->pageTokens);
        $this->assertSame(4, $result['pages']);
        $this->assertSame(5, $result['api_requests']);
        $this->assertSame(2, $result['swept_count']);
        $this->assertSame('healthy', $result['status']);
    }

    public function test_paged_quote_verification_reverses_direction_at_the_provider_record_limit(): void
    {
        config()->set('zoho-v2.reconciliation.quote_paged_verification', true);
        config()->set('zoho-v2.reconciliation.quote_page_token_record_limit', 2);
        $quoteId = '600000000000000121';
        $parent = ['id' => $quoteId, 'Subject' => 'Large quote', 'Currency' => 'EUR'];
        $items = collect(range(1, 3))->map(static fn (int $index): array => [
            'id' => '60000000000000022'.$index,
            'Parent_Id' => ['id' => $quoteId],
            'Quantity' => $index,
            'Prix_Total' => (string) ($index * 10),
        ])->all();
        $context = ['seen_at' => now()->subDay(), 'synced_at' => now()->subDay()];
        ZohoQuote::query()->create(app(QuoteMapper::class)->map($parent, $context));
        foreach ($items as $sequence => $item) {
            ZohoQuoteItem::query()->create(app(QuoteItemMapper::class)->map($item, $context + [
                'quote_zoho_id' => $quoteId,
                'currency_code' => 'EUR',
                'sequence' => $sequence + 1,
            ]));
        }
        $transport = new ReconciliationTransport([
            '/Quotes/deleted' => ['status' => 204],
            '/Quotes' => ['data' => [$parent], 'info' => ['more_records' => false]],
            '/Quoted_Items|sort:desc' => ['data' => [$items[2], $items[1]], 'info' => ['more_records' => true, 'next_page_token' => 'unused-desc-token']],
            '/Quoted_Items|sort:asc' => ['data' => [$items[0]], 'info' => ['more_records' => true, 'next_page_token' => 'asc-page-2']],
            '/Quoted_Items|sort:asc|token:asc-page-2' => ['data' => [$items[1]], 'info' => ['more_records' => true, 'next_page_token' => 'unused-asc-overlap-token']],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);
        $specificReads = 0;

        $result = app(ZohoReconciliationService::class)->reconcile(
            'quotes',
            883,
            'paged-quote-two-directions',
            hydrateRecord: static function () use (&$specificReads): array {
                $specificReads++;

                return ['successful' => true, 'created' => 0, 'updated' => 0, 'unchanged' => 1, 'quarantined' => 0, 'api_requests' => 1];
            },
            heartbeat: static fn (): bool => true,
            mutationFence: static fn (): bool => true,
        );

        $this->assertSame(0, $specificReads);
        $this->assertSame(['asc-page-2'], $transport->pageTokens);
        $this->assertSame(4, $result['pages']);
        $this->assertSame(5, $result['api_requests']);
        $this->assertSame(3, ZohoQuoteItem::query()->where('sync_batch_id', 883)->count());
        $this->assertSame('healthy', $result['status']);
    }

    public function test_paged_quote_verification_resumes_a_verified_direction_from_the_reverse_edge(): void
    {
        config()->set('zoho-v2.reconciliation.quote_paged_verification', true);
        config()->set('zoho-v2.reconciliation.quote_page_token_record_limit', 2);
        $quoteId = '600000000000000131';
        $parent = ['id' => $quoteId, 'Subject' => 'Resumed quote', 'Currency' => 'EUR'];
        $items = collect(range(1, 3))->map(static fn (int $index): array => [
            'id' => '60000000000000023'.$index,
            'Parent_Id' => ['id' => $quoteId],
            'Quantity' => $index,
            'Prix_Total' => (string) ($index * 10),
        ])->all();
        $context = ['seen_at' => now()->subDay(), 'synced_at' => now()->subDay()];
        ZohoQuote::query()->create(app(QuoteMapper::class)->map($parent, $context));
        foreach ($items as $sequence => $item) {
            $mapped = app(QuoteItemMapper::class)->map($item, $context + [
                'quote_zoho_id' => $quoteId,
                'currency_code' => 'EUR',
                'sequence' => $sequence + 1,
            ]);
            if ($sequence > 0) {
                $mapped['sync_batch_id'] = 884;
            }
            ZohoQuoteItem::query()->create($mapped);
        }
        $transport = new ReconciliationTransport([
            '/Quotes/deleted' => ['status' => 204],
            '/Quotes' => ['data' => [$parent], 'info' => ['more_records' => false]],
            '/Quoted_Items|sort:asc' => ['data' => [$items[0]], 'info' => ['more_records' => true, 'next_page_token' => 'resume-asc-page-2']],
            '/Quoted_Items|sort:asc|token:resume-asc-page-2' => ['data' => [$items[1]], 'info' => ['more_records' => true, 'next_page_token' => 'unused-resume-token']],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        $result = app(ZohoReconciliationService::class)->reconcile(
            'quotes',
            884,
            'paged-quote-resume-direction',
            hydrateRecord: static fn (): array => ['successful' => true, 'created' => 0, 'updated' => 0, 'unchanged' => 1, 'quarantined' => 0, 'api_requests' => 1],
            heartbeat: static fn (): bool => true,
            mutationFence: static fn (): bool => true,
        );

        $itemCalls = collect($transport->calls)->where('path', '/Quoted_Items')->values();
        $this->assertNotEmpty($itemCalls);
        $this->assertTrue($itemCalls->every(static fn (array $call): bool => $call['query']['sort_order'] === 'asc'));
        $this->assertSame(3, ZohoQuoteItem::query()->where('sync_batch_id', 884)->count());
        $this->assertSame('healthy', $result['status']);
    }

    public function test_numeric_zoho_ids_remain_strings_during_reconciliation_hydration(): void
    {
        $id = '600000000000000001';
        ZohoQuote::query()->create([
            'zoho_id' => $id,
            'raw_payload' => [],
            'payload_hash' => hash('sha256', $id),
        ]);
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Quotes/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Quotes' => ['data' => [['id' => $id]], 'info' => ['more_records' => false]],
        ]));
        $hydrated = [];

        $result = app(ZohoReconciliationService::class)->reconcile(
            'quotes',
            773,
            'numeric-quote-id',
            function (string $zohoId) use (&$hydrated): array {
                $hydrated[] = $zohoId;

                return [
                    'successful' => true,
                    'created' => 0,
                    'updated' => 0,
                    'unchanged' => 1,
                    'quarantined' => 0,
                    'api_requests' => 1,
                ];
            },
            static fn (): bool => true,
        );

        $this->assertSame([$id], $hydrated);
        $this->assertSame(1, $result['hydration']['attempted']);
        $this->assertTrue($result['complete']);
    }

    public function test_quote_sweep_is_sorted_chunked_and_resumes_after_its_durable_cursor(): void
    {
        foreach (['quote-chunk-3', 'quote-chunk-1', 'quote-chunk-2'] as $id) {
            ZohoQuote::query()->create([
                'zoho_id' => $id,
                'raw_payload' => [],
                'payload_hash' => hash('sha256', $id),
            ]);
        }
        $responses = [
            '/Quotes/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Quotes' => ['data' => [
                ['id' => 'quote-chunk-3'],
                ['id' => 'quote-chunk-1'],
                ['id' => 'quote-chunk-2'],
            ], 'info' => ['more_records' => false]],
        ];
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport($responses));
        $firstHydrated = [];
        $firstProgress = [];

        $first = app(ZohoReconciliationService::class)->reconcile(
            moduleKey: 'quotes',
            batchId: 772,
            correlationId: 'quote-chunk-first',
            hydrateRecord: function (string $id) use (&$firstHydrated): array {
                $firstHydrated[] = $id;

                return [
                    'successful' => true,
                    'created' => 0,
                    'updated' => 0,
                    'unchanged' => 1,
                    'quarantined' => 0,
                    'api_requests' => 1,
                ];
            },
            heartbeat: static fn (): bool => true,
            resumeAfterZohoId: null,
            maxHydrations: 2,
            recordProgress: function (string $id, array $result) use (&$firstProgress): bool {
                $firstProgress[] = [$id, $result['unchanged']];

                return true;
            },
        );

        $this->assertSame(['quote-chunk-1', 'quote-chunk-2'], $firstHydrated);
        $this->assertSame([
            ['quote-chunk-1', 1],
            ['quote-chunk-2', 1],
        ], $firstProgress);
        $this->assertTrue($first['continuation_required']);
        $this->assertSame('quote-chunk-2', $first['next_cursor_zoho_id']);
        $this->assertDatabaseMissing('zoho_sync_failures', ['failure_kind' => 'reconciliation']);

        $this->app->instance(ZohoTransport::class, new ReconciliationTransport($responses));
        $secondHydrated = [];
        $second = app(ZohoReconciliationService::class)->reconcile(
            moduleKey: 'quotes',
            batchId: 772,
            correlationId: 'quote-chunk-second',
            hydrateRecord: function (string $id) use (&$secondHydrated): array {
                $secondHydrated[] = $id;

                return [
                    'successful' => true,
                    'created' => 0,
                    'updated' => 0,
                    'unchanged' => 1,
                    'quarantined' => 0,
                    'api_requests' => 1,
                ];
            },
            heartbeat: static fn (): bool => true,
            resumeAfterZohoId: 'quote-chunk-2',
            maxHydrations: 2,
            recordProgress: static fn (): bool => true,
        );

        $this->assertSame(['quote-chunk-3'], $secondHydrated);
        $this->assertFalse($second['continuation_required']);
        $this->assertNull($second['next_cursor_zoho_id']);
        $this->assertSame('healthy', $second['status']);
    }

    public function test_lead_active_scan_uses_the_verified_converted_query(): void
    {
        $transport = new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [], 'info' => ['more_records' => false]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        app(ZohoReconciliationService::class)->reconcile('leads', 701, 'converted-reconcile');

        $leadCall = collect($transport->calls)->firstWhere('path', '/Leads');
        $this->assertSame([
            'fields' => 'id,Converted__s',
            'per_page' => 200,
            'converted' => 'both',
        ], $leadCall['query']);
    }

    public function test_active_id_scan_uses_page_token_and_marks_a_failed_second_page_incomplete_without_false_discrepancy(): void
    {
        $this->lead('lead-present');
        $transport = new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [['id' => 'lead-present']], 'info' => ['more_records' => true, 'next_page_token' => 'opaque-token']],
            '/Leads|token:opaque-token' => ['status' => 503, 'error_code' => 'TEMPORARY_FAILURE'],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        $result = app(ZohoReconciliationService::class)->reconcile('leads', 78, 'failed-page');

        $this->assertFalse($result['complete']);
        $this->assertTrue($result['degraded']);
        $this->assertSame('degraded', $result['status']);
        $this->assertNull($result['missing_count']);
        $this->assertSame(['opaque-token'], $transport->pageTokens);
        $this->assertDatabaseHas('zoho_sync_failures', ['module' => 'leads', 'failure_kind' => 'reconciliation_scan', 'resolved_at' => null]);
        $this->assertDatabaseMissing('zoho_sync_failures', ['module' => 'leads', 'failure_kind' => 'reconciliation']);
    }

    public function test_deletion_scan_failure_is_durable_then_resolved_after_a_complete_scan(): void
    {
        $this->lead('lead-deleted-page-one');
        $failed = new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [['id' => 'lead-deleted-page-one']], 'info' => ['more_records' => true]],
            '/Leads/deleted|page:2' => ['status' => 503],
        ]);
        $this->app->instance(ZohoTransport::class, $failed);
        $first = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 80, 'deletion-failed');

        $this->assertFalse($first['complete']);
        $this->assertTrue($first['degraded']);
        $this->assertDatabaseHas('zoho_sync_failures', ['failure_kind' => 'deletion_scan', 'resolved_at' => null]);

        $complete = new ReconciliationTransport(['/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]]]);
        $this->app->instance(ZohoTransport::class, $complete);
        $second = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 81, 'deletion-complete');

        $this->assertTrue($second['complete']);
        $this->assertNotNull(ZohoSyncFailure::where('failure_kind', 'deletion_scan')->sole()->resolved_at);
    }

    public function test_active_scan_is_incomplete_when_zoho_claims_more_records_without_a_continuation_token(): void
    {
        $present = $this->lead('lead-not-refreshed-after-incomplete-scan');
        $present->update(['last_seen_at' => now()->subDays(5)]);
        $before = $present->fresh()->last_seen_at;
        $transport = new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [['id' => 'partial-id']], 'info' => ['more_records' => true]],
        ]);
        $this->app->instance(ZohoTransport::class, $transport);

        $result = app(ZohoReconciliationService::class)->reconcile('leads', 82, 'missing-token');

        $this->assertFalse($result['complete']);
        $this->assertNull($result['remote_count']);
        $this->assertNull($result['missing_count']);
        $this->assertTrue($present->fresh()->last_seen_at->equalTo($before));
        $this->assertDatabaseHas('zoho_sync_failures', ['failure_kind' => 'reconciliation_scan', 'resolved_at' => null]);
    }

    public function test_active_scan_rejects_non_boolean_more_records(): void
    {
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [['id' => 'string-boolean']], 'info' => ['more_records' => 'false']],
        ]));

        $result = app(ZohoReconciliationService::class)->reconcile('leads', 84, 'string-more-records');

        $this->assertFalse($result['complete']);
        $this->assertDatabaseHas('zoho_sync_failures', ['failure_kind' => 'reconciliation_scan', 'resolved_at' => null]);
    }

    public function test_successful_but_malformed_active_and_deleted_roots_are_incomplete(): void
    {
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['info' => ['more_records' => false]],
        ]));
        $active = app(ZohoReconciliationService::class)->reconcile('leads', 83, 'malformed-active');

        $this->assertFalse($active['complete']);
        $this->assertDatabaseHas('zoho_sync_failures', [
            'module' => 'leads',
            'failure_kind' => 'reconciliation_scan',
            'resolved_at' => null,
        ]);

        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['info' => ['more_records' => false]],
        ]));
        $deleted = app(ZohoDeletedRecordsReconciler::class)->reconcile('leads', 84, 'malformed-deleted');

        $this->assertFalse($deleted['complete']);
        $this->assertDatabaseHas('zoho_sync_failures', [
            'module' => 'leads',
            'failure_kind' => 'deletion_scan',
            'resolved_at' => null,
        ]);
    }

    public function test_discrepancy_failure_resolves_only_after_a_complete_healthy_scan(): void
    {
        $this->lead('lead-present');
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [['id' => 'remote-only']], 'info' => ['more_records' => false]],
        ]));
        app(ZohoReconciliationService::class)->reconcile('leads', 90, 'degraded');
        $failure = ZohoSyncFailure::where('failure_kind', 'reconciliation')->sole();
        $this->assertNull($failure->resolved_at);

        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['status' => 503],
        ]));
        $incomplete = app(ZohoReconciliationService::class)->reconcile('leads', 91, 'incomplete');
        $this->assertFalse($incomplete['complete']);
        $this->assertNull($failure->fresh()->resolved_at);

        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['data' => [['id' => 'lead-present']], 'info' => ['more_records' => false]],
        ]));
        $healthy = app(ZohoReconciliationService::class)->reconcile('leads', 92, 'healthy');
        $this->assertSame('healthy', $healthy['status']);
        $this->assertTrue($healthy['complete']);
        $this->assertNotNull($failure->fresh()->resolved_at);
    }

    public function test_heartbeat_loss_does_not_persist_reconciliation_or_deletion_failures(): void
    {
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['status' => 503],
        ]));

        $deleted = app(ZohoDeletedRecordsReconciler::class)->reconcile(
            'leads',
            601,
            'lost-before-deleted-request',
            static fn (): bool => false,
        );

        $this->assertFalse($deleted['complete']);
        $this->assertDatabaseMissing('zoho_sync_failures', ['failure_kind' => 'deletion_scan']);

        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['status' => 503],
        ]));
        $heartbeats = 0;
        $active = app(ZohoReconciliationService::class)->reconcile(
            'leads',
            602,
            'lost-before-active-request',
            heartbeat: static function () use (&$heartbeats): bool {
                $heartbeats++;

                return $heartbeats === 1;
            },
        );

        $this->assertFalse($active['complete']);
        $this->assertDatabaseMissing('zoho_sync_failures', ['failure_kind' => 'reconciliation_scan']);
    }

    public function test_mutation_fence_loss_does_not_reopen_or_resolve_reconciliation_failures(): void
    {
        $failure = ZohoSyncFailure::query()->create([
            'failure_key' => hash('sha256', 'reconciliation_scan|leads'),
            'sync_batch_id' => 600,
            'module' => 'leads',
            'failure_kind' => 'reconciliation_scan',
            'correlation_id' => 'newer-success',
            'error_summary' => 'Active-record scan did not complete.',
            'context' => [],
            'resolved_at' => now(),
        ]);
        $this->app->instance(ZohoTransport::class, new ReconciliationTransport([
            '/Leads/deleted' => ['data' => [], 'info' => ['more_records' => false]],
            '/Leads' => ['info' => ['more_records' => false]],
        ]));

        $result = app(ZohoReconciliationService::class)->reconcile(
            'leads',
            603,
            'stale-malformed-response',
            mutationFence: static fn (): bool => false,
        );

        $this->assertFalse($result['complete']);
        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertSame('newer-success', $failure->fresh()->correlation_id);
    }

    private function lead(string $id): ZohoLead
    {
        return ZohoLead::query()->create(['zoho_id' => $id, 'raw_payload' => [], 'payload_hash' => hash('sha256', $id), 'last_synced_at' => now()]);
    }
}

final class ReconciliationTransport implements ZohoTransport
{
    /** @var list<string> */
    public array $pageTokens = [];

    /** @var list<array{path:string,query:array<string,mixed>}> */
    public array $calls = [];

    /** @param array<string, array<string, mixed>> $responses */
    public function __construct(private array $responses) {}

    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        $this->calls[] = compact('path', 'query');
        $token = isset($query['page_token']) ? (string) $query['page_token'] : null;
        $sort = isset($query['sort_order']) ? strtolower((string) $query['sort_order']) : null;
        $candidates = [];
        if ($sort !== null) {
            if ($token !== null) {
                $candidates[] = $path.'|sort:'.$sort.'|token:'.$token;
            }
            $candidates[] = $path.'|sort:'.$sort;
        }
        if ($token !== null) {
            $candidates[] = $path.'|token:'.$token;
        }
        if (($query['page'] ?? 1) > 1) {
            $candidates[] = $path.'|page:'.$query['page'];
        }
        $candidates[] = $path;
        $key = collect($candidates)->first(fn (string $candidate): bool => array_key_exists($candidate, $this->responses)) ?? $path;
        if (isset($query['page_token'])) {
            $this->pageTokens[] = (string) $query['page_token'];
        }
        $payload = $this->responses[$key] ?? $this->responses[$path] ?? [];
        $status = (int) ($payload['status'] ?? 200);

        return new TransportResult($status, $status < 300 ? (array) ($payload['data'] ?? []) : [], $status < 300 ? (array) ($payload['info'] ?? []) : [], [], $correlationId ?? 'reconciliation-test', [], $payload['error_code'] ?? null, $status < 300 ? $payload : []);
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        return $this->get($path, $query, $correlationId);
    }
}

final class ReconciliationCapacityTransport implements ZohoTransport
{
    /** @var list<string> */
    private array $calls = [];

    /** @param array<string,list<array<string,mixed>>> $responses */
    public function __construct(private array $responses) {}

    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        $this->calls[] = $path;
        $payload = array_shift($this->responses[$path]) ?? ['status' => 500, 'error_code' => 'http_500'];
        $status = (int) ($payload['status'] ?? 200);

        return new TransportResult(
            $status,
            $status < 300 ? (array) ($payload['data'] ?? []) : [],
            $status < 300 ? (array) ($payload['info'] ?? []) : [],
            [],
            $correlationId ?? 'capacity-reconciliation-test',
            [],
            $payload['error_code'] ?? null,
            $status < 300 ? $payload : [],
        );
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        return $this->get($path, $query, $correlationId);
    }

    public function countCalls(string $path): int
    {
        return count(array_filter($this->calls, static fn (string $call): bool => $call === $path));
    }
}

final class ReconciliationCapacitySleeper implements Sleeper
{
    /** @var list<int> */
    public array $milliseconds = [];

    public function sleepMilliseconds(int $milliseconds): void
    {
        $this->milliseconds[] = $milliseconds;
    }
}
