<?php

namespace App\Services\Prospecting;

use App\Models\ProspectBatch;
use App\Models\ProspectBatchContact;
use App\Models\ProspectBatchItem;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ProspectReviewWorkspace
{
    public function __construct(private readonly ProspectReviewPresenter $presenter) {}

    /** @return array{imported_contacts_count:int,provider_result_count:?int,recorded_units:?float} */
    public function statusFacts(ProspectBatchItem $item): array
    {
        $imported = ProspectBatchContact::query()
            ->where('prospect_batch_item_id', $item->getKey())
            ->count();
        $startedAt = $item->processing_started_at;
        $finishedAt = $item->processed_at;

        if ($startedAt === null || $finishedAt === null || $finishedAt->lt($startedAt)) {
            return [
                'imported_contacts_count' => $imported,
                'provider_result_count' => null,
                'recorded_units' => null,
            ];
        }

        $call = ProviderCall::query()
            ->where('prospect_batch_item_id', $item->getKey())
            ->where('provider', 'hunter')
            ->where('operation', 'domain_search')
            ->where('status', 'succeeded')
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->whereBetween('started_at', [$startedAt, $finishedAt])
            ->whereBetween('finished_at', [$startedAt, $finishedAt])
            ->latest('finished_at')
            ->latest('id')
            ->first(['result_count', 'consumed_units']);

        return [
            'imported_contacts_count' => $imported,
            'provider_result_count' => $call?->result_count,
            'recorded_units' => $call === null || $call->consumed_units === null
                ? null
                : (float) $call->consumed_units,
        ];
    }

    /** @param array{batch:?int,tab:string,reason:?string,state?:string,q:string,item?:?int,monitor_item?:?int} $filters @return array<string,mixed> */
    public function build(User $user, array $filters): array
    {
        $batches = $this->batchProgress($user, $filters['batch']);
        $items = $this->items($user, $filters);
        $stateCounts = $this->stateCounts($user, $filters);
        $monitoredItem = isset($filters['monitor_item'])
            ? $this->authorizedItems($user)
                ->select(['id', 'prospect_batch_id', 'company_name', 'status', 'domain_reason', 'error_code', 'processing_started_at', 'processed_at'])
                ->with([
                    'batch:id,name,status,created_by,prospect_criteria_id',
                    'batch.criteria:id,name,is_active',
                ])
                ->find($filters['monitor_item'])
            : null;
        $requestedItemId = $filters['item'] ?? null;
        $activeItem = $requestedItemId === null
            ? $items->getCollection()->first()
            : $this->authorizedItems($user)
                ->whereIn('status', ['review', 'failed'])
                ->with([
                    'batch:id,name,status,source_type,created_by,prospect_criteria_id',
                    'batch.criteria:id,name,is_active',
                ])
                ->withCount('importedContacts')
                ->find($requestedItemId);
        $monitoredFacts = $monitoredItem === null ? null : $this->statusFacts($monitoredItem);
        $nextItem = $activeItem === null ? null : $this->nextQueuedItem($user, $filters, (int) $activeItem->getKey());
        $nextMonitoredItem = $monitoredItem === null ? null : $this->nextQueuedItem($user, $filters, (int) $monitoredItem->getKey());

        return [
            'summary' => [
                'handled' => (int) $batches->sum('handled_items_count'),
                'companies_pending' => (int) $batches->sum('company_decisions_count'),
                'companies_attention' => (int) $batches->sum('attention_items_count'),
                'companies_blocked' => (int) $batches->sum('blocked_items_count'),
                'imported_contacts' => (int) $batches->sum('imported_contacts_count'),
                'batches' => $batches->count(),
            ],
            'batches' => $batches,
            'items' => $items,
            'stateCounts' => $stateCounts,
            'activeItem' => $activeItem,
            'activeReview' => $activeItem === null ? null : $this->presenter->companyDecision($activeItem),
            'nextItem' => $nextItem,
            'monitoredItem' => $monitoredItem,
            'monitoredFacts' => $monitoredFacts,
            'nextMonitoredItem' => $nextMonitoredItem,
            'filters' => $filters,
        ];
    }

    /**
     * Resolves which state pill a plain deep link (no explicit ?state=) to
     * a specific item should default to, so the pill/aria-current stays
     * truthful about the row it's actually showing — a failed item never
     * silently defaults onto the "À décider" pill it isn't part of. Only
     * fills the *default*; an explicit ?state= is never touched by this.
     * Falls back to the general "attention" default when the item can't be
     * resolved to review/failed (wrong owner, already decided, deleted).
     */
    public function defaultStateForItem(User $user, int $itemId): string
    {
        $status = $this->authorizedItems($user)->whereKey($itemId)->value('status');

        return $status === 'failed' ? 'blocked' : 'attention';
    }

    /** @param array{batch:?int,reason:?string,state?:string,q:string} $filters */
    private function nextQueuedItem(User $user, array $filters, int $excludedItemId): ?ProspectBatchItem
    {
        // "Next" is the immediate neighbour in the same filtered + ordered list `items()`
        // renders (latest id first), not just any other review/failed row — otherwise
        // "Continuer la file" / "Décider plus tard" can silently jump the user to a
        // different page or a different state pill than the one they're looking at.
        return $this->stateFilteredItems($user, $filters)
            ->select(['id', 'prospect_batch_id', 'company_name'])
            ->where('id', '<', $excludedItemId)
            ->latest('id')
            ->first();
    }

    private function authorizedBatches(User $user): Builder
    {
        return ProspectBatch::query()
            ->when(! $user->hasRole(['admin', 'superadmin']), fn (Builder $query) => $query->where('created_by', $user->id));
    }

    private function authorizedItems(User $user): Builder
    {
        return ProspectBatchItem::query()
            ->whereHas('batch', fn (Builder $query) => $query->when(
                ! $user->hasRole(['admin', 'superadmin']),
                fn (Builder $owned) => $owned->where('created_by', $user->id),
            ));
    }

    /** @return Collection<int, ProspectBatch> */
    private function batchProgress(User $user, ?int $batchId): Collection
    {
        return $this->authorizedBatches($user)
            ->when($batchId !== null, fn (Builder $query) => $query->whereKey($batchId))
            ->when($batchId === null, fn (Builder $query) => $query->where(function (Builder $scope): void {
                $scope->whereIn('status', ['queued', 'running', 'review'])
                    ->orWhereHas('items', fn (Builder $items) => $items->whereIn('status', ['review', 'failed']));
            }))
            ->withCount([
                'items as handled_items_count' => fn (Builder $query) => $query->whereIn('status', ['ready', 'promoted', 'skipped']),
                'items as company_decisions_count' => fn (Builder $query) => $query->whereIn('status', ['review', 'failed']),
                'items as attention_items_count' => fn (Builder $query) => $query->where('status', 'review'),
                'items as blocked_items_count' => fn (Builder $query) => $query->where('status', 'failed'),
                'items as processing_items_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'processing']),
                'importedContacts',
            ])
            ->latest('id')
            ->get()
            ->map(function (ProspectBatch $batch): ProspectBatch {
                $manualPending = (int) $batch->company_decisions_count;
                $batch->setAttribute('manual_pending_count', $manualPending);
                $batch->setAttribute('next_state', $this->presenter->batchNextState($batch, $manualPending));

                return $batch;
            });
    }

    /** @param array{batch:?int,reason:?string,q:string} $filters */
    private function filteredItems(User $user, array $filters): Builder
    {
        $search = mb_strtolower($filters['q']);

        return $this->authorizedItems($user)
            ->whereIn('status', ['review', 'failed'])
            ->when($filters['batch'] !== null, fn (Builder $query) => $query->where('prospect_batch_id', $filters['batch']))
            ->when($filters['reason'] !== null, fn (Builder $query) => $query->where(fn (Builder $reasons) => $reasons
                ->where('domain_reason', $filters['reason'])
                ->orWhere('error_code', $filters['reason'])))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matches) use ($search): void {
                $like = '%'.$search.'%';
                $matches->whereRaw('LOWER(company_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(city) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(country) LIKE ?', [$like])
                    ->orWhereHas('batch', fn (Builder $batch) => $batch->whereRaw('LOWER(name) LIKE ?', [$like]));
            }));
    }

    /** @param array{batch:?int,reason:?string,state?:string,q:string} $filters */
    private function stateFilteredItems(User $user, array $filters): Builder
    {
        return $this->filteredItems($user, $filters)
            ->when(($filters['state'] ?? 'all') === 'attention', fn (Builder $query) => $query->where('status', 'review'))
            ->when(($filters['state'] ?? 'all') === 'blocked', fn (Builder $query) => $query->where('status', 'failed'));
    }

    /**
     * Public "À relancer" query, reused by the retry-drain preview and
     * dispatch endpoints so a drain always targets exactly the same
     * ownership- and filter-scoped set the pill/list already show — never a
     * second, hand-rolled copy of the filtering rules.
     *
     * @param array{batch:?int,reason:?string,q:string} $filters
     */
    public function blockedItemsQuery(User $user, array $filters): Builder
    {
        return $this->stateFilteredItems($user, [...$filters, 'state' => 'blocked']);
    }

    /**
     * Cheap, structural-only check for the bulk action bar: is there at
     * least one blocked item whose batch isn't pinned to an inactive
     * criterion? Deliberately narrower than the drain's full eligibility
     * classification (retry window / ledger budget are per-attempt and
     * transient — worth surfacing via the preview dialog, not worth a query
     * on every page load) — this only covers the durable, structural block
     * the task calls out: a button that literally cannot work until an
     * admin reactivates a criterion.
     *
     * @param array{batch:?int,reason:?string,q:string} $filters
     */
    public function hasRetryableBlockedItem(User $user, array $filters): bool
    {
        return $this->blockedItemsQuery($user, $filters)
            ->whereHas('batch', function (Builder $query): void {
                $query->whereNull('prospect_criteria_id')
                    ->orWhereHas('criteria', fn (Builder $criteria) => $criteria->where('is_active', true));
            })
            ->exists();
    }

    /** @param array{batch:?int,tab:string,reason:?string,state?:string,q:string,item?:?int} $filters */
    private function items(User $user, array $filters): LengthAwarePaginator
    {
        return $this->stateFilteredItems($user, $filters)
            ->select([
                'id', 'prospect_batch_id', 'company_name', 'country', 'city', 'provided_domain',
                'selected_domain', 'domain_alternatives', 'domain_confidence', 'domain_reason',
                'error_code', 'status', 'source_metadata', 'created_at',
            ])
            ->with([
                'batch:id,name,status,source_type,created_by,prospect_criteria_id',
                'batch.criteria:id,name,is_active',
            ])
            ->withCount('importedContacts')
            ->latest('id')
            ->paginate(10, ['*'], 'companies_page')
            ->withQueryString();
    }

    /** @param array{batch:?int,reason:?string,q:string} $filters @return array{all:int,attention:int,blocked:int} */
    private function stateCounts(User $user, array $filters): array
    {
        $base = $this->filteredItems($user, $filters);
        $attention = (clone $base)->where('status', 'review')->count();
        $blocked = (clone $base)->where('status', 'failed')->count();

        return ['all' => $attention + $blocked, 'attention' => $attention, 'blocked' => $blocked];
    }
}
