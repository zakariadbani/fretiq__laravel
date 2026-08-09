<?php

namespace Tests\Unit\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoQuoteStatusHistory;
use App\Services\Zoho\V2\Contracts\ZohoTransport;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\V2\Mappers\ZohoMapperResolver;
use App\Services\Zoho\V2\Reconciliation\MapperFailedRecordHydrator;
use App\Services\Zoho\V2\Registry\ZohoModuleRegistry;
use App\Services\Zoho\V2\Sync\ZohoRecordIngestor;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MapperFailedRecordHydratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_retry_is_an_idempotent_aggregate_with_items_and_status_history(): void
    {
        $first = ['id' => 'Q1', 'Subject' => 'Quote', 'Quote_Stage' => 'Draft', 'Created_Time' => '2026-08-01T08:00:00+00:00', 'Currency' => 'EUR', 'Quoted_Items' => [
            ['id' => 'I1', 'product' => ['id' => 'P1', 'name' => 'Freight'], 'quantity' => 1, 'list_price' => 10],
            ['id' => 'I2', 'product' => ['id' => 'P2', 'name' => 'Fees'], 'quantity' => 1, 'list_price' => 2],
        ]];
        $changed = $first;
        $changed['Quote_Stage'] = 'Accepted';
        $changed['Modified_Time'] = '2026-08-02T09:00:00+00:00';
        $changed['Quoted_Items'] = [$first['Quoted_Items'][0]];
        $transport = new QuoteRetryTransport([$first, $first, $changed, $changed]);
        $hydrator = new MapperFailedRecordHydrator($transport, new ZohoModuleRegistry, new ZohoRecordIngestor(new ZohoMapperResolver));

        foreach ([1, 2, 3, 4] as $batch) {
            $fetched = $hydrator->fetch('quotes', 'Q1', $batch, 'quote-retry');
            $this->assertTrue(DB::transaction(fn () => $hydrator->persist('quotes', $batch, $fetched))->successful);
        }

        $this->assertSame(2, ZohoQuoteStatusHistory::count());
        $this->assertSame(1, ZohoQuoteItem::current()->count());
        $this->assertDatabaseHas('zoho_quote_items', ['zoho_quote_id' => 'Q1', 'zoho_line_item_id' => 'I2', 'zoho_deletion_type' => 'missing_from_quote']);
        $this->assertFalse($hydrator->fetch('quoted_items', 'I1', 5, 'standalone-item')->successful);
        $this->assertSame(4, $transport->calls);
    }

    public function test_converted_lead_retry_uses_the_verified_specific_record_query(): void
    {
        $transport = new QuoteRetryTransport([['id' => 'L1', 'Converted__s' => true]]);
        $hydrator = new MapperFailedRecordHydrator($transport, new ZohoModuleRegistry, new ZohoRecordIngestor(new ZohoMapperResolver));

        $fetched = $hydrator->fetch('leads', 'L1', 88, 'lead-retry');
        $result = DB::transaction(fn () => $hydrator->persist('leads', 88, $fetched));

        $this->assertTrue($result->successful);
        $this->assertSame(1, $result->apiRequests);

        $this->assertSame('/Leads/L1', $transport->requests[0]['path']);
        $this->assertSame(['converted' => 'both'], $transport->requests[0]['query']);
    }

    public function test_retry_hydration_rejects_mismatched_missing_or_non_scalar_record_ids_before_persisting(): void
    {
        foreach ([
            ['id' => 'different-lead', 'Full_Name' => 'Wrong record'],
            ['Full_Name' => 'Missing id'],
            ['id' => ['not-a-scalar'], 'Full_Name' => 'Array id'],
        ] as $record) {
            $hydrator = new MapperFailedRecordHydrator(
                new QuoteRetryTransport([$record]),
                new ZohoModuleRegistry,
                new ZohoRecordIngestor(new ZohoMapperResolver),
            );

            $fetched = $hydrator->fetch('leads', 'requested-lead', 91, 'retry-id-fence');
            $persisted = DB::transaction(fn () => $hydrator->persist('leads', 91, $fetched));

            $this->assertFalse($fetched->successful);
            $this->assertSame(1, $fetched->apiRequests);
            $this->assertFalse($persisted->successful);
        }

        $this->assertDatabaseMissing('zoho_leads', ['zoho_id' => 'requested-lead']);
        $this->assertDatabaseMissing('zoho_leads', ['zoho_id' => 'different-lead']);
    }
}

final class QuoteRetryTransport implements ZohoTransport
{
    public int $calls = 0;

    /** @var list<array{path:string,query:array<string,mixed>}> */
    public array $requests = [];

    /** @param list<array<string,mixed>> $records */
    public function __construct(private array $records) {}

    public function get(string $path, array $query = [], ?string $correlationId = null): TransportResult
    {
        $this->requests[] = compact('path', 'query');
        $record = $this->records[$this->calls++] ?? null;
        $payload = ['data' => $record ? [$record] : []];

        return new TransportResult(200, (array) $payload['data'], [], [], $correlationId ?? 'quote-retry', [], null, $payload);
    }

    public function getIfModifiedSince(string $path, DateTimeInterface $since, array $query = [], ?string $correlationId = null): TransportResult
    {
        return $this->get($path, $query, $correlationId);
    }
}
