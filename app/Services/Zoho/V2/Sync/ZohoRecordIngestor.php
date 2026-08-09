<?php

namespace App\Services\Zoho\V2\Sync;

use App\Models\Zoho\ZohoFieldManifest;
use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoQuoteStatusHistory;
use App\Models\Zoho\ZohoSyncBatch;
use App\Models\Zoho\ZohoUser;
use App\Services\Zoho\V2\Mappers\QuoteMapper;
use App\Services\Zoho\V2\Mappers\UserMapper;
use App\Services\Zoho\V2\Mappers\ZohoMapperResolver;
use App\Services\Zoho\V2\Registry\ModuleDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The one persistence path for regular syncs, retries and Bulk hydration. */
final class ZohoRecordIngestor
{
    public function __construct(private readonly ZohoMapperResolver $mappers) {}

    /** @return array{created:int,updated:int,unchanged:int} */
    public function ingest(ModuleDefinition $definition, array $payload, ZohoSyncBatch $batch, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        DB::transaction(function () use ($definition, $payload, $batch, $at, &$counts): void {
            $this->mirrorOwner($payload['Owner'] ?? null, $batch, $at);
            $context = ['seen_at' => $at, 'synced_at' => $at, 'sync_batch_id' => $batch->id, 'schema_hash' => $this->schemaHash($definition)];
            $mapper = $this->mappers->forModule($definition->key);
            if ($mapper instanceof QuoteMapper) {
                $aggregate = $mapper->mapAggregate($payload, $context);
                $quote = $aggregate['quote'];
                $old = ZohoQuote::where('zoho_id', $quote['zoho_id'])->first();
                if (! $aggregate['quoted_items_authoritative'] && $old !== null) {
                    // A specific-record response can omit the subform. That is
                    // not evidence that the last authoritative aggregate is
                    // unknown or empty.
                    $quote['line_items_total'] = $old->line_items_total;
                    $quote['line_items_total_complete'] = $old->line_items_total_complete;
                }
                $this->upsert($definition->modelClass, ['zoho_id' => $quote['zoho_id']], $quote, $counts);
                // A missing or malformed subform is not proof that old lines were deleted.
                if ($aggregate['quoted_items_authoritative']) {
                    $this->syncQuoteItems($quote['zoho_id'], $aggregate['items'], $at, $counts);
                }
                $this->recordQuoteStatus($old, $quote, $at);

                return;
            }
            $mapped = $mapper->map($payload, $context);
            $keys = $definition->activityType ? ['activity_type' => $definition->activityType, 'zoho_id' => $mapped['zoho_id']] : ['zoho_id' => $mapped['zoho_id']];
            $this->upsert($definition->modelClass, $keys, $mapped, $counts);
        });

        return $counts;
    }

    private function upsert(string $model, array $keys, array $values, array &$counts): void
    {
        $old = $model::where($keys)->first();
        if (! $old) {
            $model::create($values);
            $counts['created']++;

            return;
        } if ($old->payload_hash === $values['payload_hash']) {
            $old->update($values);
            $counts['unchanged']++;

            return;
        } $old->update($values);
        $counts['updated']++;
    }

    private function syncQuoteItems(string $quoteId, array $items, CarbonImmutable $at, array &$counts): void
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item['zoho_line_item_id'];
            $this->upsert(ZohoQuoteItem::class, ['zoho_quote_id' => $quoteId, 'zoho_line_item_id' => $item['zoho_line_item_id']], $item, $counts);
        } ZohoQuoteItem::where('zoho_quote_id', $quoteId)->when($ids, fn ($q) => $q->whereNotIn('zoho_line_item_id', $ids))->whereNull('zoho_deleted_at')->update(['zoho_deleted_at' => $at, 'zoho_deletion_type' => 'missing_from_quote']);
    }

    private function recordQuoteStatus(?ZohoQuote $old, array $quote, CarbonImmutable $observed): void
    {
        $status = $quote['status'] ?? null;
        if ($status === null || $old?->status === $status) {
            return;
        } $at = $old ? $observed->utc() : ($quote['zoho_created_at'] ? CarbonImmutable::parse($quote['zoho_created_at'])->utc() : $observed->utc());
        $id = hash('sha256', implode('|', [$quote['zoho_id'], (string) $old?->status, (string) $status, $at->format('c')]));
        ZohoQuoteStatusHistory::firstOrCreate(['zoho_id' => $id], ['quote_zoho_id' => $quote['zoho_id'], 'parent_zoho_id' => $quote['zoho_id'], 'status' => $status, 'previous_status' => $old?->status, 'owner_zoho_id' => $quote['owner_zoho_id'] ?? null, 'occurred_at' => $at, 'raw_payload' => $quote['raw_payload'], 'payload_hash' => $quote['payload_hash'], 'field_schema_hash' => $quote['field_schema_hash'], 'last_seen_at' => $at, 'last_synced_at' => $at, 'sync_batch_id' => $quote['sync_batch_id']]);
    }

    private function mirrorOwner(mixed $owner, ZohoSyncBatch $batch, CarbonImmutable $at): void
    {
        if (! is_array($owner) || empty($owner['id'])) {
            return;
        }$values = (new UserMapper)->mapOwnerLookup($owner, ['seen_at' => $at, 'synced_at' => $at, 'sync_batch_id' => $batch->id]);
        $old = ZohoUser::where('zoho_id', $values['zoho_id'])->first();
        if (! $old) {
            ZohoUser::create($values);

            return;
        }$update = ['last_seen_at' => $values['last_seen_at'], 'last_synced_at' => $values['last_synced_at'], 'sync_batch_id' => $batch->id, 'zoho_deleted_at' => null, 'zoho_deletion_type' => null];
        foreach (['full_name', 'first_name', 'last_name', 'email', 'normalized_email', 'status'] as $field) {
            if ($values[$field] !== null) {
                $update[$field] = $values[$field];
            }
        }$old->update($update);
    }

    private function schemaHash(ModuleDefinition $d): ?string
    {
        return ZohoFieldManifest::where('module', $d->key)->where('submodule', $d->submodule ?? '')->where('is_current', true)->latest('id')->value('schema_hash');
    }
}
