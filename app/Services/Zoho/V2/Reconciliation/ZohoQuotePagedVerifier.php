<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\Reconciliation;

use App\Models\Zoho\ZohoQuote;
use App\Models\Zoho\ZohoQuoteItem;
use App\Models\Zoho\ZohoSyncFailure;
use App\Services\Zoho\V2\DTO\TransportResult;
use App\Services\Zoho\V2\Mappers\QuoteItemMapper;
use App\Services\Zoho\V2\Mappers\QuoteMapper;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Verifies Quotes from two empirically equivalent paged read models:
 * parent fields from /Quotes and authoritative lines from /Quoted_Items.
 * A specific-record request is reserved for an actual mismatch, because it
 * is the only response shape which combines both halves atomically.
 */
final class ZohoQuotePagedVerifier
{
    private const PARENT_FIELDS = [
        'UN', 'Tag', 'Pays', 'Owner', 'P_Brut', 'Volume', 'Origine', 'Quantit',
        'Subject', 'Currency', 'G_rbable', 'Deal_Name', 'Franchise', 'Incoterm1',
        'La_classe', 'Valid_Till', 'Destination', 'Account_Name', 'Contact_Name',
        'Quote_Number', 'Dimensions_CM', 'Exchange_Rate', 'Type_de_Colis',
        'Transit_Time_J', 'Date_de_Cotation', 'Metre_de_Planche', 'Record_Status__s',
        'Suivie_d_Affaire', 'Type_d_quipement', 'Type_de_Transport',
        'Last_Activity_Time', 'Marchandise_dangereuse', 'Created_Time',
        'Modified_Time', 'Grand_Total', 'Sub_Total', 'Discount', 'Tax',
    ];

    private const ITEM_FIELDS = [
        'Created_Time', 'Modified_Time', 'Parent_Id', 'Product_Name', 'Description',
        'Quantity', 'Prix_Total', 'Unit_de_Mesure', 'Prix_1x40',
    ];

    private const NON_BUSINESS_PARENT_FIELDS = [
        'raw_payload', 'payload_hash', 'field_schema_hash', 'last_seen_at',
        'last_synced_at', 'zoho_deleted_at', 'zoho_deletion_type', 'sync_batch_id',
    ];

    private const ITEM_BUSINESS_FIELDS = [
        // Zoho occasionally reports an older line Modified_Time from the
        // standalone subform module than from its parent-specific read. It is
        // metadata, not business drift, so it must not force a parent fetch.
        'zoho_quote_id', 'zoho_line_item_id', 'identity_source',
        'product_zoho_id', 'product_name', 'quantity', 'unit_price',
        'description', 'unit_of_measure', 'total', 'zoho_created_at',
    ];

    public function __construct(
        private readonly QuoteMapper $quotes,
        private readonly QuoteItemMapper $items,
        private readonly ZohoDataQualityMetrics $metrics,
    ) {}

    /**
     * @param  callable(string,array<string,mixed>): ?TransportResult  $get
     * @param  callable(string): array{successful:bool,created:int,updated:int,unchanged:int,quarantined:int,api_requests:int}  $hydrateRecord
     * @param  (callable(): bool)|null  $heartbeat
     * @param  (callable(): bool)|null  $mutationFence
     * @param  array<string,mixed>  $deleted
     * @return array<string,mixed>
     */
    public function verify(
        int $batchId,
        string $correlationId,
        callable $get,
        callable $hydrateRecord,
        ?callable $heartbeat,
        ?callable $mutationFence,
        array $deleted,
    ): array {
        $at = CarbonImmutable::now();
        $pageSize = 200;
        $directionLimit = max(1, (int) config('zoho-v2.reconciliation.quote_page_token_record_limit', 100_000));
        $verifiedItemCount = ZohoQuoteItem::query()->current()->where('sync_batch_id', $batchId)->count();
        $resumeItemSweep = $verifiedItemCount >= max(1, $directionLimit - $pageSize);
        if (! $this->resetMarkers($batchId, $mutationFence, $resumeItemSweep)) {
            return $this->incomplete($deleted);
        }

        $apiRequests = (int) ($deleted['api_requests'] ?? 0);
        $pages = 0;
        $lastStatus = (int) ($deleted['status'] ?? 204);
        $targets = [];
        $remoteQuoteIds = [];

        $parent = $this->scan(
            '/Quotes',
            ['fields' => implode(',', self::PARENT_FIELDS), 'per_page' => $pageSize, 'sort_by' => 'id', 'sort_order' => 'asc'],
            $correlationId,
            $get,
            $heartbeat,
            function (array $rows) use ($batchId, $at, $mutationFence, &$targets, &$remoteQuoteIds): bool {
                $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
                $local = ZohoQuote::query()->whereIn('zoho_id', $ids)->get()->keyBy(
                    static fn (ZohoQuote $quote): string => (string) $quote->zoho_id,
                );
                $matching = [];
                foreach ($rows as $payload) {
                    $id = (string) $payload['id'];
                    $remoteQuoteIds[] = $id;
                    $quote = $local->get($id);
                    if (! $quote instanceof ZohoQuote || ! $this->parentMatches($quote, $payload, $batchId, $at)) {
                        $targets['quote:'.$id] = $id;
                    } else {
                        $matching[] = $id;
                    }
                }

                return $this->markQuotes($matching, $batchId, $at, $mutationFence);
            },
        );
        if (! $parent['complete']) {
            return $this->incomplete($deleted, $parent['status'], $parent['pages'], $apiRequests + $parent['api_requests']);
        }
        $apiRequests += $parent['api_requests'];
        $pages += $parent['pages'];
        $lastStatus = $parent['status'];

        $hydration = $this->emptyHydration();
        if (! $this->hydrateTargets($targets, $hydrateRecord, $heartbeat, $hydration)) {
            return $this->incomplete($deleted, 0, $pages, $apiRequests + $hydration['api_requests'], $hydration);
        }
        $targets = [];

        $consumeItems = function (bool $stopOnVerifiedOverlap) use ($batchId, $at, $mutationFence, &$targets): callable {
            return function (array $rows) use ($batchId, $at, $mutationFence, &$targets, $stopOnVerifiedOverlap): ?bool {
                $lineIds = array_map(static fn (array $row): string => (string) $row['id'], $rows);
                $local = ZohoQuoteItem::query()->whereIn('zoho_line_item_id', $lineIds)->get()->keyBy(
                    static fn (ZohoQuoteItem $item): string => $item->zoho_quote_id.'|'.$item->zoho_line_item_id,
                );
                $matching = [];
                $allPreviouslyVerified = $rows !== [];
                foreach ($rows as $payload) {
                    $lineId = (string) $payload['id'];
                    $parentId = $this->parentId($payload);
                    if ($parentId === null) {
                        return false;
                    }
                    $item = $local->get($parentId.'|'.$lineId);
                    $previouslyVerified = $item instanceof ZohoQuoteItem
                        && (int) $item->sync_batch_id === $batchId
                        && $this->itemMatches($item, $payload, $batchId, $at);
                    $allPreviouslyVerified = $allPreviouslyVerified && $previouslyVerified;
                    if (! $item instanceof ZohoQuoteItem
                        || ! $this->itemMatches($item, $payload, $batchId, $at)) {
                        $targets['quote:'.$parentId] = $parentId;
                    } else {
                        $matching[] = [$parentId, $lineId];
                    }
                }

                if ($stopOnVerifiedOverlap && $allPreviouslyVerified) {
                    return null;
                }

                return $this->markItems($matching, $batchId, $at, $mutationFence);
            };
        };

        $itemPages = $itemRequests = 0;
        $itemStatus = 204;
        $needsReverseSweep = $resumeItemSweep;
        if (! $resumeItemSweep) {
            $descending = $this->scan(
                '/Quoted_Items',
                ['fields' => implode(',', self::ITEM_FIELDS), 'per_page' => $pageSize, 'sort_by' => 'id', 'sort_order' => 'desc'],
                $correlationId,
                $get,
                $heartbeat,
                $consumeItems(false),
                $directionLimit,
            );
            if (! $descending['complete']) {
                return $this->incomplete($deleted, $descending['status'], $pages + $descending['pages'], $apiRequests + $descending['api_requests'] + $hydration['api_requests'], $hydration);
            }
            $itemPages += $descending['pages'];
            $itemRequests += $descending['api_requests'];
            $itemStatus = $descending['status'];
            $needsReverseSweep = $descending['limit_reached'];
        }

        if ($needsReverseSweep) {
            $ascending = $this->scan(
                '/Quoted_Items',
                ['fields' => implode(',', self::ITEM_FIELDS), 'per_page' => $pageSize, 'sort_by' => 'id', 'sort_order' => 'asc'],
                $correlationId,
                $get,
                $heartbeat,
                $consumeItems(true),
                $directionLimit,
            );
            if (! $ascending['complete'] || $ascending['limit_reached']) {
                return $this->incomplete($deleted, $ascending['limit_reached'] ? 400 : $ascending['status'], $pages + $itemPages + $ascending['pages'], $apiRequests + $itemRequests + $ascending['api_requests'] + $hydration['api_requests'], $hydration);
            }
            $itemPages += $ascending['pages'];
            $itemRequests += $ascending['api_requests'];
            $itemStatus = $ascending['status'];
        }

        $apiRequests += $itemRequests;
        $pages += $itemPages;
        $lastStatus = $itemStatus;

        foreach (ZohoQuoteItem::query()->current()
            ->where(function ($query) use ($batchId): void {
                $query->whereNull('sync_batch_id')->orWhere('sync_batch_id', '!=', $batchId);
            })
            ->distinct()->pluck('zoho_quote_id') as $parentId) {
            $targets['quote:'.(string) $parentId] = (string) $parentId;
        }
        if (! $this->hydrateTargets($targets, $hydrateRecord, $heartbeat, $hydration)) {
            return $this->incomplete($deleted, 0, $pages, $apiRequests + $hydration['api_requests'], $hydration);
        }
        $apiRequests += $hydration['api_requests'];

        $remoteQuoteIds = array_values(array_unique($remoteQuoteIds));
        sort($remoteQuoteIds, SORT_STRING);
        $localIds = ZohoQuote::query()->current()->pluck('zoho_id')->map(static fn (mixed $id): string => (string) $id)->all();
        sort($localIds, SORT_STRING);
        $missing = array_values(array_diff($remoteQuoteIds, $localIds));
        $extra = array_values(array_diff($localIds, $remoteQuoteIds));
        $itemExtras = ZohoQuoteItem::query()->current()
            ->where(function ($query) use ($batchId): void {
                $query->whereNull('sync_batch_id')->orWhere('sync_batch_id', '!=', $batchId);
            })->count();

        $specificAttempts = $hydration['attempted'];
        $hydration['attempted'] = count($remoteQuoteIds);
        $hydration['unchanged'] += max(0, count($remoteQuoteIds) - $specificAttempts);
        $degraded = ($deleted['degraded'] ?? false)
            || $missing !== []
            || $extra !== []
            || $itemExtras > 0
            || $hydration['failed'] > 0
            || $hydration['quarantined'] > 0;

        if (! $this->persistOutcome($batchId, $correlationId, $degraded, count($remoteQuoteIds), count($localIds), count($missing), count($extra), $itemExtras, $mutationFence)) {
            return $this->incomplete($deleted, $lastStatus, $pages, $apiRequests, $hydration);
        }

        return [
            'deleted' => $deleted,
            'remote_count' => count($remoteQuoteIds),
            'local_count' => count($localIds),
            'missing_count' => count($missing),
            'extra_count' => count($extra),
            'repaired_count' => max(0, $hydration['created']),
            'swept_count' => count($remoteQuoteIds),
            'hydration' => $hydration,
            'status' => $degraded ? 'degraded' : 'healthy',
            'complete' => true,
            'degraded' => $degraded,
            'continuation_required' => false,
            'next_cursor_zoho_id' => null,
            'pages' => $pages,
            'http_status' => $lastStatus,
            'api_requests' => $apiRequests,
            'quality' => $this->metrics->forModule('quotes'),
            'fast_path' => true,
            'item_extra_count' => $itemExtras,
        ];
    }

    /** @param callable(list<array<string,mixed>>): ?bool $consume @return array{complete:bool,status:int,pages:int,api_requests:int,limit_reached:bool,overlap_reached:bool} */
    private function scan(string $path, array $query, string $correlationId, callable $get, ?callable $heartbeat, callable $consume, ?int $maxRows = null): array
    {
        $token = null;
        $pages = $apiRequests = $rowsSeen = 0;
        $status = 204;
        do {
            if ($heartbeat !== null && $heartbeat() !== true) {
                return ['complete' => false, 'status' => $status, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => false, 'overlap_reached' => false];
            }
            $pageQuery = $query;
            if ($token !== null) {
                $pageQuery['page_token'] = $token;
            }
            /** @var ?TransportResult $response */
            $response = $get($path, $pageQuery);
            if ($response === null) {
                return ['complete' => false, 'status' => 0, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => false, 'overlap_reached' => false];
            }
            $status = $response->status;
            $apiRequests += $response->apiRequestCount();
            if (! $response->successful()) {
                return ['complete' => false, 'status' => $status, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => false, 'overlap_reached' => false];
            }
            $parsed = $this->parsePage($response);
            if ($parsed === null) {
                return ['complete' => false, 'status' => $status, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => false, 'overlap_reached' => false];
            }
            [$rows, $token] = $parsed;
            $decision = $consume($rows);
            if ($decision === false) {
                return ['complete' => false, 'status' => 0, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => false, 'overlap_reached' => false];
            }
            $pages++;
            $rowsSeen += count($rows);
            if ($decision === null) {
                return ['complete' => true, 'status' => $status, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => false, 'overlap_reached' => true];
            }
            if ($token !== null && $maxRows !== null && $rowsSeen >= $maxRows) {
                return ['complete' => true, 'status' => $status, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => true, 'overlap_reached' => false];
            }
        } while ($token !== null);

        return ['complete' => true, 'status' => $status, 'pages' => $pages, 'api_requests' => $apiRequests, 'limit_reached' => false, 'overlap_reached' => false];
    }

    /** @return array{0:list<array<string,mixed>>,1:?string}|null */
    private function parsePage(TransportResult $response): ?array
    {
        if ($response->status === 204) {
            return [[], null];
        }
        $rows = $response->root('data');
        $info = $response->info ?: $response->root('info');
        if ($response->status !== 200 || ! is_array($rows) || ! array_is_list($rows) || ! is_array($info)) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_scalar($row['id']) || (string) $row['id'] === '') {
                return null;
            }
        }
        $more = $info['more_records'] ?? null;
        $token = $info['next_page_token'] ?? null;
        if (! is_bool($more)
            || ($token !== null && (! is_string($token) || $token === '' || strlen($token) > 1024))
            || ($more && $token === null)
            || (! $more && $token !== null)) {
            return null;
        }

        return [$rows, $token];
    }

    private function parentMatches(ZohoQuote $quote, array $payload, int $batchId, CarbonImmutable $at): bool
    {
        $mapped = $this->quotes->map($payload, ['seen_at' => $at, 'synced_at' => $at, 'sync_batch_id' => $batchId]);
        $candidate = (new ZohoQuote)->forceFill($mapped);
        foreach (array_keys($candidate->getAttributes()) as $field) {
            if (in_array($field, self::NON_BUSINESS_PARENT_FIELDS, true)) {
                continue;
            }
            if (! $this->attributeMatches($quote, $candidate, $field)) {
                return false;
            }
        }

        return true;
    }

    private function itemMatches(ZohoQuoteItem $item, array $payload, int $batchId, CarbonImmutable $at): bool
    {
        $mapped = $this->items->map($payload, [
            'quote_zoho_id' => (string) $item->zoho_quote_id,
            // The standalone Quoted_Items module has no stable line-order
            // field. Preserve the already authoritative specific-read order.
            'sequence' => (int) $item->sequence,
            'currency_code' => $item->currency_code,
            'seen_at' => $at,
            'synced_at' => $at,
            'sync_batch_id' => $batchId,
        ]);
        $candidate = (new ZohoQuoteItem)->forceFill($mapped);
        foreach (self::ITEM_BUSINESS_FIELDS as $field) {
            if (! $this->attributeMatches($item, $candidate, $field)) {
                return false;
            }
        }

        return true;
    }

    private function attributeMatches(Model $stored, Model $candidate, string $field): bool
    {
        return $this->comparable($stored->getAttribute($field))
            === $this->comparable($candidate->getAttribute($field));
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format('Y-m-d\\TH:i:s.u\\Z');
        }
        if (is_array($value)) {
            $normalized = array_map(fn (mixed $item): mixed => $this->comparable($item), $value);
            if (array_is_list($normalized)) {
                usort($normalized, static fn (mixed $left, mixed $right): int => strcmp(
                    json_encode($left, JSON_THROW_ON_ERROR),
                    json_encode($right, JSON_THROW_ON_ERROR),
                ));
            } else {
                ksort($normalized);
            }

            return $normalized;
        }

        return $value === null ? null : (string) $value;
    }

    private function parentId(array $payload): ?string
    {
        $parent = $payload['Parent_Id'] ?? null;
        $parent = is_array($parent) ? ($parent['id'] ?? null) : $parent;

        return is_scalar($parent) && (string) $parent !== '' ? (string) $parent : null;
    }

    private function resetMarkers(int $batchId, ?callable $mutationFence, bool $preserveItems): bool
    {
        return $this->mutate($mutationFence, static function () use ($batchId, $preserveItems): void {
            ZohoQuote::query()->current()->where('sync_batch_id', $batchId)->update(['sync_batch_id' => null]);
            if (! $preserveItems) {
                ZohoQuoteItem::query()->current()->where('sync_batch_id', $batchId)->update(['sync_batch_id' => null]);
            }
        });
    }

    /** @param list<string> $ids */
    private function markQuotes(array $ids, int $batchId, CarbonImmutable $at, ?callable $mutationFence): bool
    {
        if ($ids === []) {
            return true;
        }

        return $this->mutate($mutationFence, static function () use ($ids, $batchId, $at): void {
            ZohoQuote::query()->whereIn('zoho_id', $ids)->update([
                'sync_batch_id' => $batchId,
                'last_seen_at' => $at,
                'last_synced_at' => $at,
                'zoho_deleted_at' => null,
                'zoho_deletion_type' => null,
            ]);
        });
    }

    /** @param list<array{0:string,1:string}> $pairs */
    private function markItems(array $pairs, int $batchId, CarbonImmutable $at, ?callable $mutationFence): bool
    {
        if ($pairs === []) {
            return true;
        }

        return $this->mutate($mutationFence, static function () use ($pairs, $batchId, $at): void {
            foreach (array_chunk($pairs, 100) as $chunk) {
                $bindings = [];
                foreach ($chunk as [$parentId, $lineId]) {
                    $bindings[] = $parentId;
                    $bindings[] = $lineId;
                }
                DB::table('zoho_quote_items')
                    ->whereRaw('(zoho_quote_id, zoho_line_item_id) in ('.implode(',', array_fill(0, count($chunk), '(?, ?)')).')', $bindings)
                    ->update([
                        'sync_batch_id' => $batchId,
                        'last_seen_at' => $at,
                        'last_synced_at' => $at,
                        'zoho_deleted_at' => null,
                        'zoho_deletion_type' => null,
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    /** @param array<string,string> $targets @param array<string,int> $hydration */
    private function hydrateTargets(array $targets, callable $hydrateRecord, ?callable $heartbeat, array &$hydration): bool
    {
        foreach (array_values($targets) as $id) {
            if ($heartbeat !== null && $heartbeat() !== true) {
                return false;
            }
            $result = $hydrateRecord($id);
            $hydration['attempted']++;
            foreach (['created', 'updated', 'unchanged', 'quarantined', 'api_requests'] as $counter) {
                $hydration[$counter] += max(0, (int) ($result[$counter] ?? 0));
            }
            if (($result['successful'] ?? false) !== true) {
                $hydration['failed']++;
            }
        }

        return true;
    }

    /** @return array{attempted:int,failed:int,created:int,updated:int,unchanged:int,quarantined:int,api_requests:int} */
    private function emptyHydration(): array
    {
        return ['attempted' => 0, 'failed' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'quarantined' => 0, 'api_requests' => 0];
    }

    private function persistOutcome(int $batchId, string $correlationId, bool $degraded, int $remote, int $local, int $missing, int $extra, int $itemExtra, ?callable $mutationFence): bool
    {
        return $this->mutate($mutationFence, static function () use ($batchId, $correlationId, $degraded, $remote, $local, $missing, $extra, $itemExtra): void {
            $key = hash('sha256', 'reconciliation|quotes');
            if (! $degraded) {
                ZohoSyncFailure::query()->where('failure_key', $key)->whereNull('resolved_at')->update(['resolved_at' => now(), 'retry_after' => null]);

                return;
            }
            ZohoSyncFailure::query()->updateOrCreate(['failure_key' => $key], [
                'sync_batch_id' => $batchId,
                'module' => 'quotes',
                'failure_kind' => 'reconciliation',
                'correlation_id' => $correlationId,
                'error_summary' => 'Remote/local Quote or Quoted_Items discrepancy.',
                'context' => ['remote_count' => $remote, 'local_count' => $local, 'missing_count' => $missing, 'extra_count' => $extra, 'item_extra_count' => $itemExtra],
                'resolved_at' => null,
            ]);
        });
    }

    private function mutate(?callable $mutationFence, callable $mutation): bool
    {
        return DB::transaction(static function () use ($mutationFence, $mutation): bool {
            if ($mutationFence !== null && $mutationFence() !== true) {
                return false;
            }
            $mutation();

            return true;
        });
    }

    /** @param array<string,mixed> $deleted @param array<string,int>|null $hydration */
    private function incomplete(array $deleted, int $status = 0, int $pages = 0, int $apiRequests = 0, ?array $hydration = null): array
    {
        return [
            'deleted' => $deleted,
            'remote_count' => null,
            'local_count' => null,
            'missing_count' => null,
            'extra_count' => null,
            'repaired_count' => 0,
            'swept_count' => 0,
            'hydration' => $hydration ?? $this->emptyHydration(),
            'status' => 'degraded',
            'complete' => false,
            'degraded' => true,
            'continuation_required' => false,
            'next_cursor_zoho_id' => null,
            'pages' => $pages,
            'http_status' => $status,
            'api_requests' => $apiRequests,
            'quality' => $this->metrics->forModule('quotes'),
            'fast_path' => true,
        ];
    }
}
